(function () {
    'use strict';

    var DB_NAME = 'stuws_downloads';
    var DB_VERSION = 1;
    var STORE_NAME = 'checkpoints';
    var DEFAULT_TTL_MS = 7 * 24 * 60 * 60 * 1000;

    /**
     * @brief Open the downloads IndexedDB database.
     * @return {Promise<IDBDatabase>}
     * @date 2026-07-07
     * @author Stephane H.
     */
    function openDatabase() {
        return new Promise(function (resolve, reject) {
            if (!window.indexedDB) {
                reject(new Error('download.checkpoint.indexeddb_unavailable'));
                return;
            }
            var request = window.indexedDB.open(DB_NAME, DB_VERSION);
            request.onerror = function () {
                reject(request.error || new Error('download.checkpoint.open_failed'));
            };
            request.onsuccess = function () {
                resolve(request.result);
            };
            request.onupgradeneeded = function (event) {
                var db = event.target.result;
                if (!db.objectStoreNames.contains(STORE_NAME)) {
                    db.createObjectStore(STORE_NAME, { keyPath: 'jobKey' });
                }
            };
        });
    }

    /**
     * @brief Build a stable checkpoint key for authenticated or public downloads.
     * @param {string} context auth or public.
     * @param {string|number} identifier Shared file id or public token.
     * @param {number} totalBytes Total plaintext size.
     * @return {string}
     * @date 2026-07-07
     * @author Stephane H.
     */
    function buildJobKey(context, identifier, totalBytes) {
        return String(context) + ':' + String(identifier) + ':' + String(totalBytes);
    }

    /**
     * @brief Load one checkpoint by job key.
     * @param {string} jobKey Stable job identifier.
     * @return {Promise<object|null>}
     * @date 2026-07-07
     * @author Stephane H.
     */
    function find(jobKey) {
        return openDatabase().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE_NAME, 'readonly');
                var store = tx.objectStore(STORE_NAME);
                var request = store.get(String(jobKey));
                request.onerror = function () {
                    reject(request.error || new Error('download.checkpoint.read_failed'));
                };
                request.onsuccess = function () {
                    resolve(request.result || null);
                };
            });
        });
    }

    /**
     * @brief Persist or update a checkpoint record.
     * @param {object} checkpoint Checkpoint payload.
     * @return {Promise<void>}
     * @date 2026-07-07
     * @author Stephane H.
     */
    function save(checkpoint) {
        var payload = Object.assign({}, checkpoint, {
            jobKey: String(checkpoint.jobKey),
            updatedAt: Date.now()
        });
        return openDatabase().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE_NAME, 'readwrite');
                var store = tx.objectStore(STORE_NAME);
                var request = store.put(payload);
                request.onerror = function () {
                    reject(request.error || new Error('download.checkpoint.write_failed'));
                };
                request.onsuccess = function () {
                    resolve();
                };
            });
        });
    }

    /**
     * @brief Remove one checkpoint and optional OPFS artifact metadata.
     * @param {string} jobKey Stable job identifier.
     * @return {Promise<void>}
     * @date 2026-07-07
     * @author Stephane H.
     */
    function clear(jobKey) {
        return openDatabase().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE_NAME, 'readwrite');
                var store = tx.objectStore(STORE_NAME);
                var request = store.delete(String(jobKey));
                request.onerror = function () {
                    reject(request.error || new Error('download.checkpoint.delete_failed'));
                };
                request.onsuccess = function () {
                    resolve();
                };
            });
        });
    }

    /**
     * @brief Delete checkpoints older than maxAgeMs.
     * @param {number} maxAgeMs Maximum checkpoint age in milliseconds.
     * @return {Promise<number>} Number of deleted rows.
     * @date 2026-07-07
     * @author Stephane H.
     */
    function purgeExpired(maxAgeMs) {
        var ttl = Number(maxAgeMs) || DEFAULT_TTL_MS;
        var cutoff = Date.now() - ttl;
        return openDatabase().then(function (db) {
            return new Promise(function (resolve, reject) {
                var deleted = 0;
                var tx = db.transaction(STORE_NAME, 'readwrite');
                var store = tx.objectStore(STORE_NAME);
                var request = store.openCursor();
                request.onerror = function () {
                    reject(request.error || new Error('download.checkpoint.purge_failed'));
                };
                request.onsuccess = function (event) {
                    var cursor = event.target.result;
                    if (!cursor) {
                        resolve(deleted);
                        return;
                    }
                    var value = cursor.value;
                    if (!value || Number(value.updatedAt || 0) < cutoff) {
                        cursor.delete();
                        deleted += 1;
                    }
                    cursor.continue();
                };
            });
        });
    }

    /**
     * @brief Return true when a checkpoint represents an incomplete download.
     * @param {object|null} checkpoint Checkpoint record.
     * @return {boolean}
     * @date 2026-07-07
     * @author Stephane H.
     */
    function isResumable(checkpoint) {
        if (!checkpoint) {
            return false;
        }
        var received = Number(checkpoint.receivedBytes) || 0;
        var total = Number(checkpoint.totalBytes) || 0;
        return total > 0 && received > 0 && received < total;
    }

  /**
   * @brief Acquire a per-tab lock to avoid parallel writes for the same job.
   * @param {string} jobKey Stable job identifier.
   * @return {boolean}
   * @date 2026-07-07
   * @author Stephane H.
   */
    function tryAcquireLock(jobKey) {
        try {
            var key = 'stuws-dl-lock:' + jobKey;
            if (window.sessionStorage.getItem(key)) {
                return false;
            }
            window.sessionStorage.setItem(key, String(Date.now()));
            return true;
        } catch (e) {
            return true;
        }
    }

    /**
     * @brief Release a per-tab download lock.
     * @param {string} jobKey Stable job identifier.
     * @return {void}
     * @date 2026-07-07
     * @author Stephane H.
     */
    function releaseLock(jobKey) {
        try {
            window.sessionStorage.removeItem('stuws-dl-lock:' + jobKey);
        } catch (e) {
            // ignore
        }
    }

    window.FilesDownloadCheckpoint = {
        DEFAULT_TTL_MS: DEFAULT_TTL_MS,
        buildJobKey: buildJobKey,
        find: find,
        save: save,
        clear: clear,
        purgeExpired: purgeExpired,
        isResumable: isResumable,
        tryAcquireLock: tryAcquireLock,
        releaseLock: releaseLock
    };

    window.FilesDownloadCheckpoint.purgeExpired(DEFAULT_TTL_MS).catch(function () {
        return null;
    });
}());
