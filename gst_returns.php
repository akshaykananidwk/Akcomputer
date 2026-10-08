<?php
// 🧮 GST returns: GSTR-1 and GSTR-3B for a month, e-invoice and e-way bill
// files for a bill, TDS on purchases, and composition mode. Each is worked
// out from the bills and downloaded as the portal's own JSON - the CA or
// the owner uploads it; nothing is filed from here.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/gst.php';
require_once __DIR__ . '/includes/books.php';
require_perm('reports.gst');
$tab = get('tab', 'r1');
$month = preg_match('/^\d{4}-\d{2}$/', (string)get('m')) ? get('m') : date('Y-m', strtotime('first day of last month'));
$cos = all('SELECT * FROM companies WHERE is_active = 1 AND is_gst = 1 ORDER BY id');
$coId = (int)get('co') ?: (int)($cos[0]['id'] ?? 0);

function gst_download($name, $data) {
    header('Content-Type: application/json'); header('Content-Disposition: attachment; filename="' . $name . '"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}
if (get('dl') === 'r1') { log_activity('gstr1_download', $month); gst_download("GSTR1_{$month}.json", gstr1($coId, $month)); }
if (get('dl') === 'r3b') { log_activity('gstr3b_download', $month); gst_download("GSTR3B_{$month}.json", gstr3b($coId, $month)); }
if (get('dl') === 'einv' && ($j = einvoice_json((int)get('sale')))) gst_download('einvoice_' . preg_replace('/\W/', '_', $j['DocDtls']['No']) . '.json', $j);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'eway' && ($j = eway_json((int)post('sale'), $_POST))) gst_download('ewaybill_' . (int)post('sale') . '.json', $j);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'settings') {
    require_perm('settings.edit');
    set_setting('gst_composition', post('gst_composition') ? '1' : '0');
    set_setting('composition_rate', (string)max(0, min(6, (float)post('composition_rate'))));
    set_setting('tds_194q_limit', (string)max(0, (float)post('tds_194q_limit')));
    set_setting('tds_194q_rate', (string)max(0, min(5, (float)post('tds_194q_rate'))));
    if (preg_match('/^\d{6}$/', (string)post('shop_pin'))) set_setting('shop_pin', post('shop_pin'));
    log_activity('gst_settings', '');
    flash('Saved.');
    redirect('gst_returns.php?tab=' . urlencode($tab));
}

