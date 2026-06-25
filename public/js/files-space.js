/**
 * Files space: drag-and-drop uploads, upload modal grantee autocomplete, live listing search (Sprint 18–21).
 */
(function () {
    'use strict';

    /**
     * @brief Parse a fetch Response as JSON or throw a user-facing network error.
     * @param {Response} res Fetch response object.
     * @return {Promise<{ok: boolean, json: Record<string, unknown>|null}>}
     * @date 2026-06-24
     * @author Stephane H.
     */
    function parseFetchJsonResponse(res) {
        return res.text().then(function (raw) {
            /** @type {Record<string, unknown>|null} */
            var parsed = null;
            if (raw) {
                try {
                    parsed = JSON.parse(raw);
                } catch (parseErr) {
                    parsed = null;
                }
            }
            return { ok: res.ok, json: parsed };
        });
    }

    var DEBOUNCE_MS = 300;
    var MIN_LIVE_SEARCH_LEN = 4;
    var MIN_GRANTEE_SEARCH_LEN = 2;

    /**
     * @brief Format byte count like BinaryByteFormatter / files_size_format (binary steps, o/Ko/Mo/Go/To/Po).
     * @param {number} rawBytes Non-negative byte count.
     * @return {string}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function formatFilesSize(rawBytes) {
        var n = typeof rawBytes === 'number' && !isNaN(rawBytes) && rawBytes >= 0 ? Math.floor(rawBytes) : 0;
        var units = ['o', 'Ko', 'Mo', 'Go', 'To', 'Po'];
        var value = n;
        var index = 0;
        var maxIndex = units.length - 1;
        while (value >= 1024 && index < maxIndex) {
            value /= 1024;
            index++;
        }
        if (index === 0) {
            return String(value) + ' ' + units[0];
        }
        return value.toFixed(2) + ' ' + units[index];
    }

    /**
     * @param {DOMStringList|FileList|null|undefined} types Transfer types or similar.
     * @return {boolean}
     */
    function hasFileDrag(types) {
        if (!types || typeof types.length !== 'number') {
            return false;
        }
        var i;
        for (i = 0; i < types.length; i += 1) {
            if (types[i] === 'Files') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param {HTMLElement|null} modalEl Upload modal root.
     * @return {void}
     */
    function showUploadModal(modalEl) {
        if (modalEl && window.bootstrap && typeof window.bootstrap.Modal === 'function') {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    }

    /**
     * @param {HTMLInputElement} input File input.
     * @param {FileList|File[]} files Files to assign.
     * @return {void}
     * @date 2026-06-25
     * @author Stephane H.
     */
    function assignFilesToInput(input, files) {
        var buffer = new DataTransfer();
        for (var i = 0; i < files.length; i++) {
            buffer.items.add(files[i]);
        }
        input.files = buffer.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /**
     * @param {File} file Browser file handle.
     * @param {string} [relativePathOverride] Optional explicit relative path.
     * @return {{file: File, relativePath: string, displayName: string, size: number, state: string, error: string}}
     * @date 2026-06-25
     * @author Stephane H.
     */
    function buildUploadEntryFromFile(file, relativePathOverride) {
        var rel =
            typeof relativePathOverride === 'string' && relativePathOverride !== ''
                ? relativePathOverride
                : file.webkitRelativePath || '';
        var displayName = file.name;
        if (rel !== '') {
            var parts = rel.split('/');
            displayName = parts[parts.length - 1] || file.name;
        }

        return {
            file: file,
            relativePath: rel,
            displayName: displayName,
            size: file.size,
            state: 'pending',
            error: ''
        };
    }

    /**
     * @param {string} relativePath Relative client path.
     * @return {boolean}
     * @date 2026-06-25
     * @author Stephane H.
     */
    function shouldFilterSystemUploadPath(relativePath) {
        var norm = String(relativePath || '')
            .replace(/\\/g, '/')
            .toLowerCase();
        if (norm === '') {
            return false;
        }
        var segments = norm.split('/');
        for (var i = 0; i < segments.length; i++) {
            if (segments[i] === 'node_modules' || segments[i] === '.git' || segments[i] === '__pycache__') {
                return true;
            }
        }
        var base = segments[segments.length - 1] || '';
        return base === '.ds_store' || base === 'thumbs.db';
    }

    /**
     * @param {Array<{relativePath?: string}>} entries Upload queue entries.
     * @return {Array<*>}
     * @date 2026-06-25
     * @author Stephane H.
     */
    function filterUploadEntries(entries) {
        var filterEl = document.getElementById('files-upload-filter-system');
        if (!filterEl || !filterEl.checked) {
            return entries;
        }
        return entries.filter(function (entry) {
            return !shouldFilterSystemUploadPath(entry.relativePath || entry.file.webkitRelativePath || '');
        });
    }

    /**
     * @param {FileSystemEntry} entry Directory or file entry.
     * @param {string} path Accumulated relative path.
     * @return {Promise<Array<{file: File, relativePath: string}>>}
     * @date 2026-06-25
     * @author Stephane H.
     */
    function traverseFileTreeEntry(entry, pathPrefix) {
        return new Promise(function (resolve) {
            if (entry.isFile) {
                entry.file(function (file) {
                    var rel = pathPrefix !== '' ? pathPrefix + '/' + file.name : file.name;
                    resolve([{ file: file, relativePath: rel }]);
                });
                return;
            }
            if (!entry.isDirectory) {
                resolve([]);
                return;
            }
            var dirReader = entry.createReader();
            var allEntries = [];
            /**
             * @return {void}
             */
            function readEntries() {
                dirReader.readEntries(function (results) {
                    if (!results || results.length < 1) {
                        var base = pathPrefix !== '' ? pathPrefix + '/' + entry.name : entry.name;
                        Promise.all(
                            allEntries.map(function (childEntry) {
                                return traverseFileTreeEntry(childEntry, base);
                            })
                        ).then(function (nested) {
                            var flat = [];
                            nested.forEach(function (group) {
                                flat = flat.concat(group);
                            });
                            resolve(flat);
                        });
                        return;
                    }
                    allEntries = allEntries.concat(Array.prototype.slice.call(results));
                    readEntries();
                });
            }
            readEntries();
        });
    }

    /**
     * @param {DataTransfer} dataTransfer Drag-and-drop payload.
     * @return {Promise<Array<*>>}
     * @date 2026-06-25
     * @author Stephane H.
     */
    function collectUploadEntriesFromDataTransfer(dataTransfer) {
        if (!dataTransfer) {
            return Promise.resolve([]);
        }
        var items = dataTransfer.items;
        if (items && items.length > 0 && typeof items[0].webkitGetAsEntry === 'function') {
            var entryPromises = [];
            for (var i = 0; i < items.length; i++) {
                var item = items[i];
                if (item.kind !== 'file') {
                    continue;
                }
                var entry = item.webkitGetAsEntry();
                if (entry) {
                    entryPromises.push(traverseFileTreeEntry(entry, ''));
                }
            }
            if (entryPromises.length > 0) {
                return Promise.all(entryPromises).then(function (groups) {
                    var flat = [];
                    groups.forEach(function (group) {
                        flat = flat.concat(group);
                    });
                    return flat.map(function (item) {
                        return buildUploadEntryFromFile(item.file, item.relativePath);
                    });
                });
            }
        }
        var list = dataTransfer.files;
        var out = [];
        for (var j = 0; j < list.length; j++) {
            out.push(buildUploadEntryFromFile(list[j]));
        }
        return Promise.resolve(out);
    }

    /**
     * @param {HTMLInputElement} input File input.
     * @param {File} file Dropped file.
     * @return {void}
     */
    function assignFileToInput(input, file) {
        assignFilesToInput(input, [file]);
    }

    /**
     * @param {HTMLElement} zone Drop zone element.
     * @param {HTMLInputElement|null} input File input.
     * @param {HTMLElement|null} modalEl Upload modal.
     * @return {void}
     */
    function bindDropZone(zone, input, modalEl) {
        ['dragenter', 'dragover'].forEach(function (evName) {
            zone.addEventListener(evName, function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (e.dataTransfer) {
                    e.dataTransfer.dropEffect = 'copy';
                }
                zone.classList.add('files-drop-zone--active');
            });
        });
        zone.addEventListener('dragleave', function (e) {
            e.preventDefault();
            e.stopPropagation();
            zone.classList.remove('files-drop-zone--active');
        });
        zone.addEventListener('drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            zone.classList.remove('files-drop-zone--active');
            if (!input || !e.dataTransfer || !e.dataTransfer.files || e.dataTransfer.files.length < 1) {
                return;
            }
            collectUploadEntriesFromDataTransfer(e.dataTransfer).then(function (entries) {
                if (!entries.length) {
                    return;
                }
                assignFilesToInput(
                    input,
                    entries.map(function (entry) {
                        return entry.file;
                    })
                );
                if (typeof window.filesUploadSetPendingEntries === 'function') {
                    window.filesUploadSetPendingEntries(entries);
                }
                showUploadModal(modalEl);
            });
        });
    }

    /**
     * @param {HTMLInputElement|null} input File input.
     * @param {HTMLElement|null} modalEl Upload modal.
     * @return {void}
     */
    function bindDocumentFileDrop(input, modalEl) {
        document.addEventListener(
            'dragover',
            function (e) {
                if (e.dataTransfer && hasFileDrag(e.dataTransfer.types)) {
                    e.preventDefault();
                }
            },
            false
        );
        document.addEventListener(
            'drop',
            function (e) {
                if (!e.dataTransfer || !hasFileDrag(e.dataTransfer.types)) {
                    return;
                }
                var el = e.target;
                while (el && el !== document.body) {
                    if (el.getAttribute && el.getAttribute('data-files-drop-zone') !== null) {
                        return;
                    }
                    el = el.parentNode;
                }
                if (!input || !e.dataTransfer.files || e.dataTransfer.files.length < 1) {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                collectUploadEntriesFromDataTransfer(e.dataTransfer).then(function (entries) {
                    if (!entries.length) {
                        return;
                    }
                    assignFilesToInput(
                        input,
                        entries.map(function (entry) {
                            return entry.file;
                        })
                    );
                    if (typeof window.filesUploadSetPendingEntries === 'function') {
                        window.filesUploadSetPendingEntries(entries);
                    }
                    showUploadModal(modalEl);
                });
            },
            false
        );
    }

    /**
     * @brief Upload one file through session, chunk, and finalize endpoints.
     * @param {*} entry Queue entry with file and relativePath.
     * @param {*} context Upload runtime context.
     * @return {Promise<{ok: boolean, message: string}>}
     * @date 2026-06-25
     * @author Stephane H.
     */
    function uploadSingleFile(entry, context) {
        var file = entry.file;
        var sessionUrl = context.sessionUrl;
        var chunkUrl = context.chunkUrl;
        var finalizeUrl = context.finalizeUrl;
        var csrf = context.csrf;
        var appendRetainFields = context.appendRetainFields;
        var msg = context.msg;

        if (context.isCancelled()) {
            return Promise.resolve({ ok: false, message: '', cancelled: true });
        }

        syncUploadTargetFolderInput(context.formEl);
        var fdSession = new FormData();
        fdSession.append('expected_bytes', String(file.size));
        fdSession.append('original_name', file.name);
        if (entry.relativePath) {
            fdSession.append('relative_path', entry.relativePath);
        }
        appendRetainFields(fdSession);

        return fetch(sessionUrl, {
            method: 'POST',
            body: fdSession,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
        })
            .then(function (res) {
                return parseFetchJsonResponse(res);
            })
            .then(function (pack) {
                if (!pack.ok || !pack.json || pack.json.status !== 'ok' || typeof pack.json.upload_id !== 'string') {
                    var m0 = pack.json && pack.json.message ? pack.json.message : msg('msgXhrNetworkError');
                    throw new Error(typeof m0 === 'string' ? m0 : msg('msgXhrNetworkError'));
                }
                return pack.json;
            })
            .then(function (sess) {
                var uploadId = sess.upload_id;
                var chunkSz = sess.chunk_size_bytes ? parseInt(sess.chunk_size_bytes, 10) : 8388608;
                if (!chunkSz || chunkSz < 1024) {
                    chunkSz = 8388608;
                }
                var offset = 0;
                var idx = 0;

                /**
                 * @return {Promise<void>}
                 */
                function nextChunk() {
                    if (context.isCancelled()) {
                        return Promise.reject(new Error('__upload_cancelled__'));
                    }
                    if (offset >= file.size) {
                        return Promise.resolve();
                    }
                    var end = Math.min(offset + chunkSz, file.size);
                    var blob = file.slice(offset, end);
                    return new Promise(function (resolve, reject) {
                        var xhr = new XMLHttpRequest();
                        context.setActiveXhr(xhr);
                        xhr.open('POST', chunkUrl);
                        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                        xhr.setRequestHeader('Accept', 'application/json');
                        xhr.responseType = 'text';
                        var chunkStart = offset;
                        xhr.upload.onprogress = function (e) {
                            if (e.lengthComputable && file.size > 0 && typeof context.onFileProgress === 'function') {
                                context.onFileProgress(chunkStart + e.loaded, file.size);
                            }
                        };
                        xhr.onload = function () {
                            context.setActiveXhr(null);
                            var raw = xhr.responseText || '';
                            /** @type {{status?: string, message?: string}|null} */
                            var pj = null;
                            try {
                                pj = JSON.parse(raw);
                            } catch {
                                pj = null;
                            }
                            if (xhr.status >= 200 && xhr.status < 300 && pj && pj.status === 'ok') {
                                offset = end;
                                idx += 1;
                                resolve();
                            } else {
                                var em = (pj && pj.message) || msg('msgXhrNetworkError');
                                reject(new Error(em));
                            }
                        };
                        xhr.onerror = function () {
                            context.setActiveXhr(null);
                            reject(new Error(msg('msgXhrNetworkError')));
                        };
                        var fdC = new FormData();
                        fdC.append('upload_id', uploadId);
                        fdC.append('chunk_index', String(idx));
                        fdC.append('_csrf_token', csrf);
                        fdC.append('chunk', blob, 'chunk.bin');
                        appendRetainFields(fdC, true);
                        xhr.send(fdC);
                    }).then(function () {
                        return nextChunk();
                    });
                }

                if (typeof context.onFilePhase === 'function') {
                    context.onFilePhase('uploading');
                }

                return nextChunk().then(function () {
                    if (typeof context.onFilePhase === 'function') {
                        context.onFilePhase('processing');
                    }
                    var fdF = new FormData();
                    fdF.append('upload_id', uploadId);
                    appendRetainFields(fdF);
                    return fetch(finalizeUrl, {
                        method: 'POST',
                        body: fdF,
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
                    });
                });
            })
            .then(function (res) {
                return parseFetchJsonResponse(res);
            })
            .then(function (pack) {
                if (pack.json && pack.json.status === 'ok') {
                    return { ok: true, message: typeof pack.json.message === 'string' ? pack.json.message : '' };
                }
                if (pack.json && typeof pack.json.message === 'string' && pack.json.message !== '') {
                    return { ok: false, message: pack.json.message };
                }
                return { ok: false, message: msg('msgXhrNetworkError') };
            })
            .catch(function (err) {
                if (err && err.message === '__upload_cancelled__') {
                    return { ok: false, message: '', cancelled: true };
                }
                return { ok: false, message: err && err.message ? err.message : msg('msgXhrNetworkError') };
            });
    }

    /**
     * @brief Sequentially upload a batch of entries with global byte progress.
     * @param {Array<*>} entries Upload queue entries.
     * @param {*} context Upload runtime context.
     * @return {Promise<{okCount: number, failedCount: number, cancelled: boolean}>}
     * @date 2026-06-25
     * @author Stephane H.
     */
    function uploadFileQueue(entries, context) {
        var totalBytes = 0;
        entries.forEach(function (entry) {
            totalBytes += entry.size || 0;
        });
        var completedBytes = 0;
        var okCount = 0;
        var failedCount = 0;
        var index = 0;

        /**
         * @return {Promise<{okCount: number, failedCount: number, cancelled: boolean}>}
         */
        function runNext() {
            if (context.isCancelled()) {
                for (var c = index; c < entries.length; c++) {
                    if (entries[c].state === 'pending' || entries[c].state === 'uploading') {
                        entries[c].state = 'cancelled';
                        if (typeof context.onFileStateChange === 'function') {
                            context.onFileStateChange(c, 'cancelled', '');
                        }
                    }
                }
                return Promise.resolve({ okCount: okCount, failedCount: failedCount, cancelled: true });
            }
            if (index >= entries.length) {
                return Promise.resolve({ okCount: okCount, failedCount: failedCount, cancelled: false });
            }
            var currentIndex = index;
            var entry = entries[currentIndex];
            index += 1;
            entry.state = 'uploading';
            if (typeof context.onFileStateChange === 'function') {
                context.onFileStateChange(currentIndex, 'uploading', '');
            }
            if (typeof context.onGlobalProgress === 'function') {
                context.onGlobalProgress(completedBytes, totalBytes, currentIndex + 1, entries.length);
            }

            var fileContext = Object.assign({}, context, {
                onFileProgress: function (loaded, total) {
                    if (typeof context.onFileProgress === 'function') {
                        context.onFileProgress(currentIndex, loaded, total);
                    }
                    if (typeof context.onGlobalProgress === 'function') {
                        context.onGlobalProgress(completedBytes + loaded, totalBytes, currentIndex + 1, entries.length);
                    }
                },
                onFilePhase: function (phase) {
                    if (typeof context.onFileStateChange === 'function') {
                        context.onFileStateChange(currentIndex, phase, '');
                    }
                }
            });

            return uploadSingleFile(entry, fileContext).then(function (result) {
                if (result.cancelled) {
                    entry.state = 'cancelled';
                    if (typeof context.onFileStateChange === 'function') {
                        context.onFileStateChange(currentIndex, 'cancelled', '');
                    }
                    return runNext();
                }
                completedBytes += entry.size || 0;
                if (result.ok) {
                    entry.state = 'done';
                    okCount += 1;
                    if (typeof context.onFileStateChange === 'function') {
                        context.onFileStateChange(currentIndex, 'done', '');
                    }
                } else {
                    entry.state = 'error';
                    entry.error = result.message || '';
                    failedCount += 1;
                    if (typeof context.onFileStateChange === 'function') {
                        context.onFileStateChange(currentIndex, 'error', entry.error);
                    }
                }
                if (typeof context.onGlobalProgress === 'function') {
                    context.onGlobalProgress(completedBytes, totalBytes, Math.min(index + 1, entries.length), entries.length);
                }
                return runNext();
            });
        }

        return runNext();
    }

    /**
     * @brief Wire multi-file upload modal: queue UI, global progress, sequential chunked uploads.
     * @param {HTMLFormElement} formEl Upload form.
     * @param {HTMLInputElement} fileInputEl File input.
     * @param {HTMLElement} modalEl Upload modal root (datasets for i18n labels).
     * @return {void}
     * @date 2026-06-25
     * @author Stephane H.
     */
    function bindFilesUploadProgress(formEl, fileInputEl, modalEl) {
        var errorEl = document.getElementById('files-upload-error');
        var progressWrap = document.getElementById('files-upload-progress-wrap');
        var queueWrap = document.getElementById('files-upload-queue-wrap');
        var queueList = document.getElementById('files-upload-queue');
        var globalLabel = document.getElementById('files-upload-global-label');
        var globalBarWrap = document.getElementById('files-upload-global-progress-bar-wrap');
        var globalBar = document.getElementById('files-upload-global-progress-bar');
        var globalBytes = document.getElementById('files-upload-global-bytes');
        var metaWrap = document.getElementById('files-upload-selected-meta');
        var metaCountVal = document.getElementById('files-upload-selected-count-value');
        var metaVal = document.getElementById('files-upload-selected-size-value');
        var treeHint = document.getElementById('files-upload-tree-hint');
        var folderInput = document.getElementById('files-upload-folder-input');
        var pickFolderBtn = document.getElementById('files-upload-pick-folder');
        var uploadBusy = false;
        var uploadCancelRequested = false;
        var activeXhr = null;
        var pendingEntriesOverride = null;
        var queueEntries = [];

        /**
         * @param {string} camelKey Dataset key in camelCase (e.g. msgProgressSending).
         * @return {string}
         */
        function msg(camelKey) {
            var ds = modalEl.dataset || {};
            return typeof ds[camelKey] === 'string' ? ds[camelKey] : '';
        }

        /**
         * @param {string} tpl Template with placeholders.
         * @param {Record<string, string>} replacements Placeholder map.
         * @return {string}
         */
        function fillTemplate(tpl, replacements) {
            var out = tpl;
            Object.keys(replacements).forEach(function (key) {
                out = out.split(key).join(replacements[key]);
            });
            return out;
        }

        /**
         * @return {void}
         */
        function setSubmittingUi(active) {
            uploadBusy = active;
            if (active) {
                modalEl.setAttribute('aria-busy', 'true');
            } else {
                modalEl.removeAttribute('aria-busy');
            }
            formEl.querySelectorAll('[data-files-upload-submit]').forEach(function (btn) {
                btn.disabled = !!active;
            });
            formEl.querySelectorAll('[data-files-upload-dismiss]').forEach(function (btn) {
                btn.disabled = !!active;
            });
            formEl.querySelectorAll('[data-files-target-owner-search]').forEach(function (inp) {
                inp.disabled = !!active;
            });
            fileInputEl.disabled = !!active;
            if (folderInput) {
                folderInput.disabled = !!active;
            }
            if (pickFolderBtn) {
                pickFolderBtn.disabled = !!active;
            }
        }

        /**
         * @param {string} state Queue item state token.
         * @return {string}
         */
        function statusLabel(state) {
            if (state === 'uploading') {
                return msg('msgMultiStatusUploading');
            }
            if (state === 'processing') {
                return msg('msgMultiStatusProcessing');
            }
            if (state === 'done') {
                return msg('msgMultiStatusDone');
            }
            if (state === 'error') {
                return msg('msgMultiStatusError');
            }
            if (state === 'cancelled') {
                return msg('msgMultiStatusCancelled');
            }
            return msg('msgMultiStatusPending');
        }

        /**
         * @return {void}
         */
        function renderQueueUi() {
            if (!queueList) {
                return;
            }
            queueList.innerHTML = '';
            queueEntries.forEach(function (entry, idx) {
                var li = document.createElement('li');
                li.className = 'files-upload-queue-item files-upload-queue-item--' + entry.state;
                li.setAttribute('data-files-upload-queue-index', String(idx));

                var title = document.createElement('div');
                title.className = 'files-upload-queue-item__title';
                title.textContent = entry.relativePath || entry.displayName;

                var meta = document.createElement('div');
                meta.className = 'files-upload-queue-item__meta small text-muted';
                meta.textContent = formatFilesSize(entry.size);

                var progress = document.createElement('div');
                progress.className = 'progress files-upload-queue-item__progress';
                progress.setAttribute('role', 'progressbar');
                progress.setAttribute('aria-valuemin', '0');
                progress.setAttribute('aria-valuemax', '100');
                var bar = document.createElement('div');
                bar.className = 'progress-bar';
                bar.style.width = entry.state === 'done' ? '100%' : '0%';
                bar.setAttribute('data-files-upload-queue-bar', String(idx));
                progress.appendChild(bar);

                var badge = document.createElement('span');
                badge.className = 'badge text-bg-secondary files-upload-queue-item__status';
                badge.setAttribute('data-files-upload-queue-status', String(idx));
                badge.textContent = statusLabel(entry.state);

                if (entry.error) {
                    var err = document.createElement('div');
                    err.className = 'small text-danger files-upload-queue-item__error';
                    err.textContent = entry.error;
                    li.appendChild(err);
                }

                li.appendChild(title);
                li.appendChild(meta);
                li.appendChild(progress);
                li.appendChild(badge);
                queueList.appendChild(li);
            });
        }

        /**
         * @param {number} index Entry index.
         * @param {number} loaded Loaded bytes.
         * @param {number} total Total bytes.
         * @return {void}
         */
        function updateQueueItemProgress(index, loaded, total) {
            var bar = queueList ? queueList.querySelector('[data-files-upload-queue-bar="' + String(index) + '"]') : null;
            if (!bar || total <= 0) {
                return;
            }
            var pct = Math.min(100, Math.round((loaded / total) * 100));
            bar.style.width = String(pct) + '%';
            bar.parentElement.setAttribute('aria-valuenow', String(pct));
        }

        /**
         * @param {number} index Entry index.
         * @param {string} state New state.
         * @param {string} errorMessage Optional error text.
         * @return {void}
         */
        function updateQueueItemState(index, state, errorMessage) {
            if (!queueEntries[index]) {
                return;
            }
            queueEntries[index].state = state;
            queueEntries[index].error = errorMessage || '';
            var item = queueList ? queueList.querySelector('[data-files-upload-queue-index="' + String(index) + '"]') : null;
            if (item) {
                item.className = 'files-upload-queue-item files-upload-queue-item--' + state;
            }
            var badge = queueList ? queueList.querySelector('[data-files-upload-queue-status="' + String(index) + '"]') : null;
            if (badge) {
                badge.textContent = statusLabel(state);
            }
            if (state === 'done') {
                updateQueueItemProgress(index, 1, 1);
            }
            if (errorMessage && item) {
                var errEl = item.querySelector('.files-upload-queue-item__error');
                if (!errEl) {
                    errEl = document.createElement('div');
                    errEl.className = 'small text-danger files-upload-queue-item__error';
                    item.insertBefore(errEl, item.firstChild);
                }
                errEl.textContent = errorMessage;
            }
        }

        /**
         * @param {number} completedBytes Bytes completed across the batch.
         * @param {number} totalBytes Total bytes in batch.
         * @param {number} currentIndex One-based current file index.
         * @param {number} totalCount Total file count.
         * @return {void}
         */
        function updateGlobalProgress(completedBytes, totalBytes, currentIndex, totalCount) {
            if (globalLabel) {
                globalLabel.textContent = fillTemplate(msg('msgMultiGlobalLabel'), {
                    '%current%': String(currentIndex),
                    '%total%': String(totalCount)
                });
            }
            if (globalBytes) {
                globalBytes.textContent = fillTemplate(msg('msgMultiGlobalBytes'), {
                    '%done%': formatFilesSize(completedBytes),
                    '%total%': formatFilesSize(totalBytes)
                });
            }
            if (globalBar && globalBarWrap && totalBytes > 0) {
                var pct = Math.min(100, Math.round((completedBytes / totalBytes) * 100));
                globalBar.style.width = String(pct) + '%';
                globalBarWrap.setAttribute('aria-valuenow', String(pct));
            }
        }

        /**
         * @param {string} text User-visible error.
         * @return {void}
         */
        function showError(text) {
            if (errorEl) {
                errorEl.textContent = typeof text === 'string' ? text : '';
                errorEl.classList.remove('d-none');
            }
        }

        /**
         * @return {Array<*>}
         */
        function buildEntriesFromInput() {
            if (pendingEntriesOverride && pendingEntriesOverride.length) {
                var pending = pendingEntriesOverride.slice();
                pendingEntriesOverride = null;
                return filterUploadEntries(pending);
            }
            var list = fileInputEl.files;
            var built = [];
            for (var i = 0; i < list.length; i++) {
                built.push(buildUploadEntryFromFile(list[i]));
            }
            return filterUploadEntries(built);
        }

        /**
         * @return {void}
         */
        function refreshSelectionMeta() {
            var entries = buildEntriesFromInput();
            queueEntries = entries;
            var totalSize = 0;
            var hasTree = false;
            var rootName = '';
            entries.forEach(function (entry) {
                totalSize += entry.size || 0;
                if (entry.relativePath && entry.relativePath.indexOf('/') >= 0) {
                    hasTree = true;
                    if (rootName === '') {
                        rootName = entry.relativePath.split('/')[0] || '';
                    }
                }
            });
            if (!metaWrap || !metaVal || !metaCountVal) {
                return;
            }
            if (!entries.length) {
                metaWrap.classList.add('d-none');
                if (queueWrap) {
                    queueWrap.classList.add('d-none');
                }
                if (treeHint) {
                    treeHint.classList.add('d-none');
                }
                renderQueueUi();
                return;
            }
            metaCountVal.textContent = String(entries.length);
            metaVal.textContent = formatFilesSize(totalSize);
            metaWrap.classList.remove('d-none');
            if (queueWrap) {
                queueWrap.classList.remove('d-none');
            }
            if (treeHint) {
                if (hasTree && rootName !== '') {
                    treeHint.textContent = fillTemplate(msg('msgMultiTreeDetected'), { '%root%': rootName });
                    treeHint.classList.remove('d-none');
                } else {
                    treeHint.classList.add('d-none');
                    treeHint.textContent = '';
                }
            }
            renderQueueUi();
        }

        window.filesUploadSetPendingEntries = function (entries) {
            pendingEntriesOverride = Array.isArray(entries) ? entries.slice() : null;
            refreshSelectionMeta();
        };

        modalEl.addEventListener('hide.bs.modal', function (ev) {
            if (uploadBusy) {
                uploadCancelRequested = true;
                if (activeXhr) {
                    activeXhr.abort();
                }
                ev.preventDefault();
            }
        });

        modalEl.addEventListener('hidden.bs.modal', function () {
            uploadCancelRequested = false;
            if (!uploadBusy) {
                if (errorEl) {
                    errorEl.classList.add('d-none');
                    errorEl.textContent = '';
                }
                pendingEntriesOverride = null;
            }
        });

        fileInputEl.addEventListener('change', function () {
            pendingEntriesOverride = null;
            refreshSelectionMeta();
        });

        if (folderInput) {
            folderInput.addEventListener('change', function () {
                var built = [];
                for (var i = 0; i < folderInput.files.length; i++) {
                    built.push(buildUploadEntryFromFile(folderInput.files[i]));
                }
                pendingEntriesOverride = filterUploadEntries(built);
                assignFilesToInput(
                    fileInputEl,
                    built.map(function (entry) {
                        return entry.file;
                    })
                );
                folderInput.value = '';
                refreshSelectionMeta();
            });
        }

        if (pickFolderBtn && folderInput) {
            pickFolderBtn.addEventListener('click', function () {
                if (!uploadBusy) {
                    folderInput.click();
                }
            });
        }

        var filterSystemEl = document.getElementById('files-upload-filter-system');
        if (filterSystemEl) {
            filterSystemEl.addEventListener('change', refreshSelectionMeta);
        }

        formEl.addEventListener('submit', function (ev) {
            ev.preventDefault();
            if (!formEl.reportValidity()) {
                return;
            }

            var entries = buildEntriesFromInput();
            if (!entries.length) {
                return;
            }

            var maxFilesBlock = parseInt(modalEl.dataset.filesUploadMaxFilesBlock || '2000', 10);
            var maxFilesWarn = parseInt(modalEl.dataset.filesUploadMaxFilesWarn || '500', 10);
            var maxBytes = parseInt(modalEl.dataset.filesUploadMaxBytes || '0', 10);
            if (entries.length > maxFilesBlock) {
                showError(fillTemplate(msg('msgMultiTooManyFiles'), { '%count%': String(entries.length) }));
                return;
            }
            var totalSize = 0;
            entries.forEach(function (entry) {
                totalSize += entry.size || 0;
                if (maxBytes > 0 && entry.size > maxBytes) {
                    entry.state = 'error';
                    entry.error = msg('msgXhrNetworkError');
                }
            });
            if (entries.length > maxFilesWarn) {
                var confirmTpl = msg('msgMultiConfirmMany');
                if (confirmTpl !== '' && !window.confirm(fillTemplate(confirmTpl, {
                    '%count%': String(entries.length),
                    '%size%': formatFilesSize(totalSize)
                }))) {
                    return;
                }
            }

            var sessionUrl = modalEl.dataset.filesUploadSessionUrl || '';
            var chunkUrl = modalEl.dataset.filesUploadChunkUrl || '';
            var finalizeUrl = modalEl.dataset.filesUploadFinalizeUrl || '';
            if (!sessionUrl || !chunkUrl || !finalizeUrl) {
                showError(msg('msgXhrNetworkError'));
                return;
            }

            if (errorEl) {
                errorEl.classList.add('d-none');
                errorEl.textContent = '';
            }

            uploadCancelRequested = false;
            queueEntries = entries.slice();
            renderQueueUi();
            setSubmittingUi(true);
            if (queueWrap) {
                queueWrap.classList.remove('d-none');
            }
            if (progressWrap) {
                progressWrap.classList.add('d-none');
            }

            var csrfInp = formEl.querySelector('[name="_csrf_token"]');
            var csrf = csrfInp && csrfInp.value ? csrfInp.value : '';

            /**
             * @param {FormData} fd Target form data.
             * @param {boolean} [skipDuplicateCsrf] When true, omit hidden `_csrf_token` (chunk POST already sets it).
             * @return {void}
             */
            function appendRetainFields(fd, skipDuplicateCsrf) {
                formEl.querySelectorAll('input[type="hidden"]').forEach(function (inp) {
                    if (!inp.name) {
                        return;
                    }
                    if (skipDuplicateCsrf && inp.name === '_csrf_token') {
                        return;
                    }
                    fd.append(inp.name, inp.value);
                });
            }

            var context = {
                formEl: formEl,
                sessionUrl: sessionUrl,
                chunkUrl: chunkUrl,
                finalizeUrl: finalizeUrl,
                csrf: csrf,
                appendRetainFields: appendRetainFields,
                msg: msg,
                isCancelled: function () {
                    return uploadCancelRequested;
                },
                setActiveXhr: function (xhr) {
                    activeXhr = xhr;
                },
                onFileProgress: function (index, loaded, total) {
                    updateQueueItemProgress(index, loaded, total);
                },
                onFileStateChange: function (index, state, errorMessage) {
                    updateQueueItemState(index, state, errorMessage);
                },
                onGlobalProgress: function (completedBytes, totalBytes, currentIndex, totalCount) {
                    updateGlobalProgress(completedBytes, totalBytes, currentIndex, totalCount);
                }
            };

            uploadFileQueue(queueEntries, context).then(function (summary) {
                setSubmittingUi(false);
                uploadCancelRequested = false;
                activeXhr = null;

                var listingShell = document.getElementById('files-live-region');
                var toastMsg = '';
                if (summary.cancelled) {
                    toastMsg = fillTemplate(msg('msgMultiSummaryPartial'), {
                        '%ok%': String(summary.okCount),
                        '%total%': String(queueEntries.length),
                        '%failed%': String(summary.failedCount)
                    });
                } else if (summary.failedCount > 0) {
                    toastMsg = fillTemplate(msg('msgMultiSummaryPartial'), {
                        '%ok%': String(summary.okCount),
                        '%total%': String(queueEntries.length),
                        '%failed%': String(summary.failedCount)
                    });
                } else {
                    toastMsg = fillTemplate(msg('msgMultiSummaryOk'), { '%count%': String(summary.okCount) });
                }

                if (summary.failedCount === 0 && !summary.cancelled && listingShell && typeof runPartialFetch === 'function') {
                    if (toastMsg !== '' && typeof pushFilesToast === 'function') {
                        pushFilesToast(toastMsg, 'success');
                    }
                    if (window.bootstrap && window.bootstrap.Modal) {
                        window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                    }
                    formEl.reset();
                    fileInputEl.value = '';
                    pendingEntriesOverride = null;
                    refreshSelectionMeta();
                    var searchQ = document.getElementById('files-search-q');
                    runPartialFetch(searchQ ? String(searchQ.value || '').trim() : '');
                    return;
                }

                if (toastMsg !== '' && typeof pushFilesToast === 'function') {
                    pushFilesToast(toastMsg, summary.failedCount > 0 || summary.cancelled ? 'warning' : 'success');
                }
                if (summary.okCount > 0 && listingShell && typeof runPartialFetch === 'function') {
                    var searchQ2 = document.getElementById('files-search-q');
                    runPartialFetch(searchQ2 ? String(searchQ2.value || '').trim() : '');
                }
            });
        });
    }

    var DEFAULT_GRANTEE_SUGGEST_CFG = {
        hiddenInputId: 'grantee_ids',
        searchInputId: 'files-grantee-search-input',
        listId: 'files-grantee-suggestions',
        chipsId: 'files-grantee-chips',
        liveId: 'files-grantee-live',
        optionIdPrefix: 'files-grantee-opt-'
    };

    /**
     * @param {HTMLElement} modalEl Modal with grantee dataset URLs and messages.
     * @param {object} [cfg] Optional id overrides for a second grantee UI on the page.
     * @return {void}
     */
    function bindGranteeSuggest(modalEl, cfg) {
        var merged = Object.assign({}, DEFAULT_GRANTEE_SUGGEST_CFG, cfg || {});
        var url = modalEl.dataset.filesGranteeSearchUrl || '';
        var hiddenInput = document.getElementById(merged.hiddenInputId);
        var searchInput = document.getElementById(merged.searchInputId);
        var listEl = document.getElementById(merged.listId);
        var chipsEl = document.getElementById(merged.chipsId);
        var liveEl = document.getElementById(merged.liveId);
        if (!url || !hiddenInput || !searchInput || !listEl || !chipsEl) {
            return;
        }

        var msgFetchError = modalEl.dataset.msgGranteeFetchError || '';
        var msgLiveNone = modalEl.dataset.msgGranteeLiveNone || '';
        var msgLiveResults = modalEl.dataset.msgGranteeLiveResults || '';
        var msgRemoveAria = modalEl.dataset.msgGranteeRemoveAria || '';

        /** @type {Array<{id: number, label: string, expiresAt: string}>} */
        var selected = [];
        var debounceTimer = null;
        var abortCtl = null;
        /** @type {Array<{id: number, label: string}>} */
        var lastSuggestions = [];
        var activeIndex = -1;

        /**
         * @return {void}
         */
        function syncHidden() {
            hiddenInput.value = selected
                .map(function (s) {
                    return String(s.id);
                })
                .join(',');
        }

        /**
         * @return {void}
         */
        function renderChips() {
            chipsEl.innerHTML = '';
            var msgGrantExpiresLabel = modalEl.dataset.msgGrantExpiresLabel || '';
            selected.forEach(function (item) {
                var wrap = document.createElement('div');
                wrap.className = 'badge bg-secondary d-inline-flex align-items-center gap-2 files-grantee-chip';
                wrap.setAttribute('data-grantee-id', String(item.id));
                var label = document.createElement('span');
                label.textContent = item.label;
                wrap.appendChild(label);
                if (item.expired && modalEl.dataset.msgGrantExpiredBadge) {
                    var expBadge = document.createElement('span');
                    expBadge.className = 'badge text-bg-secondary ms-1';
                    expBadge.textContent = modalEl.dataset.msgGrantExpiredBadge;
                    wrap.appendChild(expBadge);
                }
                var expLabel = document.createElement('label');
                expLabel.className = 'visually-hidden';
                expLabel.setAttribute('for', 'files-share-friends-exp-' + String(item.id));
                expLabel.textContent = msgGrantExpiresLabel;
                wrap.appendChild(expLabel);
                var expInput = document.createElement('input');
                expInput.type = 'datetime-local';
                expInput.id = 'files-share-friends-exp-' + String(item.id);
                expInput.className = 'form-control form-control-sm';
                expInput.style.maxWidth = '12rem';
                expInput.setAttribute('data-files-grant-expires-at', '1');
                expInput.value = item.expiresAt || '';
                if (item.expirationMixed) {
                    expInput.classList.add('border-warning');
                }
                expInput.addEventListener('change', function () {
                    selected = selected.map(function (row) {
                        if (row.id !== item.id) {
                            return row;
                        }
                        return {
                            id: row.id,
                            label: row.label,
                            expiresAt: expInput.value || '',
                            expirationMixed: row.expirationMixed,
                            expired: row.expired
                        };
                    });
                });
                wrap.appendChild(expInput);
                if (item.expirationMixed && modalEl.dataset.msgGrantExpirationMixed) {
                    var mixNote = document.createElement('small');
                    mixNote.className = 'text-body-secondary d-block w-100';
                    mixNote.textContent = modalEl.dataset.msgGrantExpirationMixed;
                    wrap.appendChild(mixNote);
                }
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn-close btn-close-sm files-grantee-chip-remove';
                btn.setAttribute('aria-label', msgRemoveAria);
                btn.addEventListener('click', function () {
                    selected = selected.filter(function (s) {
                        return s.id !== item.id;
                    });
                    syncHidden();
                    renderChips();
                });
                wrap.appendChild(btn);
                chipsEl.appendChild(wrap);
            });
        }

        /**
         * @brief Replace selected grantees from external modal workflow.
         * @param {Array<{id:number,label:string,expiresAt?:string}>} rows Selected grantee rows.
         * @return {void}
         * @date 2026-04-29
         * @author Stephane H.
         */
        function setSelectedRows(rows) {
            selected = [];
            (rows || []).forEach(function (row) {
                var id = Number(row && row.id ? row.id : 0);
                var label = row && row.label ? String(row.label) : '';
                var expiresAt = row && row.expiresAt ? String(row.expiresAt) : '';
                if (id > 0) {
                    selected.push({
                        id: id,
                        label: label !== '' ? label : String(id),
                        expiresAt: expiresAt,
                        expirationMixed: !!(row && row.expirationMixed),
                        expired: !!(row && row.expired)
                    });
                }
            });
            syncHidden();
            renderChips();
        }
        modalEl.__filesSetGranteeSelection = setSelectedRows;

        /**
         * @param {boolean} open Whether listbox is expanded.
         * @return {void}
         */
        function setListboxOpen(open) {
            searchInput.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                listEl.classList.remove('d-none');
                listEl.removeAttribute('hidden');
            } else {
                listEl.classList.add('d-none');
                listEl.setAttribute('hidden', 'hidden');
            }
        }

        /**
         * @param {string} announce Text for aria-live region.
         * @return {void}
         */
        function announce(announce) {
            if (liveEl) {
                liveEl.textContent = announce;
            }
        }

        /**
         * @return {void}
         */
        function hideSuggestions() {
            listEl.innerHTML = '';
            lastSuggestions = [];
            activeIndex = -1;
            setListboxOpen(false);
        }

        /**
         * @brief Render grantee suggestions in the listbox, including an explicit
         *        "no result" row when the backend returns an empty list.
         * @param {Array<{id: number, label: string}>} users Suggestion rows.
         * @return {void}
         * @date 2026-04-28
         * @author Stephane H.
         */
        function renderSuggestions(users) {
            listEl.innerHTML = '';
            lastSuggestions = users;
            activeIndex = users.length > 0 ? 0 : -1;
            if (users.length === 0) {
                announce(msgLiveNone);
                var emptyLi = document.createElement('li');
                emptyLi.className = 'list-group-item disabled';
                emptyLi.setAttribute('role', 'option');
                emptyLi.setAttribute('aria-disabled', 'true');
                emptyLi.tabIndex = -1;
                emptyLi.textContent = msgLiveNone;
                listEl.appendChild(emptyLi);
                searchInput.setAttribute('aria-activedescendant', '');
                setListboxOpen(true);
                return;
            }
            announce(msgLiveResults.replace('%count%', String(users.length)));
            var optPrefix = merged.optionIdPrefix;
            users.forEach(function (row, idx) {
                var li = document.createElement('li');
                li.className = 'list-group-item list-group-item-action files-grantee-suggestion-item';
                li.setAttribute('role', 'option');
                li.setAttribute('id', optPrefix + row.id);
                li.setAttribute('data-id', String(row.id));
                li.setAttribute('data-label', row.label);
                li.tabIndex = -1;
                li.textContent = row.label;
                if (idx === 0) {
                    li.classList.add('active');
                    li.setAttribute('aria-selected', 'true');
                } else {
                    li.setAttribute('aria-selected', 'false');
                }
                li.addEventListener('mousedown', function (ev) {
                    ev.preventDefault();
                });
                li.addEventListener('click', function () {
                    pickUser(row.id, row.label);
                });
                listEl.appendChild(li);
            });
            searchInput.setAttribute('aria-activedescendant', users.length > 0 ? optPrefix + users[0].id : '');
            setListboxOpen(true);
        }

        /**
         * @param {number} id Grantee user id.
         * @param {string} label Display label.
         * @return {void}
         */
        function pickUser(id, label) {
            if (selected.some(function (s) {
                return s.id === id;
            })) {
                hideSuggestions();
                searchInput.value = '';
                return;
            }
            selected.push({ id: id, label: label, expiresAt: '' });
            syncHidden();
            renderChips();
            hideSuggestions();
            searchInput.value = '';
            searchInput.focus();
        }

        /**
         * @param {number} delta Move active option.
         * @return {void}
         */
        function moveActive(delta) {
            var items = listEl.querySelectorAll('.files-grantee-suggestion-item');
            if (items.length === 0) {
                return;
            }
            activeIndex = (activeIndex + delta + items.length) % items.length;
            items.forEach(function (node, i) {
                if (i === activeIndex) {
                    node.classList.add('active');
                    node.setAttribute('aria-selected', 'true');
                    searchInput.setAttribute('aria-activedescendant', node.id);
                } else {
                    node.classList.remove('active');
                    node.setAttribute('aria-selected', 'false');
                }
            });
        }

        /**
         * @param {string} q Raw query.
         * @return {void}
         */
        function runSuggest(q) {
            if (abortCtl) {
                abortCtl.abort();
            }
            abortCtl = new AbortController();
            var reqUrl = url + (url.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q);
            fetch(reqUrl, {
                method: 'GET',
                credentials: 'same-origin',
                signal: abortCtl.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (res) {
                    if (!res.ok) {
                        throw new Error('Suggest failed');
                    }
                    return res.json();
                })
                .then(function (data) {
                    var users = data && Array.isArray(data.users) ? data.users : [];
                    renderSuggestions(users);
                })
                .catch(function (err) {
                    if (err.name === 'AbortError') {
                        return;
                    }
                    announce(msgFetchError);
                    hideSuggestions();
                });
        }

        searchInput.addEventListener('input', function () {
            var raw = searchInput.value.trim();
            activeIndex = -1;
            if (debounceTimer) {
                window.clearTimeout(debounceTimer);
            }
            if (raw.length < MIN_GRANTEE_SEARCH_LEN) {
                hideSuggestions();
                return;
            }
            debounceTimer = window.setTimeout(function () {
                runSuggest(raw);
            }, DEBOUNCE_MS);
        });

        searchInput.addEventListener('keydown', function (e) {
            if (!listEl.classList.contains('d-none') && lastSuggestions.length > 0) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    moveActive(1);
                    return;
                }
                if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    moveActive(-1);
                    return;
                }
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var choice = lastSuggestions[activeIndex >= 0 ? activeIndex : 0];
                    if (choice) {
                        pickUser(choice.id, choice.label);
                    }
                    return;
                }
                if (e.key === 'Escape') {
                    e.preventDefault();
                    hideSuggestions();
                    return;
                }
            }
        });

        modalEl.addEventListener('hidden.bs.modal', function () {
            hideSuggestions();
            if (debounceTimer) {
                window.clearTimeout(debounceTimer);
            }
            if (abortCtl) {
                abortCtl.abort();
            }
            setSelectedRows([]);
        });
    }

    var uploadModalEl = document.getElementById('filesUploadModal');
    var form = document.getElementById('files-upload-form');
    var fileInput = document.getElementById('file-upload');
    var zones = document.querySelectorAll('[data-files-drop-zone]');

    if (form && fileInput && uploadModalEl) {
        bindFilesUploadProgress(form, fileInput, uploadModalEl);
        if (zones.length) {
            zones.forEach(function (zone) {
                bindDropZone(zone, fileInput, uploadModalEl);
            });
            bindDocumentFileDrop(fileInput, uploadModalEl);
        }
    }

    /**
     * @brief Read subject user id from checked pane or first user pane (admin multi-pane).
     * @return {string}
     * @date 2026-05-04
     * @author Stephane H.
     */
    function readSubjectUserIdFromDomForTargetOwner() {
        var sid = getSubjectUserIdFromActivePane();
        if (sid !== '') {
            return sid;
        }
        var shell = document.getElementById('files-listing-shell');
        if (shell) {
            var fromShell = String(shell.getAttribute('data-files-listing-subject-user-id') || '').trim();
            if (fromShell !== '') {
                return fromShell;
            }
        }
        return '';
    }

    /**
     * @brief Read subject user display label from checked pane or first user pane (admin multi-pane).
     * @return {string}
     * @date 2026-05-04
     * @author Stephane H.
     */
    function readSubjectUserLabelFromDomForTargetOwner() {
        var paneId = getActivePaneIdFromSelection();
        var paneEl = paneId ? document.getElementById(paneId) : null;
        if (paneEl) {
            return String(paneEl.getAttribute('data-files-subject-user-label') || '').trim();
        }
        var shell = document.getElementById('files-listing-shell');
        if (shell) {
            return String(shell.getAttribute('data-files-listing-subject-user-label') || '').trim();
        }
        return '';
    }

    /**
     * @brief Resolve upload target folder id from listing state for one owner.
     * @param {string} ownerIdText Owner id as text.
     * @param {Record<string, unknown>} listingState Listing state payload.
     * @return {number}
     * @date 2026-05-08
     * @author Stephane H.
     */
    function resolveUploadTargetFolderIdFromListing(ownerIdText, listingState) {
        var ownerId = Number(ownerIdText || '0');
        if (ownerId < 1) {
            return 0;
        }
        var key = 'uf_' + String(ownerId);
        var namespaced = Number(listingState && listingState[key] !== undefined ? listingState[key] : 0);
        if (namespaced > 0) {
            return namespaced;
        }
        var generic = Number(listingState && listingState.folder !== undefined ? listingState.folder : 0);
        return generic > 0 ? generic : 0;
    }

    /**
     * @brief Sync explicit upload target folder hidden input for all-users admin scope.
     * @param {HTMLFormElement} formEl Upload form element.
     * @return {void}
     * @date 2026-05-08
     * @author Stephane H.
     */
    function syncUploadTargetFolderInput(formEl) {
        if (!formEl) {
            return;
        }
        var targetFolderInp = formEl.querySelector('input[name="target_folder_id"]');
        if (!targetFolderInp) {
            return;
        }
        var state = readListingState();
        var isAdminAll = String(state.admin_context || '') === '1'
            && String(state.admin_view_scope || '') === 'all'
            && String(state.view_scope || '') === 'all';
        if (!isAdminAll) {
            targetFolderInp.value = '';
            return;
        }
        var ownerInp = formEl.querySelector('input[name="target_owner_user_id"]');
        var ownerIdText = ownerInp ? String(ownerInp.value || '').trim() : '';
        if (ownerIdText === '') {
            targetFolderInp.value = '';
            return;
        }
        var folderId = resolveUploadTargetFolderIdFromListing(ownerIdText, state);
        targetFolderInp.value = folderId > 0 ? String(folderId) : '';
    }

    /**
     * @brief Toggle submit buttons that require a chosen target owner id.
     * @param {HTMLFormElement} formEl Form element.
     * @return {void}
     * @date 2026-05-04
     * @author Stephane H.
     */
    function updateTargetOwnerSubmitState(formEl) {
        var modalRoot = formEl.closest('[data-require-target-owner="1"]');
        if (!modalRoot) {
            return;
        }
        var hid = formEl.querySelector('input[name="target_owner_user_id"]');
        var ok = !!(hid && String(hid.value || '').trim() !== '');
        formEl.querySelectorAll('[data-files-target-owner-submit]').forEach(function (btn) {
            btn.disabled = !ok;
        });
    }

    /**
     * @brief Wire owner datalist suggestions and pane prefill for modals with data-require-target-owner.
     * @return {void}
     * @date 2026-05-08
     * @author Stephane H.
     */
    function initAdminTargetOwnerModals() {
        document.querySelectorAll('[data-require-target-owner="1"]').forEach(function (modalRoot) {
            if (modalRoot.getAttribute('data-files-target-owner-wired') === '1') {
                return;
            }
            var suggestUrl = modalRoot.getAttribute('data-files-admin-owner-suggest-url') || '';
            var formEl = modalRoot.querySelector('form');
            if (!formEl || !suggestUrl) {
                return;
            }
            var searchInp = formEl.querySelector('[data-files-target-owner-search]');
            var hidInp = formEl.querySelector('input[name="target_owner_user_id"]');
            if (!searchInp || !hidInp) {
                return;
            }
            var listId = searchInp.getAttribute('list') || '';
            var datalistEl = listId ? document.getElementById(listId) : null;
            if (!datalistEl) {
                return;
            }
            var debounceTimer = null;

            /**
             * @param {Array<{id:number,label:string}>} users Payload from suggest endpoint.
             * @return {void}
             */
            function renderOptions(users) {
                datalistEl.innerHTML = '';
                users.forEach(function (u) {
                    var opt = document.createElement('option');
                    opt.value = String(u.label || '');
                    opt.setAttribute('data-owner-id', String(u.id || 0));
                    datalistEl.appendChild(opt);
                });
            }

            /**
             * @param {string} label Label text to match against datalist options.
             * @return {void}
             */
            function syncHiddenFromLabel(label) {
                var t = String(label || '').trim();
                if (!t) {
                    hidInp.value = '';
                    updateTargetOwnerSubmitState(formEl);
                    return;
                }
                var matched = '';
                datalistEl.querySelectorAll('option').forEach(function (opt) {
                    if (matched) {
                        return;
                    }
                    if ((opt.value || '').trim() === t) {
                        matched = opt.getAttribute('data-owner-id') || '';
                    }
                });
                if (matched) {
                    hidInp.value = matched;
                } else {
                    hidInp.value = '';
                }
                updateTargetOwnerSubmitState(formEl);
            }

            function fetchSuggestions() {
                var q = searchInp.value.trim();
                if (q.length < 1) {
                    renderOptions([]);
                    updateTargetOwnerSubmitState(formEl);
                    return;
                }
                var url = suggestUrl + (suggestUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q);
                fetch(url, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (r) {
                        return r.json();
                    })
                    .then(function (data) {
                        var users = data && Array.isArray(data.users) ? data.users : [];
                        renderOptions(users);
                        if (!String(hidInp.value || '').trim()) {
                            syncHiddenFromLabel(searchInp.value);
                        } else {
                            updateTargetOwnerSubmitState(formEl);
                        }
                    })
                    .catch(function () {
                        renderOptions([]);
                        if (!String(hidInp.value || '').trim()) {
                            syncHiddenFromLabel(searchInp.value);
                        } else {
                            updateTargetOwnerSubmitState(formEl);
                        }
                    });
            }

            function getTargetOwnerToastMessages() {
                var i18n = document.getElementById('files-target-owner-i18n');
                return {
                    ambiguous: i18n ? (i18n.getAttribute('data-msg-target-owner-ambiguous') || '') : '',
                    notFound: i18n ? (i18n.getAttribute('data-msg-target-owner-not-found') || '') : ''
                };
            }

            function pushTargetOwnerToast(message) {
                if (!message) {
                    return;
                }
                var lr = typeof liveRegion !== 'undefined' && liveRegion ? liveRegion : document.getElementById('files-live-region');
                var closeLabel = lr && lr.getAttribute ? (lr.getAttribute('data-files-toast-close-label') || '') : '';
                if (window.AppFlashToasts && typeof window.AppFlashToasts.push === 'function') {
                    window.AppFlashToasts.push(message, 'danger', { closeLabel: closeLabel });
                }
            }

            function resolveTargetOwnerFromServer() {
                var lr = typeof liveRegion !== 'undefined' && liveRegion ? liveRegion : document.getElementById('files-live-region');
                if (!lr) {
                    return;
                }
                var resolveUrl = lr.getAttribute('data-files-admin-owner-resolve-url') || '';
                var q = String(searchInp.value || '').trim();
                if (resolveUrl === '' || q === '' || String(hidInp.value || '').trim() !== '') {
                    return;
                }
                var rUrl = resolveUrl + (resolveUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q);
                fetch(rUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (r) {
                        return r.json();
                    })
                    .then(function (data) {
                        var status = data && data.status ? String(data.status) : '';
                        if (status === 'ok' && data.id) {
                            hidInp.value = String(data.id);
                            if (data.label) {
                                searchInp.value = String(data.label);
                            }
                        } else if (status === 'ambiguous') {
                            var msgs = getTargetOwnerToastMessages();
                            pushTargetOwnerToast(msgs.ambiguous || msgs.notFound);
                        } else {
                            var m2 = getTargetOwnerToastMessages();
                            pushTargetOwnerToast(m2.notFound || m2.ambiguous);
                        }
                        updateTargetOwnerSubmitState(formEl);
                    })
                    .catch(function () {
                        var m3 = getTargetOwnerToastMessages();
                        pushTargetOwnerToast(m3.notFound);
                        updateTargetOwnerSubmitState(formEl);
                    });
            }

            var resolveDebounceTimer = null;
            function scheduleResolveAfterSync() {
                syncHiddenFromLabel(searchInp.value);
                if (String(hidInp.value || '').trim() !== '') {
                    return;
                }
                if (String(searchInp.value || '').trim() === '') {
                    return;
                }
                if (resolveDebounceTimer) {
                    window.clearTimeout(resolveDebounceTimer);
                }
                resolveDebounceTimer = window.setTimeout(function () {
                    resolveDebounceTimer = null;
                    resolveTargetOwnerFromServer();
                }, 120);
            }

            searchInp.addEventListener('input', function () {
                if (debounceTimer) {
                    window.clearTimeout(debounceTimer);
                }
                debounceTimer = window.setTimeout(fetchSuggestions, 200);
            });
            searchInp.addEventListener('change', function () {
                scheduleResolveAfterSync();
            });
            searchInp.addEventListener('blur', function () {
                scheduleResolveAfterSync();
            });

            modalRoot.addEventListener('show.bs.modal', function () {
                var sid = readSubjectUserIdFromDomForTargetOwner();
                var paneLabel = readSubjectUserLabelFromDomForTargetOwner();
                if (sid !== '' && sid !== '0') {
                    hidInp.value = sid;
                    searchInp.value = paneLabel || '';
                } else {
                    hidInp.value = '';
                    searchInp.value = '';
                }
                renderOptions([]);
                updateTargetOwnerSubmitState(formEl);
                syncUploadTargetFolderInput(formEl);
            });
            hidInp.addEventListener('input', function () {
                syncUploadTargetFolderInput(formEl);
            });
            hidInp.addEventListener('change', function () {
                syncUploadTargetFolderInput(formEl);
            });
            modalRoot.setAttribute('data-files-target-owner-wired', '1');
        });
    }

    initAdminTargetOwnerModals();

    var friendsShareModalEl = document.getElementById('filesShareFriendsModal');
    if (friendsShareModalEl) {
        bindGranteeSuggest(friendsShareModalEl, {
            hiddenInputId: 'files-share-friends-grantee-ids',
            searchInputId: 'files-share-friends-grantee-input',
            listId: 'files-share-friends-grantee-suggestions',
            chipsId: 'files-share-friends-grantee-chips',
            liveId: 'files-share-friends-grantee-live',
            optionIdPrefix: 'files-share-friends-grantee-opt-'
        });
    }

    var searchInput = document.getElementById('files-search-q');
    var clearBtn = document.getElementById('files-search-clear');
    var liveRegion = document.getElementById('files-live-region');
    /** Listing shell must exist; search box may be absent in edge layouts without skipping other handlers. */
    if (!liveRegion) {
        return;
    }
    var initialFilesListingQuery = {};
    try {
        initialFilesListingQuery = JSON.parse(liveRegion.getAttribute('data-files-listing-query') || '{}');
    } catch (ignoreErr) {
        initialFilesListingQuery = {};
    }
    var lastValidAdminScopeLabel = '';
    var scopeBadgeInitial = document.querySelector('[data-files-admin-scope-badge-label]');
    if (scopeBadgeInitial) {
        var initScopeLabel = String(scopeBadgeInitial.textContent || '').trim();
        if (initScopeLabel !== '') {
            lastValidAdminScopeLabel = initScopeLabel;
        }
    }
    document.addEventListener('contextmenu', function (event) {
        var ownerGroupRow = event.target && event.target.closest
            ? event.target.closest('tr[data-files-owner-group-row="1"]')
            : null;
        if (!ownerGroupRow) {
            return;
        }
        var toggle = ownerGroupRow.querySelector(
            '[id^="files-actions-"],[id^="files-folder-actions-"],[id^="files-shared-folder-actions-"]'
        );
        if (!toggle) {
            return;
        }
        event.preventDefault();
        if (window.bootstrap && window.bootstrap.Dropdown && typeof window.bootstrap.Dropdown.getOrCreateInstance === 'function') {
            window.bootstrap.Dropdown.getOrCreateInstance(toggle).show();
        } else {
            toggle.click();
        }
    }, true);

    var endpoint = liveRegion.getAttribute('data-files-list-endpoint') || '';
    var abortCtl = null;
    var debounceTimer = null;
    var FILES_SECTION_MY_FILES_KEY = 'files.section.my_files.expanded';
    var FILES_SECTION_SHARED_FOR_ME_KEY = 'files.section.shared_for_me.expanded';
    var sectionStateFallbackStore = {};

    /**
     * @brief Initialize adaptive Popper behavior for row action dropdowns.
     * @param {ParentNode|Document} root Container where dropdown toggles are searched.
     * @return {void}
     * @date 2026-04-27
     * @author Stephane H.
     */
    function initAdaptiveActionDropdowns(root) {
        if (!window.bootstrap || !window.bootstrap.Dropdown) {
            return;
        }
        var scope = root || document;
        var toggles = scope.querySelectorAll('.files-actions-dropdown > .dropdown-toggle');
        toggles.forEach(function (toggle) {
            window.bootstrap.Dropdown.getOrCreateInstance(toggle, {
                popperConfig: function (defaultBsPopperConfig) {
                    var defaults = defaultBsPopperConfig || {};
                    var existingModifiers = Array.isArray(defaults.modifiers) ? defaults.modifiers : [];
                    var filtered = existingModifiers.filter(function (m) {
                        return m && m.name !== 'flip' && m.name !== 'preventOverflow' && m.name !== 'offset';
                    });
                    filtered.push({
                        name: 'offset',
                        options: { offset: [0, 4] }
                    });
                    filtered.push({
                        name: 'flip',
                        options: {
                            fallbackPlacements: ['top-end', 'right-start', 'left-start'],
                            boundary: 'viewport',
                            padding: 8
                        }
                    });
                    filtered.push({
                        name: 'preventOverflow',
                        options: {
                            boundary: 'viewport',
                            padding: 8,
                            altAxis: true
                        }
                    });

                    return Object.assign({}, defaults, {
                        placement: 'bottom-end',
                        strategy: 'fixed',
                        modifiers: filtered
                    });
                }
            });
        });
    }

    var COLUMN_PREF_KEY = 'files.columns.visibility';
    var TOGGLEABLE_COLUMNS = ['type', 'size', 'share_public', 'share_friends', 'uploaded', 'modified'];
    var COLUMN_VISIBILITY_DEFAULTS = {
        type: true,
        size: true,
        share_public: true,
        share_friends: true,
        uploaded: false,
        modified: false
    };

    /**
     * @brief Read user column visibility preferences from localStorage and
     *        normalize legacy payloads by merging stored values with explicit
     *        default visibility for each known toggleable column.
     * @return {Record<string, boolean>} Map of column key to visibility flag.
     * @date 2026-04-29
     * @author Stephane H.
     */
    function readColumnPrefs() {
        var normalizedDefaults = {};
        TOGGLEABLE_COLUMNS.forEach(function (k) {
            normalizedDefaults[k] = COLUMN_VISIBILITY_DEFAULTS[k] === true;
        });
        try {
            var raw = window.localStorage.getItem(COLUMN_PREF_KEY);
            var parsed = raw ? JSON.parse(raw) : null;
            var out = Object.assign({}, normalizedDefaults);
            if (!parsed || typeof parsed !== 'object') {
                return out;
            }
            TOGGLEABLE_COLUMNS.forEach(function (k) {
                if (typeof parsed[k] === 'boolean') {
                    out[k] = parsed[k];
                }
            });
            return out;
        } catch (e) {
            return normalizedDefaults;
        }
    }

    /**
     * @brief Persist column visibility preferences to localStorage. Failures
     *        (quota exceeded, disabled storage) are silently ignored so the
     *        UI keeps working without persistence.
     * @param {Record<string, boolean>} prefs Column visibility flags.
     * @return {void}
     * @date 2026-04-29
     * @author Stephane H.
     */
    function writeColumnPrefs(prefs) {
        try {
            window.localStorage.setItem(COLUMN_PREF_KEY, JSON.stringify(prefs));
        } catch (e) {
            return;
        }
    }

    /**
     * @brief Apply current column visibility preferences to header and body
     *        cells inside the given scope by toggling Bootstrap's d-none class.
     * @param {ParentNode|Document|null} scope Subtree to update; defaults to document.
     * @return {void}
     * @date 2026-04-29
     * @author Stephane H.
     */
    function applyColumnVisibility(scope) {
        var prefs = readColumnPrefs();
        var root = scope || document;
        TOGGLEABLE_COLUMNS.forEach(function (col) {
            var cells = root.querySelectorAll('[data-files-col="' + col + '"]');
            cells.forEach(function (cell) {
                if (prefs[col]) {
                    cell.classList.remove('d-none');
                } else {
                    cell.classList.add('d-none');
                }
            });
        });
    }

    /**
     * @brief Mirror column preferences onto the toolbar checkboxes so the UI
     *        state matches the persisted user choice on first paint.
     * @return {void}
     * @date 2026-04-29
     * @author Stephane H.
     */
    function syncColumnToggleCheckboxes() {
        var prefs = readColumnPrefs();
        TOGGLEABLE_COLUMNS.forEach(function (col) {
            var cb = document.querySelector('[data-files-col-toggle="' + col + '"]');
            if (cb) {
                cb.checked = !!prefs[col];
            }
        });
    }

    /**
     * @brief Rewrite normalized column preferences to localStorage so legacy
     *        partial payloads are upgraded to the canonical six-column schema.
     * @return {void}
     * @date 2026-04-29
     * @author Stephane H.
     */
    function ensureColumnPrefsCanonical() {
        var prefs = readColumnPrefs();
        writeColumnPrefs(prefs);
    }

    /**
     * @return {Record<string, unknown>}
     */
    function readListingState() {
        var raw = liveRegion.getAttribute('data-files-listing-query') || '{}';
        try {
            var parsed = JSON.parse(raw);
            return parsed;
        } catch (e) {
            return {};
        }
    }

    /**
     * @param {Record<string, unknown>} state Listing state object.
     * @return {void}
     */
    function writeListingState(state) {
        liveRegion.setAttribute('data-files-listing-query', JSON.stringify(state));
    }

    /**
     * @brief Sync modal retain hidden fields from current listing state for upload/folder forms.
     * @param {Record<string, unknown>} state Listing state object.
     * @return {void}
     * @date 2026-05-07
     * @author Stephane H.
     */
    function syncModalRetainStateFromListing(state) {
        var forms = document.querySelectorAll('form');
        var scalarKeys = [
            'q',
            'sort',
            'dir',
            'filter_public',
            'view',
            'folder',
            'listing_scope',
            'admin_context',
            'admin_view_scope',
            'owner',
            'owner_query',
            'filter_has_grant',
            'uploaded_after',
            'uploaded_before',
            'updated_after',
            'updated_before',
            'expires_after',
            'expires_before',
            'view_scope',
            'subject_user',
            'users_page',
            'users_page_size',
            'users_sort',
            'users_dir',
            'pane',
            'shared_folder'
        ];
        forms.forEach(function (formEl) {
            if (!formEl.querySelector('input[name="_retain_admin_context"]')) {
                return;
            }
            scalarKeys.forEach(function (key) {
                var retainInput = formEl.querySelector('input[name="_retain_' + key + '"]');
                if (!retainInput) {
                    return;
                }
                var rawVal = state[key];
                retainInput.value = rawVal === undefined || rawVal === null ? '' : String(rawVal);
            });
        });
    }

    /**
     * @brief Restore admin godview keys into listing state when partial updates drop them.
     * @param {Record<string, unknown>} target Next listing state object (mutated).
     * @return {void}
     * @date 2026-05-07
     * @author Stephane H.
     */
    function preserveAdminListingKeys(target) {
        var isAdminRoute = !!document.querySelector('.files-space-page--admin-files-route');
        if (isAdminRoute && String(target.admin_context || '') !== '1') {
            target.admin_context = '1';
        }
        var sources = [initialFilesListingQuery];
        try {
            var parsedLive = JSON.parse(liveRegion.getAttribute('data-files-listing-query') || '{}');
            if (parsedLive && typeof parsedLive === 'object') {
                sources.push(parsedLive);
            }
        } catch (ignoreLive) {
            // ignore
        }
        ['admin_context', 'admin_view_scope', 'owner', 'owner_query', 'view_scope', 'subject_user'].forEach(function (k) {
            var empty = target[k] === undefined || target[k] === null || String(target[k]).trim() === '';
            if (!empty) {
                return;
            }
            for (var i = 0; i < sources.length; i++) {
                var src = sources[i];
                if (!src || typeof src !== 'object') {
                    continue;
                }
                if (src[k] !== undefined && src[k] !== null && String(src[k]).trim() !== '') {
                    target[k] = src[k];
                    break;
                }
            }
        });
        if (String(target.admin_context || '') === '1') {
            if (String(target.admin_view_scope || '').trim() === '') {
                target.admin_view_scope = 'owner';
            }
            if (String(target.admin_view_scope || '') === 'all') {
                target.view_scope = 'all';
                delete target.owner;
                delete target.owner_query;
                delete target.subject_user;
            }
        }
        if (String(target.admin_context || '') === '1' && String(target.admin_view_scope || 'owner') === 'owner') {
            var ow = target.owner;
            if (ow === undefined || ow === null || String(ow).trim() === '') {
                var sid = liveRegion.getAttribute('data-files-admin-session-user-id') || '';
                if (sid !== '') {
                    target.owner = sid;
                }
            }
        }
    }

    /**
     * @brief Return the persistence key used for a files section accordion id.
     * @param {string} sectionId Section identifier from data-files-section.
     * @return {string}
     * @date 2026-04-29
     * @author Stephane H.
     */
    function getSectionStorageKey(sectionId) {
        if (sectionId === 'my_files') {
            return FILES_SECTION_MY_FILES_KEY;
        }
        if (sectionId === 'shared_for_me') {
            return FILES_SECTION_SHARED_FOR_ME_KEY;
        }
        return '';
    }

    /**
     * @brief Read a persisted accordion expanded state from localStorage with an in-memory fallback.
     * @param {string} storageKey Persistence key.
     * @param {boolean} defaultExpanded Default value when no persisted state is found.
     * @return {boolean}
     * @date 2026-04-29
     * @author Stephane H.
     */
    function readPersistedSectionExpanded(storageKey, defaultExpanded) {
        if (storageKey === '') {
            return defaultExpanded;
        }
        if (Object.prototype.hasOwnProperty.call(sectionStateFallbackStore, storageKey)) {
            return sectionStateFallbackStore[storageKey] === '1';
        }
        try {
            var raw = window.localStorage.getItem(storageKey);
            if (raw === '1' || raw === '0') {
                return raw === '1';
            }
        } catch (e) {
            return defaultExpanded;
        }
        return defaultExpanded;
    }

    /**
     * @brief Persist a files accordion expanded state in localStorage, falling back to memory when unavailable.
     * @param {string} storageKey Persistence key.
     * @param {boolean} expanded Expanded flag to persist.
     * @return {void}
     * @date 2026-04-29
     * @author Stephane H.
     */
    function writePersistedSectionExpanded(storageKey, expanded) {
        if (storageKey === '') {
            return;
        }
        var value = expanded ? '1' : '0';
        sectionStateFallbackStore[storageKey] = value;
        try {
            window.localStorage.setItem(storageKey, value);
        } catch (e) {
            return;
        }
    }

    /**
     * @brief Re-apply persisted accordion open/closed states and bind persistence listeners on the given root.
     * @param {ParentNode|Document} root DOM root that contains accordion section nodes.
     * @return {void}
     * @date 2026-04-29
     * @author Stephane H.
     */
    function initFilesSectionAccordions(root) {
        if (!window.bootstrap || !window.bootstrap.Collapse) {
            return;
        }
        var scope = root || document;
        var sections = scope.querySelectorAll('[data-files-section]');
        sections.forEach(function (sectionEl) {
            var sectionId = sectionEl.getAttribute('data-files-section') || '';
            var key = getSectionStorageKey(sectionId);
            if (key === '') {
                return;
            }
            var desiredExpanded = readPersistedSectionExpanded(key, true);
            var collapse = window.bootstrap.Collapse.getOrCreateInstance(sectionEl, { toggle: false });
            if (desiredExpanded) {
                collapse.show();
            } else {
                collapse.hide();
            }
            if (sectionEl.getAttribute('data-files-section-listeners-bound') === '1') {
                return;
            }
            sectionEl.setAttribute('data-files-section-listeners-bound', '1');
            sectionEl.addEventListener('shown.bs.collapse', function () {
                writePersistedSectionExpanded(key, true);
            });
            sectionEl.addEventListener('hidden.bs.collapse', function () {
                writePersistedSectionExpanded(key, false);
            });
        });
    }

    /**
     * @param {Record<string, unknown>} state Listing state object.
     * @param {string} qOverride Search query override.
     * @return {URLSearchParams}
     */
    function buildSearchParams(state, qOverride) {
        var p = new URLSearchParams();
        var merged = Object.assign({}, state);
        if (qOverride !== undefined) {
            if (qOverride === '') {
                delete merged.q;
            } else {
                merged.q = qOverride;
            }
        }
        Object.keys(merged).forEach(function (key) {
            var val = merged[key];
            if (val === undefined || val === null || val === '') {
                return;
            }
            if (Array.isArray(val)) {
                val.forEach(function (item) {
                    if (key === 'ext') {
                        p.append('ext[]', String(item));
                    } else if (key === 'grantee') {
                        p.append('grantee[]', String(item));
                    } else {
                        p.append(key + '[]', String(item));
                    }
                });
            } else {
                p.append(key, String(val));
            }
        });
        p.set('partial', '1');
        return p;
    }

    /**
     * @brief Update admin scope badge and godview target-owner modals from listing state (admin route only; owner required only in all-users all-scope).
     * @param {Record<string, unknown>} state Serialized listing query map.
     * @return {void}
     * @date 2026-05-07
     * @author Stephane H.
     */
    function syncAdminGodviewChromeAfterToolbar(state) {
        var page = document.querySelector('.files-space-page--admin-files-route');
        if (!page) {
            return;
        }
        if (String(state.admin_context || '') !== '1') {
            return;
        }
        var adminViewScope = typeof state.admin_view_scope === 'string' && state.admin_view_scope !== ''
            ? state.admin_view_scope
            : 'owner';
        var scopeBadge = document.querySelector('[data-files-admin-scope-badge]');
        var scopeBadgeLabelEl = document.querySelector('[data-files-admin-scope-badge-label]');
        if (scopeBadge && scopeBadgeLabelEl) {
            var scopeAll = scopeBadge.getAttribute('data-msg-scope-all') || '';
            var fallbackScopeLabel = String(scopeBadgeLabelEl.textContent || '').trim();
            var ownerLabel = '';
            var viewScopeRaw = String(state.view_scope || '');
            var isGlobalAllGrid = adminViewScope === 'all' && viewScopeRaw !== 'user';
            if (isGlobalAllGrid) {
                ownerLabel = scopeAll;
            } else {
                var ow = state.owner;
                if (fallbackScopeLabel !== '') {
                    ownerLabel = fallbackScopeLabel;
                } else if (ow !== undefined && ow !== null && String(ow).trim() !== '') {
                    ownerLabel = String(ow).trim();
                } else if (lastValidAdminScopeLabel.trim() !== '') {
                    ownerLabel = lastValidAdminScopeLabel.trim();
                } else {
                    ownerLabel = scopeAll;
                }
            }
            if (ownerLabel !== '') {
                lastValidAdminScopeLabel = ownerLabel;
                scopeBadgeLabelEl.textContent = ownerLabel;
            }
        }
        var requireOwner = adminViewScope === 'all' && viewScopeRaw === 'all';
        var suggestUrl = liveRegion.getAttribute('data-files-admin-owner-suggest-url') || '';
        var uploadModal = document.getElementById('filesUploadModal');
        var folderModal = document.getElementById('filesCreateFolderModal');
        [uploadModal, folderModal].forEach(function (modalRoot) {
            if (!modalRoot) {
                return;
            }
            if (requireOwner) {
                modalRoot.setAttribute('data-require-target-owner', '1');
                if (suggestUrl) {
                    modalRoot.setAttribute('data-files-admin-owner-suggest-url', suggestUrl);
                }
                modalRoot.querySelectorAll('[data-files-target-owner-block]').forEach(function (blk) {
                    blk.classList.remove('d-none');
                });
                initAdminTargetOwnerModals();
                var formAfter = modalRoot.querySelector('form');
                if (formAfter) {
                    updateTargetOwnerSubmitState(formAfter);
                }
            } else {
                modalRoot.setAttribute('data-require-target-owner', '0');
                modalRoot.removeAttribute('data-files-target-owner-wired');
                modalRoot.querySelectorAll('[data-files-target-owner-block]').forEach(function (blk) {
                    blk.classList.add('d-none');
                });
                var formEl = modalRoot.querySelector('form');
                if (formEl) {
                    formEl.querySelectorAll('input[name="target_owner_user_id"]').forEach(function (inp) {
                        inp.value = '';
                    });
                    formEl.querySelectorAll('[data-files-target-owner-search]').forEach(function (inp) {
                        inp.value = '';
                    });
                    formEl.querySelectorAll('[data-files-target-owner-submit]').forEach(function (btn) {
                        btn.disabled = false;
                    });
                }
            }
        });
        syncModalRetainStateFromListing(state);
    }

    /**
     * @brief Reflect listing route state on navbar scope/view controls after partial HTML swaps.
     * @param {Record<string, unknown>} state Serialized listing query map.
     * @return {void}
     * @date 2026-05-07
     * @author Stephane H.
     */
    function syncFilesToolbarChrome(state) {
        var adminViewScope = typeof state.admin_view_scope === 'string' && state.admin_view_scope !== ''
            ? state.admin_view_scope
            : 'owner';
        var ownerFilterBlock = document.querySelector('[data-files-admin-owner-filter-block]');
        if (ownerFilterBlock) {
            var showOwnerFilter = adminViewScope === 'owner';
            ownerFilterBlock.classList.toggle('d-none', !showOwnerFilter);
            ownerFilterBlock.setAttribute('aria-hidden', showOwnerFilter ? 'false' : 'true');
            ownerFilterBlock.querySelectorAll('[data-files-admin-owner-filter-control]').forEach(function (controlEl) {
                controlEl.disabled = !showOwnerFilter;
            });
            ownerFilterBlock.querySelectorAll('input[name="admin_view_scope"]').forEach(function (scopeInput) {
                scopeInput.value = 'owner';
            });
        }
        document.querySelectorAll('a[data-files-admin-view-scope]').forEach(function (a) {
            var v = a.getAttribute('data-files-admin-view-scope') || '';
            if (v === adminViewScope) {
                a.classList.add('active');
            } else {
                a.classList.remove('active');
            }
        });
        var scopeVal = typeof state.listing_scope === 'string' && state.listing_scope !== ''
            ? state.listing_scope
            : 'both';
        document.querySelectorAll('a[data-files-listing-scope]').forEach(function (a) {
            var v = a.getAttribute('data-files-listing-scope') || '';
            if (v === scopeVal) {
                a.classList.add('active');
            } else {
                a.classList.remove('active');
            }
        });
        var viewVal = typeof state.view === 'string' && state.view !== '' ? state.view : 'list';
        document.querySelectorAll('a[data-files-view-toggle]').forEach(function (a) {
            var t = a.getAttribute('data-files-view-toggle') || '';
            if (t === viewVal) {
                a.classList.add('active');
            } else {
                a.classList.remove('active');
            }
        });
        var colSlot = document.querySelector('.files-toolbar-slot--columns');
        if (colSlot) {
            if (viewVal === 'grid') {
                colSlot.classList.add('d-none');
            } else {
                colSlot.classList.remove('d-none');
            }
        }
        syncModalRetainStateFromListing(state);
        syncAdminGodviewChromeAfterToolbar(state);
    }

    /**
     * @brief Wire admin owner searchable input to suggestion endpoint and hidden owner field.
     * @return {void}
     * @date 2026-05-04
     * @author Stephane H.
     */
    function initAdminOwnerSuggest() {
        var ownerInput = document.getElementById('files-admin-owner-search');
        var ownerHidden = document.getElementById('files-admin-owner-hidden');
        var ownerList = document.getElementById('files-admin-owner-suggest-list');
        if (!ownerInput || !ownerHidden || !ownerList || !liveRegion) {
            return;
        }
        var suggestUrl = liveRegion.getAttribute('data-files-admin-owner-suggest-url') || '';
        if (!suggestUrl) {
            return;
        }
        var suggestTimer = null;
        var debugRunId = 'pre-fix';

        /**
         * @param {string} hypothesisId Hypothesis identifier.
         * @param {string} location Source location marker.
         * @param {string} message Debug message.
         * @param {Record<string, unknown>} data Debug payload.
         * @return {void}
         */
        function postDebugLog(hypothesisId, location, message, data) {
            void debugRunId;
            void hypothesisId;
            void location;
            void message;
            void data;
        }

        /**
         * @param {Array<{id:number,label:string}>} users Suggest payload.
         * @return {void}
         */
        function renderOwnerOptions(users) {
            ownerList.innerHTML = '';
            users.forEach(function (user) {
                var option = document.createElement('option');
                option.value = String(user.label || '');
                option.setAttribute('data-owner-id', String(user.id || 0));
                ownerList.appendChild(option);
            });
        }

        /**
         * @param {string} label Selected label.
         * @return {void}
         */
        function syncHiddenOwnerFromLabel(label) {
            var target = String(label || '').trim();
            if (!target) {
                ownerHidden.value = '';
                postDebugLog('H1', 'public/js/files-space.js:syncHiddenOwnerFromLabel-empty', 'Owner hidden reset because target label is empty.', {
                    inputValue: String(ownerInput.value || '').trim(),
                });
                return;
            }
            var matched = '';
            ownerList.querySelectorAll('option').forEach(function (opt) {
                if (matched) {
                    return;
                }
                if ((opt.value || '').trim() === target) {
                    matched = opt.getAttribute('data-owner-id') || '';
                }
            });
            ownerHidden.value = matched;
            postDebugLog('H1', 'public/js/files-space.js:syncHiddenOwnerFromLabel-match', 'Owner hidden synchronized from datalist label matching.', {
                targetLabel: target,
                matchedOwnerId: matched,
                optionsCount: ownerList.querySelectorAll('option').length,
            });
        }

        function fetchOwnerSuggestions() {
            var q = ownerInput.value.trim();
            if (q.length < 1) {
                renderOwnerOptions([]);
                ownerHidden.value = '';
                return;
            }
            var url = suggestUrl + (suggestUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q);
            fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (res) {
                    if (!res.ok) {
                        throw new Error('owner suggest failed');
                    }
                    return res.json();
                })
                .then(function (payload) {
                    var users = payload && Array.isArray(payload.users) ? payload.users : [];
                    renderOwnerOptions(users);
                    syncHiddenOwnerFromLabel(ownerInput.value);
                    postDebugLog('H2', 'public/js/files-space.js:fetchOwnerSuggestions-success', 'Admin owner suggestions fetched successfully.', {
                        query: q,
                        usersCount: users.length,
                        firstLabel: users.length > 0 ? String(users[0].label || '') : '',
                    });
                })
                .catch(function () {
                    renderOwnerOptions([]);
                    postDebugLog('H2', 'public/js/files-space.js:fetchOwnerSuggestions-failed', 'Admin owner suggestions fetch failed.', {
                        query: q,
                    });
                });
        }

        ownerInput.addEventListener('input', function () {
            if (suggestTimer) {
                window.clearTimeout(suggestTimer);
            }
            suggestTimer = window.setTimeout(fetchOwnerSuggestions, 180);
        });
        ownerInput.addEventListener('change', function () {
            syncHiddenOwnerFromLabel(ownerInput.value);
        });
        var ownerForm = ownerInput.closest('form');
        if (ownerForm) {
            ownerForm.addEventListener('submit', function () {
                syncHiddenOwnerFromLabel(ownerInput.value);
                postDebugLog('H3', 'public/js/files-space.js:ownerForm-submit', 'Admin owner form submitted with visible and hidden values.', {
                    ownerQuery: String(ownerInput.value || '').trim(),
                    ownerHidden: String(ownerHidden.value || '').trim(),
                    adminViewScope: 'owner',
                });
            });
        }
    }

    /**
     * @brief Trimmed value from the listing search input when present.
     * @return {string}
     * @date 2026-05-02
     * @author Stephane H.
     */
    function getFilesSearchTrimmed() {
        var el = document.getElementById('files-search-q');
        return el ? String(el.value || '').trim() : '';
    }

    /**
     * @brief Fetch and replace the listing fragment using current filters and query.
     * @param {string} qTrimmed Trimmed search query for listing.
     * @return {void}
     * @date 2026-05-07
     * @author Stephane H.
     */
    function runPartialFetch(qTrimmed) {
        if (!endpoint) {
            return;
        }
        var base = readListingState();
        var params = buildSearchParams(base, qTrimmed);
        if (abortCtl) {
            abortCtl.abort();
        }
        abortCtl = new AbortController();
        liveRegion.setAttribute('aria-busy', 'true');
        var url = endpoint + (endpoint.indexOf('?') >= 0 ? '&' : '?') + params.toString();
        fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            signal: abortCtl.signal,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('Listing fetch failed');
                }
                return res.text();
            })
            .then(function (html) {
                liveRegion.innerHTML = html;
                initAdaptiveActionDropdowns(liveRegion);
                initFilesSectionAccordions(liveRegion);
                applyColumnVisibility(liveRegion);
                updateSelectionUi();
                var next = Object.assign({}, base);
                if (qTrimmed === '') {
                    delete next.q;
                } else {
                    next.q = qTrimmed;
                }
                preserveAdminListingKeys(next);
                writeListingState(next);
                syncModalRetainStateFromListing(next);
                syncFilesToolbarChrome(next);
                var hist = buildSearchParams(next, qTrimmed);
                hist.delete('partial');
                var pathOnly = window.location.pathname;
                var qs = hist.toString();
                window.history.replaceState({}, '', qs ? pathOnly + '?' + qs : pathOnly);
            })
            .catch(function (err) {
                if (err.name === 'AbortError') {
                    return;
                }
            })
            .finally(function () {
                liveRegion.setAttribute('aria-busy', 'false');
            });
    }

    syncModalRetainStateFromListing(readListingState());

    /**
     * @brief Tell whether a search query contains glob wildcards (* or ?).
     * @param {string} q Trimmed query string.
     * @return {boolean}
     * @date 2026-04-27
     * @author Stephane H.
     */
    function hasGlobWildcards(q) {
        return q.indexOf('*') !== -1 || q.indexOf('?') !== -1;
    }

    /**
     * @brief Tell whether a query is allowed to trigger a live fetch.
     * @param {string} q Trimmed query string.
     * @return {boolean}
     * @date 2026-04-27
     * @author Stephane H.
     */
    function isLiveSearchEligible(q) {
        if (q.length === 0) {
            return true;
        }
        if (q.length >= MIN_LIVE_SEARCH_LEN) {
            return true;
        }
        return hasGlobWildcards(q) && q.length >= 2;
    }

    /**
     * @param {string} raw Raw search box value.
     * @return {void}
     */
    function scheduleFetch(raw) {
        var q = raw.trim();
        if (!isLiveSearchEligible(q)) {
            return;
        }
        if (debounceTimer) {
            window.clearTimeout(debounceTimer);
        }
        debounceTimer = window.setTimeout(function () {
            runPartialFetch(q);
        }, DEBOUNCE_MS);
    }

    /**
     * @return {void}
     */
    function toggleClearVisibility() {
        if (!searchInput || !clearBtn) {
            return;
        }
        var v = searchInput.value.trim();
        var visible = v.length > 3 || (hasGlobWildcards(v) && v.length >= 2);
        if (visible) {
            clearBtn.classList.remove('d-none');
        } else {
            clearBtn.classList.add('d-none');
        }
    }

    var modal = document.getElementById('filesAdvancedFiltersModal');
    var modalQ = document.getElementById('files-modal-field-q');

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            toggleClearVisibility();
            var v = searchInput.value.trim();
            if (isLiveSearchEligible(v) && v.length > 0) {
                scheduleFetch(v);
            }
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                searchInput.value = '';
                toggleClearVisibility();
                runPartialFetch('');
            });
        }

        if (modal && modalQ && window.bootstrap) {
            modal.addEventListener('shown.bs.modal', function () {
                modalQ.value = searchInput.value;
            });
        }

        toggleClearVisibility();
    }
    initAdminOwnerSuggest();

    document.addEventListener('click', function (event) {
        var displayMenuToggle = document.getElementById('files-display-menu-toggle');
        var closeDisplayMenu = function () {
            if (!displayMenuToggle || !window.bootstrap || !window.bootstrap.Dropdown) {
                return;
            }
            window.bootstrap.Dropdown.getOrCreateInstance(displayMenuToggle).hide();
        };
        var viewLink = event.target && event.target.closest ? event.target.closest('a[data-files-view-toggle]') : null;
        if (viewLink) {
            event.preventDefault();
            var view = viewLink.getAttribute('data-files-view-toggle') || 'list';
            var base = readListingState();
            if (view === 'list') {
                delete base.view;
            } else {
                base.view = 'grid';
            }
            writeListingState(base);
            syncModalRetainStateFromListing(base);
            closeDisplayMenu();
            runPartialFetch(getFilesSearchTrimmed());
            return;
        }
        var scopeLink = event.target && event.target.closest ? event.target.closest('a[data-files-listing-scope]') : null;
        if (scopeLink) {
            event.preventDefault();
            var scopeVal = scopeLink.getAttribute('data-files-listing-scope') || 'both';
            var st = readListingState();
            if (scopeVal === 'both') {
                delete st.listing_scope;
            } else {
                st.listing_scope = scopeVal;
            }
            if (scopeVal === 'owned') {
                delete st.shared_folder;
            }
            if (scopeVal === 'shared') {
                delete st.folder;
            }
            writeListingState(st);
            syncModalRetainStateFromListing(st);
            closeDisplayMenu();
            runPartialFetch(getFilesSearchTrimmed());
            return;
        }
        var adminScopeLink = event.target && event.target.closest ? event.target.closest('a[data-files-admin-view-scope]') : null;
        if (adminScopeLink) {
            event.preventDefault();
            var adminScopeVal = adminScopeLink.getAttribute('data-files-admin-view-scope') || 'owner';
            var state = readListingState();
            state.admin_context = '1';
            state.admin_view_scope = adminScopeVal === 'all' ? 'all' : 'owner';
            delete state.shared_folder;
            if (state.admin_view_scope === 'all') {
                state.view_scope = 'all';
                delete state.subject_user;
                delete state.owner;
                delete state.owner_query;
                delete state.folder;
            } else {
                delete state.view_scope;
                delete state.subject_user;
                var adminSid = liveRegion.getAttribute('data-files-admin-session-user-id') || '';
                if (adminSid !== '') {
                    state.owner = adminSid;
                }
            }
            writeListingState(state);
            syncModalRetainStateFromListing(state);
            syncFilesToolbarChrome(state);
            closeDisplayMenu();
            runPartialFetch(getFilesSearchTrimmed());
        }
    });

    var modalForm = document.getElementById('files-modal-filters-form');
    if (modalForm && modalQ && searchInput) {
        modalForm.addEventListener('submit', function () {
            modalQ.value = searchInput.value;
        });
    }

    /**
     * @brief Replace count placeholders in advanced filter button labels.
     * @param {string} template Translation template containing %count%.
     * @param {number} count Numeric selection count.
     * @return {string}
     * @date 2026-05-02
     * @author Stephane H.
     */
    function formatAdvFilterCountMsg(template, count) {
        if (!template) {
            return '';
        }

        return template.split('%count%').join(String(count));
    }

    /**
     * @brief Wire extension and grantee checkbox dropdowns in the advanced filters modal.
     * @param {Document|HTMLElement} root Document or subtree root.
     * @return {void}
     * @date 2026-05-02
     * @author Stephane H.
     */
    function initFilesAdvancedFilterDropdowns(root) {
        var doc = root.nodeType === 9 ? root : root.ownerDocument || document;
        var modal = doc.getElementById('filesAdvancedFiltersModal');
        if (!modal) {
            return;
        }
        var i18n = doc.getElementById('files-advanced-filters-i18n');
        if (!i18n) {
            return;
        }
        var extAll = i18n.getAttribute('data-msg-ext-all') || '';
        var extCount = i18n.getAttribute('data-msg-ext-count') || '';
        var gAll = i18n.getAttribute('data-msg-grantee-all') || '';
        var gCount = i18n.getAttribute('data-msg-grantee-count') || '';

        /**
         * @brief Update toggle label text from checked checkboxes.
         * @param {HTMLElement} filterRoot Group container.
         * @return {void}
         * @date 2026-05-02
         * @author Stephane H.
         */
        function updateOne(filterRoot) {
            var kind = filterRoot.getAttribute('data-files-adv-filter') || '';
            var labelEl = filterRoot.querySelector('[data-files-adv-filter-label]');
            if (!labelEl) {
                return;
            }
            var selector = kind === 'extensions' ? 'input[type="checkbox"][name="ext[]"]' : 'input[type="checkbox"][name="grantee[]"]';
            var boxes = filterRoot.querySelectorAll(selector);
            var n = 0;
            boxes.forEach(function (cb) {
                if (cb.checked) {
                    n += 1;
                }
            });
            if (kind === 'extensions') {
                labelEl.textContent = n === 0 ? extAll : formatAdvFilterCountMsg(extCount, n);
            } else if (kind === 'grantees') {
                labelEl.textContent = n === 0 ? gAll : formatAdvFilterCountMsg(gCount, n);
            }
        }

        modal.querySelectorAll('[data-files-adv-filter]').forEach(function (filterRoot) {
            filterRoot.addEventListener('change', function (ev) {
                var t = ev.target;
                if (!t || !t.matches) {
                    return;
                }
                if (t.matches('input[type="checkbox"][name="ext[]"], input[type="checkbox"][name="grantee[]"]')) {
                    updateOne(filterRoot);
                }
            });

            filterRoot.addEventListener('click', function (ev) {
                var btn = ev.target && ev.target.closest ? ev.target.closest('[data-files-adv-filter-action]') : null;
                if (!btn || !filterRoot.contains(btn)) {
                    return;
                }
                var kind = filterRoot.getAttribute('data-files-adv-filter') || '';
                var targetKind = btn.getAttribute('data-files-adv-filter-target') || '';
                if (targetKind !== kind) {
                    return;
                }
                var action = btn.getAttribute('data-files-adv-filter-action') || '';
                var selector = kind === 'extensions' ? 'input[name="ext[]"]' : 'input[name="grantee[]"]';
                var boxes = filterRoot.querySelectorAll(selector);
                var check = action === 'all';
                boxes.forEach(function (cb) {
                    cb.checked = check;
                });
                ev.preventDefault();
                updateOne(filterRoot);
            });

            var panel = filterRoot.querySelector('.files-filter-dropdown-panel');
            if (panel) {
                panel.addEventListener('click', function (e) {
                    e.stopPropagation();
                });
            }

            updateOne(filterRoot);
        });
    }

    initFilesAdvancedFilterDropdowns(document);

    var createFolderModalEl = document.getElementById('filesCreateFolderModal');
    if (createFolderModalEl) {
        createFolderModalEl.addEventListener('shown.bs.modal', function () {
            var folderNameInput = document.getElementById('files-folder-name');
            if (folderNameInput) {
                folderNameInput.focus();
                folderNameInput.select();
            }
        });
    }

    initAdaptiveActionDropdowns(document);
    initFilesSectionAccordions(document);
    ensureColumnPrefsCanonical();
    applyColumnVisibility(document);
    syncColumnToggleCheckboxes();

    document.addEventListener('change', function (event) {
        var cb = event.target;
        if (!cb || !cb.matches || !cb.matches('[data-files-col-toggle]')) {
            return;
        }
        var col = cb.getAttribute('data-files-col-toggle') || '';
        if (col === '') {
            return;
        }
        var prefs = readColumnPrefs();
        prefs[col] = !!cb.checked;
        writeColumnPrefs(prefs);
        applyColumnVisibility(document);
    });

    /**
     * @brief Resolve selection scope token from a selection checkbox.
     * @param {Element|null} node Selection checkbox node.
     * @return {string}
     * @date 2026-04-30
     * @author Stephane H.
     */
    function getSelectScope(node) {
        if (!node || !node.getAttribute) {
            return 'owned';
        }
        var scope = (node.getAttribute('data-files-select-scope') || '').toLowerCase();

        return scope === 'shared' ? 'shared' : 'owned';
    }

    /**
     * @brief Toggle all row checkboxes for a scope; optional pane limits to one admin user pane.
     * @param {string} scope Selection scope owned|shared.
     * @param {boolean} checked Desired checked state.
     * @param {string|null} paneIdOrNull When set, only rows with matching data-files-pane-id.
     * @return {void}
     * @date 2026-05-04
     * @author Stephane H.
     */
    function applySelectAllInScope(scope, checked, paneIdOrNull) {
        var wantedScope = typeof scope === 'string' ? scope.toLowerCase() : '';
        document.querySelectorAll('[data-files-select-scope]').forEach(function (cb) {
            if (getSelectScope(cb) !== wantedScope || cb.disabled) {
                return;
            }
            if (paneIdOrNull && String(paneIdOrNull) !== '') {
                var rowPane = cb.getAttribute('data-files-pane-id') || '';
                if (rowPane !== String(paneIdOrNull)) {
                    return;
                }
            }
            cb.checked = checked;
        });
    }

    /**
     * @brief Collect selected file ids from listing checkboxes, optionally filtered by scope.
     * @param {string|null} scope Optional selection scope filter ('owned' or 'shared').
     * @param {string|null} paneId Optional pane id (admin multi-pane); when set, restrict to that pane.
     * @return {number[]}
     * @date 2026-04-30
     * @author Stephane H.
     */
    function getSelectedFileIds(scope, paneId) {
        var wantedScope = typeof scope === 'string' ? scope.toLowerCase() : '';
        var wantedPane = typeof paneId === 'string' && paneId !== '' ? paneId : '';
        var nodes = document.querySelectorAll('[data-files-select-id]:checked');
        var out = [];
        nodes.forEach(function (n) {
            var nodeScope = getSelectScope(n);
            if (wantedScope !== '' && nodeScope !== wantedScope) {
                return;
            }
            if (wantedPane !== '') {
                var np = n.getAttribute('data-files-pane-id') || '';
                if (np !== wantedPane) {
                    return;
                }
            }
            var v = Number(n.getAttribute('data-files-select-id') || '0');
            if (v > 0) {
                out.push(v);
            }
        });
        return out;
    }

    /**
     * @brief Collect selected folder ids from listing checkboxes, optionally filtered by scope.
     * @param {string|null} scope Optional selection scope filter ('owned' or 'shared').
     * @return {number[]}
     * @date 2026-04-30
     * @author Stephane H.
     */
    function getSelectedFolderIds(scope, paneId) {
        var wantedScope = typeof scope === 'string' ? scope.toLowerCase() : '';
        var wantedPane = typeof paneId === 'string' && paneId !== '' ? paneId : '';
        var nodes = document.querySelectorAll('[data-files-select-folder-id]:checked');
        var out = [];
        nodes.forEach(function (n) {
            var nodeScope = getSelectScope(n);
            if (wantedScope !== '' && nodeScope !== wantedScope) {
                return;
            }
            if (wantedPane !== '') {
                var np = n.getAttribute('data-files-pane-id') || '';
                if (np !== wantedPane) {
                    return;
                }
            }
            var v = Number(n.getAttribute('data-files-select-folder-id') || '0');
            if (v > 0) {
                out.push(v);
            }
        });
        return out;
    }

    /**
     * @brief Count checked selection checkboxes in a given scope, including
     *        folder-only scope checkboxes that do not carry a file id.
     * @param {string} scope Scope filter token ('owned' or 'shared').
     * @return {number}
     * @date 2026-04-30
     * @author Stephane H.
     */
    function getCheckedScopeCount(scope) {
        var wantedScope = typeof scope === 'string' ? scope.toLowerCase() : '';
        if (wantedScope !== 'owned' && wantedScope !== 'shared') {
            return 0;
        }
        var nodes = document.querySelectorAll('[data-files-select-scope]:checked');
        var out = 0;
        nodes.forEach(function (n) {
            var nodeScope = getSelectScope(n);
            if (nodeScope === wantedScope) {
                out += 1;
            }
        });
        return out;
    }

    /**
     * @brief Resolve focused pane id from first checked row checkbox carrying data-files-pane-id (admin multi-pane).
     * @param void No input parameter.
     * @return {string}
     * @date 2026-05-04
     * @author Stephane H.
     */
    function getActivePaneIdFromSelection() {
        var nodes = document.querySelectorAll('[data-files-select-scope]:checked');
        var pid = '';
        nodes.forEach(function (n) {
            var p = n.getAttribute('data-files-pane-id') || '';
            if (p !== '' && pid === '') {
                pid = p;
            }
        });
        return pid;
    }

    /**
     * @brief Distinct data-files-pane-id values among checked row checkboxes (admin multi-pane).
     * @param void No parameter.
     * @return {string[]}
     * @date 2026-05-04
     * @author Stephane H.
     */
    function getDistinctPaneIdsFromSelection() {
        var seen = {};
        var out = [];
        document.querySelectorAll('[data-files-select-scope]:checked').forEach(function (n) {
            var p = n.getAttribute('data-files-pane-id') || '';
            if (p !== '' && !seen[p]) {
                seen[p] = true;
                out.push(p);
            }
        });

        return out;
    }

    /**
     * @brief Subject user id for godview bulk/move/delete (matches pane data-files-subject-user-id).
     * @param void No parameter.
     * @return {string} Numeric user id or empty when not in multi-pane selection.
     * @date 2026-05-04
     * @author Stephane H.
     */
    function getSubjectUserIdFromActivePane() {
        var paneId = getActivePaneIdFromSelection();
        if (!paneId) {
            return '';
        }
        var paneEl = document.getElementById(paneId);
        if (!paneEl) {
            return '';
        }

        return String(paneEl.getAttribute('data-files-subject-user-id') || '').trim();
    }

    /**
     * @brief Resolve godview subject user id from a trigger element pane context.
     * @param {HTMLElement|null} triggerEl Action trigger element.
     * @return {string} Numeric user id or empty string.
     * @date 2026-05-08
     * @author Stephane H.
     */
    function getSubjectUserIdFromTrigger(triggerEl) {
        if (!triggerEl || !triggerEl.closest) {
            return '';
        }
        var rowEl = triggerEl.closest('[data-files-pane-row]');
        if (rowEl) {
            var paneId = String(rowEl.getAttribute('data-files-pane-row') || '').trim();
            if (paneId !== '') {
                var paneEl = document.querySelector('[data-files-pane-id="' + paneId + '"]');
                if (paneEl) {
                    var paneSubject = String(paneEl.getAttribute('data-files-subject-user-id') || '').trim();
                    if (paneSubject !== '') {
                        return paneSubject;
                    }
                }
            }
        }
        var closestPaneHost = triggerEl.closest('[data-files-subject-user-id]');
        if (closestPaneHost) {
            var hostSubject = String(closestPaneHost.getAttribute('data-files-subject-user-id') || '').trim();
            if (hostSubject !== '') {
                return hostSubject;
            }
        }
        return '';
    }

    /**
     * @brief Build unified front share context for /files and /admin/files modes.
     * @param {{mode?: string, explicitSubjectUserId?: string, triggerEl?: HTMLElement|null, scope?: string, fileId?: number, bulkIds?: number[], bulkFolderIds?: number[]}} options Context source options.
     * @return {{routeType: string, adminContext: boolean, adminViewScope: string, selectionScope: string, paneId: string|null, subjectUserId: string, mode: string, entityIds: {fileIds: number[], folderIds: number[]}}}
     * @date 2026-05-05
     * @author Stephane H.
     */
    function buildFrontShareContext(options) {
        var opts = options || {};
        var listingState = readListingState();
        var adminContext = String(listingState.admin_context || '') === '1';
        var adminViewScope = typeof listingState.admin_view_scope === 'string' && listingState.admin_view_scope !== ''
            ? String(listingState.admin_view_scope)
            : '';
        var selectionState = getSelectionState();
        var paneId = selectionState.activePaneId || null;
        var routeType = 'files';
        if (adminContext && adminViewScope === 'all') {
            routeType = 'admin_all_users';
        } else if (adminContext) {
            routeType = 'admin_owner';
        }
        var sid = typeof opts.explicitSubjectUserId === 'string' ? opts.explicitSubjectUserId.trim() : '';
        if (sid === '' && opts.triggerEl) {
            sid = getSubjectUserIdFromTrigger(opts.triggerEl);
        }
        if (sid === '') {
            sid = getSubjectUserIdFromActivePane();
        }
        if (sid === '') {
            sid = typeof listingState.subject_user === 'string' ? String(listingState.subject_user).trim() : '';
        }
        if (!adminContext) {
            sid = '';
        }

        return {
            routeType: routeType,
            adminContext: adminContext,
            adminViewScope: adminViewScope,
            selectionScope: typeof opts.scope === 'string' && opts.scope !== ''
                ? opts.scope
                : (selectionState.activeScope || ''),
            paneId: paneId,
            subjectUserId: sid,
            mode: typeof opts.mode === 'string' && opts.mode !== '' ? opts.mode : 'single',
            entityIds: {
                fileIds: Array.isArray(opts.bulkIds) ? opts.bulkIds : [],
                folderIds: Array.isArray(opts.bulkFolderIds) ? opts.bulkFolderIds : []
            }
        };
    }

    /**
     * @brief Keep share form hidden fields synced from unified front share context.
     * @param {{adminContext: boolean, adminViewScope: string, subjectUserId: string}|null} context Front share context.
     * @return {void}
     * @date 2026-05-05
     * @author Stephane H.
     */
    function syncShareFormsContextInputs(context) {
        var ctx = context || buildFrontShareContext({});
        var adminVal = ctx.adminContext ? '1' : '';
        var scopeVal = ctx.adminContext ? (ctx.adminViewScope || '') : '';
        var sid = ctx.adminContext ? (ctx.subjectUserId || '') : '';
        document.querySelectorAll('input[data-files-share-admin-context-input]').forEach(function (inp) {
            inp.value = adminVal;
        });
        document.querySelectorAll('input[data-files-share-admin-view-scope-input]').forEach(function (inp) {
            inp.value = scopeVal;
        });
        document.querySelectorAll('input[data-files-share-subject-user-input]').forEach(function (inp) {
            inp.value = sid;
        });
    }

    /**
     * @brief Write godview subject_user hidden inputs on bulk forms from active pane selection.
     * @param void No parameter.
     * @return {void}
     * @date 2026-05-04
     * @author Stephane H.
     */
    function syncBulkFormsSubjectUserHiddenInputs() {
        var sid = getSubjectUserIdFromActivePane();
        var delInp = document.getElementById('files-delete-bulk-subject-user');
        var moveInp = document.getElementById('files-move-bulk-subject-user');
        if (delInp) {
            delInp.value = sid;
        }
        if (moveInp) {
            moveInp.value = sid;
        }
    }

    /**
     * @brief Build aggregate selection state across owned/shared scopes.
     * @param void No input parameter.
     * @return {{activeScope: string, activePaneId: string, ownedCount: number, sharedCount: number, totalSelectedCount: number, totalFileCount: number}}
     * @date 2026-05-04
     * @author Stephane H.
     */
    function getSelectionState() {
        var ownedCount = getCheckedScopeCount('owned');
        var sharedCount = getCheckedScopeCount('shared');
        var ownedFileCount = getSelectedFileIds('owned').length;
        var sharedFileCount = getSelectedFileIds('shared').length;
        var activeScope = '';
        if (ownedCount > 0 && sharedCount < 1) {
            activeScope = 'owned';
        } else if (sharedCount > 0 && ownedCount < 1) {
            activeScope = 'shared';
        }
        var activePaneId = getActivePaneIdFromSelection();

        return {
            activeScope: activeScope,
            activePaneId: activePaneId,
            ownedCount: ownedCount,
            sharedCount: sharedCount,
            totalSelectedCount: ownedCount + sharedCount,
            totalFileCount: ownedFileCount + sharedFileCount
        };
    }

    /**
     * @brief Update global action toolbar label, scope lock state, and live region.
     * @return {void}
     * @date 2026-05-02
     * @author Stephane H.
     */
    function updateSelectionUi() {
        var selectionState = getSelectionState();
        var n = selectionState.totalSelectedCount;
        var fileCount = selectionState.totalFileCount;
        var labelEl = document.querySelector('[data-files-action-global-label]');
        var live = document.querySelector('[data-files-selection-live]');
        var labelTpl = labelEl ? (labelEl.getAttribute('data-msg-template') || '') : '';
        var liveTpl = live ? (live.getAttribute('data-msg-selection-count') || '') : '';
        document.querySelectorAll('[data-files-select-scope]').forEach(function (cb) {
            var cbScope = getSelectScope(cb);
            var paneId = cb.getAttribute('data-files-pane-id') || '';
            var paneLocked =
                selectionState.activePaneId !== '' &&
                paneId !== '' &&
                paneId !== selectionState.activePaneId &&
                !cb.checked;
            var locked =
                (selectionState.activeScope !== '' && cbScope !== selectionState.activeScope && !cb.checked) ||
                paneLocked;
            cb.disabled = locked;
            if (locked) {
                cb.setAttribute('aria-disabled', 'true');
                cb.classList.add('opacity-50');
            } else {
                cb.setAttribute('aria-disabled', 'false');
                cb.classList.remove('opacity-50');
            }
        });
        document.querySelectorAll('[data-files-select-all]').forEach(function (cb) {
            var allScope = (cb.getAttribute('data-files-select-all-scope') || 'owned').toLowerCase();
            var paneAll = cb.getAttribute('data-files-select-all-pane') || '';
            var lockAll = selectionState.activeScope !== '' && allScope !== selectionState.activeScope;
            var lockPane =
                selectionState.activePaneId !== '' &&
                paneAll !== '' &&
                paneAll !== selectionState.activePaneId;
            if (lockAll || lockPane) {
                cb.checked = false;
                cb.disabled = true;
                cb.setAttribute('aria-disabled', 'true');
                cb.classList.add('opacity-50');
            } else {
                cb.disabled = false;
                cb.setAttribute('aria-disabled', 'false');
                cb.classList.remove('opacity-50');
            }
        });
        document.querySelectorAll('[data-files-selection-group]').forEach(function (group) {
            var groupScope = (group.getAttribute('data-files-selection-group') || 'owned').toLowerCase();
            var groupPane = group.getAttribute('data-files-pane-scope') || '';
            var scopeLocked = selectionState.activeScope !== '' && groupScope !== selectionState.activeScope;
            var paneLocked =
                selectionState.activePaneId !== '' &&
                groupPane !== '' &&
                groupPane !== selectionState.activePaneId;
            if (scopeLocked || paneLocked) {
                group.classList.add('opacity-50');
                group.setAttribute('aria-disabled', 'true');
            } else {
                group.classList.remove('opacity-50');
                group.setAttribute('aria-disabled', 'false');
            }
        });
        document.querySelectorAll('[data-files-action-requires-selection="1"]').forEach(function (item) {
            var actionScope = (item.getAttribute('data-files-action-scope') || 'both').toLowerCase();
            var actionName = (item.getAttribute('data-files-action-global') || '').toLowerCase();
            var apSel = selectionState.activePaneId;
            var ownedFolderSelectionCount = getSelectedFolderIds('owned', apSel).length;
            var sharedFolderSelectionCount = getSelectedFolderIds('shared', apSel).length;
            var effOwnedFiles = getSelectedFileIds('owned', apSel).length;
            var effSharedFiles = getSelectedFileIds('shared', apSel).length;
            var hasDownloadSelection =
                effOwnedFiles + effSharedFiles + sharedFolderSelectionCount + ownedFolderSelectionCount > 0;
            var disable;
            if (actionName === 'download-selection') {
                disable = !hasDownloadSelection;
            } else if (actionName === 'delete' || actionName === 'move-selection') {
                disable = effOwnedFiles < 1 && ownedFolderSelectionCount < 1;
            } else {
                disable = effOwnedFiles < 1;
            }
            if (!disable && selectionState.activeScope !== '' && actionScope !== 'both' && actionScope !== selectionState.activeScope) {
                disable = true;
            }
            if (disable) {
                item.setAttribute('aria-disabled', 'true');
                item.classList.add('disabled');
            } else {
                item.setAttribute('aria-disabled', 'false');
                item.classList.remove('disabled');
            }
        });
        if (labelEl && labelTpl !== '') {
            labelEl.textContent = labelTpl.replace('%count%', String(n));
        } else if (labelEl) {
            labelEl.textContent = String(n);
        }
        if (live && liveTpl !== '') {
            live.textContent = liveTpl.replace('%count%', String(n));
        }
    }

    /**
     * @brief Remove dynamically injected bulk id fields from a form.
     * @param {HTMLFormElement} formEl Form element.
     * @param {string} containerSel Selector for the bulk id container.
     * @return {void}
     * @date 2026-04-28
     * @author Stephane H.
     */
    function clearBulkIdContainer(formEl, containerSel) {
        if (!formEl) {
            return;
        }
        var box = formEl.querySelector(containerSel);
        if (box) {
            box.innerHTML = '';
        }
    }

    /**
     * @brief Append hidden inputs for bulk post ids.
     * @param {HTMLFormElement} formEl Form element.
     * @param {string} containerSel Bulk ids container selector.
     * @param {number[]} ids Numeric ids.
     * @param {string} [inputName] Posted field name (default ids[]).
     * @return {void}
     * @date 2026-05-02
     * @author Stephane H.
     */
    function fillBulkIdContainer(formEl, containerSel, ids, inputName) {
        clearBulkIdContainer(formEl, containerSel);
        if (!formEl) {
            return;
        }
        var box = formEl.querySelector(containerSel);
        if (!box) {
            return;
        }
        var name = inputName || 'ids[]';
        ids.forEach(function (id) {
            var hi = document.createElement('input');
            hi.type = 'hidden';
            hi.name = name;
            hi.value = String(id);
            box.appendChild(hi);
        });
    }

    /**
     * @brief Extract display names for currently selected files from list/grid DOM.
     * @return {string[]}
     * @date 2026-04-28
     * @author Stephane H.
     */
    function getSelectedFileDisplayNames() {
        var nodes = document.querySelectorAll('[data-files-select-id]:checked');
        var out = [];
        nodes.forEach(function (n) {
            var row = n.closest('tr');
            if (row) {
                var listName = row.querySelector('.files-col-name');
                if (listName && listName.textContent) {
                    out.push(listName.textContent.trim());
                    return;
                }
            }
            var card = n.closest('.files-grid-card, .files-grid-card-compact');
            if (card) {
                var gridName = card.querySelector('.files-grid-card-compact__name, .card-title');
                if (gridName && gridName.textContent) {
                    out.push(gridName.textContent.trim());
                    return;
                }
            }
            var ariaLabel = n.getAttribute('aria-label') || '';
            if (ariaLabel !== '') {
                out.push(ariaLabel);
            }
        });
        return out;
    }

    /**
     * @brief Extract display names for selected folders (list table or grid cards).
     * @return {string[]}
     * @date 2026-05-02
     * @author Stephane H.
     */
    function getSelectedFolderDisplayNames() {
        var nodes = document.querySelectorAll('[data-files-select-folder-id]:checked');
        var out = [];
        nodes.forEach(function (n) {
            var row = n.closest('tr');
            if (row) {
                var listName = row.querySelector('.files-col-name');
                if (listName && listName.textContent) {
                    out.push(listName.textContent.trim());
                    return;
                }
            }
            var card = n.closest('.files-grid-card, .files-grid-card-compact');
            if (card) {
                var gridName = card.querySelector('.files-grid-card-compact__name, .card-title');
                if (gridName && gridName.textContent) {
                    out.push(gridName.textContent.trim());
                    return;
                }
            }
            var ariaLabel = n.getAttribute('aria-label') || '';
            if (ariaLabel !== '') {
                out.push(ariaLabel);
            }
        });
        return out;
    }

    /**
     * @brief Open bulk delete modal and inject selected ids plus a truncated name preview.
     * @param {number[]} ids Selected file ids.
     * @param {number[]} folderIds Selected owned folder ids.
     * @return {void}
     * @date 2026-05-02
     * @author Stephane H.
     */
    function openBulkDeleteModal(ids, folderIds) {
        var modalEl = document.getElementById('filesDeleteBulkModal');
        var formEl = document.getElementById('files-delete-bulk-form');
        var fileIds = Array.isArray(ids) ? ids : [];
        var fIds = Array.isArray(folderIds) ? folderIds : [];
        if (!modalEl || !formEl || (fileIds.length < 1 && fIds.length < 1)) {
            return;
        }
        clearBulkIdContainer(formEl, '[data-files-delete-bulk-ids]');
        clearBulkIdContainer(formEl, '[data-files-delete-bulk-folder-ids]');
        fillBulkIdContainer(formEl, '[data-files-delete-bulk-ids]', fileIds, 'ids[]');
        fillBulkIdContainer(formEl, '[data-files-delete-bulk-folder-ids]', fIds, 'folder_ids[]');
        var total = fileIds.length + fIds.length;
        var countEl = modalEl.querySelector('[data-files-delete-bulk-count]');
        if (countEl) {
            countEl.textContent = String(total);
        }
        var names = getSelectedFileDisplayNames().concat(getSelectedFolderDisplayNames());
        var previewEl = modalEl.querySelector('[data-files-delete-bulk-preview]');
        var moreEl = modalEl.querySelector('[data-files-delete-bulk-more]');
        var maxRows = 5;
        if (previewEl) {
            previewEl.innerHTML = '';
            names.slice(0, maxRows).forEach(function (name) {
                var li = document.createElement('li');
                li.className = 'list-group-item py-1 px-0 border-0 text-truncate';
                li.textContent = name;
                li.title = name;
                previewEl.appendChild(li);
            });
        }
        if (moreEl) {
            if (names.length > maxRows) {
                var remaining = names.length - maxRows;
                var tpl = modalEl.getAttribute('data-files-delete-bulk-more-template') || '';
                moreEl.textContent = tpl !== '' ? tpl.replace('%count%', String(remaining)) : '';
                moreEl.classList.remove('d-none');
            } else {
                moreEl.textContent = '';
                moreEl.classList.add('d-none');
            }
        }
        syncBulkFormsSubjectUserHiddenInputs();
        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    }

    var moveBrowseTrail = [{ id: 0, name: '' }];

    /**
     * @brief Open bulk move modal, reset drill-down to root, fill ids and load child folders.
     * @param {number[]} ids Selected file ids.
     * @param {number[]} folderIds Selected owned folder ids.
     * @return {void}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function openMoveBulkModal(ids, folderIds) {
        var modalEl = document.getElementById('filesMoveBulkModal');
        var formEl = document.getElementById('files-move-bulk-form');
        var fileIds = Array.isArray(ids) ? ids : [];
        var fIds = Array.isArray(folderIds) ? folderIds : [];
        if (!modalEl || !formEl || (fileIds.length < 1 && fIds.length < 1)) {
            return;
        }
        clearBulkIdContainer(formEl, '[data-files-move-bulk-ids]');
        clearBulkIdContainer(formEl, '[data-files-move-bulk-folder-ids]');
        fillBulkIdContainer(formEl, '[data-files-move-bulk-ids]', fileIds, 'ids[]');
        fillBulkIdContainer(formEl, '[data-files-move-bulk-folder-ids]', fIds, 'folder_ids[]');
        var rootLabel = modalEl.getAttribute('data-files-move-breadcrumb-root-label') || '';
        moveBrowseTrail = [{ id: 0, name: rootLabel }];
        var targetInput = document.getElementById('files-move-bulk-target-folder-id');
        if (targetInput) {
            targetInput.value = '0';
        }
        renderMoveBreadcrumbAndList(modalEl);
        syncBulkFormsSubjectUserHiddenInputs();
        loadMoveFolderChildren(modalEl);
        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    }

    /**
     * @brief Update breadcrumb trail UI and parent-up button state.
     * @param {HTMLElement} modalEl Move modal root.
     * @return {void}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function renderMoveBreadcrumbAndList(modalEl) {
        var bc = modalEl.querySelector('[data-files-move-breadcrumb]');
        if (bc) {
            bc.innerHTML = '';
            moveBrowseTrail.forEach(function (seg, idx) {
                var li = document.createElement('li');
                li.className = 'breadcrumb-item' + (idx === moveBrowseTrail.length - 1 ? ' active' : '');
                if (idx === moveBrowseTrail.length - 1) {
                    li.setAttribute('aria-current', 'page');
                }
                li.textContent = seg.name || '';
                bc.appendChild(li);
            });
        }
        var upBtn = modalEl.querySelector('[data-files-move-parent-up]');
        if (upBtn) {
            upBtn.disabled = moveBrowseTrail.length <= 1;
        }
    }

    /**
     * @brief Fetch child folders for current trail tip and render pick list.
     * @param {HTMLElement} modalEl Move modal root.
     * @return {void}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function loadMoveFolderChildren(modalEl) {
        var listEl = modalEl.querySelector('[data-files-move-folder-list]');
        var emptyHint = modalEl.querySelector('[data-files-move-empty-hint]');
        var urlBase = modalEl.getAttribute('data-files-folder-children-url') || '';
        var tip = moveBrowseTrail[moveBrowseTrail.length - 1];
        var parentId = tip ? tip.id : 0;
        var subj = '';
        var moveForm = document.getElementById('files-move-bulk-form');
        var subjInp = moveForm ? moveForm.querySelector('#files-move-bulk-subject-user') : null;
        if (subjInp && subjInp.value) {
            subj = String(subjInp.value).trim();
        } else {
            subj = getSubjectUserIdFromActivePane();
        }
        var subjQ = subj !== '' ? '&subject_user=' + encodeURIComponent(subj) : '';
        if (listEl) {
            listEl.innerHTML = '';
        }
        fetch(urlBase + (urlBase.indexOf('?') >= 0 ? '&' : '?') + 'parent=' + String(parentId) + subjQ, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (res) {
                return res.json();
            })
            .then(function (j) {
                var folders = j && j.folders ? j.folders : [];
                if (emptyHint) {
                    if (folders.length < 1) {
                        emptyHint.classList.remove('d-none');
                    } else {
                        emptyHint.classList.add('d-none');
                    }
                }
                if (!listEl) {
                    return;
                }
                folders.forEach(function (row) {
                    var li = document.createElement('li');
                    li.className = 'list-group-item d-flex justify-content-between align-items-center gap-2 py-2';
                    var span = document.createElement('span');
                    span.className = 'text-truncate flex-grow-1';
                    span.textContent = row.name;
                    span.title = row.name;
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn btn-sm btn-outline-secondary flex-shrink-0';
                    btn.setAttribute('data-files-move-enter-id', String(row.id));
                    btn.setAttribute('data-files-move-enter-name', row.name);
                    btn.setAttribute('aria-label', (modalEl.getAttribute('data-files-open-child-aria') || '') + ' ' + row.name);
                    btn.innerHTML = '<span aria-hidden="true">&rarr;</span>';
                    li.appendChild(span);
                    li.appendChild(btn);
                    listEl.appendChild(li);
                });
            })
            .catch(function () {
                if (emptyHint) {
                    emptyHint.classList.remove('d-none');
                }
            });
    }

    /**
     * @brief Submit bulk move form via fetch JSON then refresh listing.
     * @param {HTMLFormElement} formEl Move bulk form element.
     * @return {void}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function submitMoveBulkFormAsJson(formEl) {
        if (!formEl) {
            return;
        }
        var submitBtn = formEl.querySelector('[data-files-move-bulk-submit]');
        if (submitBtn && submitBtn.disabled) {
            return;
        }
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.setAttribute('aria-disabled', 'true');
        }
        var fd = new FormData(formEl);
        var params = new URLSearchParams();
        fd.forEach(function (val, key) {
            params.append(key, val);
        });
        fetch(formEl.action || window.location.href, {
            method: 'POST',
            body: params,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
        })
            .then(function (res) {
                return res.json().then(function (j) {
                    return { ok: res.ok, json: j };
                });
            })
            .then(function (pack) {
                if (pack.json && pack.json.status === 'ok') {
                    var okFiles = Array.isArray(pack.json.ok) ? pack.json.ok.length : 0;
                    var okFolders = Array.isArray(pack.json.ok_folders) ? pack.json.ok_folders.length : 0;
                    var okCount = okFiles + okFolders;
                    var failedCount = (Array.isArray(pack.json.failed) ? pack.json.failed.length : 0)
                        + (Array.isArray(pack.json.failed_folders) ? pack.json.failed_folders.length : 0);
                    notifyMoveBulkResult(okCount, failedCount, false);
                    var modalEl = document.getElementById('filesMoveBulkModal');
                    if (modalEl && window.bootstrap && window.bootstrap.Modal) {
                        window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                    }
                    if (searchInput) {
                        runPartialFetch(searchInput.value.trim());
                    }
                    return;
                }
                notifyMoveBulkResult(0, 0, true);
            })
            .catch(function () {
                notifyMoveBulkResult(0, 0, true);
            })
            .finally(function () {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.setAttribute('aria-disabled', 'false');
                }
            });
    }

    /**
     * @brief Toast feedback for bulk move (templates mirror delete_bulk placeholders).
     * @param {number} okCount Successful items.
     * @param {number} failedCount Failed items.
     * @param {boolean} hasRequestError Network or fatal JSON error.
     * @return {void}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function notifyMoveBulkResult(okCount, failedCount, hasRequestError) {
        var liveRegionNode = document.getElementById('files-live-region');
        if (!liveRegionNode) {
            return;
        }
        var successTpl = liveRegionNode.getAttribute('data-files-move-toast-success-template') || '';
        var partialTpl = liveRegionNode.getAttribute('data-files-move-toast-partial-template') || '';
        var errorMsg = liveRegionNode.getAttribute('data-files-move-toast-error') || '';
        if (hasRequestError) {
            pushFilesToast(errorMsg, 'danger');
            return;
        }
        if (okCount > 0 && failedCount > 0) {
            pushFilesToast(
                partialTpl
                    .replace('%ok%', String(okCount))
                    .replace('%failed%', String(failedCount)),
                'warning'
            );
            return;
        }
        if (okCount > 0) {
            pushFilesToast(successTpl.replace('%count%', String(okCount)), 'success');
            return;
        }
        if (failedCount > 0) {
            pushFilesToast(
                partialTpl
                    .replace('%ok%', String(okCount))
                    .replace('%failed%', String(failedCount)),
                'warning'
            );
            return;
        }
        pushFilesToast(errorMsg, 'danger');
    }

    /**
     * @brief Submit bulk delete modal form as JSON then refresh listing.
     * @param {HTMLFormElement} formEl Bulk delete form element.
     * @return {void}
     * @date 2026-05-06
     * @author Stephane H.
     */
    function submitBulkDeleteFormAsJson(formEl) {
        if (!formEl) {
            return;
        }
        var submitBtn = formEl.querySelector('[data-files-delete-bulk-submit]');
        if (submitBtn && submitBtn.disabled) {
            return;
        }
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.setAttribute('aria-disabled', 'true');
        }
        var fd = new FormData(formEl);
        var params = new URLSearchParams();
        fd.forEach(function (val, key) {
            params.append(key, val);
        });
        fetch(formEl.action || window.location.href, {
            method: 'POST',
            body: params,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
        })
            .then(function (res) {
                return res.json().then(function (j) {
                    return { ok: res.ok, json: j };
                });
            })
            .then(function (pack) {
                if (pack.json && pack.json.status === 'ok') {
                    var okFiles = Array.isArray(pack.json.ok) ? pack.json.ok.length : 0;
                    var okFolders = Array.isArray(pack.json.ok_folders) ? pack.json.ok_folders.length : 0;
                    var okCount = okFiles + okFolders;
                    var failedCount = (Array.isArray(pack.json.failed) ? pack.json.failed.length : 0)
                        + (Array.isArray(pack.json.failed_folders) ? pack.json.failed_folders.length : 0);
                    notifyBulkDeleteResult(okCount, failedCount, false);
                    var modalEl = document.getElementById('filesDeleteBulkModal');
                    if (modalEl && window.bootstrap && window.bootstrap.Modal) {
                        window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                    }
                    if (searchInput) {
                        runPartialFetch(searchInput.value.trim());
                    }
                    return;
                }
                notifyBulkDeleteResult(0, 0, true);
            })
            .catch(function () {
                notifyBulkDeleteResult(0, 0, true);
            })
            .finally(function () {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.setAttribute('aria-disabled', 'false');
                }
            });
    }

    /**
     * @brief Push a runtime toast from files listing dataset and bootstrap helper.
     * @param {string} message Runtime notification message.
     * @param {string} tone Toast tone (success|warning|danger|info).
     * @return {void}
     * @date 2026-04-29
     * @author Stephane H.
     */
    function pushFilesToast(message, tone) {
        if (!message || !window.AppFlashToasts || typeof window.AppFlashToasts.push !== 'function') {
            return;
        }
        var closeLabel = liveRegion.getAttribute('data-files-toast-close-label') || '';
        window.AppFlashToasts.push(message, tone, { closeLabel: closeLabel });
    }

    /**
     * @brief Build and show a localized toast for bulk delete ajax result.
     * @param {number} okCount Number of successfully deleted files.
     * @param {number} failedCount Number of failed deletions.
     * @param {boolean} hasRequestError True when request or response envelope failed.
     * @return {void}
     * @date 2026-04-29
     * @author Stephane H.
     */
    function notifyBulkDeleteResult(okCount, failedCount, hasRequestError) {
        var successTpl = liveRegion.getAttribute('data-files-delete-toast-success-template') || '';
        var partialTpl = liveRegion.getAttribute('data-files-delete-toast-partial-template') || '';
        var errorMsg = liveRegion.getAttribute('data-files-delete-toast-error') || '';
        if (hasRequestError) {
            pushFilesToast(errorMsg, 'danger');
            return;
        }
        if (okCount > 0 && failedCount > 0) {
            pushFilesToast(
                partialTpl
                    .replace('%ok%', String(okCount))
                    .replace('%failed%', String(failedCount)),
                'warning'
            );
            return;
        }
        if (okCount > 0) {
            pushFilesToast(successTpl.replace('%count%', String(okCount)), 'success');
            return;
        }
        if (failedCount > 0) {
            pushFilesToast(
                partialTpl
                    .replace('%ok%', String(okCount))
                    .replace('%failed%', String(failedCount)),
                'warning'
            );
            return;
        }
        pushFilesToast(errorMsg, 'danger');
    }

    /**
     * @brief Append share context query parameters to an URL.
     * @param {string} rawUrl Base URL.
     * @param {{adminContext?: boolean, adminViewScope?: string, subjectUserId?: string}|null} context Front share context fragment.
     * @return {string}
     * @date 2026-05-05
     * @author Stephane H.
     */
    function appendShareContextQuery(rawUrl, context) {
        var baseUrl = String(rawUrl || '');
        if (baseUrl === '') {
            return baseUrl;
        }
        var ctx = context || {};
        var params = [];
        if (ctx.adminContext === true) {
            params.push('admin_context=1');
            if (typeof ctx.adminViewScope === 'string' && ctx.adminViewScope.trim() !== '') {
                params.push('admin_view_scope=' + encodeURIComponent(ctx.adminViewScope.trim()));
            }
            if (typeof ctx.subjectUserId === 'string' && ctx.subjectUserId.trim() !== '') {
                params.push('subject_user=' + encodeURIComponent(ctx.subjectUserId.trim()));
            }
        }
        if (params.length < 1) {
            return baseUrl;
        }

        return baseUrl + (baseUrl.indexOf('?') >= 0 ? '&' : '?') + params.join('&');
    }

    /**
     * @brief Fetch /files/{id}/share/state JSON and pass to callback.
     * @param {string} templateUrl URL template with 999999 placeholder.
     * @param {number} fileId File id.
     * @param {{adminContext?: boolean, adminViewScope?: string, subjectUserId?: string}|null} shareContext Front share context fragment.
     * @param {function} onOk Success callback.
     * @param {function} onErr Error callback.
     * @return {void}
     * @date 2026-05-05
     * @author Stephane H.
     */
    function fetchShareStateJson(templateUrl, fileId, shareContext, onOk, onErr) {
        var url = appendShareContextQuery(
            templateUrl.replace('999999', String(fileId)),
            shareContext
        );
        fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('state_fail');
                }
                return res.json();
            })
            .then(function (data) {
                if (data && data.status === 'ok') {
                    onOk(data);
                } else {
                    onErr();
                }
            })
            .catch(function () {
                onErr();
            });
    }

    /**
     * @brief Convert datetime-local value (local wall time) to an ISO-8601 UTC string for PHP.
     * @param {string} raw Value from input[type=datetime-local] or empty.
     * @return {string} ISO string or empty when input empty; passthrough when already offset/Z.
     * @date 2026-05-02
     * @author Stephane H.
     */
    function encodePublicExpiresAtForServer(raw) {
        var s = String(raw).trim();
        if (s === '') {
            return '';
        }
        if (/[zZ]$/.test(s) || /[+-]\d{2}:?\d{2}$/.test(s)) {
            return s;
        }
        var d = new Date(s);
        if (isNaN(d.getTime())) {
            return s;
        }
        return d.toISOString();
    }

    /**
     * @brief Map server expires_at (ISO instant or legacy string) to datetime-local format in the browser local zone.
     * @param {string} raw ISO8601 or legacy payload fragment.
     * @return {string} yyyy-mm-ddThh:mm for datetime-local or raw on parse failure.
     * @date 2026-05-02
     * @author Stephane H.
     */
    function normalizeExpiresAtForDatetimeLocal(raw) {
        var s = String(raw).trim();
        if (s === '') {
            return '';
        }
        var d = new Date(s);
        if (isNaN(d.getTime())) {
            return s;
        }
        var pad = function (n) {
            return n < 10 ? '0' + n : String(n);
        };
        return (
            d.getFullYear() +
            '-' +
            pad(d.getMonth() + 1) +
            '-' +
            pad(d.getDate()) +
            'T' +
            pad(d.getHours()) +
            ':' +
            pad(d.getMinutes())
        );
    }

    /**
     * @brief Compare datetime-local public expiry to device clock and fill helper text (minutes until/past).
     * @return {void}
     * @date 2026-05-02
     * @author Stephane H.
     */
    function refreshPublicShareRelativeHint() {
        var modalEl = document.getElementById('filesSharePublicModal');
        var expInput = document.getElementById('files-share-public-expires-at');
        var enabledInput = document.getElementById('files-share-public-enabled');
        var hintEl = modalEl ? modalEl.querySelector('[data-files-share-public-expiry-relative]') : null;
        if (!modalEl || !expInput || !hintEl) {
            return;
        }
        var tplFuture = modalEl.dataset.msgExpiresRelativeFuture || '';
        var tplPast = modalEl.dataset.msgExpiresRelativePast || '';
        var tplSoon = modalEl.dataset.msgExpiresRelativeSoon || '';
        var checked = enabledInput ? Boolean(enabledInput.checked) : false;
        var raw = expInput.value.trim();
        if (!checked || raw === '') {
            hintEl.textContent = '';
            hintEl.classList.add('d-none');
            hintEl.classList.remove('text-warning');
            hintEl.classList.add('text-muted');
            return;
        }
        var endMs = new Date(raw).getTime();
        if (isNaN(endMs)) {
            hintEl.textContent = '';
            hintEl.classList.add('d-none');
            return;
        }
        var deltaMs = endMs - Date.now();
        hintEl.classList.remove('d-none');
        if (deltaMs > 60000) {
            var minsFuture = Math.ceil(deltaMs / 60000);
            hintEl.textContent = tplFuture.split('~M~').join(String(minsFuture));
            hintEl.classList.remove('text-warning');
            hintEl.classList.add('text-muted');
        } else if (deltaMs > 0) {
            hintEl.textContent = tplSoon;
            hintEl.classList.remove('text-muted');
            hintEl.classList.add('text-warning');
        } else {
            var minsPast = Math.max(1, Math.ceil(Math.abs(deltaMs) / 60000));
            hintEl.textContent = tplPast.split('~M~').join(String(minsPast));
            hintEl.classList.remove('text-muted');
            hintEl.classList.add('text-warning');
        }
    }

    /**
     * @brief Open public share modal in single, folder or bulk mode and load server state when single.
     * @param {string} mode "single", "folder" or "bulk".
     * @param {number} [fileId] Single file or folder id depending on mode.
     * @param {number[]} [bulkIds] Selected ids for bulk.
     * @param {string} [subjectUserId] Explicit godview subject user id.
     * @return {void}
     * @date 2026-05-05
     * @author Stephane H.
     */
    function openPublicShareModal(mode, fileId, bulkIds, subjectUserId) {
        var modalEl = document.getElementById('filesSharePublicModal');
        var formEl = document.getElementById('files-share-public-form');
        if (!modalEl || !formEl) {
            return;
        }
        var shareContext = buildFrontShareContext({
            mode: mode,
            explicitSubjectUserId: typeof subjectUserId === 'string' ? subjectUserId : '',
            bulkIds: Array.isArray(bulkIds) ? bulkIds : []
        });
        syncShareFormsContextInputs(shareContext);
        var titleEl = document.getElementById('filesSharePublicModalTitle');
        var summaryEl = modalEl.querySelector('[data-files-share-public-target-summary]');
        var modeInput = formEl.querySelector('[data-files-share-public-mode-input]');
        var enabledInput = document.getElementById('files-share-public-enabled');
        var expInput = document.getElementById('files-share-public-expires-at');
        var csrfInput = formEl.querySelector('[data-files-share-public-csrf-input]');
        var errBox = modalEl.querySelector('[data-files-share-public-error]');
        var stateTpl = modalEl.dataset.filesShareStateUrlTemplate || '';
        var folderStateTpl = modalEl.dataset.filesFolderShareStateUrlTemplate || '';
        /**
         * @param {Record<string, unknown>} pub Public payload from share/folder state JSON.
         * @return {void}
         */
        function applyPublicShareStateToForm(pub) {
            var p = pub || {};
            var active =
                typeof p.active === 'boolean'
                    ? p.active
                    : Boolean(p.enabled) && !Boolean(p.expired);
            if (enabledInput) {
                enabledInput.checked = active;
            }
            if (expInput) {
                expInput.value =
                    active && p.expires_at ? normalizeExpiresAtForDatetimeLocal(String(p.expires_at)) : '';
            }
            var pwdToggle = document.getElementById('files-share-public-password-enabled');
            var pwdDisp = document.getElementById('files-share-public-password-display');
            var pwdCopyBtn = document.querySelector('[data-files-share-public-password-copy]');
            if (pwdToggle) {
                pwdToggle.checked = Boolean(p.password_enabled);
            }
            if (pwdDisp) {
                if (typeof p.password_plain === 'string' && p.password_plain !== '') {
                    pwdDisp.value = p.password_plain;
                } else if (!p.password_enabled) {
                    pwdDisp.value = '';
                }
            }
            if (pwdCopyBtn) {
                var passwordCopyAvailable = typeof p.password_copy_available === 'boolean'
                    ? p.password_copy_available
                    : (typeof p.password_plain === 'string' && p.password_plain !== '');
                pwdCopyBtn.disabled = !passwordCopyAvailable;
                pwdCopyBtn.setAttribute('aria-disabled', passwordCopyAvailable ? 'false' : 'true');
            }
            refreshPublicShareRelativeHint();
        }
        if (errBox) {
            errBox.classList.add('d-none');
            errBox.textContent = '';
        }
        var pwdBlock = document.getElementById('files-share-public-password-block');
        if (pwdBlock) {
            if (mode === 'bulk') {
                pwdBlock.classList.add('d-none');
            } else {
                pwdBlock.classList.remove('d-none');
            }
        }
        if (modeInput) {
            modeInput.value = mode;
        }
        clearBulkIdContainer(formEl, '[data-files-share-public-bulk-ids]');
        if ((mode === 'single' || mode === 'folder') && fileId) {
            var isFolderMode = mode === 'folder';
            var singleTpl = modalEl.dataset.filesSharePublicSingleUrlTemplate || '';
            if (isFolderMode) {
                singleTpl = modalEl.dataset.filesSharePublicFolderUrlTemplate || singleTpl;
            }
            if (singleTpl !== '') {
                formEl.action = singleTpl.replace('999999', String(fileId));
            }
            if (csrfInput) {
                csrfInput.value = isFolderMode
                    ? (modalEl.dataset.filesSharePublicFolderCsrf || modalEl.dataset.filesSharePublicCsrf || '')
                    : (modalEl.dataset.filesSharePublicCsrf || '');
            }
            if (titleEl) {
                titleEl.textContent = isFolderMode
                    ? (modalEl.dataset.msgTitleFolder || modalEl.dataset.msgTitleSingle || titleEl.textContent)
                    : (modalEl.dataset.msgTitleSingle || titleEl.textContent);
            }
            if (summaryEl) {
                summaryEl.textContent = isFolderMode
                    ? ((modalEl.dataset.msgTitleFolder || modalEl.dataset.msgTitleSingle || '') + ' #' + String(fileId))
                    : ((modalEl.dataset.msgTitleSingle || '') + ' #' + String(fileId));
            }
            if (isFolderMode) {
                formEl.setAttribute('data-files-share-public-current-folder-id', String(fileId));
                formEl.removeAttribute('data-files-share-public-current-id');
                if (folderStateTpl !== '') {
                    fetchShareStateJson(folderStateTpl, fileId, shareContext, function (data) {
                        applyPublicShareStateToForm(data.public || {});
                    }, function () {
                        if (errBox) {
                            errBox.textContent = modalEl.dataset.msgLoadError || '';
                            errBox.classList.remove('d-none');
                        }
                    });
                }
            } else {
                formEl.removeAttribute('data-files-share-public-current-folder-id');
                formEl.setAttribute('data-files-share-public-current-id', String(fileId));
                fetchShareStateJson(stateTpl, fileId, shareContext, function (data) {
                    applyPublicShareStateToForm(data.public || {});
                }, function () {
                    if (errBox) {
                        errBox.textContent = modalEl.dataset.msgLoadError || '';
                        errBox.classList.remove('d-none');
                    }
                });
            }
        } else if (mode === 'bulk' && bulkIds && bulkIds.length) {
            formEl.removeAttribute('data-files-share-public-current-folder-id');
            formEl.removeAttribute('data-files-share-public-current-id');
            var pubBulk = modalEl.dataset.filesSharePublicBulkUrl || '';
            if (pubBulk !== '') {
                formEl.action = pubBulk;
            }
            if (csrfInput) {
                csrfInput.value = modalEl.dataset.filesSharePublicBulkCsrf || csrfInput.value;
            }
            if (titleEl) {
                titleEl.textContent = modalEl.dataset.msgTitleBulk || titleEl.textContent;
            }
            if (summaryEl) {
                summaryEl.textContent = (modalEl.dataset.msgTitleBulk || '') + ' (' + String(bulkIds.length) + ')';
            }
            fillBulkIdContainer(formEl, '[data-files-share-public-bulk-ids]', bulkIds);
            if (enabledInput) {
                enabledInput.checked = false;
            }
            if (expInput) {
                expInput.value = '';
            }
            refreshPublicShareRelativeHint();
        }
        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    }

    /**
     * @brief Open friends share modal; load grant state for single mode.
     * @param {string} mode "single", "folder" or "bulk".
     * @param {number} [fileId] File or folder id depending on mode.
     * @param {number[]} [bulkIds] Bulk ids.
     * @param {string} [subjectUserId] Explicit godview subject user id.
     * @return {void}
     * @date 2026-05-08
     * @author Stephane H.
     */
    function openFriendsShareModal(mode, fileId, bulkIds, subjectUserId) {
        var modalEl = document.getElementById('filesShareFriendsModal');
        var formEl = document.getElementById('files-share-friends-form');
        if (!modalEl || !formEl) {
            return;
        }
        var explicitSubject = typeof subjectUserId === 'string' ? subjectUserId.trim() : '';
        if (explicitSubject === '') {
            var activeSubject = getSubjectUserIdFromActivePane();
            if (activeSubject !== '') {
                explicitSubject = activeSubject;
            }
        }
        var shareContext = buildFrontShareContext({
            mode: mode,
            explicitSubjectUserId: explicitSubject,
            bulkIds: Array.isArray(bulkIds) ? bulkIds : []
        });
        syncShareFormsContextInputs(shareContext);
        var titleEl = document.getElementById('filesShareFriendsModalTitle');
        var summaryEl = modalEl.querySelector('[data-files-share-friends-target-summary]');
        var modeInput = formEl.querySelector('[data-files-share-friends-mode-input]');
        var hiddenIds = document.getElementById('files-share-friends-grantee-ids');
        var csrfInput = formEl.querySelector('[data-files-share-friends-csrf-input]');
        var errBox = modalEl.querySelector('[data-files-share-friends-error]');
        var bulkOnly = modalEl.querySelector('[data-files-share-friends-bulk-only]');
        var stateTpl = modalEl.dataset.filesShareStateUrlTemplate || '';
        var folderStateTpl = modalEl.dataset.filesFolderShareStateUrlTemplate || '';
        var setGranteeSelection = typeof modalEl.__filesSetGranteeSelection === 'function'
            ? modalEl.__filesSetGranteeSelection
            : null;
        if (errBox) {
            errBox.classList.add('d-none');
            errBox.textContent = '';
        }
        if (modeInput) {
            modeInput.value = mode;
        }
        clearBulkIdContainer(formEl, '[data-files-share-friends-bulk-ids]');
        if (hiddenIds) {
            hiddenIds.value = '';
        }
        var chips = document.getElementById('files-share-friends-grantee-chips');
        if (chips) {
            chips.innerHTML = '';
        }
        if (setGranteeSelection) {
            setGranteeSelection([]);
        }
        if ((mode === 'single' || mode === 'folder') && fileId) {
            var isFolderMode = mode === 'folder';
            var frSingleTpl = modalEl.dataset.filesShareFriendsSingleUrlTemplate || '';
            if (isFolderMode) {
                frSingleTpl = modalEl.dataset.filesShareFriendsFolderUrlTemplate || frSingleTpl;
            }
            if (frSingleTpl !== '') {
                formEl.action = frSingleTpl.replace('999999', String(fileId));
            }
            if (csrfInput) {
                csrfInput.value = isFolderMode
                    ? (modalEl.dataset.filesShareFriendsFolderCsrf || modalEl.dataset.filesShareFriendsCsrf || '')
                    : (modalEl.dataset.filesShareFriendsCsrf || '');
            }
            if (titleEl) {
                titleEl.textContent = isFolderMode
                    ? (modalEl.dataset.msgTitleFolder || modalEl.dataset.msgTitleSingle || titleEl.textContent)
                    : (modalEl.dataset.msgTitleSingle || titleEl.textContent);
            }
            if (summaryEl) {
                summaryEl.textContent = isFolderMode
                    ? ((modalEl.dataset.msgTitleFolder || modalEl.dataset.msgTitleSingle || '') + ' #' + String(fileId))
                    : ((modalEl.dataset.msgTitleSingle || '') + ' #' + String(fileId));
            }
            if (bulkOnly) {
                bulkOnly.classList.add('d-none');
            }
            if (isFolderMode) {
                formEl.setAttribute('data-files-share-friends-current-folder-id', String(fileId));
                formEl.removeAttribute('data-files-share-friends-current-id');
                if (folderStateTpl !== '') {
                    var folderStateUrl = appendShareContextQuery(
                        folderStateTpl.replace('999999', String(fileId)),
                        shareContext
                    );
                    fetch(folderStateUrl, {
                        method: 'GET',
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    }).then(function (res) {
                        if (!res.ok) {
                            throw new Error('folder_share_state_fetch_failed');
                        }
                        return res.json();
                    }).then(function (data) {
                        var rows = [];
                        var friends = data && data.friends ? data.friends : [];
                        friends.forEach(function (row) {
                            if (!row || !row.user_id) {
                                return;
                            }
                            rows.push({
                                id: Number(row.user_id),
                                label: row.label ? String(row.label) : String(row.user_id),
                                expiresAt: row.expires_at ? String(row.expires_at) : '',
                                expirationMixed: row.expiration_mixed === true,
                                expired: row.expired === true
                            });
                        });
                        if (setGranteeSelection) {
                            setGranteeSelection(rows);
                        } else if (hiddenIds) {
                            hiddenIds.value = rows.map(function (r) { return String(r.id); }).join(',');
                        }
                    }).catch(function () {
                        if (errBox) {
                            errBox.textContent = modalEl.dataset.msgLoadError || '';
                            errBox.classList.remove('d-none');
                        }
                    });
                }
            } else {
                formEl.removeAttribute('data-files-share-friends-current-folder-id');
                formEl.setAttribute('data-files-share-friends-current-id', String(fileId));
                fetchShareStateJson(
                    stateTpl,
                    fileId,
                    shareContext,
                    function (data) {
                        var friends = data.friends || [];
                        var parts = [];
                        friends.forEach(function (row) {
                            if (row.user_id) {
                                parts.push(String(row.user_id));
                            }
                        });
                        if (setGranteeSelection) {
                            var mappedRows = friends.map(function (row) {
                                return {
                                    id: Number(row.user_id || 0),
                                    label: row.label ? String(row.label) : String(row.user_id || ''),
                                    expiresAt: row.expires_at ? String(row.expires_at) : '',
                                    expirationMixed: false,
                                    expired: row.expired === true
                                };
                            });
                            setGranteeSelection(mappedRows);
                        } else if (hiddenIds) {
                            hiddenIds.value = parts.join(',');
                        }
                    },
                    function () {
                        if (errBox) {
                            errBox.textContent = modalEl.dataset.msgLoadError || '';
                            errBox.classList.remove('d-none');
                        }
                    }
                );
            }
        } else if (mode === 'bulk' && bulkIds && bulkIds.length) {
            formEl.removeAttribute('data-files-share-friends-current-folder-id');
            formEl.removeAttribute('data-files-share-friends-current-id');
            var frBulk = modalEl.dataset.filesShareFriendsBulkUrl || '';
            if (frBulk !== '') {
                formEl.action = frBulk;
            }
            if (csrfInput) {
                csrfInput.value = modalEl.dataset.filesShareFriendsBulkCsrf || csrfInput.value;
            }
            if (titleEl) {
                titleEl.textContent = modalEl.dataset.msgTitleBulk || titleEl.textContent;
            }
            if (summaryEl) {
                summaryEl.textContent = (modalEl.dataset.msgTitleBulk || '') + ' (' + String(bulkIds.length) + ')';
            }
            if (bulkOnly) {
                bulkOnly.classList.remove('d-none');
            }
            formEl.removeAttribute('data-files-share-friends-current-id');
            fillBulkIdContainer(formEl, '[data-files-share-friends-bulk-ids]', bulkIds);
        }
        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    }

    /**
     * @brief Toggle the public password gate immediately for single file/folder modal mode.
     * @param {HTMLFormElement} formEl Public share form.
     * @param {HTMLInputElement} pwdToggle Checkbox input for password gate.
     * @return {void}
     * @date 2026-05-06
     * @author Stephane H.
     */
    function submitPublicPasswordToggle(formEl, pwdToggle) {
        if (!formEl || !pwdToggle) {
            return;
        }
        if (formEl.getAttribute('data-files-password-toggle-busy') === '1') {
            return;
        }
        var modalEl = document.getElementById('filesSharePublicModal');
        if (!modalEl) {
            return;
        }
        var modeInput = formEl.querySelector('[data-files-share-public-mode-input]');
        var mode = modeInput ? String(modeInput.value || 'single') : 'single';
        if (mode !== 'single' && mode !== 'folder') {
            return;
        }
        var targetId = mode === 'folder'
            ? (formEl.getAttribute('data-files-share-public-current-folder-id') || '')
            : (formEl.getAttribute('data-files-share-public-current-id') || '');
        if (targetId === '') {
            return;
        }
        var toggleTpl = mode === 'folder'
            ? (modalEl.dataset.filesSharePublicPasswordToggleFolderUrlTemplate || '')
            : (modalEl.dataset.filesSharePublicPasswordToggleSingleUrlTemplate || '');
        if (toggleTpl === '') {
            return;
        }
        var endpoint = toggleTpl.replace('999999', targetId);
        var previousState = !pwdToggle.checked;
        var desiredState = pwdToggle.checked;
        formEl.setAttribute('data-files-password-toggle-busy', '1');
        pwdToggle.disabled = true;

        var err = modalEl.querySelector('[data-files-share-public-error]');
        if (err) {
            err.classList.add('d-none');
            err.textContent = '';
        }

        syncShareFormsContextInputs(buildFrontShareContext({}));
        var params = new URLSearchParams();
        var csrfInput = formEl.querySelector('[data-files-share-public-csrf-input]');
        params.set('_csrf_token', csrfInput ? String(csrfInput.value || '') : '');
        params.set('public_password_enabled', desiredState ? '1' : '0');

        var adminContextInput = formEl.querySelector('[data-files-share-admin-context-input]');
        var adminScopeInput = formEl.querySelector('[data-files-share-admin-view-scope-input]');
        var subjectInput = formEl.querySelector('[data-files-share-subject-user-input]');
        params.set('admin_context', adminContextInput ? String(adminContextInput.value || '') : '');
        params.set('admin_view_scope', adminScopeInput ? String(adminScopeInput.value || '') : '');
        params.set('subject_user', subjectInput ? String(subjectInput.value || '') : '');

        fetch(endpoint, {
            method: 'POST',
            body: params,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
        })
            .then(function (res) {
                return res.json().then(function (json) {
                    return { ok: res.ok, json: json };
                });
            })
            .then(function (pack) {
                var json = pack && pack.json ? pack.json : {};
                if (!pack.ok || json.status !== 'ok') {
                    pwdToggle.checked = previousState;
                    if (err) {
                        err.textContent = String((json && json.message) || modalEl.dataset.msgSubmitError || '');
                        err.classList.remove('d-none');
                    }
                    return;
                }
                var isEnabled = Boolean(json.password_enabled);
                pwdToggle.checked = isEnabled;
                var pwdDisp = document.getElementById('files-share-public-password-display');
                if (pwdDisp) {
                    if (isEnabled && typeof json.public_password_plain === 'string' && json.public_password_plain !== '') {
                        pwdDisp.value = json.public_password_plain;
                    } else if (!isEnabled) {
                        pwdDisp.value = '';
                    }
                }
            })
            .catch(function () {
                pwdToggle.checked = previousState;
                if (err) {
                    err.textContent = modalEl.dataset.msgSubmitError || '';
                    err.classList.remove('d-none');
                }
            })
            .finally(function () {
                pwdToggle.disabled = false;
                formEl.setAttribute('data-files-password-toggle-busy', '0');
            });
    }

    /**
     * @brief POST a form as JSON (XHR) and refresh listing on success; hide modal when provided.
     * @param {HTMLFormElement} formEl Form to submit.
     * @param {string} [modalIdToHide] Optional modal element id to hide on success.
     * @return {void}
     * @date 2026-05-08
     * @author Stephane H.
     */
    function submitShareFormAsJson(formEl, modalIdToHide) {
        if (!formEl) {
            return;
        }
        var adminContextInput = formEl.querySelector('[data-files-share-admin-context-input]');
        var adminScopeInput = formEl.querySelector('[data-files-share-admin-view-scope-input]');
        var subjectInput = formEl.querySelector('[data-files-share-subject-user-input]');
        var frozenAdminContext = adminContextInput ? String(adminContextInput.value || '') : '';
        var frozenAdminScope = adminScopeInput ? String(adminScopeInput.value || '') : '';
        var frozenSubjectUserId = subjectInput ? String(subjectInput.value || '').trim() : '';
        var preserveAllUsersFriendsSubject = formEl.id === 'files-share-friends-form'
            && frozenAdminContext === '1'
            && frozenAdminScope === 'all'
            && frozenSubjectUserId !== '';
        syncShareFormsContextInputs(buildFrontShareContext({}));
        if (preserveAllUsersFriendsSubject) {
            if (adminContextInput) {
                adminContextInput.value = frozenAdminContext;
            }
            if (adminScopeInput) {
                adminScopeInput.value = frozenAdminScope;
            }
            if (subjectInput) {
                subjectInput.value = frozenSubjectUserId;
            }
        }
        var fd = new FormData(formEl);
        var params = new URLSearchParams();
        fd.forEach(function (val, key) {
            if (key === 'is_public') {
                return;
            }
            if (key === 'public_expires_at') {
                var rawExp = typeof val === 'string' ? val : String(val);
                if (rawExp.trim() === '') {
                    params.append(key, '');
                } else {
                    params.append(key, encodePublicExpiresAtForServer(rawExp));
                }
                return;
            }
            params.append(key, val);
        });
        if (formEl.id === 'files-share-friends-form') {
            var chipsRoot = document.getElementById('files-share-friends-grantee-chips');
            var friendEntries = [];
            if (chipsRoot) {
                chipsRoot.querySelectorAll('[data-grantee-id]').forEach(function (node) {
                    var userId = Number(node.getAttribute('data-grantee-id') || '0');
                    if (userId < 1) {
                        return;
                    }
                    var expInput = node.querySelector('[data-files-grant-expires-at]');
                    friendEntries.push({
                        user_id: userId,
                        expires_at: expInput && expInput.value ? String(expInput.value) : ''
                    });
                });
            }
            friendEntries.forEach(function (row, idx) {
                params.append('grantees[' + String(idx) + '][user_id]', String(row.user_id));
                if (row.expires_at !== '') {
                    params.append('grantees[' + String(idx) + '][expires_at]', row.expires_at);
                }
            });
            // Single/folder modals load the full chip list; submission must replace the stored grant set so removals persist (merge mode never deletes omitted grantees).
            var friendsModeInput = formEl.querySelector('[data-files-share-friends-mode-input]');
            var friendsMode = friendsModeInput ? friendsModeInput.value : 'single';
            if (friendsMode !== 'bulk') {
                params.set('replace_existing', '1');
            }
        }
        var pubEn = formEl.querySelector('#files-share-public-enabled');
        if (pubEn && pubEn.type === 'checkbox') {
            params.set('is_public', pubEn.checked ? '1' : '0');
        }
        var pubPwd = formEl.querySelector('#files-share-public-password-enabled');
        if (pubPwd && pubPwd.type === 'checkbox') {
            params.set('public_password_enabled', pubPwd.checked ? '1' : '0');
        }
        var action = formEl.action || window.location.href;
        fetch(action, {
            method: 'POST',
            body: params,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
        })
            .then(function (res) {
                return parseFetchJsonResponse(res);
            })
            .then(function (pack) {
                var modalRoot = formEl.closest('.modal');
                if (pack.json && pack.json.status === 'ok') {
                    var plainPwd =
                        pack.json && typeof pack.json.public_password_plain === 'string'
                            ? pack.json.public_password_plain
                            : '';
                    var pwdDisp = document.getElementById('files-share-public-password-display');
                    var pwdToggle = document.getElementById('files-share-public-password-enabled');
                    var pwdCopyBtn = document.querySelector('[data-files-share-public-password-copy]');
                    if (plainPwd !== '' && pwdDisp) {
                        pwdDisp.value = plainPwd;
                    }
                    if (pwdToggle && plainPwd !== '') {
                        pwdToggle.checked = true;
                    }
                    if (modalIdToHide && window.bootstrap && window.bootstrap.Modal) {
                        var m = document.getElementById(modalIdToHide);
                        if (m) {
                            window.bootstrap.Modal.getOrCreateInstance(m).hide();
                        }
                    }
                    if (searchInput) {
                        runPartialFetch(searchInput.value.trim());
                    }
                    return;
                }
                if (modalRoot) {
                    var err = modalRoot.querySelector('[data-files-share-public-error], [data-files-share-friends-error]');
                    if (err) {
                        var msg = (pack.json && pack.json.message) || (modalRoot.dataset && modalRoot.dataset.msgSubmitError) || '';
                        err.textContent = typeof msg === 'string' ? msg : '';
                        err.classList.remove('d-none');
                    }
                }
            })
            .catch(function () {
                var modalRoot = formEl.closest('.modal');
                if (modalRoot) {
                    var err = modalRoot.querySelector('[data-files-share-public-error], [data-files-share-friends-error]');
                    if (err) {
                        err.textContent = (modalRoot.dataset && modalRoot.dataset.msgSubmitError) || '';
                        err.classList.remove('d-none');
                    }
                }
            });
    }

    document.addEventListener('change', function (e) {
        var t = e.target;
        if (t && t.matches && t.matches('[data-files-select-scope], [data-files-select-all]')) {
            if (t.matches('[data-files-select-all]')) {
                var on = t.checked;
                var selectedScope = (t.getAttribute('data-files-select-all-scope') || 'owned').toLowerCase();
                var paneAttr = t.getAttribute('data-files-select-all-pane') || '';
                applySelectAllInScope(selectedScope, on, paneAttr === '' ? null : paneAttr);
            }
            updateSelectionUi();
        }
    });

    /**
     * @brief Fallback copy for origins where navigator.clipboard is unavailable (non-HTTPS).
     * @param {string} text Text to copy.
     * @return {boolean} True when execCommand reported success.
     * @date 2026-04-28
     * @author Stephane H.
     */
    function copyTextWithExecCommand(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.setAttribute('aria-hidden', 'true');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        ta.style.top = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        ta.setSelectionRange(0, text.length);
        var ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (e) {
            ok = false;
        }
        document.body.removeChild(ta);
        return ok;
    }

    /**
     * @brief Try a stronger fallback chain for password copy in environments without Clipboard API.
     * @param {HTMLInputElement|null} inputEl Visible password input element.
     * @param {string} text Text to copy.
     * @return {{ok:boolean,method:string}} Copy result and method used.
     * @date 2026-05-06
     * @author Stephane H.
     */
    function copyPasswordWithFallbackChain(inputEl, text) {
        var ok = false;
        if (inputEl && typeof inputEl.focus === 'function' && typeof inputEl.select === 'function') {
            try {
                inputEl.focus();
                inputEl.select();
                if (typeof inputEl.setSelectionRange === 'function') {
                    inputEl.setSelectionRange(0, text.length);
                }
                ok = document.execCommand('copy');
                if (ok) {
                    return { ok: true, method: 'input_execCommand' };
                }
            } catch (e) {
                ok = false;
            }
        }
        ok = copyTextWithExecCommand(text);
        return { ok: ok, method: ok ? 'textarea_execCommand' : 'none' };
    }

    /**
     * @brief Turn a public landing path or URL into a full https URL for sharing (clipboard, chat).
     * @param {string} raw Value from the server or a data attribute.
     * @return {string} Normalized URL or empty when input is empty.
     * @date 2026-05-02
     * @author Stephane H.
     */
    function normalizePublicLandingUrlForClipboard(raw) {
        var u = String(raw || '').trim();
        if (u === '') {
            return '';
        }
        if (/^https?:\/\//i.test(u)) {
            return u;
        }
        if (u.charAt(0) === '/') {
            return window.location.origin + u;
        }
        return u;
    }

    /**
     * @brief Shows a Bootstrap modal so the user can copy a URL with a fresh user activation after async work.
     * @param {string} url URL string to place in the readonly field and copy actions.
     * @param {object} labels Label strings (title, intro, copyBtn, closeBtn).
     * @param {function(string):void} announceFn Announces a short message in the live region.
     * @param {string} msgOk Message when copy succeeds.
     * @param {string} msgBad Message when copy still fails.
     * @return {void}
     * @date 2026-05-02
     * @author Stephane H.
     */
    function openCopyUrlFallbackModal(url, labels, announceFn, msgOk, msgBad) {
        url = normalizePublicLandingUrlForClipboard(url);
        var modalId = 'filesDynamicCopyUrlModal';
        var old = document.getElementById(modalId);
        if (old) {
            old.remove();
        }
        if (!window.bootstrap || !window.bootstrap.Modal) {
            if (typeof window.prompt === 'function') {
                window.prompt(labels.intro || url, url);
            } else {
                announceFn(msgBad);
            }
            return;
        }
        var root = document.createElement('div');
        root.id = modalId;
        root.className = 'modal fade';
        root.setAttribute('tabindex', '-1');
        root.setAttribute('aria-labelledby', 'filesDynamicCopyUrlModalLabel');
        var dlg = document.createElement('div');
        dlg.className = 'modal-dialog modal-dialog-centered';
        var content = document.createElement('div');
        content.className = 'modal-content';
        var header = document.createElement('div');
        header.className = 'modal-header';
        var h = document.createElement('h2');
        h.className = 'modal-title h5';
        h.id = 'filesDynamicCopyUrlModalLabel';
        h.textContent = labels.title || '';
        var closeTop = document.createElement('button');
        closeTop.type = 'button';
        closeTop.className = 'btn-close';
        closeTop.setAttribute('data-bs-dismiss', 'modal');
        closeTop.setAttribute('aria-label', labels.closeBtn || '');
        header.appendChild(h);
        header.appendChild(closeTop);
        var body = document.createElement('div');
        body.className = 'modal-body';
        var introP = document.createElement('p');
        introP.className = 'small text-body-secondary mb-2';
        introP.textContent = labels.intro || '';
        var inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'form-control';
        inp.id = 'filesDynamicCopyUrlInput';
        inp.setAttribute('readonly', 'readonly');
        inp.value = url;
        body.appendChild(introP);
        body.appendChild(inp);
        var footer = document.createElement('div');
        footer.className = 'modal-footer';
        var btnCopy = document.createElement('button');
        btnCopy.type = 'button';
        btnCopy.className = 'btn btn-primary';
        btnCopy.id = 'filesDynamicCopyUrlDoBtn';
        btnCopy.textContent = labels.copyBtn || '';
        var btnClose = document.createElement('button');
        btnClose.type = 'button';
        btnClose.className = 'btn btn-outline-secondary';
        btnClose.setAttribute('data-bs-dismiss', 'modal');
        btnClose.textContent = labels.closeBtn || '';
        footer.appendChild(btnCopy);
        footer.appendChild(btnClose);
        content.appendChild(header);
        content.appendChild(body);
        content.appendChild(footer);
        dlg.appendChild(content);
        root.appendChild(dlg);
        document.body.appendChild(root);
        var inst = window.bootstrap.Modal.getOrCreateInstance(root);
        root.addEventListener('shown.bs.modal', function onShown() {
            root.removeEventListener('shown.bs.modal', onShown);
            inp.focus();
            inp.select();
        }, { once: true });
        root.addEventListener('hidden.bs.modal', function onHidden() {
            root.removeEventListener('hidden.bs.modal', onHidden);
            root.remove();
        }, { once: true });
        btnCopy.addEventListener('click', function onCopyClick() {
            if (copyTextWithExecCommand(url)) {
                announceFn(msgOk);
                inst.hide();
                return;
            }
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                navigator.clipboard.writeText(url).then(function () {
                    announceFn(msgOk);
                    inst.hide();
                }).catch(function () {
                    announceFn(msgBad);
                });
                return;
            }
            announceFn(msgBad);
        }, { once: true });
        inst.show();
    }

    /**
     * @brief Point the shared rename modal at an owned file target (CSRF, URL, title, maxlength).
     * @param {HTMLElement} renameModal Rename modal root element.
     * @param {HTMLFormElement} renameForm Rename form element.
     * @param {HTMLInputElement} renameInput Name input element.
     * @param {number} fileId Owned shared file identifier.
     * @param {string} displayName Current display file name.
     * @return {void}
     * @date 2026-05-02
     * @author Stephane H.
     */
    function configureRenameModalForOwnedFile(renameModal, renameForm, renameInput, fileId, displayName) {
        var fileTpl = renameModal.getAttribute('data-files-rename-url-template') || '';
        var csrfFile = renameModal.getAttribute('data-files-rename-csrf-file') || '';
        var titleFile = renameModal.getAttribute('data-rename-title-file') || '';
        var csrfHidden = renameForm.querySelector('input[name="_csrf_token"]');
        if (csrfHidden && csrfFile !== '') {
            csrfHidden.value = csrfFile;
        }
        if (fileTpl !== '') {
            renameForm.action = fileTpl.replace('999999', String(fileId));
        }
        renameForm.setAttribute('data-files-rename-target-kind', 'file');
        renameForm.setAttribute('data-files-rename-current-id', String(fileId));
        renameInput.setAttribute('maxlength', '255');
        var titleEl = document.getElementById('filesRenameModalTitle');
        if (titleEl && titleFile !== '') {
            titleEl.textContent = titleFile;
        }
        renameInput.value = displayName || '';
    }

    /**
     * @brief Point the shared rename modal at an owned folder target (CSRF, URL, title, maxlength).
     * @param {HTMLElement} renameModal Rename modal root element.
     * @param {HTMLFormElement} renameForm Rename form element.
     * @param {HTMLInputElement} renameInput Name input element.
     * @param {number} folderId Owned folder identifier.
     * @param {string} folderName Current folder display name.
     * @return {void}
     * @date 2026-05-02
     * @author Stephane H.
     */
    function configureRenameModalForOwnedFolder(renameModal, renameForm, renameInput, folderId, folderName) {
        var folderTpl = renameModal.getAttribute('data-files-folder-rename-url-template') || '';
        var csrfFolder = renameModal.getAttribute('data-files-rename-csrf-folder') || '';
        var titleFolder = renameModal.getAttribute('data-rename-title-folder') || '';
        var csrfHidden = renameForm.querySelector('input[name="_csrf_token"]');
        if (csrfHidden && csrfFolder !== '') {
            csrfHidden.value = csrfFolder;
        }
        if (folderTpl !== '') {
            renameForm.action = folderTpl.replace('999999', String(folderId));
        }
        renameForm.setAttribute('data-files-rename-target-kind', 'folder');
        renameForm.removeAttribute('data-files-rename-current-id');
        renameInput.setAttribute('maxlength', '190');
        var titleEl = document.getElementById('filesRenameModalTitle');
        if (titleEl && titleFolder !== '') {
            titleEl.textContent = titleFolder;
        }
        renameInput.value = folderName || '';
    }

    /**
     * @brief Open the single-file delete confirmation modal (POST URL, CSRF, display name).
     * @param {HTMLElement} trigger Row control carrying data-files-delete-action and related attributes.
     * @return {void}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function openSingleFileDeleteConfirmModal(trigger) {
        var deleteModal = document.getElementById('filesDeleteConfirmModal');
        var deleteForm = document.getElementById('files-delete-confirm-form');
        if (!deleteModal || !deleteForm || !window.bootstrap || !window.bootstrap.Modal) {
            return;
        }
        var nameTarget = deleteModal.querySelector('[data-files-delete-target-name]');
        var csrfInput = deleteForm.querySelector('[data-files-delete-csrf-input]');
        if (!nameTarget || !csrfInput) {
            return;
        }
        var fileId = Number(trigger.getAttribute('data-files-row-id') || '0');
        if (fileId < 1) {
            return;
        }
        deleteForm.action = trigger.getAttribute('data-files-delete-action') || '#';
        csrfInput.value = trigger.getAttribute('data-files-delete-csrf') || '';
        nameTarget.textContent = trigger.getAttribute('data-files-row-name') || '';
        window.bootstrap.Modal.getOrCreateInstance(deleteModal).show();
    }

    document.addEventListener('click', function (event) {
        var anyRowAction = event.target && event.target.closest
            ? event.target.closest('[data-files-row-action]')
            : null;
        var anyFolderCopyPwdAction = event.target && event.target.closest
            ? event.target.closest('[data-files-folder-action="copy-public-link-with-password"]')
            : null;
        if (anyRowAction || anyFolderCopyPwdAction) {
            closeAllActionMenus();
        }

        var rowDeleteOpen = event.target && event.target.closest
            ? event.target.closest('[data-files-row-action="delete-open"]')
            : null;
        if (rowDeleteOpen) {
            event.preventDefault();
            openSingleFileDeleteConfirmModal(rowDeleteOpen);
            return;
        }
        var rowRename = event.target && event.target.closest
            ? event.target.closest('[data-files-row-action="rename-open"]')
            : null;
        if (rowRename) {
            event.preventDefault();
            var renameModal = document.getElementById('filesRenameModal');
            var renameForm = document.getElementById('files-rename-form');
            var renameInput = document.getElementById('files-rename-name');
            if (!renameModal || !renameForm || !renameInput || !window.bootstrap || !window.bootstrap.Modal) {
                return;
            }
            var renameId = Number(rowRename.getAttribute('data-files-row-id') || '0');
            if (renameId < 1) {
                return;
            }
            var renameName = rowRename.getAttribute('data-files-row-name') || '';
            var errorBox = renameModal.querySelector('[data-files-rename-error]');
            if (errorBox) {
                errorBox.classList.add('d-none');
                errorBox.textContent = '';
            }
            configureRenameModalForOwnedFile(renameModal, renameForm, renameInput, renameId, renameName);
            window.bootstrap.Modal.getOrCreateInstance(renameModal).show();
            window.setTimeout(function () {
                renameInput.focus();
                renameInput.select();
            }, 0);
            return;
        }
        var rowExtractOpen = event.target && event.target.closest
            ? event.target.closest('[data-files-row-action="extract-zip-open"]')
            : null;
        if (rowExtractOpen) {
            event.preventDefault();
            openExtractZipModal(rowExtractOpen);
            return;
        }
        var rowPub = event.target && event.target.closest
            ? event.target.closest('[data-files-row-action="share-public"]')
            : null;
        if (rowPub) {
            event.preventDefault();
            var fileId = Number(rowPub.getAttribute('data-files-row-id') || '0');
            var rowSubjectUserId = getSubjectUserIdFromTrigger(rowPub);
            if (fileId > 0) {
                openPublicShareModal('single', fileId, null, rowSubjectUserId);
            }
            return;
        }
        var rowFr = event.target && event.target.closest
            ? event.target.closest('[data-files-row-action="share-friends"]')
            : null;
        if (rowFr) {
            event.preventDefault();
            var fileId2 = Number(rowFr.getAttribute('data-files-row-id') || '0');
            var rowSubjectUserId2 = getSubjectUserIdFromTrigger(rowFr);
            if (fileId2 > 0) {
                openFriendsShareModal('single', fileId2, null, rowSubjectUserId2);
            }
            return;
        }
        var sharePwdCopyBtn = event.target && event.target.closest
            ? event.target.closest('[data-files-share-public-password-copy]')
            : null;
        if (sharePwdCopyBtn) {
            event.preventDefault();
            var pwdDispForCopy = document.getElementById('files-share-public-password-display');
            var pwdText = pwdDispForCopy && pwdDispForCopy.value ? String(pwdDispForCopy.value).trim() : '';
            var livePwdEl = document.getElementById('files-copy-live');
            var msgPwdOk = livePwdEl ? (livePwdEl.getAttribute('data-msg-password-copied') || '') : '';
            var msgPwdBad = livePwdEl ? (livePwdEl.getAttribute('data-msg-failed') || '') : '';
            var msgPwdUnavailable = livePwdEl ? (livePwdEl.getAttribute('data-msg-password-unavailable') || '') : '';
            /**
             * @param {string} text Announced text.
             * @param {string} toastTone Toast tone.
             * @return {void}
             */
            function announceSharePwdCopy(text, toastTone) {
                if (livePwdEl) {
                    livePwdEl.textContent = text;
                    window.setTimeout(function () {
                        livePwdEl.textContent = '';
                    }, 4000);
                }
                if (toastTone && typeof pushFilesToast === 'function') {
                    pushFilesToast(text, toastTone);
                }
            }
            if (sharePwdCopyBtn.disabled) {
                announceSharePwdCopy(msgPwdUnavailable || msgPwdBad, 'warning');
                return;
            }
            if (pwdText === '') {
                announceSharePwdCopy(msgPwdBad, 'danger');
                return;
            }
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                navigator.clipboard.writeText(pwdText).then(function () {
                    announceSharePwdCopy(msgPwdOk || msgPwdBad, 'success');
                }).catch(function () {
                    var fallbackResultAfterClipboardError = copyPasswordWithFallbackChain(pwdDispForCopy, pwdText);
                    if (fallbackResultAfterClipboardError.ok) {
                        announceSharePwdCopy(msgPwdOk || msgPwdBad, 'success');
                    } else {
                        announceSharePwdCopy(msgPwdBad, 'danger');
                    }
                });
            } else {
                var fallbackResultWithoutClipboardApi = copyPasswordWithFallbackChain(pwdDispForCopy, pwdText);
                if (fallbackResultWithoutClipboardApi.ok) {
                    announceSharePwdCopy(msgPwdOk || msgPwdBad, 'success');
                } else {
                    announceSharePwdCopy(msgPwdBad, 'danger');
                }
            }
            return;
        }
        var elForClosest = event.target && event.target.nodeType === 1 ? event.target : (event.target && event.target.parentElement);
        var copyPub = event.target && event.target.closest
            ? event.target.closest('[data-files-row-action="copy-public-link"]')
            : null;
        var copyPubResolved = copyPub || (elForClosest && elForClosest.closest
            ? elForClosest.closest('[data-files-row-action="copy-public-link"]')
            : null);
        if (copyPubResolved) {
            event.preventDefault();
            var publicUrl = normalizePublicLandingUrlForClipboard(copyPubResolved.getAttribute('data-files-public-url') || '');
            var live = document.getElementById('files-copy-live');
            var msgOk = live ? (live.getAttribute('data-msg-copied') || '') : '';
            var msgBad = live ? (live.getAttribute('data-msg-failed') || '') : '';
            // Pushes a short message to the aria-live region and a visible toast when available.
            function announceCopy(text, toastTone) {
                if (live) {
                    live.textContent = text;
                    window.setTimeout(function () {
                        live.textContent = '';
                    }, 4000);
                }
                if (toastTone && typeof pushFilesToast === 'function') {
                    pushFilesToast(text, toastTone);
                }
            }
            if (publicUrl === '') {
                announceCopy(msgBad, 'danger');
                return;
            }
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                navigator.clipboard.writeText(publicUrl).then(function () {
                    announceCopy(msgOk, 'success');
                }).catch(function () {
                    if (copyTextWithExecCommand(publicUrl)) {
                        announceCopy(msgOk, 'success');
                    } else {
                        announceCopy(msgBad, 'danger');
                    }
                });
            } else {
                if (copyTextWithExecCommand(publicUrl)) {
                    announceCopy(msgOk, 'success');
                } else {
                    announceCopy(msgBad, 'danger');
                }
            }
        }
        var copyPubPwdFile = event.target && event.target.closest
            ? event.target.closest('[data-files-row-action="copy-public-link-with-password"]')
            : null;
        var copyPubPwdFolder = event.target && event.target.closest
            ? event.target.closest('[data-files-folder-action="copy-public-link-with-password"]')
            : null;
        if (copyPubPwdFile || copyPubPwdFolder) {
            event.preventDefault();
            var livePw = document.getElementById('files-copy-live');
            var msgOkPw = livePw ? (livePw.getAttribute('data-msg-copied') || '') : '';
            var msgBadPw = livePw ? (livePw.getAttribute('data-msg-failed') || '') : '';
            var msgNoPwdPw = livePw ? (livePw.getAttribute('data-msg-copy-password-missing') || '') : '';
            /**
             * @param {string} text Announced text.
             * @param {string} toastTone Toast tone.
             * @return {void}
             */
            function announceCopyPw(text, toastTone) {
                if (livePw) {
                    livePw.textContent = text;
                    window.setTimeout(function () {
                        livePw.textContent = '';
                    }, 4000);
                }
                if (toastTone && typeof pushFilesToast === 'function') {
                    pushFilesToast(text, toastTone);
                }
            }
            /**
             * @param {string} text Clipboard text.
             * @return {Promise<boolean>}
             */
            function tryClipboardPw(text) {
                if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                    return navigator.clipboard
                        .writeText(text)
                        .then(function () {
                            return true;
                        })
                        .catch(function () {
                            return false;
                        });
                }
                return Promise.resolve(copyTextWithExecCommand(text));
            }
            if (copyPubPwdFile) {
                var cfid = Number(copyPubPwdFile.getAttribute('data-files-row-id') || '0');
                var fileSubjectUserId = getSubjectUserIdFromTrigger(copyPubPwdFile);
                var baseFileUrl = normalizePublicLandingUrlForClipboard(
                    copyPubPwdFile.getAttribute('data-files-public-url') || ''
                );
                if (cfid < 1 || baseFileUrl === '') {
                    announceCopyPw(msgBadPw, 'danger');
                    return;
                }
                fetch(appendShareContextQuery('/files/' + String(cfid) + '/properties', buildFrontShareContext({
                    explicitSubjectUserId: fileSubjectUserId
                })), {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' }
                })
                    .then(function (r) {
                        return r.json();
                    })
                    .then(function (j) {
                        var pl = j.public && j.public.password_plain ? String(j.public.password_plain) : '';
                        if (!pl) {
                            announceCopyPw(msgNoPwdPw || msgBadPw, 'danger');
                            return;
                        }
                        var sf = baseFileUrl.indexOf('?') >= 0 ? '&' : '?';
                        var fullFile = baseFileUrl + sf + 'share_password=' + encodeURIComponent(pl);
                        tryClipboardPw(fullFile).then(function (ok) {
                            announceCopyPw(ok ? msgOkPw : msgBadPw, ok ? 'success' : 'danger');
                        });
                    })
                    .catch(function () {
                        announceCopyPw(msgBadPw, 'danger');
                    });
                return;
            }
            if (copyPubPwdFolder) {
                var folderIdPw = Number(copyPubPwdFolder.getAttribute('data-files-folder-id') || '0');
                var folderSubjectUserIdPw = getSubjectUserIdFromTrigger(copyPubPwdFolder);
                var baseFolderUrl = normalizePublicLandingUrlForClipboard(
                    copyPubPwdFolder.getAttribute('data-files-public-url') || ''
                );
                if (folderIdPw < 1 || baseFolderUrl === '') {
                    announceCopyPw(msgBadPw, 'danger');
                    return;
                }
                fetch(
                    appendShareContextQuery('/files/folders/' + String(folderIdPw) + '/share/state', buildFrontShareContext({
                        explicitSubjectUserId: folderSubjectUserIdPw
                    })),
                    {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' }
                    }
                )
                    .then(function (r) {
                        return r.json();
                    })
                    .then(function (j) {
                        var plf = j.public && j.public.password_plain ? String(j.public.password_plain) : '';
                        if (!plf) {
                            announceCopyPw(msgNoPwdPw || msgBadPw, 'danger');
                            return;
                        }
                        var sfx = baseFolderUrl.indexOf('?') >= 0 ? '&' : '?';
                        var fullFolder = baseFolderUrl + sfx + 'share_password=' + encodeURIComponent(plf);
                        tryClipboardPw(fullFolder).then(function (ok) {
                            announceCopyPw(ok ? msgOkPw : msgBadPw, ok ? 'success' : 'danger');
                        });
                    })
                    .catch(function () {
                        announceCopyPw(msgBadPw, 'danger');
                    });
            }
        }
    });

    /**
     * @brief Trigger a zip download for selected files and owned folders.
     * @param {number[]} ids Selected file ids.
     * @param {number[]} folderIds Selected owned folder ids.
     * @param {{adminContext?: boolean, adminViewScope?: string, subjectUserId?: string}|null} selectionContext Active selection context.
     * @return {void}
     * @date 2026-05-08
     * @author Stephane H.
     */
    function downloadSharedSelection(ids, folderIds, selectionContext) {
        var fileIds = Array.isArray(ids) ? ids : [];
        var selectedFolderIds = Array.isArray(folderIds) ? folderIds : [];
        if (fileIds.length < 1 && selectedFolderIds.length < 1) {
            return;
        }
        var liveRegionNode = document.getElementById('files-live-region');
        var zipUrl = liveRegionNode ? (liveRegionNode.getAttribute('data-files-download-zip-url') || '') : '';
        if (zipUrl === '') {
            return;
        }
        var params = new URLSearchParams();
        fileIds.forEach(function (id) {
            params.append('ids[]', String(id));
        });
        selectedFolderIds.forEach(function (id) {
            params.append('folder_ids[]', String(id));
        });
        var ctx = selectionContext && typeof selectionContext === 'object' ? selectionContext : null;
        if (ctx && ctx.adminContext === true && String(ctx.adminViewScope || '') === 'all') {
            params.set('admin_context', '1');
            params.set('admin_view_scope', 'all');
            if (typeof ctx.subjectUserId === 'string' && ctx.subjectUserId.trim() !== '') {
                params.set('subject_user', ctx.subjectUserId.trim());
            }
        }
        var href = zipUrl + (zipUrl.indexOf('?') >= 0 ? '&' : '?') + params.toString();
        window.location.href = href;
    }

    document.addEventListener('click', function (event) {
        var g = event.target && event.target.closest
            ? event.target.closest('[data-files-action-global]')
            : null;
        if (!g) {
            return;
        }
        event.preventDefault();
        var action = g.getAttribute('data-files-action-global') || '';
        if (action === 'create-folder') {
            var createFolderModal = document.getElementById('filesCreateFolderModal');
            if (createFolderModal && window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(createFolderModal).show();
            }
            return;
        }
        var selectionState = getSelectionState();
        var paneDistinct = getDistinctPaneIdsFromSelection();
        if (paneDistinct.length > 1) {
            var lrMulti = document.getElementById('files-live-region');
            var msgMulti = lrMulti ? lrMulti.getAttribute('data-files-msg-selection-multi-pane') || '' : '';
            if (msgMulti !== '' && typeof pushFilesToast === 'function') {
                pushFilesToast(msgMulti, 'warning');
            }
            return;
        }
        var ap = selectionState.activePaneId;
        var ids = getSelectedFileIds(selectionState.activeScope, ap);
        var folderIds = getSelectedFolderIds(selectionState.activeScope, ap);
        if (ids.length < 1 && folderIds.length < 1) {
            return;
        }
        if (action === 'download-selection') {
            if (selectionState.activeScope === 'shared' && ids.length < 1 && folderIds.length === 1) {
                var liveRegionNode = document.getElementById('files-live-region');
                var sharedFolderZipTpl = liveRegionNode ? (liveRegionNode.getAttribute('data-files-shared-folder-download-zip-url-template') || '') : '';
                if (sharedFolderZipTpl !== '') {
                    var sharedFolderZipHref = sharedFolderZipTpl.replace('999999', String(folderIds[0]));
                    window.location.href = sharedFolderZipHref;
                }
                return;
            }
            var dlContext = buildFrontShareContext({
                mode: 'bulk',
                scope: selectionState.activeScope,
                bulkIds: ids,
                bulkFolderIds: folderIds
            });
            downloadSharedSelection(ids, folderIds, {
                adminContext: dlContext.adminContext,
                adminViewScope: dlContext.adminViewScope,
                subjectUserId: dlContext.subjectUserId
            });
        } else if (action === 'move-selection') {
            if (selectionState.activeScope !== 'owned') {
                return;
            }
            openMoveBulkModal(ids, folderIds);
        } else if (action === 'delete') {
            if (selectionState.activeScope !== 'owned') {
                return;
            }
            openBulkDeleteModal(ids, folderIds);
        }
    });

    var deleteBulkForm = document.getElementById('files-delete-bulk-form');
    if (deleteBulkForm) {
        deleteBulkForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            submitBulkDeleteFormAsJson(deleteBulkForm);
        });
    }

    var moveBulkForm = document.getElementById('files-move-bulk-form');
    if (moveBulkForm) {
        moveBulkForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            submitMoveBulkFormAsJson(moveBulkForm);
        });
    }

    document.addEventListener('click', function (event) {
        var moveModal = document.getElementById('filesMoveBulkModal');
        if (!moveModal) {
            return;
        }
        var inMove = event.target && event.target.closest && event.target.closest('#filesMoveBulkModal');
        if (!inMove) {
            return;
        }
        var upBtn = event.target && event.target.closest ? event.target.closest('[data-files-move-parent-up]') : null;
        if (upBtn) {
            event.preventDefault();
            if (moveBrowseTrail.length > 1) {
                moveBrowseTrail.pop();
                var cur = moveBrowseTrail[moveBrowseTrail.length - 1];
                var tIn = document.getElementById('files-move-bulk-target-folder-id');
                if (tIn) {
                    tIn.value = String(cur.id);
                }
                renderMoveBreadcrumbAndList(moveModal);
                loadMoveFolderChildren(moveModal);
            }
            return;
        }
        var rootBtn = event.target && event.target.closest ? event.target.closest('[data-files-move-go-root]') : null;
        if (rootBtn) {
            event.preventDefault();
            var rootLabel = moveModal.getAttribute('data-files-move-breadcrumb-root-label') || '';
            moveBrowseTrail = [{ id: 0, name: rootLabel }];
            var tIn2 = document.getElementById('files-move-bulk-target-folder-id');
            if (tIn2) {
                tIn2.value = '0';
            }
            renderMoveBreadcrumbAndList(moveModal);
            loadMoveFolderChildren(moveModal);
            return;
        }
        var enterBtn = event.target && event.target.closest ? event.target.closest('[data-files-move-enter-id]') : null;
        if (enterBtn) {
            event.preventDefault();
            var fid = parseInt(enterBtn.getAttribute('data-files-move-enter-id') || '0', 10);
            var fname = enterBtn.getAttribute('data-files-move-enter-name') || '';
            if (fid < 1) {
                return;
            }
            moveBrowseTrail.push({ id: fid, name: fname });
            var tIn3 = document.getElementById('files-move-bulk-target-folder-id');
            if (tIn3) {
                tIn3.value = String(fid);
            }
            renderMoveBreadcrumbAndList(moveModal);
            loadMoveFolderChildren(moveModal);
        }
    });

    var renameForm = document.getElementById('files-rename-form');
    if (renameForm) {
        renameForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var renameModal = document.getElementById('filesRenameModal');
            var renameError = renameModal ? renameModal.querySelector('[data-files-rename-error]') : null;
            var submitBtn = renameForm.querySelector('[data-files-rename-submit]');
            if (renameError) {
                renameError.classList.add('d-none');
                renameError.textContent = '';
            }
            if (submitBtn) {
                submitBtn.disabled = true;
            }
            var fd = new FormData(renameForm);
            var body = new URLSearchParams();
            fd.forEach(function (value, key) {
                body.append(key, String(value));
            });
            fetch(renameForm.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: body,
                credentials: 'same-origin'
            }).then(function (response) {
                return response.json().catch(function () {
                    return {};
                }).then(function (json) {
                    return { ok: response.ok, json: json };
                });
            }).then(function (pack) {
                if (submitBtn) {
                    submitBtn.disabled = false;
                }
                if (pack.ok && pack.json && pack.json.status === 'ok') {
                    if (renameModal && window.bootstrap && window.bootstrap.Modal) {
                        window.bootstrap.Modal.getOrCreateInstance(renameModal).hide();
                    }
                    if (window.AppFlashToasts && typeof window.AppFlashToasts.push === 'function' && pack.json.message) {
                        window.AppFlashToasts.push('success', String(pack.json.message));
                    }
                    if (searchInput) {
                        runPartialFetch(searchInput.value.trim());
                    } else {
                        runPartialFetch('');
                    }
                    return;
                }
                if (renameError) {
                    var msg = (pack.json && pack.json.message) || (renameModal && renameModal.getAttribute('data-msg-error')) || '';
                    renameError.textContent = typeof msg === 'string' ? msg : '';
                    renameError.classList.remove('d-none');
                }
            }).catch(function () {
                if (submitBtn) {
                    submitBtn.disabled = false;
                }
                if (renameError) {
                    renameError.textContent = (renameModal && renameModal.getAttribute('data-msg-error')) || '';
                    renameError.classList.remove('d-none');
                }
            });
        });
    }

    var sharePublicForm = document.getElementById('files-share-public-form');
    if (sharePublicForm) {
        sharePublicForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var modeInput = sharePublicForm.querySelector('[data-files-share-public-mode-input]');
            var mode = modeInput ? modeInput.value : 'single';
            var modalEl = document.getElementById('filesSharePublicModal');
            if (mode === 'bulk' && modalEl) {
                sharePublicForm.action = modalEl.dataset.filesSharePublicBulkUrl || sharePublicForm.action;
            } else if (mode === 'single') {
                var sid = sharePublicForm.getAttribute('data-files-share-public-current-id') || '';
                var t = modalEl ? (modalEl.dataset.filesSharePublicSingleUrlTemplate || '') : '';
                if (t && sid) {
                    sharePublicForm.action = t.replace('999999', sid);
                }
            }
            submitShareFormAsJson(sharePublicForm, 'filesSharePublicModal');
        });
        var sharePubExpInput = document.getElementById('files-share-public-expires-at');
        var sharePubEnabledInput = document.getElementById('files-share-public-enabled');
        if (sharePubExpInput) {
            sharePubExpInput.addEventListener('input', refreshPublicShareRelativeHint);
            sharePubExpInput.addEventListener('change', refreshPublicShareRelativeHint);
        }
        if (sharePubEnabledInput) {
            sharePubEnabledInput.addEventListener('change', refreshPublicShareRelativeHint);
        }
        var sharePubPasswordToggleInput = document.getElementById('files-share-public-password-enabled');
        if (sharePubPasswordToggleInput) {
            sharePubPasswordToggleInput.addEventListener('change', function () {
                submitPublicPasswordToggle(sharePublicForm, sharePubPasswordToggleInput);
            });
        }
    }

    var shareFriendsForm = document.getElementById('files-share-friends-form');
    if (shareFriendsForm) {
        shareFriendsForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var modeInput = shareFriendsForm.querySelector('[data-files-share-friends-mode-input]');
            var mode = modeInput ? modeInput.value : 'single';
            var modalEl = document.getElementById('filesShareFriendsModal');
            if (mode === 'bulk' && modalEl) {
                shareFriendsForm.action = modalEl.dataset.filesShareFriendsBulkUrl || shareFriendsForm.action;
            } else if (mode === 'single') {
                var sid = shareFriendsForm.getAttribute('data-files-share-friends-current-id') || '';
                var t = modalEl ? (modalEl.dataset.filesShareFriendsSingleUrlTemplate || '') : '';
                if (t && sid) {
                    shareFriendsForm.action = t.replace('999999', sid);
                }
            }
            submitShareFormAsJson(shareFriendsForm, 'filesShareFriendsModal');
        });
    }

    updateSelectionUi();

    /**
     * @brief Format an ISO-8601 datetime string using the user's locale, falling back
     *        to the raw value on parse error. Empty / nullish input returns the
     *        provided fallback string.
     * @param {string|null|undefined} iso Server-provided ISO datetime.
     * @param {string} fallback Localized text to use when iso is empty / null.
     * @return {string}
     * @date 2026-04-27
     * @author Stephane H.
     */
    function formatPropertiesDate(iso, fallback) {
        if (iso === undefined || iso === null || iso === '') {
            return fallback;
        }
        var d = new Date(iso);
        if (isNaN(d.getTime())) {
            return String(iso);
        }
        try {
            return new Intl.DateTimeFormat(undefined, {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            }).format(d);
        } catch (e) {
            return d.toISOString();
        }
    }

    /**
     * @brief Reset the properties modal body to its idle state (hide content,
     *        error and loading; clear preview and grants list).
     * @param {HTMLElement} modalEl Properties modal root element.
     * @return {void}
     * @date 2026-04-30
     * @author Stephane H.
     */
    function resetPropertiesModal(modalEl) {
        var content = modalEl.querySelector('[data-files-prop="content"]');
        var error = modalEl.querySelector('[data-files-prop="error"]');
        var preview = modalEl.querySelector('[data-files-prop="preview"]');
        var iconFallback = modalEl.querySelector('[data-files-prop="icon-fallback"]');
        var grants = modalEl.querySelector('[data-files-prop="grants"]');
        var grantsEmpty = modalEl.querySelector('[data-files-prop="grantsEmpty"]');
        var sectionPublic = modalEl.querySelector('[data-files-prop-section="public"]');
        var sectionFriends = modalEl.querySelector('[data-files-prop-section="friends"]');
        var sharedByLabel = modalEl.querySelector('[data-files-prop="sharedByLabel"]');
        var sharedByValue = modalEl.querySelector('[data-files-prop="sharedBy"]');
        var separatorPublic = modalEl.querySelector('[data-files-prop-separator="public"]');
        var separatorFriends = modalEl.querySelector('[data-files-prop-separator="friends"]');
        if (content) { content.classList.add('d-none'); }
        if (error) { error.classList.add('d-none'); error.textContent = ''; }
        if (preview) { preview.classList.add('d-none'); preview.removeAttribute('src'); }
        if (iconFallback) { iconFallback.classList.add('d-none'); }
        if (grants) { grants.innerHTML = ''; }
        if (grantsEmpty) { grantsEmpty.classList.add('d-none'); }
        if (sectionPublic) { sectionPublic.classList.add('d-none'); }
        if (sectionFriends) { sectionFriends.classList.add('d-none'); }
        if (sharedByLabel) { sharedByLabel.classList.add('d-none'); }
        if (sharedByValue) { sharedByValue.classList.add('d-none'); sharedByValue.textContent = ''; }
        if (separatorPublic) { separatorPublic.classList.remove('d-none'); }
        if (separatorFriends) { separatorFriends.classList.remove('d-none'); }
    }

    /**
     * @brief Populate the properties modal DOM from a JSON payload returned by
     *        the /files/{id}/properties endpoint and reveal the relevant
     *        public / private / preview sections.
     * @param {HTMLElement} modalEl Properties modal root element.
     * @param {Record<string, unknown>} payload Server JSON payload.
     * @param {string} mode Rendering scope ('owner' or 'shared').
     * @return {void}
     * @date 2026-05-02
     * @author Stephane H.
     */
    function populatePropertiesModal(modalEl, payload, mode) {
        var msgRecipientUntilTpl = modalEl.dataset.msgRecipientUntil || '__DATE__';
        var msgRecipientNoExpiry = modalEl.dataset.msgRecipientNoExpiry || '';
        var msgPublicNoExpiry = modalEl.dataset.msgPublicNoExpiry || '';
        var msgPublicOff = modalEl.dataset.msgPublicOff || '';
        var msgPublicActive = modalEl.dataset.msgPublicActive || '';
        var msgPublicExpired = modalEl.dataset.msgPublicExpired || '';

        var setText = function (key, value) {
            var el = modalEl.querySelector('[data-files-prop="' + key + '"]');
            if (el) { el.textContent = value === undefined || value === null ? '' : String(value); }
        };

        setText('name', payload && payload.name);
        var ext = payload && payload.extension ? String(payload.extension) : '';
        setText('extension', ext === '' ? '—' : ext);
        setText('size', payload && payload.byte_size_formatted ? payload.byte_size_formatted : '—');
        setText('uploaded', formatPropertiesDate(payload && payload.uploaded_at, '—'));
        setText('updated', formatPropertiesDate(payload && payload.updated_at, '—'));

        var preview = modalEl.querySelector('[data-files-prop="preview"]');
        var iconFallback = modalEl.querySelector('[data-files-prop="icon-fallback"]');
        var extensionBadge = modalEl.querySelector('[data-files-prop="extension-badge"]');
        var previewUrl = payload && payload.preview_url ? String(payload.preview_url) : '';
        if (preview && previewUrl !== '') {
            preview.setAttribute('src', previewUrl);
            preview.classList.remove('d-none');
            if (iconFallback) { iconFallback.classList.add('d-none'); }
        } else {
            if (preview) { preview.classList.add('d-none'); preview.removeAttribute('src'); }
            if (iconFallback) {
                iconFallback.classList.remove('d-none');
                if (extensionBadge) { extensionBadge.textContent = ext === '' ? 'FILE' : ext.toUpperCase(); }
            }
        }

        var sectionPublic = modalEl.querySelector('[data-files-prop-section="public"]');
        var sectionFriends = modalEl.querySelector('[data-files-prop-section="friends"]');
        var sharedByLabel = modalEl.querySelector('[data-files-prop="sharedByLabel"]');
        var sharedByValue = modalEl.querySelector('[data-files-prop="sharedBy"]');
        var separatorPublic = modalEl.querySelector('[data-files-prop-separator="public"]');
        var separatorFriends = modalEl.querySelector('[data-files-prop-separator="friends"]');
        var isSharedScope = mode === 'shared';
        var editPublicBtn = modalEl.querySelector('[data-files-prop="editPublicBtn"]');
        var editFriendsBtn = modalEl.querySelector('[data-files-prop="editFriendsBtn"]');
        if (editPublicBtn) {
            if (isSharedScope) {
                editPublicBtn.classList.add('d-none');
            } else {
                editPublicBtn.classList.remove('d-none');
            }
        }
        if (editFriendsBtn) {
            if (isSharedScope) {
                editFriendsBtn.classList.add('d-none');
            } else {
                editFriendsBtn.classList.remove('d-none');
            }
        }
        if (isSharedScope) {
            if (sectionPublic) { sectionPublic.classList.add('d-none'); }
            if (sectionFriends) { sectionFriends.classList.add('d-none'); }
            if (separatorPublic) { separatorPublic.classList.add('d-none'); }
            if (separatorFriends) { separatorFriends.classList.add('d-none'); }
            if (sharedByLabel) { sharedByLabel.classList.remove('d-none'); }
            if (sharedByValue) {
                sharedByValue.classList.remove('d-none');
                sharedByValue.textContent = payload && payload.shared_by ? String(payload.shared_by) : '—';
            }
            var contentShared = modalEl.querySelector('[data-files-prop="content"]');
            if (contentShared) { contentShared.classList.remove('d-none'); }
            return;
        }
        var pub = payload && payload.public ? payload.public : {};
        var statusTxt = msgPublicOff;
        if (pub && pub.expired) {
            statusTxt = msgPublicExpired;
        } else if (pub && pub.active) {
            statusTxt = msgPublicActive;
        } else {
            statusTxt = msgPublicOff;
        }
        setText('publicStatus', statusTxt);

        var tokLab = modalEl.querySelector('[data-files-prop="publicTokenLabel"]');
        var tokCont = modalEl.querySelector('[data-files-prop="publicTokenContainer"]');
        var valLab = modalEl.querySelector('[data-files-prop="publicValidityLabel"]');
        var valCont = modalEl.querySelector('[data-files-prop="publicValidityContainer"]');
        if (pub && pub.active) {
            if (sectionPublic) { sectionPublic.classList.remove('d-none'); }
            if (tokLab) { tokLab.classList.remove('d-none'); }
            if (tokCont) { tokCont.classList.remove('d-none'); }
            if (valLab) { valLab.classList.remove('d-none'); }
            if (valCont) { valCont.classList.remove('d-none'); }
            setText('publicToken', pub.token || '');
            setText('publicValidity', formatPropertiesDate(pub.expires_at, msgPublicNoExpiry));
        } else {
            if (sectionPublic) { sectionPublic.classList.remove('d-none'); }
            if (tokLab) { tokLab.classList.add('d-none'); }
            if (tokCont) { tokCont.classList.add('d-none'); }
            if (valLab) { valLab.classList.add('d-none'); }
            if (valCont) { valCont.classList.add('d-none'); }
        }

        var friendsWrap = payload && payload.friends ? payload.friends : {};
        var grants = friendsWrap && Array.isArray(friendsWrap.grants) ? friendsWrap.grants : [];
        var grantsList = modalEl.querySelector('[data-files-prop="grants"]');
        var grantsEmpty = modalEl.querySelector('[data-files-prop="grantsEmpty"]');
        var grantTemplate = modalEl.querySelector('[data-files-prop="grantTemplate"]');
        if (sectionFriends) { sectionFriends.classList.remove('d-none'); }
        if (grantsList) { grantsList.innerHTML = ''; }
        if (grants.length === 0) {
            if (grantsEmpty) { grantsEmpty.classList.remove('d-none'); }
        } else {
            if (grantsEmpty) { grantsEmpty.classList.add('d-none'); }
            grants.forEach(function (g) {
                var liNode = null;
                if (grantTemplate && grantTemplate.content && grantTemplate.content.firstElementChild) {
                    liNode = grantTemplate.content.firstElementChild.cloneNode(true);
                } else {
                    liNode = document.createElement('li');
                    liNode.className = 'list-group-item d-flex justify-content-between align-items-center';
                    var sp1 = document.createElement('span');
                    sp1.setAttribute('data-files-prop', 'grantPseudo');
                    var sp2 = document.createElement('span');
                    sp2.className = 'text-body-secondary small';
                    sp2.setAttribute('data-files-prop', 'grantUntil');
                    liNode.appendChild(sp1);
                    liNode.appendChild(sp2);
                }
                var pseudoEl = liNode.querySelector('[data-files-prop="grantPseudo"]');
                var untilEl = liNode.querySelector('[data-files-prop="grantUntil"]');
                if (pseudoEl) { pseudoEl.textContent = g && g.pseudo ? String(g.pseudo) : ''; }
                if (untilEl) {
                    var untilTxt = msgRecipientNoExpiry;
                    if (g && g.expires_at) {
                        untilTxt = msgRecipientUntilTpl.replace('__DATE__', formatPropertiesDate(g.expires_at, ''));
                    }
                    untilEl.textContent = untilTxt;
                }
                if (grantsList) { grantsList.appendChild(liNode); }
            });
        }

        var content = modalEl.querySelector('[data-files-prop="content"]');
        if (content) { content.classList.remove('d-none'); }
    }

    /**
     * @brief Open the file properties modal for a given id, fetching JSON from
     *        the server and populating the DOM. Errors are surfaced inside the
     *        modal via the localized error block.
     * @param {HTMLElement} modalEl Properties modal root element.
     * @param {number} fileId Shared file identifier.
     * @param {string} scope "owner" or "shared".
     * @param {string} subjectUserId Subject user id for admin godview context.
     * @return {void}
     * @date 2026-05-05
     * @author Stephane H.
     */
    function openPropertiesModal(modalEl, fileId, scope, subjectUserId) {
        var mode = scope === 'shared' ? 'shared' : 'owner';
        var template = mode === 'shared'
            ? (modalEl.dataset.filesSharedPropertiesUrlTemplate || '')
            : (modalEl.dataset.filesPropertiesUrlTemplate || '');
        if (template === '') {
            return;
        }
        modalEl.dataset.filesCurrentFileId = String(fileId);
        modalEl.dataset.filesCurrentSubjectUserId = String(subjectUserId || '');
        resetPropertiesModal(modalEl);
        var loading = modalEl.querySelector('[data-files-prop="loading"]');
        if (loading) { loading.classList.remove('d-none'); }
        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
        var propertiesContext = buildFrontShareContext({
            mode: 'single',
            explicitSubjectUserId: String(subjectUserId || ''),
            scope: mode
        });
        var url = appendShareContextQuery(
            template.replace('999999', String(fileId)),
            propertiesContext
        );
        fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('properties_fetch_failed');
                }
                return res.json();
            })
            .then(function (payload) {
                if (loading) { loading.classList.add('d-none'); }
                if (!payload || payload.status !== 'ok') {
                    var errEl = modalEl.querySelector('[data-files-prop="error"]');
                    if (errEl) {
                        errEl.textContent = modalEl.dataset.msgError || 'Error';
                        errEl.classList.remove('d-none');
                    }
                    return;
                }
                var editPublicBtn = modalEl.querySelector('[data-files-prop="editPublicBtn"]');
                var editFriendsBtn = modalEl.querySelector('[data-files-prop="editFriendsBtn"]');
                populatePropertiesModal(modalEl, payload, mode);
            })
            .catch(function () {
                if (loading) { loading.classList.add('d-none'); }
                var errEl = modalEl.querySelector('[data-files-prop="error"]');
                if (errEl) {
                    errEl.textContent = modalEl.dataset.msgError || 'Error';
                    errEl.classList.remove('d-none');
                }
            });
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target && event.target.closest ? event.target.closest('[data-files-row-action="properties"]') : null;
        if (!trigger) {
            return;
        }
        event.preventDefault();
        var modalEl = document.getElementById('filesPropertiesModal');
        var fileId = Number(trigger.getAttribute('data-files-row-id') || '0');
        var scope = trigger.getAttribute('data-files-row-action-scope') || 'owner';
        if (!modalEl || fileId <= 0) {
            return;
        }
        closeAllActionMenus();
        openPropertiesModal(modalEl, fileId, scope, getSubjectUserIdFromTrigger(trigger));
    });

    /**
     * @brief Open folder properties modal and populate with recursive stats JSON.
     * @param {number} folderId Folder identifier.
     * @param {string} scope Data scope ('owner' or 'shared').
     * @param {string} subjectUserId Subject user id for admin godview context.
     * @return {void}
     * @date 2026-05-05
     * @author Stephane H.
     */
    function openFolderPropertiesModal(folderId, scope, subjectUserId) {
        var modalEl = document.getElementById('filesFolderPropertiesModal');
        if (!modalEl || folderId <= 0) {
            return;
        }
        var mode = scope === 'shared' ? 'shared' : 'owner';
        var urlTpl = mode === 'shared'
            ? (modalEl.dataset.filesSharedFolderPropertiesUrlTemplate || '')
            : (modalEl.dataset.filesFolderPropertiesUrlTemplate || '');
        if (urlTpl === '') {
            return;
        }
        var loading = modalEl.querySelector('[data-files-folder-prop="loading"]');
        var error = modalEl.querySelector('[data-files-folder-prop="error"]');
        var content = modalEl.querySelector('[data-files-folder-prop="content"]');
        var sharedByLabel = modalEl.querySelector('[data-files-folder-prop="sharedByLabel"]');
        var sharedByValue = modalEl.querySelector('[data-files-folder-prop="sharedBy"]');
        if (loading) { loading.classList.remove('d-none'); }
        if (error) { error.classList.add('d-none'); error.textContent = ''; }
        if (content) { content.classList.add('d-none'); }
        if (sharedByLabel) { sharedByLabel.classList.add('d-none'); }
        if (sharedByValue) { sharedByValue.classList.add('d-none'); sharedByValue.textContent = ''; }
        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
        var folderPropertiesContext = buildFrontShareContext({
            mode: 'folder',
            explicitSubjectUserId: String(subjectUserId || ''),
            scope: mode
        });
        var url = appendShareContextQuery(
            urlTpl.replace('999999', String(folderId)),
            folderPropertiesContext
        );
        fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (res) {
            if (!res.ok) {
                throw new Error('folder_properties_fetch_failed');
            }
            return res.json();
        }).then(function (payload) {
            if (loading) { loading.classList.add('d-none'); }
            if (!payload || payload.status !== 'ok') {
                throw new Error('folder_properties_payload_invalid');
            }
            var put = function (key, value) {
                var el = modalEl.querySelector('[data-files-folder-prop="' + key + '"]');
                if (el) {
                    el.textContent = value === undefined || value === null ? '' : String(value);
                }
            };
            put('name', payload.name || '');
            put('totalSize', payload.total_bytes_formatted || '0 B');
            put('totalFiles', payload.total_files || 0);
            put('totalSubfolders', payload.total_subfolders || 0);
            put('createdAt', formatPropertiesDate(payload.created_at, '—'));
            put('updatedAt', formatPropertiesDate(payload.updated_at, '—'));
            var yesLabel = modalEl.dataset.msgYes || 'Yes';
            var noLabel = modalEl.dataset.msgNo || 'No';
            put('publicActive', payload.public_active ? yesLabel : noLabel);
            put('friendsActive', payload.friends_active ? yesLabel : noLabel);
            if (mode === 'shared') {
                if (sharedByLabel) { sharedByLabel.classList.remove('d-none'); }
                if (sharedByValue) {
                    sharedByValue.classList.remove('d-none');
                    sharedByValue.textContent = payload && payload.shared_by ? String(payload.shared_by) : '—';
                }
            }
            if (content) { content.classList.remove('d-none'); }
        }).catch(function () {
            if (loading) { loading.classList.add('d-none'); }
            if (error) {
                error.textContent = modalEl.dataset.msgError || 'Error';
                error.classList.remove('d-none');
            }
        });
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target && event.target.closest ? event.target.closest('[data-files-folder-action]') : null;
        if (!trigger) {
            return;
        }
        event.preventDefault();
        closeAllActionMenus();
        var action = trigger.getAttribute('data-files-folder-action') || '';
        var scope = trigger.getAttribute('data-files-folder-action-scope') || 'owner';
        var folderId = Number(trigger.getAttribute('data-files-folder-id') || '0');
        if (action === 'rename-open' && folderId > 0 && scope !== 'shared') {
            var renameModalF = document.getElementById('filesRenameModal');
            var renameFormF = document.getElementById('files-rename-form');
            var renameInputF = document.getElementById('files-rename-name');
            if (!renameModalF || !renameFormF || !renameInputF || !window.bootstrap || !window.bootstrap.Modal) {
                return;
            }
            var folderLabel = trigger.getAttribute('data-files-folder-name') || '';
            var errBox = renameModalF.querySelector('[data-files-rename-error]');
            if (errBox) {
                errBox.classList.add('d-none');
                errBox.textContent = '';
            }
            configureRenameModalForOwnedFolder(renameModalF, renameFormF, renameInputF, folderId, folderLabel);
            window.bootstrap.Modal.getOrCreateInstance(renameModalF).show();
            window.setTimeout(function () {
                renameInputF.focus();
                renameInputF.select();
            }, 0);
            return;
        }
        if (action === 'properties') {
            openFolderPropertiesModal(folderId, scope, getSubjectUserIdFromTrigger(trigger));
        } else if (action === 'share-public' && folderId > 0) {
            openPublicShareModal('folder', folderId, null, getSubjectUserIdFromTrigger(trigger));
        } else if (action === 'share-friends' && folderId > 0) {
            openFriendsShareModal('folder', folderId, null, getSubjectUserIdFromTrigger(trigger));
        } else if (action === 'resolve-copy-public-link' && folderId > 0) {
            var liveRegion = document.getElementById('files-live-region');
            var copyLive = document.getElementById('files-copy-live');
            var tpl = liveRegion ? (liveRegion.getAttribute('data-files-folder-public-landing-url-template') || '') : '';
            var csrf = liveRegion ? (liveRegion.getAttribute('data-files-folder-public-landing-csrf') || '') : '';
            var msgOk = copyLive ? (copyLive.getAttribute('data-msg-copied') || '') : '';
            var msgBad = copyLive ? (copyLive.getAttribute('data-msg-failed') || '') : '';
            var msgNoFiles = copyLive ? (copyLive.getAttribute('data-msg-public-link-no-files') || '') : '';
            var msgResolve = copyLive ? (copyLive.getAttribute('data-msg-public-link-resolve-failed') || '') : '';
            var modalTitle = copyLive ? (copyLive.getAttribute('data-files-copy-url-modal-title') || '') : '';
            var modalCopy = copyLive ? (copyLive.getAttribute('data-files-copy-url-modal-copy') || '') : '';
            var modalIntro = copyLive ? (copyLive.getAttribute('data-files-copy-url-modal-intro') || '') : '';
            var closeLbl = liveRegion ? (liveRegion.getAttribute('data-files-toast-close-label') || '') : '';
            /**
             * @brief Updates the aria-live region and optional Bootstrap toast for copy feedback.
             * @param {string} text Message for assistive tech and toast.
             * @param {string|null} toastTone Optional toast tone (success|danger|warning|info).
             * @return {void}
             * @date 2026-05-02
             * @author Stephane H.
             */
            function announceCopyFolderLink(text, toastTone) {
                if (copyLive) {
                    copyLive.textContent = text;
                    window.setTimeout(function () {
                        copyLive.textContent = '';
                    }, 4000);
                }
                if (toastTone && typeof pushFilesToast === 'function') {
                    pushFilesToast(text, toastTone);
                }
            }
            if (tpl === '' || csrf === '') {
                announceCopyFolderLink(msgResolve || msgBad, 'danger');
                return;
            }
            var postUrl = tpl.replace('999999', String(folderId));
            var body = new URLSearchParams();
            body.set('_csrf_token', csrf);
            var folderSubjectUserId = getSubjectUserIdFromTrigger(trigger);
            if (folderSubjectUserId !== '') {
                body.set('subject_user', folderSubjectUserId);
            }
            fetch(postUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
            })
                .then(function (res) {
                    var ct = res.headers.get('content-type') || '';
                    if (ct.indexOf('application/json') === -1) {
                        return res.text().then(function () {
                            return { ok: false, data: {} };
                        });
                    }
                    return res.json().then(function (data) {
                        return { ok: res.ok, data: data };
                    });
                })
                .then(function (pack) {
                    var payload = pack.data || {};
                    if (pack.ok && payload.status === 'ok' && payload.url) {
                        var u = normalizePublicLandingUrlForClipboard(String(payload.url));
                        /**
                         * Clipboard APIs are unreliable after async fetch; open modal so copy runs on a fresh click.
                         */
                        function announceModalCopy(text, tone) {
                            announceCopyFolderLink(text, tone || null);
                        }
                        openCopyUrlFallbackModal(
                            u,
                            {
                                title: modalTitle,
                                intro: modalIntro,
                                copyBtn: modalCopy,
                                closeBtn: closeLbl
                            },
                            function (txt) {
                                announceModalCopy(txt, txt === msgOk ? 'success' : 'danger');
                            },
                            msgOk,
                            msgBad
                        );
                        return;
                    }
                    var m = msgResolve;
                    if (payload.message === 'files.folder.public_link.no_files' && msgNoFiles !== '') {
                        m = msgNoFiles;
                    }
                    announceCopyFolderLink(m || msgBad, 'warning');
                })
                .catch(function () {
                    announceCopyFolderLink(msgResolve || msgBad, 'danger');
                });
        }
    });

    var folderPropsModalForFocus = document.getElementById('filesFolderPropertiesModal');
    if (folderPropsModalForFocus) {
        folderPropsModalForFocus.addEventListener('click', function (event) {
            var dismissBtn = event.target && event.target.closest ? event.target.closest('[data-bs-dismiss="modal"]') : null;
            if (!dismissBtn) {
                return;
            }
        });
        folderPropsModalForFocus.addEventListener('shown.bs.modal', function () {
        });
        folderPropsModalForFocus.addEventListener('hidden.bs.modal', function () {
            var activeInside = !!(document.activeElement && folderPropsModalForFocus.contains(document.activeElement));
        });
    }

    document.addEventListener('click', function (event) {
        var edPub = event.target && event.target.closest
            ? event.target.closest('[data-files-prop-action="edit-public"]')
            : null;
        var edFr = !edPub && event.target && event.target.closest
            ? event.target.closest('[data-files-prop-action="edit-friends"]')
            : null;
        if (!edPub && !edFr) {
            return;
        }
        event.preventDefault();
        var props = document.getElementById('filesPropertiesModal');
        if (!props || !window.bootstrap || !window.bootstrap.Modal) {
            return;
        }
        var fid = Number(props.dataset.filesCurrentFileId || '0');
        if (fid <= 0) {
            return;
        }
        window.bootstrap.Modal.getOrCreateInstance(props).hide();
        props.addEventListener('hidden.bs.modal', function onH() {
            var propertySubjectUserId = String(props.dataset.filesCurrentSubjectUserId || '').trim();
            if (edPub) {
                openPublicShareModal('single', fid, null, propertySubjectUserId);
            } else {
                openFriendsShareModal('single', fid, null, propertySubjectUserId);
            }
        }, { once: true });
    });

    var CONTEXT_MENU_VIEWPORT_MARGIN = 8;

    /**
     * @brief Reset inline positioning that could have been applied by Popper or
     *        by a previous context-menu opening.
     * @param {HTMLElement} menu Dropdown menu element.
     * @return {void}
     * @date 2026-05-06
     * @author Stephane H.
     */
    function clearContextMenuInlineStyles(menu) {
        menu.style.left = '';
        menu.style.top = '';
        menu.style.right = '';
        menu.style.bottom = '';
        menu.style.transform = '';
        menu.style.position = '';
        menu.style.inset = '';
        menu.style.margin = '';
        menu.style.maxHeight = '';
        menu.style.overflowY = '';
        menu.classList.remove('files-row-context-menu--constrained');
    }

    /**
     * @brief Position an opened context menu inside viewport bounds using
     *        horizontal and vertical flip plus clamp safeguards.
     * @param {HTMLElement} menu Opened context menu element.
     * @param {number} clickX Viewport X coordinate of the trigger.
     * @param {number} clickY Viewport Y coordinate of the trigger.
     * @return {void}
     * @date 2026-05-06
     * @author Stephane H.
     */
    function positionContextMenuInViewport(menu, clickX, clickY) {
        if (!menu) {
            return;
        }
        var viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
        var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        var safeHeight = Math.max(0, viewportHeight - (CONTEXT_MENU_VIEWPORT_MARGIN * 2));

        menu.style.left = clickX + 'px';
        menu.style.top = clickY + 'px';
        menu.style.maxHeight = '';
        menu.style.overflowY = '';
        menu.classList.remove('files-row-context-menu--constrained');

        var rect = menu.getBoundingClientRect();
        var menuWidth = rect.width;
        var menuHeight = rect.height;
        if (menuHeight > safeHeight && safeHeight > 0) {
            menu.style.maxHeight = safeHeight + 'px';
            menu.style.overflowY = 'auto';
            menu.classList.add('files-row-context-menu--constrained');
            rect = menu.getBoundingClientRect();
            menuWidth = rect.width;
            menuHeight = rect.height;
        }

        var placedLeft = clickX;
        var placedTop = clickY;
        if ((placedLeft + menuWidth + CONTEXT_MENU_VIEWPORT_MARGIN) > viewportWidth) {
            placedLeft = clickX - menuWidth;
        }
        if ((placedTop + menuHeight + CONTEXT_MENU_VIEWPORT_MARGIN) > viewportHeight) {
            placedTop = clickY - menuHeight;
        }

        var maxLeft = Math.max(CONTEXT_MENU_VIEWPORT_MARGIN, viewportWidth - CONTEXT_MENU_VIEWPORT_MARGIN - menuWidth);
        var maxTop = Math.max(CONTEXT_MENU_VIEWPORT_MARGIN, viewportHeight - CONTEXT_MENU_VIEWPORT_MARGIN - menuHeight);
        placedLeft = Math.min(Math.max(placedLeft, CONTEXT_MENU_VIEWPORT_MARGIN), maxLeft);
        placedTop = Math.min(Math.max(placedTop, CONTEXT_MENU_VIEWPORT_MARGIN), maxTop);

        menu.style.left = placedLeft + 'px';
        menu.style.top = placedTop + 'px';
    }

    /**
     * @brief Close a previously opened row context menu and restore its state.
     * @param {HTMLElement|null} menu Dropdown menu element to close.
     * @return {void}
     * @date 2026-05-06
     * @author Stephane H.
     */
    function closeRowContextMenu(menu) {
        if (!menu) {
            return;
        }
        menu.classList.remove('files-row-context-menu', 'show');
        clearContextMenuInlineStyles(menu);
        var labelledBy = menu.getAttribute('aria-labelledby') || '';
        if (labelledBy !== '') {
            var toggle = document.getElementById(labelledBy);
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            }
        }
    }

    /**
     * @brief Close all visible action menus (custom row context menu and opened dropdown toggles).
     * @param void No input parameter.
     * @return {void}
     * @date 2026-05-08
     * @author Stephane H.
     */
    function closeAllActionMenus() {
        var openedCustomMenus = document.querySelectorAll('.dropdown-menu.files-row-context-menu.show');
        openedCustomMenus.forEach(function (menu) {
            closeRowContextMenu(menu);
        });

        var actionToggles = document.querySelectorAll('.files-actions-dropdown > .dropdown-toggle[aria-expanded="true"]');
        actionToggles.forEach(function (toggle) {
            if (window.bootstrap && window.bootstrap.Dropdown && typeof window.bootstrap.Dropdown.getOrCreateInstance === 'function') {
                window.bootstrap.Dropdown.getOrCreateInstance(toggle).hide();
            }
            toggle.setAttribute('aria-expanded', 'false');
        });
    }

    /**
     * @brief Open the action dropdown menu linked to one toggle at viewport-safe coords.
     * @param {HTMLElement|null} toggle Dropdown toggle bound to the context menu.
     * @param {number} clickX Viewport X coordinate of the click.
     * @param {number} clickY Viewport Y coordinate of the click.
     * @return {HTMLElement|null} The menu element when shown, otherwise null.
     * @date 2026-05-06
     * @author Stephane H.
     */
    function openContextMenuFromToggle(toggle, clickX, clickY) {
        if (!toggle) {
            return null;
        }
        var labelledBy = toggle.getAttribute('id') || '';
        if (labelledBy === '') {
            return null;
        }
        var menu = document.querySelector('[aria-labelledby="' + labelledBy + '"]');
        if (!menu) {
            return null;
        }
        clearContextMenuInlineStyles(menu);
        menu.classList.add('files-row-context-menu', 'show');
        positionContextMenuInViewport(menu, clickX, clickY);
        toggle.setAttribute('aria-expanded', 'true');
        return menu;
    }

    /**
     * @brief Open the action dropdown menu of a file row at viewport-safe coords.
     * @param {string} fileId Identifier matching the row's dropdown toggle id suffix.
     * @param {number} clickX Viewport X coordinate of the click.
     * @param {number} clickY Viewport Y coordinate of the click.
     * @return {HTMLElement|null} The menu element when shown, otherwise null.
     * @date 2026-05-06
     * @author Stephane H.
     */
    function openRowContextMenuAt(fileId, clickX, clickY) {
        var toggle = document.getElementById('files-actions-' + fileId);
        return openContextMenuFromToggle(toggle, clickX, clickY);
    }

    /**
     * @brief Tell whether a target inside a row should skip context-menu and long-press opening.
     *        Includes the listing action menu scroll panel so scrollbar interactions stay ignored.
     *        Does not ignore [data-files-media-preview-trigger] so right-click on previewable cells
     *        can open row actions; primary preview remains handled by media-preview.js on click.
     * @param {EventTarget|null} target Event target element.
     * @return {boolean}
     * @date 2026-05-02
     * @author Stephane H.
     */
    function shouldIgnoreRowActionTarget(target) {
        if (!target || !target.closest) {
            return false;
        }
        return !!target.closest(
            'input[type="checkbox"], label, .files-actions-dropdown, .dropdown-menu, .files-dropdown-menu-scroll, .dropdown-item, a, button'
        );
    }

    /**
     * @brief Open row context menu for one row target element.
     * @param {Element|null} cell Row target cell/card element.
     * @param {number} clickX Viewport X coordinate.
     * @param {number} clickY Viewport Y coordinate.
     * @return {void}
     * @date 2026-05-06
     * @author Stephane H.
     */
    function openRowContextMenuForCell(cell, clickX, clickY) {
        if (!cell) {
            return;
        }
        var fileId = cell.getAttribute('data-files-row-target') || '';
        if (fileId === '') {
            return;
        }

        var openedMenus = document.querySelectorAll('.dropdown-menu.files-row-context-menu.show');
        openedMenus.forEach(function (m) {
            closeRowContextMenu(m);
        });

        var menu = openRowContextMenuAt(fileId, clickX, clickY);
        bindContextMenuCleanup(menu);
    }

    /**
     * @brief Attach one-shot cleanup listeners for a currently opened context menu.
     * @param {HTMLElement|null} menu Opened context menu element.
     * @return {void}
     * @date 2026-05-06
     * @author Stephane H.
     */
    function bindContextMenuCleanup(menu) {
        if (!menu) {
            return;
        }

        function cleanupMenu() {
            closeRowContextMenu(menu);
            document.removeEventListener('mousedown', onOutsideMouseDown, true);
            document.removeEventListener('keydown', onEscapeKey, true);
            window.removeEventListener('scroll', onScrollOrResize, true);
            window.removeEventListener('resize', onScrollOrResize);
            menu.removeEventListener('click', onMenuClick);
        }

        function onOutsideMouseDown(e) {
            if (!menu.contains(e.target)) {
                cleanupMenu();
            }
        }

        function onEscapeKey(e) {
            if (e.key === 'Escape') {
                cleanupMenu();
            }
        }

        function onScrollOrResize() {
            cleanupMenu();
        }

        function onMenuClick() {
            window.setTimeout(cleanupMenu, 0);
        }

        window.setTimeout(function () {
            document.addEventListener('mousedown', onOutsideMouseDown, true);
            document.addEventListener('keydown', onEscapeKey, true);
            window.addEventListener('scroll', onScrollOrResize, true);
            window.addEventListener('resize', onScrollOrResize);
            menu.addEventListener('click', onMenuClick);
        }, 0);
    }

    document.addEventListener('contextmenu', function (event) {
        var cell = event.target && event.target.closest
            ? event.target.closest('[data-files-row-target]')
            : null;
        var ignored = shouldIgnoreRowActionTarget(event.target);
        if (!cell) {
            return;
        }
        if (ignored) {
            return;
        }
        event.preventDefault();
        openRowContextMenuForCell(cell, event.clientX, event.clientY);
    });

    document.addEventListener('contextmenu', function (event) {
        var folderTarget = event.target && event.target.closest
            ? event.target.closest('[data-files-folder-open-url]')
            : null;
        if (!folderTarget || shouldIgnoreRowActionTarget(event.target)) {
            return;
        }
        event.preventDefault();

        var row = folderTarget.closest ? folderTarget.closest('tr') : null;
        var card = !row && folderTarget.closest ? folderTarget.closest('.files-grid-card') : null;
        var toggle = row && row.querySelector
            ? row.querySelector('[id^="files-folder-actions-"], [id^="files-shared-folder-actions-"]')
            : null;
        if (!toggle && card && card.querySelector) {
            toggle = card.querySelector('[id^="files-folder-actions-"], [id^="files-shared-folder-actions-"]');
        }
        if (!toggle) {
            return;
        }
        var openedMenus = document.querySelectorAll('.dropdown-menu.files-row-context-menu.show');
        openedMenus.forEach(function (m) {
            closeRowContextMenu(m);
        });

        var menu = openContextMenuFromToggle(toggle, event.clientX, event.clientY);
        bindContextMenuCleanup(menu);
    });

    var longPressTimer = null;
    var longPressStartX = 0;
    var longPressStartY = 0;
    var longPressCell = null;
    var longPressPointerId = null;
    var LONG_PRESS_MS = 500;
    var LONG_PRESS_MOVE_TOLERANCE = 10;

    /**
     * @brief Clear pending long-press state for touch context menu opening.
     * @param void No input parameter.
     * @return {void}
     * @date 2026-04-29
     * @author Stephane H.
     */
    function clearLongPressState() {
        if (longPressTimer) {
            window.clearTimeout(longPressTimer);
            longPressTimer = null;
        }
        longPressPointerId = null;
        longPressCell = null;
    }

    document.addEventListener('pointerdown', function (event) {
        if (event.pointerType !== 'touch') {
            return;
        }
        var cell = event.target && event.target.closest
            ? event.target.closest('[data-files-row-target]')
            : null;
        if (!cell || shouldIgnoreRowActionTarget(event.target)) {
            clearLongPressState();
            return;
        }
        clearLongPressState();
        longPressCell = cell;
        longPressPointerId = event.pointerId;
        longPressStartX = event.clientX;
        longPressStartY = event.clientY;
        longPressTimer = window.setTimeout(function () {
            if (!longPressCell) {
                return;
            }
            openRowContextMenuForCell(longPressCell, longPressStartX, longPressStartY);
            clearLongPressState();
        }, LONG_PRESS_MS);
    }, { passive: true });

    document.addEventListener('pointermove', function (event) {
        if (longPressPointerId === null || event.pointerId !== longPressPointerId) {
            return;
        }
        var dx = Math.abs(event.clientX - longPressStartX);
        var dy = Math.abs(event.clientY - longPressStartY);
        if (dx > LONG_PRESS_MOVE_TOLERANCE || dy > LONG_PRESS_MOVE_TOLERANCE) {
            clearLongPressState();
        }
    }, { passive: true });

    document.addEventListener('pointerup', function (event) {
        if (longPressPointerId !== null && event.pointerId === longPressPointerId) {
            clearLongPressState();
        }
    }, { passive: true });

    document.addEventListener('pointercancel', function (event) {
        if (longPressPointerId !== null && event.pointerId === longPressPointerId) {
            clearLongPressState();
        }
    }, { passive: true });

    window.addEventListener('scroll', clearLongPressState, true);

    /**
     * @brief Open row context menu with keyboard activation on grid/list row action targets.
     * @param {KeyboardEvent} event Keyboard event.
     * @return {void}
     * @date 2026-04-28
     * @author Stephane H.
     */
    function handleRowTargetKeyboardOpen(event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }
        var target = event.target;
        var rowTarget = target && target.closest ? target.closest('[data-files-row-target]') : null;
        if (!rowTarget || shouldIgnoreRowActionTarget(target)) {
            return;
        }
        event.preventDefault();
        var rect = rowTarget.getBoundingClientRect();
        var x = Math.floor(rect.left + rect.width / 2);
        var y = Math.floor(rect.top + rect.height / 2);
        var fileId = rowTarget.getAttribute('data-files-row-target') || '';
        if (fileId === '') {
            return;
        }
        var openedMenus = document.querySelectorAll('.dropdown-menu.files-row-context-menu.show');
        openedMenus.forEach(function (menu) {
            closeRowContextMenu(menu);
        });
        openRowContextMenuAt(fileId, x, y);
    }

    document.addEventListener('keydown', handleRowTargetKeyboardOpen);

    /**
     * @brief Open folder targets on simple click for desktop and mobile while
     *        keeping interactive controls excluded via shouldIgnoreRowActionTarget.
     * @param {MouseEvent} event Click event.
     * @return {void}
     * @date 2026-04-29
     * @author Stephane H.
     */
    document.addEventListener('click', function (event) {
        var target = event.target && event.target.closest ? event.target.closest('[data-files-folder-open-url]') : null;
        if (!target) {
            return;
        }
        if (shouldIgnoreRowActionTarget(event.target)) {
            return;
        }
        event.preventDefault();
        var url = target.getAttribute('data-files-folder-open-url') || '';
        if (url !== '') {
            window.location.href = url;
        }
    });

    document.addEventListener('click', function (event) {
        var trigger = event.target && event.target.closest ? event.target.closest('[data-files-folder-delete-open]') : null;
        if (!trigger) {
            return;
        }
        event.preventDefault();
        var modalEl = document.getElementById('filesDeleteFolderModal');
        var formEl = document.getElementById('files-delete-folder-form');
        var nameEl = document.getElementById('files-delete-folder-name');
        var csrfEl = document.getElementById('files-delete-folder-csrf');
        if (!modalEl || !formEl || !nameEl || !csrfEl) {
            return;
        }
        formEl.action = trigger.getAttribute('data-files-folder-delete-action') || '#';
        csrfEl.value = trigger.getAttribute('data-files-folder-delete-csrf') || '';
        nameEl.textContent = trigger.getAttribute('data-files-folder-name') || '';
        var subjHidden = document.getElementById('files-delete-folder-subject-user');
        if (subjHidden) {
            var paneHost = trigger.closest ? trigger.closest('[data-files-subject-user-id]') : null;
            var sid = paneHost ? String(paneHost.getAttribute('data-files-subject-user-id') || '').trim() : '';
            subjHidden.value = sid;
        }
        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    });

    var extractZipPollTimer = null;
    var extractZipActiveJobId = '';
    var extractZipBusy = false;
    var extractZipTickInFlight = false;
    var EXTRACT_ZIP_POLL_DELAY_MS = 2000;

    /**
     * @brief Stop scheduled extraction tick polling.
     * @return {void}
     * @date 2026-06-24
     * @author Stephane H.
     */
    function stopExtractZipPolling() {
        if (extractZipPollTimer !== null) {
            window.clearTimeout(extractZipPollTimer);
            extractZipPollTimer = null;
        }
    }

    /**
     * @brief Schedule the next extraction tick when a job is still active.
     * @param {HTMLElement} modalEl Modal root.
     * @param {HTMLFormElement} formEl Extraction form.
     * @param {number} [delayMs] Delay before the next tick.
     * @return {void}
     * @date 2026-06-24
     * @author Stephane H.
     */
    function scheduleExtractZipTick(modalEl, formEl, delayMs) {
        stopExtractZipPolling();
        if (extractZipActiveJobId === '') {
            return;
        }
        var waitMs = typeof delayMs === 'number' && delayMs >= 0 ? delayMs : EXTRACT_ZIP_POLL_DELAY_MS;
        extractZipPollTimer = window.setTimeout(function () {
            extractZipPollTimer = null;
            postExtractZipTick(modalEl, formEl);
        }, waitMs);
    }

    /**
     * @brief Open the ZIP extraction modal for one owned file row.
     * @param {HTMLElement} trigger Dropdown trigger button.
     * @return {void}
     * @date 2026-06-24
     * @author Stephane H.
     */
    function openExtractZipModal(trigger) {
        var modalEl = document.getElementById('filesExtractZipModal');
        var formEl = document.getElementById('files-extract-zip-form');
        var nameEl = modalEl ? modalEl.querySelector('[data-files-extract-target-name]') : null;
        var optionsEl = modalEl ? modalEl.querySelector('[data-files-extract-options]') : null;
        var progressWrap = document.getElementById('files-extract-progress-wrap');
        var errorEl = document.getElementById('files-extract-error');
        var submitBtn = formEl ? formEl.querySelector('[data-files-extract-submit]') : null;
        if (!modalEl || !formEl || !window.bootstrap || !window.bootstrap.Modal) {
            return;
        }
        var fileId = Number(trigger.getAttribute('data-files-row-id') || '0');
        if (fileId < 1) {
            return;
        }
        extractZipActiveJobId = '';
        extractZipBusy = false;
        extractZipTickInFlight = false;
        stopExtractZipPolling();
        var startTemplate = modalEl.getAttribute('data-files-extract-start-url-template') || '';
        formEl.action = startTemplate.replace('999999', String(fileId));
        if (nameEl) {
            nameEl.textContent = trigger.getAttribute('data-files-row-name') || '';
        }
        if (optionsEl) {
            optionsEl.classList.remove('d-none');
        }
        if (progressWrap) {
            progressWrap.classList.add('d-none');
        }
        if (errorEl) {
            errorEl.classList.add('d-none');
            errorEl.textContent = '';
        }
        if (submitBtn) {
            submitBtn.disabled = false;
        }
        var modeHere = document.getElementById('files-extract-mode-here');
        if (modeHere) {
            modeHere.checked = true;
        }
        var conflictAbort = document.getElementById('files-extract-conflict-abort');
        if (conflictAbort) {
            conflictAbort.checked = true;
        }
        var deleteZip = document.getElementById('files-extract-delete-zip');
        if (deleteZip) {
            deleteZip.checked = false;
        }
        loadExtractZipPreflight(modalEl, fileId);
        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    /**
     * @brief Fetch and render extraction limits for one ZIP file.
     * @param {HTMLElement} modalEl Modal root.
     * @param {number} fileId Shared file id.
     * @return {void}
     * @date 2026-06-24
     * @author Stephane H.
     */
    function loadExtractZipPreflight(modalEl, fileId) {
        var preflightRoot = modalEl.querySelector('[data-files-extract-preflight]');
        var loadingEl = modalEl.querySelector('[data-files-extract-preflight-loading]');
        var bodyEl = modalEl.querySelector('[data-files-extract-preflight-body]');
        var zipSizeEl = modalEl.querySelector('[data-files-extract-zip-size]');
        var maxUncompressedEl = modalEl.querySelector('[data-files-extract-max-uncompressed]');
        var maxFilesEl = modalEl.querySelector('[data-files-extract-max-files]');
        var adminTierEl = modalEl.querySelector('[data-files-extract-admin-tier]');
        var submitBtn = modalEl.querySelector('[data-files-extract-submit]');
        var template = modalEl.getAttribute('data-files-extract-preflight-url-template') || '';
        if (template === '' || fileId < 1) {
            return;
        }
        if (loadingEl) {
            loadingEl.classList.remove('d-none');
        }
        if (bodyEl) {
            bodyEl.classList.add('d-none');
        }
        if (submitBtn) {
            submitBtn.disabled = true;
        }
        fetch(template.replace('999999', String(fileId)), {
            method: 'GET',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (json) {
                return { ok: response.ok, json: json };
            });
        }).then(function (pack) {
            if (loadingEl) {
                loadingEl.classList.add('d-none');
            }
            if (!pack.ok || !pack.json || pack.json.status !== 'ok') {
                if (preflightRoot) {
                    preflightRoot.textContent = modalEl.getAttribute('data-msg-error') || '';
                }
                return;
            }
            if (bodyEl) {
                bodyEl.classList.remove('d-none');
            }
            if (zipSizeEl) {
                zipSizeEl.textContent = pack.json.zip_file_bytes_formatted || String(pack.json.zip_file_bytes || 0);
            }
            if (maxUncompressedEl) {
                maxUncompressedEl.textContent = pack.json.max_uncompressed_bytes_formatted || String(pack.json.max_uncompressed_bytes || 0);
            }
            if (maxFilesEl) {
                maxFilesEl.textContent = String(pack.json.max_file_count || 0);
            }
            if (adminTierEl) {
                if (pack.json.limits_tier === 'admin') {
                    adminTierEl.classList.remove('d-none');
                } else {
                    adminTierEl.classList.add('d-none');
                }
            }
            if (submitBtn) {
                submitBtn.disabled = false;
            }
        }).catch(function () {
            if (loadingEl) {
                loadingEl.classList.add('d-none');
            }
            if (preflightRoot) {
                preflightRoot.textContent = modalEl.getAttribute('data-msg-error') || '';
            }
        });
    }

    /**
     * @brief Update extraction progress UI from tick payload.
     * @param {HTMLElement} modalEl Modal root.
     * @param {object} payload Tick JSON payload.
     * @return {void}
     * @date 2026-06-24
     * @author Stephane H.
     */
    function updateExtractZipProgressUi(modalEl, payload) {
        var progressWrap = document.getElementById('files-extract-progress-wrap');
        var barEl = document.getElementById('files-extract-progress-bar');
        var progressEl = document.getElementById('files-extract-progress');
        var phaseEl = document.getElementById('files-extract-phase');
        var countEl = document.getElementById('files-extract-count');
        var currentEl = document.getElementById('files-extract-current');
        var percent = Number(payload.percent || 0);
        if (progressWrap) {
            progressWrap.classList.remove('d-none');
        }
        if (phaseEl) {
            var phaseKey = String(payload.phase || '');
            var progressLabel = modalEl.getAttribute('data-msg-progress') || '';
            if (phaseKey === 'pending') {
                progressLabel = modalEl.getAttribute('data-msg-phase-pending') || progressLabel;
            } else if (phaseKey === 'scanning') {
                progressLabel = modalEl.getAttribute('data-msg-phase-scanning') || progressLabel;
            }
            phaseEl.textContent = progressLabel;
        }
        if (barEl) {
            barEl.style.width = String(Math.max(0, Math.min(100, percent))) + '%';
        }
        if (progressEl) {
            progressEl.setAttribute('aria-valuenow', String(Math.max(0, Math.min(100, percent))));
        }
        if (countEl) {
            if (Number(payload.total || 0) > 0) {
                countEl.textContent = String(payload.extracted || 0) + ' / ' + String(payload.total || 0);
                if (Number(payload.skipped || 0) > 0) {
                    countEl.textContent += ' (' + String(payload.skipped || 0) + ' skipped)';
                }
            } else {
                countEl.textContent = '';
            }
        }
        if (currentEl) {
            currentEl.textContent = payload.current_entry ? String(payload.current_entry) : '';
        }
    }

    /**
     * @brief POST one extraction tick and handle terminal states.
     * @param {HTMLElement} modalEl Modal root.
     * @param {HTMLFormElement} formEl Extraction form.
     * @param {string} jobId Active job id.
     * @return {void}
     * @date 2026-06-24
     * @author Stephane H.
     */
    function postExtractZipTick(modalEl, formEl) {
        if (extractZipActiveJobId === '' || extractZipTickInFlight) {
            return;
        }
        extractZipTickInFlight = true;
        var jobId = extractZipActiveJobId;
        var tickTemplate = modalEl.getAttribute('data-files-extract-tick-url-template') || '';
        var tickUrl = tickTemplate.replace('00000000000000000000000000000000', jobId);
        var csrfInput = formEl.querySelector('[data-files-extract-csrf-input]');
        var body = new URLSearchParams();
        if (csrfInput) {
            body.append('_csrf_token', csrfInput.value || '');
        }
        var fd = new FormData(formEl);
        fd.forEach(function (value, key) {
            if (key !== '_csrf_token') {
                body.append(key, String(value));
            }
        });
        fetch(tickUrl, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json'
            },
            body: body,
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (json) {
                return { ok: response.ok, json: json };
            });
        }).then(function (pack) {
            extractZipTickInFlight = false;
            var payload = pack.json || {};
            if (pack.ok && payload.status === 'ok') {
                updateExtractZipProgressUi(modalEl, payload);
                if (payload.done) {
                    extractZipBusy = false;
                    extractZipActiveJobId = '';
                    stopExtractZipPolling();
                    if (payload.phase === 'done') {
                        if (window.bootstrap && window.bootstrap.Modal) {
                            window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                        }
                        if (window.AppFlashToasts && typeof window.AppFlashToasts.push === 'function' && payload.message) {
                            window.AppFlashToasts.push('success', String(payload.message));
                        }
                        if (searchInput) {
                            runPartialFetch(searchInput.value.trim());
                        } else {
                            runPartialFetch('');
                        }
                        return;
                    }
                    if (payload.phase === 'failed' || payload.phase === 'cancelled') {
                        var errorEl = document.getElementById('files-extract-error');
                        var submitBtn = formEl.querySelector('[data-files-extract-submit]');
                        if (errorEl) {
                            errorEl.textContent = payload.message || modalEl.getAttribute('data-msg-error') || '';
                            errorEl.classList.remove('d-none');
                        }
                        if (submitBtn) {
                            submitBtn.disabled = false;
                        }
                    }
                    return;
                }
                scheduleExtractZipTick(modalEl, formEl);
                return;
            }
            extractZipBusy = false;
            extractZipActiveJobId = '';
            stopExtractZipPolling();
            var errorElFail = document.getElementById('files-extract-error');
            var submitBtnFail = formEl.querySelector('[data-files-extract-submit]');
            if (errorElFail) {
                errorElFail.textContent = (payload && payload.message) || modalEl.getAttribute('data-msg-error') || '';
                errorElFail.classList.remove('d-none');
            }
            if (submitBtnFail) {
                submitBtnFail.disabled = false;
            }
        }).catch(function () {
            extractZipTickInFlight = false;
            extractZipBusy = false;
            extractZipActiveJobId = '';
            stopExtractZipPolling();
            var errorElNet = document.getElementById('files-extract-error');
            if (errorElNet) {
                errorElNet.textContent = modalEl.getAttribute('data-msg-error') || '';
                errorElNet.classList.remove('d-none');
            }
        });
    }

    /**
     * @brief Cancel an active extraction job when the modal closes.
     * @param {HTMLElement} modalEl Modal root.
     * @param {HTMLFormElement} formEl Extraction form.
     * @return {void}
     * @date 2026-06-24
     * @author Stephane H.
     */
    function cancelExtractZipJob(modalEl, formEl) {
        if (extractZipActiveJobId === '') {
            return;
        }
        var cancelTemplate = modalEl.getAttribute('data-files-extract-cancel-url-template') || '';
        var cancelUrl = cancelTemplate.replace('00000000000000000000000000000000', extractZipActiveJobId);
        var csrfInput = formEl.querySelector('[data-files-extract-csrf-input]');
        var body = new URLSearchParams();
        if (csrfInput) {
            body.append('_csrf_token', csrfInput.value || '');
        }
        fetch(cancelUrl, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json'
            },
            body: body,
            credentials: 'same-origin'
        }).catch(function () {
            return null;
        });
        extractZipActiveJobId = '';
        extractZipBusy = false;
    }

    var extractZipForm = document.getElementById('files-extract-zip-form');
    var extractZipModal = document.getElementById('filesExtractZipModal');
    if (extractZipForm && extractZipModal) {
        extractZipForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            if (extractZipBusy) {
                return;
            }
            var optionsEl = extractZipModal.querySelector('[data-files-extract-options]');
            var errorEl = document.getElementById('files-extract-error');
            var submitBtn = extractZipForm.querySelector('[data-files-extract-submit]');
            if (errorEl) {
                errorEl.classList.add('d-none');
                errorEl.textContent = '';
            }
            if (submitBtn) {
                submitBtn.disabled = true;
            }
            extractZipBusy = true;
            var fd = new FormData(extractZipForm);
            var body = new URLSearchParams();
            fd.forEach(function (value, key) {
                if (key === 'delete_zip') {
                    body.append(key, '1');
                } else {
                    body.append(key, String(value));
                }
            });
            if (!fd.has('delete_zip')) {
                body.append('delete_zip', '0');
            }
            fetch(extractZipForm.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json'
                },
                body: body,
                credentials: 'same-origin'
            }).then(function (response) {
                return response.json().catch(function () {
                    return {};
                }).then(function (json) {
                    return { ok: response.ok, json: json };
                });
            }).then(function (pack) {
                if (!pack.ok || !pack.json || pack.json.status !== 'ok' || !pack.json.job_id) {
                    extractZipBusy = false;
                    if (submitBtn) {
                        submitBtn.disabled = false;
                    }
                    if (errorEl) {
                        errorEl.textContent = (pack.json && pack.json.message) || extractZipModal.getAttribute('data-msg-error') || '';
                        errorEl.classList.remove('d-none');
                    }
                    return;
                }
                if (optionsEl) {
                    optionsEl.classList.add('d-none');
                }
                var preflightEl = extractZipModal.querySelector('[data-files-extract-preflight]');
                if (preflightEl) {
                    preflightEl.classList.add('d-none');
                }
                extractZipActiveJobId = String(pack.json.job_id);
                updateExtractZipProgressUi(extractZipModal, {
                    phase: pack.json.phase || 'pending',
                    percent: 0,
                    extracted: 0,
                    skipped: 0,
                    total: pack.json.total_entries || 0,
                    current_entry: ''
                });
                postExtractZipTick(extractZipModal, extractZipForm);
            }).catch(function () {
                extractZipBusy = false;
                if (submitBtn) {
                    submitBtn.disabled = false;
                }
                if (errorEl) {
                    errorEl.textContent = extractZipModal.getAttribute('data-msg-error') || '';
                    errorEl.classList.remove('d-none');
                }
            });
        });

        extractZipModal.addEventListener('hide.bs.modal', function (event) {
            if (extractZipActiveJobId === '' || !extractZipBusy) {
                return;
            }
            var confirmMsg = extractZipModal.getAttribute('data-msg-cancel-confirm') || '';
            if (confirmMsg !== '' && !window.confirm(confirmMsg)) {
                event.preventDefault();
                return;
            }
            stopExtractZipPolling();
            cancelExtractZipJob(extractZipModal, extractZipForm);
        });
    }

}());
