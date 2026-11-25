<?php
// Structured & JavaScript-data extractor running directly on the Germany host (apps.hohedoc.ir).
// Parses raw HTML / JSON fetched from inside Iran via relay.liliumesorkh.ir — zero GitHub Secrets, zero browser needed
// for ~85-90% of modern Iranian JS sites (Next.js __NEXT_DATA__, Nuxt, JSON-LD, inline state, and direct JSON APIs).

function js_extract_clean_text(string $s, int $max = 24000): string {
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/[\r\n\t]+/u', ' ', $s) ?? $s;
    $s = preg_replace('/\s{2,}/u', ' ', $s) ?? $s;
    $s = trim($s);
    return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max, 'UTF-8') . '…' : $s;
}

/** Recursively pull meaningful human-readable string values out of hydration JSON (__NEXT_DATA__ / __NUXT__). */
function js_extract_json_strings($node, array &$out, int $depth = 0, int $limit = 160): void {
    if ($depth > 8 || count($out) >= $limit) return;
    if (is_string($node)) {
        $t = trim($node);
        if (mb_strlen($t, 'UTF-8') >= 4 && mb_strlen($t, 'UTF-8') <= 600
            && !preg_match('/^(https?:\/\/|\/_next\/|data:|#[0-9a-f]{3,8}$|[a-z0-9_-]{24,}$)/i', $t)) {
            $out[$t] = true;
        }
        return;
    }
    if (!is_array($node)) return;
    foreach ($node as $k => $v) {
        if (count($out) >= $limit) break;
        if (is_string($k) && in_array(strtolower($k), ['buildid', 'assetprefix', 'scriptloader', 'gssp', 'isfallback', 'token', 'nonce', 'svg', 'path'], true)) {
            continue;
        }
        js_extract_json_strings($v, $out, $depth + 1, $limit);
    }
}

function js_extract_meta(string $html): array {
    $meta = ['title' => '', 'description' => '', 'canonical' => ''];
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $meta['title'] = js_extract_clean_text(strip_tags($m[1]), 300);
    }
    if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/is', $html, $m)
        || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\']/is', $html, $m)
        || preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/is', $html, $m)) {
        $meta['description'] = js_extract_clean_text($m[1], 600);
    }
    if (preg_match('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/is', $html, $m)) {
        $meta['canonical'] = trim($m[1]);
    }
    return $meta;
}

function js_extract_json_ld(string $html): array {
    $blocks = [];
    if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $ms)) {
        foreach ($ms[1] as $raw) {
            $d = json_decode(trim($raw), true);
            if (is_array($d)) $blocks[] = $d;
            if (count($blocks) >= 12) break;
        }
    }
    return $blocks;
}

function js_extract_hydration(string $html): array {
    $found = ['framework' => '', 'strings' => [], 'top_keys' => []];
    $candidates = [
        'nextjs' => '/<script[^>]+id=["\']__NEXT_DATA__["\'][^>]*>(.*?)<\/script>/is',
        'nuxt'   => '/<script[^>]+id=["\']__NUXT_DATA__["\'][^>]*>(.*?)<\/script>/is',
        'initial_state' => '/window\.__(?:INITIAL_STATE|PRELOADED_STATE|APOLLO_STATE|NUXT)__\s*=\s*(\{.*?\});?\s*<\/script>/is',
    ];
    foreach ($candidates as $fw => $re) {
        if (preg_match($re, $html, $m)) {
            $d = json_decode(trim($m[1]), true);
            if (is_array($d)) {
                $found['framework'] = $fw;
                $found['top_keys'] = array_slice(array_keys($d), 0, 20);
                $bag = [];
                js_extract_json_strings($d, $bag, 0, 160);
                $found['strings'] = array_keys($bag);
                break;
            }
        }
    }
    return $found;
}

