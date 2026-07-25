<?php
// Google Gemini + Google Image Search helpers for AI product enrichment
// (ai_enrich.php). Design rule from the owner: a WRONG photo is worse than NO
// photo — so every candidate image must pass a Gemini vision check ("is this
// exactly that product?") before it is saved; anything unverified is skipped.

/** Low-level Gemini generateContent call. $parts is the API's parts array
 *  (text and/or inline_data). Returns [text|null, errorString|null].
 *
 *  Google retires model names over time (gemini-2.0-flash died mid-2026 with
 *  "no longer available"), so the model is never hardcoded: a cached working
 *  name is tried first, then the -latest alias and known flash names, and as
 *  a last resort the ListModels API discovers whatever flash model the key
 *  can use today. Whichever answers is cached so every later call pays for
 *  exactly one HTTP request again. */
function gemini_generate(array $parts, $timeout = 45, $forceJson = false) {
    $key = setting('gemini_api_key');
    if (!$key) return [null, 'Gemini API key is not set (Settings > Invoice & Payment).'];
    if (!function_exists('curl_init')) return [null, 'The server does not have curl.'];
    // 2.5-era flash models "think" before answering and the thoughts count
    // against maxOutputTokens — a small cap truncates the real answer into
    // broken JSON, so the cap is generous. responseMimeType makes the API
    // itself guarantee parseable JSON instead of prompt-begging for it.
    $genCfg = ['temperature' => 0.2, 'maxOutputTokens' => 4096];
    if ($forceJson) $genCfg['responseMimeType'] = 'application/json';
    $body = json_encode([
        'contents' => [['parts' => $parts]],
        'generationConfig' => $genCfg,
    ], JSON_UNESCAPED_UNICODE);
    $models = array_values(array_unique(array_filter([
        setting('gemini_model'), 'gemini-flash-latest', 'gemini-2.5-flash', 'gemini-2.0-flash',
    ])));
    $lastErr = 'Gemini call failed.';
    $discovered = false;
    for ($i = 0; $i < count($models); $i++) {
        $model = $models[$i];
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . urlencode($key));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body, CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) return [null, 'Network error: ' . $err];
        $j = json_decode($resp, true);
        if ($code === 200) {
            if ($model !== setting('gemini_model')) set_setting('gemini_model', $model);
            // thinking models can return several parts (thoughts first) —
            // keep only the real answer parts
            $text = null;
            foreach (($j['candidates'][0]['content']['parts'] ?? []) as $p) {
                if (!empty($p['thought'])) continue;
                if (isset($p['text'])) $text = ($text ?? '') . $p['text'];
            }
            if ($text === null || trim($text) === '') {
                $fr = $j['candidates'][0]['finishReason'] ?? '?';
                return [null, 'Gemini returned no text (finishReason: ' . $fr . ').'];
            }
            return [trim($text), null];
        }
        $msg = $j['error']['message'] ?? ('HTTP ' . $code);
        if ($code === 429) return [null, 'Gemini free-tier rate limit hit — wait a minute and press Continue.'];
        $modelGone = $code === 404 || stripos($msg, 'no longer available') !== false
            || stripos($msg, 'not found') !== false || stripos($msg, 'is not supported') !== false;
        if (!$modelGone) return [null, $msg];
        $lastErr = $msg;
        // this name is dead — forget it if it was the cached one
        if ($model === setting('gemini_model')) set_setting('gemini_model', '');
        if ($i === count($models) - 1 && !$discovered) {
            $discovered = true;
            $found = gemini_discover_model($key);
            if ($found) $models[] = $found;
        }
    }
    return [null, 'No usable Gemini model found (' . $lastErr . '). Check the key at aistudio.google.com.'];
}

/** Ask ListModels which flash model this key can actually use right now.
 *  Returns a model name or null. */
function gemini_discover_model($key) {
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models?pageSize=200&key=' . urlencode($key));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code !== 200) return null;
    $j = json_decode($resp, true);
    $best = null; $bestScore = -1;
    foreach (($j['models'] ?? []) as $m) {
        $name = str_replace('models/', '', (string)($m['name'] ?? ''));
        if (stripos($name, 'flash') === false) continue;
        if (!in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true)) continue;
        // needs vision for the photo check, so skip specialised variants
        if (preg_match('/image|tts|audio|live|embedding|thinking|gemma/i', $name)) continue;
        // prefer stable full-flash names over lite/preview/exp builds, and
        // newer versions over older (version number sorts inside the name)
        $score = 0;
        if (!preg_match('/lite|preview|exp/i', $name)) $score += 100;
        if (preg_match('/(\d+(?:\.\d+)?)/', $name, $v)) $score += (float)$v[1];
        if ($score > $bestScore || ($score === $bestScore && strcmp($name, $best) > 0)) { $best = $name; $bestScore = $score; }
    }
    return $best;
}

/** Ask Gemini for a category + short sales description for one item.
 *  Returns [['category'=>..,'description'=>..]|null, error|null]. */
