<?php
// REL phase 8: offline soak/performance bench. 6000 structured facts (attacks with witnesses, harm, aid,
// transfers, KO/wake) over ~4 game days through SocialStore::ingest in shadow mode. Budgets: p95 per fact
// and bounded tables after the retention passes; latent (pending) incidents and the ledger are never lost.
declare(strict_types=1);
if (getenv('STOBE_DB_NAME') !== 'stobe_social_phase1_test') throw new RuntimeException('Dedicated social DB required');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/social_runtime.php';
$db = $GLOBALS['db']; $n = 0;
function ok(bool $c, string $what): void { global $n; if (!$c) throw new RuntimeException('FAIL: ' . $what); ++$n; }
function sql(string $s, array $p = []): mixed { $r = $GLOBALS['db']->exec($s, $p); if ($r === false) throw new RuntimeException('SQL failed: ' . $s); return $r; }
function count_rows(string $t, string $where = 'true'): int { return (int)pg_fetch_result(sql("SELECT count(*) FROM $t WHERE $where"), 0, 0); }
foreach (['social_event_inbox','social_incident','social_belief','social_effect','social_evidence','social_checkpoint'] as $t) sql("DELETE FROM $t");
$people = [];
for ($i = 0; $i < 40; ++$i) {
    $name = sprintf('Bench Person %02d', $i); $serial = 900000 + $i;
    sql('DELETE FROM core_npc WHERE name=$1', [$name]);
    sql("INSERT INTO core_npc(name,extended_data,metadata) VALUES($1,'{}'::jsonb,jsonb_build_object('storage_id',$2::text))", [$name, 'hand_' . $serial]);
    $people[] = ['entity_key'=>"bench:1:$serial", 'serial'=>$serial, 'name'=>$name, 'storage_id'=>"hand_$serial", 'faction'=>'F' . ($i % 5), 'in_player_faction'=>false, 'conscious'=>true];
}
sql("INSERT INTO general_settings(id,value) VALUES('SOCIAL_RELATIONSHIP_MODE','shadow') ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value");
$store = new SocialStore($db);
mt_srand(42);
$times = []; $total = 6000; $ts = 100000;
$scope = ['campaign_id'=>'bench', 'timeline_epoch'=>'1', 'native_session_id'=>'bench'];
for ($seq = 1; $seq <= $total; ++$seq) {
    $ts += 60;
    $a = $people[mt_rand(0, 39)]; $b = $people[mt_rand(0, 39)];
    if ($a['entity_key'] === $b['entity_key']) $b = $people[($a['serial'] + 1 - 900000) % 40];
    $roll = mt_rand(1, 100);
    $wit = [];
    for ($w = 0; $w < 4; ++$w) { $p = $people[mt_rand(0, 39)]; if ($p['entity_key'] !== $a['entity_key'] && $p['entity_key'] !== $b['entity_key']) $wit[$p['entity_key']] = ['entity'=>$p, 'conscious'=>true, 'perceived'=>(bool)mt_rand(0, 1)]; }
    if ($roll <= 55) { $kind = 'attack'; $facts = ['victim_targeting_actor'=>(bool)mt_rand(0, 1)]; }
    elseif ($roll <= 75) { $kind = 'harm'; $facts = ['level'=>['injury','knockout','maiming'][mt_rand(0, 2)], 'attribution'=>'defeated_by', 'inventory'=>['iron plates'=>2, 'bread'=>1], 'inventory_total'=>3]; }
    elseif ($roll <= 85) { $kind = 'aid'; $facts = ['before_known'=>true, 'before_health'=>-0.4, 'before_blood'=>0.4, 'before_bleed'=>0.2, 'before_wound'=>200.0, 'before_untreated'=>200.0,
        'after_known'=>true, 'after_health'=>-0.3, 'after_blood'=>0.45, 'after_bleed'=>0.0, 'after_wound'=>190.0, 'after_untreated'=>60.0, 'conscious_before'=>(bool)mt_rand(0, 1)]; }
    elseif ($roll <= 93) { $kind = 'item_transfer'; $facts = ['items'=>['iron plates'=>1], 'to_ground'=>false, 'food_items'=>[], 'stolen_items'=>[], 'caught'=>null, 'loser_inventory_total'=>5]; $b['conscious'] = (bool)mt_rand(0, 1); }
    else { $kind = 'recovered'; $facts = ['inventory'=>['bread'=>1], 'inventory_total'=>1]; $a = null; }
    $e = ['schema_version'=>1, 'event_id'=>"bench:1:$seq", 'incident_id'=>"bench:1:$seq", 'campaign_id'=>'bench', 'timeline_epoch'=>'1', 'native_session_id'=>'bench',
        'sequence'=>$seq, 'game_ts'=>$ts, 'event_kind'=>$kind, 'origin'=>'gameplay', 'actor'=>$a, 'target'=>$b, 'state_before'=>[], 'state_after'=>[],
        'witnesses'=>array_values($wit), 'facts'=>['source'=>'structured'] + $facts];
    $pendingBefore = $seq % 2000 === 0 ? count_rows('social_incident', "state->>'phase'='pending_awareness'") : null;
    $t0 = hrtime(true);
    $r = $store->ingest($e, $scope, 'shadow');
    $times[] = (hrtime(true) - $t0) / 1e6;
    if ($pendingBefore !== null) ok(count_rows('social_incident', "state->>'phase'='pending_awareness'") >= $pendingBefore - 1, "retention keeps latent incidents (seq $seq)");
    if (($r['status'] ?? '') !== 'captured') throw new RuntimeException("FAIL: bench fact $seq " . ($r['status'] ?? '?'));
}
sort($times);
$p = fn(float $q) => round($times[(int)floor($q * (count($times) - 1))], 2);
$result = ['facts'=>$total, 'game_days'=>round(($ts - 100000) / 86400, 1), 'ms_p50'=>$p(0.5), 'ms_p95'=>$p(0.95), 'ms_max'=>round(end($times), 2),
    'rows'=>['inbox'=>count_rows('social_event_inbox'), 'incidents'=>count_rows('social_incident'), 'checkpoints'=>count_rows('social_checkpoint'),
             'effects'=>count_rows('social_effect'), 'beliefs'=>count_rows('social_belief')],
    'applied_effects'=>count_rows('social_effect', 'applied')];
echo json_encode($result), "\n";
ok($result['ms_p95'] < 80, 'p95 per fact under 80 ms (' . $result['ms_p95'] . ')');
ok($result['rows']['inbox'] < $total, 'retention bounded the raw inbox (' . $result['rows']['inbox'] . ' of ' . $total . ')');
ok($result['rows']['effects'] > 0 && $result['applied_effects'] === 0, 'shadow ledger kept, nothing applied');
ok($result['rows']['checkpoints'] < $total * 3, 'checkpoints bounded (' . $result['rows']['checkpoints'] . ')');
$oldFinished = (int)pg_fetch_result(sql("SELECT count(*) FROM social_incident WHERE campaign_id='bench' AND game_ts < $1 AND state->>'phase' NOT IN ('pending_awareness','freed')", [$ts - 259200 - 2000 * 60]), 0, 0);
ok($oldFinished === 0, "no finished incident older than the retention window survives ($oldFinished)");
sql("UPDATE general_settings SET value='off' WHERE id='SOCIAL_RELATIONSHIP_MODE'");
@file_put_contents(dirname(__DIR__, 2) . '/test-results/perf_bench.json', json_encode($result, JSON_PRETTY_PRINT));
echo "$n perf/soak checks passed\n";
