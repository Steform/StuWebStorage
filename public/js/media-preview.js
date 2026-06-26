/**
 * @file Global media preview modal (Bootstrap 5): images, PDF iframe, native video/audio, plain text (fetch).
 */
(function () {
    'use strict';

    var MODAL_ID = 'mediaPreviewModal';
    var TITLE_ID = 'mediaPreviewModalTitle';
    var IMG_ID = 'mediaPreviewImage';
    var PDF_FRAME_ID = 'mediaPreviewPdfFrame';
    var PDF_OPEN_TAB_ID = 'mediaPreviewPdfOpenTab';
    var VIDEO_ID = 'mediaPreviewVideo';
    var VIDEO_WRAP_ID = 'mediaPreviewVideoWrap';
    var AUDIO_ID = 'mediaPreviewAudio';
    var TEXT_ID = 'mediaPreviewText';
    var PLAYBACK_ERR_ID = 'mediaPreviewPlaybackError';
    var TEXT_LOAD_ERR_ID = 'mediaPreviewTextLoadError';
    var FULLSCREEN_BTN_ID = 'mediaPreviewVideoFullscreen';
    var TEXT_EDIT_BTN_ID = 'mediaPreviewTextEditBtn';

    /** @type {AbortController|null} */
    var textPreviewAbortController = null;

    /** @type {{ fileId: number, fileName: string, extension: string }|null} */
    var textEditContext = null;

    /**
     * @brief Query slot element by data-media-preview-role.
     * @param {string} role Role name (image, pdf, video, audio, text).
     * @return {HTMLElement|null}
     * @date 2026-05-06
     * @author Stephane H.
     */
    function getSlotByRole(role) {
        return document.querySelector('[data-media-preview-role="' + role + '"]');
    }

    /**
     * @brief Toggle visibility and aria-hidden for a preview slot.
     * @param {string} role Slot role (image, pdf, video, audio, text).
     * @param {boolean} visible When true, show the slot.
     * @return {void}
     * @date 2026-05-06
     * @author Stephane H.
     */
    function setSlotVisible(role, visible) {
        var slot = getSlotByRole(role);
        if (!slot) {
            return;
        }
        if (visible) {
            slot.classList.remove('d-none');
            slot.setAttribute('aria-hidden', 'false');
        } else {
            slot.classList.add('d-none');
            slot.setAttribute('aria-hidden', 'true');
        }
    }

    /**
     * @brief Show one preview slot; hide all others.
     * @param {string} type Normalized preview type (`image`, `pdf`, `video`, `audio`, `text`).
     * @return {void}
     * @date 2026-05-06
     * @author Stephane H.
     */
    function showSlotsForType(type) {
        setSlotVisible('image', type === 'image');
        setSlotVisible('pdf', type === 'pdf');
        setSlotVisible('video', type === 'video');
        setSlotVisible('audio', type === 'audio');
        setSlotVisible('text', type === 'text');
    }

    /**
     * @brief Hide the playback error alert.
     * @return {void}
     * @date 2026-05-06
     * @author Stephane H.
     */
    function hidePlaybackError() {
        var errEl = document.getElementById(PLAYBACK_ERR_ID);
        if (errEl) {
            errEl.classList.add('d-none');
        }
    }

    /**
     * @brief Show the playback error alert (browser cannot decode stream).
     * @return {void}
     * @date 2026-05-06
     * @author Stephane H.
     */
    function showPlaybackError() {
        var errEl = document.getElementById(PLAYBACK_ERR_ID);
        if (errEl) {
            errEl.classList.remove('d-none');
        }
    }

    /**
     * @brief Hide the text preview load error alert.
     * @return {void}
     * @date 2026-05-06
     * @author Stephane H.
     */
    function hideTextLoadError() {
        var errEl = document.getElementById(TEXT_LOAD_ERR_ID);
        if (errEl) {
            errEl.classList.add('d-none');
        }
    }

    /**
     * @brief Show the text preview load error alert (network or non-success HTTP status).
     * @return {void}
     * @date 2026-05-06
     * @author Stephane H.
     */
    function showTextLoadError() {
        var errEl = document.getElementById(TEXT_LOAD_ERR_ID);
        if (errEl) {
            errEl.classList.remove('d-none');
        }
    }

    /**
     * @brief Extract lowercase extension from a file name.
     * @param {string} fileName Original file name.
     * @return {string}
     * @date 2026-06-26
     * @author Stephane H.
     */
    function extensionFromFileName(fileName) {
        var name = String(fileName || '');
        var dot = name.lastIndexOf('.');
        return dot >= 0 ? name.slice(dot + 1).toLowerCase() : '';
    }

    /**
     * @brief Show or hide the text edit button in the preview modal header.
     * @param {string} type Normalized preview type.
     * @param {{ textEditable?: boolean, fileId?: number, fileName?: string, extension?: string }|null} context Edit context when type is text.
     * @return {void}
     * @date 2026-06-26
     * @author Stephane H.
     */
    function updateTextEditButton(type, context) {
        var btn = document.getElementById(TEXT_EDIT_BTN_ID);
        if (!btn) {
            return;
        }
        var canEdit = type === 'text'
            && context
            && context.textEditable === true
            && Number(context.fileId) > 0
            && window.TextFileEditor
            && typeof window.TextFileEditor.open === 'function';
        if (canEdit) {
            textEditContext = {
                fileId: Number(context.fileId),
                fileName: String(context.fileName || ''),
                extension: String(context.extension || ''),
            };
            btn.classList.remove('d-none');
        } else {
            textEditContext = null;
            btn.classList.add('d-none');
        }
    }

    /**
     * @brief Pause, clear src, and reload a media element.
     * @param {HTMLMediaElement|null} el Media element or null.
     * @return {void}
     * @date 2026-05-02
     * @author Stephane H.
     */
    function resetMediaElement(el) {
        if (!el) {
            return;
        }
        el.pause();
        el.removeAttribute('src');
        if (typeof el.load === 'function') {
            el.load();
        }
    }

    /**
     * @brief Exit document fullscreen if active.
     * @return {void}
     * @date 2026-05-02
     * @author Stephane H.
     */
    function exitFullscreenIfNeeded() {
        var doc = document;
        if (doc.fullscreenElement && doc.exitFullscreen) {
            doc.exitFullscreen().catch(function () {});
        } else if (doc.webkitFullscreenElement && doc.webkitExitFullscreen) {
            doc.webkitExitFullscreen();
        } else if (doc.msFullscreenElement && doc.msExitFullscreen) {
            doc.msExitFullscreen();
        }
    }

    /**
     * @brief Request fullscreen on the video wrapper element.
     * @return {void}
     * @date 2026-05-02
     * @author Stephane H.
     */
    function onVideoFullscreenClick() {
        var wrap = document.getElementById(VIDEO_WRAP_ID);
        if (!wrap) {
            return;
        }
        var req =
            wrap.requestFullscreen ||
            wrap.webkitRequestFullscreen ||
            wrap.msRequestFullscreen;
        if (req) {
            req.call(wrap);
        }
    }

    /**
     * @brief Open the media preview modal with the given payload.
     * @param {{ type?: string, src: string, title?: string, alt?: string }} payload Preview payload.
     * @return {void}
     * @date 2026-05-06
     * @author Stephane H.
     */
    function openMediaPreview(payload) {
        if (!payload || !payload.src) {
            return;
        }
        var typeRaw = payload.type != null && String(payload.type) !== '' ? String(payload.type) : 'image';
        var type = typeRaw.toLowerCase();
        if (type !== 'image' && type !== 'pdf' && type !== 'video' && type !== 'audio' && type !== 'text') {
            return;
        }

        var modalEl = document.getElementById(MODAL_ID);
        var titleEl = document.getElementById(TITLE_ID);
        var imgEl = document.getElementById(IMG_ID);
        var pdfFrame = document.getElementById(PDF_FRAME_ID);
        var pdfOpenTab = document.getElementById(PDF_OPEN_TAB_ID);
        var videoEl = /** @type {HTMLVideoElement|null} */ (document.getElementById(VIDEO_ID));
        var audioEl = /** @type {HTMLAudioElement|null} */ (document.getElementById(AUDIO_ID));
        var preEl = /** @type {HTMLPreElement|null} */ (document.getElementById(TEXT_ID));
        if (!modalEl || !window.bootstrap || !window.bootstrap.Modal) {
            return;
        }
        if (type === 'image' && !imgEl) {
            return;
        }
        if (type === 'pdf' && (!pdfFrame || !pdfOpenTab)) {
            return;
        }
        if (type === 'video' && !videoEl) {
            return;
        }
        if (type === 'audio' && !audioEl) {
            return;
        }
        if (type === 'text' && !preEl) {
            return;
        }

        updateTextEditButton(type, type === 'text' ? payload : null);

        if (type !== 'text' && textPreviewAbortController) {
            textPreviewAbortController.abort();
            textPreviewAbortController = null;
        }

        hidePlaybackError();
        hideTextLoadError();

        if (type !== 'text' && preEl) {
            preEl.textContent = '';
        }

        if (imgEl && type !== 'image') {
            imgEl.removeAttribute('src');
            imgEl.setAttribute('alt', '');
        }
        if (pdfFrame && type !== 'pdf') {
            pdfFrame.setAttribute('src', 'about:blank');
            var defPdfTitle = modalEl.getAttribute('data-media-preview-pdf-frame-title-default') || '';
            if (defPdfTitle !== '') {
                pdfFrame.setAttribute('title', defPdfTitle);
            }
        }
        if (pdfOpenTab && type !== 'pdf') {
            pdfOpenTab.setAttribute('href', '#');
        }
        resetMediaElement(videoEl);
        resetMediaElement(audioEl);

        showSlotsForType(type);

        if (type === 'text') {
            textPreviewAbortController = new AbortController();
            var signal = textPreviewAbortController.signal;
            preEl.textContent = '';
            var defTextAria = modalEl.getAttribute('data-media-preview-text-label') || '';
            if (payload.title != null && String(payload.title) !== '') {
                preEl.setAttribute('aria-label', String(payload.title));
            } else if (defTextAria !== '') {
                preEl.setAttribute('aria-label', defTextAria);
            }

            if (titleEl) {
                var defaultTitleText = modalEl.getAttribute('data-media-preview-default-title') || '';
                if (payload.title != null && String(payload.title) !== '') {
                    titleEl.textContent = String(payload.title);
                } else if (defaultTitleText !== '') {
                    titleEl.textContent = defaultTitleText;
                }
            }

            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();

            fetch(payload.src, {
                credentials: 'same-origin',
                signal: signal,
                headers: { Accept: 'text/plain, */*;q=0.1' },
            })
                .then(function (resp) {
                    if (!resp.ok) {
                        throw new Error('preview_http_error');
                    }
                    return resp.text();
                })
                .then(function (body) {
                    if (signal.aborted || !preEl) {
                        return;
                    }
                    preEl.textContent = body;
                })
                .catch(function () {
                    if (signal.aborted || !preEl) {
                        return;
                    }
                    preEl.textContent = '';
                    showTextLoadError();
                });

            return;
        }

        if (type === 'image') {
            imgEl.setAttribute('src', payload.src);
            imgEl.setAttribute('alt', payload.alt != null && payload.alt !== '' ? String(payload.alt) : '');
        } else if (type === 'pdf') {
            pdfFrame.setAttribute('src', payload.src);
            pdfOpenTab.setAttribute('href', payload.src);
            var defaultPdfTitle = modalEl.getAttribute('data-media-preview-pdf-frame-title-default') || '';
            if (payload.title != null && String(payload.title) !== '') {
                pdfFrame.setAttribute('title', String(payload.title));
            } else if (defaultPdfTitle !== '') {
                pdfFrame.setAttribute('title', defaultPdfTitle);
            }
        } else if (type === 'video') {
            var defVideoAria = modalEl.getAttribute('data-media-preview-video-label') || '';
            videoEl.setAttribute('src', payload.src);
            if (payload.title != null && String(payload.title) !== '') {
                videoEl.setAttribute('aria-label', String(payload.title));
            } else if (defVideoAria !== '') {
                videoEl.setAttribute('aria-label', defVideoAria);
            }
        } else if (type === 'audio') {
            var defAudioAria = modalEl.getAttribute('data-media-preview-audio-label') || '';
            audioEl.setAttribute('src', payload.src);
            if (payload.title != null && String(payload.title) !== '') {
                audioEl.setAttribute('aria-label', String(payload.title));
            } else if (defAudioAria !== '') {
                audioEl.setAttribute('aria-label', defAudioAria);
            }
        }

        if (titleEl) {
            var defaultTitle = modalEl.getAttribute('data-media-preview-default-title') || '';
            if (payload.title != null && String(payload.title) !== '') {
                titleEl.textContent = String(payload.title);
            } else if (defaultTitle !== '') {
                titleEl.textContent = defaultTitle;
            }
        }

        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    /**
     * @brief Clear sources and reset modal state on hide.
     * @return {void}
     * @date 2026-05-06
     * @author Stephane H.
     */
    function onModalHidden() {
        exitFullscreenIfNeeded();
        hidePlaybackError();
        hideTextLoadError();
        updateTextEditButton('', null);

        if (textPreviewAbortController) {
            textPreviewAbortController.abort();
            textPreviewAbortController = null;
        }

        var modalEl = document.getElementById(MODAL_ID);
        var titleEl = document.getElementById(TITLE_ID);
        var imgEl = document.getElementById(IMG_ID);
        var pdfFrame = document.getElementById(PDF_FRAME_ID);
        var pdfOpenTab = document.getElementById(PDF_OPEN_TAB_ID);
        var videoEl = /** @type {HTMLVideoElement|null} */ (document.getElementById(VIDEO_ID));
        var audioEl = /** @type {HTMLAudioElement|null} */ (document.getElementById(AUDIO_ID));
        var preEl = /** @type {HTMLPreElement|null} */ (document.getElementById(TEXT_ID));

        if (preEl) {
            preEl.textContent = '';
            var defTextAriaHide = modalEl ? modalEl.getAttribute('data-media-preview-text-label') || '' : '';
            if (defTextAriaHide !== '') {
                preEl.setAttribute('aria-label', defTextAriaHide);
            }
        }

        if (imgEl) {
            imgEl.removeAttribute('src');
            imgEl.setAttribute('alt', '');
        }
        if (pdfFrame) {
            pdfFrame.setAttribute('src', 'about:blank');
            var defPdfTitle = modalEl ? modalEl.getAttribute('data-media-preview-pdf-frame-title-default') || '' : '';
            if (defPdfTitle !== '') {
                pdfFrame.setAttribute('title', defPdfTitle);
            }
        }
        if (pdfOpenTab) {
            pdfOpenTab.setAttribute('href', '#');
        }
        resetMediaElement(videoEl);
        resetMediaElement(audioEl);
        if (modalEl && videoEl) {
            var defVideoAria = modalEl.getAttribute('data-media-preview-video-label') || '';
            if (defVideoAria !== '') {
                videoEl.setAttribute('aria-label', defVideoAria);
            }
        }
        if (modalEl && audioEl) {
            var defAudioAria = modalEl.getAttribute('data-media-preview-audio-label') || '';
            if (defAudioAria !== '') {
                audioEl.setAttribute('aria-label', defAudioAria);
            }
        }
        if (modalEl && titleEl) {
            var defaultTitle = modalEl.getAttribute('data-media-preview-default-title') || '';
            if (defaultTitle !== '') {
                titleEl.textContent = defaultTitle;
            }
        }
    }

    /**
     * @brief Resolve the element that received the click (handles text nodes).
     * @param {EventTarget|null} target Event target.
     * @return {Element|null}
     * @date 2026-05-02
     * @author Stephane H.
     */
    function getElementFromEventTarget(target) {
        if (!target) {
            return null;
        }
        if (target.nodeType === Node.ELEMENT_NODE) {
            return /** @type {Element} */ (target);
        }
        if (target.nodeType === Node.TEXT_NODE && target.parentElement) {
            return target.parentElement;
        }
        return null;
    }

    /**
     * @brief Map data-media-preview-type to internal type string.
     * @param {string} typeAttr Lowercase attribute value.
     * @return {string}
     * @date 2026-05-06
     * @author Stephane H.
     */
    function normalizePreviewTypeAttribute(typeAttr) {
        if (typeAttr === 'pdf') {
            return 'pdf';
        }
        if (typeAttr === 'video') {
            return 'video';
        }
        if (typeAttr === 'audio') {
            return 'audio';
        }
        if (typeAttr === 'text') {
            return 'text';
        }
        return 'image';
    }

    /**
     * @brief Build preview payload from a trigger element (dataset attributes).
     * @param {Element} trigger Element carrying data-src and optional type / alt.
     * @return {{ type: string, src: string, title?: string, alt?: string }|null}
     * @date 2026-05-06
     * @author Stephane H.
     */
    function buildPreviewPayloadFromTrigger(trigger) {
        var src = trigger.getAttribute('data-src') || '';
        if (src === '') {
            return null;
        }
        var typeAttr = (trigger.getAttribute('data-media-preview-type') || 'image').toLowerCase();
        var type = normalizePreviewTypeAttribute(typeAttr);
        var title = trigger.getAttribute('data-title') || '';
        var alt = trigger.getAttribute('data-alt') || '';
        /** @type {{ type: string, src: string, title?: string, alt?: string, textEditable?: boolean, fileId?: number, fileName?: string, extension?: string }} */
        var out = { type: type, src: src };
        if (title !== '') {
            out.title = title;
        }
        if (type === 'image' && alt !== '') {
            out.alt = alt;
        }
        if (type === 'text' && trigger.getAttribute('data-media-preview-text-editable') === '1') {
            var fileName = trigger.getAttribute('data-files-row-name') || title;
            out.textEditable = true;
            out.fileId = Number(trigger.getAttribute('data-files-row-id') || '0');
            out.fileName = fileName;
            out.extension = trigger.getAttribute('data-files-row-ext') || extensionFromFileName(fileName);
        }
        return out;
    }

    /**
     * @brief Delegate clicks from [data-files-media-preview-trigger] to openMediaPreview.
     * @param {MouseEvent} event Click event.
     * @return {void}
     * @date 2026-05-02
     * @author Stephane H.
     */
    function onDelegatedPreviewClick(event) {
        var el = getElementFromEventTarget(event.target);
        var trigger = el && el.closest ? el.closest('[data-files-media-preview-trigger]') : null;
        if (!trigger) {
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        var payload = buildPreviewPayloadFromTrigger(trigger);
        if (!payload) {
            return;
        }
        openMediaPreview(payload);
    }

    window.openMediaPreview = openMediaPreview;

    /**
     * @brief Open preview when a trigger is focused and user presses Enter or Space.
     * @param {KeyboardEvent} event Key event.
     * @return {void}
     * @date 2026-05-02
     * @author Stephane H.
     */
    function onPreviewTriggerKeydown(event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }
        var active = document.activeElement;
        if (!active || !active.closest) {
            return;
        }
        if (!active.hasAttribute('data-files-media-preview-trigger')) {
            return;
        }
        var trigger = active.closest('[data-files-media-preview-trigger]');
        if (!trigger || active !== trigger) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        var payload = buildPreviewPayloadFromTrigger(trigger);
        if (!payload) {
            return;
        }
        openMediaPreview(payload);
    }

    /**
     * @brief Attach error listeners on video/audio once (show i18n alert from Twig markup).
     * @return {void}
     * @date 2026-05-02
     * @author Stephane H.
     */
    function attachStreamDecodeErrorListeners() {
        var videoEl = document.getElementById(VIDEO_ID);
        var audioEl = document.getElementById(AUDIO_ID);
        if (videoEl) {
            videoEl.addEventListener('error', function () {
                showPlaybackError();
            });
        }
        if (audioEl) {
            audioEl.addEventListener('error', function () {
                showPlaybackError();
            });
        }
    }

    /**
     * @brief Attach fullscreen button handler once.
     * @return {void}
     * @date 2026-05-02
     * @author Stephane H.
     */
    function attachFullscreenButtonListener() {
        var btn = document.getElementById(FULLSCREEN_BTN_ID);
        if (btn) {
            btn.addEventListener('click', onVideoFullscreenClick);
        }
    }

    /**
     * @brief Close text preview and open the text file editor modal.
     * @return {void}
     * @date 2026-06-26
     * @author Stephane H.
     */
    function onTextEditClick() {
        if (!textEditContext || textEditContext.fileId < 1) {
            return;
        }
        if (!window.TextFileEditor || typeof window.TextFileEditor.open !== 'function') {
            return;
        }
        var ctx = textEditContext;
        textEditContext = null;
        var modalEl = document.getElementById(MODAL_ID);
        if (!modalEl || !window.bootstrap || !window.bootstrap.Modal) {
            window.TextFileEditor.open({
                fileId: ctx.fileId,
                fileName: ctx.fileName,
                extension: ctx.extension,
            });
            return;
        }
        var bsModal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
        modalEl.addEventListener('hidden.bs.modal', function openEditorAfterPreviewHide() {
            window.TextFileEditor.open({
                fileId: ctx.fileId,
                fileName: ctx.fileName,
                extension: ctx.extension,
            });
        }, { once: true });
        bsModal.hide();
    }

    /**
     * @brief Attach text edit button handler once.
     * @return {void}
     * @date 2026-06-26
     * @author Stephane H.
     */
    function attachTextEditButtonListener() {
        var btn = document.getElementById(TEXT_EDIT_BTN_ID);
        if (btn) {
            btn.addEventListener('click', onTextEditClick);
        }
    }

    /**
     * @brief Attach modal hidden listener and media helpers once the DOM is ready.
     * @return {void}
     * @date 2026-05-02
     * @author Stephane H.
     */
    function attachModalCleanupListener() {
        var modalEl = document.getElementById(MODAL_ID);
        if (modalEl) {
            modalEl.addEventListener('hidden.bs.modal', onModalHidden);
        }
        attachStreamDecodeErrorListeners();
        attachFullscreenButtonListener();
        attachTextEditButtonListener();
    }

    document.addEventListener('click', onDelegatedPreviewClick, true);
    document.addEventListener('keydown', onPreviewTriggerKeydown, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachModalCleanupListener);
    } else {
        attachModalCleanupListener();
    }
}());