function js_extract_links(string $html, int $max = 60): array {
    $links = [];
    if (preg_match_all('/<a[^>]+href=["\']([^"\'#][^"\']*)["\'][^>]*>(.*?)<\/a>/is', $html, $ms, PREG_SET_ORDER)) {
        foreach ($ms as $m) {
            $href = trim($m[1]);
            if ($href === '' || stripos($href, 'javascript:') === 0) continue;
            $label = js_extract_clean_text(strip_tags($m[2]), 140);
            $links[] = ['href' => $href, 'text' => $label];
            if (count($links) >= $max) break;
        }
    }
    return $links;
}

function js_extract_page(array $fetch): array {
    $body = (string) ($fetch['body'] ?? '');
    $ctype = strtolower((string) ($fetch['ctype'] ?? ''));
    $out = [
        'ok'        => !empty($fetch['ok']),
        'code'      => (int) ($fetch['code'] ?? 0),
        'ctype'     => (string) ($fetch['ctype'] ?? ''),
        'final'     => (string) ($fetch['final'] ?? ''),
        'sec'       => $fetch['sec'] ?? 0,
        'bytes'     => (int) ($fetch['bytes'] ?? strlen($body)),
        'truncated' => !empty($fetch['truncated']),
        'err'       => (string) ($fetch['err'] ?? ''),
        'mode'      => 'empty',
        'title'     => '',
        'description' => '',
        'canonical' => '',
        'text'      => '',
        'text_chars' => 0,
        'json_ld'   => [],
        'js_state'  => ['framework' => '', 'top_keys' => [], 'strings' => []],
        'api_json'  => null,
        'links'     => [],
    ];
    if ($body === '') return $out;

    // 1. Direct JSON API response (e.g., api.digikala.com, torob API, etc.)
    $trimmed = ltrim($body);
    if (strpos($ctype, 'application/json') !== false || $trimmed[0] === '{' || $trimmed[0] === '[') {
        $j = json_decode($body, true);
        if (is_array($j)) {
            $out['mode'] = 'json_api';
            $bag = [];
            js_extract_json_strings($j, $bag, 0, 160);
            $out['text'] = js_extract_clean_text(implode(' | ', array_keys($bag)), 16000);
            $out['text_chars'] = mb_strlen($out['text'], 'UTF-8');
            $out['api_json'] = strlen($body) <= 120000 ? $j : ['truncated' => true, 'top_keys' => array_slice(array_keys($j), 0, 30)];
            return $out;
        }
    }

    // 2. HTML + embedded JavaScript hydration / JSON-LD extraction
    $meta = js_extract_meta($body);
    $out['title'] = $meta['title'];
    $out['description'] = $meta['description'];
    $out['canonical'] = $meta['canonical'];
    $out['json_ld'] = js_extract_json_ld($body);
    $out['js_state'] = js_extract_hydration($body);
    $out['links'] = js_extract_links($body, 60);

    $stripped = preg_replace('/<(script|style|noscript|svg|template)\b[^>]*>.*?<\/\1>/is', ' ', $body) ?? $body;
    $htmlText = js_extract_clean_text(strip_tags($stripped), 20000);

    if ($out['js_state']['framework'] !== '' && count($out['js_state']['strings']) > 0) {
        $out['mode'] = 'js_' . $out['js_state']['framework'];
        $combined = trim($htmlText . "\n" . implode(' · ', $out['js_state']['strings']));
        $out['text'] = js_extract_clean_text($combined, 24000);
    } elseif (count($out['json_ld']) > 0) {
        $out['mode'] = 'json_ld_html';
        $bag = [];
        js_extract_json_strings($out['json_ld'], $bag, 0, 120);
        $combined = trim($htmlText . "\n" . implode(' · ', array_keys($bag)));
        $out['text'] = js_extract_clean_text($combined, 24000);
    } else {
        $out['text'] = $htmlText;
        $out['mode'] = mb_strlen($htmlText, 'UTF-8') >= 200 ? 'html_text' : 'js_shell';
    }
    $out['text_chars'] = mb_strlen($out['text'], 'UTF-8');
    return $out;
}
