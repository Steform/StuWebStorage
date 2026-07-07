(function () {
    'use strict';

    var root = document.getElementById('public-landing-root');
    if (!root) {
        return;
    }

    var publicToken = root.getAttribute('data-public-token') || '';
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

    var challengeId = 0;
    var preAuthToken = '';
    var downloadKey = '';
    var downloadAbortController = null;

    function msg(key, fallback) {
        return apiMsgs[key] || fallback || key;
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

    function startManagedDownload(data) {
        if (typeof window.FilesDownloadManager === 'undefined') {
            if (downloadLink) {
                downloadLink.href = data.downloadUrl || (endpointFile + '?key=' + encodeURIComponent(downloadKey));
                downloadLink.classList.remove('d-none');
            }
            return;
        }
        if (progressWrap) {
            progressWrap.classList.remove('d-none');
        }
        if (downloadLink) {
            downloadLink.classList.add('d-none');
        }
        downloadAbortController = new AbortController();
        window.FilesDownloadManager.download({
            url: data.downloadUrl || (endpointFile + '?key=' + encodeURIComponent(downloadKey)),
            fileName: data.fileName || 'download.bin',
            signal: downloadAbortController.signal,
            onProgress: updateProgress,
            onError: function () {}
        }).then(function () {
            showAlert(msg('download.authorized', ''), false);
        }).catch(function (error) {
            if (error && error.name === 'AbortError') {
                return;
            }
            showAlert(msg('download.resource.unreadable', ''), true);
        });
    }

    function revealDownload(data) {
        downloadKey = String(data.downloadKey || '');
        var useManager = !!data.useManager || ((Number(data.bytes) || 0) > managerThreshold);
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
            } else if (useManager) {
                startManagedDownload(data);
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

    var cancelBtn = document.getElementById('public-landing-download-cancel');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            if (downloadAbortController) {
                downloadAbortController.abort();
            }
        });
    }
}());
