// AK Computer - app JS (menu, billing rows, item search)

// ----- top bar: notification bell + avatar dropdowns -----
function topbarDropdown(btnId, panelId) {
  var btn = document.getElementById(btnId), panel = document.getElementById(panelId);
  if (!btn || !panel) return;
  btn.addEventListener('click', function (ev) {
    ev.stopPropagation();
    var willOpen = !panel.classList.contains('show');
    document.querySelectorAll('.bell-panel.show, .avatar-panel.show').forEach(function (p) { p.classList.remove('show'); });
    if (willOpen) panel.classList.add('show');
  });
}
topbarDropdown('bellBtn', 'bellPanel');
topbarDropdown('avatarBtn', 'avatarPanel');
document.addEventListener('click', function () {
  document.querySelectorAll('.bell-panel.show, .avatar-panel.show').forEach(function (p) { p.classList.remove('show'); });
});

// ----- global search: local sidebar-link filter (instant) + backend search
// across parties/items/sales/repairs (debounced) - wired to both the
// desktop top bar box and the mobile sidebar box (Ctrl/Cmd+K focuses
// whichever one is actually visible) -----
function wireGlobalSearch(inputId, resultsId) {
  var inp = document.getElementById(inputId);
  var box = document.getElementById(resultsId);
  if (!inp || !box) return;
  var links = Array.prototype.slice.call(document.querySelectorAll('.sidebar-links a')).map(function (a) {
    return { text: a.textContent.trim(), href: a.getAttribute('href') };
  });
  var debounce = null;
  inp.addEventListener('input', function () {
    var q = inp.value.trim();
    var qLower = q.toLowerCase();
    clearTimeout(debounce);
    if (!q) { box.classList.remove('show'); box.innerHTML = ''; return; }
    var menuMatches = links.filter(function (l) { return l.text.toLowerCase().indexOf(qLower) > -1; }).slice(0, 5);
    var menuHtml = menuMatches.length
      ? '<div class="search-group-label">Menu</div>' + menuMatches.map(function (l) { return '<a href="' + l.href + '">' + l.text + '</a>'; }).join('')
      : '';
    box.innerHTML = menuHtml || '<div class="bell-empty">Searching…</div>';
    box.classList.add('show');
    if (q.length < 2) return;
    debounce = setTimeout(function () {
      fetch('ajax.php?a=global_search&q=' + encodeURIComponent(q)).then(function (r) { return r.json(); }).then(function (d) {
        var extraHtml = (d.groups || []).map(function (g) {
          return '<div class="search-group-label">' + g.label + '</div>' +
            g.items.map(function (it) { return '<a href="' + it.href + '">' + it.label + '</a>'; }).join('');
        }).join('');
        box.innerHTML = (menuHtml + extraHtml) || '<div class="bell-empty">No matches</div>';
      });
    }, 250);
  });
  document.addEventListener('click', function (ev) { if (!ev.target.closest('#' + inputId) && !ev.target.closest('#' + resultsId)) box.classList.remove('show'); });
}
wireGlobalSearch('navSearch', 'navSearchResults');
wireGlobalSearch('sidebarSearch', 'sidebarSearchResults');

// ----- keyboard shortcuts: Ctrl/Cmd+K focuses search (desktop top bar box
// if visible, else the mobile sidebar's search box - opening the sidebar
// for it); Escape closes any open dropdown/sidebar/sheet -----
document.addEventListener('keydown', function (ev) {
  if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'k') {
    ev.preventDefault();
    var top = document.getElementById('navSearch');
    if (top && top.offsetParent !== null) { top.focus(); return; }
    var side = document.getElementById('sidebarSearch');
    if (side) {
      var sb = document.getElementById('sidebar'), ov = document.getElementById('sidebarOverlay');
      if (sb && ov) { sb.classList.add('open'); ov.classList.add('show'); }
      side.focus();
    }
    return;
  }
  if (ev.key === 'Escape') {
    document.querySelectorAll('.bell-panel.show, .avatar-panel.show, .topbar-search-results.show').forEach(function (p) { p.classList.remove('show'); });
    var sb2 = document.getElementById('sidebar'), ov2 = document.getElementById('sidebarOverlay');
    if (sb2 && sb2.classList.contains('open')) { sb2.classList.remove('open'); ov2.classList.remove('show'); }
    var sheet = document.getElementById('actionSheet'), sheetOv = document.getElementById('sheetOverlay');
    if (sheet && sheet.classList.contains('open')) { sheet.classList.remove('open'); sheetOv.classList.remove('show'); }
  }
});

// ----- saved filters (sales.php / parties.php / reports.php filter bars) -----
function saveCurrentFilter(page) {
  var name = prompt('Name this filter (e.g. "This month" or "Overdue only"):');
  if (!name) return;
  var fd = new FormData();
  fd.append('csrf', CSRF_TOKEN);
  fd.append('page', page);
  fd.append('name', name);
  fd.append('query_string', window.location.search.replace(/^\?/, ''));
  fetch('ajax.php?a=save_filter', { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
    if (d.error) { alert(d.error); return; }
    location.reload();
  });
}
document.addEventListener('click', function (ev) {
  var btn = ev.target.closest('.saved-filter-del');
  if (!btn) return;
  if (!confirm('Delete this saved filter?')) return;
  var fd = new FormData();
  fd.append('csrf', CSRF_TOKEN);
  fd.append('id', btn.dataset.id);
  fetch('ajax.php?a=delete_filter', { method: 'POST', body: fd }).then(function () { location.reload(); });
});

