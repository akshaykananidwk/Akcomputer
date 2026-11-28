<?php
// Online store CUSTOMER accounts - separate from the dealer (B2B) logins in
// web_accounts, so a customer can never see dealer prices. Register with a
// mobile number or an e-mail, log in with a password or a one-time code,
// reset a forgotten password, keep profile + delivery addresses, see every
// order and its status, and keep the wishlist on the account.
// Guest checkout (no account) keeps working exactly as before.

/** The logged-in customer, or null. */
function sc_current() {
    static $seen = [];
    $id = (int)($_SESSION['store_cust_id'] ?? 0);
    if (!$id) return null;
    if (!array_key_exists($id, $seen)) {
        try { $seen[$id] = row('SELECT * FROM store_customers WHERE id = ? AND is_active = 1', [$id]) ?: null; } catch (Exception $e) { $seen[$id] = null; }
    }
    if (!$seen[$id]) unset($_SESSION['store_cust_id']);
    return $seen[$id];
}

/** "9978123146", "+91 99781 23146" -> mobile; "a@b.in" -> email. */
function sc_parse_login($s) {
    $s = trim((string)$s);
    if (strpos($s, '@') !== false) { $e = mb_strtolower($s); return filter_var($e, FILTER_VALIDATE_EMAIL) ? ['email', $e] : [null, '']; }
    $d = preg_replace('/\D/', '', $s);
    if (strlen($d) > 10 && (strpos($d, '91') === 0 || strpos($d, '0') === 0)) $d = substr($d, -10);
    return preg_match('/^[6-9]\d{9}$/', $d) ? ['mobile', $d] : [null, ''];
}
function sc_find($login) {
    [$k, $v] = sc_parse_login($login);
    return $k ? row("SELECT * FROM store_customers WHERE $k = ? AND is_active = 1", [$v]) : null;
}
/** The OTP target key: short, never the raw e-mail. */
function sc_otp_target($k, $v) { return $k === 'mobile' ? 'scm:' . $v : 'sce:' . substr(hash('sha256', $v), 0, 40); }

function sc_login_as($id) {
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    $_SESSION['store_cust_id'] = (int)$id;
    q('UPDATE store_customers SET last_login = NOW() WHERE id = ?', [(int)$id]);
}

/** Returns [ok, message]. */
function sc_register($name, $login, $password) {
    $name = trim(mb_substr((string)$name, 0, 120));
    [$k, $v] = sc_parse_login($login);
    if ($name === '') return [false, 'Please write your name.'];
    if (!$k) return [false, 'Write a 10-digit mobile number or a valid e-mail.'];
    if (mb_strlen((string)$password) < 6) return [false, 'Choose a password of at least 6 letters or numbers.'];
    if (!api_rate_ok('screg:' . client_ip(), 6, 3600)) return [false, 'Too many accounts from here — please try again later.'];
    if (row("SELECT id FROM store_customers WHERE $k = ?", [$v])) return [false, 'An account with this ' . ($k === 'mobile' ? 'mobile' : 'e-mail') . ' already exists — log in, or use "Forgot password".'];
    q("INSERT INTO store_customers (name, $k, password_hash) VALUES (?,?,?)", [$name, $v, password_hash($password, PASSWORD_DEFAULT)]);
    $id = (int)val('SELECT MAX(id) FROM store_customers WHERE ' . $k . ' = ?', [$v]);
    sc_login_as($id);
    return [true, 'Welcome, ' . $name . '! Your account is ready.'];
}

function sc_login_password($login, $password) {
    [$k, $v] = sc_parse_login($login);
    if (!$k) return [false, 'Write your mobile number or e-mail.'];
    $tk = 'store:' . client_ip() . ':' . $v;
    if (login_throttle_blocked($tk)) return [false, 'Too many wrong tries. Wait a few minutes, or log in with a code.'];
    $c = sc_find($v);
    if (!$c || !$c['password_hash'] || !password_verify((string)$password, $c['password_hash'])) { login_throttle_hit($tk); return [false, 'Mobile / e-mail or password is wrong.']; }
    login_throttle_reset($tk);
    sc_login_as($c['id']);
    return [true, 'Welcome back, ' . $c['name'] . '!'];
}

