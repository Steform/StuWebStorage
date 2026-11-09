(function () {
    'use strict';

    /**
     * @brief Create/prepare virtual ZIP archives as a Famille B operation.
     * @date 2026-11-02
     * @author Stephane H.
     */

    var activeToken = '';
    var cancelRequested = false;
    var tickInFlight = false;
    var csrfToken = '';
    var modalEl = document.getElementById('filesArchiveProgressModal');

    /**
     * @brief Read archive API URLs and CSRF from the listing live region.
     * @return {{createUrl: string, csrf: string}|null}
     * @date 2026-11-02
     * @author Stephane H.
     */
    function readConfig() {
        var live = document.getElementById('files-live-region');
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
     * @brief Map archive JSON to an OPS task.
     * @param {object} payload Archive API payload.
     * @return {object}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function taskFromPayload(payload) {
        var token = String((payload && (payload.token || (payload.payload && payload.payload.archiveToken))) || activeToken);
        var archiveStatus = String((payload && (payload.archiveStatus || payload.status)) || 'preparing');
        var status = 'running';
        if (archiveStatus === 'ready' || archiveStatus === 'ok' && payload.download_grant) {
            status = 'done';
        }
        if (archiveStatus === 'failed' || archiveStatus === 'cancelled') {
            status = 'failed';
        }
        if (payload && payload.errorCode === 'archive.cancelled') {
            status = 'cancelled';
        }
        var prepared = Number((payload && payload.preparedBytes) || 0);
        var total = Number((payload && payload.totalBytes) || 0);
        var percent = total > 0 ? Math.min(100, Math.floor((prepared / total) * 100)) : 0;

        return {
            taskId: token,
            type: 'archive_prepare',
            status: status === 'done' && payload && payload.download_grant ? 'done' : status,
            label: String((payload && payload.fileName) || 'archive.zip'),
            phaseKey: 'files.ops.phase.archive',
            doneBytes: prepared,
            totalBytes: total,
            percent: percent,
            progressKnown: true,
            cancelSupported: status === 'running',
            modalKey: 'files_archive',
            payload: {
                archiveToken: token,
                archiveStatus: archiveStatus,
                fileName: String((payload && payload.fileName) || 'archive.zip'),
                downloadUrl: (payload && payload.download_url) || '',
                grant: (payload && payload.download_grant) || ''
            }
        };
    }

    /**
     * @brief ETA templates from the archive progress modal datasets.
     * @return {object}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function etaTemplates() {
        if (!modalEl) {
            return {};
        }

        return {
            calculating: modalEl.getAttribute('data-msg-eta-calculating') || '',
            remaining: modalEl.getAttribute('data-msg-eta-remaining') || '',
            speed: modalEl.getAttribute('data-msg-eta-speed') || '',
            line: modalEl.getAttribute('data-msg-eta-line') || '',
            units: {
                s: modalEl.getAttribute('data-msg-eta-unit-s') || 's',
                m: modalEl.getAttribute('data-msg-eta-unit-m') || 'min',
                h: modalEl.getAttribute('data-msg-eta-unit-h') || 'h'
            }
        };
    }

    var prepareEta = window.TransferEta && typeof window.TransferEta.createEstimator === 'function'
        ? window.TransferEta.createEstimator()
        : null;

    /**
     * @brief Refresh prepare progress bar, status, ETA, and OPS row.
     * @param {object} payload Archive tick/create payload.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function updateUi(payload) {
        var statusEl = document.getElementById('files-archive-progress-status');
        var etaEl = document.getElementById('files-archive-progress-eta');
        var barEl = document.getElementById('files-archive-progress-bar');
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
        if (window.FilesOperations) {
            window.FilesOperations.upsert(taskFromPayload(payload));
        }
    }

    /**
     * @brief POST archive cancel for the active token.
     * @param {string} token Archive token.
     * @param {string} csrf CSRF token.
     * @return {Promise<object|null>}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function cancelPrepare(token, csrf) {
        cancelRequested = true;
        var id = String(token || activeToken || '');
        if (id === '') {
            return Promise.resolve(null);
        }
        var body = new URLSearchParams();
        body.append('_csrf_token', csrf || csrfToken);
        return postForm('/files/archives/' + encodeURIComponent(id) + '/cancel', body).then(function (pack) {
            var next = taskFromPayload(pack.json || { token: id, archiveStatus: 'failed', errorCode: 'archive.cancelled' });
            next.status = 'cancelled';
            next.cancelSupported = false;
            if (window.FilesOperations) {
                window.FilesOperations.markIdle(modalEl);
                window.FilesOperations.upsert(next);
            }
            activeToken = '';

            return next;
        });
    }

    /**
     * @brief Poll archive preparation until ready, failed, or cancelled.
     * @param {string} token Archive token.
     * @param {string} csrf CSRF token.
     * @param {function} onProgress Progress callback.
     * @return {Promise<object>}
     * @date 2026-11-02
     * @author Stephane H.
     */
    function tickUntilReady(token, csrf, onProgress) {
        activeToken = String(token || '');
        csrfToken = csrf || csrfToken;
        var tickUrl = '/files/archives/' + encodeURIComponent(activeToken) + '/tick';
        function once() {
            if (cancelRequested) {
                return Promise.reject(new Error('archive.cancelled'));
            }
            if (tickInFlight) {
                return Promise.resolve({});
            }
            tickInFlight = true;
            var body = new URLSearchParams();
            body.append('_csrf_token', csrfToken);
            return postForm(tickUrl, body).then(function (pack) {
                tickInFlight = false;
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
            }).catch(function (err) {
                tickInFlight = false;
                throw err;
            });
        }

        return once();
    }

    /**
     * @brief Hand a ready archive to the native download cockpit.
     * @param {object} payload Ready archive payload.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function handOffDownload(payload) {
        var token = String(payload.token || activeToken);
        activeToken = '';
        cancelRequested = false;
        if (window.FilesOperations) {
            window.FilesOperations.markIdle(modalEl);
            if (token !== '') {
                window.FilesOperations.reduce(token);
            }
        } else if (modalEl && window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        }
        if (window.FilesDownloadOperation && typeof window.FilesDownloadOperation.open === 'function') {
            window.FilesDownloadOperation.open({
                type: 'download_archive',
                fileName: payload.fileName || 'archive.zip',
                byteSize: Number(payload.totalBytes) || 0,
                downloadUrl: payload.download_url || '',
                grant: payload.download_grant || '',
                archiveToken: payload.token || activeToken
            });
            return;
        }
        if (window.FilesDownloadLauncher) {
            window.FilesDownloadLauncher.startNativeDownload(
                payload.download_url || '',
                payload.download_grant || '',
                payload.fileName || 'archive.zip'
            );
        }
    }

    /**
     * @brief Create an archive from selected ids and follow OPS prepare → download.
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
        cancelRequested = false;
        csrfToken = config.csrf;
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

        var etaEl = document.getElementById('files-archive-progress-eta');
        if (prepareEta) {
            prepareEta.reset();
        }
        if (etaEl) {
            etaEl.textContent = '';
        }

        return postForm(config.createUrl, body).then(function (pack) {
            if (!pack.ok || !pack.json || (pack.json.status !== 'ok' && pack.json.status !== 'preparing' && !pack.json.token)) {
                throw new Error((pack.json && pack.json.message) || 'download.archive_create_failed');
            }
            activeToken = String(pack.json.token || '');
            if (window.FilesOperations) {
                window.FilesOperations.markRunning(modalEl, taskFromPayload(pack.json));
                window.FilesOperations.resume(activeToken);
            } else if (modalEl && window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
            updateUi(pack.json);
            var archiveStatus = pack.json.archiveStatus || '';
            if (archiveStatus === 'ready' || pack.json.download_grant) {
                return pack.json;
            }

            return tickUntilReady(pack.json.token, config.csrf, updateUi);
        }).then(function (payload) {
            if (etaEl) {
                etaEl.textContent = '';
            }
            handOffDownload(payload);
        }).catch(function (error) {
            var statusEl = document.getElementById('files-archive-progress-status');
            if (statusEl) {
                statusEl.textContent = (error && error.message) || 'error';
            }
            if (etaEl) {
                etaEl.textContent = '';
            }
            throw error;
        });
    }

    if (window.FilesOperations && modalEl) {
        window.FilesOperations.register('archive_prepare', {
            hydrate: function (task) {
                var token = String((task && task.payload && task.payload.archiveToken) || (task && task.taskId) || '');
                if (token === '') {
                    return;
                }
                activeToken = token;
                if (task.status === 'running' && !tickInFlight) {
                    cancelRequested = false;
                    var config = readConfig();
                    tickUntilReady(token, (config && config.csrf) || csrfToken, updateUi).then(function (payload) {
                        handOffDownload(payload);
                    }).catch(function () {
                        return null;
                    });
                }
            },
            cancel: function (task) {
                var token = String((task && task.payload && task.payload.archiveToken) || (task && task.taskId) || activeToken);
                var config = readConfig();

                return cancelPrepare(token, (config && config.csrf) || csrfToken);
            },
            abortLocal: function () {
                cancelRequested = true;
                tickInFlight = false;
                activeToken = '';
            },
            isRunning: function () {
                return activeToken !== '' && !cancelRequested;
            }
        });
        window.FilesOperations.bindFamilyB(modalEl, {
            isRunning: function () {
                return activeToken !== '' && !cancelRequested;
            },
            getTask: function () {
                if (activeToken === '') {
                    return null;
                }

                return taskFromPayload({ token: activeToken, archiveStatus: 'preparing', fileName: 'archive.zip' });
            }
        });
    }

    window.FilesArchiveFlow = {
        downloadSelectionAsZip: downloadSelectionAsZip,
        tickUntilReady: tickUntilReady
    };
}());