// ----- dark mode toggle (avatar menu) - persisted server-side per user -----
(function () {
  var btn = document.getElementById('themeToggleBtn');
  if (!btn) return;
  var label = document.getElementById('themeToggleLabel');
  var next = { auto: 'dark', dark: 'light', light: 'auto' };
  var labels = { auto: 'Dark Mode', dark: 'Light Mode', light: 'Auto Theme' };
  btn.addEventListener('click', function (ev) {
    ev.stopPropagation();
    var cur = btn.dataset.theme || 'auto';
    var nx = next[cur] || 'auto';
    if (nx === 'auto') document.documentElement.removeAttribute('data-theme');
    else document.documentElement.setAttribute('data-theme', nx);
    btn.dataset.theme = nx;
    if (label) label.textContent = labels[nx];
    var fd = new FormData();
    fd.append('csrf', CSRF_TOKEN);
    fd.append('theme', nx);
    fetch('ajax.php?a=set_theme', { method: 'POST', body: fd });
  });
})();

// ----- sidebar -----
var menuBtn = document.getElementById('menuBtn');
var sidebar = document.getElementById('sidebar');
var overlay = document.getElementById('sidebarOverlay');
if (menuBtn) {
  menuBtn.addEventListener('click', function () {
    sidebar.classList.toggle('open');
    overlay.classList.toggle('show');
  });
  overlay.addEventListener('click', function () {
    sidebar.classList.remove('open');
    overlay.classList.remove('show');
  });
}

// ----- sidebar accordion groups (remember open state) -----
document.querySelectorAll('.nav-group-head').forEach(function (head) {
  head.addEventListener('click', function () {
    var g = head.parentElement;
    g.classList.toggle('open');
    try {
      var open = [];
      document.querySelectorAll('.nav-group.open').forEach(function (x) { open.push(x.dataset.group); });
      localStorage.setItem('navOpen', JSON.stringify(open));
    } catch (e) {}
  });
});
try {
  (JSON.parse(localStorage.getItem('navOpen') || '[]')).forEach(function (id) {
    var g = document.querySelector('.nav-group[data-group="' + id + '"]');
    if (g) g.classList.add('open');
  });
} catch (e) {}

// ----- quick action sheet (center + button lives in the footer,
// so bind via delegation - it doesn't exist when this file loads) -----
document.addEventListener('click', function (ev) {
  var sheet = document.getElementById('actionSheet');
  var sheetOverlay = document.getElementById('sheetOverlay');
  if (!sheet) return;
  if (ev.target.closest('#fabBtn')) {
    sheet.classList.toggle('open');
    sheetOverlay.classList.toggle('show');
  } else if (ev.target === sheetOverlay) {
    sheet.classList.remove('open');
    sheetOverlay.classList.remove('show');
  }
});

// ----- generic client-side table filter -----
function tableFilter(inputId, tableId) {
  var inp = document.getElementById(inputId), tbl = document.getElementById(tableId);
  if (!inp || !tbl) return;
  inp.addEventListener('input', function () {
    // word-wise: every typed word must appear somewhere in the row, any order -
    // so "ultra curved" finds "Ultra HD Gaming Monitor 27 inch Curved Display"
    var words = inp.value.toLowerCase().split(/\s+/).filter(Boolean);
    tbl.querySelectorAll('tbody tr').forEach(function (tr) {
      var txt = tr.textContent.toLowerCase();
      tr.style.display = words.every(function (w) { return txt.indexOf(w) > -1; }) ? '' : 'none';
    });
  });
}

// ================= Billing widget =================
// Used by sales / purchases / estimates / returns / tasks.
// Config via initBill({mode:'sale'|'purchase', priceField:'selling_price'|...})

// Barcode-gun feedback: quick high beep = serial accepted, low buzz =
// duplicate/problem - so a stack of boxes can be scanned eyes-free.
function scanBeep(ok) {
  try {
    var AC = window.AudioContext || window.webkitAudioContext;
    if (!scanBeep.ctx) scanBeep.ctx = new AC();
    var c = scanBeep.ctx, o = c.createOscillator(), g = c.createGain();
    o.frequency.value = ok ? 1500 : 260;
    g.gain.value = 0.08;
    o.connect(g); g.connect(c.destination);
    o.start(); o.stop(c.currentTime + (ok ? 0.07 : 0.28));
  } catch (e) {}
}