/** Sends a 6-digit code for 'login' or 'reset' (accounts only) or 'verify' (the logged-in customer's own mobile). */
function sc_send_otp($login, $purpose) {
    [$k, $v] = sc_parse_login($login);
    if (!$k) return [false, 'Write a 10-digit mobile number or a valid e-mail.'];
    $c = $purpose === 'verify' ? sc_current() : sc_find($v);
    // the same answer whether or not an account exists, so nobody can test numbers
    $sent = [true, 'If an account exists for this ' . ($k === 'mobile' ? 'number' : 'e-mail') . ', a code has been sent. It is valid for 10 minutes.'];
    if (!api_rate_ok('scotp:' . sc_otp_target($k, $v), 3, 900) || !api_rate_ok('scotpip:' . client_ip(), 10, 3600)) return [false, 'Too many codes asked for — please wait 15 minutes.'];
    if (!$c) return $sent;
    $code = create_otp('store_' . $purpose, sc_otp_target($k, $v), 10);
    $app = setting('app_name', 'AK Computer');
    $msg = "$code is your $app code" . ($purpose === 'reset' ? ' to set a new password' : '') . '. It is valid for 10 minutes. Do not share it with anyone.';
    $ok = $k === 'mobile' ? (bool)send_whatsapp('91' . $v, $msg) : (bool)send_mail($v, "$app — your code $code", '<p>' . e($msg) . '</p>', $msg);
    if (!$ok) return [false, 'The code could not be sent just now. Try the other way (mobile / e-mail) or ask the shop on WhatsApp.'];
    return $sent;
}

function sc_check_otp($login, $purpose, $code) {
    [$k, $v] = sc_parse_login($login);
    return $k && preg_match('/^\d{6}$/', trim((string)$code)) && verify_otp('store_' . $purpose, sc_otp_target($k, $v), $code);
}

/** Wishlist on the account: merge what the phone has, return the full list. */
function sc_wishlist_sync($cid, array $ids, $mode) {
    $ids = array_values(array_unique(array_filter(array_map('intval', array_slice($ids, 0, 300)))));
    if ($mode === 'replace') q('DELETE FROM store_wishlist WHERE customer_id = ?', [$cid]);
    foreach ($ids as $i) if (val('SELECT id FROM items WHERE id = ? AND is_active = 1 AND show_on_website = 1', [$i])) q('INSERT IGNORE INTO store_wishlist (customer_id, item_id) VALUES (?,?)', [$cid, $i]);
    return array_map('intval', array_column(all('SELECT item_id FROM store_wishlist WHERE customer_id = ? ORDER BY created_at', [$cid]), 'item_id'));
}

/** Every order of this customer: placed while logged in, or - once the mobile
 *  is verified - placed as a guest with the same mobile. */
function sc_orders($c) {
    $p = [(int)$c['id']]; $w = 'store_customer_id = ?';
    if ($c['mobile'] && (int)$c['mobile_verified']) { $w .= " OR (store_customer_id IS NULL AND RIGHT(REPLACE(REPLACE(mobile, ' ', ''), '+', ''), 10) = ?)"; $p[] = $c['mobile']; }
    return all("SELECT * FROM web_orders WHERE $w ORDER BY id DESC LIMIT 50", $p);
}
function sc_addresses($cid) { return all('SELECT * FROM store_addresses WHERE customer_id = ? ORDER BY is_default DESC, id DESC', [(int)$cid]); }

/** The steps an order goes through, for the little tracker. */
function sc_order_steps($status) {
    $s = mb_strtolower((string)$status);
    if ($s === 'cancelled') return [['Placed', true], ['Cancelled', true]];
    $order = ['new' => 0, 'pending' => 0, '' => 0, 'confirmed' => 1, 'processing' => 1, 'packed' => 2, 'shipped' => 2, 'dispatched' => 2, 'ready' => 2, 'delivered' => 3, 'completed' => 3];
    $at = $order[$s] ?? 1;
    return array_map(fn($i, $lab) => [$lab, $i <= $at], range(0, 3), ['Placed', 'Confirmed', 'Packed / ready', 'Delivered']);
}

