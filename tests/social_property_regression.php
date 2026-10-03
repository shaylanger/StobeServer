<?php
// REL phase 5 (property, economy, agreements) regression. Pinned rule ranges are the oracle.
declare(strict_types=1);
if (getenv('STOBE_DB_NAME') !== 'stobe_social_phase1_test') throw new RuntimeException('Dedicated social DB required');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/social_runtime.php';
require_once __DIR__ . '/../lib/social_agreements.php';
require_once __DIR__ . '/../lib/negotiation_engine.php';
$db = $GLOBALS['db']; $n = 0;
function ok(bool $c, string $what): void { global $n; if (!$c) throw new RuntimeException('FAIL: ' . $what); ++$n; }
function sql(string $s, array $p = []): mixed { $r = $GLOBALS['db']->exec($s, $p); if ($r === false) throw new RuntimeException('SQL failed: ' . $s . ' ' . $GLOBALS['db']->GetLastError()); return $r; }
function one(string $s, array $p = []): mixed { $r = sql($s, $p); return pg_num_rows($r) ? pg_fetch_result($r, 0, 0) : null; }
function affOf(string $a, string $b): int { return (int)(stobeRelationshipEntryFor(getNpcData($a), $b)['aff'] ?? 0); }
function entryOf(string $a, string $b): ?array { return stobeRelationshipEntryFor(getNpcData($a), $b); }

const P = ['Prop Owner'=>[401,'Blue',false], 'Prop Thief'=>[402,'Grey',false], 'Prop Owner Two'=>[403,'Blue',false], 'Prop Owner Three'=>[404,'Blue',false],
    'Prop Owner Four'=>[405,'Blue',false], 'Prop Squad A'=>[411,'Nameless',true], 'Prop Squad B'=>[412,'Nameless',true],
    'Prop Giver'=>[421,'Hub',false], 'Prop Friend'=>[422,'Blue',false], 'Prop Trader'=>[431,'Traders',false], 'Prop Buyer'=>[432,'Blue',false],
    'Prop Rich Buyer'=>[433,'Blue',false], 'Prop Patron'=>[434,'Blue',false], 'Prop Npc'=>[441,'Blue',false], 'Prop Npc Two'=>[442,'Blue',false], 'Prop Player'=>[450,'Nameless',true]];
foreach (['social_event_inbox','social_incident','social_belief','social_effect','social_evidence','social_checkpoint'] as $t) sql("DELETE FROM $t");
foreach (P as $name => [$serial]) {
    sql('DELETE FROM core_npc_master_history WHERE name=$1', [$name]); sql('DELETE FROM core_npc WHERE name=$1', [$name]);
    sql("INSERT INTO core_npc(name,extended_data,metadata) VALUES($1,'{}'::jsonb,jsonb_build_object('storage_id',$2::text))", [$name, 'hand_' . $serial]);
}
sql("INSERT INTO general_settings(id,value) VALUES('SOCIAL_RELATIONSHIP_MODE','enabled') ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value");
$store = new SocialStore($db); $seq = 0;
function ent(string $name, ?bool $conscious = true): array {
    [$serial, $faction, $squad] = P[$name];
    return ['entity_key'=>"camp5c:11:$serial", 'serial'=>$serial, 'name'=>$name, 'storage_id'=>"hand_$serial", 'faction'=>$faction, 'in_player_faction'=>$squad, 'conscious'=>$conscious];
}
function send(string $kind, ?array $actor, ?array $target, array $facts, int $ts, string $mode = 'enabled'): array {
    global $seq, $store; ++$seq;
    $e = ['schema_version'=>1, 'event_id'=>"camp5c:11:$seq", 'incident_id'=>"camp5c:11:$seq", 'campaign_id'=>'camp5', 'timeline_epoch'=>'11', 'native_session_id'=>'sess5',
        'sequence'=>$seq, 'game_ts'=>$ts, 'event_kind'=>$kind, 'origin'=>'gameplay', 'actor'=>$actor, 'target'=>$target,
        'state_before'=>[], 'state_after'=>[], 'witnesses'=>[], 'facts'=>($kind === 'looting' ? [] : ['source'=>'structured']) + $facts];
    $r = $store->ingest($e, ['campaign_id'=>'camp5', 'timeline_epoch'=>'11', 'native_session_id'=>'sess5'], $mode);
    if (($r['status'] ?? '') !== 'captured') throw new RuntimeException("FAIL: event $seq ($kind @$ts) not captured: " . ($r['status'] ?? '?'));
    return $r;
}
function steal(string $thief, string $owner, array $items, ?bool $caught, int $ownerLeft, int $ts, ?float $hunger = null, array $food = []): array {
    return send('item_transfer', ent($thief), ent($owner), ['items'=>$items, 'to_ground'=>false, 'food_items'=>$food, 'recipient_hunger'=>null,
        'stolen_items'=>$items, 'caught'=>$caught, 'loser_inventory_total'=>$ownerLeft, 'loser_hunger'=>$hunger], $ts);
}
function hand(string $to, string $from, array $items, int $ts): array {
    return send('item_transfer', ent($to), ent($from), ['items'=>$items, 'to_ground'=>false, 'food_items'=>[], 'recipient_hunger'=>null,
        'stolen_items'=>[], 'caught'=>null, 'loser_inventory_total'=>10, 'loser_hunger'=>2.0], $ts);
}
function status(array $r): string { return strval($r['effects'][0]['status'] ?? ''); }

