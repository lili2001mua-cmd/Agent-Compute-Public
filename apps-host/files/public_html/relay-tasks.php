<?php
// Public task list of apps.hohedoc.ir for the Iran relay (1405-08-11 · orchestra github-native-doors, section D).
// Lists «read this Iranian page» tasks waiting to be pulled by https://relay.hohe1.ir/intake/tasks.php .
// Nothing here is secret: each task is a public Iranian URL plus two random names; the answer is written on the relay's
// own disk, so a forged answer cannot come from here. Tasks expire after one hour.
require __DIR__ . '/../app/jobs.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo json_encode(['ok' => true, 'tasks' => relay_tasks_list()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