var Bill = {
  cfg: null, rowN: 0,

  init: function (cfg) {
    this.cfg = cfg;
    var self = this;
    // A barcode gun finishes every scan with an Enter keypress. On a bill
    // form that Enter must never submit the half-entered bill: single-line
    // inputs swallow it (the item search and serial boxes already run their
    // own Enter action), textareas keep it as a newline.
    var host = document.getElementById('billItems');
    var form = host ? host.closest('form') : null;
    if (form && !form.dataset.enterGuard) {
      form.dataset.enterGuard = '1';
      form.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && ev.target.tagName === 'INPUT') ev.preventDefault();
      });
    }
    // Vyapar-style 2-step flow: an "Add Items" button opens a full-screen
    // panel (the "second page") for one item at a time instead of showing
    // every item's fields inline on page 1. Only wired up when the page
    // actually has that panel markup (sales.php) - purchases.php and other
    // Bill.init() callers keep the classic always-inline row list.
    var addPanelBtn = document.getElementById('addItemsBtn');
    if (addPanelBtn) {
      addPanelBtn.addEventListener('click', function () { self.openAddPanel(); });
      var closeBtn = document.getElementById('aipClose');
      if (closeBtn) closeBtn.addEventListener('click', function () { self.closeAddPanel(true); });
      var saveBtn = document.getElementById('aipSave');
      if (saveBtn) saveBtn.addEventListener('click', function () { self.closeAddPanel(false); });
      var saveNewBtn = document.getElementById('aipSaveNew');
      if (saveNewBtn) saveNewBtn.addEventListener('click', function () { self.closeAddPanel(false); self.openAddPanel(); });
      this.renderSummary();
    } else {
      var addBtn = document.getElementById('addRowBtn');
      if (addBtn) addBtn.addEventListener('click', function () { self.addRow(); });
      this.addRow();
    }
    ['discount', 'paid'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener('input', function () { self.totals(); });
    });
    // party picked/changed AFTER items were added? refresh every row's
    // "this customer paid ₹X last time" line
    var partySel = document.getElementById('party_id');
    if (partySel) partySel.addEventListener('change', function () {
      document.querySelectorAll('.bill-row').forEach(function (d) { self.updateLastPrice(d); });
    });
    var pt = document.getElementById('price_type');
    if (pt) pt.addEventListener('change', function () { self.repriceAll(); });
  },

  // ----- 2-step "Add Items" panel (page 1 keeps just a summary list) -----
  openAddPanel: function (div) {
    var panel = document.getElementById('addItemPanel');
    if (!panel) return null;
    if (!div) div = this.addRow();
    div.classList.add('panel-mode');
    document.getElementById('aipBody').insertBefore(div, document.getElementById('aipTotalsBox'));
    panel.classList.add('show');
    panel.dataset.activeN = div.dataset.n;
    document.body.style.overflow = 'hidden';
    this.updatePanelTotal(div);
    div.querySelector('.i-search').focus();
    return div;
  },

  closeAddPanel: function (discardIfEmpty) {
    var panel = document.getElementById('addItemPanel');
    if (!panel) return;
    var div = document.querySelector('.bill-row[data-n="' + panel.dataset.activeN + '"]');
    panel.classList.remove('show');
    document.body.style.overflow = '';
    if (!div) return;
    div.classList.remove('panel-mode');
    if (discardIfEmpty && !div.querySelector('.i-id').value) {
      div.remove();
    } else {
      document.getElementById('billItems').appendChild(div);
    }
    this.renderSummary();
    this.totals();
  },

  updatePanelTotal: function (div) {
    var box = document.getElementById('aipTotalsBox');
    if (!box) return;
    var qty = parseFloat(div.querySelector('.i-qty').value) || 0;
    var price = parseFloat(div.querySelector('.i-price').value) || 0;
    box.querySelector('.aip-sub').textContent = (qty * price - this.lineDisc(div)).toFixed(2);
  },

  renderSummary: function () {
    var list = document.getElementById('billSummaryList');
    if (!list) return;
    var rows = document.querySelectorAll('#billItems .bill-row');
    var self = this;
    if (!rows.length) { list.innerHTML = '<div class="bsum-empty">No items added yet.</div>'; return; }
    list.innerHTML = '';
    rows.forEach(function (div) {
      var name = div.querySelector('.i-search').value || '(item)';
      var qty = parseFloat(div.querySelector('.i-qty').value) || 0;
      var price = parseFloat(div.querySelector('.i-price').value) || 0;
      var unit = div.dataset.unit || '';
      var desc = div.querySelector('.i-desc') ? div.querySelector('.i-desc').value : '';
      var row = document.createElement('div');
      row.className = 'bsum-row';
      row.innerHTML =
        '<div class="bsum-main"><strong></strong><div class="muted">' + qty + ' ' + unit + ' × ₹' + price.toFixed(2) + (desc ? ' · ' + '<span class="bsum-desc"></span>' : '') + '</div></div>' +
        '<div class="bsum-val">₹' + (qty * price - self.lineDisc(div)).toFixed(2) + '</div>' +
        '<button type="button" class="bsum-del" title="Remove">✕</button>';
      row.querySelector('strong').textContent = name;
      if (desc) row.querySelector('.bsum-desc').textContent = desc;
      row.addEventListener('click', function (ev) {
        if (ev.target.classList.contains('bsum-del')) return;
        self.openAddPanel(div);
      });
      row.querySelector('.bsum-del').addEventListener('click', function () {
        div.remove();
        self.renderSummary();
        self.totals();
      });
      list.appendChild(row);
    });
  },

  priceField: function () {
    if (this.cfg.mode === 'purchase') return 'purchase_price';
    var pt = document.getElementById('price_type');
    return (pt && pt.value === 'b2b') ? 'b2b_price' : 'selling_price';
  },

  addRow: function () {
    var n = ++this.rowN, self = this;
    var wrap = document.getElementById('billItems');
    var div = document.createElement('div');
    div.className = 'bill-row';
    div.dataset.n = n;
    var cfFields = (this.cfg.customFields || []).map(function (f) {
      var safeLabel = f.label.replace(/</g, '&lt;');
      return '<div><label>' + safeLabel + '</label><input type="text" class="i-cf" name="cf_' + f.id + '[]" data-label="' + safeLabel.replace(/"/g, '&quot;') + '"></div>';
    }).join('');
    div.innerHTML =
      '<div class="row-line">' +
      '<div class="cell-item isearch-wrap">' +
      '  <label>Item Name</label>' +
      '  <div style="display:flex;gap:4px">' +
      '  <input type="text" class="i-search" placeholder="Type item name..." autocomplete="off" style="flex:1">' +
      (('BarcodeDetector' in window) ? '  <button type="button" class="btn btn-sm btn-outline i-scanbtn" title="Scan barcode with camera" style="flex-shrink:0">📷</button>' : '') +
      '  </div>' +
      '  <input type="hidden" name="item_id[]" class="i-id">' +
      // This row's stable id travels alongside item_id[] so the server can pair
      // each item with ITS OWN serials (serial_sel[n][]). Without it the server
      // guessed by array position, which broke the moment a row was deleted -
      // one item then picked up another item's serial numbers.
      '  <input type="hidden" name="row_n[]" class="i-rown" value="' + n + '">' +
      '  <input type="hidden" name="tax_rate[]" class="i-tax" value="0">' +
      '  <div class="isearch-results"></div>' +
      '</div>' +
      '<div><label>Quantity</label><input type="number" step="any" min="0" name="qty[]" class="i-qty" value="1"></div>' +
      (this.cfg.freeQty ? '<div><label>Free Quantity</label><input type="number" step="any" min="0" name="free_qty[]" class="i-freeq" value="0" title="Free quantity (scheme)"></div>' : '') +
      '<div><label>Rate (Price/Unit)</label><input type="number" step="any" min="0" name="price[]" class="i-price" value="0"></div>' +
      // Per-line stock location (godown vs shop): only on sale bills and only
      // when the shop has more than one location (cfg.locations is emptied
      // server-side for location-locked users). Defaults to the bill's own
      // location so nothing changes unless the staff picks another godown.
      (this.cfg.mode === 'sale' && (this.cfg.locations || []).length > 1 ?
      '<div><label>Stock From</label><select name="line_loc[]" class="i-loc">' +
      this.cfg.locations.map(function (l) {
        var defLoc = (document.getElementById('location_id') || {value: 0}).value;
        return '<option value="' + l.id + '"' + (String(l.id) === String(defLoc) ? ' selected' : '') + '>' +
               String(l.name).replace(/</g, '&lt;') + '</option>';
      }).join('') + '</select></div>' : '') +
      (this.cfg.lineDisc ?
      '<div><label>Disc</label><div style="display:flex;gap:4px">' +
      '<input type="number" step="any" min="0" name="ldisc[]" class="i-ldisc" value="0" style="flex:1;min-width:56px" title="Discount for this item">' +
      '<select name="ldisc_t[]" class="i-ldisct" style="width:52px;flex-shrink:0"><option value="amount">\u20b9</option><option value="percent">%</option></select>' +
      '</div></div>' : '') +
      '<div class="i-total-wrap"><label>Total</label><input type="text" class="i-total" value="0.00" readonly tabindex="-1"></div>' +
      '<div><button type="button" class="row-del" title="Remove">✕</button></div>' +
      '</div>' +
      '<div class="i-extra"></div>' +
      '<div class="muted i-stockinfo"></div>' +
      '<div class="i-lastprice" style="color:var(--ok);font-weight:600;font-size:13px"></div>' +
      (this.cfg.mode === 'sale' ? '<div><label>Description</label><input type="text" class="i-desc" name="description[]" placeholder="Optional note for this item"></div>' : '') +
      (this.cfg.mode === 'sale' && cfFields ? '<div class="cf-section"><h4>Custom Fields</h4>' + cfFields + '</div>' : '');
    wrap.appendChild(div);

    div.querySelector('.row-del').addEventListener('click', function () {
      div.remove(); self.totals();
    });
    ['.i-qty', '.i-price', '.i-ldisc'].forEach(function (sel) {
      var el = div.querySelector(sel);
      if (el) el.addEventListener('input', function () { self.rowTotal(div); });
    });
    var ldtSel = div.querySelector('.i-ldisct');
    if (ldtSel) ldtSel.addEventListener('change', function () { self.rowTotal(div); });
    var descInp = div.querySelector('.i-desc');
    if (descInp) descInp.addEventListener('input', function () { self.renderSummary(); });
    this.attachSearch(div);
    var scanBtn = div.querySelector('.i-scanbtn');
    if (scanBtn) scanBtn.addEventListener('click', function () { self.scanBarcode(div.querySelector('.i-search')); });
    return div;
  },

  attachSearch: function (div) {
    var self = this;
    var inp = div.querySelector('.i-search');
    var res = div.querySelector('.isearch-results');
    var t = null;
    // barcode scanner sends Enter - don't submit the form, just search
    inp.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); }
    });
    inp.addEventListener('input', function () {
      div.querySelector('.i-id').value = '';
      clearTimeout(t);
      var qy = inp.value.trim();
      if (qy.length < 1) { res.classList.remove('show'); return; }
      t = setTimeout(function () {
        var partySel = document.getElementById('party_id');
        fetch('ajax.php?a=item_search&q=' + encodeURIComponent(qy) + '&loc=' + (self.cfg.locSel ? document.getElementById(self.cfg.locSel).value : '') +
              '&mode=' + (self.cfg.mode || '') +
              (partySel && partySel.value > 0 ? '&party=' + partySel.value : ''))
          .then(function (r) { return r.json(); })
          .then(function (items) {
            // barcode scan: exact barcode match -> auto-pick instantly
            if (items.length === 1 && items[0].barcode && items[0].barcode === qy) {
              self.pickItem(div, items[0]);
              return;
            }
            res.innerHTML = '';
            items.forEach(function (it) {
              var d = document.createElement('div');
              d.className = 'ir';
              d.innerHTML = '<strong>' + it.name + '</strong><small>' + (it.item_type === 'service' ? 'Service' : 'Stock: ' + it.stock) +
                ' | Retail: ' + it.selling_price + ' | B2B: ' + it.b2b_price +
                (self.cfg.showPurchasePrice ? ' | Purchase: ' + it.purchase_price : '') +
                (it.serial_tracked == 1 ? ' | Serial-tracked' : '') +
                (it.last_price ? '<br>👤 આ ગ્રાહકને છેલ્લે: ₹' + it.last_price + ' (' + it.last_date + ')' : '') + '</small>';
              d.addEventListener('click', function () { self.pickItem(div, it); });
              res.appendChild(d);
            });
            // Vyapar-style: "Add New Item" link at the bottom of results
            var addNew = document.createElement('div');
            addNew.className = 'ir';
            addNew.innerHTML = '<strong style="color:var(--primary)">＋ Add New Item</strong><small>Create a new item "' + qy + '"</small>';
            addNew.addEventListener('click', function () {
              window.open('items.php?action=new', '_blank');
              res.classList.remove('show');
            });
            res.appendChild(addNew);
            res.classList.add('show');
          });
      }, 250);
    });
    document.addEventListener('click', function (ev) {
      if (!div.contains(ev.target)) res.classList.remove('show');
    });
  },

  // Camera barcode scan - uses the browser's native BarcodeDetector (Chrome/
  // Android has it built in), no external library needed. Detected code is
  // typed into the item search box, which already auto-picks on an exact
  // barcode match (see attachSearch above) - same path a USB scanner uses.
  // Camera scanner. Single-shot by default (fills the input, closes).
  // With onCode: CONTINUOUS serial mode - every code detected is handed to
  // onCode (add to bill / textarea), the overlay stays open so a whole
  // stack of boxes can be scanned one after another; the same code within
  // 2.5s is ignored (camera seeing the same label across frames).
  scanBarcode: function (inp, onCode) {
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:#000;z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center';
    var video = document.createElement('video');
    video.setAttribute('playsinline', '');
    video.style.cssText = 'max-width:100%;max-height:80vh';
    var closeBtn = document.createElement('button');
    closeBtn.textContent = onCode ? '✅ Done / Close' : '✕ Close';
    closeBtn.type = 'button';
    closeBtn.className = 'btn btn-danger';
    closeBtn.style.cssText = 'margin-top:14px';
    var hint = document.createElement('div');
    hint.textContent = onCode ? 'સિરિયલનો બારકોડ/QR કેમેરા સામે ધરો — એક પછી એક બધા સ્કેન કરો' : 'Hold the barcode in front of the camera...';
    hint.style.cssText = 'color:#fff;margin:0 12px 10px;font-size:14px;text-align:center';
    var count = 0;
    overlay.appendChild(hint);
    overlay.appendChild(video);
    overlay.appendChild(closeBtn);
    document.body.appendChild(overlay);

    var stream = null, stopped = false, lastVal = '', lastT = 0;
    function stop() {
      stopped = true;
      if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
      overlay.remove();
    }
    closeBtn.addEventListener('click', stop);

    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (s) {
      stream = s;
      video.srcObject = s;
      video.play();
      var detector = new BarcodeDetector();
      function tick() {
        if (stopped) return;
        detector.detect(video).then(function (codes) {
          if (codes.length > 0) {
            var val = codes[0].rawValue;
            if (onCode) {
              var now = Date.now();
              if (val !== lastVal || now - lastT > 2500) {
                lastVal = val; lastT = now; count++;
                onCode(val);
                hint.textContent = '✔ ' + val + '  (' + count + ' સ્કેન થયા) — બીજો બતાવો, પતે એટલે Done';
                hint.style.color = '#4ade80';
              }
              setTimeout(function () { if (!stopped) requestAnimationFrame(tick); }, 700);
            } else {
              stop();
              inp.value = val;
              inp.dispatchEvent(new Event('input'));
            }
          } else if (!stopped) {
            requestAnimationFrame(tick);
          }
        }).catch(function () { if (!stopped) requestAnimationFrame(tick); });
      }
      requestAnimationFrame(tick);
    }).catch(function () {
      hint.textContent = 'Could not access camera - check permissions.';
      hint.style.color = '#f87171';
    });
  },

  pickItem: function (div, it) {
    var self = this;
    div.querySelector('.i-search').value = it.name;
    div.querySelector('.i-id').value = it.id;
    div.querySelector('.i-tax').value = it.tax_rate;
    div.querySelector('.i-price').value = it[this.priceField()] || 0;
    div.querySelector('.isearch-results').classList.remove('show');
    div.dataset.serialTracked = it.serial_tracked;
    div.dataset.stock = it.stock;
    div.dataset.cost = it.purchase_price || 0;
    div.dataset.unit = it.unit || '';
    div.querySelector('.i-stockinfo').textContent =
      (it.item_type !== 'service' && (this.cfg.mode === 'sale' || this.cfg.mode === 'staff')) ? 'Available: ' + it.stock + ' ' + it.unit : '';
    this.updateLastPrice(div);
    if (div.classList.contains('panel-mode')) this.updatePanelTotal(div);

    var extra = div.querySelector('.i-extra');
    extra.innerHTML = '';
    if (it.serial_tracked == 1 && this.cfg.serials) {
      if (this.cfg.mode === 'purchase') {
        extra.innerHTML = '<label class="mt">Serial numbers (one per line, count = qty) — 🔫 barcode gun works: scan, scan, scan</label>' +
          '<textarea name="serials[]" rows="2" placeholder="SN001\nSN002"></textarea>' +
          (('BarcodeDetector' in window) ? '<button type="button" class="btn btn-sm btn-outline pu-cam" style="margin-top:6px">📷 મોબાઇલ કેમેરાથી સિરિયલ સ્કેન</button>' : '');
        div.dataset.hasSerialBox = '1';
        this.wireSerialQtySync(div);
        var puCam = extra.querySelector('.pu-cam');
        if (puCam) puCam.addEventListener('click', function () {
          var ta2 = extra.querySelector('textarea');
          self.scanBarcode(ta2, function (val) {
            // append as a completed line -> qty sync + double-scan dedupe + beep
            ta2.value = (ta2.value && !/[\r\n]$/.test(ta2.value) ? ta2.value + '\n' : ta2.value) + val + '\n';
            ta2.dispatchEvent(new Event('input'));
          });
        });
        // serial-tracked item picked -> cursor straight into the serial box
        // so the barcode gun can start scanning units immediately
        extra.querySelector('textarea').focus();
      } else if (this.cfg.mode === 'sale') {
        // Vyapar-style serial picker: scan/type + Add, checkbox list, counter.
        // When editing a bill (cfg.editSaleId set), the fetch also returns
        // this item's serials already on THIS bill, and any serials passed
        // via div.dataset.preSerials come back pre-checked - so editing a
        // bill shows its current serials and lets you swap/fix them.
        var loc = this.cfg.locSel ? document.getElementById(this.cfg.locSel).value : '';
        var n = div.dataset.n;
        var saleIdQ = this.cfg.editSaleId ? '&sale_id=' + this.cfg.editSaleId : '';
        var pre = div.dataset.preSerials ? JSON.parse(div.dataset.preSerials) : [];
        fetch('ajax.php?a=serials&item_id=' + it.id + '&loc=' + loc + saleIdQ)
          .then(function (r) { return r.json(); })
          .then(function (sns) {
            var boxes = sns.map(function (s) {
              var checked = pre.indexOf(s) !== -1 ? ' checked' : '';
              return '<label class="sp-row"><input type="checkbox" name="serial_sel[' + n + '][]" value="' + s + '"' + checked + '> ' + s + '</label>';
            }).join('');
            extra.innerHTML =
              '<div class="serial-pick mt">' +
              '<label>Select Serial No. <span class="sp-count badge badge-warn">0 / ' + (parseFloat(div.querySelector('.i-qty').value) || 1) + ' entered</span></label>' +
              '<div class="sp-scan"><input type="text" class="sp-inp" placeholder="Type / scan serial no.">' +
              '<button type="button" class="btn btn-sm sp-add">Add</button>' +
              (('BarcodeDetector' in window) ? '<button type="button" class="btn btn-sm btn-outline sp-cam" title="મોબાઇલ કેમેરાથી સ્કેન">📷</button>' : '') + '</div>' +
              '<div class="sp-list">' + (boxes || '<span class="muted">No serials in stock (advance billing will proceed)</span>') + '</div>' +
              '</div>';
            function updCount() {
              var c = extra.querySelectorAll('input[type=checkbox]:checked').length;
              // For a serial-tracked item the number of serials picked IS the
              // quantity - each serial is one physical unit. So auto-sync qty
              // to the serial count (4 serials picked => qty becomes 4) instead
              // of forcing the user to also correct the qty box by hand.
              var qtyInp = div.querySelector('.i-qty');
              if (c > 0 && (parseFloat(qtyInp.value) || 0) !== c) {
                qtyInp.value = c;
                self.rowTotal(div);
                self.renderSummary();
              }
              var need = parseFloat(qtyInp.value) || 0;
              var el = extra.querySelector('.sp-count');
              el.textContent = c + ' / ' + need + ' entered';
              el.className = 'sp-count badge ' + (c == need ? 'badge-ok' : 'badge-warn');
            }
            updCount();
            extra.addEventListener('change', updCount);
            div.querySelector('.i-qty').addEventListener('input', updCount);
            var spInp = extra.querySelector('.sp-inp');
            spInp.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); extra.querySelector('.sp-add').click(); } });
            // serial-tracked item picked -> next step is scanning serials, so
            // put the cursor straight where the barcode gun will type
            spInp.focus();
            var spCam = extra.querySelector('.sp-cam');
            if (spCam) spCam.addEventListener('click', function () {
              // continuous mode: each camera detection goes through the same
              // Add path (tick in-stock serial / advance-billing confirm)
              self.scanBarcode(spInp, function (val) {
                spInp.value = val;
                extra.querySelector('.sp-add').click();
              });
            });
            extra.querySelector('.sp-add').addEventListener('click', function () {
              var v = spInp.value.trim();
              if (!v) return;
              var found = false, wasDup = false;
              extra.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
                if (cb.value.toLowerCase() === v.toLowerCase()) {
                  if (cb.checked) wasDup = true; // same unit scanned twice
                  cb.checked = true; found = true;
                }
              });
              if (wasDup) { scanBeep(false); spInp.value = ''; spInp.focus(); updCount(); return; }
              if (found) scanBeep(true);
              if (!found) {
                // Advance billing: the unit is physically here but its purchase
                // bill hasn't been entered yet. Accept the typed serial as a NEW
                // one (marked so) - the server records it sold to this bill, and
                // the later purchase entry reconciles it automatically.
                if (!confirm('Serial "' + v + '" is not in stock.\n\nSell it anyway (advance billing - purchase bill will come later)?')) { spInp.value = ''; spInp.focus(); return; }
                var lbl = document.createElement('label');
                lbl.className = 'sp-row';
                lbl.innerHTML = '<input type="checkbox" name="serial_sel[' + n + '][]" checked> ';
                lbl.querySelector('input').value = v;
                lbl.appendChild(document.createTextNode(v + ' '));
                var b = document.createElement('span'); b.className = 'badge badge-warn'; b.textContent = 'new';
                lbl.appendChild(b);
                extra.querySelector('.sp-list').appendChild(lbl);
                scanBeep(true);
              }
              spInp.value = '';
              spInp.focus();
              updCount();
            });
          });
      }
    }
    if (!div.dataset.hasSerialBox) {
      // keep hidden empty serials placeholder aligned with rows for purchase mode
      if (this.cfg.mode === 'purchase') {
        extra.innerHTML = '<input type="hidden" name="serials[]" value="">';
      }
    }
    this.rowTotal(div);
  },

  repriceAll: function () {
    // price type changed - just recalc totals (prices already typed stay)
    this.totals();
  },

  // "This customer paid ₹X last time" - shown PERSISTENTLY under the item
  // once picked (not just in the search dropdown), and refreshed for every
  // row when the party changes, so it works whichever order party/items
  // get filled in.
  updateLastPrice: function (div) {
    if (this.cfg.mode !== 'sale') return;
    var lp = div.querySelector('.i-lastprice');
    if (!lp) return;
    var partySel = document.getElementById('party_id');
    var itemId = div.querySelector('.i-id').value;
    if (!partySel || !(partySel.value > 0) || !itemId) { lp.textContent = ''; return; }
    fetch('ajax.php?a=last_price&item_id=' + itemId + '&party=' + partySel.value)
      .then(function (r) { return r.json(); })
      .then(function (d) {
        lp.textContent = d.price ? '👤 આ ગ્રાહકને છેલ્લે: ₹' + d.price + ' (' + d.date + ' · ' + d.doc + ')' : '';
      }).catch(function () {});
  },

  // Purchase serials are typed one-per-line in a textarea; the number of
  // serials IS the quantity for a serial-tracked item, so keep the qty box in
  // sync automatically (type 4 serials => qty becomes 4) instead of erroring.
  wireSerialQtySync: function (div) {
    var self = this;
    var ta = div.querySelector('textarea[name="serials[]"]');
    if (!ta) return;
    var lastCount = 0;
    var sync = function () {
      // A barcode gun types the serial then presses Enter, completing a line.
      // When the same unit gets scanned twice the repeat line is dropped on
      // the spot (low buzz) so the count stays honest; each new completed
      // line gets a short ok-beep. Only lines already terminated by Enter
      // are touched - text still being typed is left alone.
      if (/[\r\n]$/.test(ta.value)) {
        var seen = {}, out = [], dropped = false;
        ta.value.split(/[\r\n,]+/).forEach(function (s) {
          s = s.trim();
          if (!s) return;
          var k = s.toLowerCase();
          if (seen[k]) { dropped = true; return; }
          seen[k] = 1; out.push(s);
        });
        if (dropped) { ta.value = out.length ? out.join('\n') + '\n' : ''; scanBeep(false); }
        else if (out.length > lastCount) scanBeep(true);
        lastCount = out.length;
      }
      var n = ta.value.split(/[\r\n,]+/).map(function (s) { return s.trim(); }).filter(Boolean).length;
      if (n > 0) {
        var q = div.querySelector('.i-qty');
        if ((parseFloat(q.value) || 0) !== n) { q.value = n; self.rowTotal(div); self.renderSummary(); }
      }
    };
    ta.addEventListener('input', sync);
    sync();
  },

  // Rupee discount for one row: reads the row's Disc input (fixed rupees for
  // the whole line, or % of qty x rate), clamped so a line never goes negative.
  lineDisc: function (div) {
    var inp = div.querySelector('.i-ldisc');
    if (!inp) return 0;
    var qty = parseFloat(div.querySelector('.i-qty').value) || 0;
    var price = parseFloat(div.querySelector('.i-price').value) || 0;
    var gross = qty * price;
    var v = parseFloat(inp.value) || 0;
    var t = (div.querySelector('.i-ldisct') || {}).value;
    var d = t === 'percent' ? gross * v / 100 : v;
    return Math.max(0, Math.min(gross, d));
  },

  rowTotal: function (div) {
    var qty = parseFloat(div.querySelector('.i-qty').value) || 0;
    var price = parseFloat(div.querySelector('.i-price').value) || 0;
    div.querySelector('.i-total').value = (qty * price - this.lineDisc(div)).toFixed(2);
    if (div.classList.contains('panel-mode')) this.updatePanelTotal(div);
    this.totals();
  },

  totals: function () {
    var sub = 0, tax = 0, lines = 0, qtyTotal = 0, ldTotal = 0;
    var gst = this.cfg.gst;
    var self = this;
    document.querySelectorAll('#billItems .bill-row').forEach(function (div) {
      var qty = parseFloat(div.querySelector('.i-qty').value) || 0;
      var price = parseFloat(div.querySelector('.i-price').value) || 0;
      var tr = parseFloat(div.querySelector('.i-tax').value) || 0;
      var ld = self.lineDisc(div);
      var line = qty * price - ld;
      if (qty > 0) { lines++; qtyTotal += qty; }
      sub += line;
      ldTotal += ld;
      if (gst) tax += line * tr / 100;
    });
    var ldRow = document.getElementById('ldiscRow');
    if (ldRow) ldRow.style.display = ldTotal > 0.004 ? '' : 'none';
    var ldEl = document.getElementById('t_ldisc');
    if (ldEl) ldEl.textContent = ldTotal.toFixed(2);
    // discount: percent-aware when the ₹/% toggle markup is present on the page,
    // otherwise fall back to reading #discount as a plain rupee amount
    var discType = document.getElementById('discount_type');
    var discValInp = document.getElementById('discount_val');
    var discHidden = document.getElementById('discount');
    var disc = 0;
    if (discType && discValInp && discHidden) {
      var raw = parseFloat(discValInp.value) || 0;
      disc = discType.value === 'percent' ? (sub * raw / 100) : raw;
      discHidden.value = disc.toFixed(2);
    } else {
      disc = parseFloat((discHidden || {}).value) || 0;
    }
    var shipping = parseFloat((document.getElementById('shipping') || {}).value) || 0;
    var total = sub - disc + tax + shipping;
    var set = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = v.toFixed(2); };
    set('t_sub', sub); set('t_tax', tax); set('t_ship', shipping); set('t_grand', total);
    // live "how many items on this bill" line (lines + total quantity)
    var ti = document.getElementById('t_items');
    if (ti) ti.textContent = lines + ' item' + (lines === 1 ? '' : 's') + ' · ' + (Math.round(qtyTotal * 100) / 100) + ' qty';
    var due = document.getElementById('t_due');
    if (due) {
      var paid = parseFloat((document.getElementById('paid') || {}).value) || 0;
      due.textContent = (total - paid).toFixed(2);
    }
  }
};

