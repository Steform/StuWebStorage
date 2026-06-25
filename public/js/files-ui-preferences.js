(function () {
    'use strict';

    var PREF_STORAGE_KEY = 'files.ui.preferences.v1';
    var DEVICE_STORAGE_KEY = 'files.ui.device_id.v1';
    var MIGRATION_STORAGE_KEY = 'files.ui.preferences.migrated.v1';
    var LEGACY_COLUMN_KEY = 'files.columns.visibility';
    var LEGACY_SECTION_MY_FILES_KEY = 'files.section.my_files.expanded';
    var LEGACY_SECTION_SHARED_FOR_ME_KEY = 'files.section.shared_for_me.expanded';
    var SAVE_DEBOUNCE_MS = 400;
    var DEVICE_ID_REGEX = /^[A-Za-z0-9._:-]{8,128}$/;

    var liveRegion = document.getElementById('files-live-region');
    if (!liveRegion) {
        return;
    }

    var saveTimer = null;
    var saveInFlight = false;
    var pendingSaveAfterInFlight = false;
    var hasLoadedBackend = false;

    /**
     * @brief Parse JSON text safely.
     * @param {string} raw Raw JSON text.
     * @param {Record<string, unknown>} fallback Fallback object.
     * @return {Record<string, unknown>}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function parseJsonObject(raw, fallback) {
        try {
            var parsed = JSON.parse(raw);
            if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                return parsed;
            }
            return fallback;
        } catch (e) {
            return fallback;
        }
    }

    /**
     * @brief Return true when the files page has authenticated context.
     * @param void No input parameter.
     * @return {boolean}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function isAuthenticatedContext() {
        return (liveRegion.getAttribute('data-files-ui-pref-authenticated') || '0') === '1';
    }

    /**
     * @brief Build a random opaque identifier for one browser device.
     * @param void No input parameter.
     * @return {string}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function generateOpaqueDeviceId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return String(window.crypto.randomUUID());
        }
        return 'dev-' + Math.random().toString(36).slice(2) + Date.now().toString(36);
    }

    /**
     * @brief Return a stable device identifier from localStorage.
     * @param void No input parameter.
     * @return {string}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function getOrCreateDeviceId() {
        var value = '';
        try {
            value = String(window.localStorage.getItem(DEVICE_STORAGE_KEY) || '').trim();
        } catch (e) {
            value = '';
        }
        if (!DEVICE_ID_REGEX.test(value)) {
            value = generateOpaqueDeviceId();
            try {
                window.localStorage.setItem(DEVICE_STORAGE_KEY, value);
            } catch (e) {
                return value;
            }
        }

        return value;
    }

    /**
     * @brief Return canonical default preference object.
     * @param void No input parameter.
     * @return {Record<string, unknown>}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function getDefaultPreferences() {
        return {
            filesViewMode: 'list',
            filesScope: 'both',
            filesSortField: 'name',
            filesSortDirection: 'asc',
            cloudVisibilityState: {
                columns: {
                    type: true,
                    size: true,
                    share_public: true,
                    share_friends: true,
                    uploaded: false,
                    modified: false
                },
                sections: {
                    my_files: true,
                    shared_for_me: true
                }
            }
        };
    }

    /**
     * @brief Read listing query state from files live region data attribute.
     * @param void No input parameter.
     * @return {Record<string, unknown>}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function readListingState() {
        var raw = liveRegion.getAttribute('data-files-listing-query') || '{}';
        return parseJsonObject(raw, {});
    }

    /**
     * @brief Persist listing query state into files live region data attribute.
     * @param {Record<string, unknown>} state Listing query object.
     * @return {void}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function writeListingState(state) {
        liveRegion.setAttribute('data-files-listing-query', JSON.stringify(state));
    }

    /**
     * @brief Normalize boolean value from localStorage token.
     * @param {string} raw Raw localStorage token.
     * @param {boolean} fallback Fallback boolean.
     * @return {boolean}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function parseBool(raw, fallback) {
        if (raw === '1') {
            return true;
        }
        if (raw === '0') {
            return false;
        }
        return fallback;
    }

    /**
     * @brief Read canonical preferences from localStorage.
     * @param void No input parameter.
     * @return {Record<string, unknown>|null}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function readLocalCanonicalPreferences() {
        try {
            var raw = window.localStorage.getItem(PREF_STORAGE_KEY);
            if (!raw) {
                return null;
            }
            return parseJsonObject(raw, null);
        } catch (e) {
            return null;
        }
    }

    /**
     * @brief Persist canonical preferences to localStorage.
     * @param {Record<string, unknown>} pref Preference object.
     * @return {void}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function writeLocalCanonicalPreferences(pref) {
        try {
            window.localStorage.setItem(PREF_STORAGE_KEY, JSON.stringify(pref));
        } catch (e) {
            return;
        }
    }

    /**
     * @brief Build canonical preference object from legacy localStorage keys.
     * @param void No input parameter.
     * @return {Record<string, unknown>|null}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function migrateLegacyLocalStorageOnce() {
        try {
            if (window.localStorage.getItem(MIGRATION_STORAGE_KEY) === '1') {
                return null;
            }
            var defaults = getDefaultPreferences();
            var migrated = JSON.parse(JSON.stringify(defaults));
            var legacyColumnsRaw = window.localStorage.getItem(LEGACY_COLUMN_KEY);
            if (legacyColumnsRaw) {
                var legacyColumns = parseJsonObject(legacyColumnsRaw, {});
                Object.keys(migrated.cloudVisibilityState.columns).forEach(function (key) {
                    if (typeof legacyColumns[key] === 'boolean') {
                        migrated.cloudVisibilityState.columns[key] = legacyColumns[key];
                    }
                });
            }
            var myFilesExpanded = parseBool(window.localStorage.getItem(LEGACY_SECTION_MY_FILES_KEY), true);
            var sharedExpanded = parseBool(window.localStorage.getItem(LEGACY_SECTION_SHARED_FOR_ME_KEY), true);
            migrated.cloudVisibilityState.sections.my_files = myFilesExpanded;
            migrated.cloudVisibilityState.sections.shared_for_me = sharedExpanded;
            writeLocalCanonicalPreferences(migrated);
            window.localStorage.setItem(MIGRATION_STORAGE_KEY, '1');

            return migrated;
        } catch (e) {
            return null;
        }
    }

    /**
     * @brief Normalize listing sort field token from query state.
     * @param {unknown} raw Raw sort field value.
     * @return {string}
     * @date 2026-06-25
     * @author Stephane H.
     */
    function normalizeSortField(raw) {
        var value = typeof raw === 'string' ? raw.trim().toLowerCase() : '';
        if (value === 'type') {
            value = 'ext';
        }
        if (value === 'name' || value === 'size' || value === 'uploaded' || value === 'modified' || value === 'ext') {
            return value;
        }
        return 'name';
    }

    /**
     * @brief Normalize listing sort direction token from query state.
     * @param {unknown} raw Raw sort direction value.
     * @return {string}
     * @date 2026-06-25
     * @author Stephane H.
     */
    function normalizeSortDirection(raw) {
        var value = typeof raw === 'string' ? raw.trim().toLowerCase() : '';
        if (value === 'asc' || value === 'desc') {
            return value;
        }
        return 'asc';
    }

    /**
     * @brief Return current UI state snapshot from listing/query and localStorage.
     * @param void No input parameter.
     * @return {Record<string, unknown>}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function snapshotCurrentUiPreferences() {
        var defaults = getDefaultPreferences();
        var listing = readListingState();
        var rawColumns = parseJsonObject(String(window.localStorage.getItem(LEGACY_COLUMN_KEY) || '{}'), {});
        var columns = JSON.parse(JSON.stringify(defaults.cloudVisibilityState.columns));
        Object.keys(columns).forEach(function (key) {
            if (typeof rawColumns[key] === 'boolean') {
                columns[key] = rawColumns[key];
            }
        });

        var sections = JSON.parse(JSON.stringify(defaults.cloudVisibilityState.sections));
        sections.my_files = parseBool(String(window.localStorage.getItem(LEGACY_SECTION_MY_FILES_KEY) || ''), sections.my_files);
        sections.shared_for_me = parseBool(String(window.localStorage.getItem(LEGACY_SECTION_SHARED_FOR_ME_KEY) || ''), sections.shared_for_me);

        var scope = typeof listing.listing_scope === 'string' && listing.listing_scope !== '' ? listing.listing_scope : 'both';
        var view = typeof listing.view === 'string' && listing.view !== '' ? listing.view : 'list';

        return {
            filesViewMode: view === 'grid' ? 'grid' : 'list',
            filesScope: (scope === 'owned' || scope === 'shared') ? scope : 'both',
            filesSortField: normalizeSortField(listing.sort),
            filesSortDirection: normalizeSortDirection(listing.dir),
            cloudVisibilityState: {
                columns: columns,
                sections: sections
            }
        };
    }

    /**
     * @brief Build URLSearchParams from listing query object.
     * @param {Record<string, unknown>} state Listing query map.
     * @return {URLSearchParams}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function buildQueryParams(state) {
        var params = new URLSearchParams();
        Object.keys(state).forEach(function (key) {
            var value = state[key];
            if (value === null || value === undefined || value === '') {
                return;
            }
            if (Array.isArray(value)) {
                value.forEach(function (item) {
                    params.append(key + '[]', String(item));
                });
                return;
            }
            params.set(key, String(value));
        });
        return params;
    }

    /**
     * @brief Apply view and scope preferences by updating query state, then reload once if needed.
     * @param {Record<string, unknown>} pref Preference object.
     * @return {void}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function applyViewAndScopeWithReload(pref) {
        var state = readListingState();
        var desiredView = pref.filesViewMode === 'grid' ? 'grid' : 'list';
        var desiredScope = pref.filesScope === 'owned' || pref.filesScope === 'shared' ? pref.filesScope : 'both';
        var currentView = typeof state.view === 'string' && state.view !== '' ? state.view : 'list';
        var currentScope = typeof state.listing_scope === 'string' && state.listing_scope !== '' ? state.listing_scope : 'both';

        if (desiredView === 'list') {
            delete state.view;
        } else {
            state.view = 'grid';
        }
        if (desiredScope === 'both') {
            delete state.listing_scope;
        } else {
            state.listing_scope = desiredScope;
        }
        writeListingState(state);

        if (desiredView === currentView && desiredScope === currentScope) {
            return;
        }
        if (window.location.search.indexOf('ui_pref_applied=1') !== -1) {
            return;
        }
        var params = buildQueryParams(state);
        params.set('ui_pref_applied', '1');
        var target = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
        window.location.replace(target);
    }

    /**
     * @brief Apply cloud visibility preferences into existing legacy localStorage keys.
     * @param {Record<string, unknown>} pref Preference object.
     * @return {void}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function applyCloudVisibilityPreferences(pref) {
        if (!pref.cloudVisibilityState || typeof pref.cloudVisibilityState !== 'object') {
            return;
        }
        var cloudState = pref.cloudVisibilityState;
        if (cloudState.columns && typeof cloudState.columns === 'object') {
            try {
                window.localStorage.setItem(LEGACY_COLUMN_KEY, JSON.stringify(cloudState.columns));
            } catch (e) {
                return;
            }
        }
        if (cloudState.sections && typeof cloudState.sections === 'object') {
            try {
                var myFilesValue = cloudState.sections.my_files ? '1' : '0';
                var sharedValue = cloudState.sections.shared_for_me ? '1' : '0';
                window.localStorage.setItem(LEGACY_SECTION_MY_FILES_KEY, myFilesValue);
                window.localStorage.setItem(LEGACY_SECTION_SHARED_FOR_ME_KEY, sharedValue);
            } catch (e) {
                return;
            }
        }
    }

    /**
     * @brief Fetch backend preferences for one device.
     * @param {string} deviceId Opaque device identifier.
     * @return {Promise<Record<string, unknown>|null>}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function fetchBackendPreferences(deviceId) {
        var getUrl = liveRegion.getAttribute('data-files-ui-pref-get-url') || '';
        if (getUrl === '') {
            return Promise.resolve(null);
        }
        var url = getUrl + (getUrl.indexOf('?') >= 0 ? '&' : '?') + 'deviceId=' + encodeURIComponent(deviceId);

        return fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(function (response) {
                if (!response.ok) {
                    return null;
                }
                return response.json();
            })
            .then(function (json) {
                if (!json || typeof json !== 'object') {
                    return null;
                }
                if (!json.preferences || typeof json.preferences !== 'object') {
                    return null;
                }
                return json.preferences;
            })
            .catch(function () {
                return null;
            });
    }

    /**
     * @brief Persist preferences to backend API.
     * @param {string} deviceId Opaque device identifier.
     * @param {Record<string, unknown>} pref Preference payload.
     * @return {Promise<boolean>}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function saveBackendPreferences(deviceId, pref) {
        var saveUrl = liveRegion.getAttribute('data-files-ui-pref-save-url') || '';
        var csrf = liveRegion.getAttribute('data-files-ui-pref-csrf') || '';
        if (saveUrl === '' || csrf === '') {
            return Promise.resolve(false);
        }
        return fetch(saveUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                deviceId: deviceId,
                preferences: pref
            })
        })
            .then(function (response) {
                return response.ok;
            })
            .catch(function () {
                return false;
            });
    }

    /**
     * @brief Queue one debounced save of the current UI snapshot.
     * @param void No input parameter.
     * @return {void}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function queueSaveCurrentPreferences() {
        if (!hasLoadedBackend) {
            return;
        }
        if (saveTimer) {
            window.clearTimeout(saveTimer);
        }
        saveTimer = window.setTimeout(function () {
            var snapshot = snapshotCurrentUiPreferences();
            writeLocalCanonicalPreferences(snapshot);
            if (!isAuthenticatedContext()) {
                return;
            }
            if (saveInFlight) {
                pendingSaveAfterInFlight = true;
                return;
            }
            saveInFlight = true;
            var deviceId = getOrCreateDeviceId();
            saveBackendPreferences(deviceId, snapshot)
                .finally(function () {
                    saveInFlight = false;
                    if (pendingSaveAfterInFlight) {
                        pendingSaveAfterInFlight = false;
                        queueSaveCurrentPreferences();
                    }
                });
        }, SAVE_DEBOUNCE_MS);
    }

    /**
     * @brief Bind save listeners on existing files toolbar and accordion interactions.
     * @param void No input parameter.
     * @return {void}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function bindSaveListeners() {
        document.addEventListener('click', function (event) {
            var node = event.target && event.target.closest
                ? event.target.closest('a[data-files-view-toggle], a[data-files-listing-scope], a[href*="sort="], a[href*="dir="]')
                : null;
            if (!node) {
                return;
            }
            window.setTimeout(queueSaveCurrentPreferences, 50);
        });

        document.addEventListener('change', function (event) {
            var target = event.target;
            if (target && target.matches && target.matches('[data-files-col-toggle], #adv-sort, #adv-dir')) {
                queueSaveCurrentPreferences();
            }
        });

        document.addEventListener('shown.bs.collapse', function (event) {
            var target = event.target;
            if (!target || !target.getAttribute || !target.getAttribute('data-files-section')) {
                return;
            }
            queueSaveCurrentPreferences();
        });
        document.addEventListener('hidden.bs.collapse', function (event) {
            var target = event.target;
            if (!target || !target.getAttribute || !target.getAttribute('data-files-section')) {
                return;
            }
            queueSaveCurrentPreferences();
        });
    }

    /**
     * @brief Bootstrap preference loading sequence and apply restored state.
     * @param void No input parameter.
     * @return {void}
     * @date 2026-05-03
     * @author Stephane H.
     */
    function bootstrapPreferences() {
        var migrated = migrateLegacyLocalStorageOnce();
        var localCanonical = readLocalCanonicalPreferences();
        var localOrDefault = localCanonical || migrated || getDefaultPreferences();

        applyCloudVisibilityPreferences(localOrDefault);
        if (!isAuthenticatedContext()) {
            hasLoadedBackend = true;
            applyViewAndScopeWithReload(localOrDefault);
            bindSaveListeners();
            return;
        }

        var deviceId = getOrCreateDeviceId();
        fetchBackendPreferences(deviceId).then(function (backendPref) {
            var resolved = backendPref && typeof backendPref === 'object' ? backendPref : localOrDefault;
            writeLocalCanonicalPreferences(resolved);
            applyCloudVisibilityPreferences(resolved);
            hasLoadedBackend = true;
            applyViewAndScopeWithReload(resolved);

            if ((!backendPref || typeof backendPref !== 'object') && localCanonical && typeof localCanonical === 'object') {
                saveBackendPreferences(deviceId, localCanonical);
            }
        }).finally(function () {
            bindSaveListeners();
        });
    }

    bootstrapPreferences();
})();
