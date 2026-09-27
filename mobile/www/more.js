// ---------------------------------------------------------------------------
// The rest of the shop.
//
// Billing is in ui.js. This file is everything the counter also does in a
// day: who owes what, a party's statement, what is on the shelf, the day's
// figures - and the way in to every other screen the website has.
//
// Same rule as ui.js: these read the phone's own copy, so they work with no
// signal. The one exception is marked as such and says so on screen - a
// website screen cannot be opened without a network, and pretending
// otherwise would be worse than admitting it.
// ---------------------------------------------------------------------------
var More = {

  // --- WhatsApp -------------------------------------------------------------
  /**
   * Send through the SHOP's WhatsApp account, not the phone's.
   *
   * Handing the message to the handset's own WhatsApp looked easier, and
   * was wrong: it goes out from whichever staff member's personal number
   * made the bill, with no PDF attached, nothing in the shop's chat log,
   * and no record that it was ever sent. The shop already has a WhatsApp
   * API set up - this uses it.
   *
   * The phone never sends the WORDS, only which bill or which party. What a
   * customer receives is decided on the server, in the same one place the
   * website decides it, so the shop speaks with one voice whatever device
   * the bill came from.
   *
   * And it is queued, not fired: with no signal the errand waits in the
   * outbox behind the bill it belongs to and goes out by itself later.
   */
  send: function (kind, body, what) {
    var uuid = newUuid();
    return Sync.queue({ uuid: uuid, kind: kind, body: body, created_at: new Date().toISOString() })
      .then(function () {
        if (!navigator.onLine) { App.toast(what + ' will be sent when the network is back'); return; }
        App.toast('Sending ' + what + '…');
        return Sync.push(true).then(function () {
          return DB.get('outbox', uuid).then(function (still) {
            if (!still) { App.toast(what + ' sent ✔'); return; }
            if (still.state === 'attention') App.toast(still.last_error || 'Could not send', true);
            else App.toast('Will be sent shortly');
            App.syncNow(false);
          });
        });
      });
  },

  sendBill: function (doc) {
    if (!doc.mobile && !doc.customer_mobile) { App.toast('This bill has no mobile number', true); return; }
    return this.send('whatsapp', {
      client_uuid: doc.uuid || null, sale_id: doc.server_id || doc.sale_id || 0,
      mobile: doc.mobile || doc.customer_mobile
    }, 'the bill');
  },

  sendReminder: function (partyId) {
    return this.send('reminder', { party_id: partyId }, 'the reminder');
  },

  // --- one party: balance, statement, and what to do about it ---------------
  /** A party's rows from the phone's own copy: the shop's bills and payments
   *  for the window that was synced, PLUS anything written on this phone
   *  that has not gone up yet - otherwise a bill made two minutes ago would
   *  be missing from the very statement the customer is being shown. */
  partyRows: function (partyId) {
    return Promise.all([
      DB.by('ssales', 'party_id', partyId),
      DB.by('spayments', 'party_id', partyId),
      DB.all('docs')
    ]).then(function (a) {
      var rows = [];
      a[0].forEach(function (s) {
        if (s.is_cancelled == 1) return;
        rows.push({ date: s.sale_date, what: 'Bill ' + (s.invoice_no || ''), debit: parseFloat(s.total) || 0,
                    credit: 0, id: 'S' + s.id });
      });
      a[1].forEach(function (p) {
        var amt = parseFloat(p.amount) || 0;
        rows.push({ date: p.pay_date, what: (p.direction === 'out' ? 'Paid out' : 'Received') + (p.mode ? ' (' + p.mode + ')' : ''),
                    debit: p.direction === 'out' ? amt : 0, credit: p.direction === 'out' ? 0 : amt, id: 'P' + p.id });
      });
      // not yet sent - shown, and marked, so nobody is surprised later
      a[2].forEach(function (d) {
        if (d.synced || d.party_id != partyId) return;
        if (d.kind === 'sale') rows.push({ date: d.date, what: 'Bill (not sent yet)', debit: d.total || 0, credit: 0, id: d.uuid, pending: true });
        else rows.push({ date: d.date, what: 'Received (not sent yet)', debit: 0, credit: d.amount || 0, id: d.uuid, pending: true });
      });
      rows.sort(function (x, y) { return (y.date || '').localeCompare(x.date || ''); });
      return rows;
    });
  },

  scr_party: function (partyId) {
    var self = this;
    App.chrome('Party account', true);
    Promise.all([DB.get('parties', partyId), this.partyRows(partyId), DB.meta('shop', 'AK Computer')])
      .then(function (a) {
        var p = a[0], rows = a[1], shop = a[2];
        if (!p) { App.el('main').innerHTML = '<div class="card"><p class="muted">This party is not on the phone yet. Sync once.</p></div>'; return; }
        var bal = parseFloat(p.balance) || 0;
        App.el('main').innerHTML =
          '<div class="card">' +
          '<div class="muted">' + App.esc(p.name) + (p.mobile ? ' · ' + App.esc(p.mobile) : '') + '</div>' +
          '<div class="big" style="color:' + (bal > 0.009 ? 'var(--bad)' : 'var(--ok)') + '">₹' + App.money(Math.abs(bal)) + '</div>' +
          '<div class="muted">' + (bal > 0.009 ? 'To receive' : (bal < -0.009 ? 'Advance held' : 'All settled')) + '</div>' +
          '</div>' +
          '<div class="row" style="margin-bottom:10px">' +
          '<button class="btn ok" id="p_collect">₹ Take money</button>' +
          '<button class="btn sec" id="p_bill">🧾 New bill</button>' +
          '</div>' +
          (p.mobile && bal > 0.009
            ? '<button class="btn sec" id="p_remind" style="margin-bottom:10px">📩 Send payment reminder</button>' : '') +
          '<div class="card"><h3>Recent account</h3>' + (rows.length
            ? '<ul class="list">' + rows.slice(0, 100).map(function (r) {
                return '<li><div><div class="nm">' + App.esc(r.what) + (r.pending ? ' <span class="pill wait">pending</span>' : '') + '</div>' +
                  '<div class="sub">' + App.esc(r.date || '') + '</div></div><div class="right">' +
                  (r.debit ? '<strong>₹' + App.money(r.debit) + '</strong>' : '<strong style="color:var(--ok)">− ₹' + App.money(r.credit) + '</strong>') +
                  '</div></li>';
              }).join('') + '</ul>'
            : '<p class="muted">No entries in this period.</p>') +
          '<p class="muted" style="font-size:12px;margin-bottom:0">Showing the period that was last synced.</p></div>';

        App.el('p_collect').addEventListener('click', function () { App.go('collect', p.id); });
        App.el('p_bill').addEventListener('click', function () {
          App.bill = null; App.go('newbill'); setTimeout(function () {
            var i = App.el('b_cust'); if (i) { i.value = p.name; App.bill.party = p; App.el('b_mob').value = p.mobile || ''; }
          }, 60);
        });
        if (App.el('p_remind')) {
          App.el('p_remind').addEventListener('click', function () {
            if (bal <= 0.009) { App.toast('Nothing is owed by this party', true); return; }
            self.sendReminder(p.id);
          });
        }
      });
  },

  // --- who owes what --------------------------------------------------------
  scr_dues: function () {
    var self = this;
    App.chrome('Dues', true);
    DB.all('parties').then(function (rows) {
      var due = rows.filter(function (p) { return (parseFloat(p.balance) || 0) > 0.009; })
                    .sort(function (a, b) { return (parseFloat(b.balance) || 0) - (parseFloat(a.balance) || 0); });
      var tot = due.reduce(function (s, p) { return s + (parseFloat(p.balance) || 0); }, 0);
      App.el('main').innerHTML =
        '<div class="card"><div class="muted">Total to receive</div>' +
        '<div class="big">₹' + App.money(tot) + '</div>' +
        '<div class="muted">' + due.length + ' parties</div></div>' +
        '<div class="card"><input type="search" id="d_q" placeholder="Search a party"><ul class="list" id="d_list"></ul></div>';
      var draw = function (q) {
        var list = q ? due.filter(function (p) { return App.match(p.name + ' ' + (p.mobile || ''), q); }) : due;
        App.el('d_list').innerHTML = list.slice(0, 200).map(function (p) {
          return '<li data-p="' + p.id + '"><div><div class="nm">' + App.esc(p.name) + '</div>' +
            '<div class="sub">' + App.esc(p.mobile || '') + '</div></div>' +
            '<div class="right"><strong>₹' + App.money(p.balance) + '</strong></div></li>';
        }).join('') || '<li class="muted">Nothing found</li>';
        App.el('d_list').querySelectorAll('[data-p]').forEach(function (li) {
          li.addEventListener('click', function () { App.go('party', parseInt(li.dataset.p, 10)); });
        });
      };
      draw('');
      App.el('d_q').addEventListener('input', function () { draw(this.value.trim()); });
    });
  },

  // --- the day's figures ----------------------------------------------------
  /** Worked out on the phone from what it has, and honest about it: these
   *  cover the window that was synced, and the screen says so. A number that
   *  quietly means something narrower than its label is worse than no
   *  number. */
  scr_reports: function () {
    App.chrome('Reports', true);
    Promise.all([DB.all('ssales'), DB.all('spayments'), DB.all('docs'), DB.all('parties'), Sync.status()])
      .then(function (a) {
        var ss = a[0], sp = a[1], docs = a[2], parties = a[3], st = a[4];
        var today = App.today();
        var d = new Date(); d.setDate(d.getDate() - 6);
        var week = d.toISOString().slice(0, 10);
        var month = today.slice(0, 8) + '01';

        // this phone's unsent bills are real sales; leaving them out would
        // under-report the very day the owner is looking at
        var unsent = docs.filter(function (x) { return x.kind === 'sale' && !x.synced; })
                         .map(function (x) { return { sale_date: x.date, total: x.total, paid: x.paid, is_cancelled: 0 }; });
        var all = ss.filter(function (s) { return s.is_cancelled != 1; }).concat(unsent);

        var sum = function (from) {
          var r = all.filter(function (s) { return (s.sale_date || '') >= from; });
          return { n: r.length, total: r.reduce(function (s, x) { return s + (parseFloat(x.total) || 0); }, 0) };
        };
        var cashIn = function (from) {
          var a1 = sp.filter(function (p) { return p.direction === 'in' && (p.pay_date || '') >= from; })
                     .reduce(function (s, p) { return s + (parseFloat(p.amount) || 0); }, 0);
          var a2 = docs.filter(function (x) { return !x.synced && (x.date || '') >= from; })
                       .reduce(function (s, x) { return s + (x.kind === 'payment' ? (x.amount || 0) : (x.paid || 0)); }, 0);
          return a1 + a2;
        };
        var outstanding = parties.reduce(function (s, p) {
          var b = parseFloat(p.balance) || 0; return s + (b > 0 ? b : 0);
        }, 0);
        var payable = parties.reduce(function (s, p) {
          var b = parseFloat(p.balance) || 0; return s + (b < 0 ? -b : 0);
        }, 0);

        var block = function (label, from) {
          var s = sum(from);
          return '<div class="stat"><span>' + label + '</span><strong>₹' + App.money(s.total) +
                 ' <span class="muted" style="font-weight:400">· ' + s.n + ' bills</span></strong></div>';
        };
        App.el('main').innerHTML =
          '<div class="card"><div class="muted">Today\'s sales</div>' +
          '<div class="big">₹' + App.money(sum(today).total) + '</div>' +
          '<div class="stat"><span>Received today</span><strong>₹' + App.money(cashIn(today)) + '</strong></div></div>' +
          '<div class="card"><h3>Sales</h3>' +
          block('Today', today) + block('Last 7 days', week) + block('This month', month) + '</div>' +
          '<div class="card"><h3>Money owed</h3>' +
          '<div class="stat"><span>To receive</span><strong style="color:var(--bad)">₹' + App.money(outstanding) + '</strong></div>' +
          '<div class="stat"><span>To pay</span><strong>₹' + App.money(payable) + '</strong></div>' +
          '<div class="stat"><span>Received this week</span><strong>₹' + App.money(cashIn(week)) + '</strong></div>' +
          '<button class="btn sec" style="margin-top:10px" id="r_dues">See who owes what</button></div>' +
          '<p class="center muted" style="font-size:13px">These figures come from the data on this phone' +
          (st.last_pull ? ' (last synced ' + App.ago(st.last_pull) + ')' : '') +
          '.<br>Full-year reports open from "Everything" — those need a network.</p>';
        App.el('r_dues').addEventListener('click', function () { App.go('dues'); });
      });
  },

  // --- what is on the shelf -------------------------------------------------
  scr_stock: function () {
    App.chrome('Stock', true);
    App.el('main').innerHTML =
      '<div class="card"><div class="pick">' +
      '<input type="text" id="k_q" placeholder="Type an item name" autocomplete="off">' +
      '<div class="res" id="k_res"></div></div></div><div id="k_out"></div>';
    App.wirePick('k_q', 'k_res', function (q) { return App.findItems(q); }, function (it) {
      App.el('k_q').value = it.name;
      Promise.all([DB.all('stock'), DB.by('serials', 'item_id', it.id), DB.meta('locations', [])])
        .then(function (a) {
          var rows = a[0].filter(function (s) { return s.item_id == it.id; });
          var locs = {}; (a[2] || []).forEach(function (l) { locs[l.id] = l.name; });
          App.el('k_out').innerHTML =
            '<div class="card"><div class="muted">' + App.esc(it.name) + '</div>' +
            '<div class="big">' + (parseFloat(it.stock) || 0) + '</div>' +
            '<div class="muted">in stock · selling price ₹' + App.money(it.selling_price) + '</div></div>' +
            '<div class="card"><h3>Where it is</h3>' + (rows.length
              ? '<ul class="list">' + rows.map(function (s) {
                  return '<li><div class="nm">' + App.esc(locs[s.location_id] || ('Location ' + s.location_id)) + '</div>' +
                    '<div class="right"><strong>' + (parseFloat(s.qty) || 0) + '</strong></div></li>';
                }).join('') + '</ul>'
              : '<p class="muted">No stock anywhere.</p>') + '</div>' +
            (a[1].length
              ? '<div class="card"><h3>Serial numbers (' + a[1].length + ')</h3><ul class="list">' +
                a[1].slice(0, 200).map(function (s) {
                  return '<li><div class="nm" style="font-family:monospace">' + App.esc(s.serial_no) + '</div>' +
                    '<div class="right sub">' + App.esc(locs[s.location_id] || '') + '</div></li>';
                }).join('') + '</ul></div>'
              : '');
        });
    });
  },

  // --- every screen the shop has -------------------------------------------
  scr_all: function () {
    var self = this;
    App.chrome('Everything', true);
    Promise.all([DB.meta('menu', null), Sync.status()]).then(function (a) {
      var groups = a[0], st = a[1];
      if (!groups) {
        App.el('main').innerHTML = '<div class="card"><p class="muted">The list has not been downloaded yet. Sync once with a network.</p>' +
          '<button class="btn" id="a_sync">⟳ Sync</button></div>';
        App.el('a_sync').addEventListener('click', function () { App.syncNow(true); });
        return;
      }
      App.el('main').innerHTML =
        '<div class="card"><input type="search" id="a_q" placeholder="Search a function (repair, cheque, report…)"></div>' +
        (st.online ? '' : '<div class="card"><p class="muted" style="margin:0">📴 No network. The 🟢 ones below still work; the rest need a connection.</p></div>') +
        '<div id="a_out"></div>' +
        '<div class="card"><button class="btn ghost" id="a_sync2">⟳ Sync &amp; settings</button></div>';

      var draw = function (q) {
        var out = '';
        groups.forEach(function (g) {
          var items = q ? g.items.filter(function (it) { return App.match(it.label + ' ' + g.label + ' ' + it.href, q); }) : g.items;
          if (!items.length) return;
          out += '<div class="card"><h3>' + App.esc(g.label) + '</h3><ul class="list">' +
            items.map(function (it) {
              return '<li data-h="' + App.esc(it.href) + '" data-n="' + App.esc(it.native || '') + '">' +
                '<div><div class="nm">' + (it.native ? '🟢 ' : '') + App.esc(it.label) + '</div>' +
                '<div class="sub">' + (it.native ? 'Works offline' : 'Opens the website') + '</div></div>' +
                '<div class="right muted">›</div></li>';
            }).join('') + '</ul></div>';
        });
        App.el('a_out').innerHTML = out || '<div class="card"><p class="muted">Nothing found.</p></div>';
        App.el('a_out').querySelectorAll('[data-h]').forEach(function (li) {
          li.addEventListener('click', function () {
            if (li.dataset.n) { App.go(li.dataset.n); return; }
            self.openWeb(li.dataset.h);
          });
        });
      };
      draw('');
      App.el('a_q').addEventListener('input', function () { draw(this.value.trim()); });
      App.el('a_sync2').addEventListener('click', function () { App.go('sync'); });
    });
  },

  /** Open a website screen inside the app, already logged in. */
  openWeb: function (href) {
    if (!navigator.onLine) { App.toast('This one needs a network', true); return; }
    App.toast('Opening…');
    Sync.webLink(href).then(function (url) {
      window.location.href = url;
    }).catch(function (e) {
      App.toast((e && e.message) || 'Could not open it', true);
      if (e && /log in again/.test(e.message || '')) App.go('login');
    });
  }
};

// hang the screens off App so go('party') / go('all') just work
['party', 'dues', 'reports', 'stock', 'all'].forEach(function (n) {
  App['scr_' + n] = function (arg) { return More['scr_' + n].call(More, arg); };
});
