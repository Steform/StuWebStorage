(function () {
    'use strict';

    var SMALL_FILE_THRESHOLD = 8388608;

    function sleep(ms) {
        return new Promise(function (resolve) {
            window.setTimeout(resolve, ms);
        });
    }

    function formatBytes(bytes) {
        var n = Number(bytes) || 0;
        if (n < 1048576) {
            return (n / 1024).toFixed(1) + ' KiB';
        }
        if (n < 1073741824) {
            return (n / 1048576).toFixed(1) + ' MiB';
        }
        return (n / 1073741824).toFixed(2) + ' GiB';
    }

    function appendToken(url, token) {
        if (!token) {
            return url;
        }
        var separator = url.indexOf('?') >= 0 ? '&' : '?';
        return url + separator + 'dt=' + encodeURIComponent(token);
    }

    /**
     * @brief Probe download URL and return total size plus range support.
     * @param {string} url Download URL without token.
     * @param {string} token Download token query value.
     * @param {AbortSignal|null} signal Optional abort signal.
     * @return {Promise<{total:number, acceptRanges:boolean}>}
     * @date 2026-07-07
     * @author Stephane H.
     */
    async function probe(url, token, signal) {
        var probeUrl = appendToken(url, token);
        var probeResponse = await fetch(probeUrl, {
            method: 'HEAD',
            credentials: 'same-origin',
            signal: signal
        });
        if (!probeResponse.ok) {
            throw new Error('download.manager.probe_failed');
        }

        var total = parseInt(probeResponse.headers.get('Content-Length') || '0', 10);
        if (total < 1) {
            throw new Error('download.manager.missing_length');
        }

        var acceptRanges = (probeResponse.headers.get('Accept-Ranges') || '').toLowerCase().indexOf('bytes') >= 0;
        return {
            total: total,
            acceptRanges: acceptRanges
        };
    }

    /**
     * @brief Fetch one HTTP Range chunk with retries.
     * @param {string} url Download URL without token.
     * @param {string} token Download token query value.
     * @param {number} start Range start byte.
     * @param {number} end Range end byte inclusive.
     * @param {number} maxRetries Maximum retry attempts.
     * @param {AbortSignal|null} signal Optional abort signal.
     * @param {function} onError Optional per-attempt error callback.
     * @return {Promise<ArrayBuffer>}
     * @date 2026-07-07
     * @author Stephane H.
     */
    async function fetchChunk(url, token, start, end, maxRetries, signal, onError) {
        var attempt = 0;
        while (attempt < maxRetries) {
            try {
                var chunkUrl = appendToken(url, token);
                var response = await fetch(chunkUrl, {
                    method: 'GET',
                    headers: {
                        Range: 'bytes=' + start + '-' + end
                    },
                    credentials: 'same-origin',
                    signal: signal
                });
                if (response.status !== 206 && response.status !== 200) {
                    throw new Error('download.manager.chunk_http_' + response.status);
                }
                return await response.arrayBuffer();
            } catch (error) {
                attempt += 1;
                if (typeof onError === 'function') {
                    onError(start, error, attempt);
                }
                if (attempt >= maxRetries) {
                    throw error;
                }
                await sleep(Math.min(8000, 500 * Math.pow(2, attempt)));
            }
        }
        throw new Error('download.manager.chunk_failed');
    }

    /**
     * @brief Fast path for small files using a single in-memory blob.
     * @param {object} config Runtime configuration.
     * @param {number} total Total file size.
     * @return {Promise<void>}
     * @date 2026-07-07
     * @author Stephane H.
     */
    async function downloadSmallBlob(config, total) {
        var url = String(config.url || '');
        var fileName = String(config.fileName || 'download.bin');
        var token = String(config.downloadToken || '');
        var signal = config.signal || null;
        var onProgress = typeof config.onProgress === 'function' ? config.onProgress : function () {};

        var buffer = await fetchChunk(url, token, 0, total - 1, Number(config.maxRetries) || 3, signal, config.onError);
        onProgress(buffer.byteLength, total, 0);

        var blob = new Blob([buffer], { type: 'application/octet-stream' });
        if (window.showSaveFilePicker) {
            try {
                var handle = await window.showSaveFilePicker({ suggestedName: fileName });
                var writable = await handle.createWritable();
                await writable.write(blob);
                await writable.close();
                return;
            } catch (pickerError) {
                if (pickerError && pickerError.name === 'AbortError') {
                    throw pickerError;
                }
            }
        }

        var objectUrl = URL.createObjectURL(blob);
        var anchor = document.createElement('a');
        anchor.href = objectUrl;
        anchor.download = fileName;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        URL.revokeObjectURL(objectUrl);
    }

    /**
     * @brief Run an incremental download job with checkpoint persistence.
     * @param {object} config Runtime configuration.
     * @param {object|null} resumeCheckpoint Existing checkpoint when resuming.
     * @return {Promise<void>}
     * @date 2026-07-07
     * @author Stephane H.
     */
    async function runJob(config, resumeCheckpoint) {
        var url = String(config.url || '');
        var fileName = String(config.fileName || 'download.bin');
        var chunkSize = Number(config.chunkSize) || 16777216;
        var maxRetries = Number(config.maxRetries) || 3;
        var token = String(config.downloadToken || '');
        var signal = config.signal || null;
        var jobKey = String(config.jobKey || '');
        var context = String(config.context || 'auth');
        var fileId = config.fileId != null ? Number(config.fileId) : null;
        var publicToken = config.publicToken != null ? String(config.publicToken) : null;
        var onProgress = typeof config.onProgress === 'function' ? config.onProgress : function () {};
        var onError = typeof config.onError === 'function' ? config.onError : function () {};
        var onCheckpointSaved = typeof config.onCheckpointSaved === 'function' ? config.onCheckpointSaved : function () {};

        if (url === '') {
            throw new Error('download.manager.missing_url');
        }
        if (jobKey === '') {
            throw new Error('download.manager.missing_job_key');
        }
        if (!window.FilesDownloadCheckpoint || !window.FilesDownloadSinks) {
            throw new Error('download.manager.missing_dependencies');
        }

        if (!window.FilesDownloadCheckpoint.tryAcquireLock(jobKey)) {
            throw new Error('download.manager.parallel_tab');
        }

        var sink = null;
        try {
            var probeResult = await probe(url, token, signal);
            if (!probeResult.acceptRanges) {
                window.location.href = appendToken(url, token);
                return;
            }

            var total = probeResult.total;
            if (resumeCheckpoint && Number(resumeCheckpoint.totalBytes) !== total) {
                await window.FilesDownloadCheckpoint.clear(jobKey);
                throw new Error('download.manager.checkpoint_invalid');
            }

            if (!resumeCheckpoint && total <= SMALL_FILE_THRESHOLD) {
                await downloadSmallBlob(config, total);
                return;
            }

            var received = resumeCheckpoint ? Number(resumeCheckpoint.receivedBytes) || 0 : 0;
            sink = await window.FilesDownloadSinks.createSink(fileName, jobKey, resumeCheckpoint);

            var checkpoint = resumeCheckpoint ? Object.assign({}, resumeCheckpoint) : {
                jobKey: jobKey,
                context: context,
                fileId: fileId,
                publicToken: publicToken,
                fileName: fileName,
                downloadUrl: url,
                totalBytes: total,
                receivedBytes: 0,
                chunkSize: chunkSize,
                sinkType: sink.type,
                opfsPath: sink.opfsPath || null,
                updatedAt: Date.now()
            };
            checkpoint.totalBytes = total;
            checkpoint.receivedBytes = received;
            checkpoint.sinkType = sink.type;
            checkpoint.opfsPath = sink.opfsPath || null;
            checkpoint.chunkSize = chunkSize;

            if (received === 0) {
                await window.FilesDownloadCheckpoint.save(checkpoint);
            }

            var chunkIndex = Math.floor(received / chunkSize);
            for (var start = received; start < total; start += chunkSize) {
                var end = Math.min(total - 1, start + chunkSize - 1);
                var buffer = await fetchChunk(url, token, start, end, maxRetries, signal, function (chunkStart, error, attempt) {
                    onError(chunkIndex, error, attempt, chunkStart);
                });
                await sink.write(buffer, start);
                checkpoint.receivedBytes = start + buffer.byteLength;
                await window.FilesDownloadCheckpoint.save(checkpoint);
                onCheckpointSaved(checkpoint.receivedBytes, total);
                onProgress(checkpoint.receivedBytes, total, chunkIndex);
                chunkIndex += 1;
            }

            await sink.finalize();
            await sink.close();
            if (sink.type === 'opfs') {
                await sink.destroy();
            }
            await window.FilesDownloadCheckpoint.clear(jobKey);
        } finally {
            window.FilesDownloadCheckpoint.releaseLock(jobKey);
        }
    }

    /**
     * @brief Download a large file using HTTP Range chunks with resume support.
     * @param {object} config Runtime configuration.
     * @return {Promise<void>}
     * @date 2026-07-07
     * @author Stephane H.
     */
    async function download(config) {
        var resumeCheckpoint = config && config.resumeCheckpoint ? config.resumeCheckpoint : null;
        return runJob(config, resumeCheckpoint);
    }

    window.FilesDownloadManager = {
        download: download,
        runJob: runJob,
        probe: probe,
        fetchChunk: fetchChunk,
        formatBytes: formatBytes
    };
}());
