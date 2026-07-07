(function () {
    'use strict';

    var root = document.getElementById('files-download-progress-page');
    if (!root || typeof window.FilesDownloadManager === 'undefined') {
        return;
    }

    var tokenUrl = root.getAttribute('data-token-url') || '';
    var downloadUrl = root.getAttribute('data-download-url') || '';
    var csrfToken = root.getAttribute('data-csrf-token') || '';
    var fileName = root.getAttribute('data-file-name') || 'download.bin';
    var bar = document.getElementById('files-download-progress-bar');
    var count = document.getElementById('files-download-progress-count');
    var errorEl = document.getElementById('files-download-progress-error');
    var abortController = new AbortController();

    function showError(message) {
        if (!errorEl) {
            return;
        }
        errorEl.textContent = message || root.getAttribute('data-msg-error') || '';
        errorEl.classList.remove('d-none');
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

    requestToken().then(function (pack) {
        if (!pack.ok || !pack.json || pack.json.status !== 'ok') {
            showError((pack.json && pack.json.message) || '');
            return;
        }
        return window.FilesDownloadManager.download({
            url: downloadUrl,
            fileName: fileName,
            downloadToken: pack.json.download_token || '',
            signal: abortController.signal,
            onProgress: updateProgress,
            onError: function () {}
        });
    }).catch(function (error) {
        if (error && error.name === 'AbortError') {
            return;
        }
        showError('');
    });

    var cancelBtn = document.getElementById('files-download-progress-cancel');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            abortController.abort();
        });
    }
}());