// SR13: unseen / undetected theft and permission checks never blame; a caught theft does.
ok(status(steal('Prop Thief', 'Prop Owner', ['Iron Bar'=>2], null, 18, 1000)) === 'unseen_or_undetected' && entryOf('Prop Owner', 'Prop Thief') === null, 'SR13 undetected theft: no blame');
ok(status(steal('Prop Thief', 'Prop Owner', ['Iron Bar'=>2], false, 16, 1010)) === 'unseen_or_undetected' && entryOf('Prop Owner', 'Prop Thief') === null, 'SR13 not caught: no blame');
send('looting', ent('Prop Thief'), ent('Prop Owner'), ['message'=>'Looted Iron Bar'], 1020);
ok(entryOf('Prop Owner', 'Prop Thief') === null, 'SR13 permission check / legacy looting line: no blame');
steal('Prop Thief', 'Prop Owner', ['Iron Bar'=>2], true, 14, 1030);
$t = affOf('Prop Owner', 'Prop Thief');
ok($t >= -18 && $t <= -8, "SR13 caught ordinary theft ($t)");
// SR14: petty vs most belongings vs a starving owner's food.
steal('Prop Thief', 'Prop Owner Two', ['Bread'=>1], true, 30, 1100);
$petty = affOf('Prop Owner Two', 'Prop Thief');
ok($petty >= -8 && $petty <= -3, "SR14 petty theft ($petty)");
steal('Prop Thief', 'Prop Owner Three', ['Katana'=>1, 'Armour'=>8], true, 1, 1200);
$all = affOf('Prop Owner Three', 'Prop Thief');
ok($all >= -55 && $all <= -35, "SR14 near-total belongings ($all)");
steal('Prop Thief', 'Prop Owner Four', ['Dried Meat'=>1], true, 40, 1300, 0.5, ['Dried Meat'=>1]);
$food = affOf('Prop Owner Four', 'Prop Thief');
ok($food >= -35 && $food <= -20, "SR14 a starving owner's food is major ($food)");
// SR14: handing the stolen items back within a day: a small compensation, once.
hand('Prop Owner Two', 'Prop Thief', ['Bread'=>1], 1400);
$back = affOf('Prop Owner Two', 'Prop Thief') - $petty;
ok($back >= 3 && $back <= 9, "SR14 returned property compensates ($back)");
hand('Prop Owner Two', 'Prop Thief', ['Bread'=>1], 1410);
ok((int)one("SELECT count(*) FROM social_effect WHERE component='property_returned'") === 1, 'SR14 compensation once (a second hand-over is an ordinary gift)');
// SR20: squad members' things moving between them: exempt even when flagged stolen.
ok(status(steal('Prop Squad A', 'Prop Squad B', ['Katana'=>1], true, 2, 1500)) === 'exempt' && entryOf('Prop Squad B', 'Prop Squad A') === null, 'SR20 squad equipment control exempt');

