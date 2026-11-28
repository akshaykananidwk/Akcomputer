// Service worker for both installable apps (the shop's billing app and the
// online store). Only the store's own static files and the offline page are
// cached; every page and every piece of shop or customer data is always
// fetched fresh from the server and never stored on the phone.
var CACHE = 'akc-static-v2';
var STATIC = ['offline.html', 'assets/store.css', 'assets/store.js', 'assets/icon.svg', 'assets/icon-192.png'];
self.addEventListener('install', function (e) {
  self.skipWaiting();
  e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(STATIC).catch(function () {}); }));
});
self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (ks) { return Promise.all(ks.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); })); }).then(function () { return self.clients.claim(); }));
});
self.addEventListener('fetch', function (e) {
  var r = e.request;
  if (r.method !== 'GET') return;
  var u = new URL(r.url);
  if (u.origin !== location.origin) return;
  // pages: always the network; the offline page only when there is no network at all
  if (r.mode === 'navigate') {
    e.respondWith(fetch(r).catch(function () { return caches.match('offline.html'); }));
    return;
  }
  // the store's versioned css/js and icons: cache first, refreshed in the background
  if (/\/assets\/(store\.(css|js)|icon[^/]*)$/.test(u.pathname)) {
    e.respondWith(caches.open(CACHE).then(function (c) {
      return c.match(r).then(function (hit) {
        var net = fetch(r).then(function (res) { if (res.ok) c.put(r, res.clone()); return res; }).catch(function () { return hit; });
        return hit || net;
      });
    }));
  }
});
