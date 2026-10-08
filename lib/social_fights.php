<?php
declare(strict_types=1);
require_once __DIR__ . '/social_runtime.php';
require_once __DIR__ . '/social_identity.php';

/**
 * B 55 (Shay, 2026-10-03; spec STOBE_full_test_plan.md section E items 1-8): fights and relationships.
 *
 * R4 (flat -10/-4 per 15 min) is retired: fights use the REL combat rules (one escalating budget per fight,
 * attacker unchanged, self-defence 0, witnesses by their bond with the victim). With SOCIAL_RELATIONSHIP_MODE=off
 * and SOCIAL_FIGHTS_LIVE (default true) REL runs in "fights" mode: only the combat and agreement components (and
 * witness echoes of fights) are applied; everything else is recorded as in shadow. SOCIAL_FIGHT_RULES=rel turns
 * the B 55 rules below off (plain phase-8 REL, used by the older REL suites). On top (b55, the default):
 *  1. forgiveness over time, decided by WHAT HAPPENED (Shay 2026-10-03, plan 94761de), never by the size of the penalty:
 *     each fight incident is its own grudge record; one that stayed "not really hurt" / "wounded but standing"
 *     (aggression, injury, a non-KO accident, and witness echoes of those) fades back linearly over
 *     SOCIAL_GRUDGE_FADE_DAYS (default 14) game days whatever the closeness multiplier made it (grudge_fade rows);
 *     knocked out or worse (KO, bleeding out, limb, defensive maiming, an accidental KO) never fades on its own;
 *  2. a kept deal wins back 1/3 x keptness x forgiveness (personality) of that fight's penalty (deal_forgiveness);
 *     a broken deal keeps its betrayal penalty (agreements);
 *  3. sparring both sides agreed to (dialogue consent, 1 game hour) costs nothing unless someone is maimed;
 *  4. no instant make-up: 1 game day after a fight, chat can't raise the victim's opinion of the attacker;
 *  5. in fights mode only fights that matter count: a squad member is involved, or a named NPC (or a squad
 *     member) is present, conscious and perceives it;
 *  6. the first fight leaves a mark (a memory line in the stance block) and while a fight grudge is open
 *     (fight penalty not yet won back) positive gains toward that person count at half rate;
 *  7. harsher fight ranges (rules fights.ranges) x the victim's closeness to the attacker before the fight
 *     (Friendly 1.3, Fond 2.0, Devoted 2.5, Bonded 3.0), floor -100; bleeding out on waking = critical_harm;
 *     accidental friendly fire = 0.25 x the level's range;
 *  8. the attacker treating her wounds afterwards takes off a variable 15-30 % (treated_relief, never positive).
 */

const STOBE_SOCIAL_VICTIM_FIGHT = ['aggression', 'injury', 'serious_assault', 'critical_harm', 'maiming', 'defensive_maiming', 'accident'];
const STOBE_SOCIAL_FIGHT_COMPONENTS = ['aggression', 'injury', 'serious_assault', 'critical_harm', 'maiming', 'defensive_maiming', 'accident',
    'witness_aggression', 'witness_injury', 'witness_serious_assault', 'witness_critical_harm', 'witness_maiming', 'witness_defensive_maiming'];
const STOBE_SOCIAL_NEVER_FADE = ['serious_assault', 'critical_harm', 'maiming', 'defensive_maiming',
    'witness_serious_assault', 'witness_critical_harm', 'witness_maiming', 'witness_defensive_maiming'];
/** Closeness applies to the deliberate harm budget and accidents (defensive maiming keeps its own range). */
const STOBE_SOCIAL_CLOSENESS_SCALED = ['aggression', 'injury', 'serious_assault', 'critical_harm', 'maiming', 'accident'];
/** Repair that belongs to one fight incident (never halved by the open grudge, never above the penalty). */
const STOBE_SOCIAL_FIGHT_RELIEF = ['treated_relief', 'deal_forgiveness'];

function stobeSocialPgList(array $items): string { return '{' . implode(',', $items) . '}'; }

