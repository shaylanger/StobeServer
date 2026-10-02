<?php

/**
 * Deal ledger. Records what was agreed; negotiation_engine.php performs and
 * verifies it. World actions remain pending until verified from evidence.
 */
function stobeDealValidate(array $deal): array {
    // LEAVE_AREA stays out until a real executor exists (no promises the game cannot keep).
    $allowed = function_exists('stobeNegAllowedTermKinds')
        ? stobeNegAllowedTermKinds()
        : ['GIVE_CATS','GIVE_ITEM','RETURN_ITEM','STOP_ATTACK','FIRST_AID'];
    $parties = $deal['parties'] ?? [];
    if (!is_array($parties) || count($parties) !== 2 || !isset($parties['npc'], $parties['player'])) {
        return ['ok'=>false, 'error'=>'invalid_parties'];
    }
    if (strval($parties['npc']) === '' || strval($parties['player']) === '' || strcasecmp(strval($parties['npc']), strval($parties['player'])) === 0) {
        return ['ok'=>false, 'error'=>'invalid_parties'];
    }
    $terms = $deal['terms'] ?? [];
    if (!is_array($terms) || count($terms) < 1 || count($terms) > 8) {
        return ['ok'=>false, 'error'=>'invalid_terms'];
    }
    foreach ($terms as $term) {
        $kind = strval(is_array($term) ? ($term['kind'] ?? '') : '');
        $by = strval(is_array($term) ? ($term['by'] ?? '') : '');
        if (!is_array($term) || !in_array($kind, $allowed, true) || !in_array($by, ['npc','player'], true)) {
            return ['ok'=>false, 'error'=>'invalid_term'];
        }
        $performer = function_exists('stobeNegTermPerformer') ? stobeNegTermPerformer($kind) : '';
        if ($performer !== '' && $performer !== $by) {
            return ['ok'=>false, 'error'=>'wrong_performer'];
        }
        if (in_array($kind, ['GIVE_CATS','GIVE_ITEM','RETURN_ITEM','LOAN_ITEM'], true) && !in_array(strval($term['to'] ?? ''), ['npc','player'], true)) {
            return ['ok'=>false, 'error'=>'invalid_recipient'];
        }
        if (isset($term['to']) && $term['to'] === $term['by']) {
            return ['ok'=>false, 'error'=>'self_transfer'];
        }
        if ($kind === 'GIVE_CATS' && (!filter_var($term['amount'] ?? null, FILTER_VALIDATE_INT) || intval($term['amount']) < 1 || intval($term['amount']) > 10000000)) {
            return ['ok'=>false, 'error'=>'invalid_cats'];
        }
        if (in_array($kind, ['GIVE_ITEM','RETURN_ITEM','LOAN_ITEM','UNEQUIP_ITEM','EQUIP_ITEM'], true)
            && (trim(strval($term['item'] ?? '')) === '' || intval($term['quantity'] ?? 1) < 1 || intval($term['quantity'] ?? 1) > 100)) {
            return ['ok'=>false, 'error'=>'invalid_item'];
        }
        if (in_array($kind, ['STOP_ATTACK','FIRST_AID','SAFE_PASSAGE','SPARE','PROTECT'], true) && !in_array(strval($term['target'] ?? ''), ['npc','player'], true)) {
            return ['ok'=>false, 'error'=>'invalid_target'];
        }
        if (in_array($kind, ['SPARE','PROTECT'], true) && strval($term['target'] ?? '') !== 'npc') {
            return ['ok'=>false, 'error'=>'invalid_target'];
        }
        if ($kind === 'PROMISE' && trim(strval($term['text'] ?? '')) === '') {
            return ['ok'=>false, 'error'=>'empty_promise'];
        }
        if ($kind === 'RELEASE_PRISONER' && trim(strval($term['subject'] ?? '')) === '') {
            return ['ok'=>false, 'error'=>'missing_prisoner'];
        }
        if (isset($term['when']) && strval($term['when']) === 'after_npc' && $by !== 'player') {
            return ['ok'=>false, 'error'=>'invalid_when'];
        }
        if (isset($term['when']) && !in_array(strval($term['when']), ['now','after_player','after_npc'], true)) {
            return ['ok'=>false, 'error'=>'invalid_when'];
        }
    }
    return ['ok'=>true];
}

/** Logging that also works when this file is loaded without the rest of the server (unit tests). */
function stobeDealLog(string $level, string $message, array $context = []): void {
    $fn = $level === 'warn' ? 'stobeLogWarn' : 'stobeLogInfo';
    if (function_exists($fn)) $fn($message, $context);
}

function stobeDealEnsureSchema(): void {
    static $done = false;
    if ($done) return;
    $db = $GLOBALS['db'] ?? null;
    if (!$db) throw new RuntimeException('Deal database unavailable');
    $sql = [
        "CREATE TABLE IF NOT EXISTS stobe_social_contract (
            contract_id TEXT PRIMARY KEY,
            npc_name TEXT NOT NULL,
            npc_serial BIGINT NOT NULL DEFAULT 0,
            player_name TEXT NOT NULL,
            status TEXT NOT NULL CHECK (status IN ('PROPOSED','COUNTERED','ACCEPTED','AWAITING_PERFORMANCE','COMPLETE','REJECTED','CANCELLED','EXPIRED','BREACHED_PLAYER','BREACHED_NPC','IMPOSSIBLE')),
            conflict_context JSONB NOT NULL DEFAULT '{}'::jsonb,
            terms JSONB NOT NULL,
            evidence JSONB NOT NULL DEFAULT '{}'::jsonb,
            deadline_at TIMESTAMP,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW())",
        "CREATE INDEX IF NOT EXISTS idx_social_contract_npc ON stobe_social_contract (LOWER(npc_name), updated_at DESC)",
        "CREATE INDEX IF NOT EXISTS idx_social_contract_active ON stobe_social_contract (status, deadline_at)"
    ];
    foreach ($sql as $statement) {
        if ($db->exec($statement) === false) throw new RuntimeException('Deal schema creation failed');
    }
    $done = true;
}

function stobeDealCreate(array $deal): array {
    $valid = stobeDealValidate($deal);
    if (!$valid['ok']) return $valid;
    stobeDealEnsureSchema();
    if (function_exists('stobeNegEnsureSchema')) stobeNegEnsureSchema();
    $id = 'deal-' . bin2hex(random_bytes(12));
    $parties = $deal['parties'];
    $serial = function_exists('stobeResolveLiveParticipantSerial') ? stobeResolveLiveParticipantSerial(strval($parties['npc'])) : 0;
    $context = $deal['context'] ?? [];
    if (!is_array($context)) $context = [];
    $kind = strval($deal['kind'] ?? 'combat') ?: 'combat';
    $proposer = strval($deal['proposer'] ?? 'player') ?: 'player';
    $db = $GLOBALS['db'];
    $result = $db->exec(
        "INSERT INTO stobe_social_contract (contract_id,npc_name,npc_serial,player_name,status,conflict_context,terms,kind,proposer)
         VALUES ($1,$2,$3,$4,'PROPOSED',$5::jsonb,$6::jsonb,$7,$8)",
        [$id,strval($parties['npc']),intval($serial),strval($parties['player']),json_encode($context),json_encode($deal['terms']),$kind,$proposer]
    );
    return $result === false ? ['ok'=>false,'error'=>'database_write_failed'] : ['ok'=>true,'id'=>$id];
}

function stobeDealTransition(string $id, string $from, string $to, array $evidence = []): bool {
    $edges = [
        'PROPOSED'=>['COUNTERED','ACCEPTED','REJECTED','CANCELLED','EXPIRED','IMPOSSIBLE'],
        'COUNTERED'=>['COUNTERED','ACCEPTED','REJECTED','CANCELLED','EXPIRED','IMPOSSIBLE'],
        'ACCEPTED'=>['CANCELLED','IMPOSSIBLE'],
        // AWAITING_PERFORMANCE and its outcomes belong to the engine, which verifies from evidence.
    ];
    if (!in_array($to, $edges[$from] ?? [], true)) return false;
    stobeDealEnsureSchema();
    $db = $GLOBALS['db'];
    $result = $db->exec(
        "UPDATE stobe_social_contract SET status=$3,
                evidence=(CASE WHEN jsonb_typeof(evidence)='object' THEN evidence ELSE '{}'::jsonb END) || $4::jsonb,
                updated_at=NOW()
          WHERE contract_id=$1 AND status=$2",
        [$id,$from,$to,json_encode((object)$evidence)]
    );
    return $result !== false && $db->affectedRows($result) === 1;
}

function stobeDealContextSnapshot(string $npc, array $npcData, string $playerMessage): array {
    $metadata = function_exists('normalizeNpcMetadataPayload')
        ? normalizeNpcMetadataPayload($npcData['metadata'] ?? []) : [];
    $combat = function_exists('stobeNpcIsInCombat') && stobeNpcIsInCombat($npcData);
    return [
        'npc' => $npc,
        'combat_observed' => $combat,
        'current_action' => trim(strval($metadata['current_action'] ?? '')),
        'player_offer' => substr(trim($playerMessage), 0, 1000),
        'origin' => 'unknown',
        'origin_confidence' => 'unknown',
        'observed_at' => gmdate('c'),
    ];
}

function stobeDealLooksNegotiableMessage(string $message): bool {
    return preg_match('/\b(stop|wait|deal|truce|peace|give|pay|return|sorry|accident|surrender|spare|mercy|let me|listen|talk|fight|attack|cats?)\b/i', $message) === 1;
}

