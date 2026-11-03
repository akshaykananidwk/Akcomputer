<?php
// The supplier's original bill (photo or PDF) kept with a purchase.
// Optional: a purchase saves the same with or without one. Files live in a
// folder the web server never serves; purchase_view.php?bill=1 hands them out
// only to staff who can see purchases. Old "Scan Bill" files stay where they
// were (purchase_scans) and are found there too.

const BILL_FILE_TYPES = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

function bill_file_dir() {
    $d = up_dir('purchase_bills');
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if (!is_file("$d/.htaccess")) @file_put_contents("$d/.htaccess", "Require all denied\n");
    return $d;
}

/** Saves the uploaded file in $_FILES[$field]. Returns the stored name, null
 *  when nothing was chosen, or throws with a message the owner can act on. */
function bill_file_save($field = 'bill_file') {
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('The bill file did not upload - try again (max 10MB).');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, BILL_FILE_TYPES, true)) throw new RuntimeException('The bill file must be a PDF or a photo (JPG, PNG, WEBP).');
    if ($f['size'] > 10 * 1024 * 1024) throw new RuntimeException('The bill file is too large (max 10MB).');
    // the content must match the name: a PDF starts with %PDF, a photo must open as an image
    $head = (string)file_get_contents($f['tmp_name'], false, null, 0, 5);
    if ($ext === 'pdf' ? $head !== '%PDF-' : !@getimagesize($f['tmp_name'])) {
        throw new RuntimeException('That file is not a real PDF or photo.');
    }
    $name = 'bill_' . bin2hex(random_bytes(8)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!move_uploaded_file($f['tmp_name'], bill_file_dir() . '/' . $name)) throw new RuntimeException('The bill file could not be saved.');
    return $name;
}

/** Full path of a stored bill file, or null when it is missing. */
function bill_file_path($name) {
    $name = basename((string)$name);
    if ($name === '') return null;
    foreach ([bill_file_dir(), up_dir('purchase_scans')] as $d) {
        if (is_file("$d/$name")) return "$d/$name";
    }
    return null;
}