function stobeSocialFightsLive(): bool
{
    try { return getSettingBool('SOCIAL_FIGHTS_LIVE', true); } catch (Throwable $e) { return false; }
}

/** b55 (default) or rel (plain phase-8 REL rules, for the older suites / a fallback). */
function stobeSocialFightRulesOn(): bool
{
    try { return strtolower(trim(getSetting('SOCIAL_FIGHT_RULES', 'b55'))) !== 'rel'; } catch (Throwable $e) { return true; }
}

/** Components REL applies in fights mode (the rest is recorded only). */
function stobeSocialAppliesInFights(SocialRules $rules, string $component): bool
{
    $base = str_starts_with($component, 'witness_') ? substr($component, 8) : $component;
    try { return in_array($rules->category($base), ['combat', 'agreements'], true); } catch (Throwable $e) { return false; }
}

/** Item 7: the victim's feeling toward the attacker before the fight -> multiplier (Neutral or worse 1.0). */
function stobeSocialClosenessMultiplier(int $affinity, SocialRules $rules): float
{
    $bands = $rules->section('fights')['closeness'] ?? [[31, 1.3], [56, 2.0], [76, 2.5], [91, 3.0]];
    $mult = 1.0;
    foreach ($bands as $band) {
        if (is_array($band) && count($band) === 2 && $affinity >= (int)$band[0]) $mult = (float)$band[1];
    }
    return $mult;
}

/** Item 1: game days a fading grudge takes to reach 0 (setting SOCIAL_GRUDGE_FADE_DAYS, rules fights.fade_days, 14). */
function stobeSocialFadeDays(SocialRules $rules): float
{
    return (float)getSetting('SOCIAL_GRUDGE_FADE_DAYS', strval($rules->section('fights')['fade_days'] ?? 14));
}

/** #5: a squad member is involved, or a named NPC / squad member is present, conscious and perceives it. */
function stobeSocialFightMatters(array $event): bool
{
    $a = $event['actor'] ?? null; $b = $event['target'] ?? null;
    if (($a['in_player_faction'] ?? null) === true || ($b['in_player_faction'] ?? null) === true) return true;
    foreach ($event['witnesses'] ?? [] as $w) {
        $who = $w['entity'] ?? null;
        if (!$who || ($w['conscious'] ?? null) !== true || ($w['perceived'] ?? null) !== true) continue;
        if (in_array($who['entity_key'] ?? '', [$a['entity_key'] ?? null, $b['entity_key'] ?? null], true)) continue;
        if (($who['in_player_faction'] ?? null) === true) return true;
        $name = trim(strval($who['name'] ?? ''));
        if ($name !== '' && !SocialIdentity::generic($name)) return true;
    }
    return false;
}

// ---------- #3 sparring ----------
function stobeSocialSparKey(string $a, string $b): string
{
    $n = [strtolower(trim($a)), strtolower(trim($b))];
    sort($n);
    return 'STOBE_REL_SPAR_' . md5(implode('|', $n));
}

function stobeSocialRecordSpar(string $a, string $b, ?int $gameTs = null, string $why = 'dialogue'): bool
{
    if (trim($a) === '' || trim($b) === '' || strcasecmp(trim($a), trim($b)) === 0) return false;
    $ts = $gameTs ?? stobeSocialNowGameTs();
    $ok = setConfOpt(stobeSocialSparKey($a, $b), json_encode(['game_ts'=>$ts, 'real'=>time(), 'a'=>$a, 'b'=>$b, 'why'=>$why]));
    if (function_exists('stobeLogRelationshipInfo')) stobeLogRelationshipInfo('SOCIAL_SPAR consent', ['a'=>$a, 'b'=>$b, 'game_ts'=>$ts, 'why'=>$why]);
    return $ok;
}

function stobeSocialSparActive(string $a, string $b, int $gameTs, ?SocialRules $rules = null): bool
{
    $raw = getConfOpt(stobeSocialSparKey($a, $b), '');
    $rec = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($rec)) return false;
    $window = (int)(($rules ?? new SocialRules())->section('fights')['spar_window_seconds'] ?? 3600);
    $at = (int)($rec['game_ts'] ?? 0);
    if ($at > 0 && $gameTs > 0) return $gameTs >= $at - 60 && $gameTs - $at <= $window;
    return time() - (int)($rec['real'] ?? 0) <= 3600;
}

