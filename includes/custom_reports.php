<?php
// Custom Report Builder: a metadata-driven query assembler, NOT a raw-SQL
// tool. Every column/filter/group-by a user can pick is resolved through
// this allowlist - user input only ever selects a KEY into these arrays,
// it never reaches string-concatenated SQL directly, so there's no
// injection surface no matter what a user submits.

function custom_report_sources() {
    return [
        'sales' => [
            'label' => 'Sales',
            'from' => 'sales s LEFT JOIN parties p ON p.id = s.party_id LEFT JOIN locations l ON l.id = s.location_id LEFT JOIN users u2 ON u2.id = s.created_by',
            'date_col' => 's.sale_date',
            'where' => 's.is_cancelled = 0',
            'columns' => [
                'invoice_no' => ['label' => 'Invoice No', 'expr' => 's.invoice_no', 'type' => 'text'],
                'sale_date' => ['label' => 'Date', 'expr' => 's.sale_date', 'type' => 'date'],
                'customer' => ['label' => 'Customer', 'expr' => "COALESCE(p.name, NULLIF(s.customer_name,''), 'Walk-in')", 'type' => 'text'],
                'location' => ['label' => 'Location', 'expr' => 'l.name', 'type' => 'text'],
                'staff' => ['label' => 'Staff', 'expr' => 'u2.name', 'type' => 'text'],
                'status' => ['label' => 'Status', 'expr' => 's.status', 'type' => 'text'],
                'total' => ['label' => 'Total ₹', 'expr' => 's.total', 'type' => 'num'],
                'paid' => ['label' => 'Paid ₹', 'expr' => 's.paid', 'type' => 'num'],
                'due' => ['label' => 'Due ₹', 'expr' => '(s.total - s.paid)', 'type' => 'num'],
            ],
            'group_cols' => ['location' => 'l.name', 'staff' => 'u2.name', 'status' => 's.status', 'customer' => "COALESCE(p.name, NULLIF(s.customer_name,''), 'Walk-in')"],
        ],
        'purchases' => [
            'label' => 'Purchases',
            'from' => 'purchases pu LEFT JOIN parties p ON p.id = pu.party_id LEFT JOIN locations l ON l.id = pu.location_id LEFT JOIN users u2 ON u2.id = pu.created_by',
            'date_col' => 'pu.purchase_date',
            'where' => 'pu.is_cancelled = 0',
            'columns' => [
                'bill_no' => ['label' => 'Bill No', 'expr' => 'pu.bill_no', 'type' => 'text'],
                'purchase_date' => ['label' => 'Date', 'expr' => 'pu.purchase_date', 'type' => 'date'],
                'supplier' => ['label' => 'Supplier', 'expr' => 'p.name', 'type' => 'text'],
                'location' => ['label' => 'Location', 'expr' => 'l.name', 'type' => 'text'],
                'staff' => ['label' => 'Entered by', 'expr' => 'u2.name', 'type' => 'text'],
                'status' => ['label' => 'Status', 'expr' => 'pu.status', 'type' => 'text'],
                'total' => ['label' => 'Total ₹', 'expr' => 'pu.total', 'type' => 'num'],
                'paid' => ['label' => 'Paid ₹', 'expr' => 'pu.paid', 'type' => 'num'],
                'due' => ['label' => 'Due ₹', 'expr' => '(pu.total - pu.paid)', 'type' => 'num'],
            ],
            'group_cols' => ['location' => 'l.name', 'staff' => 'u2.name', 'status' => 'pu.status', 'supplier' => 'p.name'],
        ],
        'payments' => [
            'label' => 'Payments',
            'from' => 'payments pay LEFT JOIN parties p ON p.id = pay.party_id LEFT JOIN users u2 ON u2.id = pay.created_by',
            'date_col' => 'pay.pay_date',
            'where' => "pay.mode NOT IN ('contra','discount')",
            'columns' => [
                'pay_date' => ['label' => 'Date', 'expr' => 'pay.pay_date', 'type' => 'date'],
                'party' => ['label' => 'Party', 'expr' => "COALESCE(p.name, 'Walk-in')", 'type' => 'text'],
                'direction' => ['label' => 'In/Out', 'expr' => 'pay.direction', 'type' => 'text'],
                'mode' => ['label' => 'Mode', 'expr' => 'pay.mode', 'type' => 'text'],
                'staff' => ['label' => 'Entered by', 'expr' => 'u2.name', 'type' => 'text'],
                'amount' => ['label' => 'Amount ₹', 'expr' => 'pay.amount', 'type' => 'num'],
            ],
            'group_cols' => ['party' => "COALESCE(p.name, 'Walk-in')", 'direction' => 'pay.direction', 'mode' => 'pay.mode', 'staff' => 'u2.name'],
        ],
        'expenses' => [
            'label' => 'Expenses',
            'from' => 'expenses x LEFT JOIN locations l ON l.id = x.location_id LEFT JOIN users u2 ON u2.id = x.created_by',
            'date_col' => 'x.exp_date',
            'where' => '1=1',
            'columns' => [
                'exp_date' => ['label' => 'Date', 'expr' => 'x.exp_date', 'type' => 'date'],
                'category' => ['label' => 'Category', 'expr' => 'x.category', 'type' => 'text'],
                'location' => ['label' => 'Location', 'expr' => 'l.name', 'type' => 'text'],
                'staff' => ['label' => 'Entered by', 'expr' => 'u2.name', 'type' => 'text'],
                'notes' => ['label' => 'Notes', 'expr' => 'x.notes', 'type' => 'text'],
                'amount' => ['label' => 'Amount ₹', 'expr' => 'x.amount', 'type' => 'num'],
            ],
            'group_cols' => ['category' => 'x.category', 'location' => 'l.name', 'staff' => 'u2.name'],
        ],
    ];
}