function gemini_item_meta($item, array $categoryNames) {
    $label = trim($item['name'] . ' ' . ($item['brand'] ?? '') . ' ' . ($item['model'] ?? ''));
    $prompt = "You are cataloguing products for a computer & CCTV shop in Gujarat, India.\n"
        . "Product: \"{$label}\"\n"
        . "Existing shop categories: " . ($categoryNames ? implode(', ', $categoryNames) : '(none yet)') . "\n\n"
        . "Reply with ONLY a JSON object, no markdown fences, exactly:\n"
        . '{"category":"...","description":"..."}' . "\n"
        . "Rules:\n"
        . "- category: pick the best-fitting EXISTING category name from the list above when one fits; otherwise give one short new category name in English (max 3 words).\n"
        . "- description: 1-2 sentences (max 220 characters) in simple English a customer understands — what the product is, its key spec, and what it is used for. No price, no emojis, no quotes inside.\n"
        . "- If you genuinely cannot tell what the product is, use category \"\" and description \"\".";
    // one silent retry: a single malformed reply shouldn't cost the item
    for ($try = 0; $try < 2; $try++) {
        list($text, $err) = gemini_generate([['text' => $prompt]], 45, true);
        if ($err) return [null, $err];
        // tolerate ```json fences / stray prose despite responseMimeType
        if (preg_match('/\{.*\}/s', $text, $m)) $text = $m[0];
        $j = json_decode($text, true);
        if (is_array($j) && array_key_exists('category', $j) && array_key_exists('description', $j)) {
            return [['category' => trim((string)$j['category']), 'description' => trim((string)$j['description'])], null];
        }
    }
    return [null, 'bad-json'];
}

/** Gemini vision gatekeeper: does this image show exactly this product?
 *  Returns 'yes', 'no', or an error string starting with 'err:'. Anything but
 *  a clear yes is treated as no by the caller — that is the whole point. */
function gemini_verify_image($jpegBytes, $productLabel) {
    $prompt = "Look at this image. Is it a clear product photo of exactly this product: \"{$productLabel}\"?\n"
        . "It must be the same type of product AND the same/compatible brand-model. A different product, a different brand, a logo only, a person, a screenshot, a box collage, or an unclear image all count as NO.\n"
        . "Answer with ONE word only: YES or NO.";
    list($text, $err) = gemini_generate([
        ['text' => $prompt],
        ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => base64_encode($jpegBytes)]],
    ]);
    if ($err) return 'err:' . $err;
    return stripos($text, 'yes') === 0 ? 'yes' : 'no';
}

/** Google Custom Search (image mode). Returns [urls[], error|null]. */
function gcs_image_search($query, $count = 4) {
    $key = setting('gcs_api_key'); $cx = setting('gcs_cx');
    if (!$key || !$cx) return [[], 'Image search keys not set (Settings > Invoice & Payment).'];
    if (!function_exists('curl_init')) return [[], 'The server does not have curl.'];
    $url = 'https://www.googleapis.com/customsearch/v1?' . http_build_query([
        'key' => $key, 'cx' => $cx, 'q' => $query, 'searchType' => 'image',
        'num' => min(10, max(1, $count)), 'imgSize' => 'large', 'safe' => 'active',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) return [[], 'Network error during image search.'];
    $j = json_decode($resp, true);
    if ($code !== 200) {
        $msg = $j['error']['message'] ?? ('HTTP ' . $code);
        if ($code === 429 || stripos($msg, 'quota') !== false) {
            $msg = "Today's 100 free image searches are used up — run again tomorrow (photos only; text continues).";
        } elseif (stripos($msg, 'API keys are not supported') !== false
            || stripos($msg, 'has not been used in project') !== false
            || stripos($msg, 'accessNotConfigured') !== false
            || stripos($msg, 'it is disabled') !== false
            || stripos($msg, 'API key not valid') !== false) {
            // the classic mistake: the Gemini (AI Studio) key pasted into the
            // image-search field — that key's project can't call Custom Search
            $msg = 'The Image Search key is wrong — note it is a DIFFERENT key than the Gemini one. '
                 . 'Make it at console.cloud.google.com: create/select a project, search "Custom Search API" and press Enable, '
                 . 'then Credentials > Create credentials > API key. Paste that key in Settings.';
        }
        return [[], $msg];
    }
    $urls = [];
    foreach (($j['items'] ?? []) as $it) if (!empty($it['link'])) $urls[] = $it['link'];
    return [$urls, null];
}

/** Download a candidate image and normalise it to a bounded JPEG so odd
 *  formats, huge files, and HTML error pages never reach the catalog.
 *  Returns JPEG bytes or null. */
function ai_fetch_image($url) {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; AKComputer/1.0)',
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code !== 200 || strlen($raw) < 1000 || strlen($raw) > 12 * 1024 * 1024) return null;
    if (!function_exists('imagecreatefromstring')) return null;
    $img = @imagecreatefromstring($raw);
    if (!$img) return null;
    $w = imagesx($img); $h = imagesy($img);
    if ($w < 150 || $h < 150) { imagedestroy($img); return null; }
    $max = 1000;
    if ($w > $max || $h > $max) {
        $sc = min($max / $w, $max / $h);
        $nw = (int)round($w * $sc); $nh = (int)round($h * $sc);
        $dst = imagecreatetruecolor($nw, $nh);
        // white behind transparency so PNG logos don't turn black
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img); $img = $dst;
    } else {
        $dst = imagecreatetruecolor($w, $h);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopy($dst, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img); $img = $dst;
    }
    ob_start();
    imagejpeg($img, null, 84);
    $jpeg = ob_get_clean();
    imagedestroy($img);
    return $jpeg ?: null;
}