/** All customer-account form posts, from catalog.php. Returns [flash text, is error, redirect]. */
function sc_handle_post() {
    $do = post('do');
    if (!in_array($do, ['sc_register', 'sc_login', 'sc_otp_send', 'sc_otp_login', 'sc_reset', 'sc_profile', 'sc_password', 'sc_addr', 'sc_addr_del', 'sc_verify_send', 'sc_verify', 'sc_logout', 'wish_sync'], true)) return null;
    if ($do === 'wish_sync') {
        header('Content-Type: application/json');
        $c = sc_current();
        if (!$c) { echo json_encode(['ok' => false]); exit; }
        echo json_encode(['ok' => true, 'ids' => sc_wishlist_sync($c['id'], (array)json_decode((string)post('ids'), true), post('mode') === 'replace' ? 'replace' : 'merge')]);
        exit;
    }
    $back = 'catalog.php?view=account';
    if ($do === 'sc_register') { [$ok, $m] = sc_register(post('name'), post('login'), post('password')); return [$m, !$ok, $ok ? $back : $back . '&a=register']; }
    if ($do === 'sc_login') { [$ok, $m] = sc_login_password(post('login'), post('password')); return [$m, !$ok, $ok ? (post('next') === 'cart' ? 'catalog.php?view=all' : $back) : $back . '&a=login']; }
    if ($do === 'sc_otp_send') {
        $_SESSION['sc_otp_login'] = trim((string)post('login'));
        [$ok, $m] = sc_send_otp(post('login'), post('purpose') === 'reset' ? 'reset' : 'login');
        return [$m, !$ok, $back . '&a=' . (post('purpose') === 'reset' ? 'reset' : 'otp') . '&step=2'];
    }
    if ($do === 'sc_otp_login') {
        $login = (string)($_SESSION['sc_otp_login'] ?? '');
        if (!sc_check_otp($login, 'login', post('code'))) return ['That code is wrong or has expired. Ask for a new one.', true, $back . '&a=otp&step=2'];
        $c = sc_find($login);
        if (!$c) return ['That code is wrong or has expired. Ask for a new one.', true, $back . '&a=otp'];
        [$k] = sc_parse_login($login);
        q('UPDATE store_customers SET ' . ($k === 'mobile' ? 'mobile_verified' : 'email_verified') . ' = 1 WHERE id = ?', [$c['id']]);
        unset($_SESSION['sc_otp_login']);
        sc_login_as($c['id']);
        return ['Welcome back, ' . $c['name'] . '!', false, $back];
    }
    if ($do === 'sc_reset') {
        $login = (string)($_SESSION['sc_otp_login'] ?? '');
        if (mb_strlen((string)post('password')) < 6) return ['Choose a password of at least 6 letters or numbers.', true, $back . '&a=reset&step=2'];
        if (!sc_check_otp($login, 'reset', post('code'))) return ['That code is wrong or has expired. Ask for a new one.', true, $back . '&a=reset&step=2'];
        $c = sc_find($login);
        if (!$c) return ['That code is wrong or has expired.', true, $back . '&a=reset'];
        [$k] = sc_parse_login($login);
        q('UPDATE store_customers SET password_hash = ?, ' . ($k === 'mobile' ? 'mobile_verified' : 'email_verified') . ' = 1 WHERE id = ?', [password_hash(post('password'), PASSWORD_DEFAULT), $c['id']]);
        unset($_SESSION['sc_otp_login']);
        sc_login_as($c['id']);
        return ['Your new password is set — you are logged in.', false, $back];
    }
    $c = sc_current();
    if (!$c) return ['Please log in first.', true, $back . '&a=login'];
    if ($do === 'sc_logout') { unset($_SESSION['store_cust_id']); return ['You are logged out.', false, 'catalog.php']; }
    if ($do === 'sc_profile') {
        $name = trim(mb_substr((string)post('name'), 0, 120));
        $email = mb_strtolower(trim((string)post('email')));
        if ($name === '') return ['Your name cannot be empty.', true, $back . '&tab=profile'];
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['That e-mail does not look right.', true, $back . '&tab=profile'];
        if ($email !== '' && val('SELECT id FROM store_customers WHERE email = ? AND id <> ?', [$email, $c['id']])) return ['Another account already uses that e-mail.', true, $back . '&tab=profile'];
        $mob = '';
        if (trim((string)post('mobile')) !== '') { [$mk, $mob] = sc_parse_login(post('mobile')); if ($mk !== 'mobile') return ['Write a 10-digit mobile number.', true, $back . '&tab=profile']; }
        if ($mob !== '' && val('SELECT id FROM store_customers WHERE mobile = ? AND id <> ?', [$mob, $c['id']])) return ['Another account already uses that mobile.', true, $back . '&tab=profile'];
        if (!$c['mobile'] && !$c['email'] && $mob === '' && $email === '') return ['Keep at least a mobile or an e-mail.', true, $back . '&tab=profile'];
        // MySQL assigns left to right: the "verified" flags are worked out from the OLD values first
        q('UPDATE store_customers SET email_verified = IF(email <=> ?, email_verified, 0), mobile_verified = IF(mobile <=> ?, mobile_verified, 0), name = ?, email = ?, mobile = ? WHERE id = ?',
          [$email ?: null, $mob ?: null, $name, $email ?: null, $mob ?: null, $c['id']]);
        return ['Profile saved.', false, $back . '&tab=profile'];
    }
    if ($do === 'sc_password') {
        if ($c['password_hash'] && !password_verify((string)post('old'), $c['password_hash'])) return ['Your current password is not right.', true, $back . '&tab=profile'];
        if (mb_strlen((string)post('password')) < 6) return ['Choose a password of at least 6 letters or numbers.', true, $back . '&tab=profile'];
        q('UPDATE store_customers SET password_hash = ? WHERE id = ?', [password_hash(post('password'), PASSWORD_DEFAULT), $c['id']]);
        return ['Password changed.', false, $back . '&tab=profile'];
    }
    if ($do === 'sc_addr') {
        $f = fn($k, $n) => trim(mb_substr((string)post($k), 0, $n));
        if ($f('address', 255) === '') return ['Write the address.', true, $back . '&tab=address'];
        $id = (int)post('id');
        $vals = [$f('label', 30) ?: 'Home', $f('aname', 120) ?: $c['name'], preg_replace('/\D/', '', $f('amobile', 15)) ?: (string)$c['mobile'], $f('address', 255), $f('area', 120), preg_replace('/\D/', '', $f('pincode', 10))];
        if ($id && val('SELECT id FROM store_addresses WHERE id = ? AND customer_id = ?', [$id, $c['id']])) q('UPDATE store_addresses SET label=?, name=?, mobile=?, address=?, area=?, pincode=? WHERE id = ?', array_merge($vals, [$id]));
        else { q('INSERT INTO store_addresses (label, name, mobile, address, area, pincode, customer_id) VALUES (?,?,?,?,?,?,?)', array_merge($vals, [$c['id']])); $id = (int)val('SELECT MAX(id) FROM store_addresses WHERE customer_id = ?', [$c['id']]); }
        if (post('is_default') || !val('SELECT COUNT(*) FROM store_addresses WHERE customer_id = ? AND is_default = 1', [$c['id']])) {
            q('UPDATE store_addresses SET is_default = (id = ?) WHERE customer_id = ?', [$id, $c['id']]);
        }
        return ['Address saved.', false, $back . '&tab=address'];
    }
    if ($do === 'sc_addr_del') { q('DELETE FROM store_addresses WHERE id = ? AND customer_id = ?', [(int)post('id'), $c['id']]); return ['Address removed.', false, $back . '&tab=address']; }
    if ($do === 'sc_verify_send') { [$ok, $m] = $c['mobile'] ? sc_send_otp($c['mobile'], 'verify') : [false, 'Add your mobile number first.']; return [$ok ? 'A code was sent to your WhatsApp.' : $m, !$ok, $back . '&tab=profile&verify=1']; }
    if ($do === 'sc_verify') {
        if (!$c['mobile'] || !sc_check_otp($c['mobile'], 'verify', post('code'))) return ['That code is wrong or has expired.', true, $back . '&tab=profile&verify=1'];
        q('UPDATE store_customers SET mobile_verified = 1 WHERE id = ?', [$c['id']]);
        return ['Mobile verified — your earlier orders with this number now show here too.', false, $back];
    }
    return null;
}

