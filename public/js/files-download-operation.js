(function () {
    'use strict';

    /**
     * @brief Native download cockpit modal (grant + Range). No in-page byte ETA.
     * @date 2026-11-09
     * @author Stephane H.
     */

    var modalEl = document.getElementById('filesDownloadOperationModal');
    if (!modalEl) {
        return;
    }

    var nameEl = modalEl.querySelector('[data-ops-download-name]');
    var sizeEl = modalEl.querySelector('[data-ops-download-size]');
    var hintEl = modalEl.querySelector('[data-ops-download-hint]');
    var statusEl = modalEl.querySelector('[data-ops-download-status]');
    var errorEl = modalEl.querySelector('[data-ops-download-error]');
    var barEl = modalEl.querySelector('[data-ops-download-indeterminate]');
    var startBtn = modalEl.querySelector('[data-ops-download-start]');
    var copyBtn = modalEl.querySelector('[data-ops-download-copy]');
    var hideBtn = modalEl.querySelector('[data-ops-download-hide]');
    var cancelBtn = modalEl.querySelector('[data-ops-download-cancel]');
    var activeTask = null;

    /**
     * @brief Build a client-side download task id.
     * @return {string}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function newTaskId() {
        var bytes = new Uint8Array(16);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
        }
        var hex = '';
        for (var i = 0; i < bytes.length; i += 1) {
            hex += ('0' + bytes[i].toString(16)).slice(-2);
        }

        return hex;
    }

    /**
     * @brief Format a byte size for the cockpit subtitle.
     * @param {number} bytes Size.
     * @return {string}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function formatSize(bytes) {
        var n = Number(bytes) || 0;
        if (window.formatFilesSize) {
            return window.formatFilesSize(n);
        }
        if (n < 1024) {
            return String(n) + ' o';
        }
        var units = ['Ko', 'Mo', 'Go', 'To'];
        var value = n / 1024;
        var idx = 0;
        while (value >= 1024 && idx < units.length - 1) {
            value /= 1024;
            idx += 1;
        }

        return value.toFixed(1) + ' ' + units[idx];
    }

    /**
     * @brief Paint the download cockpit from a task.
     * @param {object} task Task payload.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function render(task) {
        activeTask = task;
        var payload = task.payload || {};
        if (nameEl) {
            nameEl.textContent = String(task.label || payload.fileName || '—');
        }
        if (sizeEl) {
            var bytes = Number(payload.byteSize) || 0;
            sizeEl.textContent = bytes > 0 ? formatSize(bytes) : '';
        }
        if (hintEl) {
            hintEl.textContent = modalEl.getAttribute('data-msg-hint') || '';
        }
        if (errorEl) {
            errorEl.classList.add('d-none');
            errorEl.textContent = '';
        }
        var handed = task.status === 'handed_to_browser';
        if (barEl) {
            barEl.classList.toggle('d-none', handed || task.status !== 'running');
        }
        if (statusEl) {
            statusEl.textContent = handed ? (modalEl.getAttribute('data-msg-handed') || '') : '';
        }
        if (startBtn) {
            startBtn.classList.toggle('d-none', handed);
            startBtn.disabled = false;
        }
        if (copyBtn) {
            copyBtn.classList.toggle('d-none', !handed);
        }
        if (hideBtn) {
            hideBtn.classList.toggle('d-none', !handed);
        }
        if (cancelBtn) {
            cancelBtn.classList.toggle('d-none', handed);
            cancelBtn.disabled = false;
        }
        modalEl.setAttribute('aria-busy', handed ? 'false' : 'true');
    }

    /**
     * @brief Open the download modal for one file or ready archive.
     * @param {object} spec Download spec.
     * @return {object} Task payload.
     * @date 2026-11-09
     * @author Stephane H.
     */
    function open(spec) {
        var cfg = spec && typeof spec === 'object' ? spec : {};
        var task = {
            taskId: cfg.taskId || newTaskId(),
            type: cfg.type === 'download_archive' ? 'download_archive' : 'download_file',
            status: 'running',
            label: cfg.fileName || 'download',
            phaseKey: 'files.ops.phase.download',
            doneBytes: 0,
            totalBytes: Number(cfg.byteSize) || 0,
            percent: 0,
            progressKnown: false,
            cancelSupported: true,
            modalKey: 'files_download',
            payload: {
                fileId: cfg.fileId || 0,
                byteSize: Number(cfg.byteSize) || 0,
                fileName: cfg.fileName || '',
                tokenUrl: cfg.tokenUrl || '',
                csrfToken: cfg.csrfToken || '',
                downloadUrl: cfg.downloadUrl || '',
                grant: cfg.grant || '',
                archiveToken: cfg.archiveToken || ''
            }
        };
        render(task);
        if (window.FilesOperations) {
            window.FilesOperations.markRunning(modalEl, task);
            window.FilesOperations.resume(task.taskId);
        } else if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }

        return task;
    }

    /**
     * @brief Request grant (if needed) and start the native browser download.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function startNative() {
        if (!activeTask || !window.FilesDownloadLauncher) {
            return;
        }
        var payload = activeTask.payload || {};
        if (startBtn) {
            startBtn.disabled = true;
        }
        if (barEl) {
            barEl.classList.remove('d-none');
        }
        var ready = Promise.resolve({
            download_url: payload.downloadUrl || '',
            download_grant: payload.grant || '',
            file_name: payload.fileName || activeTask.label
        });
        if (!payload.downloadUrl || !payload.grant) {
            ready = window.FilesDownloadLauncher.requestGrantAndDownload({
                tokenUrl: payload.tokenUrl || '',
                csrfToken: payload.csrfToken || '',
                fileName: payload.fileName || activeTask.label
            }).then(function (json) {
                return json;
            });
        } else {
            window.FilesDownloadLauncher.startNativeDownload(
                payload.downloadUrl,
                payload.grant,
                payload.fileName || activeTask.label
            );
        }
        ready.then(function (json) {
            payload.downloadUrl = json.download_url || payload.downloadUrl || '';
            payload.grant = json.download_grant || json.download_token || payload.grant || '';
            payload.fileName = json.file_name || payload.fileName;
            activeTask.payload = payload;
            activeTask.status = 'handed_to_browser';
            activeTask.cancelSupported = false;
            render(activeTask);
            if (window.FilesOperations) {
                window.FilesOperations.upsert(activeTask);
            }
        }).catch(function (error) {
            if (startBtn) {
                startBtn.disabled = false;
            }
            if (errorEl) {
                errorEl.textContent = (error && error.message) || (modalEl.getAttribute('data-msg-grant-fail') || '');
                errorEl.classList.remove('d-none');
            }
        });
    }

    /**
     * @brief Copy the resumable grant URL.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function copyLink() {
        if (!activeTask || !window.FilesDownloadLauncher) {
            return;
        }
        var payload = activeTask.payload || {};
        window.FilesDownloadLauncher.copyResumableLink(payload.downloadUrl || '', payload.grant || '').then(function () {
            if (statusEl) {
                statusEl.textContent = modalEl.getAttribute('data-msg-copy-ok') || '';
            }
        }).catch(function () {
            if (errorEl) {
                errorEl.textContent = modalEl.getAttribute('data-msg-copy-fail') || '';
                errorEl.classList.remove('d-none');
            }
        });
    }

    if (startBtn) {
        startBtn.addEventListener('click', function () {
            startNative();
        });
    }
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            copyLink();
        });
    }
    if (hideBtn) {
        hideBtn.addEventListener('click', function () {
            if (activeTask && window.FilesOperations) {
                window.FilesOperations.reduce(activeTask.taskId);
            }
        });
    }

    if (window.FilesOperations) {
        window.FilesOperations.register('download_file', {
            hydrate: render,
            cancel: function (task) {
                if (task && task.status === 'handed_to_browser') {
                    return task;
                }
                var next = Object.assign({}, task, { status: 'cancelled', cancelSupported: false });
                if (window.FilesOperations) {
                    window.FilesOperations.forgetLocalDownload(task.taskId);
                }

                return next;
            },
            abortLocal: function () {
                activeTask = null;
            }
        });
        window.FilesOperations.register('download_archive', {
            hydrate: render,
            cancel: function (task) {
                if (task && task.status === 'handed_to_browser') {
                    return task;
                }

                return Object.assign({}, task, { status: 'cancelled', cancelSupported: false });
            },
            abortLocal: function () {
                activeTask = null;
            }
        });
        window.FilesOperations.bindFamilyB(modalEl, {
            isRunning: function () {
                return !!(activeTask && (activeTask.status === 'running' || activeTask.status === 'handed_to_browser'));
            },
            getTask: function () {
                return activeTask;
            }
        });
    }

    window.FilesDownloadOperation = {
        open: open,
        hydrate: render
    };
}());
