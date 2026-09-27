// ---------------------------------------------------------------------------
// The phone's own database.
//
// Everything the app shows comes from HERE, never from the network. The
// network only fills this up (masters) and empties the outbox (documents).
// That one decision is what makes the app work with no signal: there is no
// screen anywhere that waits for a reply.
//
// Stores:
//   meta      - token, logged-in user, last sync time, the menu   (key/value)
//   items     - the item master, as the server last told us        (by id)
//   parties   - the party master, same                             (by id)
//   serials   - serial numbers ON THE SHELF, so a camera can be billed
//               by its serial with no signal                       (by id)
//   stock     - how much of what is where                          (item:loc)
//   ssales    - the SHOP's recent bills (everyone's, not this phone's)
//   spayments - the shop's recent payments. These two are what make a
//               statement, an outstanding list and the day's figures work
//               offline - without them the phone only knows its own corner.
//   docs      - bills and payments written ON THIS PHONE           (by uuid)
//   outbox    - what still has to reach the server                 (by uuid)
//
// docs and outbox are deliberately separate. A bill stays in docs for ever -
// it is the shop's record and the thing the screen lists. Its outbox row is
// the errand: "this still needs sending", and it disappears once sent. If
// they were one row, clearing the queue would delete the bill.
//
// ssales/spayments are the server's copy and are REPLACED wholesale on every
// pull; docs/outbox are the phone's own and are never touched by a pull.
// Keeping the two apart is what stops a sync ever eating an unsent bill.
// ---------------------------------------------------------------------------
var DB = {
  _db: null,
  NAME: 'akshop',
  VERSION: 2,

  open: function () {
    var self = this;
    if (this._db) return Promise.resolve(this._db);
    return new Promise(function (resolve, reject) {
      var rq = indexedDB.open(self.NAME, self.VERSION);
      rq.onupgradeneeded = function (ev) {
        var db = ev.target.result;
        if (!db.objectStoreNames.contains('meta')) db.createObjectStore('meta');
        if (!db.objectStoreNames.contains('items')) {
          var s = db.createObjectStore('items', { keyPath: 'id' });
          s.createIndex('name', 'name', { unique: false });
        }
        if (!db.objectStoreNames.contains('parties')) {
          var p = db.createObjectStore('parties', { keyPath: 'id' });
          p.createIndex('name', 'name', { unique: false });
        }
        if (!db.objectStoreNames.contains('docs')) {
          var d = db.createObjectStore('docs', { keyPath: 'uuid' });
          d.createIndex('kind', 'kind', { unique: false });
          d.createIndex('created_at', 'created_at', { unique: false });
        }
        if (!db.objectStoreNames.contains('outbox')) {
          var o = db.createObjectStore('outbox', { keyPath: 'uuid' });
          o.createIndex('state', 'state', { unique: false });
        }
        // v2 - everything the phone needs for a full day, not just billing.
        // Added by name, so a phone upgrading from v1 keeps its unsent
        // bills: nothing here touches docs or outbox.
        if (!db.objectStoreNames.contains('serials')) {
          var sr = db.createObjectStore('serials', { keyPath: 'id' });
          sr.createIndex('item_id', 'item_id', { unique: false });
        }
        if (!db.objectStoreNames.contains('stock')) db.createObjectStore('stock', { keyPath: 'key' });
        if (!db.objectStoreNames.contains('ssales')) {
          var ss = db.createObjectStore('ssales', { keyPath: 'id' });
          ss.createIndex('party_id', 'party_id', { unique: false });
        }
        if (!db.objectStoreNames.contains('spayments')) {
          var sp = db.createObjectStore('spayments', { keyPath: 'id' });
          sp.createIndex('party_id', 'party_id', { unique: false });
        }
      };
      rq.onsuccess = function () { self._db = rq.result; resolve(self._db); };
      rq.onerror = function () { reject(rq.error); };
    });
  },

  _tx: function (store, mode) {
    return this.open().then(function (db) {
      return db.transaction(store, mode).objectStore(store);
    });
  },

  get: function (store, key) {
    return this._tx(store, 'readonly').then(function (st) {
      return new Promise(function (res, rej) {
        var r = st.get(key);
        r.onsuccess = function () { res(r.result); };
        r.onerror = function () { rej(r.error); };
      });
    });
  },

  put: function (store, value, key) {
    return this._tx(store, 'readwrite').then(function (st) {
      return new Promise(function (res, rej) {
        var r = key === undefined ? st.put(value) : st.put(value, key);
        r.onsuccess = function () { res(r.result); };
        r.onerror = function () { rej(r.error); };
      });
    });
  },

  del: function (store, key) {
    return this._tx(store, 'readwrite').then(function (st) {
      return new Promise(function (res, rej) {
        var r = st.delete(key);
        r.onsuccess = function () { res(); };
        r.onerror = function () { rej(r.error); };
      });
    });
  },

  all: function (store) {
    return this._tx(store, 'readonly').then(function (st) {
      return new Promise(function (res, rej) {
        var r = st.getAll();
        r.onsuccess = function () { res(r.result || []); };
        r.onerror = function () { rej(r.error); };
      });
    });
  },

  count: function (store) {
    return this._tx(store, 'readonly').then(function (st) {
      return new Promise(function (res, rej) {
        var r = st.count();
        r.onsuccess = function () { res(r.result); };
        r.onerror = function () { rej(r.error); };
      });
    });
  },

  /** Write many rows in ONE transaction. A sync that brings 3,000 items
   *  must not be 3,000 transactions - on a cheap phone that is the
   *  difference between two seconds and two minutes. */
  putMany: function (store, rows) {
    if (!rows || !rows.length) return Promise.resolve(0);
    return this.open().then(function (db) {
      return new Promise(function (res, rej) {
        var tx = db.transaction(store, 'readwrite');
        var st = tx.objectStore(store);
        rows.forEach(function (r) { st.put(r); });
        tx.oncomplete = function () { res(rows.length); };
        tx.onerror = function () { rej(tx.error); };
        tx.onabort = function () { rej(tx.error); };
      });
    });
  },

  /** Throw the store away and put these rows in its place, in ONE
   *  transaction. Used for the server's own copy of things (serials, stock,
   *  the recent books) where the server re-sends the whole window every
   *  time: a row deleted on the website has to disappear from the phone
   *  too, and only a replace does that. Never used on docs or outbox. */
  replaceAll: function (store, rows) {
    return this.open().then(function (db) {
      return new Promise(function (res, rej) {
        var tx = db.transaction(store, 'readwrite');
        var st = tx.objectStore(store);
        st.clear();
        (rows || []).forEach(function (r) { st.put(r); });
        tx.oncomplete = function () { res((rows || []).length); };
        tx.onerror = function () { rej(tx.error); };
        tx.onabort = function () { rej(tx.error); };
      });
    });
  },

  /** Rows of an index, e.g. every bill of one party. */
  by: function (store, index, key) {
    return this._tx(store, 'readonly').then(function (st) {
      return new Promise(function (res, rej) {
        var r = st.index(index).getAll(key);
        r.onsuccess = function () { res(r.result || []); };
        r.onerror = function () { rej(r.error); };
      });
    });
  },

  clearAll: function () {
    return this.open().then(function (db) {
      return new Promise(function (res, rej) {
        var names = ['meta', 'items', 'parties', 'docs', 'outbox', 'serials', 'stock', 'ssales', 'spayments'];
        var tx = db.transaction(names, 'readwrite');
        names.forEach(function (n) { tx.objectStore(n).clear(); });
        tx.oncomplete = function () { res(); };
        tx.onerror = function () { rej(tx.error); };
      });
    });
  },

  // --- meta shortcuts -------------------------------------------------------
  meta: function (key, def) {
    return this.get('meta', key).then(function (v) { return v === undefined ? def : v; });
  },
  setMeta: function (key, value) { return this.put('meta', value, key); }
};

/** A UUID made on the phone. This is what stops one bill becoming two when a
 *  reply is lost on the way back: the server recognises it and hands back the
 *  bill it already saved. crypto.randomUUID is not on older Android WebViews,
 *  so there is a fallback that is just as unique for this purpose. */
function newUuid() {
  if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
  var b = new Uint8Array(16);
  (window.crypto || {}).getRandomValues ? crypto.getRandomValues(b)
    : (function () { for (var i = 0; i < 16; i++) b[i] = Math.floor(Math.random() * 256); })();
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  var h = [];
  for (var i = 0; i < 16; i++) h.push((b[i] + 0x100).toString(16).slice(1));
  return h.slice(0, 4).join('') + '-' + h.slice(4, 6).join('') + '-' + h.slice(6, 8).join('') +
         '-' + h.slice(8, 10).join('') + '-' + h.slice(10, 16).join('');
}