$page_title = 'GST returns';
include __DIR__ . '/includes/header.php';
$tabs = ['r1' => 'GSTR-1', 'r3b' => 'GSTR-3B', 'einv' => 'e-Invoice', 'eway' => 'e-Way bill', 'tds' => 'TDS (194Q)', 'set' => 'Composition & settings'];
?>
<div class="page-head"><h1>🧮 GST returns</h1>
  <form style="display:flex;gap:6px"><input type="hidden" name="tab" value="<?= e($tab) ?>"><input type="month" name="m" value="<?= e($month) ?>" onchange="this.form.submit()">
  <?php if (count($cos) > 1): ?><select name="co" onchange="this.form.submit()"><?php foreach ($cos as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $c['id'] == $coId ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select><?php endif; ?></form></div>
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px"><?php foreach ($tabs as $k => $l): ?><a class="btn btn-sm <?= $tab === $k ? '' : 'btn-outline' ?>" href="gst_returns.php?tab=<?= $k ?>&amp;m=<?= e($month) ?>&amp;co=<?= $coId ?>"><?= $l ?></a><?php endforeach; ?></div>
<?php if (!$cos): ?><div class="card">There is no GST firm yet. Put your GSTIN in <a href="companies.php">Companies / Firms</a>.</div>
<?php elseif ($tab === 'r1'): $r = gstr1($coId, $month); ?>
<div class="card"><p style="margin-top:0">GSTIN <b><?= e($r['gstin'] ?: '— not set') ?></b> · period <?= e(date('F Y', strtotime("$month-01"))) ?></p>
  <a class="btn" href="gst_returns.php?dl=r1&amp;m=<?= e($month) ?>&amp;co=<?= $coId ?>">⬇️ Download GSTR-1 JSON</a>
  <p class="muted" style="font-size:13px">Upload it in the GST portal → Returns → GSTR-1 → Prepare offline. Sale returns (credit notes) are not in this file yet — add them on the portal.</p></div>
<div class="pane"><div class="pane-head"><h3>B2B — bills to GST-registered buyers (<?= count($r['b2b']) ?> buyers)</h3></div><div class="pane-body tight"><table class="rowlist"><thead><tr><th>Buyer GSTIN</th><th>Bill</th><th class="num">Taxable</th><th class="num">IGST</th><th class="num">CGST</th><th class="num">SGST</th></tr></thead><tbody>
<?php foreach ($r['b2b'] as $c) foreach ($c['inv'] as $i) foreach ($i['itms'] as $it): $d = $it['itm_det']; ?>
  <tr><td data-l="GSTIN"><?= e($c['ctin']) ?></td><td data-l="Bill"><?= e($i['inum']) ?> · <?= e($i['idt']) ?></td><td class="num">₹<?= money($d['txval']) ?></td><td class="num"><?= money($d['iamt']) ?></td><td class="num"><?= money($d['camt']) ?></td><td class="num"><?= money($d['samt']) ?></td></tr>
<?php endforeach; if (!$r['b2b']): ?><tr><td class="muted">None this month.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="pane"><div class="pane-head"><h3>B2C — everyone else, by rate</h3></div><div class="pane-body tight"><table class="rowlist"><thead><tr><th>Type</th><th class="num">Rate</th><th class="num">Taxable</th><th class="num">IGST</th><th class="num">CGST</th><th class="num">SGST</th></tr></thead><tbody>
<?php foreach ($r['b2cs'] as $b): ?><tr><td><?= e($b['sply_ty']) ?></td><td class="num"><?= +$b['rt'] ?>%</td><td class="num">₹<?= money($b['txval']) ?></td><td class="num"><?= money($b['iamt']) ?></td><td class="num"><?= money($b['camt']) ?></td><td class="num"><?= money($b['samt']) ?></td></tr><?php endforeach; ?>
<?php if (!$r['b2cs']): ?><tr><td class="muted">None this month.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="pane"><div class="pane-head"><h3>HSN summary</h3></div><div class="pane-body tight"><table class="rowlist"><thead><tr><th>HSN</th><th>Item</th><th class="num">Qty</th><th class="num">Taxable</th><th class="num">Tax</th></tr></thead><tbody>
<?php $noHsn = 0; foreach ($r['hsn']['data'] as $h): if ($h['hsn_sc'] === '') $noHsn++; ?><tr><td><?= $h['hsn_sc'] !== '' ? e($h['hsn_sc']) : '<span class="badge badge-warn">missing</span>' ?></td><td><?= e($h['desc']) ?></td><td class="num"><?= +$h['qty'] ?> <?= e($h['uqc']) ?></td><td class="num">₹<?= money($h['txval']) ?></td><td class="num"><?= money($h['iamt'] + $h['camt'] + $h['samt']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php if ($noHsn): ?><div class="flash flash-warn"><?= $noHsn ?> item(s) have no HSN code — add it on the item before filing.</div><?php endif; ?>

<?php elseif ($tab === 'r3b'): $r = gstr3b($coId, $month); $o = $r['sup_details']['osup_det']; $itc = $r['itc_elg']['itc_avl'][0]; ?>
<div class="card"><table class="rowlist"><thead><tr><th></th><th class="num">Taxable</th><th class="num">IGST</th><th class="num">CGST</th><th class="num">SGST</th></tr></thead><tbody>
  <tr><td>3.1 (a) Sales</td><td class="num">₹<?= money($o['txval']) ?></td><td class="num"><?= money($o['iamt']) ?></td><td class="num"><?= money($o['camt']) ?></td><td class="num"><?= money($o['samt']) ?></td></tr>
  <tr><td>4 (A) Input tax credit (purchases)</td><td></td><td class="num"><?= money($itc['iamt']) ?></td><td class="num"><?= money($itc['camt']) ?></td><td class="num"><?= money($itc['samt']) ?></td></tr>
  <tr><td><b>To pay in cash (about)</b></td><td></td><td class="num"><b><?= money($r['payable']['iamt']) ?></b></td><td class="num"><b><?= money($r['payable']['camt']) ?></b></td><td class="num"><b><?= money($r['payable']['samt']) ?></b></td></tr>
</tbody></table>
<a class="btn mt" href="gst_returns.php?dl=r3b&amp;m=<?= e($month) ?>&amp;co=<?= $coId ?>">⬇️ Download GSTR-3B JSON</a>
<p class="muted" style="font-size:13px">Input credit is the GST on this firm's purchase bills of the month; check it against GSTR-2B on the portal before filing.</p></div>

<?php elseif ($tab === 'einv' || $tab === 'eway'): [$f, $t] = gst_month_range($month);
  $bills = all("SELECT s.id, s.invoice_no, s.sale_date, s.total, COALESCE(p.name, s.customer_name) who, p.gstin FROM sales s LEFT JOIN parties p ON p.id = s.party_id
                WHERE s.company_id = ? AND s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? " . ($tab === 'einv' ? "AND p.gstin <> ''" : "AND s.total >= 50000") . " ORDER BY s.sale_date DESC", [$coId, $f, $t]); ?>
<div class="card muted" style="font-size:13px"><?= $tab === 'einv'
  ? 'e-Invoice is needed for B2B bills when the yearly turnover is above ₹5 crore. Download the JSON and upload it on einvoice1.gst.gov.in (bulk upload) to get the IRN.'
  : 'An e-way bill is needed when goods worth ₹50,000 or more move. Fill the transport and download the JSON for ewaybillgst.gov.in → bulk generation.' ?></div>
<div class="pane"><div class="pane-body tight"><table class="rowlist"><tbody>
<?php foreach ($bills as $b): ?><tr><td data-l="Bill"><b><?= e($b['invoice_no']) ?></b> · <?= dmy($b['sale_date']) ?><br><small class="muted"><?= e($b['who']) ?> <?= e($b['gstin']) ?></small></td><td class="num" data-l="Total">₹<?= money($b['total']) ?></td>
  <td class="act"><?php if ($tab === 'einv'): ?><a class="btn btn-sm" href="gst_returns.php?dl=einv&amp;sale=<?= (int)$b['id'] ?>">⬇️ JSON</a>
  <?php else: ?><form method="post" style="display:flex;gap:4px;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="do" value="eway"><input type="hidden" name="sale" value="<?= (int)$b['id'] ?>">
    <input name="vehicle" placeholder="Vehicle no." style="width:110px" required><input name="distance" type="number" min="1" placeholder="km" style="width:70px" required>
    <input name="to_pin" placeholder="To PIN" style="width:80px" pattern="\d{6}"><button class="btn btn-sm">⬇️ JSON</button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$bills): ?><tr><td class="muted">No such bills this month.</td></tr><?php endif; ?></tbody></table></div></div>

<?php elseif ($tab === 'tds'): [$fs, , $fyl] = fy_of(get('fy') ?: today()); $rows = tds_194q($fs); ?>
<div class="card muted" style="font-size:13px">Section 194Q: if your last year's turnover was above ₹10 crore, deduct <?= +setting('tds_194q_rate', '0.1') ?>% on purchases from a supplier above ₹<?= money(setting('tds_194q_limit', '5000000')) ?> in the year (before GST). Financial year <?= e($fyl) ?>.</div>
<div class="pane"><div class="pane-body tight"><table class="rowlist"><thead><tr><th>Supplier</th><th class="num">Purchases</th><th class="num">Above limit</th><th class="num">TDS</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td data-l="Supplier"><b><?= e($r['party']) ?></b> <small class="muted"><?= e($r['gstin']) ?></small></td><td class="num">₹<?= money($r['purchases']) ?></td><td class="num"><?= $r['over_limit'] > 0 ? '₹' . money($r['over_limit']) : '—' ?></td><td class="num"><?= $r['tds'] > 0 ? '<b>₹' . money($r['tds']) . '</b>' : '—' ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td class="muted">No purchases this year.</td></tr><?php endif; ?></tbody></table></div></div>

<?php else: $q = intdiv(((int)date('n') + 8) % 12, 3); [$fs] = fy_of(); $qs = date('Y-m-d', strtotime($fs . ' +' . (3 * $q) . ' months'));   // April = quarter 0
  $qe = date('Y-m-d', strtotime($qs . ' +3 months -1 day'));
  $turn = (float)val('SELECT COALESCE(SUM(total), 0) FROM sales WHERE is_cancelled = 0 AND sale_date BETWEEN ? AND ?', [$qs, $qe]); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="do" value="settings">
  <label class="check-inline"><input type="checkbox" name="gst_composition" value="1" <?= biz_on('gst_composition') ? 'checked' : '' ?>> <b>Composition dealer</b> — bills charge no GST and say "Bill of Supply"</label>
  <div class="form-row cols-4 mt">
    <div><label>Composition rate %</label><input type="number" step="0.01" name="composition_rate" value="<?= e(setting('composition_rate', '1')) ?>"></div>
    <div><label>194Q limit ₹</label><input type="number" name="tds_194q_limit" value="<?= e(setting('tds_194q_limit', '5000000')) ?>"></div>
    <div><label>194Q rate %</label><input type="number" step="0.01" name="tds_194q_rate" value="<?= e(setting('tds_194q_rate', '0.1')) ?>"></div>
    <div><label>Shop PIN code</label><input type="text" name="shop_pin" pattern="\d{6}" value="<?= e(setting('shop_pin', '')) ?>"></div>
  </div><?php if (can('settings.edit')): ?><button class="btn" type="submit">Save</button><?php endif; ?></form>
<?php if (biz_on('gst_composition')): ?>
<div class="card"><h3 style="margin-top:0">CMP-08 — this quarter (<?= dmy($qs) ?> to <?= dmy($qe) ?>)</h3>
  <p>Turnover ₹<?= money($turn) ?> × <?= +setting('composition_rate', '1') ?>% = <b>₹<?= money(round($turn * (float)setting('composition_rate', '1') / 100, 2)) ?></b> tax to pay (CGST and SGST half each).</p></div>
<?php endif; endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
