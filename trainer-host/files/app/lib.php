<?php
// Shared helpers for trainer.hohedoc.ir. Secrets live in ../secrets.php (outside public_html).
const TRAINER_ROOT = __DIR__ . '/..';

function trainer_secrets(): array {
    static $s = null;
    if ($s === null) {
        $f = TRAINER_ROOT . '/secrets.php';
        $s = is_file($f) ? (array) (include $f) : [];
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
