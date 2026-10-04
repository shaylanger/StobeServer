<?php
// B 55 (fights and relationships) regression: fights mode, harsher ranges x closeness, accidents, sparring,
// treatment relief, bleeding out on waking, grudge fading, chat cooldown/half rate, deal forgiveness, memory line.
// Oracles are the pinned spec ranges (STOBE_full_test_plan.md section E items 1-8), never the function under test.
declare(strict_types=1);
if (getenv('STOBE_DB_NAME') !== 'stobe_social_phase1_test') throw new RuntimeException('Dedicated social DB required');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/social_runtime.php';
require_once __DIR__ . '/../lib/relationship_manager.php';
require_once __DIR__ . '/../lib/social_dialogue.php';
require_once __DIR__ . '/../lib/social_agreements.php';
$db = $GLOBALS['db']; $n = 0;
function ok(bool $c, string $what): void { global $n; if (!$c) throw new RuntimeException('FAIL: ' . $what); ++$n; }
function sql(string $s, array $p = []): mixed { $r = $GLOBALS['db']->exec($s, $p); if ($r === false) throw new RuntimeException('SQL failed: ' . $s); return $r; }
function one(string $s, array $p = []): mixed { $r = sql($s, $p); return pg_num_rows($r) ? pg_fetch_result($r, 0, 0) : null; }
function affOf(string $a, string $b): int { return (int)(stobeRelationshipEntryFor(getNpcData($a), $b)['aff'] ?? 0); }
function setting(string $id, ?string $v): void {
    if ($v === null) sql('DELETE FROM general_settings WHERE id=$1', [$id]);
    else sql('INSERT INTO general_settings(id,value) VALUES($1,$2) ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value', [$id, $v]);
}

// name => [serial, faction, squad]
const P = ['Fight Shay'=>[601,'Nameless',true], 'Fight Mal'=>[602,'Nameless',true], 'Fight Mal Two'=>[603,'Nameless',true], 'Fight Mal Three'=>[604,'Nameless',true],
    'Bandit Rook'=>[610,'Dust Bandits',false], 'Bandit Fen'=>[611,'Dust Bandits',false], 'Bandit Fen Two'=>[612,'Dust Bandits',false], 'Bandit Fen Three'=>[613,'Dust Bandits',false],
    'Bandit Fen Four'=>[614,'Dust Bandits',false], 'Bandit Fen Five'=>[619,'Dust Bandits',false], 'Bandit Vex'=>[615,'Dust Bandits',false], 'Bandit Wex'=>[616,'Dust Bandits',false], 'Spar Bo'=>[617,'Drifters',false], 'Spar Kai'=>[618,'Drifters',false],
    'Wild Ana'=>[620,'Red',false], 'Wild Bel'=>[621,'Blue',false], 'Wild Cas'=>[622,'Red',false], 'Wild Dov'=>[623,'Blue',false], 'Wit Eon'=>[624,'Grey',false]];
foreach (['social_event_inbox','social_incident','social_belief','social_effect','social_evidence','social_checkpoint'] as $t) sql("DELETE FROM $t");
sql("DELETE FROM conf_opts WHERE id LIKE 'STOBE_REL_FADE_%' OR id LIKE 'STOBE_REL_SPAR_%'");
foreach (P as $name => [$serial]) {
    sql('DELETE FROM core_npc_master_history WHERE name=$1', [$name]); sql('DELETE FROM core_npc WHERE name=$1', [$name]);
    sql("INSERT INTO core_npc(name,extended_data,metadata) VALUES($1,'{}'::jsonb,jsonb_build_object('storage_id',$2::text))", [$name, 'hand_' . $serial]);
}
foreach (['SOCIAL_RELATIONSHIP_MODE','SOCIAL_FIGHTS_LIVE','SOCIAL_FIGHT_RULES','SOCIAL_GRUDGE_FADE_DAYS','SOCIAL_GRUDGE_FADE_THRESHOLD','SOCIAL_TEST_FORCE_FIRST_STRIKE'] as $id) setting($id, null);
sql("DELETE FROM stobe_meta.settings WHERE key='PLAYTHROUGH_AUTO_SWITCH' AND value<>'true'");
ok(one("SELECT value FROM stobe_meta.settings WHERE key='PLAYTHROUGH_AUTO_SWITCH'") === null, 'suite needs Playthrough Saves off (legacy campaign)');

