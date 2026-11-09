(function () {
    'use strict';

    /**
     * @brief Map a failed XHR/fetch payload to an honest user message (permission vs server vs network).
     * @date 2026-11-09
     * @author Stephane H.
     */

    /**
     * @brief Whether a body or key looks like a runtime permission failure.
     * @param {string} raw Response body or key.
     * @return {boolean}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function looksLikePermission(raw) {
        var text = String(raw || '').toLowerCase();
        if (text === '') {
            return false;
        }

        return text.indexOf('permission denied') >= 0
            || text.indexOf('operation not permitted') >= 0
            || text.indexOf('runtime_permission_denied') >= 0
            || text.indexOf('mkdir_failed') >= 0
            || text.indexOf('runtime.permission_denied') >= 0;
    }

    /**
     * @brief Choose a user-facing message from a failed HTTP/JSON pack.
     * @param {{ok?: boolean, json?: object, status?: number, raw?: string}|null} pack Parsed response.
     * @param {{permission?: string, server?: string, network?: string}} messages Localized fallbacks.
     * @return {string}
     * @date 2026-11-09
     * @author Stephane H.
     */
    function diagnose(pack, messages) {
        var msgs = messages && typeof messages === 'object' ? messages : {};
        var json = pack && pack.json && typeof pack.json === 'object' ? pack.json : {};
        var status = Number(pack && pack.status ? pack.status : 0);
        var raw = String((pack && pack.raw) || '');
        var key = String(json.message_key || json.errorKey || '');
        var jsonMessage = typeof json.message === 'string' ? json.message.trim() : '';

        if (looksLikePermission(key) || looksLikePermission(raw) || looksLikePermission(jsonMessage)) {
            return msgs.permission || jsonMessage || msgs.server || msgs.network || '';
        }
        if (jsonMessage !== '') {
            return jsonMessage;
        }
        if (status >= 400) {
            var serverTpl = msgs.server || '';
            if (serverTpl.indexOf('%status%') >= 0) {
                return serverTpl.split('%status%').join(String(status));
            }

            return serverTpl || msgs.network || '';
        }
        if (status === 0) {
            return msgs.network || '';
        }

        return msgs.network || jsonMessage || '';
    }

    window.FilesHttpError = {
        diagnose: diagnose,
        looksLikePermission: looksLikePermission
    };
}());
