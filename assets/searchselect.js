// No drop-down lists, anywhere. Every <select> on a page becomes a box you
// type in - the way an item is found on the bill: tap it and every choice
// shows, type a few letters (any word, any order) and the list narrows, tap
// one. The real <select> stays in the page, hidden, so every form, every
// onchange and every script that sets .value keeps working untouched.
//
// One file for the website and the phone app (mobile/www has a copy; a test
// checks the two are the same). A select can opt out with class="no-search".
(function () {
  if (window.SearchSelect) return;

  var css = '.ss-wrap{position:relative;display:block;min-width:0}' +
    '.ss-wrap>input.ss-in{width:100%;box-sizing:border-box;padding-right:30px}' +
    '.ss-wrap:after{content:"⌕";position:absolute;right:10px;top:50%;transform:translateY(-50%);opacity:.45;pointer-events:none;font-size:16px}' +
    '.ss-list{position:absolute;left:0;right:0;top:100%;z-index:60;max-height:280px;overflow-y:auto;display:none;margin-top:2px;' +
    'background:var(--card,var(--surface,#fff));color:inherit;border:1px solid var(--line,var(--border,#d6dce5));border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.14)}' +
    '.ss-list.show{display:block}.ss-o{padding:11px 12px;border-bottom:1px solid var(--line,var(--border,#eef1f5));cursor:pointer;line-height:1.3}' +
    '.ss-o:last-child{border-bottom:0}.ss-o.on,.ss-o:hover{background:rgba(26,86,219,.09)}.ss-o.cur{font-weight:700}' +
    '.ss-o small{display:block;opacity:.65;font-size:12px}.ss-none{padding:11px 12px;opacity:.6}';

  /** How close a choice is to what was typed - the bill's own party search
   *  (SearchPick.rank in app.js): the exact name first, then starting with
   *  it, then a word starting with it, then anywhere, then every typed word
   *  in any order. -1 = not a match. */
  var rank = function (hay, q) {
    hay = hay.toLowerCase().replace(/\s+/g, ' '); q = q.toLowerCase().trim().replace(/\s+/g, ' ');
    if (hay === q) return 0;
    if (hay.indexOf(q) === 0) return 1;
    if (hay.indexOf(' ' + q) !== -1) return 2;
    if (hay.indexOf(q) !== -1) return 3;
    return q.split(' ').every(function (t) { return hay.indexOf(t) !== -1; }) ? 4 : -1;
  };
  var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
  var proto = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
  var protoIdx = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'selectedIndex');

  function opts(sel) {
    var out = [];
    Array.prototype.forEach.call(sel.options, function (o, i) {
      if (o.hidden || o.style.display === 'none') return;
      var g = o.parentNode && o.parentNode.tagName === 'OPTGROUP' ? o.parentNode.label : '';
      // a party's mobile (data-mobile) or any other handle (data-search) finds it too
      out.push({ i: i, v: o.value, t: (o.text || '').trim(), g: g, dis: o.disabled, blank: o.value === '',
                 sub: o.dataset.mobile || '', more: o.dataset.search || '' });
    });
    return out;
  }

  function enhance(sel) {
    // (data-pick-ready: it already has the bill's own search box - SearchPick)
    if (sel._ss || sel.dataset.pickReady || sel.multiple || sel.size > 1 || sel.classList.contains('no-search') || sel.closest('.no-search')) return;
    // a row copied by a script carries a copy of the box but not its brain:
    // throw the copy away and build it again for this select
    var old = sel.parentNode && sel.parentNode.classList && sel.parentNode.classList.contains('ss-wrap') ? sel.parentNode : null;
    if (old) { old.parentNode.insertBefore(sel, old); old.parentNode.removeChild(old); }
    var wrap = document.createElement('span');
    wrap.className = 'ss-wrap';
    if (sel.style.width) wrap.style.width = sel.style.width;
    if (sel.style.maxWidth) wrap.style.maxWidth = sel.style.maxWidth;
    if (sel.style.flex) wrap.style.flex = sel.style.flex;
    var inp = document.createElement('input');
    inp.type = 'text';
    inp.className = 'ss-in ' + sel.className.replace(/\bno-search\b/, '');
    inp.setAttribute('autocomplete', 'off');
    inp.setAttribute('role', 'combobox');
    inp.setAttribute('aria-label', sel.getAttribute('aria-label') || (sel.labels && sel.labels[0] ? sel.labels[0].textContent.trim() : sel.name || 'Choose'));
    if (sel.id) inp.id = sel.id + '_ss';
    var list = document.createElement('div');
    list.className = 'ss-list';
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(inp); wrap.appendChild(list); wrap.appendChild(sel);
    sel.style.display = 'none';
    sel._ss = { inp: inp };
    // a hidden select cannot show "please fill this in"; the box does it
    if (sel.required) { inp.required = true; sel.required = false; sel._ssReq = true; }
    if (sel.labels) Array.prototype.forEach.call(sel.labels, function (l) { l.htmlFor = inp.id || ''; });

    var shown = [], on = -1;
    var cur = function () { var o = sel.options[sel.selectedIndex]; return o && o.value !== '' ? (o.text || '').trim() : ''; };
    var sync = function () {
      inp.value = cur();
      inp.placeholder = (sel.options[0] && sel.options[0].value === '' ? (sel.options[0].text || '').trim() : '') || inp.placeholder || 'Type to search';
      inp.disabled = sel.disabled;
    };
    var paint = function (q) {
      var all = opts(sel);
      if (q) {
        shown = all.filter(function (o) { return !o.blank && (o.r = rank(o.t + ' ' + o.sub + ' ' + o.more + ' ' + o.g, q)) >= 0; });
        shown.sort(function (a, b) { return a.r - b.r || a.t.length - b.t.length || a.i - b.i; });   // closest, then shortest
      } else shown = all;
      on = -1;
      list.innerHTML = shown.slice(0, 300).map(function (o, k) {
        return '<div class="ss-o' + (o.i === sel.selectedIndex ? ' cur' : '') + '" data-k="' + k + '"' + (o.dis ? ' style="opacity:.4"' : '') + '>' +
          esc(o.t || '—') + (o.sub || o.g ? '<small>' + esc([o.sub, o.g].filter(Boolean).join(' · ')) + '</small>' : '') + '</div>';
      }).join('') || '<div class="ss-none">Nothing found</div>';
      list.classList.add('show');
    };
    var pick = function (o) {
      if (!o || o.dis) return;
      var was = sel.value;
      protoIdx.set.call(sel, o.i);
      sync();
      list.classList.remove('show');
      if (sel.value !== was) {
        sel.dispatchEvent(new Event('input', { bubbles: true }));
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
    };
    var close = function () {
      list.classList.remove('show');
      // a half-typed word is not a choice: show what is really chosen. An
      // emptied box means "none", where "none" is one of the choices.
      if (inp.value.trim() === '' && sel.options[0] && sel.options[0].value === '' && sel.selectedIndex !== 0) pick(opts(sel)[0]);
      else sync();
    };
    inp.addEventListener('focus', function () { inp.select(); paint(''); });
    inp.addEventListener('click', function () { if (!list.classList.contains('show')) paint(''); });
    inp.addEventListener('input', function () { paint(inp.value.trim()); });
    inp.addEventListener('keydown', function (e) {
      var rows = list.querySelectorAll('.ss-o');
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!list.classList.contains('show')) paint('');
        on = Math.max(0, Math.min(rows.length - 1, on + (e.key === 'ArrowDown' ? 1 : -1)));
        rows.forEach(function (r, k) { r.classList.toggle('on', k === on); });
        if (rows[on]) rows[on].scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'Enter' && list.classList.contains('show')) {
        e.preventDefault();
        pick(shown[on >= 0 ? on : 0]);
      } else if (e.key === 'Escape') { close(); inp.blur(); }
    });
    // mousedown, not click: it must land before the box loses focus
    list.addEventListener('mousedown', function (e) {
      var d = e.target.closest('.ss-o'); if (!d) return;
      e.preventDefault();
      pick(shown[+d.dataset.k]);
    });
    inp.addEventListener('blur', function () { setTimeout(close, 120); });

    // scripts that set the choice themselves (sel.value = 5) are followed
    try {
      Object.defineProperty(sel, 'value', { configurable: true, get: function () { return proto.get.call(sel); },
        set: function (v) { proto.set.call(sel, v); sync(); } });
      Object.defineProperty(sel, 'selectedIndex', { configurable: true, get: function () { return protoIdx.get.call(sel); },
        set: function (v) { protoIdx.set.call(sel, v); sync(); } });
    } catch (e) { /* very old browser: the box still works, it just may lag a script */ }
    sel.addEventListener('change', sync);
    new MutationObserver(sync).observe(sel, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled'] });
    sel._ss.sync = sync;
    sync();
  }

  function scan(root) {
    if (!root || !root.querySelectorAll) return;
    if (root.tagName === 'SELECT') enhance(root);
    Array.prototype.forEach.call(root.querySelectorAll('select'), enhance);
  }

  function start() {
    if (!document.getElementById('ss-css')) {
      var st = document.createElement('style'); st.id = 'ss-css'; st.textContent = css; document.head.appendChild(st);
    }
    scan(document.body);
    new MutationObserver(function (ms) {
      ms.forEach(function (m) { m.addedNodes.forEach(function (n) { if (n.nodeType === 1) scan(n); }); });
    }).observe(document.body, { childList: true, subtree: true });
    // a reset form puts every box back
    document.addEventListener('reset', function () { setTimeout(function () { Array.prototype.forEach.call(document.querySelectorAll('select'), function (s) { if (s._ss) s._ss.sync(); }); }, 0); }, true);
  }

  window.SearchSelect = { enhance: enhance, scan: scan };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
