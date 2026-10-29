<?php
// Clock for Claude routines that have no schedule of their own (user's decision 1405-08-07: the clock lives on
// the host, not on the Claude account). Called from tick.php every 5 minutes; fires each job at most once per
// slot by POSTing to the routine's fire endpoint (the "API" trigger on claude.ai/code/routines).
// Secrets in ../secrets.php (outside public_html), never printed: <JOB>_FIRE_URL and <JOB>_FIRE_TOKEN.
// No secret ⇒ nothing fires and status.php says so (fire_secrets_present = false), not a silent skip.
require_once __DIR__ . '/lib.php';

// job => [every_hours, UTC hour to fire in, secret prefix, text sent to the session]
const OPS_FIRE = [
    'invariants' => [48, 17, 'INVARIANTS',
        'اجرای زمان‌بندی‌شده از ساعتِ هاستِ ops.hohedoc.ir — رانبوکِ Agents-Utility/Prompts/Live/invariants-prompt.md'],
];

function ops_secret(string $key): string {
    static $s = null;
    if ($s === null) {
        $s = [];
        $files = [OPS_ROOT . '/secrets.php'];
        foreach (glob(OPS_ROOT . '/secrets-extra*.php') ?: [] as $x) $files[] = $x;
        foreach ($files as $p) {
            if (!is_file($p)) continue;
            @chmod($p, 0600);
            $part = include $p;
            if (is_array($part)) $s = array_merge($s, array_filter($part, fn($v) => $v !== '' && $v !== null));
        }
    }
    return (string) ($s[$key] ?? '');
}

function ops_fire_due(array $job, int $last, int $now): bool {
    [$every, $hour] = $job;
    if ($last && $now - $last < ($every - 1) * 3600) return false;     // fired in this slot already
    return (int) gmdate('G', $now) === $hour;                         // only inside the chosen hour
}

function ops_fire_all(): void {
    $state = @json_decode((string) @file_get_contents(ops_var('fire.json')), true) ?: [];
    $present = [];
    foreach (OPS_FIRE as $name => $job) {
        $url = ops_secret($job[2] . '_FIRE_URL');
        $tok = ops_secret($job[2] . '_FIRE_TOKEN');
        $present[$name] = $url !== '' && $tok !== '';
        if (!$present[$name] || !ops_fire_due($job, (int) ($state[$name]['at'] ?? 0), time())) continue;
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Content-Type: application/json',
                'anthropic-version: 2023-06-01', 'anthropic-beta: experimental-cc-routine'],
            CURLOPT_POSTFIELDS => json_encode(['text' => $job[3]], JSON_UNESCAPED_UNICODE)]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $state[$name] = ['at' => time(), 'http' => $code];
        ops_log("fire $name http=$code");
        if ($code < 200 || $code >= 300) ops_telegram("⚠️ هاستِ ops نتوانست روتینِ «{$name}» را روشن کند (HTTP {$code}). لینک و توکنِ شلیک را در claude.ai/code/routines ببین.");
    }
    $state['_secrets'] = $present;
    file_put_contents(ops_var('fire.json'), json_encode($state));
}
