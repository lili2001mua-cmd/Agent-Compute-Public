<?php
// The only jobs the door accepts. role = minimum key role; args = allowlist with type rules; retry = resume after a kill;
// tg = loads MadelineProto (tick.php ends the tick after it: one library instance per process).
return [
    'ping'      => ['role' => 'read',  'args' => [], 'retry' => true],
    'probe'     => ['role' => 'read',  'args' => [], 'retry' => true],
    'calibrate' => ['role' => 'read',  'args' => ['minutes' => 'int:1:25', 'mode' => 'enum:idle,cpu'], 'retry' => false],
    'bigfile'   => ['role' => 'read',  'args' => ['mb' => 'int:1:400'], 'retry' => false],
    // phase 2 — login (never retried: a resent code or a replayed password must never happen behind the user's back)
    'login_start'    => ['role' => 'admin', 'args' => [], 'retry' => false, 'tg' => true],
    'login_code'     => ['role' => 'admin', 'args' => ['code' => 'str:[0-9]{5,6}'], 'retry' => false, 'tg' => true],
    'login_password' => ['role' => 'admin', 'args' => ['password' => 'str:.{1,256}'], 'retry' => false, 'tg' => true],
    'login_state'    => ['role' => 'read',  'args' => [], 'retry' => true],
    'tg_self'        => ['role' => 'admin', 'args' => [], 'retry' => true, 'tg' => true],
    // phase 3 — read-only (a read key may only name an allowlisted chat; see phase3.php)
    'read_recent' => ['role' => 'read', 'args' => ['query' => 'str:.{1,80}', 'limit' => 'int:1:500'], 'retry' => true, 'tg' => true],
    'last_video'  => ['role' => 'read', 'args' => ['query' => 'str:.{1,80}', 'max_scan' => 'int:1:2000'], 'retry' => true, 'tg' => true],
    'fetch'       => ['role' => 'read', 'args' => ['target' => 'str:(saved|all|@?[A-Za-z0-9_]{3,64}|-?[0-9]{1,20})', 'per_chat' => 'int:1:3000', 'all_files' => 'enum:0,1'], 'retry' => true, 'tg' => true],
    'batches'     => ['role' => 'read', 'args' => ['query' => 'str:.{1,80}', 'sets' => 'int:1:200', 'scan' => 'int:1:5000', 'offset' => 'int:0:2000', 'sleep' => 'int:0:120', 'dry' => 'enum:0,1'], 'retry' => true, 'tg' => true],
];
