/* AK Computer online store - cart, wishlist, instant search, menus.
   The cart lives on this phone only (localStorage) so it survives moving
   between pages; the shop re-prices every line from its database when the
   order is placed, so nothing typed or stored here can change a price. */
(function () {
  'use strict';
  var A = window.AKC || {};
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var store = {
    get: function (k, d) { try { var v = localStorage.getItem(k); return v ? JSON.parse(v) : d; } catch (e) { return d; } },
    set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  };
  var money = function (n) { return '₹' + Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ico = function (n, c) { return '<svg class="' + (c || 'ico') + '" aria-hidden="true"><use href="#i-' + n + '"/></svg>'; };
  function toast(msg) { var t = $('[data-toast]'); if (!t) return; t.textContent = msg; t.classList.add('show'); clearTimeout(t._h); t._h = setTimeout(function () { t.classList.remove('show'); }, 1800); }

  // ---------- cart ----------
  var cart = store.get('akcCart', {});
  function cartCount() { var n = 0; for (var k in cart) n += cart[k].q; return n; }
  function saveCart() { store.set('akcCart', cart); paintCart(); }
  function setQty(id, q, info) {
    id = String(id);
    if (q <= 0) { delete cart[id]; } else { cart[id] = cart[id] || info || { n: '', p: 0, f: '', m: '' }; cart[id].q = Math.min(q, 999); }
    saveCart();
  }
  function cardInfo(el) { return { n: el.dataset.n, p: parseFloat(el.dataset.p) || 0, f: el.dataset.f || '', m: el.dataset.m || '' }; }
  function paintCart() {
    var n = cartCount();
    $$('[data-cart-n]').forEach(function (b) { b.textContent = n; b.dataset.n = n; });
    $$('[data-card]').forEach(function (c) {
      var it = cart[c.dataset.id];
      c.classList.toggle('incart', !!it);
      var qn = $('[data-qn]', c); if (qn && it) qn.textContent = it.q;
    });
    var box = $('[data-cart-lines]'); if (!box) return;
    var ids = Object.keys(cart), tot = 0, h = '';
    ids.forEach(function (id) {
      var it = cart[id]; tot += it.q * it.p;
      h += '<div class="cl">' + (it.f ? '<img src="' + esc(it.f) + '" alt="" loading="lazy">' : '<span class="noimg">' + ico('box') + '</span>') +
        '<div><div class="n">' + esc(it.n) + '</div><div class="p">' + money(it.p) + ' × ' + it.q + ' = <b>' + money(it.p * it.q) + '</b></div></div>' +
        '<span class="qty"><button type="button" data-cq="-1" data-cid="' + esc(id) + '" aria-label="One less">−</button><span>' + it.q + '</span><button type="button" data-cq="1" data-cid="' + esc(id) + '" aria-label="One more">+</button></span></div>';
    });
    box.innerHTML = h;
    var empty = !ids.length;
    $('[data-cart-empty]').hidden = !empty;
    $('[data-checkout]').hidden = empty;
    $('[data-cart-total]').textContent = money(tot);
    var cj = {}; ids.forEach(function (id) { cj[id] = cart[id].q; });
    $('[data-cart-json]').value = JSON.stringify(cj);
  }

  // ---------- wishlist ----------
  var wish = (store.get('akcWish', []) || []).map(String);
  function paintWish() {
    $$('[data-wish]').forEach(function (b) { var on = wish.indexOf(String(b.dataset.wish)) > -1; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on); });
    $$('[data-wish-n]').forEach(function (b) { b.textContent = wish.length; b.dataset.n = wish.length; });
  }
  function toggleWish(id) {
    id = String(id); var i = wish.indexOf(id);
    if (i > -1) { wish.splice(i, 1); toast('Removed from wishlist'); } else { wish.push(id); toast('Saved to wishlist'); }
    store.set('akcWish', wish); paintWish();
  }

  // ---------- sheets & drawer ----------
  function open(id) {
    var el = document.getElementById(id); if (!el) return;
    el.classList.add('open'); var bg = el.nextElementSibling; if (bg && bg.classList.contains('sheet-bg')) bg.classList.add('show');
    document.body.style.overflow = 'hidden';
    if (id === 'cart') paintCart();
  }
  function closeAll() {
    $$('.sheet.open, .drawer.open').forEach(function (s) { s.classList.remove('open'); });
    $$('.sheet-bg.show').forEach(function (b) { b.classList.remove('show'); });
    document.body.style.overflow = '';
  }
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(); });

  // ---------- one click handler for the whole page ----------
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-add],[data-q],[data-cq],[data-wish],[data-open],[data-close],[data-theme-toggle],[data-pin],[data-wa-order],[data-share],[data-buy]');
    if (!t) return;
    var card = t.closest('[data-card]');
    if (t.hasAttribute('data-add') && card) { setQty(card.dataset.id, (cart[card.dataset.id] ? cart[card.dataset.id].q : 0) + 1, cardInfo(card)); toast('Added to cart'); }
    else if (t.hasAttribute('data-q') && card) { setQty(card.dataset.id, (cart[card.dataset.id] ? cart[card.dataset.id].q : 0) + parseInt(t.dataset.q, 10)); }
    else if (t.hasAttribute('data-cq')) { var c = cart[t.dataset.cid]; if (c) setQty(t.dataset.cid, c.q + parseInt(t.dataset.cq, 10)); }
    else if (t.hasAttribute('data-wish')) { e.preventDefault(); toggleWish(t.dataset.wish); }
    else if (t.hasAttribute('data-open')) { e.preventDefault(); open(t.dataset.open); }
    else if (t.hasAttribute('data-close')) { closeAll(); }
    else if (t.hasAttribute('data-theme-toggle')) {
      var dark = document.documentElement.getAttribute('data-theme') === 'dark' || (!document.documentElement.getAttribute('data-theme') && matchMedia('(prefers-color-scheme: dark)').matches);
      var nx = dark ? 'light' : 'dark'; document.documentElement.setAttribute('data-theme', nx); try { localStorage.setItem('akcTheme', nx); } catch (x) {}
    }
    else if (t.hasAttribute('data-pin')) { pin(t); }
    else if (t.hasAttribute('data-wa-order')) { waOrder(); }
    else if (t.hasAttribute('data-share')) { share(t); }
    else if (t.hasAttribute('data-buy')) { buyNow(t); }
  });

  // ---------- product page: qty selector, buy now, share, recently viewed ----------
  var pq = 1;
  $$('[data-pq]').forEach(function (b) { b.addEventListener('click', function () { pq = Math.max(1, pq + parseInt(b.dataset.pq, 10)); $$('[data-pqn]').forEach(function (s) { s.textContent = pq; }); }); });
  $$('[data-padd]').forEach(function (b) {
    b.addEventListener('click', function () {
      var c = b.closest('[data-card]'); var cur = cart[c.dataset.id] ? cart[c.dataset.id].q : 0;
      setQty(c.dataset.id, cur + pq, cardInfo(c)); toast(pq + ' added to cart');
    });
  });
  function buyNow(t) {
    var c = t.closest('[data-card]'); if (!c) return;
    if (!cart[c.dataset.id]) setQty(c.dataset.id, pq, cardInfo(c));
    open('cart');
  }
  function share(t) {
    var d = { title: document.title, url: t.dataset.share || location.href };
    if (navigator.share) { navigator.share(d).catch(function () {}); return; }
    try { navigator.clipboard.writeText(d.url); toast('Link copied'); } catch (x) { prompt('Copy this link', d.url); }
  }
  var viewed = $('[data-viewed]');
  if (viewed) { var rv = (store.get('akcSeen', []) || []).filter(function (x) { return String(x) !== viewed.dataset.viewed; }); rv.unshift(viewed.dataset.viewed); store.set('akcSeen', rv.slice(0, 16)); }
  var sb = $('.sticky-buy'), anchor = $('[data-buybar]');
  if (sb && anchor && 'IntersectionObserver' in window) new IntersectionObserver(function (en) { sb.classList.toggle('show', !en[0].isIntersecting && en[0].boundingClientRect.top < 0); }).observe(anchor);

  // ---------- checkout ----------
  var form = $('[data-checkout]');
  if (form) {
    form.addEventListener('change', function () {
      var pick = form.querySelector('[name=fulfil]:checked').value === 'Shop pickup';
      $('[data-addr]').hidden = pick; $('[data-pickup]').hidden = !pick;
    });
    form.addEventListener('submit', function () { paintCart(); store.set('akcCartSent', cart); });
  }
  function waOrder() {
    if (!A.wa || !form) return;
    var f = new FormData(form), lines = [], tot = 0;
    Object.keys(cart).forEach(function (id) { var it = cart[id]; tot += it.q * it.p; lines.push('• ' + it.n + (it.m ? ' (' + it.m + ')' : '') + ' × ' + it.q + ' = ' + money(it.q * it.p)); });
    var where = f.get('fulfil') === 'Shop pickup' ? 'Shop pickup' : ['Delivery to', f.get('address'), f.get('area'), f.get('pincode')].filter(Boolean).join(' ');
    var msg = 'Hello ' + A.app + '! I would like to order:\n\n' + lines.join('\n') + '\n\nTotal: ' + money(tot) +
      '\nName: ' + (f.get('customer_name') || '') + '\nMobile: ' + (f.get('mobile') || '') + '\n' + where + '\nPayment: ' + f.get('pay_method') + (f.get('order_notes') ? '\nNote: ' + f.get('order_notes') : '');
    window.open('https://wa.me/' + A.wa + '?text=' + encodeURIComponent(msg), '_blank', 'noopener');
  }
  if (document.body.dataset.ordered) { cart = {}; store.set('akcCart', {}); }

  // ---------- admin: pin a product to the front ----------
  function pin(b) {
    var fd = new FormData(); fd.append('do', 'pin'); fd.append('id', b.dataset.pin); fd.append('csrf', A.csrf);
    fetch(A.base + '/catalog.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) { toast(j.error || 'Could not pin'); return; }
      $$('[data-pin="' + b.dataset.pin + '"]').forEach(function (x) { x.classList.toggle('on', !!j.on); x.textContent = j.on ? '📌 Pinned' : '📌 Pin'; });
      toast(j.on ? 'Pinned — shows first on the store' : 'Unpinned');
    }).catch(function () { toast('Could not pin'); });
  }

  // ---------- instant search (works offline from the page's own index) ----------
  var idx = window.AKC_IDX || null, idxLoading = false;
  function loadIdx(cb) {
    if (idx) return cb();
    if (idxLoading) return; idxLoading = true;
    fetch(A.idx, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) { idx = j; cb(); }).catch(function () { idxLoading = false; });
  }
  function lev1(a, b) { // true when a and b differ by at most one letter
    if (Math.abs(a.length - b.length) > 1) return false;
    var i = 0, j = 0, d = 0;
    while (i < a.length && j < b.length) { if (a[i] === b[j]) { i++; j++; continue; } if (++d > 1) return false; if (a.length > b.length) i++; else if (b.length > a.length) j++; else { i++; j++; } }
    return d + (a.length - i) + (b.length - j) <= 1;
  }
  function matches(q) {
    var words = q.toLowerCase().split(/\s+/).filter(Boolean).slice(0, 8); if (!words.length || !idx) return [];
    var syn = A.syn || {}, out = [];
    idx.forEach(function (p) {
      var hay = (p.n + ' ' + p.b + ' ' + p.m + ' ' + p.k + ' ' + p.c + ' ' + p.g).toLowerCase(), hw = null, score = 0;
      for (var i = 0; i < words.length; i++) {
        var w = words[i], alts = [w].concat(syn[w] || []), ok = false;
        for (var a = 0; a < alts.length; a++) if (hay.indexOf(alts[a]) > -1) { ok = true; score += alts[a] === w ? 2 : 1; break; }
        if (!ok && w.length >= 4) { hw = hw || hay.split(/[^\p{L}\p{N}]+/u); for (var h = 0; h < hw.length; h++) if (lev1(w, hw[h])) { ok = true; break; } }
        if (!ok) return;
      }
      if (p.n.toLowerCase().indexOf(words[0]) === 0) score += 3;
      if (p.s === 'in' || p.s === 'low') score += 1;
      out.push([score, p]);
    });
    out.sort(function (x, y) { return y[0] - x[0]; });
    return out.map(function (x) { return x[1]; });
  }
  $$('[data-suggest]').forEach(function (inp) {
    var box = inp.closest('.srch').querySelector('[data-sugg]'), sel = -1;
    function render() {
      var q = inp.value.trim();
      if (q.length < 2) { box.classList.remove('show'); return; }
      loadIdx(function () {
        var m = matches(q), h = '';
        m.slice(0, 7).forEach(function (p) {
          h += '<a href="' + esc(p.u) + '" role="option">' + (p.f ? '<img class="th" src="' + esc(p.f) + '" alt="" loading="lazy">' : '<span class="th noimg">' + ico('box') + '</span>') +
            '<span class="sn">' + esc(p.n) + '</span><span class="sp">' + money(p.p) + '</span></a>';
        });
        h += m.length ? '<a class="all" href="' + esc(A.base + '/catalog.php?view=all&q=' + encodeURIComponent(q)) + '">See all ' + m.length + ' results</a>'
                      : '<a class="all" href="' + esc(A.wa ? 'https://wa.me/' + A.wa + '?text=' + encodeURIComponent('Do you have: ' + q + '?') : '#') + '">No match — ask us on WhatsApp</a>';
        box.innerHTML = h; box.classList.add('show'); sel = -1;
      });
    }
    var tm; inp.addEventListener('input', function () { clearTimeout(tm); tm = setTimeout(render, 120); });
    inp.addEventListener('focus', function () { loadIdx(function () {}); if (inp.value.trim().length >= 2) render(); });
    inp.addEventListener('keydown', function (e) {
      var opts = $$('a', box); if (!opts.length || !box.classList.contains('show')) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); sel = (sel + (e.key === 'ArrowDown' ? 1 : -1) + opts.length) % opts.length; opts.forEach(function (o, i) { o.classList.toggle('on', i === sel); }); }
      else if (e.key === 'Enter' && sel > -1) { e.preventDefault(); location.href = opts[sel].href; }
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('.srch')) box.classList.remove('show'); });
  });

  // ---------- pages drawn from the index: wishlist, recently viewed ----------
  function cardHtml(p) {
    var st = { in: 'In stock', low: 'Limited stock', out: 'Out of stock', svc: 'Service' }[p.s] || '';
    return '<article class="pc" data-card data-id="' + p.i + '" data-n="' + esc(p.n) + '" data-p="' + p.p + '" data-f="' + esc(p.f) + '" data-m="' + esc(p.m) + '">' +
      '<button type="button" class="wl" data-wish="' + p.i + '" aria-label="Wishlist">' + ico('heart') + '</button>' +
      '<a class="im" href="' + esc(p.u) + '">' + (p.f ? '<img src="' + esc(p.f) + '" alt="' + esc(p.n) + '" loading="lazy" class="ld">' : '<span class="noimg">' + ico('box') + '</span>') + '</a>' +
      '<div class="bd"><span class="br">' + esc(p.b || p.c) + '</span><a class="nm" href="' + esc(p.u) + '">' + esc(p.n) + '</a><span class="stk ' + p.s + '">' + st + '</span>' +
      '<div class="ft"><span class="price">' + money(p.p) + '</span><span><button type="button" class="addc" data-add aria-label="Add to cart">' + ico('cart') + '</button>' +
      '<span class="qty"><button type="button" data-q="-1">−</button><span data-qn>1</span><button type="button" data-q="1">+</button></span></span></div></div></article>';
  }
  function fromIdx(sel, ids, emptySel) {
    var el = $(sel); if (!el) return;
    loadIdx(function () {
      var by = {}; idx.forEach(function (p) { by[p.i] = p; });
      var list = ids.map(function (i) { return by[i]; }).filter(Boolean);
      el.innerHTML = list.map(cardHtml).join('');
      if (emptySel) $(emptySel).hidden = list.length > 0;
      var wrap = el.closest('[data-hide-empty]'); if (wrap) wrap.hidden = !list.length;
      paintCart(); paintWish();
    });
  }
  fromIdx('[data-wish-grid]', wish.slice().reverse(), '[data-wish-empty]');
  var seen = (store.get('akcSeen', []) || []).filter(function (x) { return !viewed || String(x) !== viewed.dataset.viewed; });
  if (seen.length) fromIdx('[data-recent]', seen.slice(0, 10)); else $$('[data-recent]').forEach(function (r) { var w = r.closest('[data-hide-empty]'); if (w) w.hidden = true; });

  // ---------- hero slider ----------
  $$('.slides').forEach(function (s) {
    var dots = $$('button', s.parentNode.querySelector('.dots') || document.createElement('i')), n = s.children.length, cur = 0, tm;
    if (n < 2) return;
    function go(i) { cur = (i + n) % n; s.scrollTo({ left: s.children[cur].offsetLeft - s.offsetLeft, behavior: 'smooth' }); }
    s.addEventListener('scroll', function () { var i = Math.round(s.scrollLeft / s.clientWidth); dots.forEach(function (d, j) { d.classList.toggle('on', j === i); }); cur = i; }, { passive: true });
    dots.forEach(function (d, j) { d.addEventListener('click', function () { go(j); restart(); }); });
    function restart() { clearInterval(tm); if (!matchMedia('(prefers-reduced-motion: reduce)').matches) tm = setInterval(function () { go(cur + 1); }, 5000); }
    s.addEventListener('pointerdown', function () { clearInterval(tm); }); restart();
  });

  // ---------- filter sheet: apply on change ----------
  // filter panel: on a wide screen it is always open, so each tap applies at once
  $$('[data-fsubmit]').forEach(function (f) { f.addEventListener('change', function (e) { if (matchMedia('(min-width: 1100px)').matches && e.target.matches('select, input[type=checkbox], input[type=radio]')) f.submit(); }); });
  $$('[data-autosubmit]').forEach(function (f) { f.addEventListener('change', function (e) { if (e.target.matches('select, input[type=checkbox], input[type=radio]')) f.submit(); }); });

  // ---------- installable app ----------
  var deferred = null;
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault(); deferred = e;
    if (store.get('akcNoInstall', 0) > Date.now()) return;
    $$('[data-install]').forEach(function (b) { b.classList.add('show'); });
  });
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-install-go],[data-install-no]'); if (!b) return;
    if (b.hasAttribute('data-install-go') && deferred) { deferred.prompt(); deferred = null; }
    if (b.hasAttribute('data-install-no')) store.set('akcNoInstall', Date.now() + 14 * 864e5);
    $$('[data-install]').forEach(function (x) { x.classList.remove('show'); });
  });
  if ('serviceWorker' in navigator) window.addEventListener('load', function () { navigator.serviceWorker.register(A.base + '/sw.js').catch(function () {}); });

  paintCart(); paintWish();
})();
// show / hide password
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-eye]'); if (!b) return;
  var i = b.parentNode.querySelector('[data-pw]'); if (i) i.type = i.type === 'password' ? 'text' : 'password';
});
// a logged-in customer's wishlist lives on the account: merge the phone's list once, then keep them the same
(function () {
  var A = window.AKC || {}; if (!A.cust || !window.fetch) return;
  function sync(mode) {
    var fd = new FormData(); fd.append('do', 'wish_sync'); fd.append('mode', mode); fd.append('csrf', A.csrf);
    try { fd.append('ids', localStorage.getItem('akcWish') || '[]'); } catch (e) { fd.append('ids', '[]'); }
    return fetch(A.base + '/catalog.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  var first = false; try { first = !sessionStorage.getItem('akcWishSynced'); } catch (e) {}
  if (first) sync('merge').then(function (j) {
    if (!j.ok) return;
    try { localStorage.setItem('akcWish', JSON.stringify(j.ids.map(String))); sessionStorage.setItem('akcWishSynced', '1'); } catch (e) {}
    if (j.ids.length) location.pathname.indexOf('catalog.php') > -1 && /view=wish/.test(location.search) && location.reload();
  });
  document.addEventListener('click', function (e) { if (e.target.closest('[data-wish]')) setTimeout(function () { sync('replace'); }, 50); });
})();
