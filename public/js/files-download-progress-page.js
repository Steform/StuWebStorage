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
    var errorEl = document.getElementById('files-download-progress-error');
    var resumePrompt = document.getElementById('files-download-resume-prompt');
    var resumeMessage = document.getElementById('files-download-resume-message');
    var resumePickerHint = document.getElementById('files-download-resume-picker-hint');
    var resumeBtn = document.getElementById('files-download-resume-btn');
    var restartBtn = document.getElementById('files-download-restart-btn');
    var abortController = null;
    var pendingCheckpoint = null;

    function jobKey() {
        return window.FilesDownloadCheckpoint.buildJobKey('auth', fileId, fileBytes);
    }

    function showError(message) {
        if (!errorEl) {
            return;
        }
        errorEl.textContent = message || root.getAttribute('data-msg-error') || '';
        errorEl.classList.remove('d-none');
    }

    function hideError() {
        if (errorEl) {
            errorEl.classList.add('d-none');
            errorEl.textContent = '';
        }
    }

    function updateProgress(received, total) {
        var percent = total > 0 ? Math.floor((received / total) * 100) : 0;
        if (bar) {
            bar.style.width = percent + '%';
            bar.setAttribute('aria-valuenow', String(percent));
        }
        if (count) {
            count.textContent = window.FilesDownloadManager.formatBytes(received) + ' / ' + window.FilesDownloadManager.formatBytes(total) + ' (' + percent + '%)';
        }
    }

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

    function showResumePrompt(checkpoint) {
        pendingCheckpoint = checkpoint;
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

    function hideResumePrompt() {
        if (resumePrompt) {
            resumePrompt.classList.add('d-none');
        }
    }

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

    function startDownload(resumeCheckpoint) {
        hideError();
        hideResumePrompt();
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
