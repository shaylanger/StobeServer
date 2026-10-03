<?php
// REL phase 3 (unconscious perception) regression: KO baseline, objective vs believed culprit,
// recovery attribution, enslavement owner, squad exemption, rollback/restart/reload durability.
declare(strict_types=1);
if (getenv('STOBE_DB_NAME') !== 'stobe_social_phase1_test') throw new RuntimeException('Dedicated social DB required');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/social_runtime.php';
require_once __DIR__ . '/../lib/social_interpreter.php';
$db = $GLOBALS['db']; $n = 0;
function ok(bool $c, string $what): void { global $n; if (!$c) throw new RuntimeException('FAIL: ' . $what); ++$n; }
function sql(string $s, array $p = []): mixed { $r = $GLOBALS['db']->exec($s, $p); if ($r === false) throw new RuntimeException('SQL failed: ' . $s); return $r; }
function one(string $s, array $p = []): mixed { $r = sql($s, $p); return pg_num_rows($r) ? pg_fetch_result($r, 0, 0) : null; }
function affOf(string $a, string $b): int { return (int)(stobeRelationshipEntryFor(getNpcData($a), $b)['aff'] ?? 0); }
function entryOf(string $a, string $b): ?array { return stobeRelationshipEntryFor(getNpcData($a), $b); }

const PEOPLE = ['Uncon Attacker'=>[201,'Red',false], 'Uncon Looter'=>[202,'Grey',false], 'Uncon Slaver'=>[203,'Slavers',false],
    'Uncon Victim One'=>[211,'Blue',false], 'Uncon Victim Two'=>[212,'Blue',false], 'Uncon Victim Three'=>[213,'Blue',false],
    'Uncon Victim Four'=>[214,'Blue',false], 'Uncon Victim Five'=>[215,'Blue',false], 'Uncon Victim Six'=>[216,'Blue',false],
    'Uncon Victim Seven'=>[217,'Blue',false], 'Uncon Victim Eight'=>[218,'Blue',false], 'Uncon Squad Victim'=>[219,'Nameless',true],
    'Uncon Squad Mate'=>[220,'Nameless',true], 'Uncon Victim Nine'=>[221,'Blue',false], 'Uncon Victim Ten'=>[222,'Blue',false]];
foreach (['social_event_inbox','social_incident','social_belief','social_effect','social_evidence','social_checkpoint'] as $t) sql("DELETE FROM $t");
foreach (PEOPLE as $name => [$serial]) {
    sql('DELETE FROM core_npc_master_history WHERE name=$1', [$name]); sql('DELETE FROM core_npc WHERE name=$1', [$name]);
    sql("INSERT INTO core_npc(name,extended_data,metadata) VALUES($1,'{}'::jsonb,jsonb_build_object('storage_id',$2::text))", [$name, 'hand_' . $serial]);
}
sql("INSERT INTO general_settings(id,value) VALUES('SOCIAL_RELATIONSHIP_MODE','enabled') ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value");
$store = new SocialStore($db);
$epoch = '7'; $seq = 0;
function scope(): array { global $epoch; return ['campaign_id'=>'camp3', 'timeline_epoch'=>$epoch, 'native_session_id'=>'sess3']; }
function ent(string $name, ?bool $conscious = true): array {
    global $epoch; [$serial, $faction, $squad] = PEOPLE[$name];
    return ['entity_key'=>"sess3:$epoch:$serial", 'serial'=>$serial, 'name'=>$name, 'storage_id'=>"hand_$serial",
        'faction'=>$faction, 'in_player_faction'=>$squad, 'conscious'=>$conscious];
}
function ev(string $kind, ?array $actor, ?array $target, array $facts, int $ts): array {
    global $seq, $epoch; ++$seq;
    return ['schema_version'=>1, 'event_id'=>"sess3:$epoch:$seq", 'incident_id'=>"sess3:$epoch:$seq", 'campaign_id'=>'camp3', 'timeline_epoch'=>$epoch,
        'native_session_id'=>'sess3', 'sequence'=>$seq, 'game_ts'=>$ts, 'event_kind'=>$kind, 'origin'=>'gameplay',
        'actor'=>$actor, 'target'=>$target, 'state_before'=>[], 'state_after'=>[], 'witnesses'=>[], 'facts'=>['source'=>'structured'] + $facts];
}
function send(array $e): array { global $store; return $store->ingest($e, scope(), 'enabled'); }
$INV = ['Katana'=>1, 'Dried Meat'=>4];
/** A attacks V and knocks V out (V had $inv); returns V->A affinity after the KO. */
function koBy(?string $attacker, string $victim, int $ts, array $inv): int {
    global $INV;
    if ($attacker) send(ev('attack', ent($attacker), ent($victim), ['victim_targeting_actor'=>false], $ts));
    send(ev('harm', $attacker ? ent($attacker) : null, ent($victim, false), ['level'=>'knockout', 'attribution'=>$attacker ? 'defeated_by' : 'none',
        'inventory'=>$inv, 'inventory_total'=>array_sum($inv), 'money'=>100], $ts + 5));
    return $attacker ? affOf($victim, $attacker) : 0;
}
function take(string $taker, string $victim, array $items, int $ts): void {
    send(ev('item_transfer', ent($taker), ent($victim, false), ['items'=>$items, 'to_ground'=>false], $ts));
}
function wake(string $victim, array $inv, int $ts): array { return send(ev('recovered', null, ent($victim), ['inventory'=>$inv, 'inventory_total'=>array_sum($inv), 'money'=>100], $ts)); }