// ---- mode ----
ok(stobeSocialIngestMode() === 'fights', 'B 55: REL off + SOCIAL_FIGHTS_LIVE default -> fights mode');
setting('SOCIAL_FIGHTS_LIVE', 'false'); ok(stobeSocialIngestMode() === 'off', 'SOCIAL_FIGHTS_LIVE=false -> off');
setting('SOCIAL_FIGHTS_LIVE', null); setting('SOCIAL_RELATIONSHIP_MODE', 'enabled'); ok(stobeSocialIngestMode() === 'enabled', 'enabled mode wins');
setting('SOCIAL_RELATIONSHIP_MODE', null);
ok(stobeSocialFightRulesOn(), 'b55 rules are the default');
ok(stobeRelationshipOnAttack('Bandit Rook: Initiated attack (talking to: Fight Shay)', 5000000) === [], 'R4 retired in fights mode');

$store = new SocialStore($db); $seq = 0;
const SCOPE = ['campaign_id'=>'legacy', 'timeline_epoch'=>'9', 'native_session_id'=>'sessF'];
function ent(string $name, ?bool $conscious = true): array {
    [$serial, $faction, $squad] = P[$name];
    return ['entity_key'=>"sessF:9:$serial", 'serial'=>$serial, 'name'=>$name, 'storage_id'=>"hand_$serial", 'faction'=>$faction, 'in_player_faction'=>$squad, 'conscious'=>$conscious];
}
function wit(string $name): array { return ['entity'=>ent($name), 'conscious'=>true, 'perceived'=>true]; }
function send(string $kind, ?array $actor, ?array $target, array $facts, int $ts, array $witnesses = [], string $mode = 'fights'): array {
    global $seq, $store; ++$seq;
    $e = ['schema_version'=>1, 'event_id'=>"sessF:9:$seq", 'incident_id'=>"sessF:9:$seq", 'campaign_id'=>'legacy', 'timeline_epoch'=>'9', 'native_session_id'=>'sessF',
        'sequence'=>$seq, 'game_ts'=>$ts, 'event_kind'=>$kind, 'origin'=>'gameplay', 'actor'=>$actor, 'target'=>$target,
        'state_before'=>[], 'state_after'=>[], 'witnesses'=>$witnesses, 'facts'=>['source'=>'structured'] + $facts];
    $r = $store->ingest($e, SCOPE, $mode);
    if (($r['status'] ?? '') !== 'captured') throw new RuntimeException("FAIL: event $seq not captured: " . json_encode($r));
    return $r;
}
function statuses(array $r): array { return array_map(static fn($e) => $e['status'] ?? null, $r['effects'] ?? []); }
function attack(string $a, string $b, int $ts, array $w = []): array { return send('attack', ent($a), ent($b), ['victim_targeting_actor'=>false], $ts, $w); }
function harm(string $a, string $b, string $level, int $ts, ?bool $conscious = true): array { return send('harm', ent($a), ent($b, $conscious), ['level'=>$level, 'attribution'=>'attacker_list'], $ts); }
function vit(string $p, float $h, float $b, float $bl, float $w, float $u): array {
    return [$p . 'known'=>true, $p . 'health'=>$h, $p . 'blood'=>$b, $p . 'bleed'=>$bl, $p . 'wound'=>$w, $p . 'untreated'=>$u];
}
function aid(string $provider, string $recipient, int $ts): array {
    return send('aid', ent($provider), ent($recipient), vit('before_', 0.2, 0.8, 0.5, 100, 80) + vit('after_', 0.2, 0.8, 0.0, 100, 10)
        + ['conscious_before'=>true, 'seconds'=>20, 'item'=>'Basic First Aid Kit', 'supplier_serial'=>0], $ts);
}
$tickPair = 0;
function tick(int $ts): array { // an unrelated wild fight nobody saw: ignored, but the ingest runs the fade pass
    global $tickPair; ++$tickPair;
    $r = send('attack', ent($tickPair % 2 ? 'Wild Ana' : 'Wild Cas'), ent($tickPair % 2 ? 'Wild Bel' : 'Wild Dov'), ['victim_targeting_actor'=>false], $ts);
    return $r;
}
function effectRow(string $obs, string $cul, string $component): ?array {
    $r = sql("SELECT delta, applied, detail FROM social_effect WHERE component=$3 AND detail->>'observer_name'=$1 AND detail->>'culprit_name'=$2 ORDER BY game_ts DESC LIMIT 1", [$obs, $cul, $component]);
    $row = pg_fetch_assoc($r);
    if (!$row) return null;
    $row['detail'] = json_decode($row['detail'], true);
    return $row;
}