// ---------------------------------------------------------------------------
//  Pages
// ---------------------------------------------------------------------------
/** Login / register / code login / forgot password for customers. */
function sc_auth_body($a, $step) {
    $c = store_ctx();
    $login = (string)($_SESSION['sc_otp_login'] ?? '');
    $field = fn($name, $ico, $ph, $type = 'text', $extra = '') => '<div class="ifield">' . store_icon($ico) . '<input name="' . $name . '" type="' . $type . '" placeholder="' . e($ph) . '" aria-label="' . e($ph) . '" ' . $extra . '>'
        . ($type === 'password' ? '<button class="hbtn eye" type="button" data-eye aria-label="Show password">' . store_icon('eye') . '</button>' : '') . '</div>';
    $pw = fn($name, $ph, $ac) => str_replace('<input ', '<input data-pw ', $field($name, 'lock', $ph, 'password', 'required minlength="6" autocomplete="' . $ac . '"'));
    $sub = ['login' => 'Log in with password', 'otp' => 'Log in with a code', 'register' => 'Create account', 'reset' => 'Forgot password'];
    echo '<div class="chips" style="margin-bottom:14px;justify-content:center;flex-wrap:wrap">';
    foreach ($sub as $k => $lab) echo '<a class="chip ' . ($a === $k ? 'on' : '') . '" href="catalog.php?view=account&amp;a=' . $k . '">' . e($lab) . '</a>';
    echo '</div>';
    echo '<form method="post" action="catalog.php?view=account">' . csrf_field();
    if ($a === 'register') {
        echo '<input type="hidden" name="do" value="sc_register">' . $field('name', 'user', 'Your name', 'text', 'required autocomplete="name"')
           . $field('login', 'phone', 'Mobile number or e-mail', 'text', 'required autocomplete="username"') . $pw('password', 'Choose a password (6+)', 'new-password')
           . '<button class="btn block" type="submit">' . store_icon('user') . 'Create account</button>'
           . '<p class="muted" style="font-size:12.5px;text-align:center;margin-top:10px">Your number is used only for your orders and codes — never shared.</p>';
    } elseif ($a === 'otp' || $a === 'reset') {
        $purpose = $a === 'reset' ? 'reset' : 'login';
        if ($step !== '2') {
            echo '<input type="hidden" name="do" value="sc_otp_send"><input type="hidden" name="purpose" value="' . $purpose . '">'
               . $field('login', 'phone', 'Mobile number or e-mail', 'text', 'required autocomplete="username" value="' . e($login) . '"')
               . '<button class="btn block" type="submit">' . store_icon('wa') . 'Send code</button>'
               . '<p class="muted" style="font-size:12.5px;text-align:center;margin-top:10px">A 6-digit code comes on WhatsApp (mobile) or by e-mail.</p>';
        } else {
            echo '<input type="hidden" name="do" value="' . ($a === 'reset' ? 'sc_reset' : 'sc_otp_login') . '">'
               . '<p class="note info" style="margin-bottom:12px">Code sent to ' . e($login) . ' — <a href="catalog.php?view=account&amp;a=' . $a . '" style="text-decoration:underline">change</a></p>'
               . $field('code', 'lock', '6-digit code', 'text', 'required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"')
               . ($a === 'reset' ? $pw('password', 'New password (6+)', 'new-password') : '')
               . '<button class="btn block" type="submit">' . store_icon('check') . ($a === 'reset' ? 'Set new password' : 'Log in') . '</button>';
        }
    } else {
        echo '<input type="hidden" name="do" value="sc_login">' . $field('login', 'phone', 'Mobile number or e-mail', 'text', 'required autocomplete="username"')
           . $pw('password', 'Password', 'current-password')
           . '<div style="display:flex;justify-content:space-between;margin:-4px 0 12px;font-size:13px"><a style="color:var(--brand);font-weight:700" href="catalog.php?view=account&amp;a=otp">Log in with a code</a><a style="color:var(--brand);font-weight:700" href="catalog.php?view=account&amp;a=reset">Forgot password?</a></div>'
           . '<button class="btn block" type="submit">' . store_icon('next') . 'Login</button>';
    }
    echo '</form>';
    if ($a !== 'register') echo '<div class="newbox"><span><b>New to ' . e($c['app']) . '?</b><p>Create your account to save addresses, track orders and keep your wishlist.</p></span><a class="btn ghost" href="catalog.php?view=account&amp;a=register">' . store_icon('user') . 'Register</a></div>';
    echo '<p class="muted" style="font-size:13px;text-align:center;margin-top:14px">No account? You can still <a style="color:var(--brand);font-weight:700" href="catalog.php?view=all">shop as a guest</a> or <a style="color:var(--brand);font-weight:700" href="catalog.php?view=account&amp;tab=track">track an order</a>.</p>';
}

