<?php
// Minimal GitHub REST client for the host. Uses GH_DISPATCH_TOKEN, a fine-grained PAT whose ONLY
// permission is Actions: read+write on the private repo — it cannot read secrets or push code.
// Purpose: let the host start a one-shot workflow when real work is pending, so the repeated
// watching happens free on the host and Actions runs only on demand.
require_once __DIR__ . '/lib.php';

final class GitHub {
    private string $token;
    public string $owner;
    public string $repo;
    public function __construct(?string $token = null, string $repo = 'lili2001mua-cmd/Agent-Project-Backup') {
        $this->token = $token ?? trainer_secret('GH_DISPATCH_TOKEN');
        if ($this->token === '') throw new RuntimeException('GH_DISPATCH_TOKEN missing in secrets');
        [$this->owner, $this->repo] = array_pad(explode('/', $repo, 2), 2, '');
    }
    private function req(string $method, string $path, ?array $body = null): array {
        $ch = curl_init("https://api.github.com{$path}");
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: trainer-host',
        ];
        $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers];
        if ($body !== null) { $opt[CURLOPT_POSTFIELDS] = json_encode($body); $headers[] = 'Content-Type: application/json'; $opt[CURLOPT_HTTPHEADER] = $headers; }
        curl_setopt_array($ch, $opt);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new RuntimeException("network: $err");
        return ['code' => $code, 'body' => $raw];
    }
    /** Confirm the token can see the repo and reports its Actions scope. */
    public function check(): array {
        $r = $this->req('GET', "/repos/{$this->owner}/{$this->repo}");
        if ($r['code'] !== 200) throw new RuntimeException("repo check HTTP {$r['code']}");
        $j = json_decode($r['body'], true);
        return ['repo' => $j['full_name'] ?? null, 'private' => $j['private'] ?? null];
    }
    /** Start one run of a workflow file on a ref. Returns true on 204. */
    public function dispatch(string $workflowFile, string $ref, array $inputs = []): bool {
        $r = $this->req('POST', "/repos/{$this->owner}/{$this->repo}/actions/workflows/{$workflowFile}/dispatches",
            array_filter(['ref' => $ref, 'inputs' => $inputs ?: null]));
        if ($r['code'] === 204) return true;
        throw new RuntimeException("dispatch HTTP {$r['code']}: " . substr($r['body'], 0, 200));
    }
}