// ---- item 5: only fights that matter (fights mode) ----
$r = attack('Wild Ana', 'Wild Bel', 100000);
ok(statuses($r) === ['ignored'] && affOf('Wild Bel', 'Wild Ana') === 0, 'item 5: a wild fight nobody who matters saw is ignored');
ok((int)one("SELECT count(*) FROM social_incident WHERE state->>'kind'='combat'") === 0, 'item 5: no encounter opened for it');
attack('Wild Cas', 'Wild Dov', 100010, [wit('Wit Eon')]);
$dc = affOf('Wild Dov', 'Wild Cas');
ok($dc >= -15 && $dc <= -10, "item 5+7: a named conscious witness makes it count, not-really-hurt range -10..-15 ($dc)");
ok(affOf('Wild Cas', 'Wild Dov') === 0, 'attacker unchanged');

// ---- item 7: squad fight, harsher ranges, one budget ----
attack('Fight Shay', 'Bandit Rook', 100100);
$a = affOf('Bandit Rook', 'Fight Shay');
ok($a >= -15 && $a <= -10, "item 7: aggression -10..-15 ($a)");
ok(affOf('Fight Shay', 'Bandit Rook') === 0, 'attacker feeling unchanged');
harm('Fight Shay', 'Bandit Rook', 'injury', 100110);
$i = affOf('Bandit Rook', 'Fight Shay');
ok($i >= -30 && $i <= -23 && $i <= $a, "item 7: wounded but standing -23..-30, one budget ($i)");
harm('Fight Shay', 'Bandit Rook', 'knockout', 100120, false);
$rook = affOf('Bandit Rook', 'Fight Shay');
ok($rook >= -50 && $rook <= -40, "item 7: knocked out -40..-50, not stacked ($rook)");

// ---- non-fight components are recorded, not applied, in fights mode ----
$r = aid('Wit Eon', 'Wild Dov', 100200);
$row = effectRow('Wild Dov', 'Wit Eon', 'meaningful_aid');
ok($row !== null && $row['applied'] === 'f' && affOf('Wild Dov', 'Wit Eon') === 0, 'fights mode: aid recorded (would-be effect), not applied');

