<?php
// The starting rows every new shop needs: roles, one location, a firm (plain
// and GST), credit terms, payment methods, a few settings and the admin who
// will log in. Used by the web installer and by every shop that signs up,
// so the two can never start differently.
// Plain PDO on purpose: the installer runs before anything else is loaded.

function shop_seed(PDO $pdo, $appName, $adminName, $adminUser, $adminMobile, $passHash, $city = '', $adminEmail = '') {
    // already seeded = it has a user. (Not "has a role": a migration may add a
    // ready-made role such as the CA's before the first seed runs.)
    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()) return false;
    if ((int)$pdo->query("SELECT COUNT(*) FROM roles WHERE name = 'Admin'")->fetchColumn()) goto seeded_roles;
    $allPerm = json_encode(['*']);
    $mk = function ($arr) { return json_encode($arr); };
    $ins = $pdo->prepare('INSERT INTO roles (name, permissions, is_system) VALUES (?, ?, ?)');
    $ins->execute(['Admin', $allPerm, 1]);
    $ins->execute(['Manager', $mk(['dashboard.view','sales.view','sales.add','sales.edit','sales.all','estimates.view','estimates.add','estimates.all','sales_return.view','sales_return.add','purchases.view','purchases.add','purchases.all','purchase_return.view','purchase_return.add','items.view','items.add','items.edit','parties.view','parties.contact','parties.add','parties.edit','stock.view','stock.adjust','handover.view','handover.add','handover.accept','handover.all','tasks.view','tasks.add','tasks.edit','tasks.all','repairs.view','repairs.add','repairs.edit','warranty.view','warranty.add','warranty.edit','payments.view','payments.add','expenses.view','expenses.add','challans.view','challans.add','challans.edit','weborders.view','weborders.edit','reports.view','reports.profit','reports.gst']), 1]);
    $ins->execute(['Sales Staff', $mk(['dashboard.view','sales.view','sales.add','estimates.view','estimates.add','sales_return.view','sales_return.add','items.view','parties.view','parties.contact','parties.add','stock.view','repairs.view','repairs.add','warranty.view','warranty.add','payments.view','payments.add']), 1]);
    $ins->execute(['Purchase Staff', $mk(['dashboard.view','purchases.view','purchases.add','purchase_return.view','purchase_return.add','items.view','items.add','parties.view','parties.contact','parties.add','stock.view']), 1]);
    $ins->execute(['Accounts', $mk(['dashboard.view','sales.view','sales.all','purchases.view','purchases.all','payments.view','payments.add','payments.delete','expenses.view','expenses.add','expenses.delete','parties.view','parties.contact','reports.view','reports.gst']), 1]);
    $ins->execute(['Field Staff', $mk(['dashboard.view','tasks.view','tasks.edit','handover.view','handover.add']), 1]);
    seeded_roles:

    $pdo->prepare("INSERT INTO locations (name, code, city, type, address) VALUES ('Main Shop', 'MAIN', ?, 'shop', '')")->execute([$city]);
    $locId = (int)$pdo->lastInsertId();
    $co = $pdo->prepare('INSERT INTO companies (name, gstin, is_gst, invoice_prefix) VALUES (?, ?, ?, ?)');
    $co->execute([$appName, '', 0, 'INV']);
    $co->execute([$appName . ' (GST)', '', 1, 'GST']);

    foreach ([[0,'Cash / No credit'],[7,'7 days'],[15,'15 days'],[30,'30 days'],[45,'45 days'],[60,'60 days'],[90,'90 days']] as $ct)
        $pdo->prepare('INSERT IGNORE INTO credit_terms (days, label) VALUES (?, ?)')->execute($ct);
    foreach ([['cash','Cash','cash',1,1], ['upi','UPI','other',1,2], ['card','Card','other',1,3],
              ['bank','Bank Transfer','bank',1,4], ['cheque','Cheque','other',1,5], ['credit','Credit / Udhar','other',1,6]] as $pm)
        $pdo->prepare('INSERT IGNORE INTO payment_methods (code, name, type, is_system, sort_order) VALUES (?,?,?,?,?)')->execute($pm);

    $set = $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
    foreach ([['app_name', $appName], ['wa_api_url', 'https://bulk.akdwk.in/api.php'], ['wa_session_id', ''], ['wa_api_key', ''],
              ['login_otp', '0'], ['default_tax', '18']] as $s) $set->execute($s);

    $adminRole = (int)$pdo->query("SELECT id FROM roles WHERE name = 'Admin'")->fetchColumn();
    $pdo->prepare('INSERT INTO users (name, username, mobile, password, role_id, location_id) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$adminName, $adminUser, $adminMobile, $passHash, $adminRole, $locId]);
    // the owner can log in with their e-mail and get reset links there (v90)
    if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        try { $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([mb_strtolower($adminEmail), (int)$pdo->lastInsertId()]); } catch (Exception $e) {}
    }
    return true;
}
