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
  /** Hand the message to WhatsApp.
   *
   *  whatsapp:// and not wa.me on purpose: the wa.me link is a web page, so
   *  with no signal it is a browser error. The app link opens WhatsApp
   *  itself, which takes the message and sends it when the phone next has a
   *  network - exactly what a shop wants after billing somebody at a site
   *  visit with no coverage. */
  wa: function (mobile, text) {
    var n = String(mobile || '').replace(/[^0-9]/g, '');
    if (n.length === 10) n = '91' + n;            // a bare Indian number
    if (!n) { App.toast('આ ગ્રાહકનો મોબાઇલ નંબર નથી', true); return; }
    var url = 'whatsapp://send?phone=' + n + '&text=' + encodeURIComponent(text);
    try { window.location.href = url; }
    catch (e) { window.open('https://wa.me/' + n + '?text=' + encodeURIComponent(text), '_blank'); }
  },

  billText: function (d, shop) {
    var lines = (d.lines || []).map(function (l) {
      return '• ' + l.name + '  ' + l.qty + ' × ₹' + App.money(l.price) + ' = ₹' + App.money(l.qty * l.price);
    }).join('\n');
    var due = (d.total || 0) - (d.paid || 0);
    return (shop || 'AK Computer') + '\n' +
      'બિલ ' + (d.invoice_no || '(નંબર સિંક પછી)') + ' · ' + (d.date || '') + '\n\n' +
      lines + '\n\n' +
      'કુલ: ₹' + App.money(d.total) + '\n' +
      'મળ્યા: ₹' + App.money(d.paid) +
      (due > 0.009 ? '\nબાકી: ₹' + App.money(due) : '') +
      '\n\nઆભાર 🙏';
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
        rows.push({ date: s.sale_date, what: 'બિલ ' + (s.invoice_no || ''), debit: parseFloat(s.total) || 0,
                    credit: 0, id: 'S' + s.id });
      });
      a[1].forEach(function (p) {
        var amt = parseFloat(p.amount) || 0;
        rows.push({ date: p.pay_date, what: (p.direction === 'out' ? 'ચૂકવ્યા' : 'જમા') + (p.mode ? ' (' + p.mode + ')' : ''),
                    debit: p.direction === 'out' ? amt : 0, credit: p.direction === 'out' ? 0 : amt, id: 'P' + p.id });
      });
      // not yet sent - shown, and marked, so nobody is surprised later
      a[2].forEach(function (d) {
        if (d.synced || d.party_id != partyId) return;
        if (d.kind === 'sale') rows.push({ date: d.date, what: 'બિલ (મોકલવાનું બાકી)', debit: d.total || 0, credit: 0, id: d.uuid, pending: true });
        else rows.push({ date: d.date, what: 'જમા (મોકલવાનું બાકી)', debit: 0, credit: d.amount || 0, id: d.uuid, pending: true });
      });
      rows.sort(function (x, y) { return (y.date || '').localeCompare(x.date || ''); });
      return rows;
    });
  },

  scr_party: function (partyId) {
    var self = this;
    App.chrome('પાર્ટીનો હિસાબ', true);
    Promise.all([DB.get('parties', partyId), this.partyRows(partyId), DB.meta('shop', 'AK Computer')])
      .then(function (a) {
        var p = a[0], rows = a[1], shop = a[2];
        if (!p) { App.el('main').innerHTML = '<div class="card"><p class="muted">આ પાર્ટી ફોનમાં નથી. એક વાર સિંક કરો.</p></div>'; return; }
        var bal = parseFloat(p.balance) || 0;
        App.el('main').innerHTML =
          '<div class="card">' +
          '<div class="muted">' + App.esc(p.name) + (p.mobile ? ' · ' + App.esc(p.mobile) : '') + '</div>' +
          '<div class="big" style="color:' + (bal > 0.009 ? 'var(--bad)' : 'var(--ok)') + '">₹' + App.money(Math.abs(bal)) + '</div>' +
          '<div class="muted">' + (bal > 0.009 ? 'બાકી લેવાના' : (bal < -0.009 ? 'એડવાન્સ જમા' : 'હિસાબ ચોખ્ખો')) + '</div>' +
          '</div>' +
          '<div class="row" style="margin-bottom:10px">' +
          '<button class="btn ok" id="p_collect">₹ પૈસા લીધા</button>' +
          '<button class="btn sec" id="p_bill">🧾 બિલ</button>' +
          '</div>' +
          (p.mobile ? '<div class="row" style="margin-bottom:10px">' +
            '<button class="btn sec" id="p_remind">📩 ઉઘરાણી યાદ</button>' +
            '<button class="btn sec" id="p_stmt">📄 હિસાબ મોકલો</button></div>' : '') +
          '<div class="card"><h3>છેલ્લો હિસાબ</h3>' + (rows.length
            ? '<ul class="list">' + rows.slice(0, 100).map(function (r) {
                return '<li><div><div class="nm">' + App.esc(r.what) + (r.pending ? ' <span class="pill wait">બાકી</span>' : '') + '</div>' +
                  '<div class="sub">' + App.esc(r.date || '') + '</div></div><div class="right">' +
                  (r.debit ? '<strong>₹' + App.money(r.debit) + '</strong>' : '<strong style="color:var(--ok)">− ₹' + App.money(r.credit) + '</strong>') +
                  '</div></li>';
              }).join('') + '</ul>'
            : '<p class="muted">આ ગાળામાં કોઈ એન્ટ્રી નથી.</p>') +
          '<p class="muted" style="font-size:12px;margin-bottom:0">છેલ્લે સિંક થયેલો ગાળો બતાવે છે.</p></div>';

        App.el('p_collect').addEventListener('click', function () { App.go('collect', p.id); });
        App.el('p_bill').addEventListener('click', function () {
          App.bill = null; App.go('newbill'); setTimeout(function () {
            var i = App.el('b_cust'); if (i) { i.value = p.name; App.bill.party = p; App.el('b_mob').value = p.mobile || ''; }
          }, 60);
        });
        if (App.el('p_remind')) {
          App.el('p_remind').addEventListener('click', function () {
            self.wa(p.mobile, shop + '\n\nનમસ્તે ' + p.name + ',\nઆપના ₹' + App.money(Math.abs(bal)) +
              ' બાકી છે. અનુકૂળતાએ ચૂકવી આપશો.\n\nઆભાર 🙏');
          });
          App.el('p_stmt').addEventListener('click', function () {
            var txt = shop + '\nહિસાબ — ' + p.name + '\n\n' +
              rows.slice(0, 25).map(function (r) {
                return r.date + '  ' + r.what + '  ' + (r.debit ? '₹' + App.money(r.debit) : '− ₹' + App.money(r.credit));
              }).join('\n') +
              '\n\nબાકી: ₹' + App.money(Math.abs(bal));
            self.wa(p.mobile, txt);
          });
        }
      });
  },

  // --- who owes what --------------------------------------------------------
  scr_dues: function () {
    var self = this;
    App.chrome('બાકી ઉઘરાણી', true);
    DB.all('parties').then(function (rows) {
      var due = rows.filter(function (p) { return (parseFloat(p.balance) || 0) > 0.009; })
                    .sort(function (a, b) { return (parseFloat(b.balance) || 0) - (parseFloat(a.balance) || 0); });
      var tot = due.reduce(function (s, p) { return s + (parseFloat(p.balance) || 0); }, 0);
      App.el('main').innerHTML =
        '<div class="card"><div class="muted">કુલ બાકી લેવાના</div>' +
        '<div class="big">₹' + App.money(tot) + '</div>' +
        '<div class="muted">' + due.length + ' પાર્ટી</div></div>' +
        '<div class="card"><input type="search" id="d_q" placeholder="પાર્ટી શોધો"><ul class="list" id="d_list"></ul></div>';
      var draw = function (q) {
        var list = q ? due.filter(function (p) { return App.match(p.name + ' ' + (p.mobile || ''), q); }) : due;
        App.el('d_list').innerHTML = list.slice(0, 200).map(function (p) {
          return '<li data-p="' + p.id + '"><div><div class="nm">' + App.esc(p.name) + '</div>' +
            '<div class="sub">' + App.esc(p.mobile || '') + '</div></div>' +
            '<div class="right"><strong>₹' + App.money(p.balance) + '</strong></div></li>';
        }).join('') || '<li class="muted">કોઈ મળ્યું નહીં</li>';
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
    App.chrome('રિપોર્ટ', true);
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
                 ' <span class="muted" style="font-weight:400">· ' + s.n + ' બિલ</span></strong></div>';
        };
        App.el('main').innerHTML =
          '<div class="card"><div class="muted">આજનું વેચાણ</div>' +
          '<div class="big">₹' + App.money(sum(today).total) + '</div>' +
          '<div class="stat"><span>આજે પૈસા આવ્યા</span><strong>₹' + App.money(cashIn(today)) + '</strong></div></div>' +
          '<div class="card"><h3>વેચાણ</h3>' +
          block('આજે', today) + block('છેલ્લા 7 દિવસ', week) + block('આ મહિનો', month) + '</div>' +
          '<div class="card"><h3>ઉઘરાણી</h3>' +
          '<div class="stat"><span>લેવાના બાકી</span><strong style="color:var(--bad)">₹' + App.money(outstanding) + '</strong></div>' +
          '<div class="stat"><span>આપવાના બાકી</span><strong>₹' + App.money(payable) + '</strong></div>' +
          '<div class="stat"><span>આ અઠવાડિયે આવ્યા</span><strong>₹' + App.money(cashIn(week)) + '</strong></div>' +
          '<button class="btn sec" style="margin-top:10px" id="r_dues">બાકીની યાદી જુઓ</button></div>' +
          '<p class="center muted" style="font-size:13px">આ આંકડા ફોનમાં ઊતરેલા ડેટામાંથી છે' +
          (st.last_pull ? ' (છેલ્લે સિંક ' + App.ago(st.last_pull) + ')' : '') +
          '.<br>આખા વરસના રિપોર્ટ "બધું" માંથી ખૂલે છે — એને નેટ જોઈએ.</p>';
        App.el('r_dues').addEventListener('click', function () { App.go('dues'); });
      });
  },

  // --- what is on the shelf -------------------------------------------------
  scr_stock: function () {
    App.chrome('સ્ટોક', true);
    App.el('main').innerHTML =
      '<div class="card"><div class="pick">' +
      '<input type="text" id="k_q" placeholder="આઇટમનું નામ ટાઇપ કરો" autocomplete="off">' +
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
            '<div class="muted">કુલ નંગ · વેચાણ ભાવ ₹' + App.money(it.selling_price) + '</div></div>' +
            '<div class="card"><h3>ક્યાં કેટલો</h3>' + (rows.length
              ? '<ul class="list">' + rows.map(function (s) {
                  return '<li><div class="nm">' + App.esc(locs[s.location_id] || ('જગ્યા ' + s.location_id)) + '</div>' +
                    '<div class="right"><strong>' + (parseFloat(s.qty) || 0) + '</strong></div></li>';
                }).join('') + '</ul>'
              : '<p class="muted">કોઈ જગ્યાએ સ્ટોક નથી.</p>') + '</div>' +
            (a[1].length
              ? '<div class="card"><h3>સિરિયલ નંબર (' + a[1].length + ')</h3><ul class="list">' +
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
    App.chrome('બધું', true);
    Promise.all([DB.meta('menu', null), Sync.status()]).then(function (a) {
      var groups = a[0], st = a[1];
      if (!groups) {
        App.el('main').innerHTML = '<div class="card"><p class="muted">યાદી હજી ઊતરી નથી. એક વાર નેટ સાથે સિંક કરો.</p>' +
          '<button class="btn" id="a_sync">⟳ સિંક કરો</button></div>';
        App.el('a_sync').addEventListener('click', function () { App.syncNow(true); });
        return;
      }
      App.el('main').innerHTML =
        '<div class="card"><input type="search" id="a_q" placeholder="ફંક્શન શોધો (દા.ત. રિપેર, ચેક, રિપોર્ટ)"></div>' +
        (st.online ? '' : '<div class="card"><p class="muted" style="margin:0">📴 નેટ નથી. નીચે 🟢 વાળાં ફંક્શન અત્યારે પણ ચાલશે; બાકીનાં નેટ આવે ત્યારે.</p></div>') +
        '<div id="a_out"></div>' +
        '<div class="card"><button class="btn ghost" id="a_sync2">⟳ સિંક / લોગઆઉટ</button></div>';

      var draw = function (q) {
        var out = '';
        groups.forEach(function (g) {
          var items = q ? g.items.filter(function (it) { return App.match(it.label + ' ' + g.label + ' ' + it.href, q); }) : g.items;
          if (!items.length) return;
          out += '<div class="card"><h3>' + App.esc(g.label) + '</h3><ul class="list">' +
            items.map(function (it) {
              return '<li data-h="' + App.esc(it.href) + '" data-n="' + App.esc(it.native || '') + '">' +
                '<div><div class="nm">' + (it.native ? '🟢 ' : '') + App.esc(it.label) + '</div>' +
                '<div class="sub">' + (it.native ? 'નેટ વગર ચાલે' : 'વેબસાઈટ પર ખૂલશે') + '</div></div>' +
                '<div class="right muted">›</div></li>';
            }).join('') + '</ul></div>';
        });
        App.el('a_out').innerHTML = out || '<div class="card"><p class="muted">કંઈ મળ્યું નહીં.</p></div>';
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
    if (!navigator.onLine) { App.toast('આ ફંક્શન માટે નેટ જોઈએ', true); return; }
    App.toast('ખોલી રહ્યા છીએ…');
    Sync.webLink(href).then(function (url) {
      window.location.href = url;
    }).catch(function (e) {
      App.toast((e && e.message) || 'ખૂલ્યું નહીં', true);
      if (e && /લોગિન/.test(e.message || '')) App.go('login');
    });
  }
};

// hang the screens off App so go('party') / go('all') just work
['party', 'dues', 'reports', 'stock', 'all'].forEach(function (n) {
  App['scr_' + n] = function (arg) { return More['scr_' + n].call(More, arg); };
});