// SR08: A KOs B, C takes almost everything; nothing on B's side until B wakes, then A (remembered) is blamed.
$koAff = koBy('Uncon Attacker', 'Uncon Victim One', 1000, $INV);
ok($koAff <= -25 && $koAff >= -40, "SR08 setup: KO harm only ($koAff)");
take('Uncon Looter', 'Uncon Victim One', ['Katana'=>1, 'Dried Meat'=>3], 1010);
ok(affOf('Uncon Victim One', 'Uncon Attacker') === $koAff, 'SR08 no immediate theft blame on A');
ok(entryOf('Uncon Victim One', 'Uncon Looter') === null, 'SR08 no immediate entry toward C');
wake('Uncon Victim One', ['Dried Meat'=>1], 1100);
$theft = affOf('Uncon Victim One', 'Uncon Attacker') - $koAff;
ok($theft <= round(-35 * 0.8) && $theft >= round(-55 * 0.8), "SR08 inferred near-total theft x0.8 on A ($theft)");
ok(entryOf('Uncon Victim One', 'Uncon Looter') === null, 'SR08 objective thief C never leaks into B');
$belief = one("SELECT belief::text FROM social_belief WHERE observer_key='sess3:7:211' AND belief->>'kind'='theft'");
ok(is_string($belief) && str_contains($belief, 'sess3:7:201') && !str_contains($belief, 'Looter'), 'SR42 belief names only the inferred culprit');
ok(!str_contains(json_encode(entryOf('Uncon Victim One', 'Uncon Attacker')), 'Looter'), 'SR42 relationship note hides the objective thief');
ok(str_contains(one("SELECT state::text FROM social_incident WHERE state->>'kind'='ko' AND state->'victim'->>'serial'='211'"), 'Uncon Looter'), 'SR42 objective truth kept as diagnostics');
ok(SocialPerception::prompt([json_decode($belief, true)]) !== [] && !str_contains(json_encode(SocialPerception::prompt([json_decode($belief, true)])), '202'), 'SR42 prompt view allowlisted');
// Waking twice (poll duplicate) does nothing more.
$after = affOf('Uncon Victim One', 'Uncon Attacker');
wake('Uncon Victim One', ['Dried Meat'=>1], 1105);
ok(affOf('Uncon Victim One', 'Uncon Attacker') === $after, 'SR35 second wake no second charge');

// SR09: verified better evidence names C: C is blamed (certain), A only keeps the harm.
$koAff = koBy('Uncon Attacker', 'Uncon Victim Two', 2000, $INV);
take('Uncon Looter', 'Uncon Victim Two', ['Katana'=>1], 2010);
$adapter = new SocialInterpreter($store, new SocialRules());
$evidence = ev('item_transfer', ent('Uncon Looter'), ent('Uncon Victim Two', false), ['items'=>[], 'to_ground'=>false], 2011); send($evidence);
ok($adapter->recordKnownThief($evidence, ent('Uncon Victim Two'), ent('Uncon Looter')), 'SR09 evidence adapter');
wake('Uncon Victim Two', ['Dried Meat'=>4], 2100);
$ct = affOf('Uncon Victim Two', 'Uncon Looter');
ok($ct <= -8 && $ct >= -18, "SR09 known thief blamed with ordinary theft ($ct)");
ok(affOf('Uncon Victim Two', 'Uncon Attacker') === $koAff, 'SR09 no double inferred charge on A');

