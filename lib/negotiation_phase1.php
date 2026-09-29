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
    if (stobeNpcIsInCombat($npcData)) return stobeDealLooksNegotiableMessage($message);
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
    return (is_array($npcData) && stobeNpcIsInCombat($npcData)) ? 'combat' : 'social';
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
        'description'=>'Your decision about an actual offer in this conversation (PROPOSE when you make the first offer); NONE if no negotiation.'
    ];
    $schema['properties']['deal_terms'] = [
        'type'=>'string',
        'description'=>'JSON array of concrete terms for ACCEPT, COUNTER or PROPOSE; empty string otherwise. Each term has kind, by, and target or to/amount/item/quantity/text/subject, plus optional when (now|after_player). Allowed kinds: ' . stobeDealTermKindsText() . '. by/to/target use npc or player. Never list a term that is physically impossible or invent an item.'
    ];
    $schema['required'][] = 'deal_decision';
    $schema['required'][] = 'deal_terms';
    return $format;
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
    $terms = json_decode(strval($response['deal_terms'] ?? ''), true);
    if ((!is_array($terms) || count($terms) === 0) && $decision === 'ACCEPT' && $open !== null) {
        $terms = json_decode(strval($open['terms'] ?? '[]'), true); // "deal" = the terms on the table
    }
    if (!is_array($terms)) return ['ok'=>false,'error'=>'invalid_terms_json'];
    $terms = stobeDealNormalizeConditionalTerms(array_values(array_filter($terms, 'is_array')), $npc, $player);
    $terms = stobeDealPromoteClothingPromises($terms, $npcData);
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
            if (is_array($rowTerms) && !stobeDealTermsDiffer($rowTerms, $terms)) {
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
function stobeDealSpeechClaimsCeasefire(string $text): bool {
    return preg_match("/\b(i'?ll|i will|we'?ll)\s+(stop|back off|stand down|give you room|lower (my|the) \w+|put (it|this|my \w+) away)\b|\b(we'?re square|you'?ve got a deal|deal'?s a deal|it'?s a deal)\b/i", $text) === 1;
}
