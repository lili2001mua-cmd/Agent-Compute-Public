<?php
// Holding vault → this subdomain (handbook github-four-tokens, Agent-Project-Backup/Memory-Workflow/
// idea-orchestrations/github-four-tokens/HANDBOOK.md). Same file in every *-host package.
// The four GitHub tokens live only in <home>/holding-vault/ or <home>/domains/holding-vault/, outside every
// domain. The web side cannot open that folder (open_basedir), so the CRON copies the one token this
// subdomain needs into ../secrets-extra-vault.php (chmod 600), which the host's secret loader merges on top
// of secrets.php. Change a token in the vault ⇒ every subdomain has it on its next tick. Never prints a token.
if (!function_exists('hv_sync')) {
    /** @param array<string,string> $map  secret key => vault file name (gh-write-host.php / gh-read-host.php) */
    function hv_sync(string $root, array $map): string {
        if (PHP_SAPI !== 'cli') return 'web';
        $root = realpath($root) ?: $root;
        $dir = null;
        foreach ([dirname($root, 2), dirname($root)] as $base) {
            if (@is_dir($base . '/holding-vault')) { $dir = $base . '/holding-vault'; break; }
        }
        $state = 'ok';
        $out = [];
        if ($dir === null) $state = 'vault missing';
        else foreach ($map as $key => $file) {
            $t = @include $dir . '/' . $file;
            $t = is_string($t) ? trim($t) : '';
            if (!preg_match('/^(github_pat_|ghp_)[A-Za-z0-9_]{20,}$/', $t)) { $state = "unreadable $file"; continue; }
            $out[$key] = $t;
        }
        $dst = $root . '/secrets-extra-vault.php';
        if ($out) {
            $body = "<?php\n// written by app/vault-sync.php from the holding vault — do not edit, edit the vault\nreturn " . var_export($out, true) . ";\n";
            if (!is_file($dst) || file_get_contents($dst) !== $body) {
                file_put_contents($dst . '.tmp', $body, LOCK_EX);
                @chmod($dst . '.tmp', 0600);
                rename($dst . '.tmp', $dst);
            }
        }
        @mkdir($root . '/var', 0700, true);
        @file_put_contents($root . '/var/vault.json', json_encode(['state' => $state, 'keys' => array_keys($out), 'at' => time()]));
        return $state;
    }
}