// ---- item 7: closeness multiplier ----
RelationshipManager::setRelationship('Fight Mal', 'Fight Shay', 60);
attack('Fight Shay', 'Fight Mal', 100300);
$m1 = affOf('Fight Mal', 'Fight Shay') - 60;
ok($m1 >= -30 && $m1 <= -20, "item 7: Fond (60) x2.0 aggression -20..-30 ($m1)");
$row = effectRow('Fight Mal', 'Fight Shay', 'aggression');
ok((int)($row['detail']['pre_fight_affinity'] ?? -999) === 60 && (float)($row['detail']['modifiers']['closeness'] ?? 0) === 2.0, 'item 7: closeness from the feeling before the fight');
harm('Fight Shay', 'Fight Mal', 'knockout', 100310, false);
$m2 = affOf('Fight Mal', 'Fight Shay') - 60;
ok($m2 >= -100 && $m2 <= -80, "item 7: Fond KO -80..-100 (multiplier stays the pre-fight one) ($m2)");
RelationshipManager::setRelationship('Fight Mal Two', 'Fight Shay', 95);
attack('Fight Shay', 'Fight Mal Two', 100400);
$b1 = affOf('Fight Mal Two', 'Fight Shay') - 95;
ok($b1 >= -45 && $b1 <= -30, "item 7: Bonded x3.0 aggression -30..-45 ($b1)");
harm('Fight Shay', 'Fight Mal Two', 'maiming', 100410);
ok(affOf('Fight Mal Two', 'Fight Shay') === -5, 'item 7: Bonded + limb = floor -100 (95 -> -5) (' . affOf('Fight Mal Two', 'Fight Shay') . ')');

// ---- item 7: accidents (squad friendly fire without an attack) ----
harm('Fight Shay', 'Fight Mal Three', 'injury', 100500);
$x1 = affOf('Fight Mal Three', 'Fight Shay');
ok($x1 >= -8 && $x1 <= -6, "item 7: accidental injury = 0.25 x -23..-30 ($x1)");
harm('Fight Shay', 'Fight Mal Three', 'knockout', 100510, false);
$x2 = affOf('Fight Mal Three', 'Fight Shay');
ok($x2 >= -13 && $x2 <= -10, "item 7: accidental KO = 0.25 x -40..-50, one budget ($x2)");

// ---- item 3: sparring ----
ok(stobeSocialSparProposal('Want to spar with me?') && stobeSocialSparProposal('A friendly fight, just practice?') && !stobeSocialSparProposal('Hand over your cats.'), 'item 3: spar proposals detected');
ok(stobeSocialSparAgreed("Sure, let's go!") && !stobeSocialSparAgreed('No, not now.') && !stobeSocialSparAgreed('Hmm.'), 'item 3: agreement detected, refusal not');
stobeSocialRecordSpar('Fight Shay', 'Spar Bo', 100600, 'test');
$r = attack('Fight Shay', 'Spar Bo', 100610);
ok(statuses($r) === ['sparring'], 'item 3: agreed spar opens a sparring encounter');
harm('Fight Shay', 'Spar Bo', 'injury', 100620);
harm('Fight Shay', 'Spar Bo', 'knockout', 100625, false);
ok(affOf('Spar Bo', 'Fight Shay') === 0, 'item 3: spar hits and KO cost nothing');
harm('Fight Shay', 'Spar Bo', 'maiming', 100630);
$sp = affOf('Spar Bo', 'Fight Shay');
ok($sp >= -78 && $sp <= -68, "item 3: a limb lost in a spar still counts (-68..-78) ($sp)");
ok(!stobeSocialSparActive('Fight Shay', 'Spar Bo', 100600 + 3700), 'item 3: consent lasts one game hour');
RelationshipManager::setRelationship('Spar Kai', 'Fight Shay', 95);
stobeSocialRecordSpar('Spar Kai', 'Fight Shay', 100640, 'test');
attack('Fight Shay', 'Spar Kai', 100645);
harm('Fight Shay', 'Spar Kai', 'maiming', 100650);
ok(affOf('Spar Kai', 'Fight Shay') === -5, 'item 7: Bonded limb in one blow = -100 (95 -> -5, a change above 80 applied in steps) (' . affOf('Spar Kai', 'Fight Shay') . ')');

