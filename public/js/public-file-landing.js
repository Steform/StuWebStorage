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
    var endpointTick = '/download/public/prepare/tick';
    var endpointDeliver = '/download/public/deliver';
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

    var challengeId = 0;
    var preAuthToken = '';
    var downloadKey = '';
    var prepareJobId = '';
    var prepareRequired = false;
    var tickTimer = null;
    var tickInFlight = false;

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

    function stopTickLoop() {
        if (tickTimer !== null) {
            window.clearTimeout(tickTimer);
            tickTimer = null;
        }
    }

    function scheduleTick() {
        stopTickLoop();
        tickTimer = window.setTimeout(runPrepareTick, 300);
    }

    function runPrepareTick() {
        if (!prepareRequired || downloadKey === '' || prepareJobId === '' || tickInFlight) {
            return;
        }
        tickInFlight = true;
        postJson(endpointTick, {
            downloadKey: downloadKey,
            jobId: prepareJobId
        }).then(function (pack) {
            tickInFlight = false;
            if (!pack.ok || !pack.json || pack.json.status !== 'ok') {
                showAlert(msg('download.resource.unreadable', ''), true);
                stopTickLoop();
                return;
            }
            var percent = Number(pack.json.percent) || 0;
            showAlert(formatBytes(pack.json.plain_written) + ' / ' + formatBytes(pack.json.bytes) + ' (' + percent + '%)', false);
            if (pack.json.complete) {
                stopTickLoop();
                window.location.href = endpointDeliver + '?key=' + encodeURIComponent(downloadKey) + '&job=' + encodeURIComponent(prepareJobId);
                return;
            }
            scheduleTick();
        }).catch(function () {
            tickInFlight = false;
            showAlert(root.getAttribute('data-msg-api-fallback') || '', true);
            stopTickLoop();
        });
    }

    function revealDownload(data) {
        downloadKey = String(data.downloadKey || '');
        prepareRequired = !!data.prepareRequired;
        prepareJobId = String(data.jobId || '');
        if (downloadLink) {
            if (prepareRequired) {
                downloadLink.href = '#';
                downloadLink.classList.remove('d-none');
                showAlert(msg('download.authorized', ''), false);
                scheduleTick();
                return;
            }
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
            } else {
                downloadLink.href = endpointFile + '?key=' + encodeURIComponent(downloadKey);
            }
            downloadLink.classList.remove('d-none');
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

    if (downloadLink) {
        downloadLink.addEventListener('click', function (event) {
            if (prepareRequired) {
                event.preventDefault();
                if (prepareJobId !== '') {
                    window.location.href = endpointDeliver + '?key=' + encodeURIComponent(downloadKey) + '&job=' + encodeURIComponent(prepareJobId);
                }
            }
        });
    }
}());
