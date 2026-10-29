<?php
// The only jobs the door accepts. role = minimum key role; args = allowlist with type rules; retry = resume after a kill.
// Phase 1: health and calibration only — no Telegram code yet.
return [
    'ping'      => ['role' => 'read',  'args' => [], 'retry' => true],
    'probe'     => ['role' => 'read',  'args' => [], 'retry' => true],
    'calibrate' => ['role' => 'read',  'args' => ['minutes' => 'int:1:25', 'mode' => 'enum:idle,cpu'], 'retry' => false],
    'bigfile'   => ['role' => 'read',  'args' => ['mb' => 'int:1:400'], 'retry' => false],
];
