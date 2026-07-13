<?php
// PDO connection + tiny query helpers

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        // MySQL's own NOW()/CURRENT_TIMESTAMP defaults (e.g. every table's
        // created_at) run in the server's OWN time zone, which on most
        // hosting is UTC - not the Asia/Kolkata zone PHP is set to above.
        // Left unset, timestamps written by MySQL itself drift ~5:30 hours
        // from what date()/today() compute in PHP, showing the wrong time
        // (and, near midnight IST, sometimes the wrong day) on invoices.
        // India has a single fixed +05:30 offset year-round (no DST).
        $pdo->exec("SET time_zone = '+05:30'");
    }
    return $pdo;
}

function q($sql, $params = []) {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function row($sql, $params = []) {
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function all($sql, $params = []) {
    return q($sql, $params)->fetchAll();
}

function val($sql, $params = []) {
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function insert_id() {
    return (int) db()->lastInsertId();
}
