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
      return Sync.pull();
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
    Promise.all([DB.all('docs'), Sync.status(), DB.count('items'), DB.count('parties')])
      .then(function (a) {
        var docs = a[0], st = a[1];
        var today = self.today();
        var sales = docs.filter(function (d) { return d.kind === 'sale' && d.date === today; });
        var pays = docs.filter(function (d) { return d.kind === 'payment' && d.date === today; });
        var total = sales.reduce(function (s, d) { return s + (d.total || 0); }, 0);
        var got = sales.reduce(function (s, d) { return s + (d.paid || 0); }, 0)
                + pays.reduce(function (s, d) { return s + (d.amount || 0); }, 0);
        self.el('main').innerHTML =
          '<div class="card">' +
          '<div class="muted">આજનું વેચાણ (આ ફોનથી)</div>' +
          '<div class="big">₹' + self.money(total) + '</div>' +
          '<div class="stat"><span>બિલ</span><strong>' + sales.length + '</strong></div>' +
          '<div class="stat"><span>આજે પૈસા આવ્યા</span><strong>₹' + self.money(got) + '</strong></div>' +
          '</div>' +
          '<button class="btn" id="h_new">🧾 નવું બિલ બનાવો</button>' +
          '<button class="btn sec" id="h_pay">₹ પૈસા લીધા નોંધો</button>' +
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
  scr_newbill: function () {
    var self = this;
    this.chrome('નવું બિલ', true);
    if (!this.bill) this.bill = { lines: [], party: null, customer: '', mobile: '', paid: 0, mode: 'cash' };
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

    this.el('b_full').addEventListener('click', function () {
      self.el('b_paid').value = self.billTotal().toFixed(2);
    });
    this.el('b_save').addEventListener('click', function () { self.saveBill(); });
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
    else this.bill.lines.push({ item_id: it.id, name: it.name, qty: 1, price: parseFloat(it.selling_price) || 0, stock: parseFloat(it.stock) || 0 });
    this.renderLines();
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
        return '<div class="line">' +
          '<div style="flex:1"><div class="nm">' + self.esc(l.name) + '</div>' +
            '<div class="sub">₹' + self.money(l.price) + ' × ' + l.qty +
            (short ? ' <span class="pill bad">સ્ટોક ' + l.stock + '</span>' : '') + '</div></div>' +
          '<div class="right"><strong>₹' + self.money(l.qty * l.price) + '</strong></div>' +
          '<button class="x" data-x="' + i + '">✕</button></div>';
      }).join('');
      box.querySelectorAll('[data-x]').forEach(function (b) {
        b.addEventListener('click', function () {
          self.bill.lines.splice(parseInt(b.dataset.x, 10), 1);
          self.renderLines();
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
      items: doc.lines.map(function (l) { return { item_id: l.item_id, qty: l.qty, price: l.price }; })
    };
    // SAVED FIRST, SENT AFTERWARDS. If the phone dies in the next second the
    // bill is already on it; if the network is gone it simply waits.
    DB.put('docs', doc)
      .then(function () { return Sync.queue({ uuid: uuid, kind: 'sale', body: body, created_at: doc.created_at }); })
      .then(function () {
        self.bill = null;
        self.toast('બિલ સેવ થઈ ગયું ₹' + self.money(total));
        self.go('bills');
        if (navigator.onLine) self.syncNow(false);
      })
      .catch(function (e) { err.textContent = 'સેવ થયું નહીં: ' + ((e && e.message) || ''); });
  },

  // --- BILLS ----------------------------------------------------------------
  scr_bills: function () {
    var self = this;
    this.chrome('બિલ', true);
    DB.all('docs').then(function (docs) {
      var rows = docs.filter(function (d) { return d.kind === 'sale'; })
                     .sort(function (a, b) { return (b.created_at || '').localeCompare(a.created_at || ''); })
                     .slice(0, 200);
      self.el('main').innerHTML = '<div class="card">' + (rows.length
        ? '<ul class="list">' + rows.map(function (d) {
            return '<li><div><div class="nm">' + self.esc(d.customer || 'વોક-ઇન') + '</div>' +
              '<div class="sub">' + self.esc(d.invoice_no || 'નંબર સિંક પછી') + ' · ' + self.esc(d.date) + '</div></div>' +
              '<div class="right"><strong>₹' + self.money(d.total) + '</strong><br>' +
              (d.synced ? '<span class="pill done">મોકલાઈ ગયું</span>' : '<span class="pill wait">બાકી</span>') +
              '</div></li>';
          }).join('') + '</ul>'
        : '<p class="muted">હજી કોઈ બિલ આ ફોનથી બન્યું નથી.</p>') + '</div>';
    });
  },

  // --- COLLECT --------------------------------------------------------------
  scr_collect: function () {
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
    this.wirePick('c_party', 'c_res', function (q) { return self.findParties(q); }, function (p) {
      picked = p;
      self.el('c_party').value = p.name;
      var b = parseFloat(p.balance) || 0;
      self.el('c_bal').textContent = b > 0.009 ? ('બાકી ₹' + self.money(b))
                                   : (b < -0.009 ? ('એડવાન્સ ₹' + self.money(-b)) : 'હિસાબ ચોખ્ખો');
    });
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
