/**
 * @brief Initialize bootstrap flash toasts and expose a runtime toast helper.
 * @param void No input parameter.
 * @return void
 * @date 2026-04-29
 * @author Stephane H.
 */
(function () {
    'use strict';

    if (!window.bootstrap || !window.bootstrap.Toast) {
        return;
    }

    /**
     * @brief Return the nearest flash toast container or create one on demand.
     * @param {HTMLElement|null|undefined} preferredContainer Optional container to reuse.
     * @return {HTMLElement}
     * @date 2026-04-29
     * @author Stephane H.
     */
    function getOrCreateToastContainer(preferredContainer) {
        if (preferredContainer instanceof HTMLElement) {
            return preferredContainer;
        }
        var existing = document.querySelector('.flash-toast-container');
        if (existing instanceof HTMLElement) {
            return existing;
        }
        var container = document.createElement('div');
        container.className = 'toast-container position-fixed bottom-0 end-0 p-3 flash-toast-container';
        document.body.appendChild(container);

        return container;
    }

    /**
     * @brief Render and show a runtime toast message with optional overrides.
     * @param {string} message Display message text.
     * @param {string} tone Bootstrap tone key (success|info|warning|danger|error).
     * @param {{closeLabel?: string, delay?: number, autohide?: boolean, container?: HTMLElement|null}=} options Runtime options.
     * @return {void}
     * @date 2026-04-29
     * @author Stephane H.
     */
    function pushToast(message, tone, options) {
        var opts = options || {};
        var normalizedTone = tone === 'error' ? 'danger' : tone;
        var styleMap = {
            success: 'text-bg-success',
            info: 'text-bg-info',
            warning: 'text-bg-warning',
            danger: 'text-bg-danger'
        };
        var isCritical = normalizedTone === 'danger' || normalizedTone === 'warning';
        var toastDelay = typeof opts.delay === 'number' ? opts.delay : (isCritical ? 8000 : 5000);
        var autoHide = typeof opts.autohide === 'boolean' ? opts.autohide : true;
        var closeLabel = typeof opts.closeLabel === 'string' ? opts.closeLabel : '';
        var container = getOrCreateToastContainer(opts.container);
        var toastElement = document.createElement('div');
        toastElement.className = 'toast fade border-0 mb-2 ' + (styleMap[normalizedTone] || 'text-bg-secondary');
        toastElement.setAttribute('role', isCritical ? 'alert' : 'status');
        toastElement.setAttribute('aria-live', isCritical ? 'assertive' : 'polite');
        toastElement.setAttribute('aria-atomic', 'true');
        toastElement.setAttribute('data-bs-delay', String(toastDelay));
        toastElement.setAttribute('data-bs-autohide', autoHide ? 'true' : 'false');
        toastElement.innerHTML = '' +
            '<div class="d-flex align-items-start w-100 flash-toast-content">' +
            '<div class="toast-body flex-grow-1 flash-toast-body"></div>' +
            '<button type="button" class="btn-close btn-close-white ms-auto flex-shrink-0 flash-toast-close" data-bs-dismiss="toast" aria-label=""></button>' +
            '</div>';
        var body = toastElement.querySelector('.toast-body');
        if (body) {
            body.textContent = message;
        }
        var closeButton = toastElement.querySelector('.btn-close');
        if (closeButton) {
            closeButton.setAttribute('aria-label', closeLabel);
        }
        container.appendChild(toastElement);
        toastElement.addEventListener('hidden.bs.toast', function () {
            if (toastElement.parentNode) {
                toastElement.parentNode.removeChild(toastElement);
            }
        });
        window.bootstrap.Toast.getOrCreateInstance(toastElement).show();
    }

    window.AppFlashToasts = {
        push: pushToast
    };

    document.querySelectorAll('[data-flash-toast]').forEach(function (toastElement) {
        window.bootstrap.Toast.getOrCreateInstance(toastElement).show();
    });
}());
