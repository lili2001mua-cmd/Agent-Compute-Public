#!/usr/bin/env bash
# Runs deploy-host's real tick.php against mock.php (fake GitHub + fake sync.php) and checks:
# signed deploy+delete reach the sites, the result is written once, an old request that already has a
# result is never re-run, a second tick does nothing, and an expired token shows up as last_error.
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
T=$(mktemp -d); trap 'kill $SRV 2>/dev/null; rm -rf "$T"' EXIT
mkdir -p "$T/site/app" "$T/site/public_html" "$T/mock"
cp "$HERE/../files/app/"*.php "$T/site/app/"; cp "$HERE/../files/public_html/"*.php "$T/site/public_html/"
printf "<?php return ['GITHUB_TOKEN' => 't', 'DEPLOY_SECRET' => 'test-deploy-secret'];\n" > "$T/site/secrets.php"
MOCK_DIR="$T/mock" php -S 127.0.0.1:8765 "$HERE/mock.php" >/dev/null 2>&1 & SRV=$!; sleep 1
export DH_GH_API=http://127.0.0.1:8765 DH_SITE_BASE=http://127.0.0.1:8765
fail=0; check() { if eval "$2"; then echo "PASS $1"; else echo "FAIL $1"; fail=1; fi; }
php "$T/site/app/tick.php"
check "deploy and delete reached the sites" "grep -q 'sync pwa deploy' $T/mock/calls.log && grep -q 'sync hub delete' $T/mock/calls.log"
check "result written for new request" "grep -q '\"ok\": true' $T/mock/result-new.json"
check "sha256 verified by site" "grep -q '\"repo\": \"pwa/public_html/a.php\"' $T/mock/result-new.json"
check "old request not re-run" "! grep -q 'put old' $T/mock/calls.log"
check "status shows no error" "php $T/site/public_html/status.php | grep -q '\"last_error\": null'"
n=$(grep -c 'put new' $T/mock/calls.log); php "$T/site/app/tick.php"
check "second tick does not re-run" "[ \$(grep -c 'put new' $T/mock/calls.log) -eq $n ]"
touch "$T/mock/mode-401"; rm -f "$T/site/var/state.json"; php "$T/site/app/tick.php"
check "expired token is loud" "php $T/site/public_html/status.php | grep -q 'HTTP 401'"
exit $fail
