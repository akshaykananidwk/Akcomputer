<?php
// AK Computer - Web installer
// Creates config.php, database tables, default roles/settings and the admin user.
error_reporting(E_ALL);
ini_set('display_errors', '1');
session_start();

$root = dirname(__DIR__);
if (file_exists($root . '/config.php')) {
    // already installed - require confirmation flag to re-run
    if (!isset($_GET['force'])) {
        die('Already installed. Delete config.php (or open install/?force=1) to re-install.');
    }
    require_once $root . '/config.php';
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_host = trim($_POST['db_host'] ?? 'localhost');
    $db_name = trim($_POST['db_name'] ?? '');
    $db_user = trim($_POST['db_user'] ?? '');
    $db_pass = $_POST['db_pass'] ?? '';
    $base_url = rtrim(trim($_POST['base_url'] ?? ''), '/');
    $app_name = trim($_POST['app_name'] ?? 'AK Computer');
    $a_name = trim($_POST['admin_name'] ?? 'Admin');
    $a_user = trim($_POST['admin_user'] ?? 'admin');
    $a_mobile = trim($_POST['admin_mobile'] ?? '');
    $a_pass = $_POST['admin_pass'] ?? '';

    try {
        if ($db_name === '' || $a_pass === '' || $a_user === '') throw new Exception('DB name, admin username and password are required.');

        $pdo = new PDO("mysql:host=$db_host;charset=utf8mb4", $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$db_name`");

        // run schema
        $sql = file_get_contents(__DIR__ . '/schema.sql');
        foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $stmt) {
            if ($stmt !== '') $pdo->exec($stmt);
        }

        // write config.php
        $secret = bin2hex(random_bytes(24));
        $cfg = "<?php\n"
            . "define('DB_HOST', " . var_export($db_host, true) . ");\n"
            . "define('DB_NAME', " . var_export($db_name, true) . ");\n"
            . "define('DB_USER', " . var_export($db_user, true) . ");\n"
            . "define('DB_PASS', " . var_export($db_pass, true) . ");\n"
            . "define('BASE_URL', " . var_export($base_url, true) . ");\n"
            . "define('APP_SECRET', " . var_export($secret, true) . ");\n"
            . "define('APP_TZ', 'Asia/Kolkata');\n";
        if (file_put_contents($root . '/config.php', $cfg) === false) {
            throw new Exception('Could not write config.php - check folder write permission.');
        }

        // ---- seed data (only when empty) ----
        $hasRoles = (int)$pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn();
        if (!$hasRoles) {
            $allPerm = json_encode(['*']);
            $mk = function ($arr) { return json_encode($arr); };
            $ins = $pdo->prepare('INSERT INTO roles (name, permissions, is_system) VALUES (?, ?, ?)');
            $ins->execute(['Admin', $allPerm, 1]);
            $ins->execute(['Manager', $mk(['dashboard.view','sales.view','sales.add','sales.edit','sales.all','estimates.view','estimates.add','estimates.all','sales_return.view','sales_return.add','purchases.view','purchases.add','purchases.all','purchase_return.view','purchase_return.add','items.view','items.add','items.edit','parties.view','parties.add','parties.edit','stock.view','stock.adjust','handover.view','handover.add','handover.accept','handover.all','tasks.view','tasks.add','tasks.edit','tasks.all','repairs.view','repairs.add','repairs.edit','warranty.view','warranty.add','warranty.edit','payments.view','payments.add','expenses.view','expenses.add','challans.view','challans.add','challans.edit','weborders.view','weborders.edit','reports.view','reports.profit','reports.gst']), 1]);
            $ins->execute(['Sales Staff', $mk(['dashboard.view','sales.view','sales.add','estimates.view','estimates.add','sales_return.view','sales_return.add','items.view','parties.view','parties.add','stock.view','repairs.view','repairs.add','warranty.view','warranty.add','payments.view','payments.add']), 1]);
            $ins->execute(['Purchase Staff', $mk(['dashboard.view','purchases.view','purchases.add','purchase_return.view','purchase_return.add','items.view','items.add','parties.view','parties.add','stock.view']), 1]);
            $ins->execute(['Accounts', $mk(['dashboard.view','sales.view','sales.all','purchases.view','purchases.all','payments.view','payments.add','payments.delete','expenses.view','expenses.add','expenses.delete','parties.view','reports.view','reports.gst']), 1]);
            $ins->execute(['Field Staff', $mk(['dashboard.view','tasks.view','tasks.edit','handover.view','handover.add']), 1]);

            // location + companies
            $pdo->exec("INSERT INTO locations (name, code, city, type, address) VALUES ('Main Shop', 'MAIN', 'Dwarka', 'shop', '')");
            $locId = (int)$pdo->lastInsertId();
            $pdo->exec("INSERT INTO companies (name, gstin, is_gst, invoice_prefix) VALUES ('" . addslashes($app_name) . "', '', 0, 'INV')");
            $pdo->exec("INSERT INTO companies (name, gstin, is_gst, invoice_prefix) VALUES ('" . addslashes($app_name) . " (GST)', '', 1, 'GST')");

            // credit terms
            foreach ([[0,'Cash / No credit'],[7,'7 days'],[15,'15 days'],[30,'30 days'],[45,'45 days'],[60,'60 days'],[90,'90 days']] as $ct) {
                $pdo->prepare('INSERT INTO credit_terms (days, label) VALUES (?, ?)')->execute($ct);
            }

            // payment methods
            foreach ([
                ['cash','Cash','cash',1,1], ['upi','UPI','other',1,2], ['card','Card','other',1,3],
                ['bank','Bank Transfer','bank',1,4], ['cheque','Cheque','other',1,5], ['credit','Credit / Udhar','other',1,6],
            ] as $pm) {
                $pdo->prepare('INSERT INTO payment_methods (code, name, type, is_system, sort_order) VALUES (?,?,?,?,?)')->execute($pm);
            }

            // settings
            $set = $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?)');
            foreach ([
                ['app_name', $app_name],
                ['wa_api_url', 'https://bulk.akdwk.in/api.php'],
                ['wa_session_id', ''],
                ['wa_api_key', ''],
                ['login_otp', '0'],
                ['default_tax', '18'],
            ] as $s) $set->execute($s);

            // admin user
            $adminRole = (int)$pdo->query("SELECT id FROM roles WHERE name = 'Admin'")->fetchColumn();
            $pdo->prepare('INSERT INTO users (name, username, mobile, password, role_id, location_id) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$a_name, $a_user, $a_mobile, password_hash($a_pass, PASSWORD_DEFAULT), $adminRole, $locId]);
        }

        echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<body style="font-family:sans-serif;padding:30px;max-width:500px;margin:auto">'
           . '<h2>✅ Installation complete!</h2>'
           . '<p>Login with username <strong>' . htmlspecialchars($a_user) . '</strong> and your password.</p>'
           . '<p><strong>Important:</strong> delete the <code>install/</code> folder from the server now.</p>'
           . '<p><a href="../login.php" style="display:inline-block;background:#1a56db;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none">Go to Login</a></p>';
        exit;
    } catch (Exception $ex) {
        $err = $ex->getMessage();
    }
}
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Install - AK Computer</title>
<style>
body{font-family:sans-serif;background:#f1f5f9;padding:20px}
.box{max-width:460px;margin:auto;background:#fff;padding:24px;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.1)}
h1{font-size:20px;margin:0 0 16px} h3{font-size:15px;margin:18px 0 8px;color:#334155}
label{display:block;font-size:13px;font-weight:600;margin:10px 0 4px}
input{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box;font-size:15px}
button{margin-top:18px;width:100%;background:#1a56db;color:#fff;border:0;padding:12px;border-radius:8px;font-size:16px;font-weight:600;cursor:pointer}
.err{background:#fee2e2;color:#991b1b;padding:10px;border-radius:8px;margin-bottom:10px;font-size:14px}
</style></head><body>
<div class="box">
<h1>🖥️ AK Computer - Install</h1>
<?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
<form method="post">
  <h3>Database (MySQL)</h3>
  <label>DB Host</label><input name="db_host" value="localhost" required>
  <label>DB Name</label><input name="db_name" value="akcomputer" required>
  <label>DB Username</label><input name="db_user" required>
  <label>DB Password</label><input name="db_pass" type="password">
  <h3>Application</h3>
  <label>Shop / App name</label><input name="app_name" value="AK Computer">
  <label>Base URL (e.g. https://billing.akdwk.in)</label><input name="base_url" placeholder="https://...">
  <h3>Admin account</h3>
  <label>Your name</label><input name="admin_name" value="Admin">
  <label>Username</label><input name="admin_user" value="admin" required>
  <label>WhatsApp Mobile (10 digit)</label><input name="admin_mobile" placeholder="98XXXXXXXX">
  <label>Password</label><input name="admin_pass" type="password" required>
  <button type="submit">Install Now</button>
</form>
</div></body></html>