/** The player's line proposes a spar and the NPC's reply agrees: both sides agreed (logged). */
function stobeSocialNoteSparConsent(string $npc, string $reply, string $eventType = 'chat'): bool
{
    if (trim($npc) === '' || strtolower(trim($eventType)) !== 'chat' || stobeSocialIngestMode() === 'off' || !stobeSocialFightRulesOn()) return false;
    $line = strval($GLOBALS['STOBE_CURRENT_PLAYER_MESSAGE'] ?? '');
    if (!stobeSocialSparProposal($line) || !stobeSocialSparAgreed($reply)) return false;
    $npcData = getNpcData($npc);
    if (!is_array($npcData) || !$npcData) return false;
    $player = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    return stobeSocialRecordSpar($npc, $player, null, 'dialogue');
}

function stobeSocialSparProposal(string $line): bool
{
    return preg_match('/\b(spar|sparring|duel|friendly (fight|bout|match)|practice (fight|bout|match)|training (fight|bout|match))\b/i', $line) === 1;
}

function stobeSocialSparAgreed(string $reply): bool
{
    if (preg_match("/\b(no|not|never|won't|wont|refuse|later|pass)\b/i", $reply)) return false;
    return preg_match("/\b(yes|yeah|sure|alright|all right|fine|okay|ok|deal|gladly|let'?s|bring it|you'?re on|ready|why not)\b/i", $reply) === 1;
}

/**
 * Item 141 (m53): a spar she started with ATTACK@<player> after "let's spar" is recorded; when the player then
 * says "enough / stop" and her reply carries no attack, Stobe gets STOP_FIGHT for her (disengage + squad rejoin),
 * whatever the model answered (it replied in words only and the deal path dropped it). Once per player line.
 */
function stobeSocialSparStopGuard(string $npc, string $actionTag, string $reply, string $eventType = 'chat'): bool
{
    if (trim($npc) === '' || strtolower(trim($eventType)) !== 'chat') return false;
    $line = strval($GLOBALS['STOBE_CURRENT_PLAYER_MESSAGE'] ?? '');
    if ($line === '') return false;
    $player = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    if (preg_match('/(?:^|[\s,;|])ATTACK@([^@,;|\]\s][^@,;|\]]*)/i', $actionTag, $m)) {
        if (!stobeSocialSparProposal($line)) return false;
        return stobeSocialRecordSpar($npc, trim($m[1]), null, 'attack');
    }
    if (!stobeSocialSparStopLine($line)) return false;
    if (stripos($actionTag, 'STOP_ATTACK') !== false) return false;   // Stobe's STOP_ATTACK already rejoins her
    if (preg_match("/\b(no|never|not yet|keep going|one more)\b/i", $reply)) return false;
    $done = md5(strtolower($npc) . '|' . $line);
    if (!empty($GLOBALS['STOBE_SPAR_STOP_SENT'][$done])) return false;
    if (!stobeSocialSparActive($npc, $player, stobeSocialNowGameTs())) return false;
    $GLOBALS['STOBE_SPAR_STOP_SENT'][$done] = true;
    $serial = function_exists('stobeResolveLiveParticipantSerial') ? intval(stobeResolveLiveParticipantSerial($npc, true)) : 0;
    if (!function_exists('stobeNegQueueBridgeBySerial')) require_once __DIR__ . '/negotiation_engine.php';
    $ok = $serial > 0 && stobeNegQueueBridgeBySerial($serial, 'STOP_FIGHT');
    if ($ok) setConfOpt(stobeSocialSparKey($npc, $player), '');   // the spar is over
    if (function_exists('stobeLogInfo')) stobeLogInfo('Spar stopped by the player: STOP_FIGHT sent (item 141)',
        ['npc'=>$npc, 'player'=>$player, 'serial'=>$serial, 'queued'=>$ok]);
    return $ok;
}

function stobeSocialSparStopLine(string $line): bool
{
    return preg_match("/\b(enough|stop|halt|yield|i give up|truce|done spar\w*|that'?s it|call it)\b/i", $line) === 1;
}