/** The logged-in customer's account: orders, addresses, profile, wishlist. */
function sc_account_body($cust) {
    $c = store_ctx();
    $tab = in_array(get('tab'), ['orders', 'address', 'profile'], true) ? get('tab') : 'orders';
    $orders = sc_orders($cust); $addrs = sc_addresses($cust['id']); ?>
  <section class="sec" style="max-width:820px">
    <div class="panel" style="display:flex;align-items:center;gap:12px">
      <span style="width:52px;height:52px;border-radius:50%;background:var(--brand);color:#fff;display:grid;place-items:center;font-weight:900;font-size:20px;flex:none"><?= e(mb_strtoupper(mb_substr($cust['name'], 0, 1))) ?></span>
      <div style="flex:1;min-width:0"><b style="font-size:17px"><?= e($cust['name']) ?></b><div class="muted" style="font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e(trim(($cust['mobile'] ? '+91 ' . $cust['mobile'] : '') . ($cust['email'] ? ' · ' . $cust['email'] : ''), ' ·')) ?></div></div>
      <form method="post" action="catalog.php?view=account"><?= csrf_field() ?><input type="hidden" name="do" value="sc_logout"><button class="btn ghost" style="height:40px" type="submit">Log out</button></form>
    </div>
    <?php if ($cust['mobile'] && !(int)$cust['mobile_verified']): ?><p class="note info" style="margin-top:10px">Verify your mobile to also see orders you placed as a guest. <a style="text-decoration:underline;font-weight:700" href="catalog.php?view=account&amp;tab=profile&amp;verify=1">Verify now</a></p><?php endif; ?>
    <div class="tabs" style="grid-template-columns:repeat(4,1fr);margin-top:14px">
      <a href="catalog.php?view=account" class="<?= $tab === 'orders' ? 'on' : '' ?>"><?= store_icon('receipt') ?>Orders</a>
      <a href="catalog.php?view=account&amp;tab=address" class="<?= $tab === 'address' ? 'on' : '' ?>"><?= store_icon('pin') ?>Address</a>
      <a href="catalog.php?view=wish"><?= store_icon('heart') ?>Wishlist</a>
      <a href="catalog.php?view=account&amp;tab=profile" class="<?= $tab === 'profile' ? 'on' : '' ?>"><?= store_icon('user') ?>Profile</a>
    </div>

    <?php if ($tab === 'orders'): ?>
      <?php if (!$orders): ?><div class="panel empty"><?= store_icon('bag') ?><h3>No orders yet</h3><p>Your orders will show here with their status.</p><a class="btn" href="catalog.php?view=all">Start shopping</a></div><?php endif; ?>
      <?php foreach ($orders as $o): $its = json_decode((string)$o['items_json'], true) ?: []; ?>
      <details class="panel" style="margin-bottom:10px">
        <summary style="list-style:none;cursor:pointer;display:flex;justify-content:space-between;gap:10px;align-items:center">
          <span><b><?= e($o['order_no']) ?></b><span class="muted" style="display:block;font-size:12.5px"><?= dmyt($o['created_at']) ?> · <?= count($its) ?> item<?= count($its) === 1 ? '' : 's' ?></span></span>
          <span style="text-align:right"><b>₹<?= money($o['total']) ?></b><span class="stkbox <?= in_array($o['status'], ['completed', 'delivered'], true) ? 'in' : ($o['status'] === 'cancelled' ? 'out' : 'ord') ?>" style="display:block;margin-top:4px;padding:2px 10px;font-size:12px"><?= e(ucfirst((string)$o['status'] ?: 'Placed')) ?></span></span>
        </summary>
        <ol class="track"><?php foreach (sc_order_steps($o['status']) as [$lab, $done]): ?><li class="<?= $done ? 'done' : '' ?>"><?= e($lab) ?></li><?php endforeach; ?></ol>
        <?php foreach ($its as $ti): ?><div class="cl" style="grid-template-columns:1fr auto;padding:8px 0"><span class="n"><?= e($ti['name'] ?? '') ?> × <?= (int)($ti['qty'] ?? 1) ?></span><b>₹<?= money($ti['total'] ?? 0) ?></b></div><?php endforeach; ?>
        <p class="muted" style="font-size:13px;margin-top:8px"><?= e($o['address']) ?><?= $o['notes'] ? ' · ' . e($o['notes']) : '' ?></p>
        <?php if ($c['wa']): ?><a class="btn ghost" style="height:40px;margin-top:10px" href="https://wa.me/<?= e($c['wa']) ?>?text=<?= rawurlencode('Hello! About my order ' . $o['order_no'] . ':') ?>" target="_blank" rel="noopener"><?= store_icon('wa') ?>Ask about this order</a><?php endif; ?>
      </details>
      <?php endforeach; ?>

    <?php elseif ($tab === 'address'): $edit = (int)get('edit'); $ea = $edit ? (array_values(array_filter($addrs, fn($x) => (int)$x['id'] === $edit))[0] ?? null) : null; ?>
      <?php foreach ($addrs as $ad): ?>
      <div class="panel" style="margin-bottom:10px;display:flex;gap:12px;align-items:flex-start">
        <span style="color:var(--brand)"><?= store_icon('pin') ?></span>
        <div style="flex:1"><b><?= e($ad['label']) ?></b><?= (int)$ad['is_default'] ? ' <span class="vbadge">Default</span>' : '' ?>
          <p style="font-size:14px;margin-top:2px"><?= e($ad['name']) ?> · <?= e($ad['mobile']) ?><br><?= e(implode(', ', array_filter([$ad['address'], $ad['area'], $ad['pincode']]))) ?></p></div>
        <div style="display:flex;flex-direction:column;gap:6px">
          <a class="chip" href="catalog.php?view=account&amp;tab=address&amp;edit=<?= (int)$ad['id'] ?>">Edit</a>
          <form method="post" action="catalog.php?view=account" onsubmit="return confirm('Remove this address?')"><?= csrf_field() ?><input type="hidden" name="do" value="sc_addr_del"><input type="hidden" name="id" value="<?= (int)$ad['id'] ?>"><button class="chip" type="submit">Remove</button></form>
        </div>
      </div>
      <?php endforeach; ?>
      <form class="panel" method="post" action="catalog.php?view=account"><?= csrf_field() ?><input type="hidden" name="do" value="sc_addr"><input type="hidden" name="id" value="<?= (int)($ea['id'] ?? 0) ?>">
        <h2 style="font-size:16px;margin-bottom:10px"><?= $ea ? 'Edit address' : 'Add a delivery address' ?></h2>
        <div class="two">
          <div class="field" style="margin:0"><label for="ad1">Label</label><input id="ad1" name="label" placeholder="Home / Shop / Office" value="<?= e($ea['label'] ?? '') ?>"></div>
          <div class="field" style="margin:0"><label for="ad2">Pincode</label><input id="ad2" name="pincode" inputmode="numeric" pattern="[0-9]{6}" value="<?= e($ea['pincode'] ?? '') ?>"></div>
        </div>
        <div class="field"><label for="ad3">Address *</label><input id="ad3" name="address" required value="<?= e($ea['address'] ?? '') ?>"></div>
        <div class="field"><label for="ad4">Area / City</label><input id="ad4" name="area" value="<?= e($ea['area'] ?? shop_city()) ?>"></div>
        <div class="two">
          <div class="field" style="margin:0"><label for="ad5">Receiver name</label><input id="ad5" name="aname" value="<?= e($ea['name'] ?? $cust['name']) ?>"></div>
          <div class="field" style="margin:0"><label for="ad6">Receiver mobile</label><input id="ad6" name="amobile" type="tel" value="<?= e($ea['mobile'] ?? $cust['mobile']) ?>"></div>
        </div>
        <label class="chip" style="margin-bottom:12px"><input type="checkbox" name="is_default" value="1" <?= !$addrs || (int)($ea['is_default'] ?? 0) ? 'checked' : '' ?>> Use as my default address</label>
        <button class="btn block" type="submit"><?= store_icon('check') ?>Save address</button>
      </form>

    <?php else: ?>
      <?php if (get('verify') === '1' && $cust['mobile'] && !(int)$cust['mobile_verified']): ?>
      <div class="panel" style="margin-bottom:12px"><h2 style="font-size:16px;margin-bottom:8px">Verify +91 <?= e($cust['mobile']) ?></h2>
        <form method="post" action="catalog.php?view=account" style="margin-bottom:8px"><?= csrf_field() ?><input type="hidden" name="do" value="sc_verify_send"><button class="btn ghost block" type="submit"><?= store_icon('wa') ?>Send code on WhatsApp</button></form>
        <form method="post" action="catalog.php?view=account"><?= csrf_field() ?><input type="hidden" name="do" value="sc_verify">
          <div class="ifield"><?= store_icon('lock') ?><input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required placeholder="6-digit code" aria-label="Code" autocomplete="one-time-code"></div>
          <button class="btn block" type="submit">Verify</button></form></div>
      <?php endif; ?>
      <form class="panel" method="post" action="catalog.php?view=account" style="margin-bottom:12px"><?= csrf_field() ?><input type="hidden" name="do" value="sc_profile">
        <h2 style="font-size:16px;margin-bottom:10px">My profile</h2>
        <div class="field"><label for="p1">Name</label><input id="p1" name="name" required value="<?= e($cust['name']) ?>"></div>
        <div class="field"><label for="p2">Mobile <?= (int)$cust['mobile_verified'] ? '<span class="vbadge">Verified</span>' : '' ?></label><input id="p2" name="mobile" type="tel" inputmode="numeric" value="<?= e($cust['mobile']) ?>"></div>
        <div class="field"><label for="p3">E-mail</label><input id="p3" name="email" type="email" value="<?= e($cust['email']) ?>"></div>
        <button class="btn block" type="submit">Save profile</button>
      </form>
      <form class="panel" method="post" action="catalog.php?view=account"><?= csrf_field() ?><input type="hidden" name="do" value="sc_password">
        <h2 style="font-size:16px;margin-bottom:10px"><?= $cust['password_hash'] ? 'Change password' : 'Set a password' ?></h2>
        <?php if ($cust['password_hash']): ?><div class="field"><label for="p4">Current password</label><input id="p4" name="old" type="password" required autocomplete="current-password"></div><?php endif; ?>
        <div class="field"><label for="p5">New password (6+)</label><input id="p5" name="password" type="password" required minlength="6" autocomplete="new-password"></div>
        <button class="btn ghost block" type="submit">Save password</button>
      </form>
    <?php endif; ?>
  </section>
<?php }
