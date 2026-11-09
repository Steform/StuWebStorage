(function () {
    'use strict';

    var root = document.getElementById('files-download-progress-page');
    if (!root || typeof window.FilesDownloadManager === 'undefined' || typeof window.FilesDownloadCheckpoint === 'undefined') {
        return;
    }

    var tokenUrl = root.getAttribute('data-token-url') || '';
    var downloadUrl = root.getAttribute('data-download-url') || '';
    var csrfToken = root.getAttribute('data-csrf-token') || '';
    var fileName = root.getAttribute('data-file-name') || 'download.bin';
    var fileId = parseInt(root.getAttribute('data-file-id') || '0', 10);
    var fileBytes = parseInt(root.getAttribute('data-file-bytes') || '0', 10);
    var bar = document.getElementById('files-download-progress-bar');
    var count = document.getElementById('files-download-progress-count');
    var etaEl = document.getElementById('files-download-progress-eta');
    var errorEl = document.getElementById('files-download-progress-error');
    var resumePrompt = document.getElementById('files-download-resume-prompt');
    var resumeMessage = document.getElementById('files-download-resume-message');
    var resumePickerHint = document.getElementById('files-download-resume-picker-hint');
    var resumeBtn = document.getElementById('files-download-resume-btn');
    var restartBtn = document.getElementById('files-download-restart-btn');
    var abortController = null;
    var pendingCheckpoint = null;
    var downloadEta = window.TransferEta && typeof window.TransferEta.createEstimator === 'function'
        ? window.TransferEta.createEstimator()
        : null;

    /**
     * @brief Build ETA message templates from page datasets.
     * @return {object}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function etaTemplates() {
        return {
            calculating: root.getAttribute('data-msg-eta-calculating') || '',
            remaining: root.getAttribute('data-msg-eta-remaining') || '',
            speed: root.getAttribute('data-msg-eta-speed') || '',
            line: root.getAttribute('data-msg-eta-line') || '',
            units: {
                s: root.getAttribute('data-msg-eta-unit-s') || 's',
                m: root.getAttribute('data-msg-eta-unit-m') || 'min',
                h: root.getAttribute('data-msg-eta-unit-h') || 'h'
            }
        };
    }

    /**
     * @brief Job key for checkpoint storage.
     * @return {string}
     * @date 2026-07-07
     * @author Stephane H.
     */
    function jobKey() {
        return window.FilesDownloadCheckpoint.buildJobKey('auth', fileId, fileBytes);
    }

    /**
     * @brief Show an error banner.
     * @param {string} message Error text.
     * @return {void}
     * @date 2026-07-07
     * @author Stephane H.
     */
    function showError(message) {
        if (!errorEl) {
            return;
        }
        errorEl.textContent = message || root.getAttribute('data-msg-error') || '';
        errorEl.classList.remove('d-none');
    }

    /**
     * @brief Hide the error banner.
     * @return {void}
     * @date 2026-07-07
     * @author Stephane H.
     */
    function hideError() {
        if (errorEl) {
            errorEl.classList.add('d-none');
            errorEl.textContent = '';
        }
    }

    /**
     * @brief Update progress bar, byte count, and ETA.
     * @param {number} received Received bytes.
     * @param {number} total Total bytes.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function updateProgress(received, total) {
        var percent = total > 0 ? Math.floor((received / total) * 100) : 0;
        if (bar) {
            bar.style.width = percent + '%';
            bar.setAttribute('aria-valuenow', String(percent));
        }
        if (count) {
            count.textContent = window.FilesDownloadManager.formatBytes(received) + ' / ' + window.FilesDownloadManager.formatBytes(total) + ' (' + percent + '%)';
        }
        if (etaEl && downloadEta && window.TransferEta) {
            var snap = downloadEta.update(received, total);
            if (snap.shouldRefreshUi || received >= total) {
                etaEl.textContent = window.TransferEta.formatLine(snap, etaTemplates());
            }
        }
    }

    /**
     * @brief Format resume prompt copy.
     * @param {object} checkpoint Checkpoint payload.
     * @return {string}
     * @date 2026-07-07
     * @author Stephane H.
     */
    function formatResumePrompt(checkpoint) {
        var template = root.getAttribute('data-msg-resume-prompt') || '';
        var received = Number(checkpoint.receivedBytes) || 0;
        var total = Number(checkpoint.totalBytes) || fileBytes;
        var percent = total > 0 ? Math.floor((received / total) * 100) : 0;
        return template
            .replace('%percent%', String(percent))
            .replace('%received%', window.FilesDownloadManager.formatBytes(received))
            .replace('%total%', window.FilesDownloadManager.formatBytes(total));
    }

    /**
     * @brief Show resume UI for an existing checkpoint.
     * @param {object} checkpoint Checkpoint payload.
     * @return {void}
     * @date 2026-07-07
     * @author Stephane H.
     */
    function showResumePrompt(checkpoint) {
        pendingCheckpoint = checkpoint;
        if (downloadEta) {
            downloadEta.reset();
        }
        updateProgress(Number(checkpoint.receivedBytes) || 0, Number(checkpoint.totalBytes) || fileBytes);
        if (resumeMessage) {
            resumeMessage.textContent = formatResumePrompt(checkpoint);
        }
        if (resumePickerHint) {
            var hint = '';
            if (checkpoint.sinkType === 'fsAccess') {
                hint = root.getAttribute('data-msg-resume-picker-hint') || '';
            } else if (checkpoint.sinkType === 'opfs') {
                hint = root.getAttribute('data-msg-resume-opfs-info') || '';
            }
            resumePickerHint.textContent = hint;
            resumePickerHint.classList.toggle('d-none', hint === '');
        }
        if (resumePrompt) {
            resumePrompt.classList.remove('d-none');
        }
    }

    /**
     * @brief Hide resume UI.
     * @return {void}
     * @date 2026-07-07
     * @author Stephane H.
     */
    function hideResumePrompt() {
        if (resumePrompt) {
            resumePrompt.classList.add('d-none');
        }
    }

    /**
     * @brief Request a short-lived download token.
     * @return {Promise<{ok: boolean, json: object}>}
     * @date 2026-07-07
     * @author Stephane H.
     */
    function requestToken() {
        var body = new URLSearchParams();
        body.append('_csrf_token', csrfToken);
        return fetch(tokenUrl, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (json) {
                return { ok: response.ok, json: json };
            });
        });
    }

    /**
     * @brief Map manager errors to user-facing copy.
     * @param {Error} error Thrown error.
     * @return {string}
     * @date 2026-07-07
     * @author Stephane H.
     */
    function resolveErrorMessage(error) {
        if (!error || !error.message) {
            return root.getAttribute('data-msg-error') || '';
        }
        if (error.message === 'download.manager.checkpoint_invalid') {
            return root.getAttribute('data-msg-checkpoint-invalid') || root.getAttribute('data-msg-error') || '';
        }
        if (error.message === 'download.manager.parallel_tab') {
            return root.getAttribute('data-msg-parallel-tab') || root.getAttribute('data-msg-error') || '';
        }
        return root.getAttribute('data-msg-error') || '';
    }

    /**
     * @brief Build FilesDownloadManager config.
     * @param {string} token Download token.
     * @param {object|null} resumeCheckpoint Optional checkpoint.
     * @return {object}
     * @date 2026-07-07
     * @author Stephane H.
     */
    function buildDownloadConfig(token, resumeCheckpoint) {
        return {
            url: downloadUrl,
            fileName: fileName,
            downloadToken: token,
            jobKey: jobKey(),
            context: 'auth',
            fileId: fileId,
            publicToken: null,
            signal: abortController.signal,
            resumeCheckpoint: resumeCheckpoint || null,
            onProgress: updateProgress,
            onError: function () {},
            onCheckpointSaved: updateProgress
        };
    }

    /**
     * @brief Start or resume a managed download.
     * @param {object|null} resumeCheckpoint Optional checkpoint.
     * @return {Promise<*>}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function startDownload(resumeCheckpoint) {
        hideError();
        hideResumePrompt();
        if (downloadEta) {
            downloadEta.reset();
        }
        abortController = new AbortController();
        return requestToken().then(function (pack) {
            if (!pack.ok || !pack.json || pack.json.status !== 'ok') {
                showError((pack.json && pack.json.message) || root.getAttribute('data-msg-error') || '');
                if (resumeCheckpoint) {
                    showResumePrompt(resumeCheckpoint);
                }
                return;
            }
            return window.FilesDownloadManager.download(buildDownloadConfig(pack.json.download_token || '', resumeCheckpoint));
        }).then(function () {
            pendingCheckpoint = null;
            if (etaEl) {
                etaEl.textContent = '';
            }
        }).catch(function (error) {
            if (error && error.name === 'AbortError') {
                if (resumeCheckpoint) {
                    showResumePrompt(resumeCheckpoint);
                }
                return;
            }
            showError(resolveErrorMessage(error));
            return window.FilesDownloadCheckpoint.find(jobKey()).then(function (checkpoint) {
                if (window.FilesDownloadCheckpoint.isResumable(checkpoint)) {
                    showResumePrompt(checkpoint);
                }
            });
        });
    }

    /**
     * @brief Clear checkpoint artifacts and restart from byte 0.
     * @return {void}
     * @date 2026-07-07
     * @author Stephane H.
     */
    function restartDownload() {
        var checkpoint = pendingCheckpoint;
        hideError();
        var clearPromise = window.FilesDownloadCheckpoint.clear(jobKey());
        var destroyPromise = Promise.resolve();
        if (checkpoint && checkpoint.opfsPath && window.FilesDownloadSinks) {
            destroyPromise = window.FilesDownloadSinks.destroyOpfsArtifact(checkpoint.opfsPath);
        }
        Promise.all([clearPromise, destroyPromise]).then(function () {
            pendingCheckpoint = null;
            if (downloadEta) {
                downloadEta.reset();
            }
            updateProgress(0, fileBytes);
            return startDownload(null);
        }).catch(function () {
            showError(root.getAttribute('data-msg-error') || '');
        });
    }

    if (resumeBtn) {
        resumeBtn.addEventListener('click', function () {
            if (!pendingCheckpoint) {
                return;
            }
            startDownload(pendingCheckpoint);
        });
    }

    if (restartBtn) {
        restartBtn.addEventListener('click', restartDownload);
    }

    var cancelBtn = document.getElementById('files-download-progress-cancel');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            if (abortController) {
                abortController.abort();
            }
        });
    }

    window.FilesDownloadCheckpoint.find(jobKey()).then(function (checkpoint) {
        if (window.FilesDownloadCheckpoint.isResumable(checkpoint)) {
            showResumePrompt(checkpoint);
            return;
        }
        return startDownload(null);
    }).catch(function () {
        return startDownload(null);
    });
}());