// ---------- #2 deals ----------
/** 0 (proud, vengeful) .. 1 (forgiving, easygoing); 0.5 when the personality says nothing either way. */
function stobeSocialForgiveness(array|false $npcRow): float
{
    $text = strtolower(strval(is_array($npcRow) ? ($npcRow['personality'] ?? '') : '') . ' ' . strval(is_array($npcRow) ? ($npcRow['speechstyle'] ?? '') : ''));
    $pos = preg_match_all('/\b(forgiving|forgives|easygoing|easy-going|laid-back|laid back|kind|gentle|calm|mellow|merciful|good-natured|patient|tolerant|warm-hearted)\b/', $text);
    $neg = preg_match_all('/\b(proud|vengeful|vindictive|grudges?|spiteful|cruel|bitter|hot-headed|hotheaded|ruthless|arrogant|resentful|unforgiving)\b/', $text);
    return max(0.0, min(1.0, 0.5 + 0.25 * ($pos - $neg)));
}

/** How fully the player kept the deal: his terms VERIFIED, paid share for cats. */
function stobeSocialDealKeptness(array $termState): float
{
    $terms = array_values(array_filter($termState, 'is_array'));
    $mine = array_values(array_filter($terms, fn($t) => strtolower(strval($t['by'] ?? '')) !== 'npc'));
    if ($mine) $terms = $mine;
    if (!$terms) return 1.0;
    $kept = 0.0;
    foreach ($terms as $t) {
        $status = strtoupper(strval($t['status'] ?? ''));
        if (in_array($status, ['VERIFIED', 'DONE', 'COMPLETE', 'FULFILLED', 'KEPT', 'RECORDED'], true)) { $kept += 1.0; continue; }
        $amount = (float)($t['amount'] ?? 0);
        $paid = (float)($t['paid_so_far'] ?? 0);
        if ($amount > 0 && $paid > 0) $kept += max(0.0, min(1.0, $paid / $amount));
    }
    return $kept / count($terms);
}

/**
 * The latest fight of culprit against observer: [incident_id, net penalty <= 0]. Net = that fight's charges plus
 * what was already won back for it (treatment, an earlier deal, fading); fights within a game day before it count too.
 */