function stobeDealRecentForNpc(string $npc, int $limit = 3): array {
    stobeDealEnsureSchema();
    if (function_exists('stobeNegEnsureSchema')) stobeNegEnsureSchema();
    $serial = function_exists('stobeResolveLiveParticipantSerial')
        ? stobeResolveLiveParticipantSerial($npc) : 0;
    $where = $serial > 0
        ? 'npc_serial=$1'
        : 'LOWER(npc_name)=LOWER($1)';
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT *, EXTRACT(EPOCH FROM (NOW() - updated_at))::int AS age_seconds
         FROM stobe_social_contract WHERE " . $where . "
         ORDER BY updated_at DESC LIMIT " . max(1,min(10,$limit)),
        [$serial > 0 ? $serial : $npc]
    );
    return is_array($rows) ? $rows : [];
}

const STOBE_DEAL_OPEN_STATUSES = ['PROPOSED','COUNTERED','ACCEPTED','AWAITING_PERFORMANCE'];
const STOBE_DEAL_OPEN_MAX_AGE_SECONDS = 600;

/** Most recent open (non-final, not stale) deal with this NPC, or null. */
function stobeDealOpenForNpc(string $npc): ?array {
    if (trim($npc) === '') return null;
    foreach (stobeDealRecentForNpc($npc, 3) as $row) {
        $status = strval($row['status'] ?? '');
        if (!in_array($status, STOBE_DEAL_OPEN_STATUSES, true)) continue;
        // Age comes from the DB clock; PHP and PostgreSQL time zones differ here.
        // Deals being performed stay open until the engine resolves them.
        if ($status !== 'AWAITING_PERFORMANCE' && intval($row['age_seconds'] ?? 0) > STOBE_DEAL_OPEN_MAX_AGE_SECONDS) continue;
        return $row;
    }
    return null;
}

/**
 * One trigger for both the prompt block and the response schema, so they cannot drift.
 * An open deal keeps the negotiation going even when the combat flag flickers or the
 * player's wording misses the keyword filter. Outside combat (Phase 6) an explicit
 * offer starts a social deal.
 */
function stobeDealShouldNegotiate(string $npc, array|false $npcData, string $message): bool {
    if (!is_array($npcData) || npcIsInPlayerFaction($npcData)) return false;
    try {
        if (stobeDealOpenForNpc($npc) !== null) return true;
    } catch (Throwable $e) {
        stobeDealLog('warn', 'Negotiation open-deal lookup failed', ['npc'=>$npc, 'error'=>$e->getMessage()]);
    }
    if (stobeDealNpcFightingPlayer($npcData)) return stobeDealLooksNegotiableMessage($message);
    return function_exists('stobeNegLooksLikeSocialOffer') && stobeNegLooksLikeSocialOffer($message);
}

/** Deal kind for a new negotiation with this NPC right now. */
function stobeDealKindFor(array|false $npcData): string {
    $open = null;
    $name = is_array($npcData) ? normalizeParticipantNameToken(strval($npcData['name'] ?? '')) : '';
    if ($name !== '') {
        try { $open = stobeDealOpenForNpc($name); } catch (Throwable $e) { $open = null; }
    }
    if ($open !== null) return strval($open['kind'] ?? 'combat') ?: 'combat';
    return stobeDealNpcFightingPlayer($npcData) ? 'combat' : 'social';
}

/** Fighting the player, even before the NPC's combat flag catches up (bug 33). */
function stobeDealNpcFightingPlayer(array|false $npcData): bool {
    if (!is_array($npcData)) return false;
    if (stobeNpcIsInCombat($npcData)) return true;
    $name = normalizeParticipantNameToken(strval($npcData['name'] ?? ''));
    if ($name === '') return false;
    try {
        $fights = json_decode(strval(getConfOpt('STOBE_PERSONAL_FIGHTS', '{}')), true);
        $fight = is_array($fights) ? ($fights[strtolower($name)] ?? null) : null;
        if (is_array($fight) && time() - intval($fight['since'] ?? 0) <= 60) return true;
        $player = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
        $row = $GLOBALS['db']->fetchOne(
            "SELECT 1 AS hit FROM eventlog WHERE type='combat' AND localts >= $1 AND LOWER(data) LIKE LOWER($2) LIMIT 1",
            [time() - 60, $name . ': Initiated attack (talking to: ' . $player . ')%']
        );
        return is_array($row) && !empty($row['hit']);
    } catch (Throwable $e) {
        return false;
    }
}

/** Replace a deal's terms while moving it along a permitted edge (counter revised or accepted). */
function stobeDealReviseTerms(string $id, string $from, string $to, array $terms, array $previousTerms): bool {
    $edges = [
        'COUNTERED'=>['COUNTERED','ACCEPTED'],
        'PROPOSED'=>['COUNTERED','ACCEPTED'],
    ];
    if (!in_array($to, $edges[$from] ?? [], true)) return false;
    stobeDealEnsureSchema();
    if (function_exists('stobeNegEnsureSchema')) stobeNegEnsureSchema();
    $db = $GLOBALS['db'];
    $result = $db->exec(
        "UPDATE stobe_social_contract
            SET status=$3, terms=$4::jsonb,
                rounds = rounds + (CASE WHEN $3::text='COUNTERED' THEN 1 ELSE 0 END),
                evidence = (CASE WHEN jsonb_typeof(evidence)='object' THEN evidence ELSE '{}'::jsonb END)
                    || jsonb_build_object('revisions',
                    COALESCE(CASE WHEN jsonb_typeof(evidence)='object' THEN evidence->'revisions' END,'[]'::jsonb) || jsonb_build_array(jsonb_build_object(
                        'from',$2::text,'to',$3::text,'previous_terms',$5::jsonb,'at',NOW()))),
                updated_at=NOW()
          WHERE contract_id=$1 AND status=$2",
        [$id,$from,$to,json_encode($terms),json_encode($previousTerms)]
    );
    return $result !== false && $db->affectedRows($result) === 1;
}

function stobeDealTermKindsText(): string {
    $kinds = function_exists('stobeNegAllowedTermKinds')
        ? stobeNegAllowedTermKinds() : ['GIVE_CATS','GIVE_ITEM','RETURN_ITEM','STOP_ATTACK','FIRST_AID'];
    return implode(', ', $kinds);
}

