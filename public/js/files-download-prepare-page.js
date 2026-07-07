(function () {
    'use strict';

    var root = document.getElementById('files-download-prepare-page');
    if (!root || typeof window.FilesDownloadPrepare === 'undefined') {
        return;
    }

    window.FilesDownloadPrepare.run({
        root: root,
        csrfToken: root.getAttribute('data-csrf-token') || '',
        prepareUrl: root.getAttribute('data-prepare-url') || '',
        tickUrlTemplate: root.getAttribute('data-tick-url-template') || '',
        deliverUrlTemplate: root.getAttribute('data-deliver-url-template') || ''
    });
}());
