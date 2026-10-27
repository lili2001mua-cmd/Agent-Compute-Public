<?php
// Job: verify the bot works and report its rights in the two trainer channels.
// Returns data only (names/ids of org channels are not personal data); never the token.
require_once __DIR__ . '/../bot.php';
require_once __DIR__ . '/../gh.php';

function job_channels(array $args = []): array {
    $bot = new TrainerBot();
    $me = $bot->getMe();
    $out = ['bot' => ['id' => $me['id'] ?? null, 'username' => $me['username'] ?? null], 'channels' => []];
    // Channel ids are tracked in the private repo, passed in here by the caller.
    $channels = [
        'pool_menu' => (int) ($args['pool'] ?? -1004295411611),
        'trainer_control' => (int) ($args['control'] ?? -1004359416005),
    ];
    foreach ($channels as $name => $id) {
        try {
            $chat = $bot->getChat($id);
            $rights = $bot->botRights($id);
            $out['channels'][$name] = [
                'id' => $id, 'title' => $chat['title'] ?? null,
                'status' => $rights['status'], 'can_post' => $rights['can_post'], 'can_edit' => $rights['can_edit'],
            ];
        } catch (Throwable $e) {
            $out['channels'][$name] = ['id' => $id, 'error' => $e->getMessage()];
        }
    }
    return $out;
}

function job_getme(array $args = []): array {
    return (new TrainerBot())->getMe();
}

function job_sendtest(array $args = []): array {
    $chat = (int) ($args['chat'] ?? 0);
    if (!$chat) throw new RuntimeException('chat required');
    $r = (new TrainerBot())->send($chat, $args['text'] ?? 'TEST — trainer.hohedoc.ir آزمایشِ اتصال', false);
    return ['message_id' => $r['message_id'] ?? null];
}

function job_ghcheck(array $args = []): array {
    return (new GitHub())->check();
}

function job_dispatch(array $args = []): array {
    $wf = preg_replace('/[^a-z0-9._-]/i', '', (string) ($args['wf'] ?? ''));
    $ref = (string) ($args['ref'] ?? 'main');
    if ($wf === '') throw new RuntimeException('wf required');
    $allowedWf = ['nightly-pool.yml', 'telegram-panel.yml', 'telegram-notify.yml', 'step3-watchdog.yml'];
    if (!in_array($wf, $allowedWf, true)) throw new RuntimeException('workflow not allowed');
    (new GitHub())->dispatch($wf, $ref);
    return ['dispatched' => $wf, 'ref' => $ref];
}