/** Builds and runs the query for one custom report config. $columns is a
 *  list of column keys (validated against the source's allowlist); when
 *  $groupBy is set, numeric columns are summed and text columns dropped
 *  from the SELECT (they'd be meaningless per-group) other than the group
 *  dimension itself. Returns [rows, effectiveColumns] where
 *  effectiveColumns is what actually got rendered (for the table header). */
function custom_report_run($sourceKey, $columns, $groupBy, $sortBy, $from, $to) {
    $sources = custom_report_sources();
    if (!isset($sources[$sourceKey])) return [[], []];
    $src = $sources[$sourceKey];
    $columns = array_values(array_intersect($columns, array_keys($src['columns'])));
    if (!$columns) $columns = array_keys($src['columns']);

    if ($groupBy && isset($src['group_cols'][$groupBy])) {
        $groupExpr = $src['group_cols'][$groupBy];
        $numCols = array_filter($columns, fn($c) => ($src['columns'][$c]['type'] ?? '') === 'num');
        $selects = ['(' . $groupExpr . ') AS grp_val', 'COUNT(*) AS row_count'];
        foreach ($numCols as $c) $selects[] = 'SUM(' . $src['columns'][$c]['expr'] . ') AS col_' . $c;
        $sql = 'SELECT ' . implode(', ', $selects) . ' FROM ' . $src['from']
             . ' WHERE ' . $src['where'] . ' AND ' . $src['date_col'] . ' BETWEEN ? AND ?'
             . ' GROUP BY (' . $groupExpr . ') ORDER BY ' . ($numCols ? 'SUM(' . $src['columns'][reset($numCols)]['expr'] . ') DESC' : 'row_count DESC');
        $rows = all($sql, [$from, $to]);
        $effCols = array_merge(['grp' => ['label' => ucfirst($groupBy), 'type' => 'text']], ['row_count' => ['label' => 'Count', 'type' => 'num']],
                                array_combine(array_map(fn($c) => 'col_' . $c, $numCols), array_map(fn($c) => $src['columns'][$c], $numCols)));
        return [$rows, $effCols, true];
    }

    $selects = [];
    foreach ($columns as $c) $selects[] = $src['columns'][$c]['expr'] . ' AS col_' . $c;
    $sortCol = ($sortBy && in_array($sortBy, $columns, true)) ? 'col_' . $sortBy : $src['date_col'];
    $sql = 'SELECT ' . implode(', ', $selects) . ' FROM ' . $src['from']
         . ' WHERE ' . $src['where'] . ' AND ' . $src['date_col'] . ' BETWEEN ? AND ?'
         . ' ORDER BY ' . $sortCol . ' DESC LIMIT 500';
    $rows = all($sql, [$from, $to]);
    $effCols = [];
    foreach ($columns as $c) $effCols['col_' . $c] = $src['columns'][$c];
    return [$rows, $effCols, false];
}
