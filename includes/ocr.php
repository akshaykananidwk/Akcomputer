<?php
// OCR-assisted purchase entry (purchase_scan.php). Reads a photo of a
// vendor bill via a cloud OCR API - OCR.space, a plain REST call with an
// API key, chosen specifically because it needs no OAuth/service-account
// setup (unlike Google Cloud Vision) a small shop owner could realistically
// do themselves in Settings, the same way Razorpay/WhatsApp keys are
// configured. There is no local OCR engine anywhere in this app - shared
// hosting (this project's target deployment) can't have Tesseract or
// similar installed.

/** Sends the image to OCR.space and returns the raw extracted text, or
 *  null if no API key is configured or the call fails. */
function ocr_extract_text($imagePath) {
    $apiKey = setting('ocr_api_key');
    if (!$apiKey || !is_file($imagePath) || !function_exists('curl_init')) return null;
    $ch = curl_init('https://api.ocr.space/parse/image');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['apikey: ' . $apiKey],
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($imagePath),
            'language' => 'eng',
            'OCREngine' => '2',
            'scale' => 'true',
            'isTable' => 'true',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code >= 300) return null;
    $data = json_decode($resp, true);
    if (!empty($data['IsErroredOnProcessing'])) return null;
    if (empty($data['ParsedResults'][0]['ParsedText'])) return null;
    return $data['ParsedResults'][0]['ParsedText'];
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
