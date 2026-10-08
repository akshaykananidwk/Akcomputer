// The shop app's service worker: it only lets the phone install the app.
// Nothing is cached - every page is fetched fresh, so no shop data is kept on the phone by it.
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('fetch', function () {});
