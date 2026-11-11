<?php
// Scoped chain lease — self-test (1405-08-20 · plan: Agent-Project-Backup Memory-Workflow/idea-orchestrations/chain-lease-scopes/PLAN.md).
// Runs the real lease functions from ../files/app/jobs/phase6.php against the committee's failure modes. Not deployed.
// Usage: php tg-host/tests/lease-scopes-test.php
$tmp = sys_get_temp_dir() . '/lease-test-' . getmypid(); @mkdir($tmp . '/app/jobs', 0777, true);
$src = file_get_contents(__DIR__ . '/../files/app/jobs/phase6.php');
$a = strpos($src, '// ---- lease + heartbeat'); $b = strpos($src, '/** Session heartbeat');
file_put_contents($tmp . '/app/jobs/lease.php', "<?php\n" . substr($src, $a, $b - $a));
file_put_contents($tmp . '/app/keys.php', "<?php return ['read'=>'x','chain'=>'y'];");
define('TG_BASE', $tmp);
function tg_dir($d){ if(!is_dir($d)) mkdir($d,0777,true); return $d; }
function tg_var(string $rel=''): string { $d=tg_dir(TG_BASE.'/var'); return $rel===''?$d:$d.'/'.$rel; }
function tg_write_json(string $p, array $d): void { file_put_contents($p.'.tmp', json_encode($d)); rename($p.'.tmp',$p); }
function tg_read_json(string $p): ?array { if(!is_file($p)) return null; $d=json_decode(file_get_contents($p),true); return is_array($d)?$d:null; }
require $tmp.'/app/jobs/lease.php';
$F=0; function ok($c,$m){ global $F; echo ($c?"  ok   ":"  FAIL ").$m."\n"; if(!$c)$F++; }
function L($a){ return tg_chain_lease($a); }
@unlink(tg_var('chain-lease.json'));
// legacy file read as both scopes
file_put_contents(tg_var('chain-lease.json'), json_encode(['run'=>'old-run-1','token'=>41,'exp'=>time()+300,'since'=>time()]));
$st=L(['op'=>'status'])[1]; ok($st['scopes']['tg']['held'] && $st['scopes']['drive']['held'] && $st['run']==='old-run-1', "legacy file = both scopes held by its run");
ok(L(['op'=>'acquire','run'=>'new-run-a','scopes'=>['drive']])[0]===409, "legacy holder blocks a drive-only acquire");
try { tg_chain_lease_check('old-run-1',41,'tg'); ok(true,"legacy token still valid for tg"); } catch(Throwable $e){ ok(false,"legacy token still valid for tg"); }
ok(L(['op'=>'release','run'=>'old-run-1','token'=>41])[0]===200, "legacy holder releases both");
// validation
foreach ([[],['main'],'tg',['tg','drive','tg','x'],['tg'=>1],[1]] as $bad) ok(L(['op'=>'acquire','run'=>'run-val-1','scopes'=>$bad])[0]===400, "bad scopes rejected: ".json_encode($bad));
// disjoint scopes run together; seq global and monotonic
$a=L(['op'=>'acquire','run'=>'run-tg-a','scopes'=>['tg']]); $b=L(['op'=>'acquire','run'=>'run-dr-b','scopes'=>['drive']]);
ok($a[0]===200 && $b[0]===200, "tg-only and drive-only runs hold at the same time");
ok($b[1]['token']===$a[1]['token']+1 && $a[1]['token']===42, "one global counter continues after the legacy token (42, 43)");
$c=L(['op'=>'acquire','run'=>'run-full-c']); ok($c[0]===409 && $c[1]['run']!==null && array_key_exists('exp',$c[1]), "old client (no scopes) waits while any scope is held; 409 keeps run/exp");
// fencing per scope
try { tg_chain_lease_check('run-tg-a',$a[1]['token'],'drive'); ok(false,"tg holder cannot mint drive"); } catch(Throwable $e){ ok(true,"tg holder cannot mint drive"); }
try { tg_chain_lease_check('run-dr-b',$b[1]['token'],'drive'); ok(true,"drive holder passes drive check"); } catch(Throwable $e){ ok(false,"drive holder passes drive check"); }
// no escalation; subset returns same token
$e=L(['op'=>'acquire','run'=>'run-tg-a','scopes'=>['drive','tg'],'queue'=>true]); ok($e[0]===409 && $e[1]['error']==='no escalation', "same run asking more scopes = 409 no escalation");
ok(L(['op'=>'status'])[1]['waiting']===1, "with queue:true the no-escalation 409 still files a ticket");
$s=L(['op'=>'acquire','run'=>'run-tg-a','scopes'=>['tg']]); ok($s[0]===200 && $s[1]['token']===$a[1]['token'], "same run, subset ⇒ same token (children keep theirs)");
// renew/release all-or-nothing, never touching another run's scope
ok(L(['op'=>'renew','run'=>'run-tg-a','token'=>999])[0]===409, "renew with a wrong token = 409");
$r=L(['op'=>'renew','run'=>'run-tg-a','token'=>$a[1]['token']]); ok($r[0]===200 && $r[1]['scopes']===['tg'], "renew answers the scopes still owned");
ok(L(['op'=>'release','run'=>'run-tg-a','token'=>$a[1]['token']])[0]===200, "release tg");
ok(L(['op'=>'status'])[1]['scopes']['drive']['held'] && L(['op'=>'status'])[1]['scopes']['drive']['run']==='run-dr-b', "release never touches another run's scope");
// FIFO: older ticket for tg blocks a newer tg acquire; own ticket never blocks itself
$q1=L(['op'=>'acquire','run'=>'run-q-old','scopes'=>['drive','tg'],'queue'=>true]); ok($q1[0]===409, "full-set waiter queued behind drive holder");
$n=L(['op'=>'acquire','run'=>'run-tg-new','scopes'=>['tg'],'queue'=>true]); ok($n[0]===409 && $n[1]['error']==='queued' && $n[1]['run']==='run-tg-a', "newer tg acquire waits behind the oldest overlapping ticket (no starvation)");
L(['op'=>'release','run'=>'run-dr-b','token'=>$b[1]['token']]);
// the escalation ticket of run-tg-a is older than run-q-old: it must be served first
$x=L(['op'=>'acquire','run'=>'run-q-old','scopes'=>['drive','tg'],'queue'=>true]); ok($x[0]===409 && $x[1]['run']==='run-tg-a', "the earliest ticket (run-tg-a, filed before its release) keeps its place");
$g=L(['op'=>'acquire','run'=>'run-tg-a','scopes'=>['drive','tg'],'queue'=>true]); ok($g[0]===200, "own ticket never blocks its own acquire; ticket removed on grant");
ok(!in_array('run-tg-a', array_column(tg_lease_load()['waiting'],'run')), "granted run's ticket is gone");
// ticket expiry
$l=tg_lease_load(); foreach($l['waiting'] as &$t) $t['seen']=time()-120; unset($t); tg_lease_save($l);
L(['op'=>'release','run'=>'run-tg-a','token'=>$g[1]['token']]);
ok(L(['op'=>'acquire','run'=>'run-late-1','scopes'=>['tg']])[0]===200, "a ticket not refreshed for 60 s expires and blocks nobody");
// rollback mirror: top-level fields mirror tg holder
$raw=json_decode(file_get_contents(tg_var('chain-lease.json')),true); ok($raw['v']===2 && $raw['run']==='run-late-1' && $raw['token']===$raw['scopes']['tg']['token'], "on-disk mirror fields follow the tg holder (old code fails closed)");
ok(!isset(L(['op'=>'status'])[1]['token']), "status never returns a token");
// expiry: an expired scope is free; renew of an expired scope fails without change
$l=tg_lease_load(); $l['scopes']['tg']['exp']=time()-1; tg_lease_save($l);
ok(L(['op'=>'renew','run'=>'run-late-1','token'=>$raw['token']])[0]===409, "renew after expiry = 409, nothing changed");
ok(L(['op'=>'acquire','run'=>'run-after-exp','scopes'=>['tg']])[0]===200, "expired scope is free to take");
echo "\n# lease tests: $F failures\n"; exit($F?1:0);
