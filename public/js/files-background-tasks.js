(function () {
    'use strict';

    /**
     * @brief Nav dropdown for background tasks (upload-encrypt jobs).
     * @date 2026-11-09
     * @author Stephane H.
     */

    var root = document.getElementById('files-background-tasks');
    if (!root) {
        return;
    }

    var listUrl = root.getAttribute('data-tasks-list-url') || '/files/tasks';
    var ackUrl = root.getAttribute('data-tasks-ack-url') || '/files/tasks/ack';
    var csrf = root.getAttribute('data-tasks-csrf') || '';
    var badge = document.getElementById('files-background-tasks-badge');
    var menu = document.getElementById('files-background-tasks-menu');
    var emptyLabel = root.getAttribute('data-msg-empty') || '';
    var openLabel = root.getAttribute('data-msg-open') || 'Open';
    var cancelLabel = root.getAttribute('data-msg-cancel') || 'Cancel';
    var toastDoneTpl = root.getAttribute('data-msg-toast-done') || '%name%';
    var toastFailedTpl = root.getAttribute('data-msg-toast-failed') || '%name%';
    var cancelUrlTemplate = root.getAttribute('data-encrypt-cancel-url-template') || '';
    var known = {};
    var REFRESH_MS = 5000;
    var TOAST_MS = 6000;

    /**
     * @brief Replace %name% in a toast template.
     * @param {string} template Template string.
     * @param {string} name Task label.
     * @return {string}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function fillName(template, name) {
        return String(template || '').split('%name%').join(name || '');
    }

    /**
     * @brief Show a lightweight toast for terminal task status.
     * @param {string} message Message.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function showToast(message) {
        if (!message) {
            return;
        }
        var existing = document.getElementById('files-background-tasks-toast');
        if (existing) {
            existing.remove();
        }
        var el = document.createElement('div');
        el.id = 'files-background-tasks-toast';
        el.className = 'alert alert-info position-fixed bottom-0 end-0 m-3 shadow';
        el.style.zIndex = '1080';
        el.setAttribute('role', 'status');
        el.textContent = message;
        document.body.appendChild(el);
        window.setTimeout(function () {
            el.remove();
        }, TOAST_MS);
    }

    /**
     * @brief Merge one task into local cache and refresh the dropdown.
     * @param {object} task Task payload.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function upsertTask(task) {
        if (!task || !task.taskId) {
            return;
        }
        known[String(task.taskId)] = task;
        render();
    }

    /**
     * @brief Open the upload modal for a given task id.
     * @param {string} taskId Task id.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function openUploadTask(taskId) {
        document.dispatchEvent(new CustomEvent('files:open-upload-task', {
            detail: { taskId: String(taskId || '') }
        }));
        var modal = document.getElementById('filesUploadModal');
        if (modal && window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modal).show();
        }
    }

    /**
     * @brief Cancel a running task via FilesUploadEncrypt or direct POST.
     * @param {string} taskId Task id.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function cancelTask(taskId) {
        var id = String(taskId || '');
        if (!id) {
            return;
        }
        var done = function () {
            refresh();
        };
        if (window.FilesUploadEncrypt && typeof window.FilesUploadEncrypt.cancelTask === 'function') {
            window.FilesUploadEncrypt.cancelTask(id, csrf, null, cancelUrlTemplate).then(done).catch(done);
            return;
        }
        var url = window.FilesUploadEncrypt && typeof window.FilesUploadEncrypt.buildTaskUrl === 'function'
            ? window.FilesUploadEncrypt.buildTaskUrl(cancelUrlTemplate, id, 'cancel')
            : '/files/upload/encrypt/' + encodeURIComponent(id) + '/cancel';
        var fd = new FormData();
        fd.append('_csrf_token', csrf);
        fetch(url, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
        }).then(done).catch(done);
    }

    /**
     * @brief Render dropdown entries from the known task cache.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function render() {
        if (!menu) {
            return;
        }
        var tasks = Object.keys(known).map(function (key) {
            return known[key];
        });
        var running = tasks.filter(function (task) {
            return task.status === 'running';
        });
        if (badge) {
            if (running.length > 0) {
                badge.textContent = String(running.length);
                badge.classList.remove('d-none');
            } else {
                badge.classList.add('d-none');
            }
        }
        menu.innerHTML = '';
        if (tasks.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'dropdown-item-text text-muted small';
            empty.textContent = emptyLabel;
            menu.appendChild(empty);
            return;
        }
        tasks.sort(function (a, b) {
            return (Number(b.updatedAt) || 0) - (Number(a.updatedAt) || 0);
        }).forEach(function (task) {
            var row = document.createElement('div');
            row.className = 'px-3 py-2 border-bottom';
            var title = document.createElement('div');
            title.className = 'small fw-semibold text-truncate';
            title.textContent = task.label || task.taskId;
            var meta = document.createElement('div');
            meta.className = 'small text-muted';
            meta.textContent = String(task.status || '') + ' — ' + String(task.percent || 0) + '%';
            var actions = document.createElement('div');
            actions.className = 'd-flex gap-2 mt-1';
            var openBtn = document.createElement('button');
            openBtn.type = 'button';
            openBtn.className = 'btn btn-sm btn-outline-primary';
            openBtn.textContent = openLabel;
            openBtn.addEventListener('click', function () {
                openUploadTask(String(task.taskId));
            });
            actions.appendChild(openBtn);
            if (task.status === 'running') {
                var cancelBtn = document.createElement('button');
                cancelBtn.type = 'button';
                cancelBtn.className = 'btn btn-sm btn-outline-danger';
                cancelBtn.textContent = cancelLabel;
                cancelBtn.addEventListener('click', function () {
                    cancelTask(String(task.taskId));
                });
                actions.appendChild(cancelBtn);
            }
            row.appendChild(title);
            row.appendChild(meta);
            row.appendChild(actions);
            menu.appendChild(row);
        });
    }

    /**
     * @brief Acknowledge terminal tasks so toasts are not repeated.
     * @param {string[]} taskIds Task ids.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function acknowledge(taskIds) {
        if (!taskIds || taskIds.length === 0) {
            return;
        }
        var fd = new FormData();
        fd.append('_csrf_token', csrf);
        taskIds.forEach(function (id) {
            fd.append('task_ids[]', id);
        });
        fetch(ackUrl, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
        }).catch(function () { /* ignore */ });
    }

    /**
     * @brief Refresh task list from the server and toast newly finished work.
     * @return {void}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function refresh() {
        fetch(listUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
        }).then(function (res) {
            return res.json().catch(function () {
                return {};
            });
        }).then(function (json) {
            if (!json || json.status !== 'ok' || !Array.isArray(json.tasks)) {
                return;
            }
            var ackIds = [];
            known = {};
            json.tasks.forEach(function (task) {
                known[String(task.taskId)] = task;
                if (!task.notified && (task.status === 'done' || task.status === 'failed')) {
                    var tpl = task.status === 'done' ? toastDoneTpl : toastFailedTpl;
                    showToast(fillName(tpl, task.label || task.taskId));
                    ackIds.push(String(task.taskId));
                }
            });
            render();
            acknowledge(ackIds);
        }).catch(function () { /* ignore */ });
    }

    window.FilesBackgroundTasks = {
        upsertTask: upsertTask,
        refresh: refresh
    };

    refresh();
    window.setInterval(refresh, REFRESH_MS);
}());
