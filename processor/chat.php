<?php

/**
 * Chat processor - handles player dialogue input.
 */

if (!function_exists('stobeNormalizeManualChatActionKey')) {
    function stobeNormalizeManualChatActionKey(string $rawAction): string
    {
        $normalized = strtolower(trim($rawAction));
        $allowed = [
            'remove_limb_left_arm',
            'remove_limb_right_arm',
            'remove_limb_left_leg',
            'remove_limb_right_leg',
            'cut_horns',
            'knockout',
            'kill',
        ];
        if (!in_array($normalized, $allowed, true)) {
            return '';
        }
        return $normalized;
    }
}

if (!function_exists('stobeManualChatActionType')) {
    function stobeManualChatActionType(string $actionKey): string
    {
        $normalized = strtolower(trim($actionKey));
        if ($normalized === 'knockout') {
            return 'knockout';
        }
        if ($normalized === 'kill') {
            return 'kill';
        }
        if ($normalized === 'cut_horns') {
            return 'cut_horns';
        }
        if (strpos($normalized, 'remove_limb_') === 0) {
            return 'remove_limb';
        }
        return '';
    }
}

if (!function_exists('stobeManualChatActionLimbToken')) {
    function stobeManualChatActionLimbToken(string $actionKey): string
    {
        return match (strtolower(trim($actionKey))) {
            'remove_limb_left_arm' => 'LEFT_ARM',
            'remove_limb_right_arm' => 'RIGHT_ARM',
            'remove_limb_left_leg' => 'LEFT_LEG',
            'remove_limb_right_leg' => 'RIGHT_LEG',
            default => '',
        };
    }
}

if (!function_exists('stobeManualChatActionLimbLabel')) {
    function stobeManualChatActionLimbLabel(string $actionKey): string
    {
        return match (strtolower(trim($actionKey))) {
            'remove_limb_left_arm' => 'left arm',
            'remove_limb_right_arm' => 'right arm',
            'remove_limb_left_leg' => 'left leg',
            'remove_limb_right_leg' => 'right leg',
            default => 'limb',
        };
    }
}

