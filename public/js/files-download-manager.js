(function () {
    'use strict';

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
     * @brief Download a large file using HTTP Range chunks with retries.
     * @param {object} config Runtime configuration.
     * @return {Promise<void>}
     * @date 2026-07-07
     * @author Stephane H.
     */
    async function download(config) {
        var url = String(config.url || '');
        var fileName = String(config.fileName || 'download.bin');
        var chunkSize = Number(config.chunkSize) || 16777216;
        var maxRetries = Number(config.maxRetries) || 3;
        var token = String(config.downloadToken || '');
        var signal = config.signal || null;
        var onProgress = typeof config.onProgress === 'function' ? config.onProgress : function () {};
        var onError = typeof config.onError === 'function' ? config.onError : function () {};

        if (url === '') {
            throw new Error('download.manager.missing_url');
        }

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

        var acceptRanges = (probeResponse.headers.get('Accept-Ranges') || '').toLowerCase();
        if (acceptRanges.indexOf('bytes') < 0) {
            window.location.href = probeUrl;
            return;
        }

        var chunks = [];
        var received = 0;
        var chunkIndex = 0;
        for (var start = 0; start < total; start += chunkSize) {
            var end = Math.min(total - 1, start + chunkSize - 1);
            var attempt = 0;
            var done = false;
            while (!done && attempt < maxRetries) {
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
                    var buffer = await response.arrayBuffer();
                    chunks.push(buffer);
                    received += buffer.byteLength;
                    onProgress(received, total, chunkIndex);
                    done = true;
                } catch (error) {
                    attempt += 1;
                    onError(chunkIndex, error);
                    if (attempt >= maxRetries) {
                        throw error;
                    }
                    await sleep(Math.min(8000, 500 * Math.pow(2, attempt)));
                }
            }
            chunkIndex += 1;
        }

        var blob = new Blob(chunks, { type: 'application/octet-stream' });
        if (window.showSaveFilePicker) {
            try {
                var handle = await window.showSaveFilePicker({
                    suggestedName: fileName
                });
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

        if (total > 2147483648) {
            throw new Error('download.manager.blob_limit');
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

    window.FilesDownloadManager = {
        download: download,
        formatBytes: formatBytes
    };
}());
