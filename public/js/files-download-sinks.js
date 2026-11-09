(function () {
    'use strict';

    /**
     * @brief Sanitize a job key for OPFS file names.
     * @param {string} jobKey Stable job identifier.
     * @return {string}
     * @date 2026-07-07
     * @author Stephane H.
     */
    function opfsFileName(jobKey) {
        return String(jobKey).replace(/[^a-zA-Z0-9._-]+/g, '_') + '.part';
    }

    /**
     * @brief File System Access sink with incremental writes.
     * @date 2026-07-07
     * @author Stephane H.
     */
    var FileSystemAccessSink = {
        /**
         * @brief Create a writable sink using the File System Access API.
         * @param {string} fileName Suggested filename.
         * @param {object|null} resumeCheckpoint Existing checkpoint when resuming.
         * @return {Promise<object>}
         * @date 2026-07-07
         * @author Stephane H.
         */
        create: async function (fileName, resumeCheckpoint) {
            if (!window.showSaveFilePicker) {
                throw new Error('download.sink.fs_access_unavailable');
            }

            var receivedBytes = Number(resumeCheckpoint && resumeCheckpoint.receivedBytes) || 0;
            var handle;
            var writable;

            if (resumeCheckpoint && resumeCheckpoint.sinkType === 'fsAccess' && receivedBytes > 0 && window.showOpenFilePicker) {
                var opened = await window.showOpenFilePicker({
                    multiple: false
                });
                handle = opened[0];
                writable = await handle.createWritable({ keepExistingData: true });
                if (typeof writable.seek === 'function') {
                    await writable.seek(receivedBytes);
                }
            } else {
                handle = await window.showSaveFilePicker({
                    suggestedName: fileName
                });
                writable = await handle.createWritable();
            }

            return {
                type: 'fsAccess',
                opfsPath: null,
                write: async function (buffer, offset) {
                    if (typeof writable.seek === 'function') {
                        await writable.seek(offset);
                    }
                    await writable.write(buffer);
                },
                close: async function () {
                    await writable.close();
                },
                destroy: async function () {
                    await writable.close();
                },
                finalize: async function () {
                    await writable.close();
                }
            };
        }
    };

    /**
     * @brief Origin Private File System sink for browsers without save picker.
     * @date 2026-07-07
     * @author Stephane H.
     */
    var OpfsSink = {
        /**
         * @brief Create or reopen an OPFS-backed partial file.
         * @param {string} jobKey Stable job identifier.
         * @param {string} fileName Suggested filename for final export.
         * @param {object|null} resumeCheckpoint Existing checkpoint when resuming.
         * @return {Promise<object>}
         * @date 2026-07-07
         * @author Stephane H.
         */
        create: async function (jobKey, fileName, resumeCheckpoint) {
            if (!navigator.storage || typeof navigator.storage.getDirectory !== 'function') {
                throw new Error('download.sink.opfs_unavailable');
            }

            var path = (resumeCheckpoint && resumeCheckpoint.opfsPath) || opfsFileName(jobKey);
            var root = await navigator.storage.getDirectory();
            var fileHandle = await root.getFileHandle(path, { create: true });

            return {
                type: 'opfs',
                opfsPath: path,
                write: async function (buffer, offset) {
                    var accessHandle = await fileHandle.createSyncAccessHandle();
                    try {
                        accessHandle.write(buffer, { at: offset });
                    } finally {
                        accessHandle.close();
                    }
                },
                close: async function () {
                    return;
                },
                destroy: async function () {
                    try {
                        await root.removeEntry(path);
                    } catch (e) {
                        // ignore missing file
                    }
                },
                finalize: async function () {
                    var file = await fileHandle.getFile();
                    var objectUrl = URL.createObjectURL(file);
                    var anchor = document.createElement('a');
                    anchor.href = objectUrl;
                    anchor.download = fileName || 'download.bin';
                    document.body.appendChild(anchor);
                    anchor.click();
                    anchor.remove();
                    window.setTimeout(function () {
                        URL.revokeObjectURL(objectUrl);
                    }, 1000);
                }
            };
        }
    };

    /**
     * @brief Pick the best sink for the current browser and checkpoint state.
     * @param {string} fileName Suggested filename.
     * @param {string} jobKey Stable job identifier.
     * @param {object|null} resumeCheckpoint Existing checkpoint when resuming.
     * @return {Promise<object>}
     * @date 2026-07-07
     * @author Stephane H.
     */
    async function createSink(fileName, jobKey, resumeCheckpoint) {
        if (resumeCheckpoint && resumeCheckpoint.sinkType === 'opfs') {
            return OpfsSink.create(jobKey, fileName, resumeCheckpoint);
        }
        if (window.showSaveFilePicker) {
            try {
                return await FileSystemAccessSink.create(fileName, resumeCheckpoint);
            } catch (error) {
                if (!resumeCheckpoint || resumeCheckpoint.sinkType !== 'fsAccess') {
                    return OpfsSink.create(jobKey, fileName, resumeCheckpoint);
                }
                throw error;
            }
        }
        return OpfsSink.create(jobKey, fileName, resumeCheckpoint);
    }

    /**
     * @brief Remove an OPFS partial file when restarting a download.
     * @param {string|null} opfsPath OPFS entry name.
     * @return {Promise<void>}
     * @date 2026-07-07
     * @author Stephane H.
     */
    async function destroyOpfsArtifact(opfsPath) {
        if (!opfsPath || !navigator.storage || typeof navigator.storage.getDirectory !== 'function') {
            return;
        }
        try {
            var root = await navigator.storage.getDirectory();
            await root.removeEntry(String(opfsPath));
        } catch (e) {
            // ignore missing file
        }
    }

    window.FilesDownloadSinks = {
        createSink: createSink,
        destroyOpfsArtifact: destroyOpfsArtifact,
        opfsFileName: opfsFileName
    };
}());