if (!function_exists('stobeManualActionTargetCannotSpeak')) {
    function stobeManualActionTargetCannotSpeak(array $npcData, string $actionKey = ''): bool
    {
        $actionType = stobeManualChatActionType($actionKey);
        if ($actionType === 'kill' || $actionType === 'knockout') {
            return true;
        }

        $cannotSpeakStates = ['dead', 'unconscious', 'ko', 'knockedout', 'knocked_out', 'incapacitated', 'passed_out', 'blackout'];

        $characterState = strtolower(trim(strval($npcData['character_state'] ?? '')));
        if (in_array($characterState, $cannotSpeakStates, true)) {
            return true;
        }

        $metadataRaw = $npcData['metadata'] ?? [];
        $metadata = [];
        if (is_array($metadataRaw)) {
            $metadata = $metadataRaw;
        } elseif (is_string($metadataRaw) && trim($metadataRaw) !== '') {
            $decoded = json_decode($metadataRaw, true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }
        if (is_array($metadata)) {
            $metaState = strtolower(trim(strval($metadata['character_state'] ?? '')));
            if (in_array($metaState, $cannotSpeakStates, true)) {
                return true;
            }
            $metaMedical = $metadata['medical'] ?? null;
            if (is_array($metaMedical) && (!empty($metaMedical['is_unconscious']) || !empty($metaMedical['is_knocked_out']) || !empty($metaMedical['is_knockedout']))) {
                return true;
            }
        }

        $extendedRaw = $npcData['extended_data'] ?? [];
        $extended = [];
        if (is_array($extendedRaw)) {
            $extended = $extendedRaw;
        } elseif (is_string($extendedRaw) && trim($extendedRaw) !== '') {
            $decoded = json_decode($extendedRaw, true);
            if (is_array($decoded)) {
                $extended = $decoded;
            }
        }
        if (is_array($extended)) {
            $extMedical = $extended['medical'] ?? null;
            if (is_array($extMedical) && (!empty($extMedical['is_unconscious']) || !empty($extMedical['is_knocked_out']) || !empty($extMedical['is_knockedout']))) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('stobeBuildManualActionPainFallback')) {
    function stobeBuildManualActionPainFallback(
        string $targetNpc,
        string $actorName,
        string $actionKey
    ): string {
        $safeTarget = trim($targetNpc) !== '' ? trim($targetNpc) : 'The target';
        $safeActor = trim($actorName) !== '' ? trim($actorName) : 'the attacker';
        $actionType = stobeManualChatActionType($actionKey);
        if ($actionType === 'kill' || $actionType === 'knockout') {
            // For manual kill/knockout, avoid emitting a second world notification.
            // The plugin execution feedback is the single source of truth.
            return '';
        }
        $limbLabel = stobeManualChatActionLimbLabel($actionKey);
        $notice = $safeTarget . ' convulses in overwhelming pain as '
            . $safeActor . ' saws into their ' . $limbLabel . '.';
        return 'ROLEPLAY_ACTION@' . $notice;
    }
}

if (!function_exists('stobeTraderInventoryEntryCountFromNpcData')) {
    function stobeTraderInventoryEntryCountFromNpcData(array $npcData): int
    {
        $metadataRaw = $npcData['metadata'] ?? [];
        $metadata = [];
        if (is_array($metadataRaw)) {
            $metadata = $metadataRaw;
        } elseif (is_string($metadataRaw) && trim($metadataRaw) !== '') {
            $decoded = json_decode($metadataRaw, true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        $entriesRaw = $metadata['trader_inventory_items'] ?? [];
        $entries = [];
        if (is_array($entriesRaw)) {
            $entries = $entriesRaw;
        } elseif (is_string($entriesRaw) && trim($entriesRaw) !== '') {
            $decodedEntries = json_decode($entriesRaw, true);
            if (is_array($decodedEntries)) {
                $entries = $decodedEntries;
            }
        }

        $count = 0;
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = trim(strval($entry['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $count++;
        }
        if ($count > 0) {
            return $count;
        }

        $shopSourcesRaw = $metadata['trader_shop_sources'] ?? [];
        $shopSources = [];
        if (is_array($shopSourcesRaw)) {
            $shopSources = $shopSourcesRaw;
        } elseif (is_string($shopSourcesRaw) && trim($shopSourcesRaw) !== '') {
            $decodedShopSources = json_decode($shopSourcesRaw, true);
            if (is_array($decodedShopSources)) {
                $shopSources = $decodedShopSources;
            }
        }

        if (count($shopSources) > 0) {
            return max(1, intval($metadata['trader_shop_item_count'] ?? 0));
        }

        return 0;
    }
}

if (!function_exists('stobeNpcLikelyTraderFromData')) {
    function stobeNpcLikelyTraderFromData(array $npcData): bool
    {
        $metadataRaw = $npcData['metadata'] ?? [];
        $metadata = [];
        if (is_array($metadataRaw)) {
            $metadata = $metadataRaw;
        } elseif (is_string($metadataRaw) && trim($metadataRaw) !== '') {
            $decoded = json_decode($metadataRaw, true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        $isTraderRaw = $metadata['is_trader'] ?? ($npcData['is_trader'] ?? false);
        if (is_bool($isTraderRaw)) {
            return $isTraderRaw;
        }
        if (is_int($isTraderRaw) || is_float($isTraderRaw)) {
            return intval($isTraderRaw) !== 0;
        }
        if (is_string($isTraderRaw)) {
            $normalized = strtolower(trim($isTraderRaw));
            return in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true);
        }
        return false;
    }
}

if (!function_exists('stobeMessageLooksTradeIntent')) {
    function stobeMessageLooksTradeIntent(string $message): bool
    {
        $text = strtolower(trim($message));
        if ($text === '') {
            return false;
        }
        $keywords = [
            'trade',
            'trading',
            'business',
            'shop',
            'buy',
            'sell',
            'for sale',
            'cats',
            'price',
            'cost',
            'merchant',
            'vendor',
        ];
        foreach ($keywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('stobeRefreshNpcDataForTraderInventory')) {
    function stobeRefreshNpcDataForTraderInventory(string $targetNpc, array $npcData, string $message): array
    {
        $initialCount = stobeTraderInventoryEntryCountFromNpcData($npcData);
        if ($initialCount > 0) {
            return $npcData;
        }

        $likelyTrader = stobeNpcLikelyTraderFromData($npcData);
        $tradeIntent = stobeMessageLooksTradeIntent($message);
        // Round 12: the DLL only captures trader inventory for real traders, so waiting
        // on a non-trader (e.g. "here are 300 cats" to Malzin) always timed out (~806 ms).
        if (!$likelyTrader) {
            return $npcData;
        }

        $maxAttempts = 8;
        $sleepMs = 100;
        $latest = $npcData;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            usleep($sleepMs * 1000);
            $refreshed = getNpcData($targetNpc);
            if (!is_array($refreshed)) {
                continue;
            }
            $latest = $refreshed;
            $count = stobeTraderInventoryEntryCountFromNpcData($refreshed);
            if ($count > 0) {
                stobeLogInfo('Chat trader metadata hydration succeeded', [
                    'target_npc' => $targetNpc,
                    'attempt' => $attempt,
                    'entry_count' => $count,
                    'trade_intent' => $tradeIntent,
                    'is_trader' => $likelyTrader,
                ]);
                return $refreshed;
            }
        }

        stobeLogDebug('Chat trader metadata hydration timed out', [
            'target_npc' => $targetNpc,
            'trade_intent' => $tradeIntent,
            'is_trader' => $likelyTrader,
            'entry_count' => stobeTraderInventoryEntryCountFromNpcData($latest),
        ]);
        return $latest;
    }
}

// $eventData, $eventType, $timestamp, $gamets are set by main.php.
$parts = explode(": ", $eventData, 2);
$speaker = $parts[0] ?? getSetting('PLAYER_NAME', 'Drifter');
$playerName = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
$message = $parts[1] ?? $eventData;
$message = trim($message);
$targetExtract = extractDialogueTarget($message);
$cleanedMessage = trim(strval($targetExtract['cleaned'] ?? ''));
if ($cleanedMessage !== '') {
    $message = $cleanedMessage;
}
$sanitizeChatMessage = static function (string $value): string {
    $clean = sanitizeForKenshi(trim($value));
    if ($clean !== '' && function_exists('stobeSanitizeDialogueMessageForLog')) {
        $clean = stobeSanitizeDialogueMessageForLog($clean);
    }
    return trim($clean);
};
$stobeEventStoreStageStartedAt = microtime(true);
$message = $sanitizeChatMessage($message);
$GLOBALS['STOBE_CURRENT_PLAYER_MESSAGE'] = $message;
if ($message === '') {
    stobeLogWarn('Chat input rejected: empty message after sanitize', [
        'event_type' => $eventType,
        'speaker' => $speaker,
        'gamets' => intval($gamets),
        'data_preview' => substr($eventData, 0, 180),
    ]);
    echo "ok";
    return;
}

$messagePreview = $message;
if (strlen($messagePreview) > 180) {
    $messagePreview = substr($messagePreview, 0, 180) . '...';
}

$dialogueModeRaw = $_GET["mode"] ?? '';
$dialogueMode = strtolower(trim((string)$dialogueModeRaw));
$allowedDialogueModes = ['talk', 'whisper', 'shout', 'autochat', 'cheat', 'narrator', 'inject', 'inject_chat'];
if (!in_array($dialogueMode, $allowedDialogueModes, true)) {
    $dialogueMode = 'talk';
}

/*
 * Local voice modifier bridge:
 *   U       -> normal talk
 *   Shift+U -> action request: prefer a real gameplay action; if the NPC accepts but no real action exists, use RoleplayAction
 *   Ctrl+U  -> cheat/action instruction, allowing the model to use real game actions.
 * KenshiFP creates a one-shot marker at PTT key-down. The marker is consumed
 * only after transcription arrives, and expires quickly if capture is abandoned.
 */
$voiceRoleplayMarker = '/mnt/d/Steam/steamapps/common/Kenshi/RE_Kenshi/mods/Stobe/voice_action.flag';
$voiceCommandMarker = '/mnt/d/Steam/steamapps/common/Kenshi/RE_Kenshi/mods/Stobe/voice_command.flag';
$voiceMarkerMaxAge = 90;
$voiceMarkerMode = '';
$voiceMarkerAge = -1;

foreach ([
    'cheat' => $voiceCommandMarker,
    'inject_chat' => $voiceRoleplayMarker,
] as $candidateMode => $markerPath) {
    if ($dialogueMode !== 'talk' || !is_file($markerPath)) {
        continue;
    }
    $markerMtime = @filemtime($markerPath);
    $fresh = is_int($markerMtime) && (time() - $markerMtime) <= $voiceMarkerMaxAge;
    @unlink($markerPath);
    if ($fresh) {
        $voiceMarkerMode = $candidateMode;
        $voiceMarkerAge = max(0, time() - $markerMtime);
        $dialogueMode = $candidateMode;
        break;
    }
    stobeLogInfo('Voice modifier marker discarded as stale', [
        'mode' => $candidateMode,
        'marker_mtime' => is_int($markerMtime) ? $markerMtime : 0,
    ]);
}

/* Never let an abandoned marker leak into the next utterance. */
if ($voiceMarkerMode !== '') {
    @unlink($voiceRoleplayMarker);
    @unlink($voiceCommandMarker);
    stobeLogInfo('Voice modifier applied', [
        'mode' => $voiceMarkerMode,
        'speaker' => $speaker,
        'requested_profile' => normalizeParticipantNameToken(strval($_GET['profile'] ?? '')),
        'marker_age_seconds' => $voiceMarkerAge,
    ]);
}
$injectionMode = ($dialogueMode === 'inject' || $dialogueMode === 'inject_chat');
$injectionChatMode = ($dialogueMode === 'inject_chat');

if (!function_exists('stobeParseNearbyRosterNamesFromEventData')) {
    function stobeParseNearbyRosterNamesFromEventData(string $eventData): array
    {
        $text = trim($eventData);
        if ($text === '') {
            return [];
        }

        if (preg_match('/nearby\s+NPC\s+roster\s*\([0-9]+\)\s*:\s*(.+)$/iu', $text, $matches) !== 1) {
            return [];
        }

        $rosterText = trim(strval($matches[1] ?? ''));
        if ($rosterText === '') {
            return [];
        }

        $parts = explode(',', $rosterText);
        $names = [];
        $seen = [];
        foreach ($parts as $part) {
            if (function_exists('stobeRosterSplitState')) {
                $part = stobeRosterSplitState(strval($part))[0];
            }
            $name = normalizeParticipantNameToken(strval($part));
            $name = trim(str_replace('...', '', $name));
            if ($name === '') {
                continue;
            }
            $key = strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $names[] = $name;
            if (count($names) >= 24) {
                break;
            }
        }
        return $names;
    }
}

if (!function_exists('stobeFetchNearbyActorsFromInfonpcRoster')) {
    function stobeFetchNearbyActorsFromInfonpcRoster(string $speakerName): array
    {
        $safeSpeaker = normalizeParticipantNameToken($speakerName);
        if ($safeSpeaker === '') {
            return [];
        }

        $db = $GLOBALS['db'] ?? null;
        if (!$db) {
            return [];
        }

        $row = $db->fetchOne(
            "SELECT data
             FROM eventlog
             WHERE type = 'infonpc'
               AND data ILIKE $1
             ORDER BY rowid DESC
             LIMIT 1",
            [$safeSpeaker . ': nearby NPC roster%']
        );
        if (!is_array($row)) {
            return [];
        }

        $names = stobeParseNearbyRosterNamesFromEventData(strval($row['data'] ?? ''));
        if (count($names) === 0) {
            return [];
        }

        $actors = [];
        foreach ($names as $name) {
            $rosterState = function_exists('stobeRosterState') ? stobeRosterState($name) : '';
            $actors[] = $rosterState !== ''
                ? ['name' => $name, 'current_action' => $rosterState, 'is_' . $rosterState => true]
                : ['name' => $name];
        }
        return $actors;
    }
}
$narratorMode = ($dialogueMode === 'narrator');
$narratorName = stobeNarratorName();
if ($narratorMode && !stobeNarratorModeEnabled()) {
    stobeLogWarn('Chat input rejected: narrator mode disabled', [
        'event_type' => $eventType,
        'speaker' => $speaker,
        'gamets' => intval($gamets),
    ]);
    streamResponse($narratorName, 'ScriptQueue', 'Narrator mode is disabled in server settings.');
    echo "ok";
    return;
}
$manualActionKey = stobeNormalizeManualChatActionKey(strval($_GET['manual_action'] ?? ''));
$manualActionActor = normalizeParticipantNameToken(strval($_GET['manual_action_actor'] ?? ''));
$manualActionTarget = normalizeParticipantNameToken(strval($_GET['manual_action_target'] ?? ''));
$manualActionActorSid = trim(strval($_GET['manual_action_actor_sid'] ?? ''));
$manualActionTargetSid = trim(strval($_GET['manual_action_target_sid'] ?? ''));
if ($manualActionActor === '') {
    $manualActionActor = normalizeParticipantNameToken(strval($speaker));
}
if ($manualActionActor === '') {
    $manualActionActor = trim(strval($speaker));
}
$manualActionActive = ($manualActionKey !== '');

$targetNpc = normalizeParticipantNameToken(strval($_GET["profile"] ?? ''));
$extractedTargetNpc = normalizeParticipantNameToken(strval($targetExtract['target'] ?? ''));
if ($targetNpc === '' && $extractedTargetNpc !== '') {
    $targetNpc = $extractedTargetNpc;
}

/*
 * Fast deterministic addressee routing. If the player starts a message with the
 * name of a currently-known participant ("Wendy, how are you?"), route this turn
 * to that NPC instead of whichever character happened to be nearest when PTT
 * started. This is string matching only: no extra LLM call and effectively no
 * added latency.
 */
if (!$narratorMode && !$injectionMode) {
    $routePeopleRaw = strval($GLOBALS['CACHE_PEOPLE'] ?? ($_GET['people'] ?? ''));
    $routeIdentities = extractParticipantIdentities([
        'people' => $routePeopleRaw,
        'profile' => $targetNpc,
        'speaker' => $speaker,
    ]);
    // Also use the latest in-game nearby-roster telemetry. This keeps voice
    // addressing useful when the initially selected/locked target is not the
    // person whose name the player actually says, without searching the whole
    // NPC database or making an extra LLM call.
    if (function_exists('stobeFetchNearbyActorsFromInfonpcRoster')) {
        foreach (stobeFetchNearbyActorsFromInfonpcRoster(strval($speaker)) as $nearbyActor) {
            if (!is_array($nearbyActor)) {
                continue;
            }
            $nearbyName = normalizeParticipantNameToken(strval($nearbyActor['name'] ?? ''));
            if ($nearbyName !== '') {
                $routeIdentities[] = ['name' => $nearbyName, 'storage_id' => ''];
            }
        }
    }
    $routeMatches = [];
    foreach ($routeIdentities as $routeIdentity) {
        if (!is_array($routeIdentity)) {
            continue;
        }
        $candidateName = normalizeParticipantNameToken(strval($routeIdentity['name'] ?? ''));
        if ($candidateName === '' || strcasecmp($candidateName, $speaker) === 0) {
            continue;
        }
        $aliases = [$candidateName];
        $bracketPos = strpos($candidateName, ' [');
        if ($bracketPos !== false) {
            $shortName = trim(substr($candidateName, 0, $bracketPos));
            if ($shortName !== '') {
                $aliases[] = $shortName;
            }
        }
        foreach (array_unique($aliases) as $alias) {
            $routeMatches[] = ['alias' => $alias, 'name' => $candidateName];
        }
    }
    usort($routeMatches, static fn(array $a, array $b): int => strlen($b['alias']) <=> strlen($a['alias']));
    foreach ($routeMatches as $routeMatch) {
        $alias = trim(strval($routeMatch['alias'] ?? ''));
        if ($alias === '') {
            continue;
        }
        $quotedAlias = preg_quote($alias, '/');
        // Prefer unmistakable direct-address shapes. This supports
        // "Wendy...", "Hey Wendy...", "Hey, Wendy..." and a trailing
        // vocative such as "how are you, Wendy?" without treating an arbitrary
        // mid-sentence mention as a retarget command.
        $startPattern = '/^\s*(?:(?:hey|hi|yo)\s*[,.:;-]?\s*)?' . $quotedAlias . '(?=\s|[,.:;!?-]|$)/iu';
        $endPattern = '/[,;:]\s*' . $quotedAlias . '\s*[.!?]*\s*$/iu';
        if (preg_match($startPattern, $message) === 1 || preg_match($endPattern, $message) === 1) {
            $namedTarget = normalizeParticipantNameToken(strval($routeMatch['name'] ?? ''));
            if ($namedTarget !== '' && strcasecmp($namedTarget, $targetNpc) !== 0) {
                stobeLogInfo('Chat addressee routed by spoken name', [
                    'original_target_npc' => $targetNpc,
                    'resolved_target_npc' => $namedTarget,
                    'matched_alias' => $alias,
                ]);
                $targetNpc = $namedTarget;
            }
            break;
        }
    }
}
if (!$narratorMode && function_exists('stobeNegPartnerForUnnamedLine')) {
    $negPartner = stobeNegPartnerForUnnamedLine(
        $targetNpc, $message, strval($GLOBALS['CACHE_PEOPLE'] ?? ($_GET['people'] ?? ''))
    );
    if ($negPartner !== '') {
        stobeLogInfo('Chat addressee routed to negotiation partner', [
            'original_target_npc' => $targetNpc,
            'resolved_target_npc' => $negPartner,
        ]);
        $targetNpc = $negPartner;
    }
}
if ($narratorMode) {
    $targetNpc = $narratorName;
}
if ($targetNpc === '') {
    stobeLogWarn('Chat input rejected: missing target NPC', [
        'event_type' => $eventType,
        'speaker' => $speaker,
        'gamets' => intval($gamets),
        'data_preview' => substr($eventData, 0, 180),
    ]);
    echo "ok";
    return;
}

$speakerProfileName = normalizeParticipantNameToken(strval($speaker));
if (!$narratorMode && !$injectionMode && $speakerProfileName !== '' && function_exists('stobeNpcCannotRespondInDirectChat')) {
    $speakerNpcData = getNpcData($speakerProfileName);
    if (is_array($speakerNpcData) && stobeNpcCannotRespondInDirectChat($speakerNpcData)) {
        $speakerState = function_exists('stobeResolveNpcAwarenessState')
            ? stobeResolveNpcAwarenessState($speakerNpcData)
            : strtolower(trim(strval($speakerNpcData['character_state'] ?? '')));
        stobeLogInfo('Chat input rejected: speaker cannot speak in current state', [
            'event_type' => $eventType,
            'speaker' => $speakerProfileName,
            'target_npc' => $targetNpc,
            'state' => $speakerState,
            'mode' => $dialogueMode,
            'gamets' => intval($gamets),
        ]);
        echo "ok";
        return;
    }
}

if ($manualActionTarget === '') {
    $manualActionTarget = $targetNpc;
}
if (($narratorMode || $injectionMode) && $manualActionActive) {
    stobeLogWarn('Manual action ignored: selected mode does not support manual actions', [
        'manual_action' => $manualActionKey,
        'speaker' => $speaker,
        'mode' => $dialogueMode,
    ]);
    $manualActionKey = '';
    $manualActionActive = false;
}

stobeLogInfo('Chat input received', [
    'event_type' => $eventType,
    'speaker' => $speaker,
    'target_npc' => $targetNpc,
    'unix_ms' => intval(round(microtime(true) * 1000)),
    'request_elapsed_ms' => isset($GLOBALS['__stobe_request_start']) && is_float($GLOBALS['__stobe_request_start'])
        ? intval(round((microtime(true) - $GLOBALS['__stobe_request_start']) * 1000))
        : 0,
    'message_sha1' => sha1(trim(strval($message ?? ''))),
    'mode' => $dialogueMode,
    'manual_action' => $manualActionActive ? $manualActionKey : '',
    'manual_action_actor' => $manualActionActor,
    'manual_action_target' => $manualActionTarget,
    'manual_action_actor_sid' => $manualActionActorSid,
    'manual_action_target_sid' => $manualActionTargetSid,
    'gamets' => intval($gamets),
    'message_preview' => $messagePreview,
]);

$stobeProfileStageStartedAt = microtime(true);
$npcData = false;
if ($narratorMode) {
    $npcData = stobeBuildNarratorNpcData();
    $targetNpc = $narratorName;
} else {
    $npcData = getNpcData($targetNpc);
    if (!$npcData) {
        storeNpcProfile($targetNpc, []);
        $npcData = getNpcData($targetNpc);
        stobeLogInfo('NPC profile JIT-created', ['target_npc' => $targetNpc]);
    } elseif (npcNeedsBootstrap($npcData)) {
        // Backfill older sparse rows created before profile defaults were added.
        storeNpcProfile($targetNpc, []);
        $npcData = getNpcData($targetNpc) ?: $npcData;
        stobeLogInfo('NPC profile baseline refreshed', ['target_npc' => $targetNpc]);
    }

    if (!$npcData) {
        $npcData = [
            'name' => $targetNpc,
            'race' => 'Unknown',
            'faction' => '',
            'gender' => '',
        ];
    }

    $canonicalTargetNpc = normalizeParticipantNameToken(strval($npcData['name'] ?? ''));
    if ($canonicalTargetNpc !== '' && strcasecmp($canonicalTargetNpc, $targetNpc) !== 0) {
        stobeLogInfo('Chat target remapped to canonical NPC name', [
            'requested_target_npc' => $targetNpc,
            'resolved_target_npc' => $canonicalTargetNpc,
        ]);
        $targetNpc = $canonicalTargetNpc;
    }
}
if ($manualActionActive) {
    if ($manualActionTarget === '') {
        $manualActionTarget = $targetNpc;
    }
    if ($manualActionTarget !== '' && strcasecmp($manualActionTarget, $targetNpc) !== 0) {
        stobeLogWarn('Manual action ignored: target mismatch', [
            'manual_action' => $manualActionKey,
            'manual_action_target' => $manualActionTarget,
            'chat_target_npc' => $targetNpc,
        ]);
        $manualActionKey = '';
        $manualActionActive = false;
    }
}
stobeLogInfo('Latency pre-llm stage npc_profile', [
    'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
    'duration_ms' => intval(round((microtime(true) - $stobeProfileStageStartedAt) * 1000)),
    'unix_ms' => intval(round(microtime(true) * 1000)),
]);

$manualActionLimbToken = stobeManualChatActionLimbToken($manualActionKey);
$manualActionLimbLabel = stobeManualChatActionLimbLabel($manualActionKey);
$manualActionType = stobeManualChatActionType($manualActionKey);

$formatInjectedEventData = static function (string $eventSpeaker, string $eventTarget, string $eventMessage): string {
    $eventText = trim($eventMessage);
    if (!(str_starts_with($eventText, '(') && str_ends_with($eventText, ')'))) {
        $eventText = '(' . $eventText . ')';
    }
    return $eventSpeaker . ': ' . $eventText . ' (talking to: ' . $eventTarget . ')';
};
$formatShiftUActionRequestData = static function (string $eventSpeaker, string $eventTarget, string $eventMessage): string {
    $eventText = trim($eventMessage);
    return $eventSpeaker . ': [Shift+U action request to ' . $eventTarget . '] ' . $eventText
        . ' (requested/attempted action; outcome not yet established)';
};

if ($injectionMode && !$injectionChatMode) {
    $message = $sanitizeChatMessage($message);
    $eventData = $formatInjectedEventData($speaker, $targetNpc, $message);
    storeEvent('injection', $timestamp, $gamets, $eventData);
    stobeLogInfo('Injection event stored without response', [
        'speaker' => $speaker,
        'target_npc' => $targetNpc,
        'gamets' => intval($gamets),
        'message_length' => strlen($message),
    ]);
    echo "ok";
    return;
}

$stobeTraderStageStartedAt = microtime(true);
$npcData = stobeRefreshNpcDataForTraderInventory($targetNpc, is_array($npcData) ? $npcData : [], $message);
$traderInventoryEntryCount = stobeTraderInventoryEntryCountFromNpcData($npcData);
if ($traderInventoryEntryCount > 0 || stobeMessageLooksTradeIntent($message)) {
    stobeLogInfo('Chat prompt trader inventory context', [
        'target_npc' => $targetNpc,
        'speaker' => $speaker,
        'entry_count' => $traderInventoryEntryCount,
        'is_trader' => stobeNpcLikelyTraderFromData($npcData),
    ]);
}

stobeLogInfo('Latency pre-llm stage trader_refresh', [
    'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
    'duration_ms' => intval(round((microtime(true) - $stobeTraderStageStartedAt) * 1000)),
    'unix_ms' => intval(round(microtime(true) * 1000)),
]);

$contextHistory = getNpcProfileIntegerSetting(
    $npcData,
    ['CONTEXT_HISTORY'],
    '',
    50,
    10,
    250
);
$historyAliases = $narratorMode
    ? []
    : stobeResolveNpcEventHistoryAliases($npcData, $targetNpc);
$stobeEventHistoryStageStartedAt = microtime(true);
$eventHistory = $narratorMode
    ? DataEventLog($contextHistory)
    : DataEventLog($contextHistory, $targetNpc, '', $historyAliases);
stobeLogInfo('Latency pre-llm stage event_history_query', [
    'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
    'duration_ms' => intval(round((microtime(true) - $stobeEventHistoryStageStartedAt) * 1000)),
    'rows' => is_array($eventHistory) ? count($eventHistory) : 0,
    'unix_ms' => intval(round(microtime(true) * 1000)),
]);
$stobeHistoryFormatStageStartedAt = microtime(true);
$eventHistory = stobeFilterNarratorRowsForContext($eventHistory, $targetNpc, $dialogueMode, $speaker);
if (!$narratorMode && is_array($npcData)) {
    $npcData = stobeAttachRecentCombatPromptEvents($npcData, $eventHistory, intval($gamets));
}
$historyLines = [];
foreach (array_reverse($eventHistory) as $row) {
    $line = stobeFormatEventHistoryLine($row, true);
    if ($line !== '') {
        $historyLines[] = $line;
    }
}
$historyText = implode("\n", $historyLines);
$historyMessages = stobeBuildRecentContextMessages(
    $eventHistory,
    intval($gamets),
    64,
    $narratorMode ? '' : $targetNpc,
    true
);
stobeLogInfo('Latency pre-llm stage recent_history_build', [
    'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
    'duration_ms' => intval(round((microtime(true) - $stobeHistoryFormatStageStartedAt) * 1000)),
    'history_messages' => count($historyMessages),
    'unix_ms' => intval(round(microtime(true) * 1000)),
]);

$stobeRegularMemoryStageStartedAt = microtime(true);
$memoryContextMessages = stobeBuildMemoryEventContextMessages(
    is_array($npcData) ? $npcData : [],
    $targetNpc,
    $message,
    intval($gamets)
);
stobeLogInfo('Latency pre-llm stage regular_memory_context', [
    'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
    'duration_ms' => intval(round((microtime(true) - $stobeRegularMemoryStageStartedAt) * 1000)),
    'memory_messages' => count($memoryContextMessages),
    'unix_ms' => intval(round(microtime(true) * 1000)),
]);

$enginePath = $GLOBALS["ENGINE_PATH"] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($enginePath . 'connector/llm_dispatcher.php');

$autochatRewriteApplied = false;
if ($dialogueMode === 'autochat') {
    $rewriteResult = rewriteSpeakerMessageForAutochat(
        $speaker,
        $targetNpc,
        $npcData,
        $message,
        $historyText
    );
    if (boolval($rewriteResult['rewritten'] ?? false)) {
        $rewrittenMessage = $sanitizeChatMessage(trim(strval($rewriteResult['message'] ?? $message)));
        if ($rewrittenMessage !== '') {
            $message = $rewrittenMessage;
        }
        $autochatRewriteApplied = true;
        stobeLogInfo('Autochat rewrite generated', [
            'speaker' => $speaker,
            'target_npc' => $targetNpc,
            'model' => strval($rewriteResult['model'] ?? ''),
            'rewritten_length' => strlen($message),
        ]);
    } else {
        stobeLogInfo('Autochat rewrite fallback', [
            'speaker' => $speaker,
            'target_npc' => $targetNpc,
            'model' => strval($rewriteResult['model'] ?? ''),
            'reason' => strval($rewriteResult['error'] ?? 'unknown'),
        ]);
    }
} elseif ($dialogueMode === 'cheat') {
    stobeLogInfo('Cheat mode active for chat request', [
        'speaker' => $speaker,
        'target_npc' => $targetNpc,
        'mode' => $dialogueMode,
        'message_length' => strlen($message),
    ]);
}

$message = $sanitizeChatMessage($message);
$playerMoodCue = stobeResolvePlayerMoodCue($_GET, $dialogueMode, $speaker);
$eventData = $injectionChatMode
    ? $formatShiftUActionRequestData($speaker, $targetNpc, $message)
    : ($injectionMode
        ? $formatInjectedEventData($speaker, $targetNpc, $message)
        : $speaker . ': ' . $message . $playerMoodCue . ' (talking to: ' . $targetNpc . ')');
storeEvent($injectionMode ? 'injection' : $eventType, $timestamp, $gamets, $eventData);
if (!$narratorMode && !$injectionMode) {
    // Mirror player input as chat immediately so timeline order is stable even
    // when game-emitted chat events arrive later.
    storeEvent('chat', intval($timestamp) + 1, $gamets, $eventData, 'inputtext_chat_mirror');
}

if (
    !$narratorMode &&
    is_array($npcData) &&
    function_exists('stobeNpcCannotRespondInDirectChat') &&
    stobeNpcCannotRespondInDirectChat($npcData)
) {
    $stateLabel = function_exists('stobeResolveNpcAwarenessState')
        ? stobeResolveNpcAwarenessState($npcData)
        : strtolower(trim(strval($npcData['character_state'] ?? '')));
    stobeLogInfo('Direct chat skipped: target cannot speak in current state', [
        'target_npc' => $targetNpc,
        'speaker' => $speaker,
        'state' => $stateLabel,
        'event_type' => $eventType,
        'gamets' => intval($gamets),
    ]);
    echo "ok";
    return;
}

if ($dialogueMode === 'autochat' && trim($message) !== '') {
    stobeLogInfo('Autochat streaming rewritten speaker line', [
        'speaker' => $speaker,
        'target_npc' => $targetNpc,
        'rewritten' => $autochatRewriteApplied,
        'message_length' => strlen($message),
    ]);
    // Emit the player's autochat line before NPC generation so audio order is natural.
    streamResponse($speaker, 'ScriptQueue', $message, false, [], 'chat', $targetNpc, $gamets);
}

if (!$narratorMode && !$injectionMode) {
    stobeTryTriggerRandomNarration(
        intval($gamets),
        $speaker,
        $message,
        $targetNpc,
        'chat',
        $playerName,
        intval($timestamp)
    );
}

stobeLogInfo('Latency pre-llm stage event_store_and_misc', [
    'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
    'duration_ms' => intval(round((microtime(true) - $stobeEventStoreStageStartedAt) * 1000)),
    'unix_ms' => intval(round(microtime(true) * 1000)),
]);

$manualActionCannotSpeak = $manualActionActive
    ? stobeManualActionTargetCannotSpeak($npcData, $manualActionKey)
    : false;

$stobePromptHydrationStageStartedAt = microtime(true);
$promptNpcData = $npcData;
if (
    !$narratorMode &&
    $speakerProfileName !== '' &&
    is_array($promptNpcData)
) {
    $targetExtended = normalizeNpcExtendedDataPayload($promptNpcData['extended_data'] ?? []);
    $targetNearbyActors = stobeExtractSceneArray($targetExtended, 'nearby_actors');
    if (count($targetNearbyActors) === 0) {
        $targetNearbyActors = stobeExtractSceneArray($targetExtended, 'nearby');
    }

    if (count($targetNearbyActors) === 0) {
        $speakerPromptData = getNpcData($speakerProfileName);
        if (is_array($speakerPromptData) && count($speakerPromptData) > 0) {
            $speakerExtended = normalizeNpcExtendedDataPayload($speakerPromptData['extended_data'] ?? []);
            $speakerNearbyActors = stobeExtractSceneArray($speakerExtended, 'nearby_actors');
            if (count($speakerNearbyActors) === 0) {
                $speakerNearbyActors = stobeExtractSceneArray($speakerExtended, 'nearby');
            }
            if (count($speakerNearbyActors) > 0) {
                $targetExtended['nearby_actors'] = $speakerNearbyActors;
                $targetExtended['nearby'] = $speakerNearbyActors;
                $promptNpcData['extended_data'] = $targetExtended;
                $targetNearbyActors = $speakerNearbyActors;
                stobeLogDebug('Chat prompt nearby actors hydrated from speaker snapshot', [
                    'target_npc' => $targetNpc,
                    'speaker' => $speakerProfileName,
                    'count' => count($speakerNearbyActors),
                ]);
            }
        }
    }

    if (count($targetNearbyActors) === 0) {
        $rosterNearbyActors = stobeFetchNearbyActorsFromInfonpcRoster($speakerProfileName);
        if (count($rosterNearbyActors) > 0) {
            $targetExtended['nearby_actors'] = $rosterNearbyActors;
            $targetExtended['nearby'] = $rosterNearbyActors;
            $promptNpcData['extended_data'] = $targetExtended;
            $targetNearbyActors = $rosterNearbyActors;
            stobeLogDebug('Chat prompt nearby actors hydrated from latest infonpc roster', [
                'target_npc' => $targetNpc,
                'speaker' => $speakerProfileName,
                'count' => count($rosterNearbyActors),
            ]);
        }
    }
}

stobeLogInfo('Latency pre-llm stage scene_hydration', [
    'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
    'duration_ms' => intval(round((microtime(true) - $stobePromptHydrationStageStartedAt) * 1000)),
    'unix_ms' => intval(round(microtime(true) * 1000)),
]);

$stobeSystemPromptStageStartedAt = microtime(true);
$systemPrompt = buildSystemPrompt(
    $targetNpc,
    $promptNpcData,
    $speaker,
    $message,
    !$narratorMode,
    'chat',
    intval($gamets)
);

// Cache-friendly normal-chat contract: these blocks are unchanged across ordinary
// repeated turns with the same NPC. Put them before volatile Current Situation data
// so provider prefix caching can reuse them. Special chat modes keep the old path.
$cacheableDialogueContractEmbedded = false;
if (!$narratorMode && !$injectionChatMode && $dialogueMode !== 'cheat') {
    $cacheableTurnGuidance = stobeBuildTurnGuidanceUserPrompt(
        $targetNpc,
        $speaker,
        false,
        false,
        '',
        $speaker
    );
    $cacheableOutputContract = stobeBuildOutputContractUserPrompt(
        $targetNpc,
        false,
        false,
        npcIsInPlayerFaction($npcData),
        'chat',
        $speaker,
        $npcData
    );
    $cacheableContractBlock = "# Dialogue Contract\n\n"
        . $cacheableTurnGuidance
        . "\n\n"
        . $cacheableOutputContract;

    foreach (["\n\n# Current Situation", "\n\n<current_situation>"] as $cacheMarker) {
        $cacheMarkerPos = strpos($systemPrompt, $cacheMarker);
        if ($cacheMarkerPos !== false) {
            $systemPrompt = substr_replace(
                $systemPrompt,
                "\n\n" . $cacheableContractBlock,
                $cacheMarkerPos,
                0
            );
            $cacheableDialogueContractEmbedded = true;
            break;
        }
    }
}
$gameTimePrompt = stobeBuildGameTimePromptBlock($gamets, $npcData);
if ($gameTimePrompt !== '') {
    // Keep volatile time data after the stable character prompt so provider-side
    // prefix caches can reuse the unchanged head across consecutive turns.
    $systemPrompt .= "\n\n" . $gameTimePrompt;
}
$nearbyPartyPrompt = stobeBuildNearbyPlayerFactionPartyPrompt($npcData, $targetNpc);
if ($nearbyPartyPrompt !== '') {
    $systemPrompt .= "\n\n" . $nearbyPartyPrompt;
}
stobeLogInfo('Latency pre-llm stage system_prompt_and_scene', [
    'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
    'duration_ms' => intval(round((microtime(true) - $stobeSystemPromptStageStartedAt) * 1000)),
    'system_prompt_chars' => strlen($systemPrompt),
    'unix_ms' => intval(round(microtime(true) * 1000)),
]);

$deliveryStyleInstruction = '';
if ($dialogueMode === 'whisper') {
    $deliveryStyleInstruction = 'The player is whispering. Respond in a quiet, discreet tone.';
} elseif ($dialogueMode === 'shout') {
    $deliveryStyleInstruction = 'The player is shouting. Respond with urgency and stronger emotional intensity.';
} elseif ($dialogueMode === 'autochat') {
    $deliveryStyleInstruction = 'The player triggered a bored-event automatic chat. Keep responses brief and natural for overheard conversation.';
} elseif ($dialogueMode === 'narrator') {
    $deliveryStyleInstruction = 'You are ' . stobeNarratorRoleplayName() . ' in a private one-on-one conversation. Reply directly to the speaker as conversation. Never narrate scenes, atmosphere, or actions in this mode. Never emit action tags.';
} elseif ($injectionChatMode) {
    $deliveryStyleInstruction = 'Shift+U is an action request directed at this NPC, not an established event. Decide in character whether the NPC would comply. If the NPC complies and a matching real gameplay action exists in the available action list, emit that real action. If the NPC complies but no matching real gameplay action exists, emit RoleplayAction describing what the NPC actually does. If the NPC refuses, use Talk only and do not emit the requested action or a RoleplayAction claiming it happened.';
}
if ($deliveryStyleInstruction !== '') {
    $systemPrompt .= "\n\n<speech_mode>\n"
        . "  <mode>" . stobePromptXmlEscape($dialogueMode) . "</mode>\n"
        . "  <instruction>" . stobePromptXmlEscape($deliveryStyleInstruction) . "</instruction>\n"
        . "</speech_mode>";
}
if ($manualActionActive) {
    if ($manualActionType === 'knockout') {
        $manualInstruction = 'Manual knockout is happening now. The target is knocked out immediately and cannot speak. Do not invent coherent spoken dialogue for the target.';
    } elseif ($manualActionType === 'kill') {
        $manualInstruction = 'Manual execution is happening now. The target is killed immediately and cannot speak. Do not invent coherent spoken dialogue for the target.';
    } elseif ($manualActionType === 'cut_horns') {
        $manualInstruction = $manualActionCannotSpeak
            ? 'Manual horn cutting is happening now, and the target cannot speak. Do not invent coherent spoken dialogue for the target.'
            : 'Manual horn cutting is happening now. The target should react with immediate extreme pain, humiliation, shock, and desperation as their horns are sawn off.';
    } else {
        $manualInstruction = $manualActionCannotSpeak
            ? 'Manual limb removal is happening now, and the target cannot speak. Do not invent coherent spoken dialogue for the target.'
            : 'Manual limb removal is happening now. The target should react with immediate extreme pain, shock, and desperation.';
    }
    $systemPrompt .= "\n\n<manual_action_context>\n"
        . "  <type>" . stobePromptXmlEscape($manualActionType !== '' ? $manualActionType : 'manual_action') . "</type>\n"
        . "  <action_key>" . stobePromptXmlEscape($manualActionKey) . "</action_key>\n"
        . "  <actor>" . stobePromptXmlEscape($manualActionActor) . "</actor>\n"
        . "  <target>" . stobePromptXmlEscape($targetNpc) . "</target>\n"
        . "  <target_can_speak>" . ($manualActionCannotSpeak ? 'false' : 'true') . "</target_can_speak>\n"
        . "  <instruction>" . stobePromptXmlEscape($manualInstruction) . "</instruction>\n";
    if ($manualActionType === 'remove_limb') {
        $systemPrompt .= "  <limb_token>" . stobePromptXmlEscape($manualActionLimbToken) . "</limb_token>\n"
            . "  <limb_label>" . stobePromptXmlEscape($manualActionLimbLabel) . "</limb_label>\n";
    } elseif ($manualActionType === 'cut_horns') {
        $systemPrompt .= "  <body_part>horns</body_part>\n";
    }
    $systemPrompt .= "</manual_action_context>";
}
$userContent = $injectionChatMode
    ? "<shift_u_action_request>\n"
        . "  <source>player-authored action request</source>\n"
        . "  <requester>" . stobePromptXmlEscape($speaker) . "</requester>\n"
        . "  <target>" . stobePromptXmlEscape($targetNpc) . "</target>\n"
        . "  <request>" . stobePromptXmlEscape($message) . "</request>\n"
        . "  <instruction>Decide whether the target NPC would comply. If yes, use a matching real gameplay action first when one exists. If no matching real gameplay action exists, use RoleplayAction to represent the action in roleplay. If the NPC refuses, use Talk only. Do not treat the requested action as already completed.</instruction>\n"
        . "</shift_u_action_request>"
    : stobeBuildPlayerInputPromptContent($speaker, $targetNpc, $message . $playerMoodCue);
if ($manualActionActive) {
    $userContent .= "\n<manual_action_event>\n"
        . "  <type>" . stobePromptXmlEscape($manualActionType !== '' ? $manualActionType : 'manual_action') . "</type>\n"
        . "  <actor>" . stobePromptXmlEscape($manualActionActor) . "</actor>\n"
        . "  <target>" . stobePromptXmlEscape($targetNpc) . "</target>\n"
        . "  <target_can_speak>" . ($manualActionCannotSpeak ? 'false' : 'true') . "</target_can_speak>\n";
    if ($manualActionType === 'remove_limb') {
        $userContent .= "  <limb>" . stobePromptXmlEscape($manualActionLimbLabel) . "</limb>\n";
    } elseif ($manualActionType === 'cut_horns') {
        $userContent .= "  <body_part>horns</body_part>\n";
    }
    $userContent .= "</manual_action_event>";
}
if ($dialogueMode === 'cheat') {
    $priorityInstruction = "PRIORITY INSTRUCTION - {$targetNpc} must do this, even if it breaks character roleplay: {$message}";
    $systemPrompt .= "\n\n<cheatmode>\n"
        . "  <priority_instruction>" . stobePromptXmlEscape($priorityInstruction) . "</priority_instruction>\n"
        . "</cheatmode>";
    $userContent = "<cheat_request>\n"
        . "  <speaker>" . stobePromptXmlEscape($speaker) . "</speaker>\n"
        . "  <target>" . stobePromptXmlEscape($targetNpc) . "</target>\n"
        . "  <request>" . stobePromptXmlEscape($message) . "</request>\n"
        . "</cheat_request>";
}
$compactHistoryEnabled = stobeShouldCompactChatHistory($targetNpc);
$stobeShortTermStageStartedAt = microtime(true);
$shortTermMemory = stobeBuildShortTermMemoryContext(
    $npcData,
    $targetNpc,
    $historyMessages,
    intval($gamets),
    $compactHistoryEnabled,
    $systemPrompt
);
if ($shortTermMemory !== '') {
    $systemPrompt .= "\n\n" . $shortTermMemory;
}
stobeLogInfo('Latency pre-llm stage short_term_memory', [
    'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
    'duration_ms' => intval(round((microtime(true) - $stobeShortTermStageStartedAt) * 1000)),
    'included' => $shortTermMemory !== '',
    'unix_ms' => intval(round(microtime(true) * 1000)),
]);

$stobeCompactHistoryStageStartedAt = microtime(true);
$compactHistory = stobeApplyCompactChatHistory(
    $systemPrompt,
    $historyMessages,
    $targetNpc,
    $compactHistoryEnabled,
    getSettingBool('PROMPT_HEAD_MARKDOWN_ENABLED', true)
);
$systemPrompt = strval($compactHistory['system_prompt'] ?? $systemPrompt);
$historyMessages = is_array($compactHistory['history_messages'] ?? null)
    ? $compactHistory['history_messages']
    : $historyMessages;
stobeLogInfo('Latency pre-llm stage compact_history', [
    'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
    'duration_ms' => intval(round((microtime(true) - $stobeCompactHistoryStageStartedAt) * 1000)),
    'history_messages' => count($historyMessages),
    'unix_ms' => intval(round(microtime(true) * 1000)),
]);

$stobeMessageAssemblyStageStartedAt = microtime(true);
$messages = [
    [
        'role' => 'system',
        'content' => $systemPrompt,
    ],
];
foreach ($historyMessages as $historyMessage) {
    $messages[] = $historyMessage;
}
foreach ($memoryContextMessages as $memoryContextMessage) {
    $messages[] = $memoryContextMessage;
}

// Normal chat embeds these blocks in the stable system prefix. Special modes retain
// the original user-message guidance because their instructions are turn-specific.
if (!$cacheableDialogueContractEmbedded) {
    $messages[] = [
        'role' => 'user',
        'content' => $narratorMode
            ? stobeBuildNarratorDirectReplyGuidanceUserPrompt($speaker, $message)
            : ($injectionChatMode
                ? 'Resolve the Shift+U action request from the target NPC perspective. Preserve NPC agency: refusal is allowed. On compliance, prefer a real supported gameplay action; only use RoleplayAction when no matching real gameplay action exists. Never claim the requested action happened unless the chosen action represents it.'
                : stobeBuildTurnGuidanceUserPrompt(
                $targetNpc,
                $speaker,
                false,
                $dialogueMode === 'cheat',
                $dialogueMode === 'cheat' ? $message : '',
                $speaker
            )),
    ];
    $messages[] = [
        'role' => 'user',
        'content' => $narratorMode
            ? 'Output contract: return only a direct conversational reply to the current speaker. Do not include scene narration, atmospheric description, third-person prose, or action tags.'
            : stobeBuildOutputContractUserPrompt(
                $targetNpc,
                $dialogueMode === 'cheat',
                false,
                npcIsInPlayerFaction($npcData),
                'chat',
                $speaker,
                $npcData
            ),
    ];
}
$messages[] = [
    'role' => 'user',
    'content' => $userContent,
];

stobeLogInfo('Latency pre-llm stage message_assembly', [
    'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
    'duration_ms' => intval(round((microtime(true) - $stobeMessageAssemblyStageStartedAt) * 1000)),
    'message_count' => count($messages),
    'unix_ms' => intval(round(microtime(true) * 1000)),
]);

$stobeConfigStageStartedAt = microtime(true);
$llmConfig = getLlmConfigForNpc($npcData);
$actionConfig = stobeBuildActionConfigForNpc('chat', $npcData);
if (function_exists('stobeNegTick')) {
    try { stobeNegTick($targetNpc); } catch (Throwable $negTickError) {
        stobeLogWarn('Negotiation tick failed', ['npc'=>$targetNpc, 'error'=>$negTickError->getMessage()]);
    }
}
// Voice hand-over ("Here are your 200 cats"): the player really hands it over before the NPC replies.
if (!$narratorMode && empty($manualActionActive) && strcasecmp($speaker, $playerName) === 0
    && function_exists('stobeNegVoiceHandover')) {
    $voiceHandoverNote = stobeNegVoiceHandover($targetNpc, $npcData, $playerName, $message, intval($gamets));
    if ($voiceHandoverNote === '' && function_exists('stobeNegPlayerRefusal')) {
        $voiceHandoverNote = stobeNegPlayerRefusal($targetNpc, $playerName, $message);
    }
    if ($voiceHandoverNote !== '') {
        $messages[] = ['role' => 'user', 'content' => '[' . $voiceHandoverNote . ']'];
    }
}
// Bug 42: what she is about to do anyway (breach reaction, refund, hand-over) must shape her words.
if (!$narratorMode && function_exists('stobeNegPendingDirectiveNotes')) {
    try {
        foreach (stobeNegPendingDirectiveNotes($targetNpc) as $directiveNote) {
            $messages[] = ['role' => 'user', 'content' => '[' . $directiveNote
                . ' This is already happening in the game; your words must match it. Deal progress, not what '
                . $playerName . ' claims, decides what was paid.]'];
        }
    } catch (Throwable $directiveNoteError) {
        stobeLogWarn('Directive notes failed', ['npc'=>$targetNpc, 'error'=>$directiveNoteError->getMessage()]);
    }
}
$negotiationActive = !$narratorMode && strcasecmp($speaker, $playerName) === 0
    && stobeDealShouldNegotiate($targetNpc, $npcData, $message);
// The player accepting the NPC's own last offer binds the NPC to it.
if ($negotiationActive && function_exists('stobeNegPlayerAcceptsOfferNote')) {
    $acceptNote = stobeNegPlayerAcceptsOfferNote($targetNpc, $message);
    if ($acceptNote !== '') {
        $messages[] = ['role' => 'user', 'content' => '[' . $acceptNote . ']'];
    }
}
// Bug 30: last turn she agreed in words but no deal was recorded: remind her once.
if ($negotiationActive && function_exists('stobeDealTakeUnrecordedAgreement')) {
    $unrecordedNote = stobeDealTakeUnrecordedAgreement($targetNpc, $playerName);
    if ($unrecordedNote !== '') {
        $messages[] = ['role' => 'user', 'content' => '[' . $unrecordedNote . ']'];
    }
}
// Bug 119: asked to put on what she already wears: tell her it's on.
if (!$narratorMode && strcasecmp($speaker, $playerName) === 0 && function_exists('stobeWornItemRequestNote')) {
    $wornNote = stobeWornItemRequestNote($message, $npcData);
    if ($wornNote !== '') {
        $messages[] = ['role' => 'user', 'content' => '[' . $wornNote . ']'];
        stobeLogInfo('Worn item note added (bug 119)', ['npc'=>$targetNpc, 'note'=>$wornNote]);
    }
}
// Mid-fight replies skip the model's hidden reasoning step (setting COMBAT_FAST_REPLIES).
$GLOBALS['STOBE_REASONING_OFF'] = is_array($npcData) && stobeNpcIsInCombat($npcData)
    && (function_exists('getSettingBool') ? getSettingBool('COMBAT_FAST_REPLIES', true) : true);
$negotiationKind = $negotiationActive ? stobeDealKindFor($npcData) : '';
// Speech is held back only while terms can still be agreed; an agreed deal that is
// just being carried out streams normally (no added latency).
$negotiationOpenDeal = $negotiationActive ? stobeDealOpenForNpc($targetNpc) : null;
$negotiationDefer = $negotiationActive
    && ($negotiationOpenDeal === null || in_array(strval($negotiationOpenDeal['status'] ?? ''), ['PROPOSED','COUNTERED'], true));
// Handing over goods under an already agreed deal is not a gift.
if ($negotiationOpenDeal !== null && in_array(strval($negotiationOpenDeal['status'] ?? ''), ['ACCEPTED','AWAITING_PERFORMANCE'], true)) {
    $actionConfig['deal_sanctioned_give'] = true;
}
if ($narratorMode) {
    $actionConfig['enabled'] = false;
    $actionConfig['max_actions'] = 1;
}

$chatResponseFormat = $narratorMode
    ? null
    : ($negotiationActive
        ? stobeDealResponseFormat(stobeBuildStructuredDialogueResponseFormat($targetNpc, $npcData, false, 'chat', $speaker))
        : stobeBuildStructuredDialogueResponseFormat($targetNpc, $npcData, npcIsInPlayerFaction($npcData), 'chat', $speaker));

stobeLogInfo('Latency pre-llm stage config_relationship_negotiation', [
    'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
    'duration_ms' => intval(round((microtime(true) - $stobeConfigStageStartedAt) * 1000)),
    'unix_ms' => intval(round(microtime(true) * 1000)),
]);

$responseText = '';
$responseActions = [];
$responseListener = '';
$alreadyStreamed = false;
$streamHeldBack = false;     // round 10b: money talk held back mid-reply
$streamSpokenPrefix = '';    // sentences already spoken before the hold
$streamHeldFrom = '';        // first held sentence
$streamSpeakOverride = null; // corrected held part (amount check)
$actionsStreamedInLlm = false;
$manualActionForcedEmoteOnly = false;
if ($manualActionActive && $manualActionCannotSpeak) {
    $manualActionForcedEmoteOnly = true;
    $responseText = '';
    $fallbackAction = stobeBuildManualActionPainFallback(
        $targetNpc,
        $manualActionActor,
        $manualActionKey
    );
    $responseActions = $fallbackAction !== '' ? [$fallbackAction] : [];
    stobeLogInfo('Manual action fallback emitted: target cannot speak', [
        'target_npc' => $targetNpc,
        'manual_action' => $manualActionKey,
        'manual_action_actor' => $manualActionActor,
        'manual_action_target' => $manualActionTarget,
    ]);
} elseif ($llmConfig['api_key'] === '') {
    $responseText = 'No OpenRouter API key configured yet.';
    stobeLogWarn('LLM call skipped because API key is missing', ['target_npc' => $targetNpc]);
} else {
    $stobePreLlmMs = isset($GLOBALS['__stobe_request_start']) && is_float($GLOBALS['__stobe_request_start'])
        ? intval(round((microtime(true) - $GLOBALS['__stobe_request_start']) * 1000))
        : 0;
    stobeLogInfo('Latency stage pre-llm complete', [
        'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
        'target_npc' => $targetNpc,
        'speaker' => $speaker,
        'pre_llm_ms' => $stobePreLlmMs,
        'unix_ms' => intval(round(microtime(true) * 1000)),
        'message_sha1' => sha1(trim(strval($message ?? ''))),
        'message_count' => count($messages),
        'model' => strval($llmConfig['model'] ?? ''),
    ]);

    if (function_exists('stobeQueueShadowLlm')) {
        stobeQueueShadowLlm([
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'target_npc' => $targetNpc,
            'speaker' => $speaker,
            'gamets' => intval($gamets),
            'messages' => $messages,
            'response_format' => $chatResponseFormat,
            'max_tokens' => intval($llmConfig['max_tokens'] ?? 750),
            'temperature' => floatval($llmConfig['temperature'] ?? 0.8),
            'authoritative_model' => strval($llmConfig['model'] ?? ''),
            'queued_after_pre_llm_ms' => $stobePreLlmMs,
        ]);
    }

    $streamResult = stobeStreamDialogueViaLlm(
        $targetNpc,
        $npcData,
        $messages,
        $llmConfig,
        'chat',
        [
            'npc_name' => $targetNpc,
            'event_type' => 'chat',
            'speaker' => $speaker,
            'action_config' => $actionConfig,
            'stream_event_type' => 'chat',
            'stream_listener' => $speaker, // bug 95: replies go to whoever spoke
            'stream_gamets' => $gamets,
            'defer_structured_stream' => $negotiationDefer,
            'hold_stream_on_money' => $negotiationActive && !$negotiationDefer,
            'response_format' => $chatResponseFormat,
        ]
    );

    if (boolval($streamResult['ok'] ?? false)) {
        $responseText = sanitizeForKenshi(trim(strval($streamResult['response_text'] ?? '')));
        $responseActions = is_array($streamResult['actions'] ?? null) ? $streamResult['actions'] : [];
        $responseListener = normalizeParticipantNameToken(strval($streamResult['listener'] ?? ''));
        $alreadyStreamed = intval($streamResult['chunks_emitted'] ?? 0) > 0;
        if (!empty($streamResult['held_back'])) {
            // Part of the reply is still unspoken: check it, then speak it at the end.
            $streamHeldBack = true;
            $streamSpokenPrefix = trim(strval($streamResult['spoken_text'] ?? ''));
            $streamHeldFrom = trim(strval($streamResult['held_from'] ?? ''));
            $alreadyStreamed = false;
        }
        $actionsStreamedInLlm = boolval($streamResult['actions_streamed'] ?? false);
        if ($negotiationActive) {
            $dealResult = stobeDealCaptureResponse(
                strval($streamResult['raw_response'] ?? ''), $targetNpc, $playerName, $npcData, $message,
                $negotiationKind !== '' ? $negotiationKind : 'combat'
            );
            if (!empty($dealResult['blocked_by_active'])) {
                // A different deal while the last one is underway: finish that one first.
                $responseText = strval($dealResult['blocked_line'] ?? "Let's finish our last deal first.");
                $responseActions = array_values(array_filter($responseActions, static fn($a) =>
                    !preg_match('/^(STOP_ATTACK|GIVE_CATS|GIVE_ITEM|TAKE_CATS|TAKE_ITEM|UNEQUIP_ITEM|EQUIP_ITEM)@/i', strval($a))));
            }
            if (!empty($dealResult['ok']) && strval($dealResult['decision'] ?? '') === 'NONE'
                && empty($dealResult['blocked_by_active']) && empty($dealResult['duplicate_of'])
                && $negotiationOpenDeal === null && function_exists('stobeDealSpeechAgrees')
                && stobeDealSpeechAgrees($responseText) && stobeNegLooksLikeSocialOffer($message)) {
                // Bug 30: she agreed in words, but nothing was recorded.
                stobeDealRememberUnrecordedAgreement($targetNpc, $message, $responseText);
            }
            if (!empty($dealResult['ok']) && empty($dealResult['blocked_by_active'])
                && function_exists('stobeDealSpeechAmountCheck')) {
                // Bug 26: numbers she says must be the recorded ones.
                try {
                    $underwayStatus = strval($negotiationOpenDeal['status'] ?? '');
                    if (in_array($underwayStatus, ['ACCEPTED','AWAITING_PERFORMANCE'], true)
                        && (strval($dealResult['decision'] ?? '') === 'NONE' || !empty($dealResult['already_active']))
                        && function_exists('stobeDealProgressAmountCheck')) {
                        // Talk about the deal that's underway: amounts must be its paid/owed figures.
                        $amountCheck = stobeDealProgressAmountCheck($responseText, $targetNpc, $message);
                    } else {
                        $amountCheck = stobeDealSpeechAmountCheck($responseText, $targetNpc, $dealResult);
                    }
                } catch (Throwable $amountError) {
                    $amountCheck = null;
                }
                if ($amountCheck !== null) {
                    stobeLogWarn($alreadyStreamed
                        ? 'Negotiation speech amounts differ from recorded terms (already spoken)'
                        : 'Negotiation speech amounts differ from recorded terms; rewritten', [
                        'npc'=>$targetNpc, 'decision'=>strval($dealResult['decision'] ?? ''), 'text'=>$responseText,
                        'spoken'=>$amountCheck['spoken'], 'wrong'=>$amountCheck['wrong'], 'line'=>$amountCheck['line'],
                    ]);
                    if (!$alreadyStreamed) {
                        $responseText = trim($streamSpokenPrefix . ' ' . $amountCheck['line']);
                        if ($streamSpokenPrefix !== '') $streamSpeakOverride = $amountCheck['line'];
                    }
                }
            }
            if (empty($dealResult['ok'])) {
                // Malformed terms: record nothing, but keep the NPC's own words unless they claim a deal.
                if (strval($dealResult['error'] ?? '') === 'weapon_not_negotiable') {
                    // Bug 32: whatever she said, her weapon isn't part of the deal.
                    $responseText = strval($dealResult['refusal_line'] ?? 'My weapon stays with me.');
                } elseif ((stobeDealSpeechClaimsCeasefire($responseText)
                    || preg_match("/\\b(deal|agreed|you'?ve got it|you got it)\\b/i", $responseText))
                    && function_exists('stobeDealRecentCompletedCeasefire')
                    && ($honoured = stobeDealRecentCompletedCeasefire($targetNpc)) !== null) {
                    // Bug 90: the paid ceasefire still stands; keep her words and stop again.
                    $honourCeasefire = 'STOP_ATTACK@' . $playerName;
                    stobeLogInfo('Ceasefire honoured from a completed deal (bug 90)', ['npc'=>$targetNpc, 'contract_id'=>$honoured['contract_id'] ?? '']);
                } elseif (stobeDealSpeechClaimsCeasefire($responseText)
                    || preg_match("/\\b(deal|agreed|you'?ve got it|you got it)\\b/i", $responseText)) {
                    $responseText = "Let's get the terms straight first.";
                }
                $responseActions = array_values(array_filter($responseActions, static fn($a) =>
                    !preg_match('/^(STOP_ATTACK|GIVE_CATS|GIVE_ITEM|TAKE_CATS|TAKE_ITEM)@/i', strval($a))));
                if (!empty($honourCeasefire)) {
                    $responseActions[] = $honourCeasefire;
                    $actionConfig['deal_sanctioned_give'] = true; // let the STOP_ATTACK through (bug 88)
                }
                stobeLogWarn('Negotiation rejected by deterministic validation', [
                    'npc'=>$targetNpc, 'error'=>strval($dealResult['error'] ?? 'unknown')
                ]);
                // Bug 37: an offer or agreement with no readable terms: remind her next turn.
                if (strval($dealResult['error'] ?? '') === 'invalid_terms_json'
                    && in_array(strval($dealResult['decision'] ?? ''), ['ACCEPT','COUNTER','PROPOSE'], true)
                    && function_exists('stobeDealRememberUnrecordedAgreement')) {
                    stobeDealRememberUnrecordedAgreement($targetNpc, $message, $responseText, strval($dealResult['decision']));
                }
            } elseif (($dealResult['decision'] ?? '') === 'ACCEPT') {
                // Ceasefire first, then the NPC's own terms that are due now. Everything the
                // player owes, and every outcome, is verified later from game evidence.
                $dealKind = strval($dealResult['kind'] ?? ($negotiationKind ?: 'combat'));
                $actionConfig['deal_sanctioned_give'] = true;
                if (!empty($dealResult['already_active'])) {
                    $responseActions = in_array($dealKind, ['combat','surrender'], true) ? ['STOP_ATTACK@' . $playerName] : [];
                } else {
                    $responseActions = stobeNegAcceptActions(
                        is_array($dealResult['terms'] ?? null) ? $dealResult['terms'] : [], $playerName, $dealKind
                    );
                }
            } else {
                if (($dealResult['decision'] ?? '') === 'COUNTER') {
                    $responseActions = [];
                }
                // Words must not promise a ceasefire the NPC did not actually accept.
                if (in_array(($dealResult['decision'] ?? ''), ['COUNTER','REJECT'], true)
                    && in_array(strval($dealResult['kind'] ?? ($negotiationKind ?: 'combat')), ['combat','surrender'], true)
                    && stobeDealSpeechClaimsCeasefire($responseText)) {
                    stobeLogWarn('Negotiation speech implied ceasefire without ACCEPT; rewritten', [
                        'npc'=>$targetNpc, 'decision'=>strval($dealResult['decision'] ?? ''), 'text'=>$responseText,
                    ]);
                    $responseText = ($dealResult['decision'] ?? '') === 'COUNTER'
                        ? 'Not until you meet my terms.'
                        : 'No deal.';
                }
            }
        }
        stobeLogInfo('LLM stream response generated', [
            'target_npc' => $targetNpc,
            'model' => $llmConfig['model'] ?? '',
            'response_length' => strlen($responseText),
            'structured_json' => boolval($streamResult['structured_json'] ?? false),
            'actions_count' => count($responseActions),
            'actions' => $responseActions,
            'chunks_emitted' => intval($streamResult['chunks_emitted'] ?? 0),
            'actions_streamed' => $actionsStreamedInLlm,
        ]);
    } else {
        $responseText = '...';
        stobeLogWarn('LLM stream response failed', [
            'target_npc' => $targetNpc,
            'model' => $llmConfig['model'] ?? '',
            'narrator_mode' => $narratorMode,
        ]);
    }
}
$responseActions = stobeDedupeActionList($responseActions, 'chat', $actionConfig);
if ($narratorMode) {
    $responseActions = [];
}
// Anything added from here on was not streamed with the LLM's own actions.
$preLateActions = $responseActions;
$dealTurnDecision = isset($dealResult) && is_array($dealResult) ? strval($dealResult['decision'] ?? '') : '';
if (!$narratorMode && function_exists('stobeInferClothingAction')
    && !in_array($dealTurnDecision, ['ACCEPT','COUNTER','PROPOSE'], true)) {
    $inferredAction = stobeInferClothingAction($responseText, $npcData, $responseActions);
    if ($inferredAction !== '') {
        $responseActions[] = $inferredAction;
        stobeLogInfo('Clothing action inferred from speech', ['npc'=>$targetNpc, 'action'=>$inferredAction, 'text'=>$responseText]);
    }
}
if (!$narratorMode && function_exists('stobeInferWorkGoalFromOrder')
    && !in_array($dealTurnDecision, ['ACCEPT','COUNTER','PROPOSE'], true)) {
    $inferredGoal = stobeInferWorkGoalFromOrder(strval($GLOBALS['STOBE_CURRENT_PLAYER_MESSAGE'] ?? ''), $npcData, $responseActions);
    if ($inferredGoal !== '') {
        $responseActions[] = $inferredGoal;
        stobeLogInfo('Work goal inferred from a direct order (bug 76)', ['npc'=>$targetNpc, 'action'=>$inferredGoal]);
    }
}
if (!$narratorMode && function_exists('stobeInferFollowFromOrder')
    && !in_array($dealTurnDecision, ['ACCEPT','COUNTER','PROPOSE'], true)) {
    $inferredFollow = stobeInferFollowFromOrder(strval($GLOBALS['STOBE_CURRENT_PLAYER_MESSAGE'] ?? ''), $npcData, $responseActions,
        trim(strval(getSetting('PLAYER_NAME', ''))));
    if ($inferredFollow !== '') {
        $responseActions[] = $inferredFollow;
        stobeLogInfo('Follow inferred from a direct request (bug 87)', ['npc'=>$targetNpc, 'action'=>$inferredFollow]);
    }
}
if (!$narratorMode && function_exists('stobeNegAttachPendingForChat')) {
    try {
        $responseActions = stobeNegAttachPendingForChat($targetNpc, $responseActions);
    } catch (Throwable $negAttachError) {
        stobeLogWarn('Negotiation dispatch attach failed', ['npc'=>$targetNpc, 'error'=>$negAttachError->getMessage()]);
    }
}
// Bug 32: an NPC doesn't give up the weapon she's using unless she's surrendering or trusts the player.
if (!$narratorMode && is_array($npcData) && function_exists('stobeDealFilterWeaponActions')) {
    [$responseActions, $weaponActionsRemoved] = stobeDealFilterWeaponActions(
        $responseActions, $npcData, $playerName, strval($dealResult['kind'] ?? ($negotiationKind ?? ''))
    );
    if (count($weaponActionsRemoved) > 0) {
        stobeLogWarn('Weapon hand-over blocked (not surrendering, not trusted)', [
            'npc'=>$targetNpc, 'removed'=>$weaponActionsRemoved, 'text'=>$responseText,
        ]);
    }
}
// An NPC attacking the player over a private matter keeps it one-on-one.
if (!$narratorMode && function_exists('stobeNegRegisterPersonalFight') && is_array($npcData) && !npcIsInPlayerFaction($npcData)) {
    foreach ($responseActions as $actionIndex => $responseAction) {
        if (preg_match('/^ATTACK@(.+)$/i', strval($responseAction), $attackMatch)
            && strcasecmp(normalizeParticipantNameToken($attackMatch[1]), $playerName) === 0) {
            try { stobeNegRegisterPersonalFight($targetNpc, $npcData, strval($responseText ?? '')); } catch (Throwable $e) {}
            // She called for help: tell the DLL not to stand her faction-mates down.
            if (function_exists('stobeNegCalledForHelp') && stobeNegCalledForHelp(strval($responseText ?? ''))) {
                $responseActions[$actionIndex] = 'ATTACK@' . $attackMatch[1] . '@help';
            }
            break;
        }
    }
}

$peopleRaw = strval($GLOBALS['CACHE_PEOPLE'] ?? ($_GET['people'] ?? ''));
$participantIdentities = extractParticipantIdentities([
    'people' => $peopleRaw,
    'profile' => $targetNpc,
    'speaker' => $speaker,
]);
$listenerCandidates = [$speaker, $targetNpc, $playerName];
foreach ($participantIdentities as $participantIdentity) {
    if (!is_array($participantIdentity)) {
        continue;
    }
    $listenerCandidates[] = strval($participantIdentity['name'] ?? '');
}
$replyTarget = stobeResolveDialogueListenerTarget($responseListener, $listenerCandidates, $speaker);
if ($replyTarget === '') {
    $replyTarget = $speaker;
}
if ($responseListener !== '' && strcasecmp($responseListener, $replyTarget) !== 0) {
    stobeLogDebug('Chat listener remapped to known participant', [
        'target_npc' => $targetNpc,
        'parsed_listener' => $responseListener,
        'resolved_listener' => $replyTarget,
    ]);
}

if (!$manualActionForcedEmoteOnly && !$narratorMode) {
    $relationshipInput = $injectionChatMode
        ? 'Shift+U action request: ' . $message
        : ($injectionMode
            ? 'Injected event: ' . $message
            : $speaker . ': ' . $message);
    $relationshipEval = stobeEvaluateRelationshipsForTurn(
        $targetNpc,
        $replyTarget,
        $relationshipInput,
        $responseText,
        $npcData,
        'chat'
    );
    $responseText = sanitizeForKenshi(trim(strval($relationshipEval['clean_response'] ?? $responseText)));
    if (!stobeInlineNarrationApplies($targetNpc, 'chat')) {
        $responseText = stobeStripParentheticalDialogueText($responseText);
    }
}
if (!stobeInlineNarrationApplies($targetNpc, 'chat')) {
    $responseText = stobeStripParentheticalDialogueText($responseText);
}
if ($responseText === '' && count($responseActions) === 0) {
    $responseText = '...';
}

storeActionEvents($targetNpc, $responseActions, $gamets, $replyTarget, 'chat');

if ($alreadyStreamed) {
    if (count($responseActions) > 0 && !$actionsStreamedInLlm) {
        streamResponse($targetNpc, 'ScriptQueue', '', $npcData, $responseActions, 'chat', $replyTarget, $gamets);
    } elseif ($actionsStreamedInLlm) {
        $lateActions = array_values(array_diff($responseActions, $preLateActions ?? $responseActions));
        if (count($lateActions) > 0) {
            streamResponse($targetNpc, 'ScriptQueue', '', $npcData, $lateActions, 'chat', $replyTarget, $gamets);
        }
    }
} else {
    $textToSpeak = $responseText;
    if ($streamHeldBack && $streamSpokenPrefix !== '') {
        // The start was already spoken; speak only the held part.
        if ($streamSpeakOverride !== null) {
            $textToSpeak = $streamSpeakOverride;
        } elseif ($streamHeldFrom !== '' && ($heldPos = strpos($responseText, $streamHeldFrom)) !== false) {
            $textToSpeak = substr($responseText, $heldPos);
        } else {
            stobeLogWarn('Held-back reply: held part not found, speaking the full line', ['npc'=>$targetNpc, 'text'=>$responseText]);
        }
    }
    stobeStreamDialogueResponse(
        $targetNpc,
        $npcData,
        $textToSpeak,
        $responseActions,
        'chat',
        $replyTarget,
        intval($gamets)
    );
}
if (isset($dealResult) && ($dealResult['decision'] ?? '') === 'ACCEPT'
    && ($dealResult['status'] ?? '') === 'ACCEPTED' && function_exists('stobeNegBeginPerformance')) {
    // Queued actions are not proof of anything; the engine verifies each term from evidence.
    stobeNegBeginPerformance(strval($dealResult['id']), $responseActions, $playerName, intval($gamets), $peopleRaw);
    // "Here's your cats" in the same line that closed the deal: pay what is now owed.
    if (($voiceHandoverNote ?? '') === '' && !$narratorMode && empty($manualActionActive)
        && function_exists('stobeNegVoiceHandover')) {
        stobeNegVoiceHandover($targetNpc, $npcData, $playerName, $message, intval($gamets));
    }
}
// Bug 34: the player handed something over in this request; wait (briefly) for the game to
// confirm it so her side is queued now and goes out below.
if (!$narratorMode && !empty($GLOBALS['STOBE_VOICE_HANDOVER_NPC']) && function_exists('stobeNegSettleAfterHandover')) {
    try { stobeNegSettleAfterHandover($targetNpc); } catch (Throwable $settleError) {
        stobeLogWarn('Settle after hand-over failed', ['npc'=>$targetNpc, 'error'=>$settleError->getMessage()]);
    }
    if (function_exists('stobeNegRefundUnearnedPrepayment')) { // bug 122
        try { stobeNegRefundUnearnedPrepayment($targetNpc, $playerName); } catch (Throwable $refundError) {
            stobeLogWarn('Prepayment refund failed', ['npc'=>$targetNpc, 'error'=>$refundError->getMessage()]);
        }
    }
}
// Bug 29: payment verified during this request queued her side (or a refund) after the
// reply went out. Send those actions now instead of waiting for the next line.
if (!$narratorMode && function_exists('stobeNegAttachPendingForChat')) {
    try {
        $lateDirectiveActions = stobeNegAttachPendingForChat($targetNpc, []);
        if (count($lateDirectiveActions) > 0) {
            streamResponse($targetNpc, 'ScriptQueue', '', $npcData, $lateDirectiveActions, 'chat', $replyTarget, $gamets);
            stobeLogInfo('Negotiation directive sent in the same turn (after the reply)', ['npc'=>$targetNpc, 'actions'=>$lateDirectiveActions]);
        }
    } catch (Throwable $lateDirectiveError) {
        stobeLogWarn('Late negotiation directive send failed', ['npc'=>$targetNpc, 'error'=>$lateDirectiveError->getMessage()]);
    }
}

if (function_exists('stobeTrainingCapture')) {
    stobeTrainingCapture('chat_turn_final', [
        'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
        'gamets' => intval($gamets),
        'target_npc' => $targetNpc,
        'speaker' => $speaker,
        'player_name' => $playerName,
        'input_text' => $message,
        'dialogue_mode' => $dialogueMode,
        'narrator_mode' => $narratorMode,
        'injection_mode' => $injectionMode,
        'injection_chat_mode' => $injectionChatMode,
        'manual_action' => $manualActionActive ? $manualActionKey : '',
        'model' => strval($llmConfig['model'] ?? ''),
        'final_response_text' => $responseText,
        'final_actions' => $responseActions,
        'late_directive_actions' => isset($lateDirectiveActions) && is_array($lateDirectiveActions) ? $lateDirectiveActions : [],
        'reply_target' => $replyTarget,
        'negotiation_active' => $negotiationActive,
        'negotiation_kind' => strval($negotiationKind ?? ''),
        'deal_decision' => isset($dealResult) && is_array($dealResult) ? strval($dealResult['decision'] ?? '') : '',
        'deal_status' => isset($dealResult) && is_array($dealResult) ? strval($dealResult['status'] ?? '') : '',
        'deal_id' => isset($dealResult) && is_array($dealResult) ? strval($dealResult['id'] ?? '') : '',
        'already_streamed' => $alreadyStreamed,
        'actions_streamed_in_llm' => $actionsStreamedInLlm,
        'shadow_enabled' => function_exists('stobeShadowEnabled') && stobeShadowEnabled(),
    ]);
}
