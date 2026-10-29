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
];
