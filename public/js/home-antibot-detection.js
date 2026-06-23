(function () {
    'use strict';

    var MIN_VERIFY_MS = 2500;
    var MIN_SUCCESS_MS = 400;

    var root = document.querySelector('[data-home-access-root]');
    var form = document.getElementById('home-access-form');
    if (!root || !form) {
        return;
    }

    var pointerField = document.getElementById('home-access-pointer-moves');
    var focusField = document.getElementById('home-access-focus-events');
    var timeField = document.getElementById('home-access-time-on-page');
    var webdriverField = document.getElementById('home-access-webdriver');
    var refreshButton = document.getElementById('home-access-captcha-refresh');
    var captchaImage = document.querySelector('.home-access__captcha-image');
    var captchaInput = document.getElementById('captcha-code');
    var phasePanels = root.querySelectorAll('[data-home-access-phase]');
    var startedAt = Date.now();
    var pointerMoves = 0;
    var focusEvents = 0;
    var autoCheckStarted = false;
    var currentPhase = root.getAttribute('data-initial-phase') || 'checking';

    if (webdriverField) {
        webdriverField.value = navigator.webdriver ? '1' : '0';
    }

    /**
     * @brief Show a single access gate phase panel.
     * @param {string} phase checking, success, or captcha.
     */
    function setPhase(phase) {
        currentPhase = phase;
        phasePanels.forEach(function (panel) {
            var isActive = panel.getAttribute('data-home-access-phase') === phase;
            panel.classList.toggle('d-none', !isActive);
            panel.classList.toggle('home-access__phase--active', isActive);
        });
    }

    /**
     * @brief Load captcha image from data-src when entering captcha phase.
     */
    function loadCaptchaImage() {
        if (!captchaImage || captchaImage.getAttribute('src')) {
            return;
        }
        var dataSrc = captchaImage.getAttribute('data-src');
        if (dataSrc) {
            captchaImage.setAttribute('src', dataSrc);
        }
    }

    /**
     * @brief Sync elapsed time hidden field for server scoring.
     */
    function syncElapsed() {
        if (timeField) {
            timeField.value = String(Date.now() - startedAt);
        }
    }

    /**
     * @brief Whether redirect Location points back to the access gate (captcha required).
     * @param {string} location Header value or absolute URL.
     * @return {boolean}
     */
    function isAccessGateRedirect(location) {
        if (!location) {
            return false;
        }
        try {
            var url = new URL(location, window.location.origin);
            return url.pathname.indexOf('/home/access') === 0;
        } catch (error) {
            return location.indexOf('/home/access') >= 0;
        }
    }

    /**
     * @brief Navigate to captcha phase via full page load when fetch did not redirect.
     */
    function redirectToCaptchaPhaseFallback() {
        var fallbackUrl = new URL(form.action, window.location.origin);
        fallbackUrl.searchParams.set('phase', 'captcha');
        var targetInput = form.querySelector('input[name="target"]');
        if (targetInput && targetInput.value) {
            fallbackUrl.searchParams.set('target', targetInput.value);
        }
        window.location.assign(fallbackUrl.toString());
    }

    /**
     * @brief Run automatic behavioural check via fetch after minimum wait.
     */
    function runAutoCheck() {
        if (autoCheckStarted || currentPhase !== 'checking') {
            return;
        }
        if (Date.now() - startedAt < MIN_VERIFY_MS) {
            return;
        }
        autoCheckStarted = true;
        syncElapsed();

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            redirect: 'manual',
        })
            .then(function (response) {
                var location = response.headers.get('Location') || response.url || '';
                if (response.status >= 300 && response.status < 400 && location) {
                    if (isAccessGateRedirect(location)) {
                        window.location.assign(location);
                        return;
                    }
                    setPhase('success');
                    window.setTimeout(function () {
                        window.location.assign(location);
                    }, MIN_SUCCESS_MS);
                    return;
                }
                redirectToCaptchaPhaseFallback();
            })
            .catch(function () {
                redirectToCaptchaPhaseFallback();
            });
    }

    document.addEventListener('pointermove', function () {
        pointerMoves += 1;
        if (pointerField) {
            pointerField.value = String(pointerMoves);
        }
    });

    document.addEventListener('focusin', function () {
        focusEvents += 1;
        if (focusField) {
            focusField.value = String(focusEvents);
        }
    });

    if (refreshButton && captchaImage) {
        refreshButton.addEventListener('click', function () {
            var baseSrc = captchaImage.getAttribute('data-src') || captchaImage.src.split('?')[0];
            if (!baseSrc) {
                return;
            }
            captchaImage.setAttribute('src', baseSrc + (baseSrc.indexOf('?') >= 0 ? '&' : '?') + 't=' + Date.now());
        });
    }

    setPhase(currentPhase);

    if (currentPhase === 'captcha') {
        loadCaptchaImage();
        if (captchaInput) {
            captchaInput.focus();
        }
    } else {
        window.setInterval(syncElapsed, 500);
        window.setInterval(runAutoCheck, 800);
    }
})();
