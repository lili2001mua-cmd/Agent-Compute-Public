#!/usr/bin/env bash
# Runs sites-host's real tick.php against mock.php (fake GitHub + fake sync.php) and checks:
# signed deploy+delete reach the sites, the result is written once, an old request that already has a
# result is never re-run, a second tick does nothing, and an expired token shows up as last_error.
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
T=$(mktemp -d); trap 'kill $SRV 2>/dev/null; rm -rf "$T"' EXIT
mkdir -p "$T/site/app" "$T/site/public_html" "$T/mock"
cp "$HERE/../files/app/"*.php "$T/site/app/"; cp "$HERE/../files/public_html/"*.php "$T/site/public_html/"
printf "<?php return ['GITHUB_TOKEN' => 't', 'STYLISTCRM_DEPLOY_SECRET' => 'test-deploy-secret', 'STYLISTCRM_QUEUE_STAMP_KEY' => 'test-stamp-key'];\n" > "$T/site/secrets.php"
# The request as a hooked session writes it, stamped by the real gate code (same MAC text the host checks).
stamp() { python3 - "$1" "${2:-new}" <<'PY'
import json, sys, time
sys.path.insert(0, sys.argv[1])
from host_transfer_gate import stamp_mac
qid = sys.argv[2]
if qid == "ops":
    req = {"ref": "main", "groups": [{"action": "manifest", "env": "pwa", "files": []},
                                     {"action": "run", "env": "pwa", "files": ["probe.php?x=1"]},
                                     {"action": "secprobe", "env": "pwa", "files": []}]}
else:
    req = {"ref": "main", "groups": [{"action": "deploy", "env": "pwa", "files": ["pwa/public_html/a.php"]},
                                     {"action": "delete", "env": "hub", "files": ["hub/public_html/b.php"]}]}
at, quote = int(time.time()), "تأیید انتقال به هاست"
req["approval"] = {"v": 1, "by": "host_transfer_gate", "at": at, "quote": quote,
                   "mac": stamp_mac("test-stamp-key", qid, req, at, quote)}
print(json.dumps(req, ensure_ascii=False))
PY
}
GATE_LIB="$HERE/../../../Agent-Project-Backup/Sys.scripts/lib"
python3 -c "import json;print(json.dumps({'ref':'main','groups':[{'action':'deploy','env':'pwa','files':['pwa/public_html/a.php']}]}))" > "$T/mock/new.json"
MOCK_DIR="$T/mock" php -S 127.0.0.1:8765 "$HERE/mock.php" >/dev/null 2>&1 & SRV=$!; sleep 1
export DH_GH_API=http://127.0.0.1:8765 DH_SITE_BASE=http://127.0.0.1:8765
fail=0; check() { if eval "$2"; then echo "PASS $1"; else echo "FAIL $1"; fail=1; fi; }
php "$T/site/app/tick.php"
check "unstamped request waits, never runs" "! grep -q 'sync pwa deploy' $T/mock/calls.log && php $T/site/public_html/status.php | grep -q 'stylistcrm/new'"
stamp "$GATE_LIB" > "$T/mock/new.json"; php "$T/site/app/tick.php"
check "deploy and delete reached the sites" "grep -q 'sync pwa deploy' $T/mock/calls.log && grep -q 'sync hub delete' $T/mock/calls.log"
check "result written for new request" "grep -q '\"ok\": true' $T/mock/result-new.json"
check "sha256 verified by site" "grep -q '\"repo\": \"pwa/public_html/a.php\"' $T/mock/result-new.json"
check "old request not re-run" "! grep -q 'put old' $T/mock/calls.log"
check "status shows no error" "php $T/site/public_html/status.php | grep -q '\"last_error\": null'"
stamp "$GATE_LIB" ops > "$T/mock/ops.json"; php "$T/site/app/tick.php"
check "manifest pages through the bridge" "[ \$(grep -c 'sync pwa manifest' $T/mock/calls.log) -eq 2 ] && [ \$(wc -l < $T/mock/manifest-pwa.txt) -eq 3 ]"
check "run opens the page on the env host" "grep -q 'run probe 1' $T/mock/calls.log && grep -q ran-ok $T/mock/run.txt"
check "secprobe flags the exposed file, the listing and the leaky error page" "grep -q 'secprobe env' $T/mock/calls.log && grep -q '3 یافتهٔ حساس' $T/mock/security-probe-pwa.md && grep -q 'لیستِ دایرکتوریِ باز' $T/mock/security-probe-pwa.md && grep -q 'استک‌تریس' $T/mock/security-probe-pwa.md && ! grep -q 'DB_PASS' $T/mock/security-probe-pwa.md"
n=$(grep -c 'put new' $T/mock/calls.log); php "$T/site/app/tick.php"
check "second tick does not re-run" "[ \$(grep -c 'put new' $T/mock/calls.log) -eq $n ]"
touch "$T/mock/mode-401"; rm -f "$T/site/var/state.json"; php "$T/site/app/tick.php"
check "expired token is loud" "php $T/site/public_html/status.php | grep -q 'HTTP 401'"
exit $fail
