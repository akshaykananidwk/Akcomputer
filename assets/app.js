// AK Computer - app JS (menu, billing rows, item search)

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
    var q = inp.value.toLowerCase();
    tbl.querySelectorAll('tbody tr').forEach(function (tr) {
      tr.style.display = tr.textContent.toLowerCase().indexOf(q) > -1 ? '' : 'none';
    });
  });
}

// ================= Billing widget =================
// Used by sales / purchases / estimates / returns / tasks.
// Config via initBill({mode:'sale'|'purchase', priceField:'selling_price'|...})
var Bill = {
  cfg: null, rowN: 0,

  init: function (cfg) {
    this.cfg = cfg;
    var self = this;
    var addBtn = document.getElementById('addRowBtn');
    if (addBtn) addBtn.addEventListener('click', function () { self.addRow(); });
    this.addRow();
    ['discount', 'paid'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener('input', function () { self.totals(); });
    });
    var pt = document.getElementById('price_type');
    if (pt) pt.addEventListener('change', function () { self.repriceAll(); });
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
    div.innerHTML =
      '<div class="row-line">' +
      '<div class="cell-item isearch-wrap">' +
      '  <label>Item</label>' +
      '  <input type="text" class="i-search" placeholder="Type item name..." autocomplete="off">' +
      '  <input type="hidden" name="item_id[]" class="i-id">' +
      '  <input type="hidden" name="tax_rate[]" class="i-tax" value="0">' +
      '  <div class="isearch-results"></div>' +
      '</div>' +
      '<div><label>Qty</label><input type="number" step="any" min="0" name="qty[]" class="i-qty" value="1"></div>' +
      (this.cfg.freeQty ? '<div><label>Free</label><input type="number" step="any" min="0" name="free_qty[]" class="i-freeq" value="0" title="Free quantity (scheme)"></div>' : '') +
      '<div><label>Price</label><input type="number" step="any" min="0" name="price[]" class="i-price" value="0"></div>' +
      '<div><label>Total</label><input type="text" class="i-total" value="0.00" readonly tabindex="-1"></div>' +
      '<div><button type="button" class="row-del" title="Remove">✕</button></div>' +
      '</div>' +
      '<div class="i-extra"></div>' +
      '<div class="muted i-stockinfo"></div>';
    wrap.appendChild(div);

    div.querySelector('.row-del').addEventListener('click', function () {
      div.remove(); self.totals();
    });
    ['.i-qty', '.i-price'].forEach(function (sel) {
      div.querySelector(sel).addEventListener('input', function () { self.rowTotal(div); });
    });
    this.attachSearch(div);
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
        fetch('ajax.php?a=item_search&q=' + encodeURIComponent(qy) + '&loc=' + (self.cfg.locSel ? document.getElementById(self.cfg.locSel).value : ''))
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
              d.innerHTML = '<strong>' + it.name + '</strong><small>Stock: ' + it.stock +
                ' | Retail: ' + it.selling_price + ' | B2B: ' + it.b2b_price +
                (it.serial_tracked == 1 ? ' | Serial-tracked' : '') + '</small>';
              d.addEventListener('click', function () { self.pickItem(div, it); });
              res.appendChild(d);
            });
            // Vyapar-style: "Add New Item" link at the bottom of results
            var addNew = document.createElement('div');
            addNew.className = 'ir';
            addNew.innerHTML = '<strong style="color:var(--primary)">＋ Add New Item</strong><small>"' + qy + '" નવી item બનાવો</small>';
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

  pickItem: function (div, it) {
    var self = this;
    div.querySelector('.i-search').value = it.name;
    div.querySelector('.i-id').value = it.id;
    div.querySelector('.i-tax').value = it.tax_rate;
    div.querySelector('.i-price').value = it[this.priceField()] || 0;
    div.querySelector('.isearch-results').classList.remove('show');
    div.dataset.serialTracked = it.serial_tracked;
    div.dataset.stock = it.stock;
    div.querySelector('.i-stockinfo').textContent =
      (this.cfg.mode === 'sale' || this.cfg.mode === 'staff') ? 'Available: ' + it.stock + ' ' + it.unit : '';

    var extra = div.querySelector('.i-extra');
    extra.innerHTML = '';
    if (it.serial_tracked == 1 && this.cfg.serials) {
      if (this.cfg.mode === 'purchase') {
        extra.innerHTML = '<label class="mt">Serial numbers (one per line, count = qty)</label>' +
          '<textarea name="serials[]" rows="2" placeholder="SN001\nSN002"></textarea>';
        div.dataset.hasSerialBox = '1';
      } else if (this.cfg.mode === 'sale') {
        // Vyapar-style serial picker: scan/type + Add, checkbox list, counter
        var loc = this.cfg.locSel ? document.getElementById(this.cfg.locSel).value : '';
        var n = div.dataset.n;
        fetch('ajax.php?a=serials&item_id=' + it.id + '&loc=' + loc)
          .then(function (r) { return r.json(); })
          .then(function (sns) {
            var boxes = sns.map(function (s) {
              return '<label class="sp-row"><input type="checkbox" name="serial_sel[' + n + '][]" value="' + s + '"> ' + s + '</label>';
            }).join('');
            extra.innerHTML =
              '<div class="serial-pick mt">' +
              '<label>Select Serial No. <span class="sp-count badge badge-warn">0 / ' + (parseFloat(div.querySelector('.i-qty').value) || 1) + ' entered</span></label>' +
              '<div class="sp-scan"><input type="text" class="sp-inp" placeholder="Type / scan serial no.">' +
              '<button type="button" class="btn btn-sm sp-add">Add</button></div>' +
              '<div class="sp-list">' + (boxes || '<span class="muted">Stock માં serial નથી (advance billing ચાલશે)</span>') + '</div>' +
              '</div>';
            function updCount() {
              var c = extra.querySelectorAll('input[type=checkbox]:checked').length;
              var need = parseFloat(div.querySelector('.i-qty').value) || 0;
              var el = extra.querySelector('.sp-count');
              el.textContent = c + ' / ' + need + ' entered';
              el.className = 'sp-count badge ' + (c == need ? 'badge-ok' : 'badge-warn');
            }
            extra.addEventListener('change', updCount);
            div.querySelector('.i-qty').addEventListener('input', updCount);
            var spInp = extra.querySelector('.sp-inp');
            spInp.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); extra.querySelector('.sp-add').click(); } });
            extra.querySelector('.sp-add').addEventListener('click', function () {
              var v = spInp.value.trim();
              if (!v) return;
              var found = false;
              extra.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
                if (cb.value.toLowerCase() === v.toLowerCase()) { cb.checked = true; found = true; }
              });
              if (!found) alert('Serial "' + v + '" stock માં નથી.');
              spInp.value = '';
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

  rowTotal: function (div) {
    var qty = parseFloat(div.querySelector('.i-qty').value) || 0;
    var price = parseFloat(div.querySelector('.i-price').value) || 0;
    div.querySelector('.i-total').value = (qty * price).toFixed(2);
    this.totals();
  },

  totals: function () {
    var sub = 0, tax = 0;
    var gst = this.cfg.gst;
    document.querySelectorAll('#billItems .bill-row').forEach(function (div) {
      var qty = parseFloat(div.querySelector('.i-qty').value) || 0;
      var price = parseFloat(div.querySelector('.i-price').value) || 0;
      var tr = parseFloat(div.querySelector('.i-tax').value) || 0;
      var line = qty * price;
      sub += line;
      if (gst) tax += line * tr / 100;
    });
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
    var total = sub - disc + tax;
    var set = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = v.toFixed(2); };
    set('t_sub', sub); set('t_tax', tax); set('t_grand', total);
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
