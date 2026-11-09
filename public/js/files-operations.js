(function () {
    'use strict';

    /**
     * @brief Famille B operations bus: register, upsert, reduce, resume, cancel.
     * @date 2026-11-09
     * @author Stephane H.
     */

    var MODAL_IDS = {
        files_upload: 'filesUploadModal',
        files_extract_zip: 'filesExtractZipModal',
        files_archive: 'filesArchiveProgressModal',
        files_download: 'filesDownloadOperationModal'
    };
    var DOWNLOAD_TYPES = { download_file: true, download_archive: true };
    var STORAGE_KEY = 'stuweb.ops.downloads.v1';
    var executors = {};
    var tasks = {};
    var reducingIds = {};
    var dismissTaskId = null;

    /**
     * @brief Register an executor for one operation type.
     * @param {string} type Task type.
     * @param {{hydrate?: Function, cancel?: Function, isRunning?: Function}} executor Executor API.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function register(type, executor) {
        executors[String(type || '')] = executor || {};
    }

    /**
     * @brief Merge and store a task, then refresh the operations center.
     * @param {object} task Task payload.
     * @return {object|null}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function upsert(task) {
        if (!task || !task.taskId) {
            return null;
        }
        var id = String(task.taskId);
        tasks[id] = Object.assign({}, tasks[id] || {}, task, { taskId: id });
        persistLocalDownload(tasks[id]);
        if (window.FilesOperationsCenter && typeof window.FilesOperationsCenter.upsert === 'function') {
            window.FilesOperationsCenter.upsert(tasks[id]);
        }

        return tasks[id];
    }

    /**
     * @brief Read a stored task.
     * @param {string} taskId Task id.
     * @return {object|null}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function getTask(taskId) {
        return tasks[String(taskId || '')] || null;
    }

    /**
     * @brief Map modalKey to the DOM modal element.
     * @param {object} task Task payload.
     * @return {HTMLElement|null}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function modalElFor(task) {
        if (!task) {
            return null;
        }
        var id = MODAL_IDS[String(task.modalKey || '')] || '';

        return id ? document.getElementById(id) : null;
    }

    /**
     * @brief Toggle Reduce visibility and aria-busy on a Famille B modal.
     * @param {HTMLElement|null} el Modal root.
     * @param {boolean} on Whether the operation is running.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function setRunningChrome(el, on) {
        if (!el) {
            return;
        }
        el.classList.toggle('ops-modal--running', !!on);
        el.setAttribute('aria-busy', on ? 'true' : 'false');
        if (on) {
            el.setAttribute('data-bs-backdrop', 'static');
        }
        el.querySelectorAll('[data-ops-reduce]').forEach(function (btn) {
            btn.classList.toggle('d-none', !on);
        });
    }

    /**
     * @brief Mark a modal as hosting a running task.
     * @param {HTMLElement|null} el Modal root.
     * @param {object} task Task payload.
     * @return {object|null}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function markRunning(el, task) {
        var stored = upsert(task);
        if (el && stored) {
            el.setAttribute('data-ops-active-task', stored.taskId);
            setRunningChrome(el, true);
        }

        return stored;
    }

    /**
     * @brief Clear running chrome after a terminal state.
     * @param {HTMLElement|null} el Modal root.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function markIdle(el) {
        if (!el) {
            return;
        }
        setRunningChrome(el, false);
        el.removeAttribute('data-ops-active-task');
    }

    /**
     * @brief Hide a Bootstrap modal.
     * @param {HTMLElement|null} el Modal root.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function hideModal(el) {
        if (!el || !window.bootstrap || !window.bootstrap.Modal) {
            return;
        }
        window.bootstrap.Modal.getOrCreateInstance(el).hide();
    }

    /**
     * @brief Show a Bootstrap modal.
     * @param {HTMLElement|null} el Modal root.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function showModal(el) {
        if (!el || !window.bootstrap || !window.bootstrap.Modal) {
            return;
        }
        window.bootstrap.Modal.getOrCreateInstance(el).show();
    }

    /**
     * @brief Reduce the operation modal into the operations center.
     * @param {string} taskId Task id.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function reduce(taskId) {
        var task = getTask(taskId);
        if (!task) {
            return;
        }
        reducingIds[String(taskId)] = true;
        hideModal(modalElFor(task));
    }

    /**
     * @brief Hide every other visible Famille B modal (auto-reduce).
     * @param {string} keepTaskId Task that stays visible.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function reduceOthers(keepTaskId) {
        document.querySelectorAll('[data-ops-family="b"].show').forEach(function (el) {
            var active = el.getAttribute('data-ops-active-task') || '';
            if (active && active !== keepTaskId) {
                reduce(active);
                return;
            }
            if (!active) {
                reducingIds.__anon = true;
                hideModal(el);
            }
        });
    }

    /**
     * @brief Reopen the operation modal and hydrate live state.
     * @param {string} taskId Task id.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function resume(taskId) {
        var task = getTask(taskId);
        if (!task) {
            return;
        }
        reduceOthers(String(taskId));
        var el = modalElFor(task);
        if (!el) {
            window.location.href = '/files';
            return;
        }
        var executor = executors[String(task.type || '')] || {};
        if (el) {
            el.setAttribute('data-ops-active-task', task.taskId);
            setRunningChrome(el, task.status === 'running' || task.status === 'handed_to_browser');
            showModal(el);
        }
        if (typeof executor.hydrate === 'function') {
            executor.hydrate(task);
        }
    }

    /**
     * @brief Ask the type executor to cancel the operation for real.
     * @param {string} taskId Task id.
     * @return {Promise<object|null>}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function cancel(taskId) {
        var task = getTask(taskId);
        if (!task) {
            return Promise.resolve(null);
        }
        var executor = executors[String(task.type || '')] || {};
        if (typeof executor.cancel !== 'function') {
            return Promise.resolve(null);
        }

        return Promise.resolve(executor.cancel(task)).then(function (result) {
            var next = result && result.taskId
                ? result
                : Object.assign({}, task, { status: 'cancelled', cancelSupported: false });
            upsert(next);
            var el = modalElFor(next);
            markIdle(el);
            reducingIds[String(taskId)] = true;
            hideModal(el);

            return next;
        });
    }

    /**
     * @brief Open the unique Reduce / Stay / Cancel dialog.
     * @param {string} taskId Task id.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function openDismiss(taskId) {
        var modal = document.getElementById('opsDismissModal');
        if (!modal || !window.bootstrap || !window.bootstrap.Modal) {
            reduce(taskId);
            return;
        }
        dismissTaskId = String(taskId);
        var task = getTask(taskId);
        var body = modal.querySelector('[data-ops-dismiss-body]');
        var root = document.getElementById('files-operations-center');
        if (body && task) {
            var typeLabel = root
                ? (root.getAttribute('data-type-' + task.type) || task.type)
                : String(task.type || '');
            var tpl = body.getAttribute('data-ops-dismiss-template') || body.textContent || '';
            body.textContent = tpl.split('%operation%').join(typeLabel);
        }
        window.bootstrap.Modal.getOrCreateInstance(modal).show();
        window.setTimeout(function () {
            var reduceBtn = modal.querySelector('[data-ops-dismiss-reduce]');
            if (reduceBtn) {
                reduceBtn.focus();
            }
        }, 50);
    }

    /**
     * @brief Intercept Famille B hide while running and wire Reduce / Cancel.
     * @param {HTMLElement} el Modal root.
     * @param {{isRunning?: Function, getTask?: Function}} [options] Runtime hooks.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function bindFamilyB(el, options) {
        if (!el || el.getAttribute('data-ops-bound') === '1') {
            return;
        }
        el.setAttribute('data-ops-bound', '1');
        var opts = options && typeof options === 'object' ? options : {};
        el.addEventListener('hide.bs.modal', function (ev) {
            var taskId = el.getAttribute('data-ops-active-task') || '';
            if (reducingIds[taskId] || reducingIds.__anon) {
                delete reducingIds[taskId];
                delete reducingIds.__anon;
                return;
            }
            var running = typeof opts.isRunning === 'function'
                ? !!opts.isRunning()
                : el.classList.contains('ops-modal--running');
            if (!running) {
                return;
            }
            ev.preventDefault();
            if (!taskId && typeof opts.getTask === 'function') {
                var live = opts.getTask();
                if (live && live.taskId) {
                    upsert(live);
                    taskId = String(live.taskId);
                    el.setAttribute('data-ops-active-task', taskId);
                }
            }
            if (taskId) {
                openDismiss(taskId);
            }
        });
        el.querySelectorAll('[data-ops-reduce]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = el.getAttribute('data-ops-active-task') || '';
                if (id) {
                    reduce(id);
                }
            });
        });
        el.querySelectorAll('[data-ops-cancel]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = el.getAttribute('data-ops-active-task') || '';
                if (id) {
                    cancel(id);
                }
            });
        });
    }

    /**
     * @brief Persist client-only download tasks for F5 restore.
     * @param {object} task Task payload.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function persistLocalDownload(task) {
        if (!DOWNLOAD_TYPES[String(task.type || '')] || !window.sessionStorage) {
            return;
        }
        var bag = readLocalDownloads();
        bag[task.taskId] = {
            taskId: task.taskId,
            type: task.type,
            status: task.status,
            label: task.label,
            modalKey: 'files_download',
            progressKnown: false,
            cancelSupported: task.status === 'running',
            percent: 0,
            payload: task.payload || {}
        };
        try {
            window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(bag));
        } catch (ignore) {
            return;
        }
    }

    /**
     * @brief Read download tasks stored in sessionStorage.
     * @return {Object<string, object>}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function readLocalDownloads() {
        if (!window.sessionStorage) {
            return {};
        }
        try {
            var raw = window.sessionStorage.getItem(STORAGE_KEY);
            var parsed = raw ? JSON.parse(raw) : {};

            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (ignore) {
            return {};
        }
    }

    /**
     * @brief Drop a client-only download line (hide / ack).
     * @param {string} taskId Task id.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function forgetLocalDownload(taskId) {
        var bag = readLocalDownloads();
        delete bag[String(taskId || '')];
        delete tasks[String(taskId || '')];
        try {
            window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(bag));
        } catch (ignore) {
            return;
        }
        if (window.FilesOperationsCenter && typeof window.FilesOperationsCenter.remove === 'function') {
            window.FilesOperationsCenter.remove(taskId);
        }
    }

    /**
     * @brief Hydrate client download tasks into the bus.
     * @return {object[]}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function loadLocalDownloads() {
        var bag = readLocalDownloads();
        var out = [];
        Object.keys(bag).forEach(function (id) {
            out.push(upsert(bag[id]));
        });

        return out;
    }

    /**
     * @brief Wire the unique dismiss dialog once.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function bindDismissModal() {
        var modal = document.getElementById('opsDismissModal');
        if (!modal || modal.getAttribute('data-ops-bound') === '1') {
            return;
        }
        modal.setAttribute('data-ops-bound', '1');
        var api = window.bootstrap && window.bootstrap.Modal
            ? window.bootstrap.Modal.getOrCreateInstance(modal)
            : null;
        var stayBtn = modal.querySelector('[data-ops-dismiss-stay]');
        var reduceBtn = modal.querySelector('[data-ops-dismiss-reduce]');
        var cancelBtn = modal.querySelector('[data-ops-dismiss-cancel]');
        if (stayBtn) {
            stayBtn.addEventListener('click', function () {
                if (api) {
                    api.hide();
                }
                dismissTaskId = null;
            });
        }
        if (reduceBtn) {
            reduceBtn.addEventListener('click', function () {
                var id = dismissTaskId;
                if (api) {
                    api.hide();
                }
                if (id) {
                    reduce(id);
                }
                dismissTaskId = null;
            });
        }
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                var id = dismissTaskId;
                if (api) {
                    api.hide();
                }
                if (id) {
                    cancel(id);
                }
                dismissTaskId = null;
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        bindDismissModal();
        document.querySelectorAll('[data-ops-family="b"]').forEach(function (el) {
            bindFamilyB(el);
        });
        loadLocalDownloads();
    });

    window.FilesOperations = {
        register: register,
        upsert: upsert,
        reduce: reduce,
        resume: resume,
        cancel: cancel,
        get: getTask,
        markRunning: markRunning,
        markIdle: markIdle,
        bindFamilyB: bindFamilyB,
        loadLocalDownloads: loadLocalDownloads,
        forgetLocalDownload: forgetLocalDownload,
        modalIds: MODAL_IDS
    };
}());