// ---- item 8: the attacker treating her wounds ----
attack('Fight Shay', 'Bandit Fen Two', 100700);
harm('Fight Shay', 'Bandit Fen Two', 'injury', 100710);
$p = affOf('Bandit Fen Two', 'Fight Shay');
$r = aid('Fight Shay', 'Bandit Fen Two', 101310);
$t = affOf('Bandit Fen Two', 'Fight Shay');
$relief = $t - $p;
ok(in_array('no_trust_from_own_harm', statuses($r), true) && $relief >= (int)round(-$p * 0.15) && $relief <= (int)round(-$p * 0.30) && $t < 0,
    "item 8: treatment takes 15-30 % off ($p -> $t), no positive trust");
aid('Fight Shay', 'Bandit Fen Two', 101320);
ok(affOf('Bandit Fen Two', 'Fight Shay') === $t, 'item 8: once per fight');
$share = stobeSocialTreatedShare('x', 'y', 0, new SocialRules()); $late = stobeSocialTreatedShare('x', 'y', 999999, new SocialRules());
ok($share > $late && $share <= 0.30 && $late >= 0.15, "item 8: sooner = more ($share > $late)");

// ---- item 7: bleeding out on waking ----
attack('Fight Shay', 'Bandit Vex', 101400);
harm('Fight Shay', 'Bandit Vex', 'knockout', 101410, false);
send('recovered', null, ent('Bandit Vex'), vit('', 0.1, 0.3, 0.5, 200, 150) + ['inventory'=>[], 'inventory_total'=>0, 'money'=>0], 101500);
$v = affOf('Bandit Vex', 'Fight Shay');
ok($v >= -65 && $v <= -55, "item 7: woke bleeding out = -55..-65 ($v)");
attack('Fight Shay', 'Bandit Wex', 101520);
harm('Fight Shay', 'Bandit Wex', 'knockout', 101530, false);
send('recovered', null, ent('Bandit Wex'), vit('', 0.6, 0.9, 0.0, 40, 10) + ['inventory'=>[], 'inventory_total'=>0, 'money'=>0], 101540);
$w = affOf('Bandit Wex', 'Fight Shay');
ok($w >= -50 && $w <= -40, "item 7: woke with moderate wounds stays the KO band ($w)");

// ---- items 4 + 6: chat after a fight ----
$u = stobeSocialFilterDialogueUpdates('Bandit Rook', [['target'=>'Fight Shay', 'aff_delta'=>6, 'note'=>'kind words']]); // the evaluator's hook
ok((int)$u[0]['aff_delta'] === 0, 'item 4: no chat gain within a game day of the fight');
$u = stobeSocialFightDialogueRules('Bandit Rook', [['target'=>'Fight Shay', 'aff_delta'=>-4]]);
ok((int)$u[0]['aff_delta'] === -4, 'item 4: negative chat passes');

