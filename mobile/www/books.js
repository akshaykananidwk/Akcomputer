// ---------------------------------------------------------------------------
// The shop's books ON THE PHONE: parties and their statements, items, every
// bill, purchase, payment and expense, cash & bank, reports, settings.
//
// All of it reads the phone's own copy of the WHOLE shop (Sync.pullAll), so
// every screen here opens instantly and works with no signal. Nothing here
// is a website page.
//
// The one piece of arithmetic that must agree with the website to the paisa
// is a party's balance. It is worked out here exactly as the server's
// party_balance_expr() does (money.php): opening balance + bills − sales
// returns − purchases + purchase returns − money in + money out. The app
// test compares it with the server for every party.
// ---------------------------------------------------------------------------
var Books = {

  num: function (v) { return parseFloat(v) || 0; },
  d: function (iso) {       // 2026-10-05 -> 05 Oct 2026
    if (!iso) return '';
    var m = String(iso).slice(0, 10).split('-');
    var mon = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][parseInt(m[1], 10) - 1] || '';
    return m[2] + ' ' + mon + ' ' + m[0];
  },

  /** Everything the books need, in one read. */
  load: function () {
    return Promise.all([DB.all('ssales'), DB.all('spurchases'), DB.all('spayments'), DB.all('sexpenses'),
                        DB.all('sreturns'), DB.all('spreturns'), DB.all('docs'), DB.all('parties'), DB.all('items')])
      .then(function (a) {
        return { sales: a[0], purchases: a[1], payments: a[2], expenses: a[3], sreturns: a[4], preturns: a[5],
                 docs: a[6].filter(function (d) { return !d.synced; }), parties: a[7], items: a[8] };
      });
  },

  /** A party's statement, the website's own list (parties.php ledger):
   *  bills and returns, purchases and returns, every payment - plus what this
   *  phone has written and not sent yet, marked as such. Oldest first, with
   *  the running balance after each line. */
  ledger: function (b, p) {
    var id = p.id, rows = [], N = this.num;
    b.sales.forEach(function (s) { if (s.party_id == id && s.is_cancelled != 1) rows.push({ date: s.sale_date, what: 'Bill ' + (s.invoice_no || ''), dr: N(s.total), cr: 0, go: ['billview', s.id], sub: N(s.total) - N(s.paid) > 0.009 ? 'Balance ₹' + App.money(N(s.total) - N(s.paid)) : 'Paid' }); });
    b.purchases.forEach(function (s) { if (s.party_id == id && s.is_cancelled != 1) rows.push({ date: s.purchase_date, what: 'Purchase ' + (s.bill_no || '#' + s.id), dr: 0, cr: N(s.total), go: ['purchaseview', s.id], sub: N(s.total) - N(s.paid) > 0.009 ? 'Balance ₹' + App.money(N(s.total) - N(s.paid)) : 'Paid' }); });
    b.payments.forEach(function (x) { if (x.party_id == id) rows.push({ date: x.pay_date, what: (x.direction === 'in' ? 'Received' : 'Paid') + ' (' + (x.mode || '') + ')', dr: x.direction === 'out' ? N(x.amount) : 0, cr: x.direction === 'in' ? N(x.amount) : 0, sub: x.notes || '' }); });
    b.sreturns.forEach(function (x) { if (x.party_id == id) rows.push({ date: x.return_date, what: 'Sales return ' + (x.return_no || ''), dr: 0, cr: N(x.total) }); });
    b.preturns.forEach(function (x) { if (x.party_id == id) rows.push({ date: x.return_date, what: 'Purchase return ' + (x.return_no || ''), dr: N(x.total), cr: 0 }); });
    b.docs.forEach(function (d) {
      if (d.party_id != id) return;
      if (d.kind === 'sale') rows.push({ date: d.date, what: 'Bill (not sent yet)', dr: d.total || 0, cr: 0, pending: true });
      if (d.kind === 'payment') rows.push({ date: d.date, what: 'Received (not sent yet)', dr: 0, cr: d.amount || 0, pending: true });
      if (d.kind === 'payout') rows.push({ date: d.date, what: 'Paid (not sent yet)', dr: d.amount || 0, cr: 0, pending: true });
      if (d.kind === 'purchase') rows.push({ date: d.date, what: 'Purchase (not sent yet)', dr: 0, cr: d.total || 0, pending: true });
    });
    rows.sort(function (x, y) { return (x.date || '').localeCompare(y.date || ''); });
    var run = N(p.opening_balance);
    rows.forEach(function (r) { run += r.dr - r.cr; r.bal = Math.round(run * 100) / 100; });
    return { opening: N(p.opening_balance), rows: rows, balance: Math.round(run * 100) / 100 };
  },

  /** Every party's balance at once (for lists), the same formula. */
  balances: function (b) {
    var m = {}, N = this.num, add = function (id, v) { if (id) m[id] = (m[id] || 0) + v; };
    b.parties.forEach(function (p) { m[p.id] = N(p.opening_balance); });
    b.sales.forEach(function (s) { if (s.is_cancelled != 1) add(s.party_id, N(s.total)); });
    b.sreturns.forEach(function (s) { add(s.party_id, -N(s.total)); });
    b.purchases.forEach(function (s) { if (s.is_cancelled != 1) add(s.party_id, -N(s.total)); });
    b.preturns.forEach(function (s) { add(s.party_id, N(s.total)); });
    b.payments.forEach(function (x) { add(x.party_id, x.direction === 'in' ? -N(x.amount) : N(x.amount)); });
    b.docs.forEach(function (d) {
      if (d.kind === 'sale') add(d.party_id, d.total || 0);
      if (d.kind === 'payment') add(d.party_id, -(d.amount || 0));
      if (d.kind === 'payout') add(d.party_id, d.amount || 0);
      if (d.kind === 'purchase') add(d.party_id, -(d.total || 0));
    });
    Object.keys(m).forEach(function (k) { m[k] = Math.round(m[k] * 100) / 100; });
    return m;
  },

  /** On the website every party works on both sides (type is always "both"),
   *  so "a supplier" means someone we have bought from or owe money to. */
  isSupplier: function (p, b, bal) {
    if (bal != null && bal < -0.009) return true;
    return !!(b && b.purchases.some(function (x) { return x.party_id == p.id; }));
  },

  /** A searchable list screen: one search box, rows drawn by a function. */
  list: function (rows, draw, opt) {
    opt = opt || {};
    App.el('main').innerHTML = (opt.head || '') +
      '<div class="card"><input type="search" id="bk_q" placeholder="' + App.esc(opt.ph || 'Search') + '">' + (opt.filters || '') + '</div>' +
      '<div class="card" style="padding:0 14px"><ul class="list" id="bk_list"></ul><div id="bk_more"></div></div>';
    var shown = 100, q = '';
    var paint = function () {
      var list = q ? rows.filter(function (r) { return App.match(opt.text(r), q); }) : rows;
      App.el('bk_list').innerHTML = list.slice(0, shown).map(draw).join('') || '<li class="muted">Nothing found</li>';
      App.el('bk_more').innerHTML = list.length > shown ? '<button class="btn ghost" id="bk_mb" style="margin:8px 0">Show more (' + (list.length - shown) + ')</button>' : '';
      if (App.el('bk_mb')) App.el('bk_mb').addEventListener('click', function () { shown += 200; paint(); });
      App.el('bk_list').querySelectorAll('[data-go]').forEach(function (li) {
        li.addEventListener('click', function () { var g = li.dataset.go.split(':'); App.go(g[0], g[1] ? (isNaN(g[1]) ? g[1] : +g[1]) : undefined); });
      });
      if (opt.after) opt.after();
    };
    App.el('bk_q').addEventListener('input', function () { q = this.value.trim(); shown = 100; paint(); });
    paint();
    return paint;
  },

  // === PARTIES ==============================================================
  scr_parties: function (kind) {
    var self = this;
    App.chrome('Parties', true);
    this.load().then(function (b) {
      var bal = self.balances(b), tab = kind || 'all';
      var rows = b.parties.filter(function (p) { return p.is_active != 0; })
        .concat(b.docs.filter(function (d) { return d.kind === 'party'; }).map(function (d) { return { id: 0, name: d.body.name, mobile: d.body.mobile, type: d.body.type, pending: true }; }));
      var buys = {}; b.purchases.forEach(function (x) { buys[x.party_id] = 1; });
      var pick = function () {
        return rows.filter(function (p) {
          if (tab === 'customer') return !buys[p.id];
          if (tab === 'supplier') return buys[p.id] || (bal[p.id] || 0) < -0.009;
          if (tab === 'due') return (bal[p.id] || 0) > 0.009;
          return true;
        }).sort(function (x, y) {
          // not sent yet first (just added), then who owes most
          return (y.pending ? 1 : 0) - (x.pending ? 1 : 0) || Math.abs(bal[y.id] || 0) - Math.abs(bal[x.id] || 0) || (x.name || '').localeCompare(y.name || '');
        });
      };
      var tabs = [['all', 'All'], ['customer', 'Customers'], ['supplier', 'Suppliers'], ['due', 'To receive']];
      var draw = function (p) {
        var v = bal[p.id] || 0;
        return '<li' + (p.id ? ' data-go="party:' + p.id + '"' : '') + '><div><div class="nm">' + App.esc(p.name) +
          (p.pending ? ' <span class="pill wait">not sent</span>' : '') + '</div><div class="sub">' + App.esc(p.mobile || '') +
          (p.city ? ' · ' + App.esc(p.city) : '') + '</div></div><div class="right">' +
          (Math.abs(v) > 0.009 ? '<strong style="color:' + (v > 0 ? 'var(--bad)' : 'var(--ok)') + '">₹' + App.money(Math.abs(v)) + '</strong><div class="sub">' + (v > 0 ? 'to receive' : 'to pay') + '</div>' : '<span class="sub">settled</span>') +
          '</div></li>';
      };
      var head = (App.can('parties.add') ? '<button class="btn" id="pt_add">➕ New party</button><div style="height:10px"></div>' : '') +
        '<div class="chips">' + tabs.map(function (t) { return '<button class="chip' + (t[0] === tab ? ' on' : '') + '" data-tab="' + t[0] + '">' + t[1] + '</button>'; }).join('') + '</div>';
      var all = pick();
      var paint = self.list(all, draw, { head: head, ph: 'Search name, mobile or city', text: function (p) { return p.name + ' ' + (p.mobile || '') + ' ' + (p.city || ''); } });
      if (App.el('pt_add')) App.el('pt_add').addEventListener('click', function () { App.go('partyedit'); });
      App.el('main').querySelectorAll('[data-tab]').forEach(function (c) {
        c.addEventListener('click', function () { try { history.replaceState({ name: 'parties', arg: c.dataset.tab }, ''); } catch (e) {} self.scr_parties(c.dataset.tab); });
      });
    });
  },

  /** A party's own screen: balance, what to do next, the full statement. */
  scr_party: function (partyId) {
    var self = this;
    App.chrome('Party', true);
    this.load().then(function (b) {
      var p = null; b.parties.forEach(function (x) { if (x.id == partyId) p = x; });
      if (!p) { App.el('main').innerHTML = '<div class="card"><p class="muted">This party is not on the phone yet. Sync once.</p></div>'; return; }
      var L = self.ledger(b, p), bal = L.balance, sup = self.isSupplier(p, b, bal);
      // the server's own balance from the last sync: a mismatch means the
      // phone is behind, and the screen says so instead of showing a wrong sum
      var srv = parseFloat(p.balance), unsent = L.rows.some(function (r) { return r.pending; });
      var behind = !unsent && !isNaN(srv) && Math.abs(srv - bal) > 0.009;
      App.el('main').innerHTML =
        '<div class="card"><div class="nm" style="font-size:18px">' + App.esc(p.name) + '</div>' +
        '<div class="sub">' + [p.mobile, p.city, p.gstin ? 'GSTIN ' + p.gstin : ''].filter(Boolean).map(App.esc).join(' · ') + '</div>' +
        '<div class="big" style="margin-top:8px;color:' + (bal > 0.009 ? 'var(--bad)' : (bal < -0.009 ? 'var(--ok)' : 'inherit')) + '">₹' + App.money(Math.abs(bal)) + '</div>' +
        '<div class="muted">' + (bal > 0.009 ? 'They owe you' : (bal < -0.009 ? (sup ? 'You owe them' : 'Advance with you') : 'All settled')) + '</div>' +
        (behind ? '<p class="pill wait" style="margin:8px 0 0">The shop shows ₹' + App.money(Math.abs(srv)) + ' — sync to bring this phone up to date</p>' : '') +
        '</div>' +
        '<div class="acts">' +
          (App.can('payments.add') ? '<button class="act" id="p_collect"><span>₹</span>Take money</button>' : '') +
          (App.can('sales.add') ? '<button class="act" id="p_bill"><span>🧾</span>New bill</button>' : '') +
          (sup && App.can('payments.add') ? '<button class="act" id="p_payout"><span>💸</span>Pay them</button>' : '') +
          (sup && App.can('purchases.add') ? '<button class="act" id="p_purch"><span>📥</span>Their bill</button>' : '') +
          (p.mobile && bal > 0.009 ? '<button class="act" id="p_remind"><span>📩</span>Reminder</button>' : '') +
          (p.mobile ? '<a class="act" href="tel:' + App.esc(p.mobile) + '"><span>📞</span>Call</a>' : '') +
          (App.can('parties.edit') ? '<button class="act" id="p_edit"><span>✏️</span>Edit</button>' : '') +
          '<button class="act" id="p_print"><span>🖨️</span>Statement</button>' +
        '</div>' +
        '<div class="card" style="padding:0 14px" id="p_stmt"><h3 style="margin:14px 0 4px">Statement</h3><ul class="list">' +
          '<li><div><div class="nm">Opening balance</div></div><div class="right"><strong>₹' + App.money(L.opening) + '</strong></div></li>' +
          L.rows.slice().reverse().map(function (r) {
            return '<li' + (r.go ? ' data-go="' + r.go[0] + ':' + r.go[1] + '"' : '') + '><div><div class="nm">' + App.esc(r.what) +
              (r.pending ? ' <span class="pill wait">not sent</span>' : '') + '</div><div class="sub">' + self.d(r.date) + (r.sub ? ' · ' + App.esc(r.sub) : '') + '</div></div>' +
              '<div class="right">' + (r.dr ? '<strong>+ ₹' + App.money(r.dr) + '</strong>' : '<strong style="color:var(--ok)">− ₹' + App.money(r.cr) + '</strong>') +
              '<div class="sub">bal ₹' + App.money(r.bal) + '</div></div></li>';
          }).join('') + '</ul></div>';
      var on = function (id, fn) { if (App.el(id)) App.el(id).addEventListener('click', fn); };
      on('p_collect', function () { App.go('collect', p.id); });
      on('p_bill', function () {
        App.bill = { lines: [], party: p, customer: p.name, mobile: p.mobile || '', paid: 0, mode: 'cash' };
        App.go('newbill');
      });
      on('p_payout', function () { App.go('payout', p.id); });
      on('p_purch', function () { Entries.pur = { lines: [], party: p, bill_no: '', paid: 0, mode: 'credit' }; App.go('purchase'); });
      on('p_remind', function () { More.sendReminder(p.id); });
      on('p_edit', function () { App.go('partyedit', p.id); });
      on('p_print', function () { self.print(); });
      App.el('p_stmt').querySelectorAll('[data-go]').forEach(function (li) {
        li.addEventListener('click', function () { var g = li.dataset.go.split(':'); App.go(g[0], +g[1]); });
      });
    });
  },

  scr_partyedit: function (partyId) {
    var self = this;
    App.chrome(partyId ? 'Edit party' : 'New party', true);
    (partyId ? DB.get('parties', partyId) : Promise.resolve({ type: 'customer' })).then(function (p) {
      p = p || { type: 'customer' };
      var f = function (id, label, v, type) {
        return '<label>' + label + '</label><input id="' + id + '" type="' + (type || 'text') + '" value="' + App.esc(v == null ? '' : v) + '"' +
          (type === 'tel' ? ' inputmode="tel"' : '') + (type === 'number' ? ' inputmode="decimal" step="any"' : '') + '>';
      };
      App.el('main').innerHTML = '<div class="card">' +
        f('pe_name', 'Name *', p.name) + f('pe_mob', 'Mobile', p.mobile, 'tel') +
        f('pe_city', 'City', p.city) + f('pe_addr', 'Address', p.address) + f('pe_gst', 'GSTIN', p.gstin) + f('pe_mail', 'E-mail', p.email, 'email') +
        f('pe_days', 'Credit days', p.credit_days || 0, 'number') +
        (partyId ? '' : f('pe_open', 'Opening balance (they owe you +, you owe them −)', 0, 'number')) +
        '<div class="err" id="pe_err"></div><button class="btn ok" id="pe_save">💾 Save</button></div>';
      App.el('pe_save').addEventListener('click', function () {
        var name = App.el('pe_name').value.trim(), err = App.el('pe_err');
        if (!name) { err.textContent = 'Enter the name'; return; }
        var body = { id: partyId || 0, name: name, mobile: App.el('pe_mob').value.replace(/\D/g, ''),
                     city: App.el('pe_city').value.trim(), address: App.el('pe_addr').value.trim(), gstin: App.el('pe_gst').value.trim().toUpperCase(),
                     email: App.el('pe_mail').value.trim(), credit_days: parseInt(App.el('pe_days').value, 10) || 0 };
        if (!partyId) body.opening_balance = parseFloat(App.el('pe_open').value) || 0;
        // show the change at once; the server's answer replaces it at the next sync
        var local = partyId ? Object.assign({}, p, body) : null;
        Entries.save('party', { customer: name, body: body }, body, partyId ? 'Saved' : name + ' added', partyId ? ['party', partyId] : ['parties'])
          .then(function () { if (local) return DB.put('parties', local); });
      });
    });
  },

  // === ITEMS ================================================================
  scr_items: function () {
    var self = this;
    App.chrome('Items', true);
    Promise.all([DB.all('items'), DB.meta('categories', []), DB.all('docs')]).then(function (a) {
      var cats = {}; (a[1] || []).forEach(function (c) { cats[c.id] = c.name; });
      // a new item added on this phone and not sent yet: shown first, not tappable
      var fresh = a[2].filter(function (d) { return d.kind === 'item' && !d.synced && !d.body.id; })
        .map(function (d) { return { id: 0, name: d.body.name, selling_price: d.body.selling_price, item_type: d.body.item_type, stock: 0, pending: true }; });
      var rows = fresh.concat(a[0].filter(function (i) { return i.is_active != 0; }).sort(function (x, y) { return (x.name || '').localeCompare(y.name || ''); }));
      var low = rows.filter(function (i) { return i.item_type !== 'service' && self.num(i.stock) <= self.num(i.min_stock); }).length;
      var head = (App.can('items.add') ? '<button class="btn" id="it_add">➕ New item</button><div style="height:10px"></div>' : '') +
        '<div class="card"><div class="stat"><span>Items</span><strong>' + rows.length + '</strong></div>' +
        '<div class="stat"><span>Low or out of stock</span><strong style="color:var(--bad)">' + low + '</strong></div></div>';
      self.list(rows, function (i) {
        var st = self.num(i.stock), lowx = i.item_type !== 'service' && st <= self.num(i.min_stock);
        return '<li' + (i.id ? ' data-go="item:' + i.id + '"' : '') + '><div><div class="nm">' + App.esc(i.name) + (i.pending ? ' <span class="pill wait">not sent</span>' : '') + '</div><div class="sub">' +
          [cats[i.category_id], i.brand, i.barcode].filter(Boolean).map(App.esc).join(' · ') + '</div></div>' +
          '<div class="right"><strong>₹' + App.money(i.selling_price) + '</strong><div class="sub"' + (lowx ? ' style="color:var(--bad)"' : '') + '>' +
          (i.item_type === 'service' ? 'service' : 'stock ' + st + ' ' + App.esc(i.unit || '')) + '</div></div></li>';
      }, { head: head, ph: 'Search name, brand or barcode', text: function (i) { return i.name + ' ' + (i.brand || '') + ' ' + (i.model || '') + ' ' + (i.barcode || '') + ' ' + (cats[i.category_id] || ''); } });
      if (App.el('it_add')) App.el('it_add').addEventListener('click', function () { App.go('itemedit'); });
    });
  },

  scr_item: function (itemId) {
    var self = this;
    App.chrome('Item', true);
    Promise.all([DB.get('items', itemId), DB.all('stock'), DB.by('serials', 'item_id', itemId), DB.meta('locations', []), DB.all('ssales'), DB.all('spurchases')]).then(function (a) {
      var it = a[0]; if (!it) { App.go('items'); return; }
      var locs = {}; (a[3] || []).forEach(function (l) { locs[l.id] = l.name; });
      var where = a[1].filter(function (s) { return s.item_id == itemId; });
      var sold = 0, bought = 0;
      a[4].forEach(function (s) { if (s.is_cancelled != 1) (s.lines || []).forEach(function (l) { if (l.item_id == itemId) sold += self.num(l.qty); }); });
      a[5].forEach(function (s) { if (s.is_cancelled != 1) (s.lines || []).forEach(function (l) { if (l.item_id == itemId) bought += self.num(l.qty); }); });
      App.el('main').innerHTML =
        '<div class="card"><div class="nm" style="font-size:18px">' + App.esc(it.name) + '</div>' +
        '<div class="sub">' + [it.brand, it.model, it.hsn ? 'HSN ' + it.hsn : '', it.barcode].filter(Boolean).map(App.esc).join(' · ') + '</div>' +
        '<div class="big" style="margin-top:8px">' + (it.item_type === 'service' ? 'Service' : self.num(it.stock) + ' ' + App.esc(it.unit || '')) + '</div>' +
        '<div class="stat"><span>Selling price</span><strong>₹' + App.money(it.selling_price) + '</strong></div>' +
        (self.num(it.b2b_price) ? '<div class="stat"><span>Dealer price</span><strong>₹' + App.money(it.b2b_price) + '</strong></div>' : '') +
        (App.can('items.cost') ? '<div class="stat"><span>Purchase price</span><strong>₹' + App.money(it.purchase_price) + '</strong></div>' : '') +
        '<div class="stat"><span>GST</span><strong>' + self.num(it.tax_rate) + '%</strong></div>' +
        '<div class="stat"><span>Sold / bought (all time)</span><strong>' + sold + ' / ' + bought + '</strong></div></div>' +
        (App.can('items.edit') ? '<button class="btn sec" id="it_edit">✏️ Edit item</button><div style="height:10px"></div>' : '') +
        (where.length ? '<div class="card"><h3>Where it is</h3><ul class="list">' + where.map(function (s) {
          return '<li><div class="nm">' + App.esc(locs[s.location_id] || 'Location ' + s.location_id) + '</div><div class="right"><strong>' + self.num(s.qty) + '</strong></div></li>';
        }).join('') + '</ul></div>' : '') +
        (a[2].length ? '<div class="card"><h3>Serial numbers on the shelf (' + a[2].length + ')</h3><ul class="list">' + a[2].slice(0, 300).map(function (s) {
          return '<li><div class="nm" style="font-family:monospace">' + App.esc(s.serial_no) + '</div><div class="right sub">' + App.esc(locs[s.location_id] || '') + '</div></li>';
        }).join('') + '</ul></div>' : '');
      if (App.el('it_edit')) App.el('it_edit').addEventListener('click', function () { App.go('itemedit', it.id); });
    });
  },

  scr_itemedit: function (itemId) {
    App.chrome(itemId ? 'Edit item' : 'New item', true);
    Promise.all([itemId ? DB.get('items', itemId) : Promise.resolve(null), DB.meta('categories', [])]).then(function (a) {
      var it = a[0] || { unit: 'Pcs', tax_rate: 18, item_type: 'product' };
      var f = function (id, label, v, num) {
        return '<label>' + label + '</label><input id="' + id + '" value="' + App.esc(v == null ? '' : v) + '"' + (num ? ' type="number" inputmode="decimal" step="any"' : '') + '>';
      };
      App.el('main').innerHTML = '<div class="card">' + f('ie_name', 'Name *', it.name) +
        '<label>Category</label><select id="ie_cat"><option value="0">—</option>' + (a[1] || []).map(function (c) { return '<option value="' + c.id + '">' + App.esc(c.name) + '</option>'; }).join('') + '</select>' +
        '<label>Kind</label><select id="ie_type"><option value="product">Product (has stock)</option><option value="service">Service (no stock)</option></select>' +
        '<div class="row"><div>' + f('ie_sell', 'Selling price ₹', it.selling_price, 1) + '</div><div>' + f('ie_gst', 'GST %', it.tax_rate, 1) + '</div></div>' +
        (App.can('items.cost') ? f('ie_buy', 'Purchase price ₹', it.purchase_price, 1) : '') +
        '<div class="row"><div>' + f('ie_unit', 'Unit', it.unit) + '</div><div>' + f('ie_min', 'Low-stock alert at', it.min_stock || 0, 1) + '</div></div>' +
        '<div class="row"><div>' + f('ie_brand', 'Brand', it.brand) + '</div><div>' + f('ie_hsn', 'HSN', it.hsn) + '</div></div>' +
        f('ie_bar', 'Barcode', it.barcode) +
        '<label style="display:flex;gap:8px;align-items:center;font-weight:400;color:inherit"><input type="checkbox" id="ie_sn" style="width:auto;margin:0"' + (it.serial_tracked == 1 ? ' checked' : '') + '> Sold by serial number / IMEI</label>' +
        '<div class="err" id="ie_err"></div><button class="btn ok" id="ie_save">💾 Save</button>' +
        '<p class="muted" style="font-size:13px">Photos, variants and kits: on the website screen (☰ Everything → All Items).</p></div>';
      App.el('ie_cat').value = it.category_id || 0;
      App.el('ie_type').value = it.item_type || 'product';
      App.el('ie_save').addEventListener('click', function () {
        var name = App.el('ie_name').value.trim();
        if (!name) { App.el('ie_err').textContent = 'Enter the name'; return; }
        var v = function (id) { return App.el(id) ? App.el(id).value.trim() : ''; };
        var body = { id: itemId || 0, name: name, category_id: +v('ie_cat') || 0, item_type: v('ie_type'), selling_price: parseFloat(v('ie_sell')) || 0,
                     tax_rate: parseFloat(v('ie_gst')) || 0, unit: v('ie_unit') || 'Pcs', min_stock: parseFloat(v('ie_min')) || 0, brand: v('ie_brand'),
                     hsn: v('ie_hsn'), barcode: v('ie_bar'), serial_tracked: App.el('ie_sn').checked ? 1 : 0 };
        if (App.el('ie_buy')) body.purchase_price = parseFloat(v('ie_buy')) || 0;
        var local = itemId ? Object.assign({}, it, body) : null;
        Entries.save('item', { customer: name, body: body }, body, itemId ? 'Saved' : name + ' added', itemId ? ['item', itemId] : ['items'])
          .then(function () { if (local) return DB.put('items', local); });
      });
    });
  },

  // === BILLS, PURCHASES, PAYMENTS, EXPENSES =================================
  /** Date range chips shared by the document lists. */
  RANGES: [['today', 'Today'], ['7', '7 days'], ['month', 'This month'], ['fy', 'This year'], ['all', 'All']],
  from: function (r) {
    var t = App.today(), d = new Date();
    if (r === 'today') return t;
    if (r === '7') { d.setDate(d.getDate() - 6); return d.toISOString().slice(0, 10); }
    if (r === 'month') return t.slice(0, 8) + '01';
    if (r === 'fy') { var y = +t.slice(0, 4), m = +t.slice(5, 7); return (m >= 4 ? y : y - 1) + '-04-01'; }
    return '';
  },
  chips: function (cur) {
    return '<div class="chips">' + this.RANGES.map(function (r) { return '<button class="chip' + (r[0] === cur ? ' on' : '') + '" data-rng="' + r[0] + '">' + r[1] + '</button>'; }).join('') + '</div>';
  },
  docList: function (title, scr, store, dateKey, rng, row, sumOf, text, extra) {
    var self = this;
    rng = rng || 'month';
    App.chrome(title, true);
    Promise.all([DB.all(store), DB.all('parties')]).then(function (a) {
      var names = {}; a[1].forEach(function (p) { names[p.id] = p.name; });
      var from = self.from(rng);
      var rows = a[0].filter(function (x) { return !from || (x[dateKey] || '') >= from; })
        .sort(function (x, y) { return (y[dateKey] || '').localeCompare(x[dateKey] || '') || y.id - x.id; });
      var tot = rows.reduce(function (s, x) { return s + sumOf(x); }, 0);
      var head = (extra || '') + self.chips(rng) +
        '<div class="card"><div class="stat"><span>' + rows.length + ' entries</span><strong>₹' + App.money(tot) + '</strong></div></div>';
      self.list(rows, function (x) { return row(x, names); }, { head: head, ph: 'Search', text: function (x) { return text(x, names); } });
      App.el('main').querySelectorAll('[data-rng]').forEach(function (c) {
        c.addEventListener('click', function () { try { history.replaceState({ name: scr, arg: c.dataset.rng }, ''); } catch (e) {} self['scr_' + scr](c.dataset.rng); });
      });
      if (App.el('dl_new')) App.el('dl_new').addEventListener('click', function () { App.go(App.el('dl_new').dataset.to); });
    });
  },

  scr_sales: function (rng) {
    var self = this, N = this.num;
    this.docList('Bills', 'sales', 'ssales', 'sale_date', rng, function (s, names) {
      var due = N(s.total) - N(s.paid);
      return '<li data-go="billview:' + s.id + '"><div><div class="nm">' + App.esc(s.customer_name || names[s.party_id] || 'Walk-in') +
        (s.is_cancelled == 1 ? ' <span class="pill bad">cancelled</span>' : '') + '</div><div class="sub">' + App.esc(s.invoice_no || '') + ' · ' + self.d(s.sale_date) + '</div></div>' +
        '<div class="right"><strong>₹' + App.money(s.total) + '</strong><div class="sub"' + (due > 0.009 && s.is_cancelled != 1 ? ' style="color:var(--bad)">due ₹' + App.money(due) : '>paid') + '</div></div></li>';
    }, function (s) { return s.is_cancelled == 1 ? 0 : N(s.total); },
    function (s, names) { return (s.customer_name || '') + ' ' + (names[s.party_id] || '') + ' ' + (s.invoice_no || '') + ' ' + (s.customer_mobile || ''); },
    App.can('sales.add') ? '<button class="btn" id="dl_new" data-to="newbill">🧾 New bill</button><div style="height:10px"></div>' : '');
  },

  scr_purchases: function (rng) {
    var self = this, N = this.num;
    this.docList('Purchases', 'purchases', 'spurchases', 'purchase_date', rng, function (s, names) {
      var due = N(s.total) - N(s.paid);
      return '<li data-go="purchaseview:' + s.id + '"><div><div class="nm">' + App.esc(names[s.party_id] || 'Supplier') +
        (s.is_cancelled == 1 ? ' <span class="pill bad">cancelled</span>' : '') + '</div><div class="sub">' + App.esc(s.bill_no || '#' + s.id) + ' · ' + self.d(s.purchase_date) + '</div></div>' +
        '<div class="right"><strong>₹' + App.money(s.total) + '</strong><div class="sub"' + (due > 0.009 ? ' style="color:var(--bad)">to pay ₹' + App.money(due) : '>paid') + '</div></div></li>';
    }, function (s) { return s.is_cancelled == 1 ? 0 : N(s.total); },
    function (s, names) { return (names[s.party_id] || '') + ' ' + (s.bill_no || ''); },
    App.can('purchases.add') ? '<button class="btn" id="dl_new" data-to="purchase">📥 New purchase bill</button><div style="height:10px"></div>' : '');
  },

  scr_payments: function (rng) {
    var self = this, N = this.num;
    this.docList('Payments', 'payments', 'spayments', 'pay_date', rng, function (x, names) {
      var inn = x.direction === 'in';
      return '<li' + (x.party_id ? ' data-go="party:' + x.party_id + '"' : '') + '><div><div class="nm">' + App.esc(names[x.party_id] || (x.ref_type === 'sale' ? 'Walk-in bill' : '—')) + '</div>' +
        '<div class="sub">' + (inn ? 'Received' : 'Paid') + ' · ' + App.esc(x.mode || '') + ' · ' + self.d(x.pay_date) + '</div></div>' +
        '<div class="right"><strong style="color:' + (inn ? 'var(--ok)' : 'var(--bad)') + '">' + (inn ? '+' : '−') + ' ₹' + App.money(x.amount) + '</strong></div></li>';
    }, function (x) { return x.direction === 'in' ? N(x.amount) : -N(x.amount); },
    function (x, names) { return (names[x.party_id] || '') + ' ' + (x.mode || '') + ' ' + (x.notes || ''); });
  },

  scr_expenses: function (rng) {
    var self = this, N = this.num;
    this.docList('Expenses', 'expenses', 'sexpenses', 'exp_date', rng, function (x) {
      return '<li><div><div class="nm">' + App.esc(x.category || '') + '</div><div class="sub">' + App.esc(x.mode || '') + ' · ' + self.d(x.exp_date) +
        (x.notes ? ' · ' + App.esc(x.notes) : '') + '</div></div><div class="right"><strong>₹' + App.money(x.amount) + '</strong></div></li>';
    }, function (x) { return N(x.amount); }, function (x) { return (x.category || '') + ' ' + (x.notes || ''); },
    App.can('expenses.add') ? '<button class="btn" id="dl_new" data-to="expense">🧾 New expense</button><div style="height:10px"></div>' : '');
  },

  /** A bill, all of it, from the phone: lines, GST, payments - and print it
   *  (Android's print screen also saves it as a PDF to share). */
  docView: function (kind, id) {
    var self = this, N = this.num, sale = kind === 'sale';
    App.chrome(sale ? 'Bill' : 'Purchase bill', true);
    Promise.all([DB.get(sale ? 'ssales' : 'spurchases', id), DB.all('items'), DB.all('parties'), DB.meta('companies', []), DB.all('spayments'), DB.meta('shop', '')]).then(function (a) {
      var s = a[0]; if (!s) { App.el('main').innerHTML = '<div class="card"><p class="muted">Not on the phone yet. Sync once.</p></div>'; return; }
      var items = {}; a[1].forEach(function (i) { items[i.id] = i; });
      var party = null; a[2].forEach(function (p) { if (p.id == s.party_id) party = p; });
      var co = null; (a[3] || []).forEach(function (c) { if (c.id == s.company_id) co = c; });
      var pays = a[4].filter(function (x) { return x.ref_type === (sale ? 'sale' : 'purchase') && x.ref_id == s.id; });
      var due = N(s.total) - N(s.paid);
      var who = sale ? (s.customer_name || (party && party.name) || 'Walk-in') : ((party && party.name) || 'Supplier');
      var tot = function (l, v, strong) { return v ? '<div class="tot' + (strong ? ' grand' : '') + '"><span>' + l + '</span><span>' + v + '</span></div>' : ''; };
      App.el('main').innerHTML =
        '<div class="card invoice">' +
        '<div class="center"><div class="nm" style="font-size:18px">' + App.esc((co && co.name) || a[5]) + '</div>' +
        '<div class="sub">' + (sale ? 'Tax invoice ' : 'Purchase bill ') + App.esc(sale ? (s.invoice_no || '') : (s.bill_no || '#' + s.id)) + ' · ' + self.d(sale ? s.sale_date : s.purchase_date) + '</div></div>' +
        '<div style="margin:10px 0"><div class="sub">' + (sale ? 'Bill to' : 'From') + '</div><div class="nm">' + App.esc(who) + '</div>' +
        '<div class="sub">' + App.esc((sale ? s.customer_mobile : '') || (party && party.mobile) || '') + (party && party.gstin ? ' · GSTIN ' + App.esc(party.gstin) : '') + '</div></div>' +
        (s.is_cancelled == 1 ? '<p><span class="pill bad">cancelled</span></p>' : '') +
        '<ul class="list">' + (s.lines || []).map(function (l) {
          var it = items[l.item_id] || {};
          return '<li><div><div class="nm">' + App.esc(it.name || l.description || 'Item') + '</div><div class="sub">' + N(l.qty) + ' × ₹' + App.money(l.price) +
            (N(l.tax_rate) ? ' · GST ' + N(l.tax_rate) + '%' : '') + (l.serials ? ' · ' + App.esc(l.serials) : '') + '</div></div>' +
            '<div class="right"><strong>₹' + App.money(l.total) + '</strong></div></li>';
        }).join('') + '</ul>' +
        tot('Subtotal', '₹' + App.money(s.subtotal)) +
        (N(s.discount) ? tot('Discount', '− ₹' + App.money(s.discount)) : '') +
        (N(s.tax_amount) ? tot('GST', '₹' + App.money(s.tax_amount)) : '') +
        (N(s.shipping) ? tot('Shipping', '₹' + App.money(s.shipping)) : '') +
        (N(s.round_off) ? tot('Round off', '₹' + App.money(s.round_off)) : '') +
        tot('Total', '₹' + App.money(s.total), true) +
        tot('Paid', '₹' + App.money(s.paid)) +
        (due > 0.009 && s.is_cancelled != 1 ? '<div class="tot" style="color:var(--bad);font-weight:700"><span>' + (sale ? 'Due' : 'To pay') + '</span><span>₹' + App.money(due) + '</span></div>' : '') +
        (pays.length ? '<div class="sub" style="margin-top:8px">' + pays.map(function (x) { return self.d(x.pay_date) + ' ' + App.esc(x.mode) + ' ₹' + App.money(x.amount); }).join(' · ') + '</div>' : '') +
        '</div>' +
        '<div class="acts no-print">' +
          '<button class="act" id="dv_print"><span>🖨️</span>Print / PDF</button>' +
          (sale && (s.customer_mobile || (party && party.mobile)) && s.is_cancelled != 1 ? '<button class="act" id="dv_wa"><span>📲</span>WhatsApp</button>' : '') +
          (due > 0.009 && s.party_id && s.is_cancelled != 1 && App.can('payments.add') ? '<button class="act" id="dv_pay"><span>₹</span>' + (sale ? 'Take payment' : 'Pay') + '</button>' : '') +
          (party ? '<button class="act" id="dv_party"><span>👤</span>Party</button>' : '') +
          '<button class="act" id="dv_web"><span>✏️</span>Edit / cancel</button>' +
        '</div>';
      var on = function (i, fn) { if (App.el(i)) App.el(i).addEventListener('click', fn); };
      on('dv_print', function () { self.print(); });
      on('dv_wa', function () { More.sendBill({ sale_id: s.id, mobile: s.customer_mobile || party.mobile }); });
      on('dv_pay', function () { App.go(sale ? 'collect' : 'payout', s.party_id); });
      on('dv_party', function () { App.go('party', party.id); });
      on('dv_web', function () { More.openWeb((sale ? 'sale_view.php?id=' : 'purchase_view.php?id=') + s.id); });
    });
  },
  scr_billview: function (id) { this.docView('sale', id); },
  scr_purchaseview: function (id) { this.docView('purchase', id); },

  /** Print what is on screen (the app's bar and buttons are left off). In the
   *  app this is Android's print screen, which also saves a PDF to share. */
  print: function () {
    if (window.AKApp && AKApp.print) AKApp.print(document.title || 'Print'); else window.print();
  },

  // === CASH & BANK ==========================================================
  scr_cashbank: function () {
    var self = this;
    App.chrome('Cash & Bank', true);
    Promise.all([DB.meta('summary', null), DB.all('docs')]).then(function (a) {
      var s = a[0], unsent = a[1].filter(function (d) { return !d.synced; }), cashMove = 0;
      unsent.forEach(function (d) {
        if (d.mode && d.mode !== 'cash') return;
        if (d.kind === 'sale') cashMove += d.paid || 0;
        if (d.kind === 'payment') cashMove += d.amount || 0;
        if (d.kind === 'payout' || d.kind === 'expense') cashMove -= d.amount || 0;
        if (d.kind === 'purchase') cashMove -= d.paid || 0;
      });
      if (!s) { App.el('main').innerHTML = '<div class="card"><p class="muted">Sync once with a network to bring the books onto the phone.</p></div>'; return; }
      App.el('main').innerHTML =
        '<div class="card"><div class="muted">Cash in hand</div><div class="big">₹' + App.money(s.cash + cashMove) + '</div>' +
        (Math.abs(cashMove) > 0.009 ? '<div class="sub">includes ₹' + App.money(cashMove) + ' written on this phone, not sent yet</div>' : '') + '</div>' +
        '<div class="card"><h3>Bank</h3>' + (s.banks || []).map(function (b) {
          return '<div class="stat"><span>' + App.esc(b.name) + '</span><strong>₹' + App.money(b.balance) + '</strong></div>';
        }).join('') + '<div class="stat"><span><b>All banks</b></span><strong>₹' + App.money(s.bank) + '</strong></div></div>' +
        '<div class="card"><div class="stat"><span>To receive (all parties)</span><strong style="color:var(--bad)">₹' + App.money(s.receivable) + '</strong></div>' +
        '<div class="stat"><span>To pay</span><strong>₹' + App.money(s.payable) + '</strong></div>' +
        (s.stock_value != null ? '<div class="stat"><span>Stock value</span><strong>₹' + App.money(s.stock_value) + '</strong></div>' : '') + '</div>' +
        '<div class="acts"><button class="act" data-go="payments"><span>💳</span>Payments</button><button class="act" data-go="expenses"><span>🧾</span>Expenses</button>' +
        '<button class="act" data-go="daybook"><span>📖</span>Day book</button><button class="act" id="cb_web"><span>🏦</span>Transfers, cheques, day close</button></div>' +
        '<p class="center muted" style="font-size:13px">As the shop\'s books stood at the last sync (' + App.ago(s.as_of) + ').</p>';
      App.el('main').querySelectorAll('[data-go]').forEach(function (b) { b.addEventListener('click', function () { App.go(b.dataset.go); }); });
      App.el('cb_web').addEventListener('click', function () { More.openWeb('cash_bank.php'); });
    });
  },

  // === REPORTS ==============================================================
  scr_reports: function () {
    var self = this, N = this.num;
    App.chrome('Reports', true);
    // the figures at a glance first, then every report
    this.load().then(function (b) {
      var sum = function (r) { var f = self.from(r), t = 0, n = 0; b.sales.forEach(function (s) { if (s.is_cancelled != 1 && (s.sale_date || '') >= f) { t += N(s.total); n++; } });
        b.docs.forEach(function (d) { if (d.kind === 'sale' && (d.date || '') >= f) { t += d.total || 0; n++; } }); return '₹' + App.money(t) + ' <span class="sub">· ' + n + ' bills</span>'; };
      var bal = self.balances(b), rec = 0, pay = 0;
      Object.keys(bal).forEach(function (k) { if (bal[k] > 0) rec += bal[k]; else pay -= bal[k]; });
      var box = document.createElement('div');
      box.className = 'card';
      box.innerHTML = '<div class="muted">Today\'s sales</div><div class="big">' + sum('today').split(' <')[0] + '</div>' +
        '<div class="stat"><span>Last 7 days</span><strong>' + sum('7') + '</strong></div>' +
        '<div class="stat"><span>This month</span><strong>' + sum('month') + '</strong></div>' +
        '<div class="stat"><span>To receive</span><strong style="color:var(--bad)">₹' + App.money(rec) + '</strong></div>' +
        '<div class="stat"><span>To pay</span><strong>₹' + App.money(pay) + '</strong></div>';
      DB.meta('books_synced', '').then(function (t) {
        box.innerHTML += '<p class="sub" style="margin:8px 0 0">From the whole shop\'s books on this phone, last synced ' + (t ? App.ago(t) : 'never') + '.</p>';
      });
      if (App.el('main').firstChild) App.el('main').insertBefore(box, App.el('main').firstChild);
    });
    var R = [['daybook', '📖', 'Day book'], ['salesreg', '🧾', 'Sales register'], ['purchasereg', '📥', 'Purchase register'],
             ['outstanding', '📋', 'Who owes / whom we owe'], ['gstsum', '🧮', 'GST summary'], ['expsum', '💸', 'Expenses by kind'],
             ['stocksum', '📦', 'Stock summary'], ['topitems', '🏆', 'Best-selling items'], ['cashbank', '🏦', 'Cash & Bank']];
    App.el('main').innerHTML = '<div class="acts">' + R.map(function (r) { return '<button class="act" data-go="' + r[0] + '"><span>' + r[1] + '</span>' + r[2] + '</button>'; }).join('') + '</div>' +
      '<p class="center muted" style="font-size:13px">Worked out on the phone from the whole shop\'s books — no network needed.<br>' +
      '<a href="#" id="rp_web">Profit &amp; loss, balance sheet and the rest</a></p>';
    App.el('main').querySelectorAll('[data-go]').forEach(function (b) { b.addEventListener('click', function () { App.go(b.dataset.go); }); });
    App.el('rp_web').addEventListener('click', function (e) { e.preventDefault(); More.openWeb('reports.php'); });
  },

  /** A report page: range chips, a summary, rows. */
  report: function (title, scr, rng, build) {
    var self = this;
    rng = rng || 'month';
    App.chrome(title, true);
    this.load().then(function (b) {
      var from = self.from(rng), inR = function (d) { return !from || (d || '') >= from; };
      var out = build(b, inR);
      App.el('main').innerHTML = self.chips(rng) + out + '<div class="no-print"><button class="btn ghost" id="rp_print">🖨️ Print / PDF</button></div>';
      App.el('main').querySelectorAll('[data-rng]').forEach(function (c) {
        c.addEventListener('click', function () { try { history.replaceState({ name: scr, arg: c.dataset.rng }, ''); } catch (e) {} self['scr_' + scr](c.dataset.rng); });
      });
      App.el('main').querySelectorAll('[data-go]').forEach(function (li) {
        li.addEventListener('click', function () { var g = li.dataset.go.split(':'); App.go(g[0], +g[1]); });
      });
      App.el('rp_print').addEventListener('click', function () { self.print(); });
    });
  },

  scr_daybook: function (rng) {
    var self = this, N = this.num;
    this.report('Day book', 'daybook', rng || 'today', function (b, inR) {
      var names = {}; b.parties.forEach(function (p) { names[p.id] = p.name; });
      var rows = [];
      b.sales.forEach(function (s) { if (inR(s.sale_date) && s.is_cancelled != 1) rows.push([s.sale_date, 'Bill ' + s.invoice_no, s.customer_name || names[s.party_id] || 'Walk-in', N(s.total), 'billview:' + s.id]); });
      b.purchases.forEach(function (s) { if (inR(s.purchase_date) && s.is_cancelled != 1) rows.push([s.purchase_date, 'Purchase ' + (s.bill_no || ''), names[s.party_id] || '', -N(s.total), 'purchaseview:' + s.id]); });
      b.payments.forEach(function (x) { if (inR(x.pay_date)) rows.push([x.pay_date, (x.direction === 'in' ? 'Received ' : 'Paid ') + x.mode, names[x.party_id] || '', x.direction === 'in' ? N(x.amount) : -N(x.amount), x.party_id ? 'party:' + x.party_id : '']); });
      b.expenses.forEach(function (x) { if (inR(x.exp_date)) rows.push([x.exp_date, 'Expense', x.category, -N(x.amount), '']); });
      rows.sort(function (x, y) { return y[0].localeCompare(x[0]); });
      return '<div class="card" style="padding:0 14px"><ul class="list">' + (rows.map(function (r) {
        return '<li' + (r[4] ? ' data-go="' + r[4] + '"' : '') + '><div><div class="nm">' + App.esc(r[1]) + '</div><div class="sub">' + self.d(r[0]) + ' · ' + App.esc(r[2] || '') + '</div></div>' +
          '<div class="right"><strong style="color:' + (r[3] >= 0 ? 'var(--ok)' : 'var(--bad)') + '">' + (r[3] >= 0 ? '+' : '−') + ' ₹' + App.money(Math.abs(r[3])) + '</strong></div></li>';
      }).join('') || '<li class="muted">Nothing in this period</li>') + '</ul></div>';
    });
  },

  register: function (title, scr, rng, key, store, dateKey) {
    var self = this, N = this.num;
    this.report(title, scr, rng, function (b, inR) {
      var names = {}; b.parties.forEach(function (p) { names[p.id] = p.name; });
      var rows = b[key].filter(function (s) { return s.is_cancelled != 1 && inR(s[dateKey]); });
      var sum = function (f) { return rows.reduce(function (t, s) { return t + N(s[f]); }, 0); };
      var paid = sum('paid'), total = sum('total');
      return '<div class="card"><div class="stat"><span>' + rows.length + ' bills</span><strong>₹' + App.money(total) + '</strong></div>' +
        '<div class="stat"><span>Before GST</span><strong>₹' + App.money(sum('subtotal') - sum('discount')) + '</strong></div>' +
        '<div class="stat"><span>GST</span><strong>₹' + App.money(sum('tax_amount')) + '</strong></div>' +
        '<div class="stat"><span>Paid</span><strong>₹' + App.money(paid) + '</strong></div>' +
        '<div class="stat"><span>Still due</span><strong style="color:var(--bad)">₹' + App.money(total - paid) + '</strong></div></div>' +
        '<div class="card" style="padding:0 14px"><ul class="list">' + rows.sort(function (x, y) { return (y[dateKey] || '').localeCompare(x[dateKey] || ''); }).slice(0, 500).map(function (s) {
          return '<li data-go="' + (key === 'sales' ? 'billview' : 'purchaseview') + ':' + s.id + '"><div><div class="nm">' + App.esc(s.customer_name || names[s.party_id] || '') + '</div>' +
            '<div class="sub">' + App.esc(s.invoice_no || s.bill_no || '') + ' · ' + self.d(s[dateKey]) + '</div></div><div class="right"><strong>₹' + App.money(s.total) + '</strong></div></li>';
        }).join('') + '</ul></div>';
    });
  },
  scr_salesreg: function (rng) { this.register('Sales register', 'salesreg', rng, 'sales', 'ssales', 'sale_date'); },
  scr_purchasereg: function (rng) { this.register('Purchase register', 'purchasereg', rng, 'purchases', 'spurchases', 'purchase_date'); },

  scr_outstanding: function () {
    var self = this;
    App.chrome('Who owes what', true);
    this.load().then(function (b) {
      var bal = self.balances(b), rec = [], pay = [];
      b.parties.forEach(function (p) { var v = bal[p.id] || 0; if (v > 0.009) rec.push([p, v]); else if (v < -0.009) pay.push([p, -v]); });
      var block = function (title, rows, color) {
        rows.sort(function (x, y) { return y[1] - x[1]; });
        var t = rows.reduce(function (s, r) { return s + r[1]; }, 0);
        return '<div class="card" style="padding:0 14px"><h3 style="margin:14px 0 4px">' + title + ' · ₹' + App.money(t) + '</h3><ul class="list">' + rows.map(function (r) {
          return '<li data-go="party:' + r[0].id + '"><div><div class="nm">' + App.esc(r[0].name) + '</div><div class="sub">' + App.esc(r[0].mobile || '') + '</div></div>' +
            '<div class="right"><strong style="color:' + color + '">₹' + App.money(r[1]) + '</strong></div></li>';
        }).join('') + '</ul></div>';
      };
      App.el('main').innerHTML = block('They owe you', rec, 'var(--bad)') + block('You owe them', pay, 'inherit') +
        '<div class="no-print"><button class="btn ghost" id="rp_print">🖨️ Print / PDF</button></div>';
      App.el('main').querySelectorAll('[data-go]').forEach(function (li) { li.addEventListener('click', function () { App.go('party', +li.dataset.go.split(':')[1]); }); });
      App.el('rp_print').addEventListener('click', function () { self.print(); });
    });
  },

  scr_gstsum: function (rng) {
    var N = this.num;
    this.report('GST summary', 'gstsum', rng, function (b, inR) {
      var by = {};
      var add = function (side, rate, taxable, tax) { var k = side + rate; by[k] = by[k] || { side: side, rate: rate, taxable: 0, tax: 0 }; by[k].taxable += taxable; by[k].tax += tax; };
      b.sales.forEach(function (s) { if (s.is_cancelled != 1 && inR(s.sale_date)) (s.lines || []).forEach(function (l) { add('Sales', N(l.tax_rate), N(l.total), N(l.total) * N(l.tax_rate) / 100); }); });
      b.purchases.forEach(function (s) { if (s.is_cancelled != 1 && inR(s.purchase_date)) (s.lines || []).forEach(function (l) { add('Purchases', N(l.tax_rate), N(l.total), N(l.total) * N(l.tax_rate) / 100); }); });
      var rows = Object.keys(by).map(function (k) { return by[k]; }).sort(function (x, y) { return x.side.localeCompare(y.side) || x.rate - y.rate; });
      var outT = 0, inT = 0; rows.forEach(function (r) { if (r.side === 'Sales') outT += r.tax; else inT += r.tax; });
      return '<div class="card"><div class="stat"><span>GST on sales</span><strong>₹' + App.money(outT) + '</strong></div>' +
        '<div class="stat"><span>GST on purchases (input)</span><strong>₹' + App.money(inT) + '</strong></div>' +
        '<div class="stat"><span><b>To pay (before adjustments)</b></span><strong>₹' + App.money(outT - inT) + '</strong></div></div>' +
        '<div class="card"><h3>By rate</h3>' + rows.map(function (r) {
          return '<div class="stat"><span>' + r.side + ' @ ' + r.rate + '% · taxable ₹' + App.money(r.taxable) + '</span><strong>₹' + App.money(r.tax) + '</strong></div>';
        }).join('') + '</div><p class="center muted" style="font-size:13px">Line-level, before bill discounts. The filed GSTR-1/3B files are made on the GST returns screen.</p>';
    });
  },

  scr_expsum: function (rng) {
    var N = this.num;
    this.report('Expenses by kind', 'expsum', rng, function (b, inR) {
      var by = {}, t = 0;
      b.expenses.forEach(function (x) { if (inR(x.exp_date)) { by[x.category] = (by[x.category] || 0) + N(x.amount); t += N(x.amount); } });
      return '<div class="card"><div class="stat"><span><b>All expenses</b></span><strong>₹' + App.money(t) + '</strong></div>' +
        Object.keys(by).sort(function (x, y) { return by[y] - by[x]; }).map(function (k) { return '<div class="stat"><span>' + App.esc(k) + '</span><strong>₹' + App.money(by[k]) + '</strong></div>'; }).join('') + '</div>';
    });
  },

  scr_topitems: function (rng) {
    var N = this.num;
    this.report('Best-selling items', 'topitems', rng, function (b, inR) {
      var names = {}; b.items.forEach(function (i) { names[i.id] = i.name; });
      var by = {};
      b.sales.forEach(function (s) { if (s.is_cancelled != 1 && inR(s.sale_date)) (s.lines || []).forEach(function (l) {
        by[l.item_id] = by[l.item_id] || { q: 0, v: 0 }; by[l.item_id].q += N(l.qty); by[l.item_id].v += N(l.total); }); });
      return '<div class="card" style="padding:0 14px"><ul class="list">' + Object.keys(by).sort(function (x, y) { return by[y].v - by[x].v; }).slice(0, 100).map(function (k) {
        return '<li data-go="item:' + k + '"><div><div class="nm">' + App.esc(names[k] || 'Item ' + k) + '</div><div class="sub">' + by[k].q + ' sold</div></div><div class="right"><strong>₹' + App.money(by[k].v) + '</strong></div></li>';
      }).join('') + '</ul></div>';
    });
  },

  scr_stocksum: function () {
    var self = this, N = this.num;
    App.chrome('Stock summary', true);
    DB.all('items').then(function (items) {
      var prods = items.filter(function (i) { return i.is_active != 0 && i.item_type !== 'service'; });
      var val = 0, low = [];
      prods.forEach(function (i) { val += Math.max(0, N(i.stock)) * N(i.purchase_price); if (N(i.stock) <= N(i.min_stock)) low.push(i); });
      App.el('main').innerHTML = '<div class="card"><div class="stat"><span>Products</span><strong>' + prods.length + '</strong></div>' +
        (App.can('items.cost') ? '<div class="stat"><span>Value at purchase price</span><strong>₹' + App.money(val) + '</strong></div>' : '') +
        '<div class="stat"><span>Low or out of stock</span><strong style="color:var(--bad)">' + low.length + '</strong></div></div>' +
        '<div class="card" style="padding:0 14px"><h3 style="margin:14px 0 4px">To buy</h3><ul class="list">' + low.map(function (i) {
          return '<li data-go="item:' + i.id + '"><div><div class="nm">' + App.esc(i.name) + '</div><div class="sub">alert at ' + N(i.min_stock) + '</div></div><div class="right"><strong style="color:var(--bad)">' + N(i.stock) + '</strong></div></li>';
        }).join('') + '</ul></div><div class="no-print"><button class="btn ghost" id="rp_print">🖨️ Print / PDF</button></div>';
      App.el('main').querySelectorAll('[data-go]').forEach(function (li) { li.addEventListener('click', function () { App.go('item', +li.dataset.go.split(':')[1]); }); });
      App.el('rp_print').addEventListener('click', function () { self.print(); });
    });
  },

  // === SETTINGS =============================================================
  scr_settings: function () {
    var self = this;
    App.chrome('Settings', true);
    Promise.all([DB.meta('user', {}), DB.meta('server', ''), DB.meta('shop', ''), DB.meta('settings', null), DB.meta('companies', []), Sync.status(), DB.meta('books_synced', '')]).then(function (a) {
      var u = a[0] || {}, st = a[3] || {}, can = App.can('settings.edit');
      var f = function (k, label, type) {
        var v = st[k] == null ? '' : st[k];
        if (type === 'check') return '<label style="display:flex;gap:8px;align-items:center;font-weight:400;color:inherit"><input type="checkbox" data-k="' + k + '" style="width:auto;margin:0"' + (v === '1' ? ' checked' : '') + (can ? '' : ' disabled') + '> ' + label + '</label>';
        return '<label>' + label + '</label><input data-k="' + k + '" value="' + App.esc(v) + '"' + (can ? '' : ' disabled') + '>';
      };
      App.el('main').innerHTML =
        '<div class="card"><div class="nm">' + App.esc(u.name || '') + '</div><div class="sub">' + App.esc((u.role || '') + ' · ' + (u.location || '')) + '</div>' +
        '<div class="sub">' + App.esc(a[2]) + ' · ' + App.esc(a[1].replace(/^https?:\/\//, '')) + '</div></div>' +
        '<div class="card"><h3>This phone</h3>' +
        '<div class="stat"><span>Waiting to send</span><strong>' + a[5].pending + '</strong></div>' +
        '<div class="stat"><span>Books on the phone</span><strong>' + (a[6] ? App.ago(a[6]) : 'not yet') + '</strong></div>' +
        '<button class="btn sec" data-go="sync">⟳ Sync &amp; log out</button></div>' +
        (a[3] ? '<div class="card"><h3>Shop</h3>' +
          f('app_name', 'Shop name') + f('shop_mobile', 'Shop mobile') + f('shop_whatsapp', 'WhatsApp number for orders') +
          f('round_off_default', 'Round off bills to the rupee', 'check') + f('allow_negative_stock', 'Allow billing more than the stock', 'check') +
          f('app_email', 'Shop e-mail') + f('default_margin_pct', 'Minimum profit % on every item') +
          f('google_review_link', 'Google review link (sent after a bill)') +
          (can ? '<div class="err" id="st_err"></div><button class="btn ok" id="st_save">💾 Save</button>' : '<p class="muted">Only the owner can change these.</p>') + '</div>' : '') +
        '<div class="card"><h3>Firms</h3>' + (a[4] || []).map(function (c) {
          return '<div class="stat"><span>' + App.esc(c.name) + '</span><strong>' + (c.is_gst == 1 ? 'GST' : 'non-GST') + ' · ' + App.esc(c.invoice_prefix || '') + '</strong></div>';
        }).join('') + '</div>' +
        '<div class="acts">' + [['settings.php', '⚙️', 'All settings'], ['users.php', '👥', 'Staff & roles'], ['companies.php', '🏢', 'Firms'], ['payment_methods.php', '💳', 'Payment methods'],
          ['connections.php', '🔗', 'WhatsApp, e-mail, UPI'], ['my_account.php', '👤', 'My account']].map(function (w) {
          return '<button class="act" data-web="' + w[0] + '"><span>' + w[1] + '</span>' + w[2] + '</button>';
        }).join('') + '</div>';
      App.el('main').querySelectorAll('[data-go]').forEach(function (b) { b.addEventListener('click', function () { App.go(b.dataset.go); }); });
      App.el('main').querySelectorAll('[data-web]').forEach(function (b) { b.addEventListener('click', function () { More.openWeb(b.dataset.web); }); });
      if (App.el('st_save')) App.el('st_save').addEventListener('click', function () {
        var body = {};
        App.el('main').querySelectorAll('[data-k]').forEach(function (i) { body[i.dataset.k] = i.type === 'checkbox' ? (i.checked ? '1' : '0') : i.value.trim(); });
        DB.setMeta('settings', Object.assign({}, st, body));
        Entries.save('settings', { customer: 'Settings', body: body }, body, 'Settings saved', ['settings']);
      });
    });
  }
};

['parties', 'party', 'partyedit', 'items', 'item', 'itemedit', 'sales', 'purchases', 'payments', 'expenses', 'billview', 'purchaseview',
 'cashbank', 'reports', 'daybook', 'salesreg', 'purchasereg', 'outstanding', 'gstsum', 'expsum', 'topitems', 'stocksum', 'settings'].forEach(function (n) {
  App['scr_' + n] = function (arg) { return Books['scr_' + n].call(Books, arg); };
});
