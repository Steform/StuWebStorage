(() => {
    const MAX_EVENTS = 100;
    const MAX_STRING_LENGTH = 512;
    const MAX_TIMELINE_LENGTH = 64000;
    const SENSITIVE_KEY_PATTERN = /(token|password|passwd|cookie|authorization|secret|csrf|key)/i;
    const timeline = [];

    /**
     * @brief Normalize a scalar value for log payload safety.
     * @param {*} value Raw value.
     * @returns {*}
     * @date 2026-05-06
     * @author Stephane H.
     */
    function sanitizeValue(value) {
        if (typeof value === 'string') {
            if (value.length > MAX_STRING_LENGTH) {
                return `${value.slice(0, MAX_STRING_LENGTH)}…`;
            }

            return value;
        }

        if (typeof value === 'number' || typeof value === 'boolean' || value === null) {
            return value;
        }

        if (Array.isArray(value)) {
            return value.slice(0, 20).map((item) => sanitizeValue(item));
        }

        if (typeof value === 'object' && value !== null) {
            const sanitized = {};
            Object.entries(value).slice(0, 30).forEach(([key, entryValue]) => {
                sanitized[key] = SENSITIVE_KEY_PATTERN.test(key) ? '[REDACTED]' : sanitizeValue(entryValue);
            });

            return sanitized;
        }

        return String(value);
    }

    /**
     * @brief Push one event to timeline ring buffer.
     * @param {string} type Event type.
     * @param {object} meta Event metadata.
     * @returns {void}
     * @date 2026-05-06
     * @author Stephane H.
     */
    function track(type, meta = {}) {
        const event = {
            ts: new Date().toISOString(),
            type,
            path: window.location.pathname,
            query: window.location.search,
            meta: sanitizeValue(meta),
        };
        timeline.push(event);
        if (timeline.length > MAX_EVENTS) {
            timeline.splice(0, timeline.length - MAX_EVENTS);
        }
    }

    /**
     * @brief Export timeline JSON with hard size limit.
     * @returns {string}
     * @date 2026-05-06
     * @author Stephane H.
     */
    function exportTimeline() {
        let payload = JSON.stringify(timeline);
        if (payload.length <= MAX_TIMELINE_LENGTH) {
            return payload;
        }

        const reduced = timeline.slice(-40);
        payload = JSON.stringify(reduced);

        return payload.length <= MAX_TIMELINE_LENGTH ? payload : '[]';
    }

    track('page_load', {
        route: document.body?.dataset?.route ?? null,
    });

    document.addEventListener('click', (event) => {
        const target = event.target instanceof HTMLElement ? event.target.closest('button,a,[data-bs-toggle],[data-files-action-global],[data-files-row-action],[data-files-folder-action]') : null;
        if (!target) {
            return;
        }

        track('ui_click', {
            tag: target.tagName.toLowerCase(),
            id: target.id || null,
            className: target.className || null,
            action: target.getAttribute('data-files-action-global')
                || target.getAttribute('data-files-row-action')
                || target.getAttribute('data-files-folder-action')
                || target.getAttribute('data-bs-toggle')
                || null,
        });
    }, { passive: true });

    document.addEventListener('change', (event) => {
        const target = event.target instanceof HTMLElement ? event.target : null;
        if (!target || !(target instanceof HTMLInputElement || target instanceof HTMLSelectElement || target instanceof HTMLTextAreaElement)) {
            return;
        }

        track('form_change', {
            name: target.name || target.id || null,
            type: target.type || target.tagName.toLowerCase(),
        });
    }, { passive: true });

    document.addEventListener('submit', (event) => {
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        if (!form) {
            return;
        }

        track('form_submit', {
            id: form.id || null,
            action: form.action || null,
            method: form.method || null,
        });
    });

    window.addEventListener('popstate', () => {
        track('navigation_popstate');
    });

    window.addEventListener('hashchange', () => {
        track('navigation_hashchange');
    });

    window.addEventListener('error', (event) => {
        track('js_error', {
            message: event.message || null,
            file: event.filename || null,
            line: event.lineno || null,
            column: event.colno || null,
        });
    });

    window.addEventListener('unhandledrejection', (event) => {
        track('js_unhandled_rejection', {
            reason: typeof event.reason === 'string' ? event.reason : (event.reason?.message || null),
        });
    });

    if (typeof window.fetch === 'function') {
        const nativeFetch = window.fetch.bind(window);
        window.fetch = async (...args) => {
            const url = typeof args[0] === 'string' ? args[0] : (args[0]?.url || null);
            try {
                const response = await nativeFetch(...args);
                track('network_fetch', {
                    url,
                    status: response.status,
                    ok: response.ok,
                });

                return response;
            } catch (error) {
                track('network_fetch_error', {
                    url,
                    message: error instanceof Error ? error.message : String(error),
                });
                throw error;
            }
        };
    }

    if (window.XMLHttpRequest) {
        const originalOpen = XMLHttpRequest.prototype.open;
        const originalSend = XMLHttpRequest.prototype.send;

        XMLHttpRequest.prototype.open = function open(method, url, ...rest) {
            this.__bugReportMethod = method;
            this.__bugReportUrl = url;
            return originalOpen.call(this, method, url, ...rest);
        };

        XMLHttpRequest.prototype.send = function send(body) {
            this.addEventListener('loadend', () => {
                track('network_xhr', {
                    method: this.__bugReportMethod || null,
                    url: this.__bugReportUrl || null,
                    status: this.status,
                });
            });
            return originalSend.call(this, body);
        };
    }

    document.addEventListener('show.bs.modal', (event) => {
        const target = event.target instanceof HTMLElement ? event.target : null;
        track('modal_open', { id: target?.id || null });
    });

    document.addEventListener('hide.bs.modal', (event) => {
        const target = event.target instanceof HTMLElement ? event.target : null;
        track('modal_close', { id: target?.id || null });
    });

    const bugForm = document.getElementById('bug-report-submit-form');
    if (bugForm instanceof HTMLFormElement) {
        bugForm.addEventListener('submit', () => {
            const widthInput = bugForm.querySelector('[data-bug-report-viewport-width]');
            const heightInput = bugForm.querySelector('[data-bug-report-viewport-height]');
            const timelineInput = bugForm.querySelector('[data-bug-report-action-timeline]');

            if (widthInput instanceof HTMLInputElement) {
                widthInput.value = String(window.innerWidth || 0);
            }
            if (heightInput instanceof HTMLInputElement) {
                heightInput.value = String(window.innerHeight || 0);
            }
            if (timelineInput instanceof HTMLInputElement) {
                timelineInput.value = exportTimeline();
            }
        });
    }
})();
