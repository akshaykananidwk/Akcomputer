// ---------------------------------------------------------------------------
// THE CUSTOMER'S SIDE OF THE APP.
//
// The same apk, handed to a customer or a dealer instead of to staff. No
// login needed to look: products, prices, what is on the shelf - the same
// things the website shows anybody who visits it.
//
// A dealer who signs in sees THEIR price instead of the ordinary one, which
// is the whole reason a dealer would install this rather than bookmark the
// website.
//
// Offline, same as everywhere else in this app: the catalogue is kept on the
// phone, so a customer standing in a basement with no bars can still look up
// what a camera costs. An order placed offline waits in the outbox and goes
// when there is signal - and cannot be placed twice, because it carries the
// id the phone gave it.
// ---------------------------------------------------------------------------
var Shop = {
  cart: {},

  /** Enter customer mode. Nothing is thrown away: a staff member who taps
   *  "Browse products" is still logged in and gets back with one Back. */
  enter: function () {
    var self = this;
    // whatever address is on the login screen wins - a shop that has moved
    // host typed it there, and the customer side must go to the same place
    var typed = App.el('l_srv') ? App.el('l_srv').value.trim() : '';
    return DB.meta('server', '').then(function (srv) {
      var use = typed || srv || App.SERVER;
      if (use !== srv) return DB.setMeta('server', use.replace(/\/+$/, ''));
    }).then(function () {
      return DB.meta('cart', null);
    }).then(function (c) {
      self.cart = c || {};
      return DB.setMeta('mode', 'shop').then(function () { App.go('shop'); });
    });
  },

  saveCart: function () { return DB.setMeta('cart', this.cart); },

  count: function () {
    var n = 0, c = this.cart;
    for (var k in c) if (c.hasOwnProperty(k)) n += c[k].qty;
    return n;
  },

  total: function () {
    var t = 0, c = this.cart;
    for (var k in c) if (c.hasOwnProperty(k)) t += c[k].qty * c[k].price;
    return t;
  },

  /** Refresh the catalogue. Never blocks a screen: what is already on the
   *  phone is drawn first and this quietly replaces it when it arrives. */
  pull: function () {
    return Promise.all([DB.meta('server', App.SERVER), DB.meta('wtoken', '')]).then(function (a) {
      var hdr = a[1] ? { Authorization: 'Bearer ' + a[1] } : {};
      return Sync.fetchJson(a[0] + '/api.php?r=catalog', { headers: hdr }, 25000);
    }).then(function (res) {
      if (!res.ok || !res.data || !res.data.items) throw new Error('Could not load the products');
      return Promise.all([
        DB.replaceAll('catalog', res.data.items),
        DB.setMeta('shop', res.data.shop || 'AK Computer'),
        DB.setMeta('dealer', res.data.dealer || null),
        DB.setMeta('shop_wa', res.data.whatsapp || ''),
        DB.setMeta('catalog_at', new Date().toISOString())
      ]).then(function () { return res.data.items.length; });
    });
  },

  // --- the product list -----------------------------------------------------
  scr_shop: function () {
    var self = this;
    App.chrome('Products', true);
    App.el('tabs').hidden = true;
    Promise.all([DB.all('catalog'), DB.meta('dealer', null), DB.meta('catalog_at', '')])
      .then(function (a) {
        var items = a[0], dealer = a[1];
        App.el('main').innerHTML =
          (dealer
            ? '<div class="card"><div class="stat"><span>Dealer</span><strong>' + App.esc(dealer.name) + '</strong></div>' +
              (dealer.discount_pct > 0 ? '<div class="stat"><span>Your discount</span><strong>' + dealer.discount_pct + '%</strong></div>' : '') +
              '</div>'
            : '') +
          '<div class="card"><input type="search" id="sh_q" placeholder="Search products"></div>' +
          '<div id="sh_out"></div>' +
          (items.length ? '' : '<div class="card"><p class="muted">No products on the phone yet.</p>' +
            '<button class="btn" id="sh_load">Load products</button></div>') +
          '<div class="card">' +
          (dealer ? '<button class="btn ghost" id="sh_out2">Dealer sign out</button>'
                  : '<button class="btn sec" id="sh_login">Dealer login (special prices)</button>') +
          '<button class="btn ghost" id="sh_staff" style="margin-top:8px">Shop staff login</button></div>';

        var draw = function (q) {
          var list = q ? items.filter(function (i) { return App.match(i.name + ' ' + (i.category || '') + ' ' + (i.brand || ''), q); }) : items;
          App.el('sh_out').innerHTML = list.length
            ? '<div class="card"><ul class="list">' + list.slice(0, 400).map(function (i) {
                return '<li data-id="' + i.id + '"><div style="flex:1">' +
                  '<div class="nm">' + App.esc(i.name) + '</div>' +
                  '<div class="sub">' + App.esc(i.category || '') + (i.brand ? ' · ' + App.esc(i.brand) : '') + '</div>' +
                  '<div class="sub stk-' + App.esc(i.stock_class) + '">' + App.esc(i.stock_text) + '</div>' +
                  '</div><div class="right"><strong>₹' + App.money(i.price) + '</strong>' +
                  (i.mrp && i.mrp > i.price ? '<br><span class="sub" style="text-decoration:line-through">₹' + App.money(i.mrp) + '</span>' : '') +
                  '<br><button class="btn sec" style="width:auto;padding:6px 12px;margin-top:4px" data-add="' + i.id + '">Add</button>' +
                  '</div></li>';
              }).join('') + '</ul></div>'
            : '<div class="card"><p class="muted">Nothing matched.</p></div>';
          App.el('sh_out').querySelectorAll('[data-add]').forEach(function (btn) {
            btn.addEventListener('click', function (ev) {
              ev.stopPropagation();
              var it = items.filter(function (x) { return x.id == btn.dataset.add; })[0];
              if (!it) return;
              var c = self.cart[it.id] || { item_id: it.id, name: it.name, price: it.price, qty: 0 };
              c.qty += 1; c.price = it.price;
              self.cart[it.id] = c;
              self.saveCart().then(function () { self.bar(); App.toast(it.name + ' added'); });
            });
          });
        };
        draw('');
        App.el('sh_q').addEventListener('input', function () { draw(this.value.trim()); });
        if (App.el('sh_load')) App.el('sh_load').addEventListener('click', function () {
          self.pull().then(function () { App.go('shop'); }).catch(function (e) { App.toast(e.message, true); });
        });
        if (App.el('sh_login')) App.el('sh_login').addEventListener('click', function () { App.go('dealer'); });
        if (App.el('sh_out2')) App.el('sh_out2').addEventListener('click', function () {
          Promise.all([DB.setMeta('wtoken', ''), DB.setMeta('dealer', null)])
            .then(function () { return self.pull(); })
            .then(function () { App.go('shop'); });
        });
        App.el('sh_staff').addEventListener('click', function () {
          DB.setMeta('mode', '').then(function () { App.go('login'); });
        });
        self.bar();

        // and quietly bring the prices up to date behind the screen
        if (navigator.onLine) self.pull().then(function () {
          if (App.screen === 'shop') App.render('shop');
        }).catch(function () { });
      });
  },

  /** The cart bar, pinned above the tabs. Only there when there is
   *  something in it - an empty bar is a permanent reminder of nothing. */
  bar: function () {
    var el = App.el('cartbar');
    if (!el) return;
    var n = this.count();
    if (!n) { el.hidden = true; return; }
    el.hidden = false;
    el.innerHTML = '<span>' + n + ' item' + (n > 1 ? 's' : '') + ' · ₹' + App.money(this.total()) + '</span>' +
                   '<button class="btn ok" style="width:auto;padding:8px 18px">View cart</button>';
    el.onclick = function () { App.go('cart'); };
  },

  // --- the cart -------------------------------------------------------------
  scr_cart: function () {
    var self = this;
    App.chrome('Your order', true);
    App.el('tabs').hidden = true;
    var keys = Object.keys(this.cart);
    if (!keys.length) {
      App.el('main').innerHTML = '<div class="card center"><p class="muted">Your cart is empty.</p>' +
        '<button class="btn" id="ct_back">Browse products</button></div>';
      App.el('ct_back').addEventListener('click', function () { App.go('shop'); });
      this.bar();
      return;
    }
    DB.meta('dealer', null).then(function (dealer) {
      App.el('main').innerHTML =
        '<div class="card"><ul class="list">' + keys.map(function (k) {
          var c = self.cart[k];
          return '<li><div style="flex:1"><div class="nm">' + App.esc(c.name) + '</div>' +
            '<div class="sub">₹' + App.money(c.price) + ' each</div>' +
            '<div class="row" style="margin-top:6px;gap:6px;max-width:170px">' +
            '<button class="btn ghost" data-minus="' + k + '" style="padding:6px">−</button>' +
            '<div class="center" style="align-self:center"><strong>' + c.qty + '</strong></div>' +
            '<button class="btn ghost" data-plus="' + k + '" style="padding:6px">＋</button></div></div>' +
            '<div class="right"><strong>₹' + App.money(c.qty * c.price) + '</strong>' +
            '<br><button class="x" data-del="' + k + '">✕</button></div></li>';
        }).join('') + '</ul>' +
        '<div class="tot grand"><span>Total</span><span>₹' + App.money(self.total()) + '</span></div></div>' +
        '<div class="card"><h3>Where to send it</h3>' +
        '<label>Your name *</label><input type="text" id="ct_name" value="' + App.esc(dealer ? dealer.name : '') + '">' +
        '<label>Mobile number *</label><input type="tel" id="ct_mob" inputmode="tel">' +
        '<label>Address</label><textarea id="ct_addr" rows="2"></textarea>' +
        '<label>Anything else?</label><input type="text" id="ct_note">' +
        '<div class="err" id="ct_err"></div>' +
        '<button class="btn ok" id="ct_go">Place order</button></div>';

      App.el('main').querySelectorAll('[data-plus]').forEach(function (b) {
        b.addEventListener('click', function () { self.cart[b.dataset.plus].qty++; self.saveCart().then(function () { App.render('cart'); }); });
      });
      App.el('main').querySelectorAll('[data-minus]').forEach(function (b) {
        b.addEventListener('click', function () {
          var c = self.cart[b.dataset.minus];
          if (--c.qty <= 0) delete self.cart[b.dataset.minus];
          self.saveCart().then(function () { App.render('cart'); });
        });
      });
      App.el('main').querySelectorAll('[data-del]').forEach(function (b) {
        b.addEventListener('click', function () { delete self.cart[b.dataset.del]; self.saveCart().then(function () { App.render('cart'); }); });
      });
      DB.meta('last_mobile', '').then(function (m) { if (m) App.el('ct_mob').value = m; });
      App.el('ct_go').addEventListener('click', function () { self.placeOrder(); });
      self.bar();
    });
  },

  placeOrder: function () {
    var self = this;
    var err = App.el('ct_err');
    err.textContent = '';
    var name = App.el('ct_name').value.trim();
    var mob = App.el('ct_mob').value.replace(/\D/g, '');
    // every box checked before anything is written, so a refusal leaves
    // nothing half-done
    if (!name) { err.textContent = 'Please enter your name'; App.el('ct_name').focus(); return; }
    if (mob.length < 10) { err.textContent = 'Please enter a 10-digit mobile number'; App.el('ct_mob').focus(); return; }
    if (!Object.keys(this.cart).length) { err.textContent = 'Your cart is empty'; return; }

    var uuid = newUuid();
    var body = {
      client_uuid: uuid, name: name, mobile: mob,
      address: App.el('ct_addr').value.trim(), notes: App.el('ct_note').value.trim(),
      items: Object.keys(this.cart).map(function (k) {
        return { item_id: self.cart[k].item_id, qty: self.cart[k].qty };
      })
    };
    var order = { uuid: uuid, kind: 'order', date: App.today(), created_at: new Date().toISOString(),
                  name: name, mobile: mob, total: this.total(),
                  lines: Object.keys(this.cart).map(function (k) { return self.cart[k]; }), synced: false };

    // saved on the phone first, sent afterwards - exactly like a bill
    DB.put('docs', order)
      .then(function () { return Sync.queue({ uuid: uuid, kind: 'order', body: body, created_at: order.created_at }); })
      .then(function () {
        self.cart = {};
        return Promise.all([self.saveCart(), DB.setMeta('last_mobile', mob)]);
      })
      .then(function () {
        App.go('ordered', uuid);
        if (navigator.onLine) Sync.push(true).then(function () { App.render('ordered', uuid); });
      })
      .catch(function (e) { err.textContent = 'Could not save: ' + ((e && e.message) || ''); });
  },

  scr_ordered: function (uuid) {
    var self = this;
    App.chrome('Order placed', true);
    App.el('tabs').hidden = true;
    Promise.all([DB.get('docs', uuid), DB.meta('shop_wa', '')]).then(function (a) {
      var o = a[0];
      if (!o) { App.go('shop'); return; }
      App.el('main').innerHTML =
        '<div class="card center"><div style="font-size:42px">✅</div>' +
        '<div class="big">₹' + App.money(o.total) + '</div>' +
        '<div class="muted">' + App.esc(o.name) + ' · ' + App.esc(o.mobile) + '</div>' +
        '<div class="muted" style="margin-top:8px">' +
        (o.order_no ? 'Order ' + App.esc(o.order_no) + '<br>The shop has it and will call you.'
                    : '⏳ Waiting for a network. Your order is saved on this phone and will reach the shop by itself.') +
        '</div></div>' +
        '<button class="btn" id="od_more">Browse more products</button>' +
        '<button class="btn sec" id="od_list">My orders</button>';
      App.el('od_more').addEventListener('click', function () { App.go('shop'); });
      App.el('od_list').addEventListener('click', function () { App.go('myorders'); });
      self.bar();
    });
  },

  scr_myorders: function () {
    App.chrome('My orders', true);
    App.el('tabs').hidden = true;
    DB.all('docs').then(function (docs) {
      var rows = docs.filter(function (d) { return d.kind === 'order'; })
                     .sort(function (a, b) { return (b.created_at || '').localeCompare(a.created_at || ''); });
      App.el('main').innerHTML = '<div class="card">' + (rows.length
        ? '<ul class="list">' + rows.map(function (o) {
            return '<li><div><div class="nm">' + App.esc(o.order_no || 'Order') + '</div>' +
              '<div class="sub">' + App.esc(o.date) + ' · ' + o.lines.length + ' item(s)</div></div>' +
              '<div class="right"><strong>₹' + App.money(o.total) + '</strong><br>' +
              (o.synced ? '<span class="pill done">With the shop</span>' : '<span class="pill wait">Sending</span>') +
              '</div></li>';
          }).join('') + '</ul>'
        : '<p class="muted">You have not placed an order yet.</p>') + '</div>' +
        '<button class="btn sec" id="mo_back">Browse products</button>';
      App.el('mo_back').addEventListener('click', function () { App.go('shop'); });
    });
  },

  // --- dealer login ---------------------------------------------------------
  scr_dealer: function () {
    var self = this;
    App.chrome('Dealer login', true);
    App.el('tabs').hidden = true;
    App.el('main').innerHTML =
      '<div class="card">' +
      '<p class="muted" style="margin-top:0">Dealers and electricians: sign in with the mobile number and password the shop gave you, and you will see your own prices.</p>' +
      '<label>Mobile number</label><input type="tel" id="dl_mob" inputmode="tel" autocomplete="username">' +
      '<label>Password</label><input type="password" id="dl_pass" autocomplete="current-password">' +
      '<div class="err" id="dl_err"></div>' +
      '<button class="btn" id="dl_go">Sign in</button></div>';
    App.el('dl_go').addEventListener('click', function () {
      var err = App.el('dl_err');
      err.textContent = '';
      var mob = App.el('dl_mob').value.replace(/\D/g, '');
      var pwd = App.el('dl_pass').value;
      if (mob.length < 10) { err.textContent = 'Enter your 10-digit mobile number'; return; }
      if (!pwd) { err.textContent = 'Enter your password'; return; }
      if (!navigator.onLine) { err.textContent = 'You need a network to sign in'; return; }
      var btn = App.el('dl_go');
      btn.disabled = true; btn.textContent = 'Checking…';
      DB.meta('server', App.SERVER).then(function (srv) {
        return Sync.fetchJson(srv + '/api.php?r=wlogin', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ mobile: mob, password: pwd })
        }, 25000);
      }).then(function (res) {
        btn.disabled = false; btn.textContent = 'Sign in';
        if (!res.ok || !res.data || !res.data.token) {
          err.textContent = (res.data && res.data.error) || 'Could not sign in';
          return;
        }
        return DB.setMeta('wtoken', res.data.token)
          .then(function () { return self.pull(); })
          .then(function () { App.toast('Welcome, ' + res.data.name); App.go('shop'); });
      }).catch(function (e) {
        btn.disabled = false; btn.textContent = 'Sign in';
        err.textContent = (e && e.message) || 'Could not sign in';
      });
    });
  }
};

['shop', 'cart', 'ordered', 'myorders', 'dealer'].forEach(function (n) {
  App['scr_' + n] = function (arg) { return Shop['scr_' + n].call(Shop, arg); };
});