// SR10a: unknown KO attacker: missing items are blamed on nobody.
koBy(null, 'Uncon Victim Three', 3000, $INV);
take('Uncon Looter', 'Uncon Victim Three', ['Katana'=>1], 3010);
$r = wake('Uncon Victim Three', ['Dried Meat'=>4], 3100);
ok(entryOf('Uncon Victim Three', 'Uncon Looter') === null && entryOf('Uncon Victim Three', 'Uncon Attacker') === null, 'SR10 unknown KO no invented blame');
ok(($r['effects'][0]['status'] ?? '') === 'no_known_culprit', 'SR10 reported as no_known_culprit');
// SR10b: items used up (no transfer) are not theft.
$koAff = koBy('Uncon Attacker', 'Uncon Victim Four', 4000, $INV);
wake('Uncon Victim Four', ['Katana'=>1], 4100);
ok(affOf('Uncon Victim Four', 'Uncon Attacker') === $koAff, 'SR10 consumed items not blamed');
// SR10c: items taken and put back before waking: nothing missing.
$koAff = koBy('Uncon Attacker', 'Uncon Victim Five', 5000, $INV);
take('Uncon Looter', 'Uncon Victim Five', ['Katana'=>1], 5010);
wake('Uncon Victim Five', $INV, 5100);
ok(affOf('Uncon Victim Five', 'Uncon Attacker') === $koAff, 'SR10 restored property not blamed');
// SR10d / SR20: squad member's inventory handled by another squad member: exempt.
$squadInv = ['Bandage'=>2, 'Katana'=>1];
send(ev('harm', null, ent('Uncon Squad Victim', false), ['level'=>'knockout', 'attribution'=>'none', 'inventory'=>$squadInv, 'inventory_total'=>3, 'money'=>0], 5500));
$ko = one("SELECT incident_id FROM social_incident WHERE state->>'kind'='ko' AND state->'victim'->>'serial'='219'");
sql("UPDATE social_incident SET state=jsonb_set(state,'{remembered}',$1::jsonb) WHERE incident_id=$2", [json_encode(ent('Uncon Attacker')), $ko]); // even with a remembered attacker
take('Uncon Squad Mate', 'Uncon Squad Victim', ['Katana'=>1, 'Bandage'=>2], 5510);
$r = wake('Uncon Squad Victim', [], 5600);
ok(($r['effects'][0]['status'] ?? '') === 'squad_inventory' && entryOf('Uncon Squad Victim', 'Uncon Attacker') === null, 'SR10/SR20 squad inventory exempt');

// SR11: A KOs B, slaver C chains B while unconscious: latent; on waking C gets enslavement (<= -56), A only harm.
$koAff = koBy('Uncon Attacker', 'Uncon Victim Six', 6000, ['Dried Meat'=>1]);
send(ev('enslaved', ent('Uncon Slaver'), ent('Uncon Victim Six', false), ['owner_role'=>'owner'], 6010));
ok(entryOf('Uncon Victim Six', 'Uncon Slaver') === null, 'SR11 no enslavement charge while unconscious');
send(ev('enslaved', ent('Uncon Slaver'), ent('Uncon Victim Six', false), ['owner_role'=>'owner'], 6011)); // repeated poll
wake('Uncon Victim Six', ['Dried Meat'=>1], 6100);
$slave = affOf('Uncon Victim Six', 'Uncon Slaver');
ok($slave <= -56 && $slave >= -90, "SR11 enslaver charged on waking, capped ($slave)");
ok(affOf('Uncon Victim Six', 'Uncon Attacker') === $koAff, 'SR11 KO attacker only harm');
ok((int)one("SELECT count(*) FROM social_effect WHERE component='enslavement' AND observer_key='sess3:7:216'") === 1, 'SR12 repeated chaining once');
// SR12: unknown owner: nothing. Conscious enslavement: immediate. Freed before waking: never learned.
send(ev('enslaved', null, ent('Uncon Victim Seven'), ['owner_role'=>'owner'], 7000));
ok((int)one("SELECT count(*) FROM social_effect WHERE observer_key='sess3:7:217'") === 0, 'SR12 unknown owner no blame');
send(ev('enslaved', ent('Uncon Slaver'), ent('Uncon Victim Seven'), ['owner_role'=>'owner'], 7010));
ok(affOf('Uncon Victim Seven', 'Uncon Slaver') <= -56, 'SR12 conscious enslavement immediate and capped');
koBy('Uncon Attacker', 'Uncon Victim Eight', 8000, ['Dried Meat'=>1]);
send(ev('enslaved', ent('Uncon Slaver'), ent('Uncon Victim Eight', false), ['owner_role'=>'owner'], 8010));
send(ev('freed', null, ent('Uncon Victim Eight', false), ['owner_role'=>'owner'], 8020));
wake('Uncon Victim Eight', ['Dried Meat'=>1], 8100);
ok(entryOf('Uncon Victim Eight', 'Uncon Slaver') === null, 'SR12 freed before waking: never learned');

