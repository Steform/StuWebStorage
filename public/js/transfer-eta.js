(function () {
    'use strict';

    /**
     * @brief Shared transfer ETA / speed estimator for upload and download UIs.
     * @date 2026-11-09
     * @author Stephane H.
     */

    /**
     * @brief Create a smoothed bytes/sec estimator with ETA.
     * @param {object} [options] Estimator options.
     * @param {number} [options.alpha=0.3] EMA smoothing factor.
     * @param {number} [options.minSamples=3] Minimum samples before labelReady.
     * @param {number} [options.minWindowMs=1000] Minimum time span before labelReady.
     * @param {number} [options.maxEtaSeconds=356400] Cap displayed ETA (99 h).
     * @param {number} [options.minUiIntervalMs=500] UI refresh throttle.
     * @param {number} [options.stallBytesPerSec=256] Below this rate, ETA is not ready.
     * @return {{reset: Function, update: Function, get: Function}}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function createEstimator(options) {
        var opts = options && typeof options === 'object' ? options : {};
        var alpha = typeof opts.alpha === 'number' ? opts.alpha : 0.3;
        var minSamples = typeof opts.minSamples === 'number' ? opts.minSamples : 3;
        var minWindowMs = typeof opts.minWindowMs === 'number' ? opts.minWindowMs : 1000;
        var maxEtaSeconds = typeof opts.maxEtaSeconds === 'number' ? opts.maxEtaSeconds : 99 * 3600;
        var minUiIntervalMs = typeof opts.minUiIntervalMs === 'number' ? opts.minUiIntervalMs : 500;
        var stallBytesPerSec = typeof opts.stallBytesPerSec === 'number' ? opts.stallBytesPerSec : 256;
        var maxSamples = typeof opts.maxSamples === 'number' ? opts.maxSamples : 8;

        var samples = [];
        var emaRate = null;
        var lastUiMs = 0;
        var lastBytes = -1;
        var firstSampleMs = 0;

        /**
         * @brief Reset samples and rate state.
         * @return {void}
         * @date 2026-11-09
         * @author Stephane H.
         */
        function reset() {
            samples = [];
            emaRate = null;
            lastUiMs = 0;
            lastBytes = -1;
            firstSampleMs = 0;
        }

        /**
         * @brief Build a snapshot for the current progress.
         * @param {number} bytesDone Bytes transferred.
         * @param {number} bytesTotal Total bytes.
         * @param {number} nowMs Timestamp ms.
         * @param {boolean} forceUi Force UI refresh flag.
         * @return {{bytesPerSec: number|null, etaSeconds: number|null, labelReady: boolean, shouldRefreshUi: boolean, done: boolean}}
         * @date 2026-11-09
         * @author Stephane H.
         */
        function snapshot(bytesDone, bytesTotal, nowMs, forceUi) {
            var done = bytesTotal > 0 && bytesDone >= bytesTotal;
            var rate = emaRate;
            var labelReady = false;
            var etaSeconds = null;

            // Use elapsed time since first sample, not the short sliding window:
            // on localhost, 8 high-frequency ticks can span << minWindowMs.
            if (!done && rate !== null && rate >= stallBytesPerSec && samples.length >= minSamples) {
                var span = firstSampleMs > 0 ? (nowMs - firstSampleMs) : 0;
                if (span >= minWindowMs) {
                    labelReady = true;
                    var remaining = Math.max(0, bytesTotal - bytesDone);
                    if (bytesTotal > 0) {
                        etaSeconds = Math.ceil(remaining / rate);
                        if (etaSeconds > maxEtaSeconds) {
                            etaSeconds = maxEtaSeconds;
                        }
                    }
                }
            }

            if (done) {
                labelReady = false;
                etaSeconds = 0;
                rate = emaRate;
            }

            var shouldRefreshUi = !!forceUi;
            if (!shouldRefreshUi) {
                if (lastUiMs === 0 || (nowMs - lastUiMs) >= minUiIntervalMs || done) {
                    shouldRefreshUi = true;
                }
            }
            if (shouldRefreshUi) {
                lastUiMs = nowMs;
            }

            return {
                bytesPerSec: rate,
                etaSeconds: etaSeconds,
                labelReady: labelReady,
                shouldRefreshUi: shouldRefreshUi,
                done: done
            };
        }

        /**
         * @brief Ingest a progress tick and return a snapshot.
         * @param {number} bytesDone Bytes transferred so far.
         * @param {number} bytesTotal Known total bytes (0 if unknown).
         * @param {number} [nowMs] Optional timestamp.
         * @return {{bytesPerSec: number|null, etaSeconds: number|null, labelReady: boolean, shouldRefreshUi: boolean, done: boolean}}
         * @date 2026-11-09
         * @author Stephane H.
         */
        function update(bytesDone, bytesTotal, nowMs) {
            var now = typeof nowMs === 'number' ? nowMs : Date.now();
            var doneBytes = Number(bytesDone) || 0;
            var totalBytes = Number(bytesTotal) || 0;

            if (doneBytes < 0) {
                doneBytes = 0;
            }

            if (doneBytes !== lastBytes) {
                if (lastBytes >= 0 && doneBytes > lastBytes && samples.length > 0) {
                    var prev = samples[samples.length - 1];
                    var dt = (now - prev.t) / 1000;
                    // Ignore sub-ms bursts that explode instantaneous rate on local disks.
                    if (dt >= 0.05) {
                        var instant = (doneBytes - prev.b) / dt;
                        if (instant >= 0) {
                            emaRate = emaRate === null ? instant : (alpha * instant + (1 - alpha) * emaRate);
                        }
                    }
                }
                samples.push({ t: now, b: doneBytes });
                if (firstSampleMs === 0) {
                    firstSampleMs = now;
                }
                if (samples.length > maxSamples) {
                    samples = samples.slice(samples.length - maxSamples);
                }
                lastBytes = doneBytes;
            }

            return snapshot(doneBytes, totalBytes, now, false);
        }

        /**
         * @brief Read current estimate without ingesting a new sample.
         * @param {number} bytesDone Bytes transferred.
         * @param {number} bytesTotal Total bytes.
         * @return {{bytesPerSec: number|null, etaSeconds: number|null, labelReady: boolean, shouldRefreshUi: boolean, done: boolean}}
         * @date 2026-11-09
         * @author Stephane H.
         */
        function get(bytesDone, bytesTotal) {
            return snapshot(Number(bytesDone) || 0, Number(bytesTotal) || 0, Date.now(), true);
        }

        return {
            reset: reset,
            update: update,
            get: get
        };
    }

    /**
     * @brief Format a byte rate as a human-readable speed value (without "/s").
     * @param {number|null} bytesPerSec Bytes per second.
     * @return {string}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function formatSpeedValue(bytesPerSec) {
        var n = Number(bytesPerSec);
        if (!isFinite(n) || n < 0) {
            return '';
        }
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var idx = 0;
        var value = n;
        while (value >= 1024 && idx < units.length - 1) {
            value /= 1024;
            idx += 1;
        }
        var digits = value >= 100 || idx === 0 ? 0 : (value >= 10 ? 1 : 2);

        return value.toFixed(digits) + ' ' + units[idx];
    }

    /**
     * @brief Format ETA seconds into a short duration string.
     * @param {number|null} etaSeconds Seconds remaining.
     * @param {object} labels Unit labels {s, m, h}.
     * @return {string}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function formatDuration(etaSeconds, labels) {
        var sec = Math.ceil(Number(etaSeconds));
        if (!isFinite(sec) || sec < 0) {
            return '';
        }
        var lab = labels && typeof labels === 'object' ? labels : {};
        var sUnit = lab.s || 's';
        var mUnit = lab.m || 'min';
        var hUnit = lab.h || 'h';
        if (sec < 60) {
            return String(sec) + ' ' + sUnit;
        }
        var hours = Math.floor(sec / 3600);
        var minutes = Math.floor((sec % 3600) / 60);
        var seconds = sec % 60;
        if (hours > 0) {
            return String(hours) + ' ' + hUnit + ' ' + String(minutes) + ' ' + mUnit;
        }

        return String(minutes) + ' ' + mUnit + (seconds > 0 ? ' ' + String(seconds) + ' ' + sUnit : '');
    }

    /**
     * @brief Build a single UI line for speed + ETA.
     * @param {{bytesPerSec: number|null, etaSeconds: number|null, labelReady: boolean, done: boolean}} snap Estimator snapshot.
     * @param {object} templates Message templates.
     * @return {string}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function formatLine(snap, templates) {
        var t = templates && typeof templates === 'object' ? templates : {};
        if (!snap || snap.done) {
            return '';
        }
        if (!snap.labelReady) {
            return t.calculating || '';
        }
        var speedVal = formatSpeedValue(snap.bytesPerSec);
        var speed = (t.speed || '%speed%/s').split('%speed%').join(speedVal);
        var duration = formatDuration(snap.etaSeconds, t.units || {});
        var eta = (t.remaining || '~%duration%').split('%duration%').join(duration);
        var line = t.line || '%speed% · %eta%';

        return line.split('%speed%').join(speed).split('%eta%').join(eta);
    }

    window.TransferEta = {
        createEstimator: createEstimator,
        formatSpeedValue: formatSpeedValue,
        formatDuration: formatDuration,
        formatLine: formatLine
    };
}());
