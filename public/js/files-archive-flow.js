(function () {
    'use strict';

    /**
     * @brief Create/prepare/download virtual ZIP archives from the files space UI.
     * @date 2026-11-02
     * @author Stephane H.
     */
    function getLiveRegion() {
        return document.getElementById('files-live-region');
    }

    /**
     * @brief Read archive API URLs and CSRF from the listing live region.
     * @return {{createUrl: string, csrf: string}|null}
     * @date 2026-11-02
     * @author Stephane H.
     */
    function readConfig() {
        var live = getLiveRegion();
        if (!live) {
            return null;
        }
        return {
            createUrl: live.getAttribute('data-files-archives-create-url') || '',
            csrf: live.getAttribute('data-files-archives-csrf') || ''
        };
    }

    /**
     * @brief POST form-urlencoded payload and parse JSON.
     * @param {string} url Endpoint.
     * @param {URLSearchParams|FormData} body Body.
     * @return {Promise<{ok: boolean, json: object}>}
     * @date 2026-11-02
     * @author Stephane H.
     */
    function postForm(url, body) {
        return fetch(url, {
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
     * @brief Poll archive preparation until ready or failed.
     * @param {string} token Archive token.
     * @param {string} csrf CSRF token.
     * @param {function} onProgress Progress callback.
     * @return {Promise<object>}
     * @date 2026-11-02
     * @author Stephane H.
     */
    function tickUntilReady(token, csrf, onProgress) {
        var tickUrl = '/files/archives/' + encodeURIComponent(token) + '/tick';
        function once() {
            var body = new URLSearchParams();
            body.append('_csrf_token', csrf);
            return postForm(tickUrl, body).then(function (pack) {
                if (!pack.ok || !pack.json) {
                    throw new Error((pack.json && pack.json.message) || 'download.archive_tick_failed');
                }
                if (typeof onProgress === 'function') {
                    onProgress(pack.json);
                }
                var status = pack.json.archiveStatus || pack.json.status;
                if (status === 'ready') {
                    return pack.json;
                }
                if (status === 'failed') {
                    throw new Error(pack.json.errorCode || 'download.archive_failed');
                }
                return new Promise(function (resolve) {
                    window.setTimeout(function () {
                        resolve(once());
                    }, 500);
                });
            });
        }
        return once();
    }

    /**
     * @brief Create an archive from selected ids and download it natively when ready.
     * @param {number[]} fileIds Selected file ids.
     * @param {number[]} folderIds Selected folder ids.
     * @param {object|null} selectionContext Optional admin context.
     * @return {Promise<void>}
     * @date 2026-11-02
     * @author Stephane H.
     */
    function downloadSelectionAsZip(fileIds, folderIds, selectionContext) {
        var config = readConfig();
        if (!config || config.createUrl === '') {
            return Promise.reject(new Error('download.archive_config_missing'));
        }
        var body = new URLSearchParams();
        body.append('_csrf_token', config.csrf);
        (fileIds || []).forEach(function (id) {
            body.append('ids[]', String(id));
        });
        (folderIds || []).forEach(function (id) {
            body.append('folder_ids[]', String(id));
        });
        var ctx = selectionContext && typeof selectionContext === 'object' ? selectionContext : null;
        if (ctx && ctx.adminContext === true && String(ctx.adminViewScope || '') === 'all') {
            if (typeof ctx.subjectUserId === 'string' && ctx.subjectUserId.trim() !== '') {
                body.set('subject_user', ctx.subjectUserId.trim());
            }
        }

        var modal = document.getElementById('filesArchiveProgressModal');
        var statusEl = document.getElementById('files-archive-progress-status');
        var etaEl = document.getElementById('files-archive-progress-eta');
        var barEl = document.getElementById('files-archive-progress-bar');
        var prepareEta = window.TransferEta && typeof window.TransferEta.createEstimator === 'function'
            ? window.TransferEta.createEstimator()
            : null;
        if (prepareEta) {
            prepareEta.reset();
        }
        if (etaEl) {
            etaEl.textContent = '';
        }
        if (modal && window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modal).show();
        }

        /**
         * @brief ETA templates from the archive progress modal datasets.
         * @return {object}
         * @date 2026-11-09
         * @author Stephane H.
         */
        function etaTemplates() {
            if (!modal) {
                return {};
            }
            return {
                calculating: modal.getAttribute('data-msg-eta-calculating') || '',
                remaining: modal.getAttribute('data-msg-eta-remaining') || '',
                speed: modal.getAttribute('data-msg-eta-speed') || '',
                line: modal.getAttribute('data-msg-eta-line') || '',
                units: {
                    s: modal.getAttribute('data-msg-eta-unit-s') || 's',
                    m: modal.getAttribute('data-msg-eta-unit-m') || 'min',
                    h: modal.getAttribute('data-msg-eta-unit-h') || 'h'
                }
            };
        }

        /**
         * @brief Refresh prepare progress bar, status, and ETA.
         * @param {object} payload Archive tick/create payload.
         * @return {void}
         * @date 2026-11-09
         * @author Stephane H.
         */
        function updateUi(payload) {
            var prepared = Number(payload.preparedBytes) || 0;
            var total = Number(payload.totalBytes) || 0;
            var percent = total > 0 ? Math.min(100, Math.floor((prepared / total) * 100)) : 0;
            if (barEl) {
                barEl.style.width = percent + '%';
                barEl.setAttribute('aria-valuenow', String(percent));
            }
            if (statusEl) {
                statusEl.textContent = (payload.fileName || '') + ' — ' + percent + '%';
            }
            if (etaEl && prepareEta && window.TransferEta) {
                var snap = prepareEta.update(prepared, total);
                if (snap.shouldRefreshUi || prepared >= total) {
                    etaEl.textContent = window.TransferEta.formatLine(snap, etaTemplates());
                }
            }
        }

        return postForm(config.createUrl, body).then(function (pack) {
            if (!pack.ok || !pack.json || (pack.json.status !== 'ok' && pack.json.status !== 'preparing' && !pack.json.token)) {
                throw new Error((pack.json && pack.json.message) || 'download.archive_create_failed');
            }
            updateUi(pack.json);
            var archiveStatus = pack.json.archiveStatus || '';
            if (archiveStatus === 'ready' || pack.json.download_grant) {
                return pack.json;
            }
            return tickUntilReady(pack.json.token, config.csrf, updateUi);
        }).then(function (payload) {
            if (!window.FilesDownloadLauncher) {
                throw new Error('download.launcher_missing');
            }
            // Native browser download has no JS byte events → no in-page ETA after this point.
            window.FilesDownloadLauncher.startNativeDownload(
                payload.download_url || '',
                payload.download_grant || '',
                payload.fileName || 'archive.zip'
            );
            if (etaEl) {
                etaEl.textContent = '';
            }
            if (modal && window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modal).hide();
            }
        }).catch(function (error) {
            if (statusEl) {
                statusEl.textContent = (error && error.message) || 'error';
            }
            if (etaEl) {
                etaEl.textContent = '';
            }
            throw error;
        });
    }

    window.FilesArchiveFlow = {
        downloadSelectionAsZip: downloadSelectionAsZip,
        tickUntilReady: tickUntilReady
    };
}());