// ---- item 1: fading ----
attack('Fight Shay', 'Bandit Fen', 101600);
harm('Fight Shay', 'Bandit Fen', 'injury', 101610);
$f0 = affOf('Bandit Fen', 'Fight Shay');
ok($f0 >= -30 && $f0 <= -23, "fade setup: injury ($f0)");
tick(101600 + 7 * 86400);
$f7 = affOf('Bandit Fen', 'Fight Shay');
ok($f7 === $f0 + (int)floor(-$f0 * 0.5), "item 1: half faded after 7 game days ($f0 -> $f7)");
ok(affOf('Bandit Rook', 'Fight Shay') === $rook && affOf('Bandit Vex', 'Fight Shay') === $v, 'item 1: KO / bleeding out never fade');
ok(affOf('Fight Mal Two', 'Fight Shay') === -5, 'item 1: maiming never fades');
tick(101600 + 14 * 86400 + 5);
ok(affOf('Bandit Fen', 'Fight Shay') === 0, 'item 1: back to 0 after 14 game days (' . affOf('Bandit Fen', 'Fight Shay') . ')');
$ft = affOf('Bandit Fen Two', 'Fight Shay');
ok($ft === 0, "item 1: treated injury fades the rest (no double count) ($ft)");
ok(affOf('Fight Mal Three', 'Fight Shay') === $x2, 'item 1: accidental KO never fades');
ok(stobeSocialFightMemoryLine('Bandit Fen', 'Fight Shay') !== '' && str_contains(stobeSocialFightMemoryLine('Bandit Fen', 'Fight Shay'), 'hurt you'), 'item 6: the memory stays after the value recovered');
ok(str_contains(stobeSocialFightMemoryLine('Bandit Rook', 'Fight Shay'), 'knocked you out'), 'item 6: memory names the worst of the first fight');
$block = stobeBuildRelationshipStanceBlock('Bandit Rook', getNpcData('Bandit Rook'), 'Fight Shay', false);
ok(str_contains($block, '<memory>') && str_contains($block, 'knocked you out'), 'item 6: memory line in the stance block');
// Shay 94761de: fading is decided by what happened, not by the size (the old size-threshold setting is ignored).
$t3 = 101600 + 15 * 86400;
setting('SOCIAL_GRUDGE_FADE_THRESHOLD', '5');
RelationshipManager::setRelationship('Bandit Fen Three', 'Fight Shay', 60);
attack('Fight Shay', 'Bandit Fen Three', $t3);
harm('Fight Shay', 'Bandit Fen Three', 'injury', $t3 + 10);
$g3 = affOf('Bandit Fen Three', 'Fight Shay') - 60;
ok($g3 >= -60 && $g3 <= -46, "item 1 setup: Fond wound x2 = -46..-60 ($g3)");
RelationshipManager::setRelationship('Bandit Fen Five', 'Fight Shay', 60);
attack('Fight Shay', 'Bandit Fen Five', $t3 + 100);
harm('Fight Shay', 'Bandit Fen Five', 'knockout', $t3 + 110, false);
$g5 = affOf('Bandit Fen Five', 'Fight Shay');
tick($t3 + 15 * 86400);
ok(affOf('Bandit Fen Three', 'Fight Shay') === 60, "item 1: a Fond victim's wound (x2, $g3) fades back fully: what happened decides, not the size (" . affOf('Bandit Fen Three', 'Fight Shay') . ')');
ok(affOf('Bandit Fen Five', 'Fight Shay') === $g5 && $g5 <= -20, "item 1: a Fond victim's KO never fades ($g5)");
setting('SOCIAL_GRUDGE_FADE_THRESHOLD', null);
$t4 = $t3 + 16 * 86400;
setting('SOCIAL_GRUDGE_FADE_DAYS', '0.05');
attack('Fight Shay', 'Bandit Fen Four', $t4);
ok(affOf('Bandit Fen Four', 'Fight Shay') < 0, 'fade days setup');
tick($t4 + 4400);
ok(affOf('Bandit Fen Four', 'Fight Shay') === 0, 'item 1: SOCIAL_GRUDGE_FADE_DAYS setting (0.05 game days)');
setting('SOCIAL_GRUDGE_FADE_DAYS', null);
ok((int)one("SELECT count(*) FROM social_effect WHERE component='grudge_fade' AND incident_id LIKE '%#fade@%'") >= 3, 'item 1: each fade step is its own ledger row (rollback-safe)');

// ---- item 6: half rate while a fight grudge is open ----
$now = $t4 + 5000;
aid('Fight Shay', 'Bandit Rook', $now);
$row = effectRow('Bandit Rook', 'Fight Shay', 'meaningful_aid');
ok($row !== null && (float)($row['detail']['modifiers']['grudgeRate'] ?? 0) === 0.5 && (int)$row['delta'] >= 1 && (int)$row['delta'] <= 3,
    'item 6: a REL gain toward someone with an open fight grudge counts at half rate (' . json_encode($row['delta'] ?? null) . ')');
