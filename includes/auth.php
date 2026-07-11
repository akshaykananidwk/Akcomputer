<?php
// Authentication + role/permission system
//
// Permissions are strings like "sales.add". A role holds a JSON array of them;
// a user can additionally have per-user extra permissions (users.permissions).
// Special points:
//   *.all          -> see everyone's records (without it, staff sees only their own)
//   reports.profit -> can see profit figures
//   *              -> super admin (everything)

function permission_catalog() {
    return [
        'dashboard' => ['view'],
        'sales' => ['view', 'add', 'edit', 'delete', 'all'],
        'estimates' => ['view', 'add', 'edit', 'delete', 'all'],
        'sales_return' => ['view', 'add', 'delete'],
        'purchases' => ['view', 'add', 'edit', 'delete', 'all'],
        'purchase_return' => ['view', 'add', 'delete'],
        'items' => ['view', 'add', 'edit', 'delete'],
        'parties' => ['view', 'add', 'edit', 'delete'],
        'stock' => ['view', 'adjust'],
        'handover' => ['view', 'add', 'accept', 'all'],
        'tasks' => ['view', 'add', 'edit', 'all'],
        'repairs' => ['view', 'add', 'edit', 'delete'],
        'warranty' => ['view', 'add', 'edit', 'delete'],
        'payments' => ['view', 'add', 'delete'],
        'expenses' => ['view', 'add', 'delete'],
        'challans' => ['view', 'add', 'edit'],
        'reports' => ['view', 'profit', 'gst'],
        'users' => ['view', 'add', 'edit', 'delete'],
        'roles' => ['view', 'add', 'edit', 'delete'],
        'locations' => ['view', 'add', 'edit'],
        'companies' => ['view', 'add', 'edit'],
        'settings' => ['view', 'edit'],
    ];
}

function permission_labels() {
    return [
        'dashboard' => 'Dashboard', 'sales' => 'Sales / Billing', 'estimates' => 'Estimates',
        'sales_return' => 'Sales Return', 'purchases' => 'Purchase', 'purchase_return' => 'Purchase Return',
        'items' => 'Items', 'parties' => 'Parties (Customer/Supplier)', 'stock' => 'Stock',
        'handover' => 'Stock Handover', 'tasks' => 'Field Tasks', 'repairs' => 'Repair Jobs',
        'warranty' => 'Warranty Claims', 'payments' => 'Payments / Ledger',
        'expenses' => 'Expenses', 'challans' => 'Delivery Challans', 'reports' => 'Reports',
        'users' => 'Staff Users', 'roles' => 'Roles', 'locations' => 'Locations',
        'companies' => 'Companies / Firms', 'settings' => 'Settings',
    ];
}

function current_user() {
    static $user = null;
    if ($user === null && !empty($_SESSION['user_id'])) {
        $user = row('SELECT u.*, r.name AS role_name, r.permissions AS role_permissions, l.name AS location_name
                     FROM users u
                     JOIN roles r ON r.id = u.role_id
                     JOIN locations l ON l.id = u.location_id
                     WHERE u.id = ? AND u.is_active = 1', [$_SESSION['user_id']]);
        if ($user) {
            $perms = json_decode($user['role_permissions'] ?: '[]', true) ?: [];
            $extra = json_decode($user['permissions'] ?: '[]', true) ?: [];
            $user['perms'] = array_unique(array_merge($perms, $extra));
        }
    }
    return $user;
}

function can($perm) {
    $u = current_user();
    if (!$u) return false;
    if (in_array('*', $u['perms'], true)) return true;
    return in_array($perm, $u['perms'], true);
}

function require_login() {
    if (!current_user()) {
        redirect('login.php');
    }
}

function require_perm($perm) {
    require_login();
    if (!can($perm)) {
        http_response_code(403);
        include __DIR__ . '/header.php';
        echo '<div class="card"><h2>Access denied</h2><p>You do not have permission for this page (' . e($perm) . '). Contact admin.</p></div>';
        include __DIR__ . '/footer.php';
        exit;
    }
}

/**
 * Scope filter: if user lacks "<module>.all", restrict to own records.
 * Returns [extra_sql, extra_params].
 */
function own_scope($module, $column = 'created_by') {
    if (can($module . '.all')) return ['', []];
    return [' AND ' . $column . ' = ? ', [current_user()['id']]];
}
