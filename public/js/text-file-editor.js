/**
 * @file In-browser text file editor (CodeMirror 6) for owned StuWebStorage files.
 */
(function () {
    'use strict';

    var MODAL_ID = 'textFileEditorModal';
    var MOUNT_ID = 'textFileEditorMount';
    var editorView = null;
    var editorModulesPromise = null;
    var loadAbortController = null;
    var isDirty = false;
    var suppressDirty = false;
    var currentFileId = 0;
    var currentExtension = '';
    var initialContent = '';

    /**
     * @brief Replace placeholder id in Symfony-generated URL templates.
     * @param {string} template URL template containing 999999.
     * @param {number} fileId Shared file id.
     * @return {string}
     * @date 2026-06-26
     * @author Stephane H.
     */
    function resolveUrlTemplate(template, fileId) {
        return String(template || '').replace('999999', String(fileId));
    }

    /**
     * @brief Read a data attribute from the editor modal root element.
     * @param {string} name Data attribute suffix without data- prefix.
     * @return {string}
     * @date 2026-06-26
     * @author Stephane H.
     */
    function modalData(name) {
        var modalEl = document.getElementById(MODAL_ID);
        if (!modalEl) {
            return '';
        }

        return modalEl.getAttribute('data-text-editor-' + name) || '';
    }

    /**
     * @brief Lazy-load CodeMirror modules for the requested file extension.
     * @param {string} extension Lowercase file extension.
     * @return {Promise<{EditorView: Function, basicSetup: unknown, EditorState: Function, langExtension: unknown|null}>}
     * @date 2026-06-26
     * @author Stephane H.
     */
    function loadEditorModules(extension) {
        if (!editorModulesPromise) {
            editorModulesPromise = (async function () {
                var base = 'https://esm.sh';
                var viewPkg = await import(base + '/codemirror@6.0.1');
                var statePkg = await import(base + '/@codemirror/state@6.4.1');
                var langByExt = {
                    md: () => import(base + '/@codemirror/lang-markdown@6.3.2').then(function (m) { return m.markdown(); }),
                    markdown: () => import(base + '/@codemirror/lang-markdown@6.3.2').then(function (m) { return m.markdown(); }),
                    json: () => import(base + '/@codemirror/lang-json@6.0.1').then(function (m) { return m.json(); }),
                    yaml: () => import(base + '/@codemirror/lang-yaml@6.1.2').then(function (m) { return m.yaml(); }),
                    yml: () => import(base + '/@codemirror/lang-yaml@6.1.2').then(function (m) { return m.yaml(); }),
                    xml: () => import(base + '/@codemirror/lang-xml@6.1.0').then(function (m) { return m.xml(); }),
                };
                var ext = String(extension || '').toLowerCase();
                var langLoader = langByExt[ext];
                var langExtension = langLoader ? await langLoader() : null;

                return {
                    EditorView: viewPkg.EditorView,
                    basicSetup: viewPkg.basicSetup,
                    EditorState: statePkg.EditorState,
                    langExtension: langExtension,
                };
            })();
        }

        return editorModulesPromise;
    }

    /**
     * @brief Destroy the active CodeMirror view if present.
     * @return {void}
     * @date 2026-06-26
     * @author Stephane H.
     */
    function destroyEditorView() {
        if (editorView) {
            editorView.destroy();
            editorView = null;
        }
        var mountEl = document.getElementById(MOUNT_ID);
        if (mountEl) {
            mountEl.textContent = '';
        }
    }

    /**
     * @brief Toggle unsaved badge and save button state.
     * @param {boolean} dirty Whether content diverges from last load/save.
     * @return {void}
     * @date 2026-06-26
     * @author Stephane H.
     */
    function setDirtyState(dirty) {
        isDirty = dirty;
        var badge = document.getElementById('textFileEditorUnsavedBadge');
        var saveBtn = document.getElementById('textFileEditorSaveBtn');
        if (badge) {
            badge.classList.toggle('d-none', !dirty);
        }
        if (saveBtn) {
            saveBtn.disabled = !dirty;
        }
    }

    /**
     * @brief Update Markdown preview pane when extension supports it.
     * @param {string} content Current editor plaintext.
     * @return {void}
     * @date 2026-06-26
     * @author Stephane H.
     */
    function refreshMarkdownPreview(content) {
        var previewEl = document.getElementById('textFileEditorMarkdownPreview');
        if (!previewEl) {
            return;
        }
        if (currentExtension !== 'md' && currentExtension !== 'markdown') {
            previewEl.textContent = '';
            return;
        }
        if (window.marked && typeof window.marked.parse === 'function') {
            previewEl.innerHTML = window.marked.parse(content);
            return;
        }
        previewEl.textContent = content;
    }

    /**
     * @brief Show or hide Markdown preview tabs based on extension.
     * @param {string} extension Lowercase file extension.
     * @return {void}
     * @date 2026-06-26
     * @author Stephane H.
     */
    function configureMarkdownTabs(extension) {
        var tabsEl = document.getElementById('textFileEditorTabs');
        var isMarkdown = extension === 'md' || extension === 'markdown';
        if (tabsEl) {
            tabsEl.classList.toggle('d-none', !isMarkdown);
        }
        if (isMarkdown) {
            var editTabBtn = document.getElementById('textFileEditorTabEditBtn');
            if (editTabBtn && window.bootstrap && window.bootstrap.Tab) {
                window.bootstrap.Tab.getOrCreateInstance(editTabBtn).show();
            }
        }
    }

    /**
     * @brief Hide inline error alerts in the editor modal.
     * @return {void}
     * @date 2026-06-26
     * @author Stephane H.
     */
    function hideErrors() {
        var loadError = document.getElementById('textFileEditorLoadError');
        var saveError = document.getElementById('textFileEditorSaveError');
        if (loadError) {
            loadError.classList.add('d-none');
            loadError.textContent = '';
        }
        if (saveError) {
            saveError.classList.add('d-none');
            saveError.textContent = '';
        }
    }

    /**
     * @brief Mount CodeMirror with loaded plaintext content.
     * @param {string} content File body.
     * @param {string} extension Lowercase extension.
     * @return {Promise<void>}
     * @date 2026-06-26
     * @author Stephane H.
     */
    async function mountEditor(content, extension) {
        destroyEditorView();
        var modules = await loadEditorModules(extension);
        var mountEl = document.getElementById(MOUNT_ID);
        if (!mountEl) {
            return;
        }

        var extensions = [modules.basicSetup];
        if (modules.langExtension) {
            extensions.push(modules.langExtension);
        }
        extensions.push(modules.EditorView.updateListener.of(function (update) {
            if (!update.docChanged || suppressDirty) {
                return;
            }
            var value = update.state.doc.toString();
            setDirtyState(value !== initialContent);
            refreshMarkdownPreview(value);
        }));

        suppressDirty = true;
        editorView = new modules.EditorView({
            state: modules.EditorState.create({
                doc: content,
                extensions: extensions,
            }),
            parent: mountEl,
        });
        suppressDirty = false;
        initialContent = content;
        setDirtyState(false);
        refreshMarkdownPreview(content);
    }

    /**
     * @brief Load file content from files_preview and open the editor modal.
     * @param {{fileId: number, fileName: string, extension: string}} payload Open payload.
     * @return {Promise<void>}
     * @date 2026-06-26
     * @author Stephane H.
     */
    async function openEditor(payload) {
        var fileId = Number(payload && payload.fileId);
        if (fileId < 1) {
            return;
        }

        var modalEl = document.getElementById(MODAL_ID);
        if (!modalEl || !window.bootstrap || !window.bootstrap.Modal) {
            return;
        }

        if (loadAbortController) {
            loadAbortController.abort();
        }
        loadAbortController = new AbortController();

        currentFileId = fileId;
        currentExtension = String(payload.extension || '').toLowerCase();
        hideErrors();
        configureMarkdownTabs(currentExtension);

        var titleEl = document.getElementById('textFileEditorModalTitle');
        if (titleEl) {
            var titlePrefix = modalData('title');
            var fileName = String(payload.fileName || '');
            titleEl.textContent = titlePrefix !== '' && fileName !== ''
                ? titlePrefix + ': ' + fileName
                : (fileName !== '' ? fileName : titlePrefix);
        }

        destroyEditorView();
        setDirtyState(false);
        var saveBtn = document.getElementById('textFileEditorSaveBtn');
        if (saveBtn) {
            saveBtn.disabled = true;
        }

        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();

        var previewUrl = resolveUrlTemplate(modalEl.getAttribute('data-text-editor-preview-url-template') || '', fileId);
        try {
            var response = await fetch(previewUrl, {
                credentials: 'same-origin',
                signal: loadAbortController.signal,
                headers: { Accept: 'text/plain, */*;q=0.1' },
            });
            if (!response.ok) {
                throw new Error('load_failed');
            }
            var body = await response.text();
            if (loadAbortController.signal.aborted) {
                return;
            }
            await mountEditor(body, currentExtension);
        } catch (error) {
            if (loadAbortController.signal.aborted) {
                return;
            }
            var loadError = document.getElementById('textFileEditorLoadError');
            if (loadError) {
                loadError.textContent = modalData('load-error');
                loadError.classList.remove('d-none');
            }
        }
    }

    /**
     * @brief Persist editor content through files_content_save.
     * @return {Promise<void>}
     * @date 2026-06-26
     * @author Stephane H.
     */
    async function saveEditorContent() {
        if (!editorView || currentFileId < 1) {
            return;
        }

        var modalEl = document.getElementById(MODAL_ID);
        var saveBtn = document.getElementById('textFileEditorSaveBtn');
        var saveError = document.getElementById('textFileEditorSaveError');
        if (!modalEl) {
            return;
        }

        if (saveError) {
            saveError.classList.add('d-none');
            saveError.textContent = '';
        }
        if (saveBtn) {
            saveBtn.disabled = true;
        }

        var content = editorView.state.doc.toString();
        var saveUrl = resolveUrlTemplate(modalEl.getAttribute('data-text-editor-save-url-template') || '', currentFileId);
        var csrf = modalEl.getAttribute('data-text-editor-csrf') || '';

        try {
            var response = await fetch(saveUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'text/plain; charset=utf-8',
                    'X-CSRF-Token': csrf,
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: content,
            });
            var json = await response.json().catch(function () {
                return {};
            });
            if (!response.ok || !json || json.status !== 'ok') {
                var message = (json && json.message) ? String(json.message) : modalData('save-error');
                if (saveError) {
                    saveError.textContent = message;
                    saveError.classList.remove('d-none');
                }
                if (saveBtn) {
                    saveBtn.disabled = false;
                }
                return;
            }

            initialContent = content;
            setDirtyState(false);
            if (window.AppFlashToasts && typeof window.AppFlashToasts.push === 'function' && json.message) {
                window.AppFlashToasts.push('success', String(json.message));
            }
            document.dispatchEvent(new CustomEvent('files:text-content-saved', {
                detail: {
                    fileId: currentFileId,
                    byteSize: json.byte_size,
                    byteSizeFormatted: json.byte_size_formatted,
                    updatedAt: json.updated_at,
                },
            }));
            window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        } catch (error) {
            if (saveError) {
                saveError.textContent = modalData('save-error');
                saveError.classList.remove('d-none');
            }
            if (saveBtn) {
                saveBtn.disabled = false;
            }
        }
    }

    /**
     * @brief Bind modal lifecycle and save button listeners once.
     * @return {void}
     * @date 2026-06-26
     * @author Stephane H.
     */
    function bindModalEvents() {
        var modalEl = document.getElementById(MODAL_ID);
        var saveBtn = document.getElementById('textFileEditorSaveBtn');
        if (!modalEl) {
            return;
        }

        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                saveEditorContent();
            });
        }

        modalEl.addEventListener('hide.bs.modal', function (event) {
            if (!isDirty) {
                return;
            }
            var confirmMessage = modalData('close-confirm');
            if (confirmMessage !== '' && !window.confirm(confirmMessage)) {
                event.preventDefault();
            }
        });

        modalEl.addEventListener('hidden.bs.modal', function () {
            if (loadAbortController) {
                loadAbortController.abort();
                loadAbortController = null;
            }
            destroyEditorView();
            setDirtyState(false);
            currentFileId = 0;
            currentExtension = '';
            initialContent = '';
        });
    }

    window.TextFileEditor = {
        open: openEditor,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindModalEvents);
    } else {
        bindModalEvents();
    }
})();
