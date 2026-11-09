(function () {
    'use strict';

    /**
     * @brief Operations-center dropdown: list, badge, resume, cancel.
     * @date 2026-11-09
     * @author Stephane H.
     */

    var root = document.getElementById('files-operations-center');
    if (!root) {
        return;
    }

    var menuEl = document.getElementById('files-operations-center-menu');
    var badgeEl = document.getElementById('files-operations-center-badge');
    var listUrl = root.getAttribute('data-ops-list-url') || '/files/operations';
    var ackUrl = root.getAttribute('data-ops-ack-url') || '/files/operations/ack';
    var purgeUrl = root.getAttribute('data-ops-purge-url') || '/files/operations/purge';
    var csrf = root.getAttribute('data-ops-csrf') || '';
    var tasks = {};
    var lastBadge = 0;
    var pollTimer = 0;
    var purgeInFlight = false;

    /**
     * @brief Localized type label for a task.
     * @param {object} task Task payload.
     * @return {string}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function typeLabel(task) {
        return root.getAttribute('data-type-' + String(task.type || '')) || String(task.type || '');
    }

    /**
     * @brief Progress caption: percent, or “Browser” when bytes are unknown.
     * @param {object} task Task payload.
     * @return {string}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function progressCaption(task) {
        if (task.progressKnown === false || task.status === 'handed_to_browser') {
            return root.getAttribute('data-msg-browser') || '';
        }
        var percent = Number(task.percent) || 0;

        return String(percent) + '%';
    }

    /**
     * @brief Count running tasks for the badge.
     * @return {number}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function runningCount() {
        var n = 0;
        Object.keys(tasks).forEach(function (id) {
            var status = String(tasks[id].status || '');
            if (status === 'running' || status === 'handed_to_browser') {
                n += 1;
            }
        });

        return n;
    }

    /**
     * @brief Whether a task is eligible for purge-all (everything except successful done).
     * @param {object} task Task payload.
     * @return {boolean}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function isPurgeEligible(task) {
        return String((task && task.status) || '') !== 'done';
    }

    /**
     * @brief Count purge-eligible tasks currently listed.
     * @return {number}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function purgeEligibleCount() {
        var n = 0;
        Object.keys(tasks).forEach(function (id) {
            if (isPurgeEligible(tasks[id])) {
                n += 1;
            }
        });

        return n;
    }

    /**
     * @brief Open the purge confirmation modal.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function openPurgeConfirm() {
        var modal = document.getElementById('opsPurgeConfirmModal');
        if (!modal || !window.bootstrap || !window.bootstrap.Modal) {
            return;
        }
        window.bootstrap.Modal.getOrCreateInstance(modal).show();
        window.setTimeout(function () {
            var confirmBtn = modal.querySelector('[data-ops-purge-confirm]');
            if (confirmBtn) {
                confirmBtn.focus();
            }
        }, 50);
    }

    /**
     * @brief POST purge-all then refresh local OPS state.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function runPurgeAll() {
        if (purgeInFlight || !purgeUrl) {
            return;
        }
        purgeInFlight = true;
        var confirmModal = document.getElementById('opsPurgeConfirmModal');
        var confirmBtn = confirmModal ? confirmModal.querySelector('[data-ops-purge-confirm]') : null;
        if (confirmBtn) {
            confirmBtn.disabled = true;
        }
        var fd = new FormData();
        fd.append('_csrf_token', csrf);
        fetch(purgeUrl, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (json) {
                return { ok: response.ok, json: json || {}, status: response.status };
            });
        }).then(function (pack) {
            if (confirmModal && window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(confirmModal).hide();
            }
            if (!pack.ok || !pack.json || pack.json.status !== 'ok') {
                var msg = '';
                if (window.FilesHttpError && typeof window.FilesHttpError.diagnose === 'function') {
                    msg = window.FilesHttpError.diagnose(pack, {
                        server: root.getAttribute('data-msg-toast-failed') || '',
                        network: root.getAttribute('data-msg-toast-failed') || ''
                    });
                }
                if (msg && window.AppFlashToasts && typeof window.AppFlashToasts.push === 'function') {
                    window.AppFlashToasts.push('danger', msg);
                }
                return;
            }
            if (window.FilesOperations && typeof window.FilesOperations.abortAllLocal === 'function') {
                window.FilesOperations.abortAllLocal();
            }
            tasks = {};
            (pack.json.tasks || []).forEach(function (task) {
                upsert(task);
                if (window.FilesOperations) {
                    window.FilesOperations.upsert(task);
                }
            });
            render();
            var doneMsg = root.getAttribute('data-msg-purge-done') || '';
            if (doneMsg && window.AppFlashToasts && typeof window.AppFlashToasts.push === 'function') {
                window.AppFlashToasts.push('success', doneMsg);
            }
        }).catch(function () {
            if (confirmModal && window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(confirmModal).hide();
            }
        }).then(function () {
            purgeInFlight = false;
            if (confirmBtn) {
                confirmBtn.disabled = false;
            }
        });
    }

    /**
     * @brief Render the dropdown list from the in-memory map.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function render() {
        if (!menuEl) {
            return;
        }
        var ids = Object.keys(tasks).sort(function (a, b) {
            return ((Number(tasks[b].updatedAt) || 0) - (Number(tasks[a].updatedAt) || 0));
        });
        menuEl.innerHTML = '';
        var listWrap = document.createElement('div');
        listWrap.className = 'ops-center-list';
        if (ids.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'dropdown-item-text text-muted small';
            empty.textContent = root.getAttribute('data-msg-empty') || '';
            listWrap.appendChild(empty);
        } else {
            ids.forEach(function (id) {
                listWrap.appendChild(buildRow(tasks[id]));
            });
        }
        menuEl.appendChild(listWrap);
        if (purgeEligibleCount() > 0) {
            var purgeWrap = document.createElement('div');
            purgeWrap.className = 'ops-center-purge';
            var purgeBtn = document.createElement('button');
            purgeBtn.type = 'button';
            purgeBtn.className = 'btn btn-sm btn-outline-danger';
            purgeBtn.setAttribute('data-ops-purge-all', '1');
            purgeBtn.textContent = root.getAttribute('data-msg-purge-all') || '';
            purgeBtn.disabled = purgeInFlight;
            purgeBtn.addEventListener('click', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                openPurgeConfirm();
            });
            purgeWrap.appendChild(purgeBtn);
            menuEl.appendChild(purgeWrap);
        }
        var count = runningCount();
        if (badgeEl) {
            badgeEl.textContent = String(count);
            badgeEl.classList.toggle('d-none', count < 1);
            if (count !== lastBadge) {
                lastBadge = count;
            }
        }
    }

    /**
     * @brief Build one dropdown row.
     * @param {object} task Task payload.
     * @return {HTMLElement}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function buildRow(task) {
        var wrap = document.createElement('div');
        wrap.className = 'dropdown-item-text ops-center-row';
        wrap.setAttribute('data-ops-task-id', task.taskId);

        var top = document.createElement('div');
        top.className = 'ops-center-row__top';
        var label = document.createElement('span');
        label.className = 'ops-center-row__label';
        label.textContent = String(task.label || task.taskId);
        var pct = document.createElement('span');
        pct.className = 'small text-nowrap';
        pct.textContent = progressCaption(task);
        top.appendChild(label);
        top.appendChild(pct);

        var meta = document.createElement('div');
        meta.className = 'ops-center-row__meta';
        meta.textContent = typeLabel(task);

        var actions = document.createElement('div');
        actions.className = 'ops-center-row__actions';
        var resumeBtn = document.createElement('button');
        resumeBtn.type = 'button';
        resumeBtn.className = 'btn btn-sm btn-outline-primary';
        resumeBtn.textContent = root.getAttribute('data-msg-resume') || '';
        resumeBtn.addEventListener('click', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            if (window.FilesOperations) {
                window.FilesOperations.upsert(task);
                window.FilesOperations.resume(task.taskId);
            }
        });
        actions.appendChild(resumeBtn);
        if (task.cancelSupported && task.status === 'running') {
            var cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'btn btn-sm btn-outline-danger';
            cancelBtn.textContent = root.getAttribute('data-msg-cancel') || '';
            cancelBtn.addEventListener('click', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                if (window.FilesOperations) {
                    window.FilesOperations.cancel(task.taskId);
                }
            });
            actions.appendChild(cancelBtn);
        } else if (task.status === 'handed_to_browser' || task.status === 'done' || task.status === 'failed' || task.status === 'cancelled') {
            var hideBtn = document.createElement('button');
            hideBtn.type = 'button';
            hideBtn.className = 'btn btn-sm btn-outline-secondary';
            hideBtn.textContent = root.getAttribute('data-msg-hide') || 'OK';
            hideBtn.addEventListener('click', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                acknowledge([task.taskId]);
            });
            actions.appendChild(hideBtn);
        }

        wrap.appendChild(top);
        wrap.appendChild(meta);
        wrap.appendChild(actions);

        return wrap;
    }

    /**
     * @brief Insert or replace a task in the center.
     * @param {object} task Task payload.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function upsert(task) {
        if (!task || !task.taskId) {
            return;
        }
        tasks[String(task.taskId)] = Object.assign({}, tasks[String(task.taskId)] || {}, task);
        render();
        maybeToast(tasks[String(task.taskId)]);
    }

    /**
     * @brief Remove a task from the dropdown.
     * @param {string} taskId Task id.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function remove(taskId) {
        delete tasks[String(taskId || '')];
        render();
    }

    /**
     * @brief Toast once for a newly terminal server task.
     * @param {object} task Task payload.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function maybeToast(task) {
        if (task.notified || (task.status !== 'done' && task.status !== 'failed')) {
            return;
        }
        var tpl = task.status === 'done'
            ? (root.getAttribute('data-msg-toast-done') || '')
            : (root.getAttribute('data-msg-toast-failed') || '');
        var msg = tpl.split('%name%').join(String(task.label || ''));
        if (msg && window.AppFlashToasts && typeof window.AppFlashToasts.push === 'function') {
            window.AppFlashToasts.push(task.status === 'done' ? 'success' : 'danger', msg);
        }
        task.notified = true;
        acknowledge([task.taskId]);
    }

    /**
     * @brief POST ack for terminal encrypt jobs; drop client downloads locally.
     * @param {string[]} ids Task ids.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function acknowledge(ids) {
        ids.forEach(function (id) {
            var task = tasks[String(id)];
            if (task && (task.type === 'download_file' || task.type === 'download_archive') && window.FilesOperations) {
                window.FilesOperations.forgetLocalDownload(id);
            }
            remove(id);
        });
        if (!ackUrl) {
            return;
        }
        var fd = new FormData();
        fd.append('_csrf_token', csrf);
        ids.forEach(function (id) {
            fd.append('task_ids[]', id);
        });
        fetch(ackUrl, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
        }).catch(function () {
            return null;
        });
    }

    /**
     * @brief Refresh from GET /files/operations and merge local downloads.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function refresh() {
        fetch(listUrl, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            });
        }).then(function (json) {
            if (!json || json.status !== 'ok' || !Array.isArray(json.tasks)) {
                return;
            }
            json.tasks.forEach(function (task) {
                upsert(task);
                if (window.FilesOperations) {
                    window.FilesOperations.upsert(task);
                }
            });
            if (window.FilesOperations && typeof window.FilesOperations.loadLocalDownloads === 'function') {
                window.FilesOperations.loadLocalDownloads();
            }
        }).catch(function () {
            return null;
        });
    }

    root.addEventListener('show.bs.dropdown', function () {
        refresh();
    });
    refresh();

    document.addEventListener('DOMContentLoaded', function () {
        var confirmModal = document.getElementById('opsPurgeConfirmModal');
        if (!confirmModal || confirmModal.getAttribute('data-ops-purge-bound') === '1') {
            return;
        }
        confirmModal.setAttribute('data-ops-purge-bound', '1');
        var confirmBtn = confirmModal.querySelector('[data-ops-purge-confirm]');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function () {
                runPurgeAll();
            });
        }
    });

    window.addEventListener('pagehide', function () {
        if (pollTimer) {
            window.clearInterval(pollTimer);
        }
    });

    window.FilesOperationsCenter = {
        upsert: upsert,
        remove: remove,
        refresh: refresh,
        purgeAll: runPurgeAll
    };
    window.FilesBackgroundTasks = {
        upsertTask: upsert,
        refresh: refresh
    };
}());