$u = stobeSocialFilterDialogueUpdates('Bandit Rook', [['target'=>'Fight Shay', 'aff_delta'=>5]]);
ok((int)$u[0]['aff_delta'] === 2, 'item 6: chat gain at half rate while the grudge is open');
ok((int)one("SELECT count(*) FROM social_effect WHERE component='dialogue_repair' AND detail->>'observer_name'='Bandit Rook'") === 1, 'item 6: halved chat gain counts toward closing the grudge');
$u = stobeSocialFightDialogueRules('Bandit Fen', [['target'=>'Fight Shay', 'aff_delta'=>5]]);
ok((int)$u[0]['aff_delta'] === 5, 'item 6: grudge back to 0 -> normal gains');

// ---- item 2: deals ----
[$fi, $pen] = stobeSocialLatestFight('Bandit Rook', 'Fight Shay');
ok($fi !== null && $pen < 0, "deal setup: Rook's latest fight penalty ($pen)");
$before = affOf('Bandit Rook', 'Fight Shay');
ok(stobeSocialAgreementOutcome(['contract_id'=>'b55-deal-1', 'npc_name'=>'Bandit Rook', 'kind'=>'surrender'], 'Fight Shay', 'COMPLETE',
    [['by'=>'player', 'kind'=>'GIVE_CATS', 'amount'=>100, 'status'=>'VERIFIED']]) === true, 'item 2: fights mode: REL owns the deal outcome');
$fr = effectRow('Bandit Rook', 'Fight Shay', 'deal_forgiveness');
$expect = (int)round(-$pen * 0.3333 * 1.0 * 0.5);
ok($fr !== null && (int)$fr['delta'] === $expect && $fr['applied'] === 't', "item 2: kept deal wins back 1/3 x kept x forgiveness ($expect)");
ok(affOf('Bandit Rook', 'Fight Shay') - $before >= $expect && affOf('Bandit Rook', 'Fight Shay') < 0, 'item 2: forgiveness applied, still not positive');
ok(abs(stobeSocialDealKeptness([['by'=>'player', 'kind'=>'GIVE_CATS', 'amount'=>100, 'paid_so_far'=>50, 'status'=>'UNMET'], ['by'=>'npc', 'status'=>'VERIFIED']]) - 0.5) < 0.001, 'item 2: half paid = half kept');
ok(stobeSocialForgiveness(['personality'=>'Forgiving and calm']) === 1.0 && stobeSocialForgiveness(['personality'=>'proud, vengeful']) === 0.0, 'item 2: personality sets forgiveness');
$before = affOf('Bandit Rook', 'Fight Shay');
stobeSocialAgreementOutcome(['contract_id'=>'b55-deal-2', 'npc_name'=>'Bandit Rook', 'kind'=>'surrender'], 'Fight Shay', 'BREACHED_PLAYER', [['by'=>'player', 'player_broke_truce'=>true]]);
$betray = affOf('Bandit Rook', 'Fight Shay') - $before;
ok($betray <= -25 && $betray >= -55, "item 2: broken truce adds betrayal ($betray)");

// ---- enabled mode keeps applying everything with B 55 rules; shadow applies nothing ----
setting('SOCIAL_RELATIONSHIP_MODE', 'shadow');
ok(stobeSocialFightDialogueRules('Bandit Rook', [['target'=>'Fight Shay', 'aff_delta'=>5]])[0]['aff_delta'] === 5, 'shadow: dialogue untouched');
setting('SOCIAL_RELATIONSHIP_MODE', null);
setting('SOCIAL_FIGHTS_LIVE', 'false');
ok(stobeSocialFightDialogueRules('Bandit Rook', [['target'=>'Fight Shay', 'aff_delta'=>5]])[0]['aff_delta'] === 5 && stobeSocialFightMemoryLine('Bandit Rook', 'Fight Shay') === '', 'fights off: dialogue and stance untouched');
setting('SOCIAL_FIGHTS_LIVE', null);
sql("DELETE FROM conf_opts WHERE id LIKE 'STOBE_REL_FADE_%' OR id LIKE 'STOBE_REL_SPAR_%'");
echo "$n B 55 fights regression checks passed\n";
