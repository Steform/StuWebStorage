(function () {
    'use strict';

    /**
     * @brief Client helpers for upload-encrypt background jobs (tick / cancel / URLs).
     * @date 2026-11-09
     * @author Stephane H.
     */

    var TASK_ID_PLACEHOLDER = '00000000000000000000000000000000';
    var POLL_DELAY_MS = 400;

    /**
     * @brief Build a task URL from a Twig template that embeds the placeholder id.
     * @param {string} template Url template.
     * @param {string} taskId Hex task id.
     * @param {string} fallbackSuffix Path suffix after /files/upload/encrypt/{id}/.
     * @return {string}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function buildTaskUrl(template, taskId, fallbackSuffix) {
        var id = String(taskId || '');
        var tpl = String(template || '');
        if (id !== '' && tpl.indexOf(TASK_ID_PLACEHOLDER) >= 0) {
            return tpl.split(TASK_ID_PLACEHOLDER).join(id);
        }
        if (id !== '') {
            return '/files/upload/encrypt/' + encodeURIComponent(id) + '/' + fallbackSuffix;
        }

        return '';
    }

    /**
     * @brief Read encrypt URL templates from the listing live region.
     * @return {{tick: string, cancel: string}}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function readUrlTemplates() {
        var live = document.getElementById('files-live-region');

        return {
            tick: (live && live.getAttribute('data-files-upload-encrypt-tick-url-template')) || '',
            cancel: (live && live.getAttribute('data-files-upload-encrypt-cancel-url-template')) || ''
        };
    }

    /**
     * @brief POST form-urlencoded/FormData and parse JSON.
     * @param {string} url Endpoint.
     * @param {FormData} body Body.
     * @return {Promise<{ok: boolean, json: object}>}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function postForm(url, body) {
        return fetch(url, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (json) {
                return { ok: response.ok, json: json || {} };
            });
        });
    }

    /**
     * @brief Cancel an upload-encrypt job.
     * @param {string} taskId Task id.
     * @param {string} csrf CSRF token.
     * @param {Function} [appendRetainFields] Optional retain-field helper.
     * @param {string} [cancelUrlTemplate] Optional cancel URL template.
     * @return {Promise<object|null>} Task payload or null.
     * @date 2026-11-09
     * @author Stephane H.
     */
    function cancelTask(taskId, csrf, appendRetainFields, cancelUrlTemplate) {
        var templates = readUrlTemplates();
        var url = buildTaskUrl(cancelUrlTemplate || templates.cancel, taskId, 'cancel');
        if (!url) {
            return Promise.resolve(null);
        }
        var fd = new FormData();
        fd.append('_csrf_token', csrf || '');
        if (typeof appendRetainFields === 'function') {
            appendRetainFields(fd);
        }

        return postForm(url, fd).then(function (pack) {
            return pack.json && pack.json.task ? pack.json.task : null;
        }).catch(function () {
            return null;
        });
    }

    /**
     * @brief Poll encrypt ticks until the job reaches a terminal status.
     * @param {object} initialTask Task from finalize.
     * @param {object} options Runtime options.
     * @param {string} options.csrf CSRF token.
     * @param {Function} [options.appendRetainFields] Retain fields helper.
     * @param {Function} [options.isCancelled] When true, requests cancel.
     * @param {Function} [options.onProgress] Progress callback (done, total, task).
     * @param {string} [options.tickUrlTemplate] Tick URL template.
     * @param {string} [options.cancelUrlTemplate] Cancel URL template.
     * @param {Function} [options.msgNetworkError] Network error message getter.
     * @return {Promise<{ok: boolean, message: string, cancelled?: boolean, task?: object}>}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function pollUntilTerminal(initialTask, options) {
        var opts = options && typeof options === 'object' ? options : {};
        var templates = readUrlTemplates();
        var tickTemplate = opts.tickUrlTemplate || templates.tick;
        var cancelTemplate = opts.cancelUrlTemplate || templates.cancel;
        var csrf = opts.csrf || '';
        var networkError = typeof opts.msgNetworkError === 'function'
            ? opts.msgNetworkError
            : function () { return 'network error'; };

        /**
         * @param {object} current Current task payload.
         * @return {Promise<{ok: boolean, message: string, cancelled?: boolean, task?: object}>}
         */
        function step(current) {
            if (typeof opts.isCancelled === 'function' && opts.isCancelled()) {
                return cancelTask(current.taskId, csrf, opts.appendRetainFields, cancelTemplate).then(function (cancelledTask) {
                    return {
                        ok: false,
                        message: '',
                        cancelled: true,
                        task: cancelledTask || current
                    };
                });
            }

            if (typeof opts.onProgress === 'function') {
                opts.onProgress(Number(current.doneBytes) || 0, Number(current.totalBytes) || 0, current);
            }
            if (window.FilesBackgroundTasks && typeof window.FilesBackgroundTasks.upsertTask === 'function') {
                window.FilesBackgroundTasks.upsertTask(current);
            }

            var status = String(current.status || '');
            if (status === 'done') {
                return Promise.resolve({ ok: true, message: '', task: current });
            }
            if (status === 'cancelled') {
                return Promise.resolve({ ok: false, message: '', cancelled: true, task: current });
            }
            if (status === 'failed') {
                return Promise.resolve({
                    ok: false,
                    message: String(current.errorKey || networkError()),
                    task: current
                });
            }

            var tickUrl = buildTaskUrl(tickTemplate, current.taskId, 'tick');
            var fd = new FormData();
            fd.append('_csrf_token', csrf);
            if (typeof opts.appendRetainFields === 'function') {
                opts.appendRetainFields(fd);
            }

            return postForm(tickUrl, fd).then(function (pack) {
                if (!pack.ok || !pack.json || pack.json.status !== 'ok' || !pack.json.task) {
                    throw new Error((pack.json && pack.json.message) || networkError());
                }

                return new Promise(function (resolve) {
                    window.setTimeout(function () {
                        resolve(step(pack.json.task));
                    }, POLL_DELAY_MS);
                });
            });
        }

        return step(initialTask || {});
    }

    window.FilesUploadEncrypt = {
        TASK_ID_PLACEHOLDER: TASK_ID_PLACEHOLDER,
        buildTaskUrl: buildTaskUrl,
        cancelTask: cancelTask,
        pollUntilTerminal: pollUntilTerminal
    };
}());
