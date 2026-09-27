// ---------------------------------------------------------------------------
// The sync engine.
//
// Two directions, and they are deliberately different:
//
//   PULL (masters: items, parties)  - the SERVER owns these. The phone takes
//       what it is given and overwrites its copy. Nobody edits an item on the
//       phone, so there is nothing to merge and no conflict can arise.
//
//   PUSH (documents: bills, payments) - the PHONE owns these. They are only
//       ever created, never edited, and they go up one at a time carrying the
//       UUID they were born with. The server recognises a repeat and answers
//       with the document it already has.
//
// That split is the whole design. Conflict resolution is a hard problem
// nobody gets right at this size; not having conflicts at all is easier and
// safer than solving them.
//
// What can go wrong, and what happens:
//   - no signal            -> nothing is attempted; the queue waits.
//   - request times out    -> stays queued, tried again later. The UUID makes
//                             a double save impossible even if the server did
//                             in fact receive it.
//   - server says 5xx      -> same: it is not the phone's fault, so it waits.
//   - server says 401      -> the token is dead. The app asks for a login
//                             again and the queue is kept, not dropped.
//   - server says 4xx      -> the document itself is wrong (a deleted item, a
//                             locked period). Retrying for ever would never
//                             help, so it is parked as "needs attention" with
//                             the server's own words and the owner is shown it.
// ---------------------------------------------------------------------------
var Sync = {
  running: false,
  BACKOFF: [0, 5, 30, 120, 600],   // seconds before attempt 1, 2, 3...

  base: function () {
    return DB.meta('server', '').then(function (s) {
      return (s || '').replace(/\/+$/, '');
    });
  },

  headers: function () {
    return DB.meta('token', '').then(function (t) {
      return { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + t };
    });
  },

  /** One fetch with a timeout. A phone on a dying 2G signal will otherwise
   *  hang for a minute on every request and the app will feel broken. */
  fetchJson: function (url, opt, timeoutMs) {
    opt = opt || {};
    var ctl = ('AbortController' in window) ? new AbortController() : null;
    if (ctl) opt.signal = ctl.signal;
    var timer = setTimeout(function () { if (ctl) ctl.abort(); }, timeoutMs || 20000);
    return fetch(url, opt).then(function (r) {
      clearTimeout(timer);
      return r.text().then(function (txt) {
        var data = null;
        try { data = txt ? JSON.parse(txt) : null; } catch (e) { data = null; }
        return { ok: r.ok, status: r.status, data: data, raw: txt };
      });
    }, function (err) {
      clearTimeout(timer);
      throw err;
    });
  },

  // ---- login ---------------------------------------------------------------
  login: function (server, username, password, device) {
    server = (server || '').replace(/\/+$/, '');
    return this.fetchJson(server + '/api.php?r=login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username: username, password: password, device: device || 'Android' })
    }, 25000).then(function (res) {
      if (!res.ok || !res.data || !res.data.token) {
        throw new Error((res.data && res.data.error) || ('Could not log in (' + res.status + ')'));
      }
      return Promise.all([
        DB.setMeta('server', server),
        DB.setMeta('token', res.data.token),
        DB.setMeta('user', res.data.user),
        DB.setMeta('shop', res.data.shop || 'AK Computer')
      ]).then(function () { return res.data; });
    });
  },

  // ---- pull ----------------------------------------------------------------
  pull: function () {
    var self = this;
    return Promise.all([this.base(), this.headers(), DB.meta('since', '')])
      .then(function (a) {
        var base = a[0], hdr = a[1], since = a[2];
        if (!base) throw new Error('No shop address set');
        var url = base + '/api.php?r=sync' + (since ? '&since=' + encodeURIComponent(since) : '');
        return self.fetchJson(url, { headers: hdr }, 45000);
      })
      .then(function (res) {
        if (res.status === 401) { return DB.setMeta('token_bad', 1).then(function () { throw new Error('Please log in again'); }); }
        if (!res.ok || !res.data) throw new Error('The shop is not answering (' + res.status + ')');
        var d = res.data;
        return Promise.all([
          DB.putMany('items', d.items || []),
          DB.putMany('parties', d.parties || []),
          // the server's own copy: replaced, not merged. A serial sold on the
          // website, or a bill cancelled there, has to VANISH from the phone,
          // and only a replace does that.
          DB.replaceAll('serials', d.serials || []),
          DB.replaceAll('stock', (d.stock || []).map(function (s) {
            s.key = s.item_id + ':' + s.location_id; return s;
          })),
          DB.replaceAll('ssales', d.sales || []),
          DB.replaceAll('spayments', d.payments || []),
          DB.setMeta('locations', d.locations || []),
          DB.setMeta('companies', d.companies || []),
          DB.setMeta('modes', d.payment_modes || []),
          DB.setMeta('shop', d.shop || 'AK Computer'),
          // the SERVER's clock, never the phone's: a phone an hour slow would
          // ask for the same window for ever and miss everything in between
          DB.setMeta('since', d.server_time),
          DB.setMeta('last_pull', new Date().toISOString())
        ]).then(function () {
          return { items: (d.items || []).length, parties: (d.parties || []).length, full: !!d.full };
        });
      });
  },

  /** The list of every screen the shop has, straight from the website's own
   *  menu. Cached, so the list is there with no signal even though opening
   *  one of those screens is not. Its own call and its own failure: a shop
   *  whose menu did not come down must still be able to write a bill. */
  pullMenu: function () {
    var self = this;
    return Promise.all([this.base(), this.headers()]).then(function (a) {
      if (!a[0]) return null;
      return self.fetchJson(a[0] + '/api.php?r=menu', { headers: a[1] }, 20000);
    }).then(function (res) {
      if (!res || !res.ok || !res.data || !res.data.groups) return null;
      return DB.setMeta('menu', res.data.groups).then(function () { return res.data.groups; });
    }).catch(function () { return null; });
  },

  /** A one-time url that opens a website screen already logged in. Needs the
   *  network by its nature - there is nothing to open without it. */
  webLink: function (to) {
    var self = this, base;
    return this.base().then(function (b) {
      base = b;
      return self.headers();
    }).then(function (hdr) {
      return self.fetchJson(base + '/api.php?r=weblink&to=' + encodeURIComponent(to), { headers: hdr }, 15000);
    }).then(function (res) {
      if (res.status === 401) { return DB.setMeta('token_bad', 1).then(function () { throw new Error('Please log in again'); }); }
      if (!res.ok || !res.data || !res.data.url) throw new Error((res.data && res.data.error) || 'Could not open it');
      return base + '/' + res.data.url;
    });
  },

  // ---- push ----------------------------------------------------------------
  /** Put a document in the queue. Called right after it is saved locally -
   *  saving and sending are separate steps so a save never depends on a
   *  network that may not be there. */
  queue: function (doc) {
    return DB.put('outbox', {
      uuid: doc.uuid, kind: doc.kind, body: doc.body,
      state: 'pending', attempts: 0, last_error: '', next_try: 0,
      created_at: doc.created_at || new Date().toISOString()
    });
  },

  KINDS: { payment: 'payments', sale: 'sales', whatsapp: 'whatsapp', reminder: 'reminder', order: 'worder' },

  pushOne: function (row) {
    var self = this;
    var path = this.KINDS[row.kind] || 'sales';
    // a customer's order goes up as the CUSTOMER - with the dealer's token
    // if they have one, and with none at all if they are a walk-in. Sending
    // it with a staff token would price it as staff.
    var auth = row.kind === 'order'
      ? DB.meta('wtoken', '').then(function (t) {
          return t ? { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + t }
                   : { 'Content-Type': 'application/json' };
        })
      : this.headers();
    return Promise.all([this.base(), auth]).then(function (a) {
      return self.fetchJson(a[0] + '/api.php?r=' + path, {
        method: 'POST', headers: a[1], body: JSON.stringify(row.body)
      }, 30000);
    }).then(function (res) {
      if (res.ok && res.data && (res.data.order_no || (row.kind === 'order' && res.data.duplicate))) {
        return DB.get('docs', row.uuid).then(function (doc) {
          if (doc) { doc.synced = true; doc.order_no = res.data.order_no; return DB.put('docs', doc); }
        }).then(function () { return DB.del('outbox', row.uuid); })
          .then(function () { return { ok: true }; });
      }
      // an errand, not a document: nothing to write back, it is simply done
      if (res.ok && res.data && res.data.sent) {
        return DB.del('outbox', row.uuid).then(function () { return { ok: true }; });
      }
      // 409 means "not yet" - a WhatsApp send whose bill is still in the
      // queue ahead of it. That is a wait, not a failure, so it goes round
      // again instead of onto the "needs attention" pile.
      if (res.status === 409) {
        throw new Error((res.data && res.data.error) || 'Waiting for the bill to go up first');
      }
      // 200 with duplicate:true is the happy ending of a retry - the server
      // had it all along and nothing was written twice
      if (res.ok && res.data && (res.data.id || res.data.duplicate)) {
        return DB.get('docs', row.uuid).then(function (doc) {
          if (doc) {
            doc.synced = true;
            doc.server_id = res.data.id;
            doc.invoice_no = res.data.invoice_no || doc.invoice_no;
            doc.synced_at = new Date().toISOString();
            return DB.put('docs', doc);
          }
        }).then(function () {
          return DB.del('outbox', row.uuid);
        }).then(function () { return { ok: true, duplicate: !!(res.data && res.data.duplicate) }; });
      }
      if (res.status === 401) {
        return DB.setMeta('token_bad', 1).then(function () { return { ok: false, stop: true, error: 'Please log in again' }; });
      }
      // 4xx that is not 401 = this document will never be accepted as it is
      if (res.status >= 400 && res.status < 500) {
        row.state = 'attention';
        row.last_error = (res.data && res.data.error) || ('The shop refused it (' + res.status + ')');
        return DB.put('outbox', row).then(function () { return { ok: false, error: row.last_error }; });
      }
      throw new Error((res.data && res.data.error) || ('Server (' + res.status + ')'));
    }).catch(function (err) {
      if (err && err.__handled) throw err;
      // network / timeout / 5xx - not this document's fault, so it waits
      row.attempts = (row.attempts || 0) + 1;
      row.state = 'pending';
      row.last_error = (err && err.message) || 'No network';
      var wait = Sync.BACKOFF[Math.min(row.attempts, Sync.BACKOFF.length - 1)];
      row.next_try = Date.now() + wait * 1000;
      return DB.put('outbox', row).then(function () { return { ok: false, retry: true, error: row.last_error }; });
    });
  },

  /** $force is what the Sync button means. A failed attempt backs off - 5
   *  seconds, then 30, then 2 minutes - which is right for the background,
   *  and wrong for a person standing there having just pressed the button.
   *  When a human asks, the wait is ignored. */
  push: function (force) {
    var self = this;
    return DB.all('outbox').then(function (rows) {
      var due = rows.filter(function (r) {
        return r.state === 'pending' && (force || !r.next_try || r.next_try <= Date.now());
      }).sort(function (a, b) { return (a.created_at || '').localeCompare(b.created_at || ''); });
      var sent = 0, failed = 0;
      // one at a time, oldest first: bills reach the shop in the order they
      // were written, and a queue of fifty does not open fifty connections
      return due.reduce(function (chain, row) {
        return chain.then(function (stop) {
          if (stop) return true;
          return self.pushOne(row).then(function (r) {
            if (r.ok) sent++; else failed++;
            return !!r.stop;
          });
        });
      }, Promise.resolve(false)).then(function () {
        return { sent: sent, failed: failed, left: rows.length - sent };
      });
    });
  },

  /** Everything, in the right order: send what is waiting first (so a bill
   *  written offline is safe), then take what is new. */
  run: function (force) {
    var self = this;
    if (this.running) return Promise.resolve({ skipped: true });
    if (!navigator.onLine) return Promise.resolve({ offline: true });
    this.running = true;
    var out = {};
    return this.push(force)
      .then(function (p) { out.push = p; return self.pull(); })
      .then(function (p) { out.pull = p; return self.pullMenu(); })
      .then(function () { out.ok = true; return out; })
      .catch(function (e) { out.ok = false; out.error = (e && e.message) || 'Something went wrong'; return out; })
      .then(function (res) { self.running = false; return res; });
  },

  status: function () {
    return Promise.all([DB.all('outbox'), DB.meta('last_pull', ''), DB.meta('token_bad', 0)])
      .then(function (a) {
        var rows = a[0];
        return {
          pending: rows.filter(function (r) { return r.state === 'pending'; }).length,
          attention: rows.filter(function (r) { return r.state === 'attention'; }).length,
          last_pull: a[1],
          token_bad: !!a[2],
          online: navigator.onLine
        };
      });
  }
};
