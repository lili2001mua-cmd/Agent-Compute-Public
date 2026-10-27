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

// Board/notify primitives: the host-bot owns the channel messages it posts, so it can edit/delete
// them later. The chain hands rendered text here over HTTPS; these three do the Telegram side.
function job_post(array $args = []): array {
    $chat = (int) ($args['chat'] ?? 0);
    $text = (string) ($args['text'] ?? '');
    if (!$chat || $text === '') throw new RuntimeException('chat and text required');
    $r = (new TrainerBot())->send($chat, $text, false);
    return ['message_id' => $r['message_id'] ?? null, 'chat' => $chat];
}

function job_edit(array $args = []): array {
    $chat = (int) ($args['chat'] ?? 0);
    $mid = (int) ($args['mid'] ?? 0);
    $text = (string) ($args['text'] ?? '');
    if (!$chat || !$mid || $text === '') throw new RuntimeException('chat, mid and text required');
    (new TrainerBot())->edit($chat, $mid, $text, false);
    return ['edited' => $mid, 'chat' => $chat];
}

function job_del(array $args = []): array {
    $chat = (int) ($args['chat'] ?? 0);
    $mid = (int) ($args['mid'] ?? 0);
    if (!$chat || !$mid) throw new RuntimeException('chat and mid required');
    (new TrainerBot())->delete($chat, $mid);
    return ['deleted' => $mid, 'chat' => $chat];
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
    $inputs = [];
    if (isset($args['mode'])) $inputs['mode'] = preg_replace('/[^a-z-]/', '', (string) $args['mode']);
    (new GitHub())->dispatch($wf, $ref, $inputs);
    return ['dispatched' => $wf, 'ref' => $ref, 'inputs' => $inputs];
}

function job_enable(array $args = []): array {
    $wf = preg_replace('/[^a-z0-9._-]/i', '', (string) ($args['wf'] ?? ''));
    $allowedWf = ['nightly-pool.yml', 'telegram-panel.yml', 'telegram-notify.yml', 'step3-watchdog.yml'];
    if (!in_array($wf, $allowedWf, true)) throw new RuntimeException('workflow not allowed');
    (new GitHub())->enable($wf);
    return ['enabled' => $wf];
}

function job_runstatus(array $args = []): array {
    $wf = preg_replace('/[^a-z0-9._-]/i', '', (string) ($args['wf'] ?? ''));
    if ($wf === '') throw new RuntimeException('wf required');
    return (new GitHub())->latestRun($wf);
}

function job_wfstate(array $args = []): array {
    $wf = preg_replace('/[^a-z0-9._-]/i', '', (string) ($args['wf'] ?? ''));
    if ($wf === '') throw new RuntimeException('wf required');
    return ['wf' => $wf, 'state' => (new GitHub())->workflowState($wf)];
}

// Manual nightly fire for the joint test: enable (if needed) then dispatch mode=plan, bypassing
// the clock's time window. Same path the host takes at 03:00; use for the live test with the user.
function job_nightly(array $args = []): array {
    $mode = preg_replace('/[^a-z-]/', '', (string) ($args['mode'] ?? 'plan'));
    $gh = new GitHub();
    if ($gh->workflowState('nightly-pool.yml') !== 'active') $gh->enable('nightly-pool.yml');
    $gh->dispatch('nightly-pool.yml', 'main', ['mode' => $mode]);
    return ['dispatched' => 'nightly-pool.yml', 'mode' => $mode];
}