// Gifts: small, decreasing, at most +6 a day, never past +30 by economics alone.
hand('Prop Friend', 'Prop Giver', ['Hat'=>1], 2000);
$g1 = affOf('Prop Friend', 'Prop Giver');
ok($g1 >= 1 && $g1 <= 4, "gift band ($g1)");
for ($i = 1; $i < 6; ++$i) hand('Prop Friend', 'Prop Giver', ['Hat'=>1], 2000 + $i);
ok(affOf('Prop Friend', 'Prop Giver') <= 6, 'gift day budget <= +6 (' . affOf('Prop Friend', 'Prop Giver') . ')');
for ($d = 1; $d <= 20; ++$d) for ($i = 0; $i < 3; ++$i) hand('Prop Friend', 'Prop Giver', ['Hat'=>1], 2000 + $d * 86400 + $i);
$eco = affOf('Prop Friend', 'Prop Giver');
ok($eco <= 30 && $eco >= 20, "economic-only ceiling +30 ($eco)");
// Deal payments are not gifts (scored by the agreement outcome).
sql("DELETE FROM stobe_social_contract WHERE contract_id LIKE 'reltest%'");
sql("INSERT INTO stobe_social_contract(contract_id,npc_name,player_name,status,terms,performance_started_unix) VALUES('reltest-pay','Prop Friend','Prop Giver','AWAITING_PERFORMANCE','[]'::jsonb,1)");
ok(status(hand('Prop Friend', 'Prop Giver', ['Hat'=>1], 2000 + 30 * 86400)) === 'deal_payment', 'deal payment is not a gift');
sql("DELETE FROM stobe_social_contract WHERE contract_id='reltest-pay'");

$T0 = 31 * 86400;
// SR22: ordinary trade ~0; exceptional deals bounded; economics never past +30.
function buy(string $buyer, ?string $seller, int $spent, int $gained, int $ref, int $ts): array {
    return send('trade', ent($buyer), $seller ? ent($seller) : null, ['item'=>'Bread', 'quantity'=>1, 'buyer_spent'=>$spent, 'seller_gained'=>$gained, 'reference_value'=>$ref], $ts);
}
ok(status(buy('Prop Buyer', 'Prop Trader', 100, 100, 100, $T0 + 3000)) === 'fair_trade' && entryOf('Prop Trader', 'Prop Buyer') === null, 'SR22 fair trade: nothing');
for ($i = 0; $i < 6; ++$i) buy('Prop Rich Buyer', 'Prop Trader', 500, 500, 100, $T0 + 3100 + $i);
$six = affOf('Prop Trader', 'Prop Rich Buyer');
ok($six >= 4 && $six <= 6, "SR22 six exceptional deals in a day ($six)");
for ($d = 1; $d <= 30; ++$d) for ($i = 0; $i < 6; ++$i) buy('Prop Rich Buyer', 'Prop Trader', 500, 500, 100, $T0 + 3100 + $d * 86400 + $i);
$max = affOf('Prop Trader', 'Prop Rich Buyer');
ok($max <= 30, "SR22 economics cannot reach Bonded/recruit ($max)");
ok(!SocialRules::recruitment($max, ['exceptional_trade'], []), 'SR22 economic evidence alone never qualifies a recruit');
// A trader who sells cheap earns the buyer's goodwill.
buy('Prop Patron', 'Prop Trader', 30, 30, 100, $T0 + 3100 + 40 * 86400);
ok(affOf('Prop Patron', 'Prop Trader') >= 1, 'cheap sale: buyer goodwill');
// SR23: no personal seller, shared faction purse, no reference value: nobody credited.
ok(status(buy('Prop Buyer', null, 500, 500, 100, $T0 + 3100 + 41 * 86400)) === 'no_personal_seller', 'SR23 shop storage seller: nobody');
ok(status(buy('Prop Buyer', 'Prop Trader', 500, 0, 100, $T0 + 3100 + 41 * 86400 + 1)) === 'shared_purse', 'SR23 faction purse: nobody');
ok(status(buy('Prop Buyer', 'Prop Trader', 500, 500, -1, $T0 + 3100 + 41 * 86400 + 2)) === 'no_reference', 'SR23 unknown value: nobody');
ok(entryOf('Prop Trader', 'Prop Buyer') === null, 'SR23 no guessed reward');

// SR29: deal outcomes (server-owned semantic events in the connected campaign).
$prevSession = one("SELECT value FROM stobe_meta.settings WHERE key='PLAYTHROUGH_SESSION'");
$prevMode = one("SELECT value FROM general_settings WHERE id='SOCIAL_RELATIONSHIP_MODE'");
sql("INSERT INTO stobe_meta.settings(key,value) VALUES('PLAYTHROUGH_SESSION',$1) ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value",
    [json_encode(['status'=>'ready', 'character_id'=>'camp5', 'load_id'=>11, 'client_id'=>'sess5'])]);
