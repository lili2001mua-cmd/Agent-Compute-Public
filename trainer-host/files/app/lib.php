<?php
// Shared helpers for trainer.hohedoc.ir. Secrets live in ../secrets.php (outside public_html).
// An optional ../secrets-extra.php (same "return [...]" shape) is merged on top, so new keys
// can be added by uploading one small file instead of editing the big secrets.php.
const TRAINER_ROOT = __DIR__ . '/..';

function trainer_secrets(): array {
    static $s = null;
    if ($s === null) {
        $s = [];
        foreach (['/secrets.php', '/secrets-extra.php'] as $f) {
            $p = TRAINER_ROOT . $f;
            if (is_file($p)) {
                $part = include $p;
                if (is_array($part)) $s = array_merge($s, array_filter($part, fn($v) => $v !== '' && $v !== null));
            }
        }
    }
    return $s;
}

function trainer_secret(string $key): string {
    return (string) (trainer_secrets()[$key] ?? '');
}

function trainer_state_path(string $name): string {
    $dir = TRAINER_ROOT . '/var';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    return $dir . '/' . $name;
}

function trainer_log(string $stream, string $line): void {
    file_put_contents(trainer_state_path($stream . '.log'), gmdate('c') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}