function stobeDealPromptBlock(string $npc, array $npcData, string $playerMessage): string {
    $context = stobeDealContextSnapshot($npc,$npcData,$playerMessage);
    $open = null;
    $active = [];
    foreach (stobeDealRecentForNpc($npc) as $row) {
        if (in_array(strval($row['status'] ?? ''), STOBE_DEAL_OPEN_STATUSES, true)) {
            $open = $open ?? $row;
            $active[] = [
                'id'=>$row['contract_id'],
                'status'=>$row['status'],
                'kind'=>$row['kind'] ?? 'combat',
                'proposed_by'=>$row['proposer'] ?? 'player',
                'terms'=>json_decode(strval($row['terms'] ?? '[]'),true),
            ];
        }
    }
    $kind = $open !== null ? strval($open['kind'] ?? 'combat') : ((stobeNpcIsInCombat($npcData)) ? 'combat' : 'social');
    $rules = "An offer is not a completed action. Judge it using live context, your motives, prior history and what each side can really provide."
        . " Set deal_decision to ACCEPT, COUNTER, REJECT, PROPOSE (you make the first offer) or NONE."
        . " For ACCEPT, COUNTER or PROPOSE, put a JSON array of concrete obligations in deal_terms. Example: "
        . '[{"kind":"GIVE_CATS","by":"player","to":"npc","amount":100},{"kind":"STOP_ATTACK","by":"npc","target":"player"}]'
        . " Allowed kinds: " . stobeDealTermKindsText() . "."
        . " Items use \"item\" and optional \"quantity\"; add \"when\":\"after_player\" to your own terms that you only perform once the player has delivered. Always write sides as \"player\" and \"npc\" (never names). When the player pays in parts (\"half now, half later\"), make one GIVE_CATS term per part and add \"when\":\"after_npc\" to the part paid after you deliver; your own delivery then waits only for the first part. If the player accepts your last offer, ACCEPT exactly those terms; never raise the price after they agreed. A fight having started does not mean anyone is hurt: only mention wounds the condition data shows. If the player offers to heal, bandage or patch you up, that is their term: kind FIRST_AID, by player, target npc. To hand over something you are wearing, use GIVE_ITEM (it comes off and goes to them); UNEQUIP_ITEM only takes it off and you keep it. When you agree to take something off, put something on or hand something over, use the concrete kind (UNEQUIP_ITEM, EQUIP_ITEM, GIVE_ITEM with the exact item name), never PROMISE; PROMISE is only for things the game cannot carry out. Bets and anything that depends on an outcome that has not happened yet (\"if you win\", \"if I lose\") must be a PROMISE term with that condition in its text, never a transfer. If an item is offered or demanded only generically, use item \"drink\" or \"food\" (any drink/food then counts); if a specific item is named (\"bread\"), use that exact name.";
    if (in_array($kind, ['combat','surrender'], true)) {
        $rules .= " An accepted or countered combat deal must include your STOP_ATTACK.";
        // Bug 43: her STOP_ATTACK stands down her faction-mates too (DLL round 15).
        $rules .= " When you stop fighting the player, your own faction's people who joined the fight stand down with you:"
            . " you can call them off, so never say they are not yours to call off.";
    }
    $rules .= " If active_deals holds a COUNTERED or PROPOSED deal and the player now agrees to it, answer ACCEPT with those terms; if you agree to stop fighting, you must answer ACCEPT (never agree in words while choosing COUNTER, REJECT or NONE)."
        . " Terms do not execute merely because you speak. Never claim payment, transfer, first aid, or lasting peace is complete before the deal progress below shows it verified. If the cause is unknown, do not invent it.";
    $extras = function_exists('stobeNegDealPromptExtras')
        ? stobeNegDealPromptExtras($npc, $npcData, $open ?? []) : '';
    return "<situational_negotiation>\n"
        . json_encode(['context'=>$context,'active_deals'=>$active], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
        . "\n" . $rules
        . ($extras !== '' ? "\n" . $extras : '')
        . "\n</situational_negotiation>";
}

function stobeDealResponseFormat(array $format): array {
    $schema =& $format['json_schema']['schema'];
    $schema['properties']['deal_decision'] = [
        'type'=>'string',
        'enum'=>['NONE','ACCEPT','COUNTER','REJECT','PROPOSE'],
        'description'=>'Your decision about an actual offer in this conversation (PROPOSE when you make the first offer); NONE if no negotiation. If your words agree to the offer as it stands (even with "coin first"), this is ACCEPT, not COUNTER or NONE; COUNTER only when you change the terms.'
    ];
    $schema['properties']['deal_terms'] = [
        'type'=>'string',
        'description'=>'JSON array of concrete terms for ACCEPT, COUNTER or PROPOSE; empty string otherwise. Each term has kind, by, and target or to/amount/item/quantity/text/subject, plus optional when (now|after_player). Allowed kinds: ' . stobeDealTermKindsText() . '. by/to/target use npc or player. Always list BOTH sides: what the player gives AND what you give or do (for example UNEQUIP_ITEM for each piece of clothing you take off). Never list a term that is physically impossible or invent an item. Any number of Cats you say out loud must equal an amount (or the total) in deal_terms. Taking something off and keeping it is UNEQUIP_ITEM; use GIVE_ITEM only when you hand it to the player.'
    ];
    $schema['required'][] = 'deal_decision';
    $schema['required'][] = 'deal_terms';
    return $format;
}

/**
 * Bug 91: most Cats an NPC offers to be spared / for help. Common bandits
 * 50-300, leaders/bosses up to ~1000, wealthy NPCs scaled to their purse;
 * never more than 35 % of what they carry (Shay, 2026-10-01).
 * Returns [cap, carried, tier].
 */
function stobeNegOfferCap(string $npc, array $npcData): array {
    $meta = function_exists('normalizeNpcMetadataPayload')
        ? normalizeNpcMetadataPayload($npcData['metadata'] ?? []) : (is_array($npcData['metadata'] ?? null) ? $npcData['metadata'] : []);
    $carried = max(0, intval($meta['money'] ?? ($npcData['money'] ?? 0)));
    $who = strtolower($npc . ' ' . strval($npcData['faction'] ?? '') . ' ' . strval($meta['faction'] ?? '') . ' ' . strval($meta['title'] ?? ''));
    if (preg_match('/\b(traders?|merchants?|caravans?|nobles?|lords?|lady|shopkeepers?|barman|bartenders?|innkeepers?)\b/', $who)) {
        $tier = 'wealthy'; $tierCap = PHP_INT_MAX;
    } elseif (preg_match('/\b(leaders?|boss|king|queen|chief|captain|warlord|commander|elder)\b/', $who)) {
        $tier = 'leader'; $tierCap = 1000;
    } else {
        $tier = 'common'; $tierCap = 300;
    }
    $cap = min($tierCap, intval(floor($carried * 0.35)));
    return [max(0, $cap), $carried, $tier];
}

/**
 * Bug 90: a ceasefire deal with this NPC that completed recently (default 10 min).
 * Returns the contract row or null.
 */
/**
 * Bug 116: a paid ceasefire covers the NPC's whole faction for 10 minutes.
 * True when $action is ATTACK on the player side and must be dropped.
 */
function stobeNegCeasefireBlocksAttack(string $npc, string $action): bool {
    if (!preg_match('/^ATTACK@([^@]+)(?:@help)?$/i', trim($action), $m)) return false;
    try {
        $player = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
        $target = normalizeParticipantNameToken(trim($m[1]));
        if (!function_exists('stobeNegIsPlayerSide') || !stobeNegIsPlayerSide($target, $player)) return false;
        $me = $GLOBALS['db']->fetchOne("SELECT faction FROM core_npc_master WHERE LOWER(name)=LOWER($1) LIMIT 1", [$npc]);
        $faction = trim(strval($me['faction'] ?? ''));
        $deal = $faction === '' ? stobeDealRecentCompletedCeasefire($npc) : $GLOBALS['db']->fetchOne(
            "SELECT c.contract_id, EXTRACT(EPOCH FROM (NOW() - c.updated_at))::bigint AS done_ago
               FROM stobe_social_contract c JOIN core_npc_master m ON LOWER(m.name)=LOWER(c.npc_name)
              WHERE c.kind IN ('combat','surrender') AND c.status='COMPLETE'
                AND c.updated_at > NOW() - interval '600 seconds' AND LOWER(m.faction)=LOWER($1)
              ORDER BY c.updated_at DESC LIMIT 1",
            [$faction]
        );
        if (!is_array($deal)) return false;
        // Only the gang that fought in that deal's fight (the 15 min before it was
        // settled); another squad of the same faction isn't covered.
        // Age from the DB clock (PHP and PostgreSQL time zones differ here).
        $doneUnix = isset($deal['done_ago']) ? time() - intval($deal['done_ago']) : 0;
        if ($doneUnix > 0) {
            $foughtThen = false;
            foreach (stobeNegCombatEvents($doneUnix - 900, $npc) as $ev) {
                if ($ev['ts'] > $doneUnix) continue;
                $other = stobeNegCharMatches($ev['attacker'], $npc) ? $ev['target'] : $ev['attacker'];
                if (stobeNegIsPlayerSide($other, $player)) { $foughtThen = true; break; }
            }
            if (!$foughtThen) return false;
        }
        if (function_exists('stobeNegCombatEvents')) {
            foreach (stobeNegCombatEvents(time() - 60, $npc) as $ev) {
                if (stobeNegCharMatches($ev['target'], $npc) && stobeNegIsPlayerSide($ev['attacker'], $player)) return false;
            }
        }
        stobeLogInfo('Attack on the player dropped: paid ceasefire stands (bug 116)',
            ['npc'=>$npc, 'action'=>$action, 'faction'=>$faction, 'contract_id'=>$deal['contract_id'] ?? '']);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function stobeDealRecentCompletedCeasefire(string $npc, int $seconds = 600): ?array {
    try {
        $row = $GLOBALS['db']->fetchOne(
            "SELECT contract_id, kind FROM stobe_social_contract
              WHERE LOWER(npc_name)=LOWER($1) AND kind IN ('combat','surrender') AND status='COMPLETE'
                AND updated_at > NOW() - ($2 || ' seconds')::interval
              ORDER BY updated_at DESC LIMIT 1",
            [$npc, strval(max(1, $seconds))]
        );
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Bug 129: "Make it 400" -> "Four hundred. Fine." is her accepting the player's
 * counter: his line names an amount and her terms differ from her own offer.
 */
function stobeDealAcceptsPlayerCounter(array $response, array $open, string $playerMessage): bool {
    if (!preg_match('/\d|\b(hundred|thousand|fifty|twenty|thirty|forty|sixty|seventy|eighty|ninety)\b/i', $playerMessage)) return false;
    $terms = json_decode(trim(strval($response['deal_terms'] ?? '')), true);
    if (!is_array($terms) || count($terms) === 0) return false;
    $openTerms = json_decode(strval($open['terms'] ?? '[]'), true);
    return function_exists('stobeDealTermsDiffer') && stobeDealTermsDiffer(is_array($openTerms) ? $openTerms : [], $terms);
}

function stobeDealCaptureResponse(string $raw, string $npc, string $player, array $npcData, string $playerMessage, string $kind = 'combat', string $proposer = 'player'): array {
    $response = function_exists('stobeDecodeStructuredDialoguePayload')
        ? stobeDecodeStructuredDialoguePayload($raw) : json_decode($raw, true);
    // Some providers fall back to plain text. Preserve ordinary dialogue.
    if (!is_array($response)) return ['ok'=>true,'decision'=>'NONE'];
    $decision = strtoupper(trim(strval($response['deal_decision'] ?? 'NONE')));
    $open = stobeDealOpenForNpc($npc);
    if ($decision === 'REJECT' && $open !== null && in_array(strval($open['status']), ['PROPOSED','COUNTERED'], true)) {
        stobeDealTransition(strval($open['contract_id']), strval($open['status']), 'REJECTED', ['rejected_by'=>'npc']);
        return ['ok'=>true,'decision'=>'REJECT','id'=>strval($open['contract_id'])];
    }
    if ($decision === 'NONE' || $decision === 'REJECT') return ['ok'=>true,'decision'=>$decision];
    if (!in_array($decision, ['ACCEPT','COUNTER','PROPOSE'], true)) return ['ok'=>false,'error'=>'invalid_decision'];
    // Bug 96: on an NPC's own offer, ACCEPT stands only if the player's line accepts it.
    if ($decision === 'ACCEPT' && $open !== null && strval($open['proposer'] ?? 'player') === 'npc'
        && function_exists('stobeNegLooksLikeAcceptance')
        && !stobeNegLooksLikeAcceptance(strtolower(trim($playerMessage)))
        && !stobeDealAcceptsPlayerCounter($response, $open, $playerMessage)) {
        stobeDealLog('warn', 'Negotiation: ACCEPT ignored, the player did not accept the NPC offer (bug 96)',
            ['npc'=>$npc, 'message'=>$playerMessage]);
        return ['ok'=>true,'decision'=>'NONE'];
    }
    $termsRaw = trim(strval($response['deal_terms'] ?? ''));
    $terms = json_decode($termsRaw, true);
    if (!is_array($terms) && $termsRaw !== '') {
        // Models sometimes echo the prompt's summary text instead of JSON.
        $terms = stobeDealParseTermsText($termsRaw);
        stobeDealLog($terms === null ? 'warn' : 'info', $terms === null
            ? 'Negotiation terms unreadable (neither JSON nor summary text)'
            : 'Negotiation terms parsed from summary text', ['npc'=>$npc, 'decision'=>$decision, 'terms'=>$termsRaw]);
    }
    // Valid JSON that holds no terms ('{"accepted": true}') is the same as no terms.
    if (is_array($terms)) {
        $hasTerm = false;
        foreach ($terms as $candidate) {
            if (is_array($candidate) && isset($candidate['kind'])) { $hasTerm = true; break; }
        }
        if (!$hasTerm) $terms = [];
    }
    if ((!is_array($terms) || count($terms) === 0) && $decision === 'ACCEPT' && $open !== null) {
        $terms = json_decode(strval($open['terms'] ?? '[]'), true); // "deal" = the terms on the table
    }
    if (!is_array($terms)) return ['ok'=>false,'error'=>'invalid_terms_json','decision'=>$decision];
    $terms = stobeDealNormalizeConditionalTerms(array_values(array_filter($terms, 'is_array')), $npc, $player);
    $terms = stobeDealPromoteClothingPromises($terms, $npcData);
    $terms = stobeDealFixTakeOffTerms($terms, $npcData, $playerMessage);
    // A social deal needs something from the NPC. Counters often restate only the price:
    // keep the NPC's side from the deal on the table, else refuse the one-sided terms.
    $effectiveKind = $open !== null ? (strval($open['kind'] ?? $kind) ?: $kind) : $kind;
    if ($effectiveKind === 'social' && count($terms) > 0) {
        $npcSide = array_filter($terms, static fn($t) => is_array($t) && ($t['by'] ?? '') === 'npc');
        if (count($npcSide) === 0) {
            $openTerms = $open !== null ? json_decode(strval($open['terms'] ?? '[]'), true) : [];
            $carried = array_values(array_filter(is_array($openTerms) ? $openTerms : [], static fn($t) => is_array($t) && ($t['by'] ?? '') === 'npc'));
            if (count($carried) === 0) {
                stobeDealLog('warn', 'Negotiation rejected: one-sided terms (nothing from the NPC)', ['npc'=>$npc, 'decision'=>$decision]);
                return ['ok'=>false, 'error'=>'one_sided_terms'];
            }
            $terms = array_merge($terms, $carried);
            stobeDealLog('info', 'Negotiation: NPC side carried over from the deal on the table', ['npc'=>$npc, 'decision'=>$decision]);
        }
    }
    if ($open !== null) $kind = strval($open['kind'] ?? $kind) ?: $kind;
    if (in_array($kind, ['combat','surrender'], true)) {
        $ceasefire = false;
        foreach ($terms as $term) {
            if (($term['kind'] ?? '') === 'STOP_ATTACK' && ($term['by'] ?? '') === 'npc' && ($term['target'] ?? '') === 'player') {
                $ceasefire = true;
            }
        }
        // Agreeing to terms in the middle of a fight means stopping the fight; an empty agreement stays invalid.
        if (!$ceasefire && count($terms) === 0) return ['ok'=>false,'error'=>'missing_npc_ceasefire'];
        if (!$ceasefire) $terms[] = ['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player'];
    }
    if (in_array($kind, ['surrender','assist'], true)) {
        [$offerCap, $offerCarried, $offerTier] = stobeNegOfferCap($npc, $npcData);
        foreach ($terms as $ti => $term) {
            if (($term['kind'] ?? '') !== 'GIVE_CATS' || ($term['by'] ?? '') !== 'npc') continue;
            $amount = intval($term['amount'] ?? 0);
            if ($amount <= $offerCap) continue;
            stobeDealLog('info', 'NPC offer capped (bug 91)', ['npc'=>$npc, 'offered'=>$amount, 'cap'=>$offerCap, 'carried'=>$offerCarried, 'tier'=>$offerTier]);
            if ($offerCap > 0) {
                $terms[$ti]['amount'] = $offerCap;
            } else {
                unset($terms[$ti]); // broke: no Cats in the deal
            }
        }
        $terms = array_values($terms);
    }
    // Bug 32: her own weapon only goes when she's surrendering or trusts the player.
    $weaponGiven = stobeDealTermsGiveUpWeapon($terms, $npcData);
    if ($weaponGiven !== '' && !stobeDealWeaponReleaseAllowed($npcData, $player, $kind)) {
        stobeDealLog('warn', 'Negotiation rejected: NPC would give up her weapon (not surrendering, not trusted)', [
            'npc'=>$npc, 'decision'=>$decision, 'weapon'=>$weaponGiven, 'kind'=>$kind,
            'trust'=>stobeDealNpcTrust($npcData, $player),
        ]);
        return ['ok'=>false, 'error'=>'weapon_not_negotiable', 'weapon'=>$weaponGiven,
            'refusal_line'=>'Not my ' . $weaponGiven . '. That stays with me, whatever you pay.'];
    }
    $deal = [
        'parties'=>['npc'=>$npc,'player'=>$player],
        'terms'=>$terms,
        'context'=>stobeDealContextSnapshot($npc,$npcData,$playerMessage),
        'kind'=>$kind,
        'proposer'=>$decision === 'PROPOSE' ? 'npc' : $proposer,
    ];
    if ($open !== null) {
        $openId = strval($open['contract_id']);
        $openStatus = strval($open['status']);
        if (in_array($openStatus, ['ACCEPTED','AWAITING_PERFORMANCE'], true)) {
            $openTerms = json_decode(strval($open['terms'] ?? '[]'), true);
            if (stobeDealTermsDiffer(is_array($openTerms) ? $openTerms : [], $terms) && stobeDealNothingPerformedYet($open)) {
                // A new, different agreement before anything happened replaces the old one.
                $GLOBALS['db']->exec(
                    "UPDATE stobe_social_contract SET status='CANCELLED', consequences_applied=TRUE, resolved_at=NOW(), updated_at=NOW(),
                            evidence=(CASE WHEN jsonb_typeof(evidence)='object' THEN evidence ELSE '{}'::jsonb END) || '{\"note\":\"superseded_by_renegotiation\"}'::jsonb
                      WHERE contract_id=$1 AND status=$2",
                    [$openId, $openStatus]
                );
                stobeDealLog('info', 'Negotiation contract superseded by renegotiation', ['contract_id'=>$openId,'npc'=>$npc]);
                $open = null;
            } else {
                // Underway and something still owed: a clearly different deal waits until it's settled.
                if (stobeDealTermsDiffer(is_array($openTerms) ? $openTerms : [], $terms)
                    && !stobeDealTermsLooselySame($terms, is_array($openTerms) ? $openTerms : [])
                    && stobeDealIsUnderway($open)) {
                    stobeDealLog('info', 'Negotiation: new deal refused while another is underway', [
                        'npc'=>$npc, 'open_contract_id'=>$openId, 'decision'=>$decision,
                    ]);
                    return ['ok'=>true, 'decision'=>'NONE', 'blocked_by_active'=>true, 'id'=>$openId,
                        'blocked_line'=>stobeDealFinishFirstLine($open)];
                }
                // Already agreed: never open a second deal for the same matter.
                return ['ok'=>true,'decision'=>'ACCEPT','id'=>$openId,'terms'=>$openTerms,'already_active'=>true,'status'=>$openStatus,'kind'=>$kind];
            }
        }
    }
    if ($open !== null) {
        $openId = strval($open['contract_id']);
        $openStatus = strval($open['status']);
        $valid = stobeDealValidate($deal);
        if (!$valid['ok']) return $valid;
        if ($decision !== 'ACCEPT' && intval($open['rounds'] ?? 0) + 1 >= (defined('STOBE_NEG_MAX_COUNTER_ROUNDS') ? STOBE_NEG_MAX_COUNTER_ROUNDS : 6)) {
            stobeDealTransition($openId, $openStatus, 'CANCELLED', ['note'=>'bargaining_exhausted']);
            stobeDealLog('info', 'Negotiation ended: bargaining rounds exhausted', ['contract_id'=>$openId,'npc'=>$npc]);
            return ['ok'=>true,'decision'=>'REJECT','id'=>$openId,'exhausted'=>true];
        }
        $state = $decision === 'ACCEPT' ? 'ACCEPTED' : 'COUNTERED';
        $previous = json_decode(strval($open['terms'] ?? '[]'), true);
        if (!stobeDealReviseTerms($openId, $openStatus, $state, $terms, is_array($previous) ? $previous : [])) {
            return ['ok'=>false,'error'=>'revision_failed','id'=>$openId];
        }
        stobeDealLog('info', 'Negotiation contract revised', ['contract_id'=>$openId,'npc'=>$npc,'decision'=>$decision,'from'=>$openStatus,'status'=>$state]);
        return ['ok'=>true,'decision'=>$decision,'id'=>$openId,'terms'=>$terms,'status'=>$state,'kind'=>$kind];
    }
    if ($decision === 'ACCEPT') try {
        $recent = $GLOBALS['db']->fetchAll(
            "SELECT contract_id, terms FROM stobe_social_contract
              WHERE LOWER(npc_name)=LOWER($1) AND status IN ('COMPLETE','IMPOSSIBLE','BREACHED_PLAYER','BREACHED_NPC')
                AND COALESCE(resolved_at, updated_at) > NOW() - INTERVAL '3 minutes'
              ORDER BY updated_at DESC LIMIT 3",
            [$npc]
        );
        foreach (is_array($recent) ? $recent : [] as $row) {
            $rowTerms = json_decode(strval($row['terms'] ?? '[]'), true);
            if (is_array($rowTerms) && (!stobeDealTermsDiffer($rowTerms, $terms) || stobeDealTermsSubsetOf($terms, $rowTerms))) {
                stobeDealLog('info', 'Negotiation capture ignored: same deal just finished', ['npc'=>$npc, 'contract_id'=>$row['contract_id']]);
                return ['ok'=>true, 'decision'=>'NONE', 'duplicate_of'=>strval($row['contract_id'])];
            }
        }
    } catch (Throwable $e) {
        // fall through and record the deal
    }
    $created = stobeDealCreate($deal);
    if (empty($created['ok'])) return $created;
    $id = strval($created['id']);
    $state = match ($decision) { 'ACCEPT' => 'ACCEPTED', 'COUNTER' => 'COUNTERED', default => 'PROPOSED' };
    if ($state !== 'PROPOSED' && !stobeDealTransition($id,'PROPOSED',$state)) return ['ok'=>false,'error'=>'transition_failed','id'=>$id];
    stobeDealLog('info', 'Negotiation contract recorded', ['contract_id'=>$id,'npc'=>$npc,'decision'=>$decision,'status'=>$state,'kind'=>$kind]);
    return ['ok'=>true,'decision'=>$decision,'id'=>$id,'terms'=>$terms,'status'=>$state,'kind'=>$kind];
}

/**
 * Obligations that depend on an uncertain outcome ("if you lose you give it back")
 * cannot be carried out by the game, so they are recorded as PROMISE terms instead
 * of being executed immediately.
 */
function stobeDealNormalizeConditionalTerms(array $terms, string $npc = '', string $player = ''): array {
    $out = [];
    $side = static function ($value) use ($npc, $player): string {
        $v = strtolower(trim(strval($value)));
        $base = static fn(string $n): string => strtolower(trim(preg_replace('/\s*\[[^\]]*\]\s*/u', ' ', $n) ?? $n));
        if (in_array($v, ['player', 'you', 'the player'], true) || ($player !== '' && $v === $base($player))) return 'player';
        if (in_array($v, ['npc', 'me', 'myself', 'self', 'i'], true) || ($npc !== '' && $v === $base($npc))) return 'npc';
        return strval($value);
    };
    foreach ($terms as $term) {
        // Models often write names ("Shay", "Slant") where the ledger expects player/npc.
        foreach (['by', 'to', 'target'] as $field) {
            if (isset($term[$field])) $term[$field] = $side($term[$field]);
        }
        // Models often leave out "to" on a transfer; it can only go to the other side.
        if (in_array(strval($term['kind'] ?? ''), ['GIVE_CATS','GIVE_ITEM','RETURN_ITEM','LOAN_ITEM'], true)
            && trim(strval($term['to'] ?? '')) === '' && in_array(strval($term['by'] ?? ''), ['npc','player'], true)) {
            $term['to'] = $term['by'] === 'player' ? 'npc' : 'player';
        }
        $condition = trim(strval($term['condition'] ?? ''));
        $when = strtolower(trim(strval($term['when'] ?? '')));
        if ($when !== '' && preg_match('/^(now|immediate(ly)?|up[_ ]?front|first|right[_ ]?(now|away)|at[_ ]once|before|today|on[_ ]the[_ ]spot)$/', $when)) {
            $when = 'now';
            $term['when'] = 'now';
        }
        // "Half now, half later": the player's later installment is due after the NPC delivers.
        if (($term['by'] ?? '') === 'player' && $condition === '' && $when !== '' && !in_array($when, ['now', 'after_npc'], true)
            && !preg_match('/\b(if|unless|lose|lost|win|won|bet|should|in case)\b/', str_replace('_', ' ', $when))) {
            $when = 'after_npc';
            $term['when'] = 'after_npc';
        }
        // "after payment", "once paid", "on delivery" all mean: after the player's side.
        if ($when !== '' && preg_match('/^(after|on|once|upon|when)[_ ]?(the[_ ]?)?(player|payment|paid|pay|delivery|delivered|receipt|received|drink|food|item|cats|money)/', $when)) {
            $when = 'after_player';
            $term['when'] = 'after_player';
        }
        $kind = strval($term['kind'] ?? '');
        if ($kind !== 'PROMISE' && $kind !== 'STOP_ATTACK' && ($condition !== '' || !in_array($when, ['', 'now', 'after_player', 'after_npc'], true))) {
            $what = strtolower(str_replace('_', ' ', $kind));
            if (isset($term['amount'])) $what .= ' ' . intval($term['amount']) . ' Cats';
            if (!empty($term['item'])) $what .= ' ' . strval($term['item']);
            $term = [
                'kind'=>'PROMISE',
                'by'=>strval($term['by'] ?? 'npc'),
                'text'=>trim($what . ' ' . ($condition !== '' ? $condition : $when)),
            ];
        }
        $out[] = $term;
    }
    return $out;
}

/**
 * Terms written in stobeNegTermsSummary's format ("shay pay 1500 Cats; Malzin unequip item
 * Iron Hat [awaiting_player]"). Sides stay as names; stobeDealNormalizeConditionalTerms maps
 * them to npc/player. Returns null if any part doesn't parse.
 */
function stobeDealParseTermsText(string $text): ?array {
    $splitItems = static function (string $list): array {
        $out = [];
        foreach (preg_split('/\s*(?:,|\band\b|&)\s*/i', $list) ?: [] as $item) {
            $item = trim(preg_replace('/^(?:the|her|his|my|your|their)\s+/i', '', trim($item)) ?? $item);
            if ($item !== '') $out[] = $item;
        }
        return $out;
    };
    $terms = [];
    $text = trim(preg_replace('/\s*\[[a-z_ ]+\]/i', '', $text) ?? $text);
    foreach (preg_split('/\s*;\s*|,\s+(?=\S+\s+(?:pay|pays|give|gives|unequip|unequips|take|takes|remove|removes|equip|equips|put|puts|promise|promises|stop|stops)\b)|\.\s+/i', $text) ?: [] as $part) {
        $part = trim(rtrim(trim($part), '.'));
        if ($part === '') continue;
        if (preg_match('/^(.+?)\s+(?:pay|pays|give|gives)\s+(\d+)\s+cats?$/i', $part, $m)) {
            $terms[] = ['kind'=>'GIVE_CATS', 'by'=>trim($m[1]), 'amount'=>intval($m[2])];
        } elseif (preg_match('/^(.+?)\s+(?:unequips?(?:\s+items?)?|takes?\s+off|removes?)\s+(.+)$/i', $part, $m)) {
            foreach ($splitItems($m[2]) as $item) $terms[] = ['kind'=>'UNEQUIP_ITEM', 'by'=>trim($m[1]), 'item'=>$item];
        } elseif (preg_match('/^(.+?)\s+(?:equips?(?:\s+items?)?|puts?\s+on)\s+(.+)$/i', $part, $m)) {
            foreach ($splitItems($m[2]) as $item) $terms[] = ['kind'=>'EQUIP_ITEM', 'by'=>trim($m[1]), 'item'=>$item];
        } elseif (preg_match('/^(.+?)\s+promises?\s*:?\s*(.+)$/i', $part, $m)) {
            $terms[] = ['kind'=>'PROMISE', 'by'=>trim($m[1]), 'text'=>trim($m[2])];
        } elseif (preg_match('/^(.+?)\s+(?:returns?\s+item|returns?)\s+([^,]+)$/i', $part, $m) && !preg_match('/\d+\s*cats?/i', $m[2])) {
            $terms[] = ['kind'=>'RETURN_ITEM', 'by'=>trim($m[1]), 'item'=>trim($m[2])];
        } elseif (preg_match('/^(.+?)\s+(?:gives?\s+item|gives?)\s+([^,]+)$/i', $part, $m) && !preg_match('/\d+\s*cats?/i', $m[2])) {
            $terms[] = ['kind'=>'GIVE_ITEM', 'by'=>trim($m[1]), 'item'=>trim($m[2])];
        } elseif (preg_match('/^(.+?)\s+(?:stops?\s+attack(?:ing)?|stops?\s+fighting)$/i', $part, $m)) {
            $terms[] = ['kind'=>'STOP_ATTACK', 'by'=>trim($m[1]), 'target'=>'player'];
        } elseif (preg_match('/^(.+?)\s+first\s+aid\b(.*)$/i', $part, $m)) {
            $terms[] = ['kind'=>'FIRST_AID', 'by'=>trim($m[1])];
        } else {
            return null; // anything unaccounted for: refuse rather than record half a deal
        }
    }
    return count($terms) > 0 ? $terms : null;
}

/** Same agreement? Compares kinds, sides, amounts, items and quantities. */
function stobeDealTermsDiffer(array $a, array $b): bool {
    $sig = static function (array $terms): array {
        $out = [];
        foreach ($terms as $t) {
            if (!is_array($t)) continue;
            $out[] = strtoupper(strval($t['kind'] ?? '')) . '|' . strval($t['by'] ?? '') . '|' . intval($t['amount'] ?? 0)
                . '|' . strtolower(trim(strval($t['item'] ?? ''))) . '|' . max(1, intval($t['quantity'] ?? 1));
        }
        sort($out);
        return $out;
    };
    return $sig($a) !== $sig($b);
}

/** Every term of $sub also appears in $of (same kind, side, amount, item, quantity). */
function stobeDealTermsSubsetOf(array $sub, array $of): bool {
    $sig = static fn(array $t): string => strtoupper(strval($t['kind'] ?? '')) . '|' . strval($t['by'] ?? '') . '|'
        . intval($t['amount'] ?? 0) . '|' . strtolower(trim(strval($t['item'] ?? ''))) . '|' . max(1, intval($t['quantity'] ?? 1));
    $have = array_map($sig, array_values(array_filter($of, 'is_array')));
    $want = array_map($sig, array_values(array_filter($sub, 'is_array')));
    if (count($want) === 0) return false;
    foreach ($want as $w) {
        if (!in_array($w, $have, true)) return false;
    }
    return true;
}

/** What each side still has to do on a deal, as short phrases in the NPC's voice. */
function stobeDealOutstanding(array $deal): array {
    $state = $deal['term_state'] ?? [];
    if (is_string($state)) $state = json_decode($state, true);
    $done = ['VERIFIED','RECORDED','IMPOSSIBLE','UNMET','BETRAYED'];
    $out = ['npc'=>[], 'player'=>[]];
    foreach (is_array($state) ? $state : [] as $t) {
        if (!is_array($t) || in_array(strval($t['status'] ?? ''), $done, true)) continue;
        $by = ($t['by'] ?? '') === 'npc' ? 'npc' : 'player';
        $kind = strtoupper(strval($t['kind'] ?? ''));
        $item = trim(preg_replace('/\s*\[[^\]]*\]/', '', strval($t['item'] ?? '')) ?? '');
        $the = $item !== '' ? 'the ' . $item : 'it';
        if ($kind === 'GIVE_CATS') {
            $left = max(0, intval($t['amount'] ?? 0) - intval($t['paid_so_far'] ?? 0));
            if ($left > 0) $out[$by][] = ($by === 'npc' ? 'pay you ' : 'pay me ') . $left . ' Cats';
        } elseif ($kind === 'GIVE_ITEM') {
            $out[$by][] = ($by === 'npc' ? 'give you ' : 'give me ') . $the;
        } elseif ($kind === 'RETURN_ITEM') {
            $out[$by][] = 'return ' . $the;
        } elseif ($kind === 'UNEQUIP_ITEM') {
            $out[$by][] = 'take off ' . $the;
        } elseif ($kind === 'EQUIP_ITEM') {
            $out[$by][] = 'put on ' . $the;
        } elseif ($kind === 'FIRST_AID') {
            $out[$by][] = $by === 'npc' ? 'patch you up' : 'patch me up';
        }
    }
    return $out;
}

/** A deal is underway: something was carried out or part-paid, and something is still owed. */
function stobeDealIsUnderway(array $deal): bool {
    if (!in_array(strval($deal['status'] ?? ''), ['ACCEPTED','AWAITING_PERFORMANCE'], true)) return false;
    if (stobeDealNothingPerformedYet($deal)) return false;
    $owed = stobeDealOutstanding($deal);
    return count($owed['npc']) + count($owed['player']) > 0;
}

/** The fixed "finish this first" line, naming what's owed on each side. */
function stobeDealFinishFirstLine(array $deal): string {
    $owed = stobeDealOutstanding($deal);
    $line = "Let's finish our last deal first.";
    if (count($owed['player']) > 0) $line .= ' You still need to ' . implode(' and ', $owed['player']) . '.';
    if (count($owed['npc']) > 0) $line .= ' I still need to ' . implode(' and ', $owed['npc']) . '.';
    return $line;
}

/** Same deal restated with other wording: every new term has a counterpart in the open deal. */
function stobeDealTermsLooselySame(array $new, array $open): bool {
    $base = static fn($s): string => strtolower(trim(preg_replace('/\s*\[[^\]]*\]/', '', strval($s)) ?? ''));
    $any = false;
    foreach ($new as $n) {
        if (!is_array($n)) continue;
        $any = true;
        $found = false;
        foreach ($open as $o) {
            if (!is_array($o)) continue;
            if (strtoupper(strval($n['kind'] ?? '')) !== strtoupper(strval($o['kind'] ?? ''))) continue;
            if (strval($n['by'] ?? '') !== strval($o['by'] ?? '')) continue;
            if (isset($n['amount']) && intval($n['amount']) !== intval($o['amount'] ?? 0)) continue;
            $ni = $base($n['item'] ?? '');
            $oi = $base($o['item'] ?? '');
            if ($ni !== '' && $oi !== '' && !str_contains($ni, $oi) && !str_contains($oi, $ni)) continue;
            $found = true;
            break;
        }
        if (!$found) return false;
    }
    return $any;
}

/** True while no term of this deal has been carried out or sent to the game. */
function stobeDealNothingPerformedYet(array $deal): bool {
    $state = json_decode(strval($deal['term_state'] ?? '[]'), true);
    foreach (is_array($state) ? $state : [] as $t) {
        if (in_array(strval($t['status'] ?? ''), ['VERIFIED','DISPATCHED','SETTLE_QUEUED','REISSUE_QUEUED'], true)) {
            if (strval($t['kind'] ?? '') === 'STOP_ATTACK') continue; // a ceasefire carries over
            return false;
        }
        if (!empty($t['paid_so_far'])) return false;
    }
    return true;
}

/**
 * "Malzin will remove her black cloth shirt" written as a PROMISE becomes a real
 * UNEQUIP_ITEM term for each worn item it names (done after the player delivers
 * when the player owes something). The promise text is kept for anything else.
 */
function stobeDealPromoteClothingPromises(array $terms, array $npcData): array {
    if (!function_exists('stobeNegInventoryCounts')) return $terms;
    $worn = array_keys(stobeNegInventoryCounts(strval($npcData['equipment'] ?? '')));
    if (count($worn) === 0) return $terms;
    $playerOwes = false;
    foreach ($terms as $t) {
        if (($t['by'] ?? '') === 'player' && in_array($t['kind'] ?? '', ['GIVE_CATS','GIVE_ITEM','RETURN_ITEM'], true)) $playerOwes = true;
    }
    $have = [];
    foreach ($terms as $t) {
        if (($t['kind'] ?? '') === 'UNEQUIP_ITEM') $have[strtolower(trim(strval($t['item'] ?? '')))] = true;
    }
    $added = [];
    foreach ($terms as $t) {
        if (($t['kind'] ?? '') !== 'PROMISE' || ($t['by'] ?? '') !== 'npc') continue;
        $text = strtolower(strval($t['text'] ?? ''));
        if (!preg_match('/\b(remove|removes|removing|take off|takes off|taking off|strip|come off|comes off|coming off|unequip)\b/', $text)) continue;
        foreach ($worn as $item) {
            if (strlen($item) < 3 || isset($have[$item]) || !str_contains($text, $item)) continue;
            $have[$item] = true;
            $added[] = ['kind'=>'UNEQUIP_ITEM', 'by'=>'npc', 'item'=>ucwords($item)] + ($playerOwes ? ['when'=>'after_player'] : []);
        }
    }
    return array_merge($terms, $added);
}

/** Speech that commits to stopping the fight right now. Narrow on purpose. */
/** Cats per side from a deal's term_state: total agreed, paid so far, still owed. */
function stobeDealMoneyTotals(array $state): array {
    $out = ['player'=>['total'=>0,'paid'=>0,'owed'=>0], 'npc'=>['total'=>0,'paid'=>0,'owed'=>0]];
    foreach ($state as $t) {
        if (!is_array($t) || strtoupper(strval($t['kind'] ?? '')) !== 'GIVE_CATS') continue;
        $by = ($t['by'] ?? '') === 'npc' ? 'npc' : 'player';
        $amount = max(0, intval($t['amount'] ?? 0));
        $status = strval($t['status'] ?? '');
        if (in_array($status, ['IMPOSSIBLE','UNMET','BETRAYED'], true)) continue;
        $paid = $status === 'VERIFIED' ? $amount : min($amount, max(0, intval($t['paid_so_far'] ?? 0)));
        $out[$by]['total'] += $amount;
        $out[$by]['paid'] += $paid;
        $out[$by]['owed'] += $amount - $paid;
    }
    return $out;
}

/** Prompt line: "Money (exact): Shay agreed to pay 500 Cats in total; paid 200; still owes 300." */
function stobeDealMoneyTotalsLine(array $state, string $player): string {
    $m = stobeDealMoneyTotals($state);
    $parts = [];
    if ($m['player']['total'] > 0) {
        $parts[] = $player . ' agreed to pay ' . $m['player']['total'] . ' Cats in total; paid ' . $m['player']['paid']
            . '; still owes ' . $m['player']['owed'];
    }
    if ($m['npc']['total'] > 0) {
        $parts[] = 'you agreed to pay ' . $m['npc']['total'] . ' Cats in total; paid ' . $m['npc']['paid']
            . '; still owe ' . $m['npc']['owed'];
    }
    if (count($parts) === 0) return '';
    return 'Money (exact; use only these numbers when you talk about amounts): ' . implode('. ', $parts) . '.';
}

/** Cats amounts the NPC says out loud (digits or words, next to cats/now/after/total/pay/owe). */
function stobeDealSpokenCatsAmounts(string $text): array {
    if (function_exists('stobeNegWordsToNumbers')) $text = stobeNegWordsToNumbers($text);
    $text = preg_replace('/(\d),(?=\d{3}\b)/', '$1', $text) ?? $text;
    $found = [];
    $patterns = [
        '/\b(\d+)\s+(?:more\s+|extra\s+)?cats?\b/i',
        '/\b(\d+)\s+(?:now|up\s*front|after(?:wards)?|later|in\s+total|total)\b/i',
        '/\b(?:total(?:\s+of)?|now|after(?:wards)?|up\s*front|later|pay(?:s|ing)?|paid|owes?|owed|another|rest(?:\s+of)?)\s+(?:(?:is|of|me|you|the|just|only|another|still)\s+){0,2}(\d+)\b/i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $text, $m)) {
            foreach ($m[1] as $n) {
                $n = intval($n);
                if ($n > 1) $found[$n] = true;
            }
        }
    }
    return array_keys($found);
}

/** Every Cats figure the recorded deal supports: each amount, sums per side and per timing, paid and owed. */
function stobeDealAllowedCatsAmounts(array $terms, array $state = [], int $purse = -1): array {
    $allowed = [];
    $sums = [];
    foreach ($terms as $t) {
        if (!is_array($t) || strtoupper(strval($t['kind'] ?? '')) !== 'GIVE_CATS') continue;
        $a = max(0, intval($t['amount'] ?? 0));
        $by = ($t['by'] ?? '') === 'npc' ? 'npc' : 'player';
        $when = strval($t['when'] ?? '') === 'after_player' ? 'after' : 'now';
        $allowed[$a] = true;
        $sums[$by] = ($sums[$by] ?? 0) + $a;
        $sums[$by . $when] = ($sums[$by . $when] ?? 0) + $a;
    }
    foreach ($sums as $s) $allowed[$s] = true;
    foreach ($state as $t) {
        if (!is_array($t) || strtoupper(strval($t['kind'] ?? '')) !== 'GIVE_CATS') continue;
        $a = max(0, intval($t['amount'] ?? 0));
        $p = max(0, intval($t['paid_so_far'] ?? 0));
        $allowed[$a] = true;
        $allowed[$p] = true;
        $allowed[max(0, $a - $p)] = true;
    }
    if (count($state) > 0) {
        foreach (stobeDealMoneyTotals($state) as $side) {
            foreach ($side as $v) $allowed[$v] = true;
        }
    }
    if ($purse >= 0) $allowed[$purse] = true;
    return $allowed;
}

/** One recorded term, from the NPC's side: "you pay me 300 Cats now", "I stop fighting". */
function stobeDealTermPhrase(array $t): string {
    $npcSide = ($t['by'] ?? '') === 'npc';
    $who = $npcSide ? 'I' : 'you';
    $kind = strtoupper(strval($t['kind'] ?? ''));
    $item = trim(preg_replace('/\s*\[[^\]]*\]/', '', strval($t['item'] ?? '')) ?? '');
    $qty = intval($t['quantity'] ?? 0);
    $thing = $item === '' ? 'it' : ($qty > 1 ? $qty . ' ' . $item : 'the ' . $item);
    $when = strval($t['when'] ?? '');
    $whenText = $when === 'now' ? ' now' : ($when !== '' ? ' after' : '');
    switch ($kind) {
        case 'GIVE_CATS':
            return $who . ($npcSide ? ' pay you ' : ' pay me ') . intval($t['amount'] ?? 0) . ' Cats' . $whenText;
        case 'GIVE_ITEM':
            return $who . ($npcSide ? ' give you ' : ' give me ') . $thing . $whenText;
        case 'RETURN_ITEM':
            return $who . ' return ' . $thing . $whenText;
        case 'UNEQUIP_ITEM':
            return $who . ' take off ' . $thing;
        case 'EQUIP_ITEM':
            return $who . ' put on ' . $thing;
        case 'STOP_ATTACK':
            return $who . ' stop fighting';
        case 'FIRST_AID':
            return $npcSide ? 'I patch you up' : 'you patch me up';
        case 'PROMISE':
            $text = trim(strval($t['text'] ?? ($t['subject'] ?? '')));
            return $who . ' promise' . ($text !== '' ? ' to ' . preg_replace('/^to\s+/i', '', $text) : '');
    }
    return $who . ' ' . strtolower(str_replace('_', ' ', $kind)) . ($item !== '' ? ' ' . $thing : '');
}

/** Plain statement of the recorded terms, used when her words quote different amounts. */
function stobeDealPlainTermsLine(array $terms, string $decision): string {
    $parts = [];
    // Her ceasefire goes last: "you pay me 300 Cats now and I stop fighting".
    usort($terms, static fn($a, $b) => (strtoupper(strval($a['kind'] ?? '')) === 'STOP_ATTACK') <=> (strtoupper(strval($b['kind'] ?? '')) === 'STOP_ATTACK'));
    foreach ($terms as $t) {
        if (is_array($t) && isset($t['kind'])) $parts[] = stobeDealTermPhrase($t);
    }
    if (count($parts) === 0) return '';
    $last = array_pop($parts);
    $body = count($parts) > 0 ? implode(', ', $parts) . ' and ' . $last : $last;
    return match ($decision) {
        'ACCEPT' => 'Deal: ' . $body . '.',
        'PROPOSE' => "Here's my offer: " . $body . '. Deal?',
        default => 'My terms: ' . $body . '. Deal?',
    };
}

/**
 * Bug 26: amounts in her speech must match the recorded deal. Returns null when they do
 * (or nothing is said about Cats), else ['line'=>plain terms, 'spoken'=>[...]].
 */
/** A sentence talks about money (used to hold the stream back during an underway deal). */
function stobeDealSpeechMentionsMoney(string $text): bool {
    if (count(stobeDealSpokenCatsAmounts($text)) > 0) return true;
    return preg_match("/\b(cats?|owe[sd]?|owing|paid|the rest|how much)\b/i", $text) === 1;
}

/** NPC's purse, or -1 when unknown. */
function stobeDealNpcPurse(string $npc): int {
    try {
        if (function_exists('stobeNegMoney') && function_exists('stobeNegNpcRow')) {
            $money = stobeNegMoney(stobeNegNpcRow($npc));
            if (!empty($money['known'])) return intval($money['value']);
        }
    } catch (Throwable $e) {
    }
    return -1;
}

/** "You've paid me 350 of the 500 Cats; you still owe me 150." from the recorded deal. */
function stobeDealProgressLine(array $terms, array $state): string {
    if (count($state) === 0) $state = $terms; // nothing observed yet: all still owed
    $m = stobeDealMoneyTotals($state);
    $out = [];
    $p = $m['player'];
    if ($p['total'] > 0) {
        $out[] = $p['owed'] <= 0 ? "You've paid me all " . $p['total'] . ' Cats.'
            : ($p['paid'] > 0 ? "You've paid me " . $p['paid'] . ' of the ' . $p['total'] . ' Cats; you still owe me ' . $p['owed'] . '.'
                : 'You still owe me ' . $p['owed'] . ' Cats.');
    }
    $n = $m['npc'];
    if ($n['total'] > 0) {
        $out[] = $n['owed'] <= 0 ? "I've paid you all " . $n['total'] . ' Cats.'
            : ($n['paid'] > 0 ? "I've paid you " . $n['paid'] . ' of the ' . $n['total'] . ' Cats; I still owe you ' . $n['owed'] . '.'
                : 'I still owe you ' . $n['owed'] . ' Cats.');
    }
    return implode(' ', $out);
}

/**
 * Bug 26 for a deal that's underway: amounts she says about it must be its recorded
 * amounts or paid/owed figures. Returns null when they are, else the corrected line.
 */
function stobeDealProgressAmountCheck(string $text, string $npc, string $playerMessage = ''): ?array {
    $spoken = stobeDealSpokenCatsAmounts($text);
    if (count($spoken) === 0) return null;
    $open = stobeDealOpenForNpc($npc);
    if ($open === null) return null;
    $terms = json_decode(strval($open['terms'] ?? '[]'), true);
    $terms = is_array($terms) ? array_values(array_filter($terms, 'is_array')) : [];
    $state = function_exists('stobeNegDecode') ? stobeNegDecode($open['term_state'] ?? []) : [];
    $state = is_array($state) ? $state : [];
    $allowed = stobeDealAllowedCatsAmounts($terms, $state, stobeDealNpcPurse($npc));
    // Bug 35: echoing the player's new offer is not a wrong amount.
    foreach (stobeDealSpokenCatsAmounts($playerMessage) as $said) $allowed[$said] = true;
    $wrong = array_values(array_filter($spoken, static fn($n) => !isset($allowed[$n])));
    if (count($wrong) === 0) return null;
    // Drop only the sentences with a wrong amount; the progress line only if nothing is left.
    $kept = [];
    foreach (preg_split('/(?<=[.!?])\s+/', trim($text)) ?: [] as $sentence) {
        $bad = array_intersect(stobeDealSpokenCatsAmounts($sentence), $wrong);
        if (count($bad) === 0 && trim($sentence) !== '') $kept[] = trim($sentence);
    }
    $line = count($kept) > 0 ? implode(' ', $kept) : stobeDealProgressLine($terms, $state);
    if ($line === '') return null;
    return ['line'=>$line, 'spoken'=>$spoken, 'wrong'=>$wrong, 'allowed'=>array_keys($allowed)];
}

function stobeDealSpeechAmountCheck(string $text, string $npc, array $dealResult): ?array {
    $decision = strtoupper(strval($dealResult['decision'] ?? ''));
    if (!in_array($decision, ['ACCEPT','COUNTER','PROPOSE'], true)) return null;
    $terms = is_array($dealResult['terms'] ?? null) ? $dealResult['terms'] : [];
    if (count($terms) === 0) return null;
    $spoken = stobeDealSpokenCatsAmounts($text);
    if (count($spoken) === 0) return null;
    $state = [];
    try {
        $open = stobeDealOpenForNpc($npc);
        if ($open !== null && function_exists('stobeNegDecode')) $state = stobeNegDecode($open['term_state'] ?? []);
    } catch (Throwable $e) {
        $state = [];
    }
    $allowed = stobeDealAllowedCatsAmounts($terms, is_array($state) ? $state : [], stobeDealNpcPurse($npc));
    $wrong = array_values(array_filter($spoken, static fn($n) => !isset($allowed[$n])));
    if (count($wrong) === 0) return null;
    $line = stobeDealPlainTermsLine($terms, $decision);
    if ($line === '') return null;
    return ['line'=>$line, 'spoken'=>$spoken, 'wrong'=>$wrong, 'allowed'=>array_keys($allowed)];
}

// ---- Bug 31: "take it off" is UNEQUIP, not a hand-over ----------------------------------

/** The player asked for something to be taken off, and not handed over. */
function stobeDealPlayerAsksTakeOffOnly(string $playerMessage): bool {
    $m = strtolower($playerMessage);
    if (!preg_match("/\b(take (it|them|that|those|your [a-z' -]{1,40}) off|take off|remove|unequip|strip)\b/", $m)) return false;
    return !preg_match("/\b(hand|give|pass|toss|throw)\b[^.?!]{0,40}\b(me|over)\b|\bto me\b|\bmine\b|\bi get\b/", $m);
}

/** Her GIVE_ITEM of something she's wearing becomes UNEQUIP_ITEM when the player only asked her to take it off. */
function stobeDealFixTakeOffTerms(array $terms, array|false $npcData, string $playerMessage): array {
    if (!is_array($npcData) || trim($playerMessage) === '' || !stobeDealPlayerAsksTakeOffOnly($playerMessage)) return $terms;
    if (!function_exists('stobeNegInventoryDisplayNames')) return $terms;
    $worn = stobeNegInventoryDisplayNames(strval($npcData['equipment'] ?? ''));
    $said = strtolower($playerMessage);
    foreach ($terms as $i => $t) {
        if (!is_array($t) || ($t['by'] ?? '') !== 'npc' || strtoupper(strval($t['kind'] ?? '')) !== 'GIVE_ITEM') continue;
        $item = strtolower(trim(preg_replace('/\s*\[[^\]]*\]|\s*\([^)]*\)/', '', strval($t['item'] ?? '')) ?? ''));
        if ($item === '') continue;
        $isWorn = false;
        foreach ($worn as $lower => $display) {
            if ($item === $lower || str_contains($lower, $item) || str_contains($item, $lower)) { $isWorn = true; break; }
        }
        // It must be the item the player named ("your Black Rag Shirt", "the shirt").
        $named = false;
        foreach (preg_split('/\s+/', $item) ?: [] as $word) {
            if (strlen($word) >= 4 && str_contains($said, $word)) { $named = true; break; }
        }
        if (!$isWorn || !$named) continue;
        $terms[$i]['kind'] = 'UNEQUIP_ITEM';
        unset($terms[$i]['to']);
        stobeDealLog('info', 'Negotiation term fixed: take-off request recorded as UNEQUIP_ITEM, not GIVE_ITEM', ['item'=>$t['item'] ?? '']);
    }
    return $terms;
}

// ---- Bug 30: agreed in words, nothing recorded -------------------------------------------

/** Her line agrees to the offer on the table. */
function stobeDealSpeechAgrees(string $text): bool {
    $t = strtolower($text);
    if (preg_match("/\b(no deal|not a deal|that'?s not a deal|not happening|forget it|no way|not for|stays on|stays where|i refuse)\b/", $t)) return false;
    return preg_match("/\b(fine|deal|agreed|done|you'?ve got (a|yourself a) deal|it'?s a deal|you'?re on|sounds fair|alright,? then|all right,? then)\b/", $t) === 1;
}

function stobeDealRememberUnrecordedAgreement(string $npc, string $playerMessage, string $reply, string $decision = 'ACCEPT'): void {
    setConfOpt('STOBE_NEG_UNRECORDED_' . strtolower($npc), json_encode(['offer'=>$playerMessage, 'reply'=>$reply, 'at'=>time(), 'decision'=>$decision], JSON_UNESCAPED_UNICODE));
    stobeDealLog('warn', 'Negotiation: NPC agreed in words but recorded no deal (reminder queued for next turn)', ['npc'=>$npc, 'offer'=>$playerMessage, 'reply'=>$reply]);
}

/** One-shot note for the next negotiation turn, or ''. */
function stobeDealTakeUnrecordedAgreement(string $npc, string $player): string {
    $key = 'STOBE_NEG_UNRECORDED_' . strtolower($npc);
    $raw = getConfOpt($key, '');
    if ($raw === '') return '';
    setConfOpt($key, '');
    $row = json_decode($raw, true);
    if (!is_array($row) || time() - intval($row['at'] ?? 0) > 180) return '';
    if (stobeDealOpenForNpc($npc) !== null) return '';
    $decision = strtoupper(strval($row['decision'] ?? 'ACCEPT'));
    if (in_array($decision, ['COUNTER','PROPOSE'], true)) {
        // Bug 37: she made an offer but gave no deal_terms.
        return 'In your last reply you made ' . ($decision === 'COUNTER' ? 'a counter-offer' : 'an offer') . ' to ' . $player
            . ' (you said: "' . strval($row['reply'] ?? '') . '") but gave no deal_terms, so nothing was recorded. '
            . 'If it still stands and they have not accepted it yet, set deal_decision to ' . $decision . ' and list its terms in deal_terms now.';
    }
    return 'In your last reply you agreed in words to this offer from ' . $player . ': "' . strval($row['offer'] ?? '')
        . '" (you said: "' . strval($row['reply'] ?? '') . '"). It was not recorded as a deal. If you still agree, '
        . 'set deal_decision to ACCEPT and list its terms in deal_terms now.';
}

// ---- Bug 32: an NPC's own weapon ----------------------------------------------------

/** Kenshi weapon names (equipped items only are checked, so food like "chewstick" never shows up). */
function stobeDealIsWeaponName(string $name): bool {
    return preg_match("/\b(katana|sabre|saber|sword|longsword|nodachi|wakizashi|machete|cleaver|blade|knife|dagger|jitte|club|mace|hammer|axe|plank|stick|tooth ?pick|polearm|naginata|halberd|spear|staff|horse chopper|falling sun|paladin'?s cross|topknot|bow|crossbow|eagle'?s cross|harpoon|weapon)\b/i", $name) === 1;
}

/** Weapons the NPC has equipped: [lower name => display name]. */
function stobeDealEquippedWeapons(array|false $npcData): array {
    if (!is_array($npcData) || !function_exists('stobeNegInventoryDisplayNames')) return [];
    $out = [];
    foreach (stobeNegInventoryDisplayNames(strval($npcData['equipment'] ?? '')) as $lower => $display) {
        if (stobeDealIsWeaponName($lower)) $out[$lower] = $display;
    }
    return $out;
}

/** The NPC's affinity toward the player, or 0 if they have no relationship. */
function stobeDealNpcTrust(array|false $npcData, string $player): int {
    if (!is_array($npcData) || !function_exists('stobeGetNpcRelationshipMap')) return 0;
    foreach (stobeGetNpcRelationshipMap($npcData) as $target => $entry) {
        if (strcasecmp(normalizeParticipantNameToken(strval($target)), normalizeParticipantNameToken($player)) === 0) {
            return intval(is_array($entry) ? ($entry['aff'] ?? 0) : 0);
        }
    }
    return 0;
}

/** May she give up the weapon she's using? Only when surrendering, or trusting the player. */
function stobeDealWeaponReleaseAllowed(array|false $npcData, string $player, string $kind): bool {
    if (!is_array($npcData)) return true;
    if (function_exists('npcIsInPlayerFaction') && npcIsInPlayerFaction($npcData)) return true;
    if ($kind === 'surrender') return true;
    $minTrust = function_exists('getSettingInt') ? getSettingInt('NEG_WEAPON_TRUST_MIN', 56) : 56;
    return stobeDealNpcTrust($npcData, $player) >= $minTrust;
}

/** The equipped weapon an item name refers to ("Chisa Katana [Ancient]", "katana", "your blade"), or ''. */
function stobeDealMatchEquippedWeapon(string $item, array $weapons): string {
    $t = strtolower(trim(preg_replace('/\s*\[[^\]]*\]|\s*\([^)]*\)/', '', $item) ?? ''));
    if ($t === '' || count($weapons) === 0) return '';
    foreach ($weapons as $lower => $display) {
        if ($t === $lower || str_contains($lower, $t) || str_contains($t, $lower)) return $display;
    }
    // A generic word ("weapon", "blade", "sword") means the one she's holding.
    if (preg_match('/\b(weapon|blade|sword)\b/', $t)) return reset($weapons);
    return '';
}

/** Name of an equipped weapon the NPC would give up under these terms, or ''. */
function stobeDealTermsGiveUpWeapon(array $terms, array|false $npcData): string {
    $weapons = stobeDealEquippedWeapons($npcData);
    if (count($weapons) === 0) return '';
    foreach ($terms as $t) {
        if (!is_array($t) || ($t['by'] ?? '') !== 'npc') continue;
        if (!in_array(strtoupper(strval($t['kind'] ?? '')), ['UNEQUIP_ITEM','GIVE_ITEM','LOAN_ITEM'], true)) continue;
        $hit = stobeDealMatchEquippedWeapon(strval($t['item'] ?? ''), $weapons);
        if ($hit !== '') return $hit;
    }
    return '';
}

/** Remove UNEQUIP_ITEM/GIVE_ITEM actions on her equipped weapon unless allowed. Returns [actions, removed]. */
function stobeDealFilterWeaponActions(array $actions, array|false $npcData, string $player, string $kind): array {
    $weapons = stobeDealEquippedWeapons($npcData);
    if (count($weapons) === 0 || stobeDealWeaponReleaseAllowed($npcData, $player, $kind)) return [$actions, []];
    $kept = [];
    $removed = [];
    foreach ($actions as $action) {
        $a = strval($action);
        $item = '';
        if (preg_match('/^UNEQUIP_ITEM@(.+)$/i', $a, $m)) $item = $m[1];
        elseif (preg_match('/^GIVE_ITEM@[^@]*@([^@]+)/i', $a, $m)) $item = $m[1];
        if ($item !== '' && stobeDealMatchEquippedWeapon($item, $weapons) !== '') { $removed[] = $a; continue; }
        $kept[] = $action;
    }
    return [$kept, $removed];
}

/** Prompt line when the rule applies to her, else ''. */
function stobeDealWeaponPromptLine(array|false $npcData, string $player, string $kind): string {
    $weapons = stobeDealEquippedWeapons($npcData);
    if (count($weapons) === 0 || stobeDealWeaponReleaseAllowed($npcData, $player, $kind)) return '';
    return 'In Kenshi your weapon is your life. Your ' . implode(' and ', array_values($weapons))
        . ' is not for sale: never sell, hand over, lend, put away or drop it for Cats or favours, whatever the price. '
        . $player . ' is not someone you trust that much. Refuse such offers, and never list it in deal_terms.';
}

function stobeDealSpeechClaimsCeasefire(string $text): bool {
    return preg_match("/\b(i'?ll|i will|we'?ll)\s+(stop|back off|stand down|give you room|lower (my|the) \w+|put (it|this|my \w+) away)\b|\b(we'?re square|you'?ve got a deal|deal'?s a deal|it'?s a deal)\b/i", $text) === 1;
}