// Latent harm: a limb cut while unconscious is learned on waking, inside the attacker's encounter budget.
$v9 = ent('Uncon Victim Nine');
send(ev('attack', ent('Uncon Attacker'), $v9, ['victim_targeting_actor'=>false], 9000));
send(ev('harm', ent('Uncon Attacker'), ['conscious'=>false] + $v9, ['level'=>'knockout', 'attribution'=>'defeated_by', 'inventory'=>[], 'inventory_total'=>0], 9005));
$koAff = affOf('Uncon Victim Nine', 'Uncon Attacker');
send(ev('harm', ent('Uncon Attacker'), ['conscious'=>false] + $v9, ['level'=>'maiming', 'limb'=>'left arm', 'attribution'=>'defeated_by'], 9010));
ok(affOf('Uncon Victim Nine', 'Uncon Attacker') === $koAff, 'latent maiming not charged while unconscious');
wake('Uncon Victim Nine', [], 9100);
$maim = affOf('Uncon Victim Nine', 'Uncon Attacker');
ok($maim < $koAff && $maim >= -70, "latent maiming learned on waking, one budget ($maim)");

// SR37: a fresh store (server restart) still resolves a pending KO; SR38: rollback between KO and wake restores pending.
$koAff = koBy('Uncon Attacker', 'Uncon Victim Three', 10000, $INV); // Victim Three again: new KO incident
take('Uncon Looter', 'Uncon Victim Three', ['Katana'=>1, 'Dried Meat'=>4], 10010);
$store = new SocialStore($db);
wake('Uncon Victim Three', [], 10100);
$charged = affOf('Uncon Victim Three', 'Uncon Attacker');
ok($charged < $koAff, 'SR37 pending KO survives a restart');
$store->rollback(10050);
ok(one("SELECT state->>'phase' FROM social_incident WHERE state->>'kind'='ko' AND state->'victim'->>'serial'='213' ORDER BY game_ts DESC LIMIT 1") === 'pending_awareness', 'SR38 rollback restores the KO as pending');
ok((int)one("SELECT count(*) FROM social_effect WHERE game_ts>10050") === 0, 'SR38 rollback drops later effects');

// Reload (new load id) while unconscious: the KO incident is carried into the new load and resolved once.
$epoch = '8';
$r = wake('Uncon Victim Three', [], 10200);
ok(in_array('known_outcome', array_map(fn($e) => $e['effect']['reason'] ?? '', $r['effects']), true), 'reload: carried KO resolved in the new load');
ok(one("SELECT state->>'phase' FROM social_incident WHERE timeline_epoch='7' AND state->>'kind'='ko' AND state->'victim'->>'serial'='213' ORDER BY game_ts DESC LIMIT 1") === 'carried_over', 'reload: old load copy retired');

// Death while unconscious: no wake penalties.
$epoch = '7';
koBy('Uncon Attacker', 'Uncon Victim Four', 11000, $INV);
take('Uncon Looter', 'Uncon Victim Four', ['Katana'=>1], 11010);
send(ev('harm', null, ent('Uncon Victim Four', false), ['level'=>'death'], 11020));
ok(one("SELECT state->>'phase' FROM social_incident WHERE state->>'kind'='ko' AND state->'victim'->>'serial'='214' ORDER BY game_ts DESC LIMIT 1") === 'dead', 'death closes the KO incident');

// Run m8: looting a KO'd body shows only the looter's gain; it is tied to the one KO'd owner whose baseline has the items.
$koAff = koBy('Uncon Attacker', 'Uncon Victim Five', 12000, ['rag shirt'=>1, 'fabrics'=>3]);
$r = send(ev('item_gain', ent('Uncon Looter'), null, ['items'=>['rag shirt'=>1]], 12010));
ok(($r['effects'][0]['status'] ?? '') === 'latent_property', 'm8 unmatched gain attached to the KO owner');
wake('Uncon Victim Five', ['fabrics'=>3], 12100);
ok(affOf('Uncon Victim Five', 'Uncon Attacker') < $koAff, 'm8 looted (gain only) item blamed on the remembered attacker');
koBy('Uncon Attacker', 'Uncon Victim Seven', 13000, ['rag shirt'=>1]);
koBy('Uncon Attacker', 'Uncon Victim Eight', 13100, ['rag shirt'=>1]);
$r = send(ev('item_gain', ent('Uncon Looter'), null, ['items'=>['rag shirt'=>1]], 13200));
ok(($r['effects'][0]['status'] ?? '') === 'ambiguous_owner', 'm8 two possible owners: not attributed');
// Run m11: enslaved while unconscious but the knockout itself was never seen: still learned on waking.
send(ev('enslaved', ent('Uncon Slaver'), ent('Uncon Victim Ten', false), ['owner_role'=>'owner'], 14000));
ok(entryOf('Uncon Victim Ten', 'Uncon Slaver') === null, 'm11 not charged while unconscious');
wake('Uncon Victim Ten', [], 14100);
ok(affOf('Uncon Victim Ten', 'Uncon Slaver') <= -56, 'm11 enslavement without a seen KO learned on waking');
sql("UPDATE general_settings SET value='off' WHERE id='SOCIAL_RELATIONSHIP_MODE'");
echo "$n unconscious-perception regression checks passed\n";
