// ---------------------------------------------------------------------------
// Money going OUT and goods coming IN: a purchase bill, a payment to a
// supplier, an expense.
//
// The same rule as billing: saved on the phone first, sent afterwards, with
// no signal too. The server writes each one through the website screen's own
// save (purchase_save_form, payment_save_form, expense_save_form), so stock,
// serials, the cost price, bill links and the cash book come out exactly as
// if it had been typed on the website. The phone only says WHAT happened; the
// tax, the totals and which bills a payment settles are worked out there.
// ---------------------------------------------------------------------------
var Entries = {

  /** Write it here, queue it, go home. One place, so the three screens
   *  cannot drift apart in how they save. */
  save: function (kind, doc, body, msg) {
    var uuid = newUuid();
    doc.uuid = uuid; doc.kind = kind; doc.date = doc.date || App.today();
    doc.created_at = new Date().toISOString(); doc.synced = false;
    body.client_uuid = uuid;
    return DB.put('docs', doc)
      .then(function () { return Sync.queue({ uuid: uuid, kind: kind, body: body, created_at: doc.created_at }); })
      .then(function () {
        App.toast(msg);
        App.go('home');
        if (navigator.onLine) App.syncNow(false);
      });
  },

  /** The shop's ways of paying, as the website lists them. */
  modes: function (sel, withCredit) {
    return DB.meta('modes', []).then(function (modes) {
      var list = (modes && modes.length) ? modes : [{ code: 'cash', name: 'Cash' }, { code: 'upi', name: 'UPI' }, { code: 'bank', name: 'Bank' }];
      // "credit" is not a way of paying - it is not paying yet
      list = list.filter(function (m) { return m.code !== 'credit'; });
      sel.innerHTML = list.map(function (m) { return '<option value="' + App.esc(m.code) + '">' + App.esc(m.name) + '</option>'; }).join('') +
                      (withCredit ? '<option value="credit">Credit (pay later)</option>' : '');
    });
  },

  findSuppliers: function (q) {
    return App.findParties(q).then(function (rows) {
      // suppliers first, but a customer who also sells to us is still findable
      return rows.sort(function (a, b) {
        var sa = a.type === 'customer' ? 1 : 0, sb = b.type === 'customer' ? 1 : 0;
        return sa - sb;
      });
    });
  },

  // --- PURCHASE BILL --------------------------------------------------------
  pur: null,

  scr_purchase: function () {
    var self = this;
    App.chrome('Purchase bill', true);
    if (!this.pur) {
      return DB.meta('pdraft', null).then(function (d) {
        self.pur = (d && d.lines) ? d : { lines: [], party: null, bill_no: '', paid: 0, mode: 'credit' };
        self.scr_purchase();
      });
    }
    App.el('main').innerHTML =
      '<div class="card">' +
      '<label>Supplier *</label>' +
      '<div class="pick"><input type="text" id="u_party" placeholder="Type the supplier\'s name" autocomplete="off"><div class="res" id="u_pres"></div></div>' +
      '<label>Supplier\'s bill number</label><input type="text" id="u_no" autocomplete="off">' +
      '<div id="u_cobox" hidden><label>Bought by which firm</label><select id="u_co"></select></div>' +
      '</div>' +
      '<div class="card"><h3>Items</h3>' +
      '<div class="pick"><input type="text" id="u_item" placeholder="Type an item name" autocomplete="off"><div class="res" id="u_ires"></div></div>' +
      '<div class="lines" id="u_lines"></div></div>' +
      '<div class="card">' +
      '<div class="tot"><span>Before GST</span><span id="u_sub">₹0.00</span></div>' +
      '<div class="tot"><span>GST</span><span id="u_tax">₹0.00</span></div>' +
      '<div class="tot grand"><span>Total</span><span id="u_tot">₹0.00</span></div>' +
      '<label style="margin-top:10px">Paid now</label>' +
      '<div class="row"><input type="number" id="u_paid" inputmode="decimal" min="0" step="any"><button class="btn sec" id="u_full" style="max-width:110px">All</button></div>' +
      '<label>How</label><select id="u_mode"></select>' +
      '<div class="err" id="u_err"></div>' +
      '<button class="btn ok" id="u_save">💾 Save purchase</button>' +
      (this.pur.lines.length ? '<button class="btn ghost" id="u_clear">Discard</button>' : '') +
      '</div>';
    var p = this.pur;
    App.el('u_party').value = p.party ? p.party.name : '';
    App.el('u_no').value = p.bill_no || '';
    App.el('u_paid').value = p.paid || '';
    this.modes(App.el('u_mode'), true).then(function () { App.el('u_mode').value = p.mode || 'credit'; });
    Promise.all([DB.meta('companies', []), DB.meta('composition', 0)]).then(function (a) {
      self._cos = a[0] || []; self._composition = !!a[1];
      if (p.company_id == null) p.company_id = self._cos.length ? self._cos[0].id : 0;
      if (self._cos.length > 1) {
        App.el('u_cobox').hidden = false;
        App.el('u_co').innerHTML = self._cos.map(function (c) { return '<option value="' + c.id + '">' + App.esc(c.name) + (c.is_gst == 1 ? ' (GST)' : '') + '</option>'; }).join('');
        App.el('u_co').value = p.company_id;
        App.el('u_co').addEventListener('change', function () { p.company_id = parseInt(this.value, 10); self.keep(); self.drawLines(); });
      }
      self.drawLines();
    });
    App.wirePick('u_party', 'u_pres', function (q) { return self.findSuppliers(q); }, function (pt) {
      p.party = pt; App.el('u_party').value = pt.name; self.keep();
    });
    App.wirePick('u_item', 'u_ires', function (q) { return App.findItems(q); }, function (it) {
      var ex = null;
      p.lines.forEach(function (l) { if (l.item_id === it.id) ex = l; });
      if (ex) ex.qty += 1;
      else p.lines.push({ item_id: it.id, name: it.name, qty: 1, price: parseFloat(it.purchase_price) || 0,
                          tax_rate: parseFloat(it.tax_rate) || 0, serial_tracked: it.serial_tracked == 1, serials: '' });
      App.el('u_item').value = '';
      self.drawLines(); self.keep();
    });
    ['u_no', 'u_paid', 'u_mode'].forEach(function (id) { App.el(id).addEventListener('change', function () { self.keep(); }); });
    App.el('u_full').addEventListener('click', function () { App.el('u_paid').value = self.math().total.toFixed(2); App.el('u_mode').value = 'cash'; self.keep(); });
    App.el('u_save').addEventListener('click', function () { self.savePurchase(); });
    if (App.el('u_clear')) App.el('u_clear').addEventListener('click', function () {
      if (!confirm('Throw away this purchase?')) return;
      self.pur = null; DB.setMeta('pdraft', null).then(function () { App.go('home'); });
    });
    this.drawLines();
  },

  keep: function () {
    var p = this.pur; if (!p) return;
    if (App.el('u_no')) p.bill_no = App.el('u_no').value.trim();
    if (App.el('u_paid')) p.paid = Math.max(0, parseFloat(App.el('u_paid').value) || 0);
    if (App.el('u_mode')) p.mode = App.el('u_mode').value;
    return DB.setMeta('pdraft', p);
  },

  /** GST on a purchase is the supplier's rate on each line (editable - their
   *  bill may differ from our item master). The server repeats this sum. */
  math: function () {
    var sub = 0, tax = 0, p = this.pur, co = null;
    (this._cos || []).forEach(function (c) { if (c.id == p.company_id) co = c; });
    var gst = !!(co && co.is_gst == 1);   // a non-GST firm takes no input GST, as on the website
    (p.lines || []).forEach(function (l) { var n = l.qty * l.price; sub += n; if (gst) tax += n * (l.tax_rate || 0) / 100; });
    var r = function (n) { return Math.round(n * 100) / 100; };
    return { sub: r(sub), tax: r(tax), total: r(sub + tax) };
  },

  drawLines: function () {
    var self = this, box = App.el('u_lines'), p = this.pur;
    if (!box || !p) return;
    box.innerHTML = p.lines.length ? p.lines.map(function (l, i) {
      return '<div class="line"><div style="flex:1;min-width:0"><div class="nm">' + App.esc(l.name) + '</div>' +
        '<div class="row cap" style="margin-top:6px;gap:6px"><span>Qty</span><span>Rate ₹</span><span style="max-width:70px">GST %</span></div>' +
        '<div class="row" style="gap:6px">' +
        '<input type="number" inputmode="decimal" step="any" min="0" value="' + l.qty + '" data-f="qty" data-i="' + i + '" style="margin:0" aria-label="Quantity" placeholder="Qty">' +
        '<input type="number" inputmode="decimal" step="any" min="0" value="' + l.price + '" data-f="price" data-i="' + i + '" style="margin:0" aria-label="Rate" placeholder="Rate">' +
        '<input type="number" inputmode="decimal" step="any" min="0" value="' + l.tax_rate + '" data-f="tax_rate" data-i="' + i + '" style="margin:0;max-width:70px" aria-label="GST %" placeholder="GST%">' +
        '</div>' +
        (l.serial_tracked ? '<textarea data-f="serials" data-i="' + i + '" rows="2" style="margin-top:6px" placeholder="Serial numbers, one per line">' + App.esc(l.serials || '') + '</textarea>' : '') +
        '</div><div class="right"><strong>₹' + App.money(l.qty * l.price) + '</strong><br><button class="x" data-x="' + i + '">✕</button></div></div>';
    }).join('') : '<p class="muted" style="margin:6px 0">Type an item name in the box above.</p>';
    box.querySelectorAll('[data-f]').forEach(function (inp) {
      inp.addEventListener('change', function () {
        var l = p.lines[parseInt(inp.dataset.i, 10)], f = inp.dataset.f;
        if (f === 'serials') {
          l.serials = inp.value;
          // one serial = one piece, as on the website's purchase screen
          var n = inp.value.split(/[\r\n,]+/).filter(function (x) { return x.trim(); }).length;
          if (n) l.qty = n;
        } else l[f] = Math.max(0, parseFloat(inp.value) || 0);
        self.keep();
        // redraw after this event, not inside it: a change fired because the
        // screen is being replaced must not write into the screen going away
        setTimeout(function () { self.drawLines(); }, 0);
      });
    });
    box.querySelectorAll('[data-x]').forEach(function (b) {
      b.addEventListener('click', function () { p.lines.splice(parseInt(b.dataset.x, 10), 1); self.drawLines(); self.keep(); });
    });
    var m = this.math();
    App.el('u_sub').textContent = '₹' + App.money(m.sub);
    App.el('u_tax').textContent = '₹' + App.money(m.tax);
    App.el('u_tot').textContent = '₹' + App.money(m.total);
  },

  savePurchase: function () {
    var self = this, p = this.pur, err = App.el('u_err');
    this.keep();
    err.textContent = '';
    if (!p.party) { err.textContent = 'Choose the supplier'; App.el('u_party').focus(); return; }
    if (!p.lines.length) { err.textContent = 'Add at least one item'; App.el('u_item').focus(); return; }
    var bad = null;
    p.lines.forEach(function (l) {
      if (bad) return;
      if (!(l.qty > 0)) bad = l.name + ': enter a quantity';
      var n = (l.serials || '').split(/[\r\n,]+/).filter(function (x) { return x.trim(); }).length;
      if (!bad && l.serial_tracked && n !== l.qty) bad = l.name + ': ' + n + ' of ' + l.qty + ' serial numbers entered';
    });
    if (bad) { err.textContent = bad; return; }
    var m = this.math();
    var paid = Math.min(p.paid || 0, m.total);
    var mode = paid > 0.009 ? (p.mode === 'credit' ? 'cash' : p.mode) : 'credit';
    var body = {
      party_id: p.party.id, company_id: p.company_id || 0, bill_no: p.bill_no, purchase_date: App.today(), paid: paid, payment_mode: mode,
      notes: 'From the mobile app',
      items: p.lines.map(function (l) {
        return { item_id: l.item_id, qty: l.qty, price: l.price, tax_rate: l.tax_rate,
                 serials: (l.serials || '').split(/[\r\n,]+/).map(function (x) { return x.trim(); }).filter(Boolean) };
      })
    };
    this.save('purchase', { party_id: p.party.id, customer: p.party.name, total: m.total, paid: paid, bill_no: p.bill_no, lines: p.lines.slice() },
              body, 'Purchase saved ₹' + App.money(m.total))
      .then(function () { self.pur = null; return DB.setMeta('pdraft', null); })
      .catch(function (e) { err.textContent = 'Could not save: ' + ((e && e.message) || ''); });
  },

  // --- MONEY PAID OUT -------------------------------------------------------
  scr_payout: function (partyId) {
    var self = this;
    App.chrome('Money paid', true);
    App.el('main').innerHTML =
      '<div class="card">' +
      '<label>To which party *</label>' +
      '<div class="pick"><input type="text" id="o_party" placeholder="Type the supplier\'s name" autocomplete="off"><div class="res" id="o_res"></div></div>' +
      '<div class="muted" id="o_bal" style="margin:-4px 0 10px"></div>' +
      '<label>How much *</label><input type="number" id="o_amt" inputmode="decimal" min="0" step="any">' +
      '<label>How</label><select id="o_mode"></select>' +
      '<label>Note</label><input type="text" id="o_note" maxlength="200">' +
      '<p class="muted" style="font-size:13px;margin:0 0 8px">It settles this party\'s oldest unpaid purchase bills first, as on the website.</p>' +
      '<div class="err" id="o_err"></div>' +
      '<button class="btn ok" id="o_save">💾 Record payment</button></div>';
    this.modes(App.el('o_mode'), false);
    var picked = null;
    var show = function (pt) {
      picked = pt; App.el('o_party').value = pt.name;
      var b = parseFloat(pt.balance) || 0;
      // a negative balance is money WE owe them
      App.el('o_bal').textContent = b < -0.009 ? ('We owe ₹' + App.money(-b)) : (b > 0.009 ? ('They owe us ₹' + App.money(b)) : 'All settled');
      if (b < -0.009) App.el('o_amt').value = (-b).toFixed(2);
    };
    App.wirePick('o_party', 'o_res', function (q) { return self.findSuppliers(q); }, show);
    if (partyId) DB.get('parties', partyId).then(function (pt) { if (pt) show(pt); });
    App.el('o_save').addEventListener('click', function () {
      var err = App.el('o_err'); err.textContent = '';
      if (!picked) { err.textContent = 'Choose a party'; App.el('o_party').focus(); return; }
      var amt = parseFloat(App.el('o_amt').value) || 0;
      if (amt <= 0) { err.textContent = 'Enter an amount'; App.el('o_amt').focus(); return; }
      var mode = App.el('o_mode').value, note = App.el('o_note').value.trim();
      self.save('payout', { party_id: picked.id, customer: picked.name, amount: amt, mode: mode },
                { party_id: picked.id, direction: 'out', amount: amt, mode: mode, pay_date: App.today(), notes: note || 'From the mobile app' },
                '₹' + App.money(amt) + ' paid to ' + picked.name)
        .catch(function (e) { err.textContent = 'Could not save: ' + ((e && e.message) || ''); });
    });
  },

  // --- EXPENSE --------------------------------------------------------------
  scr_expense: function () {
    var self = this;
    App.chrome('Expense', true);
    DB.meta('expense_categories', []).then(function (cats) {
      cats = (cats && cats.length) ? cats : ['General'];
      App.el('main').innerHTML =
        '<div class="card">' +
        '<label>What for *</label><select id="e_cat">' + cats.map(function (c) { return '<option>' + App.esc(c) + '</option>'; }).join('') + '</select>' +
        '<label>How much *</label><input type="number" id="e_amt" inputmode="decimal" min="0" step="any">' +
        '<label>Paid how</label><select id="e_mode"></select>' +
        '<label>Note</label><input type="text" id="e_note" maxlength="200" placeholder="e.g. tea for the staff">' +
        '<div class="err" id="e_err"></div>' +
        '<button class="btn ok" id="e_save">💾 Save expense</button></div>' +
        '<p class="center muted" style="font-size:13px">A big expense may need the owner\'s approval before it counts — the shop\'s own rule.</p>';
      self.modes(App.el('e_mode'), false);
      App.el('e_save').addEventListener('click', function () {
        var err = App.el('e_err'); err.textContent = '';
        var amt = parseFloat(App.el('e_amt').value) || 0;
        if (amt <= 0) { err.textContent = 'Enter an amount'; App.el('e_amt').focus(); return; }
        var cat = App.el('e_cat').value, mode = App.el('e_mode').value, note = App.el('e_note').value.trim();
        self.save('expense', { customer: cat, amount: amt, mode: mode },
                  { amount: amt, category: cat, mode: mode, exp_date: App.today(), notes: note },
                  '₹' + App.money(amt) + ' ' + cat + ' saved')
          .catch(function (e) { err.textContent = 'Could not save: ' + ((e && e.message) || ''); });
      });
    });
  }
};

['purchase', 'payout', 'expense'].forEach(function (n) {
  App['scr_' + n] = function (arg) { return Entries['scr_' + n].call(Entries, arg); };
});
