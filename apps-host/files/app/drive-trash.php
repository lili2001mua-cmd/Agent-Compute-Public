<?php
// drive-trash — one-approval bulk trash on Google Drive (user request 1405-08-10).
// The Claude session writes Drive file/folder ids into app/drive-trash.txt (one per line, '#' comments allowed)
// and pushes once; this cron leg moves them to the Drive trash in small batches, never a permanent delete
// (Drive empties its trash after 30 days, so a mistake stays recoverable for that long).
// Progress lives in var/drive-trash.json and is shown on status.php as `drive_trash`.
// A new list (different content hash) starts a fresh run; an id already done in this run is never re-sent.
// A line `restore <id>` takes the file back out of the trash instead (1405-08-11).

const DRIVE_TRASH_LIST = __DIR__ . '/drive-trash.txt';
const DRIVE_TRASH_PER_TICK = 60;

function drive_trash_ids(): array {
    if (!is_file(DRIVE_TRASH_LIST)) return [];
    $ids = [];
    foreach (file(DRIVE_TRASH_LIST, FILE_IGNORE_NEW_LINES) as $l) {
        $l = trim(preg_replace('/#.*/', '', $l));
        if ($l !== '' && preg_match('/^(restore\s+)?[A-Za-z0-9_-]{10,80}$/', $l)) $ids[preg_replace('/\s+/', ' ', $l)] = true;
    }
    return array_keys($ids);
}

function drive_trash_tick(): void {
    $ids = drive_trash_ids();
    $sp = apps_var('drive-trash.json');
    $st = @json_decode((string) @file_get_contents($sp), true) ?: [];
    $hash = substr(hash('sha256', implode("\n", $ids)), 0, 16);
    if (($st['list'] ?? '') !== $hash) $st = ['list' => $hash, 'total' => count($ids), 'done' => [], 'failed' => [], 'started' => gmdate('c')];
    if (!$ids || apps_secret('DRIVE_OAUTH') === '') { $st['note'] = $ids ? 'no DRIVE_OAUTH in cron' : 'empty list'; file_put_contents($sp, json_encode($st)); return; }
    $root = apps_secret('DRIVE_ROOT_ID');
    $n = 0;
    foreach ($ids as $line) {
        $restore = strpos($line, 'restore ') === 0;
        $id = $restore ? substr($line, 8) : $line;
        if (isset($st['done'][$line])) continue;
        if ($n >= DRIVE_TRASH_PER_TICK) break;
        $n++;
        if (!$restore && $id === $root) { $st['failed'][$line] = 'refused: the Memory-Cloud root itself'; $st['done'][$line] = 0; continue; }
        try {
            drive_call('PATCH', '/files/' . rawurlencode($id), ['supportsAllDrives' => 'true'], ['trashed' => !$restore]);
            $st['done'][$line] = 1;
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (!$restore && strpos($msg, '→ 404') !== false) { $st['done'][$line] = 1; continue; }   // already gone
            $st['failed'][$line] = substr($msg, 0, 160);
            $st['done'][$line] = 0;
        }
    }
    $st['trashed'] = count(array_filter($st['done']));
    $st['last'] = gmdate('c');
    file_put_contents($sp, json_encode($st));
}
