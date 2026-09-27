// ---------------------------------------------------------------------------
// The screens.
//
// One rule runs through all of them: NOTHING WAITS FOR THE NETWORK. Every
// screen reads the phone's own database, every save writes to it and returns
// immediately, and sending is an errand that happens afterwards, by itself.
// The only screen that talks to the server while you watch is Login, because
// there is nothing else it can do.
// ---------------------------------------------------------------------------
var App = {
  screen: null,
  user: null,

  // --- small helpers --------------------------------------------------------
  el: function (id) { return document.getElementById(id); },
  money: function (n) {
    n = Math.round((parseFloat(n) || 0) * 100) / 100;
    return n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  },
  esc: function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  },
  today: function () {
    var d = new Date();
    return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
  },
  toast: function (msg, bad) {
    var t = this.el('toast');
    t.textContent = msg;
    t.className = 'toast' + (bad ? ' bad' : '');
    t.hidden = false;
    clearTimeout(this._tt);
    this._tt = setTimeout(function () { t.hidden = true; }, bad ? 4500 : 2500);
  },

  // --- boot -----------------------------------------------------------------
  start: function () {
    var self = this;
    this.el('syncBtn').addEventListener('click', function () { self.syncNow(true); });
    this.el('backBtn').addEventListener('click', function () { self.go('home'); });
    document.querySelectorAll('.tabs button').forEach(function (b) {
      b.addEventListener('click', function () { self.go(b.dataset.go); });
    });
    window.addEventListener('online', function () { self.netbar(); self.syncNow(false); });
    window.addEventListener('offline', function () { self.netbar(); });

    DB.meta('token', '').then(function (tok) {
      return DB.meta('user', null).then(function (u) {
        self.user = u;
        self.netbar();
        if (!tok || !u) { self.go('login'); return; }
        self.go('home');
        // catching up happens quietly in the background - the app is already
        // usable before the first byte arrives
        setTimeout(function () { self.syncNow(false); }, 800);
      });
    });
    // and again every few minutes while the app is open
    setInterval(function () { if (self.user) self.syncNow(false); }, 5 * 60 * 1000);
  },

  netbar: function () {
    var b = this.el('netbar');
    if (navigator.onLine) { b.hidden = true; return; }
    b.textContent = '📴 નેટવર્ક નથી — કામ ચાલુ રાખો, બધું સચવાય છે';
    b.className = 'netbar';
    b.hidden = false;
  },

  chrome: function (title, showBack) {
    this.el('top').hidden = false;
    this.el('tabs').hidden = false;
    this.el('title').textContent = title;
    this.el('backBtn').hidden = !showBack;
  },

  go: function (name, arg) {
    this.screen = name;
    document.querySelectorAll('.tabs button').forEach(function (b) {
      b.classList.toggle('on', b.dataset.go === name);
    });
    window.scrollTo(0, 0);
    var fn = this['scr_' + name];
    if (fn) fn.call(this, arg);
  },

  syncNow: function (loud) {
    var self = this;
    if (!navigator.onLine) { if (loud) this.toast('નેટવર્ક નથી — પછી જાતે થઈ જશે', true); return; }
    var btn = this.el('syncBtn');
    btn.textContent = '…';
    return Sync.run(loud).then(function (r) {
      btn.textContent = '⟳';
      if (r.skipped || r.offline) return;
      if (!r.ok) { if (loud) self.toast(r.error || 'સિંક થયું નહીં', true); }
      else if (loud) {
        var sent = (r.push && r.push.sent) || 0;
        self.toast(sent ? (sent + ' મોકલાઈ ગયું · સિંક પૂરું') : 'સિંક પૂરું');
      }
      return DB.meta('token_bad', 0).then(function (bad) {
        if (bad) { DB.setMeta('token_bad', 0); self.toast('ફરી લોગિન કરો', true); self.go('login'); return; }
        if (self.screen === 'home' || self.screen === 'sync' || self.screen === 'bills') self.go(self.screen);
      });
    });
  },

  // --- LOGIN ----------------------------------------------------------------
  scr_login: function () {
    var self = this;
    this.el('top').hidden = true;
    this.el('tabs').hidden = true;
    DB.meta('server', '').then(function (srv) {
      self.el('main').innerHTML =
        '<div class="logo">AK Computer</div>' +
        '<p class="center muted" style="margin:0 0 20px">દુકાનનો હિસાબ, ખિસ્સામાં</p>' +
        '<div class="card">' +
        '<label>દુકાનનું સરનામું (URL)</label>' +
        '<input type="url" id="l_srv" inputmode="url" autocapitalize="off" autocorrect="off" placeholder="https://shop.akdwk.in" value="' + self.esc(srv || 'https://shop.akdwk.in') + '">' +
        '<label>યુઝરનેમ</label><input type="text" id="l_user" autocapitalize="off" autocorrect="off">' +
        '<label>પાસવર્ડ</label><input type="password" id="l_pass">' +
        '<div class="err" id="l_err"></div>' +
        '<button class="btn" id="l_go">લોગિન કરો</button>' +
        '</div>' +
        '<p class="center muted" style="font-size:13px">પહેલી વાર લોગિન કરવા નેટવર્ક જોઈશે. પછી નેટ વગર પણ ચાલશે.</p>';
      self.el('l_go').addEventListener('click', function () { self.doLogin(); });
      self.el('l_pass').addEventListener('keydown', function (e) { if (e.key === 'Enter') self.doLogin(); });
    });
  },

  doLogin: function () {
    var self = this;
    var srv = this.el('l_srv').value.trim();
    var usr = this.el('l_user').value.trim();
    var pwd = this.el('l_pass').value;
    var err = this.el('l_err');
    err.textContent = '';
    if (!srv) { err.textContent = 'દુકાનનું સરનામું ભરો'; this.el('l_srv').focus(); return; }
    if (!usr) { err.textContent = 'યુઝરનેમ ભરો'; this.el('l_user').focus(); return; }
    if (!pwd) { err.textContent = 'પાસવર્ડ ભરો'; this.el('l_pass').focus(); return; }
    if (!navigator.onLine) { err.textContent = 'પહેલી વાર લોગિન કરવા નેટવર્ક જોઈએ'; return; }
    var btn = this.el('l_go');
    btn.disabled = true; btn.textContent = 'તપાસી રહ્યા છીએ…';
    Sync.login(srv, usr, pwd, 'Android').then(function (d) {
      self.user = d.user;
      btn.textContent = 'ડેટા લાવી રહ્યા છીએ…';
      // masters first - the app is usable the moment they land - then the
      // list of screens, which is nice to have and must not hold up login
      return Sync.pull().then(function () { return Sync.pullMenu(); });
    }).then(function () {
      btn.disabled = false; btn.textContent = 'લોગિન કરો';
      self.toast('આવકાર, ' + (self.user.name || ''));
      self.go('home');
    }).catch(function (e) {
      btn.disabled = false; btn.textContent = 'લોગિન કરો';
      err.textContent = (e && e.message) || 'લોગિન થયું નહીં';
    });
  },

  // --- HOME -----------------------------------------------------------------
  scr_home: function () {
    var self = this;
    this.chrome('AK Computer', false);
    Promise.all([DB.all('docs'), Sync.status(), DB.count('items'), DB.count('parties'),
                 DB.all('ssales'), DB.all('spayments')])
      .then(function (a) {
        var docs = a[0], st = a[1];
        var today = self.today();
        // the SHOP's day, not this phone's - plus whatever this phone has
        // written and not yet sent, which the server's copy cannot know about
        var unsent = docs.filter(function (d) { return !d.synced; });
        var sales = a[4].filter(function (s) { return s.sale_date === today && s.is_cancelled != 1; })
          .concat(unsent.filter(function (d) { return d.kind === 'sale' && d.date === today; }));
        var total = sales.reduce(function (s, d) { return s + (parseFloat(d.total) || 0); }, 0);
        var got = a[5].filter(function (p) { return p.pay_date === today && p.direction === 'in'; })
                      .reduce(function (s, p) { return s + (parseFloat(p.amount) || 0); }, 0)
                + unsent.filter(function (d) { return d.date === today; })
                        .reduce(function (s, d) { return s + (d.kind === 'payment' ? (d.amount || 0) : (d.paid || 0)); }, 0);
        self.el('main').innerHTML =
          '<div class="card">' +
          '<div class="muted">આજનું વેચાણ</div>' +
          '<div class="big">₹' + self.money(total) + '</div>' +
          '<div class="stat"><span>બિલ</span><strong>' + sales.length + '</strong></div>' +
          '<div class="stat"><span>આજે પૈસા આવ્યા</span><strong>₹' + self.money(got) + '</strong></div>' +
          '</div>' +
          '<button class="btn" id="h_new">🧾 નવું બિલ બનાવો</button>' +
          '<button class="btn sec" id="h_pay">₹ પૈસા લીધા નોંધો</button>' +
          '<div class="row" style="margin-top:8px">' +
          '<button class="btn sec" id="h_dues">📋 બાકી ઉઘરાણી</button>' +
          '<button class="btn sec" id="h_stock">📦 સ્ટોક</button>' +
          '</div>' +
          '<div class="row" style="margin-top:8px">' +
          '<button class="btn sec" id="h_rep">📊 રિપોર્ટ</button>' +
          '<button class="btn sec" id="h_all">☰ બધું</button>' +
          '</div>' +
          '<div class="card" style="margin-top:12px">' +
          '<div class="stat"><span>મોકલવાનું બાકી</span><strong>' +
            (st.pending ? '<span class="pill wait">' + st.pending + '</span>' : '<span class="pill done">0</span>') + '</strong></div>' +
          (st.attention ? '<div class="stat"><span>ધ્યાન આપવાનું</span><strong><span class="pill bad">' + st.attention + '</span></strong></div>' : '') +
          '<div class="stat"><span>આઇટમ / પાર્ટી</span><strong>' + a[2] + ' / ' + a[3] + '</strong></div>' +
          '<div class="stat"><span>છેલ્લે સિંક</span><strong>' + (st.last_pull ? self.ago(st.last_pull) : 'કદી નહીં') + '</strong></div>' +
          '</div>' +
          '<p class="center muted" style="font-size:13px">' + self.esc((self.user && self.user.name) || '') +
            ' · ' + self.esc((self.user && self.user.location) || '') + '</p>';
        self.el('h_new').addEventListener('click', function () { self.go('newbill'); });
        self.el('h_pay').addEventListener('click', function () { self.go('collect'); });
        self.el('h_dues').addEventListener('click', function () { self.go('dues'); });
        self.el('h_stock').addEventListener('click', function () { self.go('stock'); });
        self.el('h_rep').addEventListener('click', function () { self.go('reports'); });
        self.el('h_all').addEventListener('click', function () { self.go('all'); });
      });
  },

  ago: function (iso) {
    var s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 90) return 'હમણાં';
    if (s < 3600) return Math.round(s / 60) + ' મિનિટ પહેલાં';
    if (s < 86400) return Math.round(s / 3600) + ' કલાક પહેલાં';
    return Math.round(s / 86400) + ' દિવસ પહેલાં';
  },

  // --- NEW BILL -------------------------------------------------------------
  bill: null,

  /** Keep what has been typed so far ON DISK, not just in a variable.
   *
   *  A half-written bill is the easiest thing in the world to lose - the
   *  owner steps away to look up a serial, Android reclaims the app's
   *  memory, and twenty minutes of typing is gone. Saving the draft on
   *  every change costs nothing and means the bill is still there when the
   *  app comes back, however it came back. */
  saveDraft: function () {
    if (!this.bill) return Promise.resolve();
    this.stashHeader();
    return DB.setMeta('draft', this.bill);
  },

  /** The boxes at the top are only in the DOM; copy them into the draft
   *  before anything can navigate away from them. */
  stashHeader: function () {
    if (!this.bill) return;
    var c = this.el('b_cust'), m = this.el('b_mob'), p = this.el('b_paid'), md = this.el('b_mode');
    if (c) this.bill.customer = c.value.trim();
    if (m) this.bill.mobile = m.value.trim();
    if (p) this.bill.paid = parseFloat(p.value) || 0;
    if (md) this.bill.mode = md.value;
  },

  scr_newbill: function () {
    var self = this;
    this.chrome('નવું બિલ', true);
    if (!this.bill) {
      // nothing in memory: pick up a draft left behind by a restart before
      // starting a blank one
      return DB.meta('draft', null).then(function (d) {
        self.bill = (d && d.lines) ? d : { lines: [], party: null, customer: '', mobile: '', paid: 0, mode: 'cash' };
        if (d && d.lines && d.lines.length) self.toast('અધૂરું બિલ પાછું મળ્યું');
        self.scr_newbill();
      });
    }
    this.el('main').innerHTML =
      '<div class="card">' +
      '<label>ગ્રાહક</label>' +
      '<div class="pick"><input type="text" id="b_cust" placeholder="નામ કે મોબાઇલ ટાઇપ કરો (વૈકલ્પિક)" autocomplete="off"><div class="res" id="b_custres"></div></div>' +
      '<input type="tel" id="b_mob" placeholder="મોબાઇલ નંબર" inputmode="tel">' +
      '</div>' +
      '<div class="card">' +
      '<h3>આઇટમ</h3>' +
      '<div class="pick"><input type="text" id="b_item" placeholder="આઇટમનું નામ ટાઇપ કરો" autocomplete="off"><div class="res" id="b_itemres"></div></div>' +
      '<div class="lines" id="b_lines"></div>' +
      '</div>' +
      '<div class="card">' +
      '<div class="tot"><span>સરવાળો</span><span id="b_sub">₹0.00</span></div>' +
      '<div class="tot grand"><span>કુલ</span><span id="b_grand">₹0.00</span></div>' +
      '<label style="margin-top:10px">કેટલા પૈસા મળ્યા</label>' +
      '<div class="row"><input type="number" id="b_paid" inputmode="decimal" value="0"><button class="btn sec" id="b_full" style="max-width:110px">આખા</button></div>' +
      '<label>કઈ રીતે</label><select id="b_mode"></select>' +
      '<div class="err" id="b_err"></div>' +
      '<button class="btn ok" id="b_save">💾 બિલ સેવ કરો</button>' +
      (this.bill.lines.length ? '<button class="btn ghost" id="b_clear">બિલ રદ કરો</button>' : '') +
      '</div>';

    DB.meta('modes', []).then(function (modes) {
      var sel = self.el('b_mode');
      var list = (modes && modes.length) ? modes : [{ code: 'cash', name: 'રોકડ' }, { code: 'upi', name: 'UPI' }];
      sel.innerHTML = list.map(function (m) { return '<option value="' + self.esc(m.code) + '">' + self.esc(m.name) + '</option>'; }).join('') +
                      '<option value="credit">ઉધાર</option>';
      sel.value = self.bill.mode || 'cash';
    });

    this.wirePick('b_cust', 'b_custres', function (q) { return self.findParties(q); },
      function (p) {
        self.bill.party = p; self.el('b_cust').value = p.name; self.el('b_mob').value = p.mobile || '';
        if (p.balance > 0.009) self.toast('આ પાર્ટીના ₹' + self.money(p.balance) + ' બાકી છે');
      });
    this.wirePick('b_item', 'b_itemres', function (q) { return self.findItems(q); },
      function (it) { self.addLine(it); self.el('b_item').value = ''; });

    this.el('b_cust').value = this.bill.customer || '';
    this.el('b_mob').value = this.bill.mobile || '';
    this.el('b_paid').value = this.bill.paid || 0;
    this.el('b_full').addEventListener('click', function () {
      self.el('b_paid').value = self.billTotal().toFixed(2);
      self.saveDraft();
    });
    ['b_cust', 'b_mob', 'b_paid'].forEach(function (id) {
      self.el(id).addEventListener('change', function () { self.saveDraft(); });
    });
    this.el('b_save').addEventListener('click', function () { self.saveBill(); });
    if (this.el('b_clear')) this.el('b_clear').addEventListener('click', function () {
      if (!confirm('આખું બિલ ભૂંસી નાખવું?')) return;
      self.bill = null;
      DB.setMeta('draft', null).then(function () { self.go('home'); });
    });
    this.renderLines();
  },

  wirePick: function (inputId, resId, finder, onPick) {
    var self = this, t = null;
    var inp = this.el(inputId), res = this.el(resId);
    inp.addEventListener('input', function () {
      clearTimeout(t);
      var q = inp.value.trim();
      if (q.length < 1) { res.classList.remove('show'); return; }
      t = setTimeout(function () {
        finder(q).then(function (rows) {
          res.innerHTML = rows.length
            ? rows.map(function (r, i) {
                return '<div data-i="' + i + '"><strong>' + self.esc(r.name) + '</strong>' +
                       (r._sub ? '<div class="sub">' + self.esc(r._sub) + '</div>' : '') + '</div>';
              }).join('')
            : '<div class="muted">કંઈ મળ્યું નહીં</div>';
          res.classList.add('show');
          res.querySelectorAll('[data-i]').forEach(function (d) {
            d.addEventListener('click', function () {
              res.classList.remove('show');
              onPick(rows[parseInt(d.dataset.i, 10)]);
            });
          });
        });
      }, 120);
    });
    inp.addEventListener('blur', function () { setTimeout(function () { res.classList.remove('show'); }, 200); });
  },

  /** Word-by-word, any order — the same way the website searches, so what
   *  works at the counter works on the phone. */
  match: function (hay, q) {
    hay = (hay || '').toLowerCase();
    var toks = q.toLowerCase().trim().split(/\s+/);
    for (var i = 0; i < toks.length; i++) if (hay.indexOf(toks[i]) === -1) return false;
    return true;
  },

  findItems: function (q) {
    var self = this;
    return DB.all('items').then(function (rows) {
      return rows.filter(function (r) {
        return r.is_active != 0 && self.match(r.name + ' ' + (r.barcode || ''), q);
      }).slice(0, 25).map(function (r) {
        r._sub = '₹' + self.money(r.selling_price) + ' · સ્ટોક ' + (parseFloat(r.stock) || 0);
        return r;
      });
    });
  },

  findParties: function (q) {
    var self = this;
    return DB.all('parties').then(function (rows) {
      return rows.filter(function (r) {
        return r.is_active != 0 && self.match(r.name + ' ' + (r.mobile || ''), q);
      }).slice(0, 25).map(function (r) {
        var b = parseFloat(r.balance) || 0;
        r._sub = (r.mobile || '') + (Math.abs(b) > 0.009 ? ' · બાકી ₹' + self.money(Math.abs(b)) : '');
        return r;
      });
    });
  },

  addLine: function (it) {
    var ex = null;
    this.bill.lines.forEach(function (l) { if (l.item_id === it.id) ex = l; });
    if (ex) ex.qty += 1;
    else this.bill.lines.push({ item_id: it.id, name: it.name, qty: 1,
                                price: parseFloat(it.selling_price) || 0, stock: parseFloat(it.stock) || 0,
                                serial_tracked: it.serial_tracked == 1, serials: [] });
    this.renderLines();
    this.saveDraft();
  },

  /** The serials of this item that are on the shelf, minus the ones already
   *  put on another line of THIS bill - a camera cannot be sold twice on the
   *  same invoice any more than on two invoices. */
  freeSerials: function (itemId) {
    var used = {};
    (this.bill.lines || []).forEach(function (l) {
      (l.serials || []).forEach(function (s) { used[s] = 1; });
    });
    return DB.by('serials', 'item_id', itemId).then(function (rows) {
      return rows.filter(function (r) { return !used[r.serial_no]; });
    });
  },

  /** Pick the serial numbers for one line. Offered from the shelf, and
   *  typeable too: a piece billed the day it arrives is often not in the
   *  books yet, and the counter must not be stuck waiting for the purchase
   *  entry. The server treats a typed-in serial the same way the billing
   *  screen does. */
  scr_serials: function (idx) {
    var self = this;
    var line = this.bill && this.bill.lines[idx];
    if (!line) { this.go('newbill'); return; }
    this.chrome('સિરિયલ નંબર', true);
    this.freeSerials(line.item_id).then(function (shelf) {
      var draw = function () {
        self.el('main').innerHTML =
          '<div class="card"><div class="muted">' + self.esc(line.name) + '</div>' +
          '<div class="big">' + line.serials.length + ' / ' + line.qty + '</div>' +
          '<div class="muted">પસંદ કરેલા સિરિયલ નંબર</div></div>' +
          (line.serials.length
            ? '<div class="card"><h3>પસંદ કરેલા</h3><ul class="list">' + line.serials.map(function (s, i) {
                return '<li><div class="nm" style="font-family:monospace">' + self.esc(s) + '</div>' +
                  '<button class="x" data-rm="' + i + '">✕</button></li>';
              }).join('') + '</ul></div>'
            : '') +
          '<div class="card"><h3>શેલ્ફ પરના</h3>' + (shelf.length
            ? '<input type="search" id="sn_q" placeholder="સિરિયલ શોધો"><ul class="list" id="sn_list"></ul>'
            : '<p class="muted">આ આઇટમના કોઈ સિરિયલ સ્ટોકમાં નોંધાયેલા નથી.</p>') + '</div>' +
          '<div class="card"><h3>અથવા જાતે લખો</h3>' +
          '<p class="muted" style="margin-top:0">નવો માલ હજી ખરીદીમાં ન નોંધાયો હોય તો અહીં લખો.</p>' +
          '<input type="text" id="sn_new" placeholder="સિરિયલ નંબર" autocapitalize="characters" autocomplete="off">' +
          '<button class="btn sec" id="sn_add">+ ઉમેરો</button></div>' +
          '<button class="btn ok" id="sn_done">✓ થઈ ગયું</button>';

        var list = self.el('sn_list');
        if (list) {
          var drawList = function (q) {
            var rows = q ? shelf.filter(function (r) { return self.match(r.serial_no, q); }) : shelf;
            list.innerHTML = rows.slice(0, 100).map(function (r) {
              return '<li data-add="' + self.esc(r.serial_no) + '">' +
                '<div class="nm" style="font-family:monospace">' + self.esc(r.serial_no) + '</div>' +
                '<div class="right muted">+</div></li>';
            }).join('') || '<li class="muted">કંઈ મળ્યું નહીં</li>';
            list.querySelectorAll('[data-add]').forEach(function (li) {
              li.addEventListener('click', function () { add(li.dataset.add); });
            });
          };
          drawList('');
          self.el('sn_q').addEventListener('input', function () { drawList(this.value.trim()); });
        }
        self.el('main').querySelectorAll('[data-rm]').forEach(function (b) {
          b.addEventListener('click', function () {
            var s = line.serials.splice(parseInt(b.dataset.rm, 10), 1)[0];
            DB.by('serials', 'item_id', line.item_id).then(function (rows) {
              rows.forEach(function (r) {
                if (r.serial_no === s && !shelf.some(function (x) { return x.serial_no === s; })) shelf.push(r);
              });
              self.saveDraft(); draw();
            });
          });
        });
        self.el('sn_add').addEventListener('click', function () {
          add(self.el('sn_new').value.trim().toUpperCase());
        });
        self.el('sn_done').addEventListener('click', function () { self.go('newbill'); });
      };
      var add = function (sn) {
        if (!sn) return;
        if (line.serials.indexOf(sn) >= 0) { self.toast('એ સિરિયલ પહેલેથી છે', true); return; }
        // the count must match the quantity, or the bill says one thing and
        // the serial records say another
        if (line.serials.length >= line.qty) { self.toast('નંગ ' + line.qty + ' છે, એટલા જ સિરિયલ ચાલશે', true); return; }
        line.serials.push(sn);
        var i = shelf.findIndex(function (r) { return r.serial_no === sn; });
        if (i >= 0) shelf.splice(i, 1);
        self.saveDraft();
        draw();
      };
      draw();
    });
  },

  billTotal: function () {
    return (this.bill.lines || []).reduce(function (s, l) { return s + l.qty * l.price; }, 0);
  },

  renderLines: function () {
    var self = this;
    var box = this.el('b_lines');
    if (!box) return;
    if (!this.bill.lines.length) {
      box.innerHTML = '<p class="muted" style="margin:6px 0">ઉપરના ખાનામાં આઇટમનું નામ લખો.</p>';
    } else {
      box.innerHTML = this.bill.lines.map(function (l, i) {
        var short = l.qty > l.stock;
        var need = l.serial_tracked ? (l.serials || []).length + '/' + l.qty : '';
        return '<div class="line">' +
          '<div style="flex:1"><div class="nm">' + self.esc(l.name) + '</div>' +
            '<div class="sub">₹' + self.money(l.price) + ' × ' + l.qty +
            (short ? ' <span class="pill bad">સ્ટોક ' + l.stock + '</span>' : '') + '</div>' +
            '<div class="row" style="margin-top:6px;gap:6px">' +
              '<input type="number" inputmode="decimal" step="any" min="0" value="' + l.qty + '" data-q="' + i + '" style="margin:0" aria-label="નંગ">' +
              '<input type="number" inputmode="decimal" step="any" min="0" value="' + l.price + '" data-p="' + i + '" style="margin:0" aria-label="ભાવ">' +
            '</div>' +
            (l.serial_tracked
              ? '<button class="btn sec" style="margin-top:6px" data-sn="' + i + '">🔢 સિરિયલ ' + need + '</button>'
              : '') +
          '</div>' +
          '<div class="right"><strong>₹' + self.money(l.qty * l.price) + '</strong>' +
          '<br><button class="x" data-x="' + i + '">✕</button></div></div>';
      }).join('');
      box.querySelectorAll('[data-x]').forEach(function (b) {
        b.addEventListener('click', function () {
          self.bill.lines.splice(parseInt(b.dataset.x, 10), 1);
          self.renderLines(); self.saveDraft();
        });
      });
      box.querySelectorAll('[data-q]').forEach(function (inp) {
        inp.addEventListener('change', function () {
          var l = self.bill.lines[parseInt(inp.dataset.q, 10)];
          l.qty = Math.max(0, parseFloat(inp.value) || 0);
          // fewer pieces than serials already picked would bill one thing
          // and hand over another
          if (l.serials && l.serials.length > l.qty) l.serials = l.serials.slice(0, l.qty);
          self.renderLines(); self.saveDraft();
        });
      });
      box.querySelectorAll('[data-p]').forEach(function (inp) {
        inp.addEventListener('change', function () {
          self.bill.lines[parseInt(inp.dataset.p, 10)].price = Math.max(0, parseFloat(inp.value) || 0);
          self.renderLines(); self.saveDraft();
        });
      });
      box.querySelectorAll('[data-sn]').forEach(function (b) {
        b.addEventListener('click', function () {
          self.stashHeader();
          self.go('serials', parseInt(b.dataset.sn, 10));
        });
      });
    }
    var t = this.billTotal();
    this.el('b_sub').textContent = '₹' + this.money(t);
    this.el('b_grand').textContent = '₹' + this.money(t);
  },

  saveBill: function () {
    var self = this;
    var err = this.el('b_err');
    err.textContent = '';
    if (!this.bill.lines.length) { err.textContent = 'ઓછામાં ઓછી એક આઇટમ ઉમેરો'; this.el('b_item').focus(); return; }
    // EVERY check before the first write, so a refusal leaves nothing behind
    var bad = null;
    this.bill.lines.forEach(function (l, i) {
      if (bad) return;
      if (!(l.qty > 0)) bad = { msg: l.name + ': નંગ ભરો', line: i };
      else if (l.serial_tracked && (l.serials || []).length !== l.qty) {
        bad = { msg: l.name + ': ' + l.qty + ' માંથી ' + (l.serials || []).length + ' સિરિયલ નંબર ભર્યા છે', line: i, sn: true };
      }
    });
    if (bad) {
      err.textContent = bad.msg;
      if (bad.sn) { this.stashHeader(); this.go('serials', bad.line); }
      return;
    }
    var total = this.billTotal();
    var paid = Math.max(0, Math.min(parseFloat(this.el('b_paid').value) || 0, total));
    var mode = this.el('b_mode').value;
    var uuid = newUuid();
    var doc = {
      uuid: uuid, kind: 'sale', date: this.today(), created_at: new Date().toISOString(),
      customer: this.el('b_cust').value.trim(), mobile: this.el('b_mob').value.trim(),
      party_id: this.bill.party ? this.bill.party.id : null,
      lines: this.bill.lines.slice(), total: total, paid: paid, mode: mode,
      synced: false, invoice_no: null, server_id: null
    };
    var body = {
      client_uuid: uuid,
      party_id: doc.party_id || 0,
      customer_name: doc.customer, customer_mobile: doc.mobile,
      sale_date: doc.date, paid: paid, payment_mode: mode,
      notes: 'મોબાઇલ એપથી',
      items: doc.lines.map(function (l) {
        return { item_id: l.item_id, qty: l.qty, price: l.price, serials: l.serials || [] };
      })
    };
    // SAVED FIRST, SENT AFTERWARDS. If the phone dies in the next second the
    // bill is already on it; if the network is gone it simply waits.
    DB.put('docs', doc)
      .then(function () { return Sync.queue({ uuid: uuid, kind: 'sale', body: body, created_at: doc.created_at }); })
      .then(function () { return DB.setMeta('draft', null); })
      .then(function () {
        self.bill = null;
        self.toast('બિલ સેવ થઈ ગયું ₹' + self.money(total));
        self.go('done', uuid);
        if (navigator.onLine) self.syncNow(false);
      })
      .catch(function (e) { err.textContent = 'સેવ થયું નહીં: ' + ((e && e.message) || ''); });
  },

  /** Straight after saving: what a shop actually does next. Send it on
   *  WhatsApp, or start the next bill. The invoice number is not here yet if
   *  the phone is offline, and the screen says so rather than inventing
   *  one - the customer's copy gets the number once it exists. */
  scr_done: function (uuid) {
    var self = this;
    this.chrome('બિલ થઈ ગયું', true);
    Promise.all([DB.get('docs', uuid), DB.meta('shop', 'AK Computer')]).then(function (a) {
      var d = a[0], shop = a[1];
      if (!d) { self.go('bills'); return; }
      var due = (d.total || 0) - (d.paid || 0);
      self.el('main').innerHTML =
        '<div class="card center">' +
        '<div style="font-size:42px">✅</div>' +
        '<div class="big">₹' + self.money(d.total) + '</div>' +
        '<div class="muted">' + self.esc(d.customer || 'વોક-ઇન') + '</div>' +
        (due > 0.009 ? '<div class="muted" style="color:var(--bad)">બાકી ₹' + self.money(due) + '</div>' : '') +
        '<div class="muted" style="font-size:13px;margin-top:6px">' +
          (d.invoice_no ? self.esc(d.invoice_no) : '⏳ નંબર સિંક પછી મળશે') + '</div>' +
        '</div>' +
        (d.mobile ? '<button class="btn ok" id="dn_wa">📲 WhatsApp પર મોકલો</button>' : '') +
        '<button class="btn" id="dn_new">🧾 નવું બિલ</button>' +
        '<button class="btn sec" id="dn_list">📄 બધાં બિલ</button>';
      if (self.el('dn_wa')) self.el('dn_wa').addEventListener('click', function () {
        More.wa(d.mobile, More.billText(d, shop));
      });
      self.el('dn_new').addEventListener('click', function () { self.bill = null; self.go('newbill'); });
      self.el('dn_list').addEventListener('click', function () { self.go('bills'); });
    });
  },

  // --- BILLS ----------------------------------------------------------------
  /** The shop's bills, not just this phone's. An owner looking up "what did
   *  I sell that man last week" does not care which device it was typed on.
   *  This phone's unsent ones come first and are marked, because those are
   *  the only ones anybody has to do anything about. */
  scr_bills: function () {
    var self = this;
    this.chrome('બિલ', true);
    Promise.all([DB.all('docs'), DB.all('ssales'), DB.meta('shop', 'AK Computer')]).then(function (a) {
      var shop = a[2];
      var mine = a[0].filter(function (d) { return d.kind === 'sale'; });
      var sentUuids = {};
      mine.forEach(function (d) { if (d.synced) sentUuids[d.uuid] = 1; });
      var rows = mine.filter(function (d) { return !d.synced; }).map(function (d) {
        return { key: d.uuid, local: true, name: d.customer || 'વોક-ઇન', mobile: d.mobile,
                 no: d.invoice_no, date: d.date, total: d.total, paid: d.paid, doc: d };
      });
      a[1].forEach(function (s) {
        rows.push({ key: 'S' + s.id, local: false, name: s.customer_name || 'વોક-ઇન', mobile: s.customer_mobile,
                    no: s.invoice_no, date: s.sale_date, total: parseFloat(s.total) || 0,
                    paid: parseFloat(s.paid) || 0, cancelled: s.is_cancelled == 1 });
      });
      rows.sort(function (x, y) {
        if (x.local !== y.local) return x.local ? -1 : 1;
        return (y.date || '').localeCompare(x.date || '') || String(y.no || '').localeCompare(String(x.no || ''));
      });

      self.el('main').innerHTML =
        '<div class="card"><input type="search" id="bl_q" placeholder="ગ્રાહક કે બિલ નંબર શોધો"></div>' +
        '<div class="card" id="bl_box"></div>';
      var draw = function (q) {
        var list = q ? rows.filter(function (r) { return self.match(r.name + ' ' + (r.no || '') + ' ' + (r.mobile || ''), q); }) : rows;
        self.el('bl_box').innerHTML = list.length
          ? '<ul class="list">' + list.slice(0, 200).map(function (r, i) {
              var due = r.total - r.paid;
              return '<li data-i="' + i + '"><div><div class="nm">' + self.esc(r.name) +
                (r.cancelled ? ' <span class="pill bad">રદ</span>' : '') + '</div>' +
                '<div class="sub">' + self.esc(r.no || 'નંબર સિંક પછી') + ' · ' + self.esc(r.date || '') +
                (due > 0.009 ? ' · બાકી ₹' + self.money(due) : '') + '</div></div>' +
                '<div class="right"><strong>₹' + self.money(r.total) + '</strong><br>' +
                (r.local ? '<span class="pill wait">મોકલવાનું બાકી</span>'
                         : (r.mobile ? '<span class="pill done">📲</span>' : '')) +
                '</div></li>';
            }).join('') + '</ul>'
          : '<p class="muted">કોઈ બિલ મળ્યું નહીં.</p>';
        self.el('bl_box').querySelectorAll('[data-i]').forEach(function (li) {
          li.addEventListener('click', function () {
            var r = list[parseInt(li.dataset.i, 10)];
            if (r.local) { self.go('done', r.key); return; }
            if (!r.mobile) { self.toast('આ બિલમાં મોબાઇલ નંબર નથી', true); return; }
            More.wa(r.mobile, shop + '\nબિલ ' + (r.no || '') + ' · ' + (r.date || '') +
              '\nકુલ: ₹' + self.money(r.total) +
              ((r.total - r.paid) > 0.009 ? '\nબાકી: ₹' + self.money(r.total - r.paid) : '\n(ચૂકતે)') +
              '\n\nઆભાર 🙏');
          });
        });
      };
      draw('');
      self.el('bl_q').addEventListener('input', function () { draw(this.value.trim()); });
    });
  },

  // --- COLLECT --------------------------------------------------------------
  scr_collect: function (partyId) {
    var self = this;
    this.chrome('પૈસા લીધા', true);
    this.el('main').innerHTML =
      '<div class="card">' +
      '<label>કઈ પાર્ટી પાસેથી *</label>' +
      '<div class="pick"><input type="text" id="c_party" placeholder="પાર્ટીનું નામ ટાઇપ કરો" autocomplete="off"><div class="res" id="c_res"></div></div>' +
      '<div class="muted" id="c_bal" style="margin:-4px 0 10px"></div>' +
      '<label>કેટલા રૂપિયા *</label><input type="number" id="c_amt" inputmode="decimal">' +
      '<label>કઈ રીતે</label><select id="c_mode"><option value="cash">રોકડ</option><option value="upi">UPI</option><option value="bank">બેંક</option><option value="cheque">ચેક</option></select>' +
      '<div class="err" id="c_err"></div>' +
      '<button class="btn ok" id="c_save">💾 નોંધી લો</button>' +
      '</div>';
    var picked = null;
    var show = function (p) {
      picked = p;
      self.el('c_party').value = p.name;
      var b = parseFloat(p.balance) || 0;
      self.el('c_bal').textContent = b > 0.009 ? ('બાકી ₹' + self.money(b))
                                   : (b < -0.009 ? ('એડવાન્સ ₹' + self.money(-b)) : 'હિસાબ ચોખ્ખો');
      if (b > 0.009) self.el('c_amt').value = b.toFixed(2);   // the usual answer, still editable
    };
    this.wirePick('c_party', 'c_res', function (q) { return self.findParties(q); }, show);
    // arrived from a party's own screen: it is already known who is paying
    if (partyId) DB.get('parties', partyId).then(function (p) { if (p) show(p); });
    this.el('c_save').addEventListener('click', function () {
      var err = self.el('c_err');
      err.textContent = '';
      if (!picked) { err.textContent = 'પાર્ટી પસંદ કરો'; self.el('c_party').focus(); return; }
      var amt = parseFloat(self.el('c_amt').value) || 0;
      if (amt <= 0) { err.textContent = 'રકમ ભરો'; self.el('c_amt').focus(); return; }
      var uuid = newUuid();
      var doc = { uuid: uuid, kind: 'payment', date: self.today(), created_at: new Date().toISOString(),
                  party_id: picked.id, customer: picked.name, amount: amt, mode: self.el('c_mode').value, synced: false };
      var body = { client_uuid: uuid, party_id: picked.id, direction: 'in', amount: amt,
                   mode: doc.mode, pay_date: doc.date, notes: 'મોબાઇલ એપથી' };
      DB.put('docs', doc)
        .then(function () { return Sync.queue({ uuid: uuid, kind: 'payment', body: body, created_at: doc.created_at }); })
        .then(function () {
          self.toast('₹' + self.money(amt) + ' નોંધી લીધા');
          self.go('home');
          if (navigator.onLine) self.syncNow(false);
        })
        .catch(function (e) { err.textContent = 'સેવ થયું નહીં: ' + ((e && e.message) || ''); });
    });
  },

  // --- SYNC -----------------------------------------------------------------
  scr_sync: function () {
    var self = this;
    this.chrome('સિંક', true);
    Promise.all([Sync.status(), DB.all('outbox'), DB.meta('server', '')]).then(function (a) {
      var st = a[0], box = a[1], srv = a[2];
      var att = box.filter(function (r) { return r.state === 'attention'; });
      self.el('main').innerHTML =
        '<div class="card">' +
        '<div class="stat"><span>નેટવર્ક</span><strong>' + (st.online ? '✅ ચાલુ' : '📴 બંધ') + '</strong></div>' +
        '<div class="stat"><span>મોકલવાનું બાકી</span><strong>' + st.pending + '</strong></div>' +
        '<div class="stat"><span>ધ્યાન આપવાનું</span><strong>' + st.attention + '</strong></div>' +
        '<div class="stat"><span>છેલ્લે સિંક</span><strong>' + (st.last_pull ? self.ago(st.last_pull) : 'કદી નહીં') + '</strong></div>' +
        '<div class="stat"><span>સર્વર</span><strong style="font-size:12px">' + self.esc(srv) + '</strong></div>' +
        '</div>' +
        '<button class="btn" id="s_now">⟳ હમણાં સિંક કરો</button>' +
        (att.length
          ? '<div class="card" style="margin-top:12px"><h3>⚠️ આ સર્વરે સ્વીકાર્યાં નથી</h3>' +
            '<p class="muted" style="margin-top:0">આ એન્ટ્રી ફોનમાં સલામત છે. કારણ સુધારીને ફરી મોકલો, અથવા કાઢી નાખો.</p>' +
            '<ul class="list">' + att.map(function (r) {
              return '<li><div><div class="nm">' + (r.kind === 'payment' ? 'પેમેન્ટ' : 'બિલ') + '</div>' +
                '<div class="sub">' + self.esc(r.last_error) + '</div></div>' +
                '<div class="right"><button class="btn sec" style="width:auto;padding:6px 10px" data-retry="' + r.uuid + '">ફરી</button>' +
                '<button class="btn ghost" style="width:auto;padding:6px 10px;margin-top:4px" data-drop="' + r.uuid + '">કાઢો</button></div></li>';
            }).join('') + '</ul></div>'
          : '') +
        '<div class="card" style="margin-top:12px">' +
        '<h3>ફોનનો ડેટા</h3>' +
        '<p class="muted" style="margin-top:0">બધું મોકલાઈ ગયું હોય તો જ સાફ કરવું. બાકી હોય તો પહેલાં સિંક કરો.</p>' +
        '<button class="btn ghost" id="s_out">લોગઆઉટ</button>' +
        '</div>';
      self.el('s_now').addEventListener('click', function () { self.syncNow(true); });
      self.el('main').querySelectorAll('[data-retry]').forEach(function (b) {
        b.addEventListener('click', function () {
          DB.get('outbox', b.dataset.retry).then(function (r) {
            if (!r) return;
            r.state = 'pending'; r.attempts = 0; r.next_try = 0;
            return DB.put('outbox', r);
          }).then(function () { self.syncNow(true); });
        });
      });
      self.el('main').querySelectorAll('[data-drop]').forEach(function (b) {
        b.addEventListener('click', function () {
          if (!confirm('આ એન્ટ્રી કાયમ કાઢી નાખવી? સર્વર પર કદી નહીં જાય.')) return;
          DB.del('outbox', b.dataset.drop).then(function () { self.go('sync'); });
        });
      });
      self.el('s_out').addEventListener('click', function () {
        Sync.status().then(function (s) {
          if (s.pending && !confirm(s.pending + ' એન્ટ્રી હજી મોકલવાની બાકી છે. લોગઆઉટ કરશો તો એ ખોવાઈ જશે. ચાલુ રાખવું?')) return;
          DB.clearAll().then(function () { self.user = null; self.go('login'); });
        });
      });
    });
  }
};

document.addEventListener('DOMContentLoaded', function () { App.start(); });