function stobeSocialLatestFight(string $observer, string $culprit): array
{
    $db = $GLOBALS['db'];
    $list = stobeSocialPgList(STOBE_SOCIAL_VICTIM_FIGHT);
    $last = $db->fetchOne("SELECT incident_id, game_ts FROM social_effect WHERE applied AND delta<0 AND component = ANY($3::text[])
          AND lower(detail->>'observer_name')=lower($1) AND lower(detail->>'culprit_name')=lower($2) ORDER BY game_ts DESC LIMIT 1", [$observer, $culprit, $list]);
    if (!$last) return [null, 0];
    $row = $db->fetchOne("SELECT COALESCE(SUM(delta),0) AS s FROM social_effect WHERE applied
          AND (component = ANY($3::text[]) OR component = ANY($5::text[]) OR component='grudge_fade')
          AND lower(detail->>'observer_name')=lower($1) AND lower(detail->>'culprit_name')=lower($2) AND COALESCE(detail->>'fight_incident', incident_id) IN (
            SELECT incident_id FROM social_effect WHERE applied AND delta<0 AND component = ANY($3::text[]) AND game_ts >= $4
              AND lower(detail->>'observer_name')=lower($1) AND lower(detail->>'culprit_name')=lower($2))",
        [$observer, $culprit, $list, (int)$last['game_ts'] - 86400, stobeSocialPgList(STOBE_SOCIAL_FIGHT_RELIEF)]);
    return [strval($last['incident_id']), min(0, (int)($row['s'] ?? 0))];
}

// ---------- #6 open grudge ----------
/** Fight penalty of observer toward culprit not yet won back (<= 0): fight charges + every positive gain since the first fight. */
function stobeSocialGrudgeOutstanding(string $observer, string $culprit): int
{
    try {
        $db = $GLOBALS['db'];
        $first = $db->fetchOne("SELECT MIN(game_ts) AS t, COALESCE(SUM(delta),0) AS s FROM social_effect WHERE applied AND delta<0 AND component = ANY($3::text[])
              AND lower(detail->>'observer_name')=lower($1) AND lower(detail->>'culprit_name')=lower($2)", [$observer, $culprit, stobeSocialPgList(STOBE_SOCIAL_VICTIM_FIGHT)]);
        if (!$first || $first['t'] === null) return 0;
        $back = $db->fetchOne("SELECT COALESCE(SUM(delta),0) AS s FROM social_effect WHERE applied AND delta>0 AND game_ts >= $3
              AND lower(detail->>'observer_name')=lower($1) AND lower(detail->>'culprit_name')=lower($2)", [$observer, $culprit, (int)$first['t']]);
        return min(0, (int)$first['s'] + (int)($back['s'] ?? 0));
    } catch (Throwable $e) {
        return 0;
    }
}

/** #4: a fight charge of observer toward culprit within the last game day (relative to now). */
function stobeSocialRecentFight(string $observer, string $culprit, int $now, int $window = 86400): bool
{
    try {
        $row = $GLOBALS['db']->fetchOne("SELECT 1 AS x FROM social_effect WHERE applied AND delta<0 AND component = ANY($3::text[]) AND game_ts >= $4
              AND lower(detail->>'observer_name')=lower($1) AND lower(detail->>'culprit_name')=lower($2) LIMIT 1",
            [$observer, $culprit, stobeSocialPgList(STOBE_SOCIAL_VICTIM_FIGHT), $now - $window]);
        return is_array($row);
    } catch (Throwable $e) {
        return false;
    }
}

/** The current game time: the game's latest event (last 10 real minutes), else the newest ledger row (tests, idle game). */
function stobeSocialNowGameTs(): int
{
    try {
        $row = $GLOBALS['db']->fetchOne('SELECT MAX(gamets) AS g FROM eventlog WHERE localts >= $1', [time() - 600]);
        if ((int)($row['g'] ?? 0) > 0) return (int)$row['g'];
    } catch (Throwable $e) {}
    try { $row = $GLOBALS['db']->fetchOne('SELECT MAX(game_ts) AS t FROM social_effect'); return max(0, (int)($row['t'] ?? 0)); } catch (Throwable $e) { return 0; }
}

/**
 * #4 + #6 on dialogue (evaluator) gains: no gain for a game day after a fight, half while a fight grudge is open.
 * A halved gain is written to the ledger (dialogue_repair, applied: the evaluator writes the value itself) so
 * chat effort counts toward closing the grudge.
 */
function stobeSocialFightDialogueRules(string $speaker, array $updates): array
{
    if (!$updates || !in_array(stobeSocialIngestMode(), ['enabled', 'fights'], true) || !stobeSocialFightRulesOn()) return $updates;
    $now = null;
    $rules = new SocialRules();
    $rate = (float)($rules->section('fights')['grudge_positive_rate'] ?? 0.5);
    $cooldown = (int)($rules->section('fights')['chat_cooldown_seconds'] ?? 86400);
    foreach ($updates as &$update) {
        if (!is_array($update) || (int)($update['aff_delta'] ?? 0) <= 0) continue;
        $target = strval($update['target'] ?? '');
        if ($target === '') continue;
        $now = $now ?? stobeSocialNowGameTs();
        $before = (int)$update['aff_delta'];
        if (stobeSocialRecentFight($speaker, $target, $now, $cooldown)) { $update['aff_delta'] = 0; $why = 'fight_cooldown_1_game_day'; }
        elseif (stobeSocialGrudgeOutstanding($speaker, $target) < 0) {
            $update['aff_delta'] = (int)floor($before * $rate); $why = 'open_fight_grudge_half_rate';
            if ($update['aff_delta'] > 0) stobeSocialLedgerDialogueRepair($speaker, $target, (int)$update['aff_delta'], $now);
        }
        else continue;
        if (function_exists('stobeLogRelationshipInfo')) stobeLogRelationshipInfo('SOCIAL_DIALOGUE filtered', ['speaker'=>$speaker, 'target'=>$target,
            'from'=>$before, 'to'=>$update['aff_delta'], 'reason'=>$why]);
    }
    unset($update);
    return $updates;
}

function stobeSocialLedgerDialogueRepair(string $observer, string $culprit, int $delta, int $now): void
{
    try {
        $db = $GLOBALS['db'];
        try { $scope = stobeSocialScope($db); } catch (Throwable $e) { $scope = ['campaign_id'=>STOBE_SOCIAL_LEGACY_CAMPAIGN, 'timeline_epoch'=>'0']; }
        $db->exec("INSERT INTO social_effect(campaign_id,timeline_epoch,incident_id,observer_key,culprit_key,component,game_ts,delta,detail,applied,rules_version)
             VALUES($1,$2,$3,$4,$5,'dialogue_repair',$6,$7,jsonb_build_object('observer_name',$8::text,'culprit_name',$9::text,'total',$7::int,'source','dialogue'),true,$10)",
            [strval($scope['campaign_id']), strval($scope['timeline_epoch']), 'dialogue:' . $now . ':' . bin2hex(random_bytes(4)),
             'name:' . strtolower($observer), 'name:' . strtolower($culprit), $now, max(1, min(100, $delta)), $observer, $culprit, (new SocialRules())->version()]);
    } catch (Throwable $e) {
        if (function_exists('stobeLogWarn')) stobeLogWarn('REL dialogue repair ledger row failed', ['error'=>$e->getMessage()]);
    }
}

/** #6: the first fight leaves a mark (stance block line); stays after the value recovers. */
function stobeSocialFightMemoryLine(string $npcName, string $other): string
{
    if (stobeSocialIngestMode() === 'off' || !stobeSocialFightRulesOn()) return '';
    try {
        $row = $GLOBALS['db']->fetchOne("SELECT incident_id, game_ts FROM social_effect WHERE applied AND delta<0 AND component = ANY($3::text[])
              AND lower(detail->>'observer_name')=lower($1) AND lower(detail->>'culprit_name')=lower($2) ORDER BY game_ts ASC LIMIT 1",
            [$npcName, $other, stobeSocialPgList(STOBE_SOCIAL_VICTIM_FIGHT)]);
        if (!is_array($row)) return '';
        $worst = $GLOBALS['db']->fetchAll("SELECT component FROM social_effect WHERE applied AND incident_id=$1 AND lower(detail->>'observer_name')=lower($2)
              AND lower(detail->>'culprit_name')=lower($3)", [$row['incident_id'], $npcName, $other]) ?: [];
    } catch (Throwable $e) {
        return '';
    }
    $components = array_column($worst, 'component');
    $what = 'attacked you';
    foreach (['injury'=>'hurt you', 'serious_assault'=>'knocked you out', 'critical_harm'=>'left you bleeding out', 'maiming'=>'cost you a limb',
              'accident'=>'hurt you by accident', 'defensive_maiming'=>'cost you a limb when you attacked them'] as $c => $text) {
        if (in_array($c, $components, true)) $what = $text;
    }
    $day = intdiv(max(0, (int)$row['game_ts']), 86400) + 1;
    return $other . ' ' . $what . ' in a fight (day ' . $day . '). You remember it, even if you have made peace since.';
}

/** Item 8: the share of a fight penalty the attacker's treatment takes off: 15-30 %, more the sooner, seeded per incident. */
function stobeSocialTreatedShare(string $incident, string $observer, int $elapsed, SocialRules $rules): float
{
    $f = $rules->section('fights');
    [$lo, $hi] = $f['treated_share'] ?? [0.15, 0.30];
    $soonWindow = max(1, (int)($f['treated_soon_seconds'] ?? 21600));
    $soon = max(0.0, min(1.0, 1.0 - max(0, $elapsed) / $soonWindow));
    $seed = hexdec(substr(hash('sha256', $incident . '|' . strtolower($observer) . '|treated'), 0, 6)) / 0xFFFFFF;
    return (float)$lo + ((float)$hi - (float)$lo) * (0.6 * $soon + 0.4 * $seed);
}
