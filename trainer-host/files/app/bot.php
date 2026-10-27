<?php
// Telegram Bot API client for trainer.hohedoc.ir. Pure HTTPS via curl; no user session here.
// The bot can post/edit only messages it owns, so panels it manages must be (re)posted by the bot.
require_once __DIR__ . '/lib.php';

final class TrainerBot {
    private string $token;
    public function __construct(?string $token = null) {
        $this->token = $token ?? trainer_secret('TELEGRAM_BOT_TOKEN');
        if ($this->token === '') throw new RuntimeException('TELEGRAM_BOT_TOKEN missing in secrets.php');
    }
    /** Raw API call. Returns the decoded "result" on ok, throws on Telegram error. */
    public function call(string $method, array $params = []): mixed {
        $ch = curl_init("https://api.telegram.org/bot{$this->token}/{$method}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params),
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new RuntimeException("network: $err");
        $j = json_decode($raw, true);
        if (!is_array($j) || !($j['ok'] ?? false)) {
            // Never leak the token; Telegram errors don't contain it, but be explicit.
            throw new RuntimeException("telegram {$method} failed: " . ($j['description'] ?? 'unknown'));
        }
        return $j['result'];
    }
    public function getMe(): array { return $this->call('getMe'); }
    public function getChat(int|string $chat): array { return $this->call('getChat', ['chat_id' => $chat]); }
    /** Is the bot an admin that can post/edit in this chat? */
    public function botRights(int|string $chat): array {
        $me = $this->getMe();
        $m = $this->call('getChatMember', ['chat_id' => $chat, 'user_id' => $me['id']]);
        return [
            'status' => $m['status'] ?? '?',
            'can_post' => (bool) ($m['can_post_messages'] ?? ($m['status'] === 'administrator')),
            'can_edit' => (bool) ($m['can_edit_messages'] ?? ($m['status'] === 'administrator')),
        ];
    }
    public function send(int|string $chat, string $text, bool $preview = false): array {
        return $this->call('sendMessage', [
            'chat_id' => $chat, 'text' => mb_substr($text, 0, 4096),
            'disable_web_page_preview' => !$preview,
        ]);
    }
    public function edit(int|string $chat, int $messageId, string $text, bool $preview = false): array {
        return $this->call('editMessageText', [
            'chat_id' => $chat, 'message_id' => $messageId, 'text' => mb_substr($text, 0, 4096),
            'disable_web_page_preview' => !$preview,
        ]);
    }
    public function pin(int|string $chat, int $messageId): void {
        $this->call('pinChatMessage', ['chat_id' => $chat, 'message_id' => $messageId, 'disable_notification' => true]);
    }
}
