(function () {
    'use strict';

    function replaceJobId(template, jobId) {
        return String(template || '').replace('00000000000000000000000000000000', jobId);
    }

    function formatBytes(bytes) {
        var n = Number(bytes) || 0;
        if (n < 1024) {
            return n + ' B';
        }
        if (n < 1048576) {
            return (n / 1024).toFixed(1) + ' KiB';
        }
        if (n < 1073741824) {
            return (n / 1048576).toFixed(1) + ' MiB';
        }
        return (n / 1073741824).toFixed(2) + ' GiB';
    }

    function updateProgressUi(root, payload) {
        var percent = Number(payload.percent) || 0;
        var bar = root.querySelector('#files-download-prepare-progress-bar, #files-download-prepare-page-progress-bar');
        var count = root.querySelector('#files-download-prepare-count, #files-download-prepare-page-count');
        var phase = root.querySelector('#files-download-prepare-phase');
        var progress = root.querySelector('#files-download-prepare-progress');
        if (bar) {
            bar.style.width = percent + '%';
            bar.setAttribute('aria-valuenow', String(percent));
        }
        if (progress) {
            progress.setAttribute('aria-valuenow', String(percent));
        }
        if (count) {
            count.textContent = formatBytes(payload.plain_written) + ' / ' + formatBytes(payload.bytes) + ' (' + percent + '%)';
        }
        if (phase) {
            phase.textContent = root.getAttribute('data-msg-progress') || '';
        }
    }

    function showError(root, message) {
        var errorEl = root.querySelector('#files-download-prepare-error, #files-download-prepare-page-error');
        if (!errorEl) {
            return;
        }
        errorEl.textContent = message || root.getAttribute('data-msg-error') || '';
        errorEl.classList.remove('d-none');
    }

  /**
   * @brief Run prepare -> tick loop until ready, then trigger browser download.
   * @param {object} config Runtime configuration.
   * @return {void}
   * @date 2026-07-07
   * @author Stephane H.
   */
    window.FilesDownloadPrepare = {
        run: function (config) {
            var root = config.root;
            var csrfToken = config.csrfToken || '';
            var prepareUrl = config.prepareUrl || '';
            var tickTemplate = config.tickUrlTemplate || '';
            var deliverTemplate = config.deliverUrlTemplate || '';
            var activeJobId = '';
            var pollTimer = null;
            var tickInFlight = false;

            function stopPolling() {
                if (pollTimer !== null) {
                    window.clearTimeout(pollTimer);
                    pollTimer = null;
                }
            }

            function scheduleTick() {
                stopPolling();
                pollTimer = window.setTimeout(postTick, 250);
            }

            function postTick() {
                if (activeJobId === '' || tickInFlight) {
                    return;
                }
                tickInFlight = true;
                var body = new URLSearchParams();
                body.append('_csrf_token', csrfToken);
                fetch(replaceJobId(tickTemplate, activeJobId), {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                    },
                    body: body.toString(),
                    credentials: 'same-origin'
                }).then(function (response) {
                    return response.json().catch(function () {
                        return {};
                    }).then(function (json) {
                        return { ok: response.ok, json: json };
                    });
                }).then(function (pack) {
                    tickInFlight = false;
                    if (!pack.ok || !pack.json || pack.json.status !== 'ok') {
                        showError(root, (pack.json && pack.json.message) || '');
                        stopPolling();
                        return;
                    }
                    updateProgressUi(root, pack.json);
                    if (pack.json.complete) {
                        stopPolling();
                        window.location.href = pack.json.deliver_url || replaceJobId(deliverTemplate, activeJobId);
                        return;
                    }
                    scheduleTick();
                }).catch(function () {
                    tickInFlight = false;
                    showError(root, '');
                    stopPolling();
                });
            }

            function startPrepare() {
                var body = new URLSearchParams();
                body.append('_csrf_token', csrfToken);
                fetch(prepareUrl, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                    },
                    body: body.toString(),
                    credentials: 'same-origin'
                }).then(function (response) {
                    return response.json().catch(function () {
                        return {};
                    }).then(function (json) {
                        return { ok: response.ok, json: json };
                    });
                }).then(function (pack) {
                    if (!pack.ok || !pack.json || pack.json.status !== 'ok' || !pack.json.job_id) {
                        showError(root, (pack.json && pack.json.message) || '');
                        return;
                    }
                    activeJobId = String(pack.json.job_id);
                    updateProgressUi(root, {
                        percent: pack.json.bytes > 0 ? Math.floor((pack.json.plain_written / pack.json.bytes) * 100) : 0,
                        plain_written: pack.json.plain_written || 0,
                        bytes: pack.json.bytes || 0
                    });
                    if (pack.json.phase === 'ready') {
                        window.location.href = pack.json.deliver_url || replaceJobId(deliverTemplate, activeJobId);
                        return;
                    }
                    scheduleTick();
                }).catch(function () {
                    showError(root, '');
                });
            }

            startPrepare();

            return {
                cancel: function () {
                    stopPolling();
                }
            };
        }
    };
}());