// mark paid = total shortcut
function payFull() {
  var g = document.getElementById('t_grand');
  var p = document.getElementById('paid');
  if (g && p) { p.value = g.textContent; Bill.totals(); }
}

// ----- signature pad (digital job card sign-off) -----
var SignaturePad = {
  init: function (canvasId, hiddenId, clearBtnId) {
    var canvas = document.getElementById(canvasId);
    var input = document.getElementById(hiddenId);
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    ctx.strokeStyle = '#1e293b'; ctx.lineWidth = 2.5; ctx.lineJoin = 'round'; ctx.lineCap = 'round';
    var drawing = false, last = null, hasDrawn = false;
    function pos(ev) {
      var r = canvas.getBoundingClientRect();
      var t = ev.touches && ev.touches.length ? ev.touches[0] : ev;
      return { x: (t.clientX - r.left) * (canvas.width / r.width), y: (t.clientY - r.top) * (canvas.height / r.height) };
    }
    function start(ev) { drawing = true; last = pos(ev); ev.preventDefault(); }
    function move(ev) {
      if (!drawing) return;
      var p = pos(ev);
      ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
      last = p; hasDrawn = true; ev.preventDefault();
    }
    function end() { if (drawing) { drawing = false; if (input && hasDrawn) input.value = canvas.toDataURL('image/png'); } }
    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    window.addEventListener('mouseup', end);
    canvas.addEventListener('touchstart', start, { passive: false });
    canvas.addEventListener('touchmove', move, { passive: false });
    canvas.addEventListener('touchend', end);
    var clearBtn = clearBtnId ? document.getElementById(clearBtnId) : null;
    if (clearBtn) clearBtn.addEventListener('click', function () {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      hasDrawn = false;
      if (input) input.value = '';
    });
  }
};
