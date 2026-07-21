<?php
// OCR-assisted purchase entry (purchase_scan.php). Reads a photo of a
// vendor bill via a cloud OCR API - OCR.space, a plain REST call with an
// API key, chosen specifically because it needs no OAuth/service-account
// setup (unlike Google Cloud Vision) a small shop owner could realistically
// do themselves in Settings, the same way Razorpay/WhatsApp keys are
// configured. There is no local OCR engine anywhere in this app - shared
// hosting (this project's target deployment) can't have Tesseract or
// similar installed.

/** Sends the file (photo OR PDF) to OCR.space and returns the raw extracted
 *  text, or null if no API key is configured or the call fails. PDFs are read
 *  page by page by OCR.space when filetype=PDF is passed, so a bill saved as a
 *  PDF works the same as a photo. */
function ocr_extract_text($imagePath) {
    $apiKey = setting('ocr_api_key');
    if (!$apiKey || !is_file($imagePath) || !function_exists('curl_init')) return null;
    $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
    $fields = [
        'file' => new CURLFile($imagePath),
        'language' => 'eng',
        'OCREngine' => '2',
        'scale' => 'true',
        'isTable' => 'true',
    ];
    // Tell OCR.space the format explicitly - it otherwise guesses from the
    // upload and can reject a PDF or an unusual image extension.
    $typeMap = ['pdf' => 'PDF', 'jpg' => 'JPG', 'jpeg' => 'JPG', 'png' => 'PNG', 'gif' => 'GIF', 'webp' => 'WEBP', 'bmp' => 'BMP', 'tif' => 'TIF', 'tiff' => 'TIF'];
    if (isset($typeMap[$ext])) $fields['filetype'] = $typeMap[$ext];
    $ch = curl_init('https://api.ocr.space/parse/image');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['apikey: ' . $apiKey],
        CURLOPT_POSTFIELDS => $fields,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code >= 300) return null;
    $data = json_decode($resp, true);
    if (!empty($data['IsErroredOnProcessing'])) return null;
    // A PDF returns one ParsedResults entry per page - join them all so a
    // multi-page bill is read whole, not just page 1.
    if (empty($data['ParsedResults'])) return null;
    $text = '';
    foreach ($data['ParsedResults'] as $pr) {
        if (!empty($pr['ParsedText'])) $text .= $pr['ParsedText'] . "\n";
    }
    return $text !== '' ? $text : null;
}

/** Best-effort guess of a line-item price for a token, read straight off the
 *  bill: finds the OCR text line(s) mentioning the token and returns the
 *  right-most money-looking number on it (vendor bills put the amount at the
 *  end of the row). Returns 0.0 when nothing convincing is found - the review
 *  screen always shows the price as an EDITABLE field, so this is only ever a
 *  starting suggestion, never trusted blindly. */
function ocr_guess_price($text, $token) {
    $token = (string)$token;
    if ($token === '') return 0.0;
    $best = 0.0;
    foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) {
        if (stripos($line, $token) === false) continue;
        // amounts like 1,234.56 / 1234.00 / 599 - prefer decimals, take the last
        if (preg_match_all('/\d[\d,]*(?:\.\d{1,2})?/', $line, $m)) {
            foreach ($m[0] as $numStr) {
                $val = (float)str_replace(',', '', $numStr);
                // skip tiny numbers (qty, serials) and absurd ones (phone/GSTIN)
                if ($val >= 1 && $val < 100000000) $best = $val;
            }
        }
    }
    return $best;
}

/** Pulls tokens that look like a product model/part number out of raw OCR
 *  text - a run of letters+digits (optionally with -/_ separators),
 *  4-24 chars, containing at least one digit AND one letter. Plain words
 *  ("Total", "Invoice") and pure numbers (invoice no., GSTIN, amounts) are
 *  filtered out by that letter+digit combination requirement. This is a
 *  heuristic, not a guarantee - it's why every candidate still goes
 *  through a review screen rather than being trusted blindly. */
function ocr_candidate_tokens($text) {
    if (!preg_match_all('/[A-Za-z0-9][A-Za-z0-9\-\/]{2,22}[A-Za-z0-9]/', (string)$text, $m)) return [];
    $out = [];
    foreach (array_unique($m[0]) as $tok) {
        $hasDigit = (bool)preg_match('/[0-9]/', $tok);
        $hasAlpha = (bool)preg_match('/[A-Za-z]/', $tok);
        // A GSTIN (15 chars, fixed state-code+PAN+entity+checksum shape) is
        // on nearly every Indian vendor bill and would otherwise show up as
        // noise on every single scan - it's distinctive enough to filter
        // out safely without risking a real model number.
        $isGstin = (bool)preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9][A-Z][A-Z0-9]$/i', $tok);
        if ($hasDigit && $hasAlpha && mb_strlen($tok) >= 4 && !$isGstin) $out[] = mb_strtoupper($tok);
    }
    return array_values(array_unique($out));
}

/** For each candidate token, finds items whose model / barcode / name
 *  matches - exact model match ranked first, then partial matches.
 *  Returns [['token' => ..., 'matches' => [...items...]], ...]. */
function ocr_match_items($tokens) {
    $results = [];
    foreach ($tokens as $tok) {
        $like = '%' . $tok . '%';
        $matches = all("SELECT id, name, model, barcode, purchase_price, tax_rate FROM items
                         WHERE is_active = 1 AND (model = ? OR model LIKE ? OR barcode = ? OR name LIKE ?)
                         ORDER BY (model = ?) DESC, name LIMIT 5", [$tok, $like, $tok, $like, $tok]);
        $results[] = ['token' => $tok, 'matches' => $matches];
    }
    return $results;
}