try {
    ok(stobeSocialAgreementOutcome(['contract_id'=>'reltest-1', 'npc_name'=>'Prop Npc', 'kind'=>'trade'], 'Prop Player', 'COMPLETE', []) === true, 'SR29 enabled: REL owns the outcome');
    $kept = affOf('Prop Npc', 'Prop Player');
    ok($kept >= 1 && $kept <= 3, "SR29 kept promise ($kept)");
    ok(stobeSocialAgreementOutcome(['contract_id'=>'reltest-1', 'npc_name'=>'Prop Npc', 'kind'=>'trade'], 'Prop Player', 'COMPLETE', []) === true && affOf('Prop Npc', 'Prop Player') === $kept, 'SR29 retry once');
    stobeSocialAgreementOutcome(['contract_id'=>'reltest-2', 'npc_name'=>'Prop Npc Two', 'kind'=>'surrender'], 'Prop Player', 'COMPLETE', []);
    $coerced = affOf('Prop Npc Two', 'Prop Player');
    ok($coerced >= 0 && $coerced <= 2, "SR29 honored coercive deal: minimal gain ($coerced)");
    stobeSocialAgreementOutcome(['contract_id'=>'reltest-3', 'npc_name'=>'Prop Npc Two', 'kind'=>'surrender'], 'Prop Player', 'BREACHED_PLAYER', [['by'=>'player', 'player_broke_truce'=>true]]);
    $betrayed = affOf('Prop Npc Two', 'Prop Player') - $coerced;
    ok($betrayed <= -25 && $betrayed >= -55, "SR29 attacking after an accepted surrender adds betrayal ($betrayed)");
    sql("UPDATE general_settings SET value='shadow' WHERE id='SOCIAL_RELATIONSHIP_MODE'");
    $before = affOf('Prop Npc', 'Prop Player');
    ok(stobeSocialAgreementOutcome(['contract_id'=>'reltest-4', 'npc_name'=>'Prop Npc', 'kind'=>'trade'], 'Prop Player', 'BREACHED_PLAYER', []) === false
        && affOf('Prop Npc', 'Prop Player') === $before && (int)one("SELECT count(*) FROM social_effect WHERE incident_id='deal:reltest-4' AND NOT applied") === 1, 'SR41 shadow: legacy stays, would-be effect recorded');
    // The negotiation engine skips its legacy delta when REL is enabled, and keeps it otherwise.
    sql("UPDATE general_settings SET value='enabled' WHERE id='SOCIAL_RELATIONSHIP_MODE'");
    sql("INSERT INTO stobe_social_contract(contract_id,npc_name,player_name,status,terms,kind) VALUES('reltest-5','Prop Npc','Prop Player','COMPLETE','[]'::jsonb,'trade')");
    $before = affOf('Prop Npc', 'Prop Player');
    stobeNegApplyConsequences(['contract_id'=>'reltest-5', 'npc_name'=>'Prop Npc', 'status'=>'COMPLETE', 'kind'=>'trade', 'term_state'=>'[]', 'baseline'=>'{}'], 'Prop Player');
    $d = affOf('Prop Npc', 'Prop Player') - $before;
    ok($d >= 1 && $d <= 3, "negotiation engine: REL delta instead of the legacy +4 ($d)");
    sql("UPDATE general_settings SET value='off' WHERE id='SOCIAL_RELATIONSHIP_MODE'");
    sql("INSERT INTO stobe_social_contract(contract_id,npc_name,player_name,status,terms,kind) VALUES('reltest-6','Prop Npc','Prop Player','COMPLETE','[]'::jsonb,'trade')");
    $before = affOf('Prop Npc', 'Prop Player');
    stobeNegApplyConsequences(['contract_id'=>'reltest-6', 'npc_name'=>'Prop Npc', 'status'=>'COMPLETE', 'kind'=>'trade', 'term_state'=>'[]', 'baseline'=>'{}'], 'Prop Player');
    ok(affOf('Prop Npc', 'Prop Player') - $before === 4, 'negotiation engine: off keeps the legacy +4');
} finally {
    if ($prevSession === null) sql("DELETE FROM stobe_meta.settings WHERE key='PLAYTHROUGH_SESSION'");
    else sql("UPDATE stobe_meta.settings SET value=$1 WHERE key='PLAYTHROUGH_SESSION'", [$prevSession]);
    sql("DELETE FROM stobe_social_contract WHERE contract_id LIKE 'reltest%'");
    sql("DELETE FROM stobe_negotiation_reputation WHERE player_name='prop player'");
    sql("UPDATE general_settings SET value='off' WHERE id='SOCIAL_RELATIONSHIP_MODE'");
}
echo "$n property/agreement regression checks passed\n";
