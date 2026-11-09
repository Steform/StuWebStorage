(function () {
    'use strict';

    /**
     * @brief Trigger a native browser download from a grant-backed URL.
     * @param {string} downloadUrl Absolute or relative download URL without grant.
     * @param {string} grant Grant query value.
     * @param {string} fileName Suggested file name.
     * @return {void}
     * @date 2026-11-02
     * @author Stephane H.
     */
    function startNativeDownload(downloadUrl, grant, fileName) {
        var url = String(downloadUrl || '');
        if (url === '') {
            return;
        }
        if (grant) {
            url += (url.indexOf('?') >= 0 ? '&' : '?') + 'g=' + encodeURIComponent(grant);
        }
        var anchor = document.createElement('a');
        anchor.href = url;
        if (fileName) {
            anchor.setAttribute('download', fileName);
        }
        anchor.rel = 'noopener';
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
    }

    /**
     * @brief Copy a resumable download URL to the clipboard.
     * @param {string} downloadUrl Download URL without grant.
     * @param {string} grant Grant value.
     * @return {Promise<void>}
     * @date 2026-11-02
     * @author Stephane H.
     */
    function copyResumableLink(downloadUrl, grant) {
        var url = String(downloadUrl || '');
        if (grant) {
            url += (url.indexOf('?') >= 0 ? '&' : '?') + 'g=' + encodeURIComponent(grant);
        }
        if (window.location && url.indexOf('http') !== 0) {
            url = window.location.origin + url;
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(url);
        }
        return Promise.reject(new Error('download.clipboard_unavailable'));
    }

    /**
     * @brief Request a download grant then start a native download.
     * @param {object} config Runtime configuration.
     * @return {Promise<object>}
     * @date 2026-11-02
     * @author Stephane H.
     */
    function requestGrantAndDownload(config) {
        var tokenUrl = String(config.tokenUrl || '');
        var csrfToken = String(config.csrfToken || '');
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
        }).then(function (pack) {
            if (!pack.ok || !pack.json || pack.json.status !== 'ok') {
                throw new Error((pack.json && pack.json.message) || 'download.grant_failed');
            }
            var grant = pack.json.download_grant || pack.json.download_token || '';
            var downloadUrl = pack.json.download_url || '';
            var fileName = pack.json.file_name || config.fileName || 'download.bin';
            startNativeDownload(downloadUrl, grant, fileName);
            return pack.json;
        });
    }

    window.FilesDownloadLauncher = {
        startNativeDownload: startNativeDownload,
        copyResumableLink: copyResumableLink,
        requestGrantAndDownload: requestGrantAndDownload
    };
}());
