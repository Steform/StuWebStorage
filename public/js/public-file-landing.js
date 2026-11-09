(function () {
    'use strict';

    var root = document.getElementById('public-landing-root');
    if (!root) {
        return;
    }

    var publicToken = root.getAttribute('data-public-token') || '';
    var fileBytes = parseInt(root.getAttribute('data-file-bytes') || '0', 10);
    var endpointChallenge = root.getAttribute('data-endpoint-challenge') || '';
    var endpointVerify = root.getAttribute('data-endpoint-verify') || '';
    var endpointVerifyPassword = root.getAttribute('data-endpoint-verify-password') || '';
    var endpointFile = root.getAttribute('data-endpoint-file') || '';
    var managerThreshold = parseInt(root.getAttribute('data-download-manager-threshold-bytes') || '0', 10);
    var apiMsgs = {};
    try {
        apiMsgs = JSON.parse(root.getAttribute('data-api-msgs') || '{}');
    } catch (e) {
        apiMsgs = {};
    }

    var sendBtn = document.getElementById('public-landing-send');
    var verifyBtn = document.getElementById('public-landing-verify');
    var downloadLink = document.getElementById('public-landing-download');
    var emailInput = document.getElementById('public-landing-email');
    var codeInput = document.getElementById('public-landing-code');
    var alertBox = document.getElementById('public-landing-alert');
    var passwordSection = document.getElementById('public-landing-share-password-section');
    var passwordInput = document.getElementById('public-landing-share-password');
    var passwordBtn = document.getElementById('public-landing-submit-share-password');
    var progressBar = document.getElementById('public-landing-download-progress-bar');
    var progressCount = document.getElementById('public-landing-download-progress-count');
    var progressWrap = document.getElementById('public-landing-download-progress-wrap');
    var resumePrompt = document.getElementById('public-landing-download-resume-prompt');
    var resumeMessage = document.getElementById('public-landing-download-resume-message');
    var resumePickerHint = document.getElementById('public-landing-download-resume-picker-hint');
    var resumeBtn = document.getElementById('public-landing-download-resume-btn');
    var restartBtn = document.getElementById('public-landing-download-restart-btn');

    var challengeId = 0;
    var preAuthToken = '';
    var downloadKey = '';
    var downloadAbortController = null;
    var pendingDownloadData = null;
    var pendingCheckpoint = null;

    function msg(key, fallback) {
        return apiMsgs[key] || fallback || key;
    }

    function jobKey(bytes) {
        if (!window.FilesDownloadCheckpoint) {
            return 'public:' + publicToken + ':' + String(bytes);
        }
        return window.FilesDownloadCheckpoint.buildJobKey('public', publicToken, bytes);
    }

    function showAlert(text, isError) {
        if (!alertBox) {
            return;
        }
        alertBox.textContent = text;
        alertBox.className = isError ? 'alert alert-danger' : 'alert alert-success';
        alertBox.classList.remove('d-none');
    }

    function postJson(url, payload) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json'
            },
            body: JSON.stringify(payload),
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (json) {
                return { ok: response.ok, status: response.status, json: json };
            });
        });
    }

    function updateProgress(received, total) {
        var percent = total > 0 ? Math.floor((received / total) * 100) : 0;
        if (progressBar) {
            progressBar.style.width = percent + '%';
            progressBar.setAttribute('aria-valuenow', String(percent));
        }
        if (progressCount && window.FilesDownloadManager) {
            progressCount.textContent = window.FilesDownloadManager.formatBytes(received) + ' / ' + window.FilesDownloadManager.formatBytes(total) + ' (' + percent + '%)';
        }
    }

    function formatResumePrompt(checkpoint, totalBytes) {
        var template = root.getAttribute('data-msg-resume-prompt') || '';
        var received = Number(checkpoint.receivedBytes) || 0;
        var total = Number(checkpoint.totalBytes) || totalBytes;
        var percent = total > 0 ? Math.floor((received / total) * 100) : 0;
        return template
            .replace('%percent%', String(percent))
            .replace('%received%', window.FilesDownloadManager.formatBytes(received))
            .replace('%total%', window.FilesDownloadManager.formatBytes(total));
    }

    function showResumePrompt(checkpoint, data) {
        pendingCheckpoint = checkpoint;
        pendingDownloadData = data;
        var totalBytes = Number(data.bytes) || fileBytes;
        updateProgress(Number(checkpoint.receivedBytes) || 0, Number(checkpoint.totalBytes) || totalBytes);
        if (resumeMessage) {
            resumeMessage.textContent = formatResumePrompt(checkpoint, totalBytes);
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
        if (progressWrap) {
            progressWrap.classList.remove('d-none');
        }
        if (downloadLink) {
            downloadLink.classList.add('d-none');
        }
    }

    function hideResumePrompt() {
        if (resumePrompt) {
            resumePrompt.classList.add('d-none');
        }
    }

    function resolveDownloadError(error) {
        if (!error || !error.message) {
            return msg('download.resource.unreadable', '');
        }
        if (error.message === 'download.manager.checkpoint_invalid') {
            return root.getAttribute('data-msg-checkpoint-invalid') || msg('download.resource.unreadable', '');
        }
        if (error.message === 'download.manager.parallel_tab') {
            return root.getAttribute('data-msg-parallel-tab') || msg('download.resource.unreadable', '');
        }
        return msg('download.resource.unreadable', '');
    }

    function runManagedDownload(data, resumeCheckpoint) {
        if (typeof window.FilesDownloadManager === 'undefined') {
            if (downloadLink) {
                downloadLink.href = data.downloadUrl || (endpointFile + '?key=' + encodeURIComponent(downloadKey));
                downloadLink.classList.remove('d-none');
            }
            return Promise.resolve();
        }

        hideResumePrompt();
        if (progressWrap) {
            progressWrap.classList.remove('d-none');
        }
        if (downloadLink) {
            downloadLink.classList.add('d-none');
        }

        var totalBytes = Number(data.bytes) || fileBytes;
        downloadAbortController = new AbortController();
        return window.FilesDownloadManager.download({
            url: data.downloadUrl || (endpointFile + '?key=' + encodeURIComponent(downloadKey)),
            fileName: data.fileName || 'download.bin',
            jobKey: jobKey(totalBytes),
            context: 'public',
            fileId: null,
            publicToken: publicToken,
            signal: downloadAbortController.signal,
            resumeCheckpoint: resumeCheckpoint || null,
            onProgress: updateProgress,
            onCheckpointSaved: updateProgress,
            onError: function () {}
        }).then(function () {
            pendingCheckpoint = null;
            pendingDownloadData = null;
            showAlert(msg('download.authorized', ''), false);
        }).catch(function (error) {
            if (error && error.name === 'AbortError') {
                if (resumeCheckpoint) {
                    showResumePrompt(resumeCheckpoint, data);
                }
                return;
            }
            showAlert(resolveDownloadError(error), true);
            if (!window.FilesDownloadCheckpoint) {
                return;
            }
            return window.FilesDownloadCheckpoint.find(jobKey(totalBytes)).then(function (checkpoint) {
                if (window.FilesDownloadCheckpoint.isResumable(checkpoint)) {
                    showResumePrompt(checkpoint, data);
                }
            });
        });
    }

    function startManagedDownload(data) {
        var totalBytes = Number(data.bytes) || fileBytes;
        if (!window.FilesDownloadCheckpoint) {
            return runManagedDownload(data, null);
        }
        return window.FilesDownloadCheckpoint.find(jobKey(totalBytes)).then(function (checkpoint) {
            if (window.FilesDownloadCheckpoint.isResumable(checkpoint)) {
                showResumePrompt(checkpoint, data);
                return;
            }
            return runManagedDownload(data, null);
        }).catch(function () {
            return runManagedDownload(data, null);
        });
    }

    function restartManagedDownload() {
        if (!pendingDownloadData) {
            return;
        }
        var data = pendingDownloadData;
        var checkpoint = pendingCheckpoint;
        var totalBytes = Number(data.bytes) || fileBytes;
        var clearPromise = window.FilesDownloadCheckpoint
            ? window.FilesDownloadCheckpoint.clear(jobKey(totalBytes))
            : Promise.resolve();
        var destroyPromise = Promise.resolve();
        if (checkpoint && checkpoint.opfsPath && window.FilesDownloadSinks) {
            destroyPromise = window.FilesDownloadSinks.destroyOpfsArtifact(checkpoint.opfsPath);
        }
        Promise.all([clearPromise, destroyPromise]).then(function () {
            pendingCheckpoint = null;
            updateProgress(0, totalBytes);
            return runManagedDownload(data, null);
        }).catch(function () {
            showAlert(msg('download.resource.unreadable', ''), true);
        });
    }

    function revealDownload(data) {
        downloadKey = String(data.downloadKey || '');
        var useNative = !!data.useNative || !!data.useManager || ((Number(data.bytes) || 0) > managerThreshold);
        if (downloadLink) {
            if (data.contentBase64) {
                var mime = data.mimeType || 'application/octet-stream';
                var binary = window.atob(data.contentBase64);
                var len = binary.length;
                var bytes = new Uint8Array(len);
                for (var i = 0; i < len; i++) {
                    bytes[i] = binary.charCodeAt(i);
                }
                var blob = new Blob([bytes], { type: mime });
                downloadLink.href = URL.createObjectURL(blob);
                downloadLink.download = data.fileName || 'download.bin';
                downloadLink.classList.remove('d-none');
            } else if (useNative && window.FilesDownloadLauncher) {
                var grant = data.download_grant || '';
                var url = data.downloadUrl || '';
                if (grant && url.indexOf('g=') < 0) {
                    url += (url.indexOf('?') >= 0 ? '&' : '?') + 'g=' + encodeURIComponent(grant);
                }
                downloadLink.href = url;
                downloadLink.setAttribute('download', data.fileName || 'download.bin');
                downloadLink.classList.remove('d-none');
                window.FilesDownloadLauncher.startNativeDownload(data.downloadUrl || '', grant, data.fileName || 'download.bin');
            } else {
                downloadLink.href = data.downloadUrl || (endpointFile + '?key=' + encodeURIComponent(downloadKey));
                downloadLink.classList.remove('d-none');
            }
        }
        showAlert(msg('download.authorized', ''), false);
    }

    function handleVerifyResponse(pack) {
        if (!pack.ok || !pack.json) {
            showAlert(msg(pack.json && pack.json.message, root.getAttribute('data-msg-api-fallback') || ''), true);
            return;
        }
        if (pack.json.passwordRequired) {
            preAuthToken = String(pack.json.preAuthToken || '');
            if (passwordSection) {
                passwordSection.classList.remove('d-none');
            }
            showAlert(msg('download.totp_ok_password_required', ''), false);
            return;
        }
        if (pack.json.status !== 'ok') {
            showAlert(msg(pack.json.message, ''), true);
            return;
        }
        revealDownload(pack.json);
    }

    if (sendBtn) {
        sendBtn.addEventListener('click', function () {
            var email = emailInput ? emailInput.value.trim() : '';
            if (email === '') {
                return;
            }
            postJson(endpointChallenge, {
                publicToken: publicToken,
                email: email
            }).then(function (pack) {
                if (!pack.ok) {
                    showAlert(msg(pack.json && pack.json.message, ''), true);
                    return;
                }
                challengeId = Number(pack.json.challengeId) || 0;
                if (verifyBtn) {
                    verifyBtn.disabled = challengeId < 1;
                }
                showAlert(msg('download.challenge.sent', ''), false);
            }).catch(function () {
                showAlert(root.getAttribute('data-msg-api-fallback') || '', true);
            });
        });
    }

    if (verifyBtn) {
        verifyBtn.addEventListener('click', function () {
            var code = codeInput ? codeInput.value.trim() : '';
            if (challengeId < 1 || code === '') {
                return;
            }
            postJson(endpointVerify, {
                challengeId: challengeId,
                inputCode: code
            }).then(handleVerifyResponse).catch(function () {
                showAlert(root.getAttribute('data-msg-api-fallback') || '', true);
            });
        });
    }

    if (passwordBtn) {
        passwordBtn.addEventListener('click', function () {
            var password = passwordInput ? passwordInput.value : '';
            if (preAuthToken === '' || password === '') {
                return;
            }
            postJson(endpointVerifyPassword, {
                preAuthToken: preAuthToken,
                sharePassword: password
            }).then(handleVerifyResponse).catch(function () {
                showAlert(root.getAttribute('data-msg-api-fallback') || '', true);
            });
        });
    }

    if (resumeBtn) {
        resumeBtn.addEventListener('click', function () {
            if (!pendingDownloadData || !pendingCheckpoint) {
                return;
            }
            runManagedDownload(pendingDownloadData, pendingCheckpoint);
        });
    }

    if (restartBtn) {
        restartBtn.addEventListener('click', restartManagedDownload);
    }

    var cancelBtn = document.getElementById('public-landing-download-cancel');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            if (downloadAbortController) {
                downloadAbortController.abort();
            }
        });
    }
}());
