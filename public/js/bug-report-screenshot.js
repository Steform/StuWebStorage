(() => {
    const MAX_CAPTURE_WIDTH = 1920;
    const JPEG_QUALITY = 0.7;

    /**
     * @brief Downscale canvas when viewport is wider than max width.
     * @param {HTMLCanvasElement} canvas Source canvas.
     * @returns {HTMLCanvasElement}
     * @date 2026-06-26
     * @author Stephane H.
     */
    function downscaleCanvas(canvas) {
        if (canvas.width <= MAX_CAPTURE_WIDTH) {
            return canvas;
        }

        const ratio = MAX_CAPTURE_WIDTH / canvas.width;
        const scaled = document.createElement('canvas');
        scaled.width = MAX_CAPTURE_WIDTH;
        scaled.height = Math.max(1, Math.round(canvas.height * ratio));
        const context = scaled.getContext('2d');
        if (!context) {
            return canvas;
        }
        context.drawImage(canvas, 0, 0, scaled.width, scaled.height);

        return scaled;
    }

    /**
     * @brief Capture current page screenshot as JPEG data URL.
     * @returns {Promise<string>}
     * @date 2026-06-26
     * @author Stephane H.
     */
    async function captureScreenshotDataUrl() {
        if (typeof window.html2canvas !== 'function') {
            return '';
        }

        const canvas = await window.html2canvas(document.body, {
            useCORS: true,
            logging: false,
            scale: 1,
            windowWidth: document.documentElement.clientWidth,
            windowHeight: document.documentElement.clientHeight,
        });
        const outputCanvas = downscaleCanvas(canvas);

        return outputCanvas.toDataURL('image/jpeg', JPEG_QUALITY);
    }

    /**
     * @brief Bind screenshot capture before bug report modal opens.
     * @returns {void}
     * @date 2026-06-26
     * @author Stephane H.
     */
    function bindBugReportScreenshotCapture() {
        const trigger = document.querySelector('[data-bug-report-trigger]');
        const modal = document.getElementById('bugReportModal');
        const screenshotInput = document.querySelector('[data-bug-report-screenshot]');
        if (!(trigger instanceof HTMLButtonElement) || !(modal instanceof HTMLElement) || !(screenshotInput instanceof HTMLInputElement)) {
            return;
        }

        trigger.addEventListener('click', async (event) => {
            event.preventDefault();
            event.stopPropagation();

            screenshotInput.value = '';
            try {
                screenshotInput.value = await captureScreenshotDataUrl();
            } catch {
                screenshotInput.value = '';
            }

            const modalInstance = window.bootstrap?.Modal?.getOrCreateInstance(modal);
            if (modalInstance) {
                modalInstance.show();
            }
        }, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindBugReportScreenshotCapture);
    } else {
        bindBugReportScreenshotCapture();
    }
})();
