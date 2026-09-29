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
        if (!$likelyTrader && !$tradeIntent) {
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
            $actors[] = ['name' => $name];
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
    'mode' => $dialogueMode,
    'manual_action' => $manualActionActive ? $manualActionKey : '',
    'manual_action_actor' => $manualActionActor,
    'manual_action_target' => $manualActionTarget,
    'manual_action_actor_sid' => $manualActionActorSid,
    'manual_action_target_sid' => $manualActionTargetSid,
    'gamets' => intval($gamets),
    'message_preview' => $messagePreview,
]);

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
$eventHistory = $narratorMode
    ? DataEventLog($contextHistory)
    : DataEventLog($contextHistory, $targetNpc, '', $historyAliases);
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
$memoryContextMessages = stobeBuildMemoryEventContextMessages(
    is_array($npcData) ? $npcData : [],
    $targetNpc,
    $message,
    intval($gamets)
);

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

$manualActionCannotSpeak = $manualActionActive
    ? stobeManualActionTargetCannotSpeak($npcData, $manualActionKey)
    : false;

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

$systemPrompt = buildSystemPrompt(
    $targetNpc,
    $promptNpcData,
    $speaker,
    $message,
    !$narratorMode,
    'chat',
    intval($gamets)
);
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
$messages[] = [
    'role' => 'user',
    'content' => $userContent,
];
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
$negotiationActive = !$narratorMode && strcasecmp($speaker, $playerName) === 0
    && stobeDealShouldNegotiate($targetNpc, $npcData, $message);
// The player accepting the NPC's own last offer binds the NPC to it.
if ($negotiationActive && function_exists('stobeNegPlayerAcceptsOfferNote')) {
    $acceptNote = stobeNegPlayerAcceptsOfferNote($targetNpc, $message);
    if ($acceptNote !== '') {
        $messages[] = ['role' => 'user', 'content' => '[' . $acceptNote . ']'];
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

$responseText = '';
$responseActions = [];
$responseListener = '';
$alreadyStreamed = false;
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
            'stream_gamets' => $gamets,
            'defer_structured_stream' => $negotiationDefer,
            'response_format' => $narratorMode
                ? null
                : ($negotiationActive
                    ? stobeDealResponseFormat(stobeBuildStructuredDialogueResponseFormat($targetNpc, $npcData, false, 'chat', $speaker))
                    : stobeBuildStructuredDialogueResponseFormat($targetNpc, $npcData, npcIsInPlayerFaction($npcData), 'chat', $speaker)),
        ]
    );

    if (boolval($streamResult['ok'] ?? false)) {
        $responseText = sanitizeForKenshi(trim(strval($streamResult['response_text'] ?? '')));
        $responseActions = is_array($streamResult['actions'] ?? null) ? $streamResult['actions'] : [];
        $responseListener = normalizeParticipantNameToken(strval($streamResult['listener'] ?? ''));
        $alreadyStreamed = intval($streamResult['chunks_emitted'] ?? 0) > 0;
        $actionsStreamedInLlm = boolval($streamResult['actions_streamed'] ?? false);
        if ($negotiationActive) {
            $dealResult = stobeDealCaptureResponse(
                strval($streamResult['raw_response'] ?? ''), $targetNpc, $playerName, $npcData, $message,
                $negotiationKind !== '' ? $negotiationKind : 'combat'
            );
            if (empty($dealResult['ok'])) {
                // Malformed terms: record nothing, but keep the NPC's own words unless they claim a deal.
                if (stobeDealSpeechClaimsCeasefire($responseText)
                    || preg_match("/\\b(deal|agreed|you'?ve got it|you got it)\\b/i", $responseText)) {
                    $responseText = "Let's get the terms straight first.";
                }
                $responseActions = array_values(array_filter($responseActions, static fn($a) =>
                    !preg_match('/^(STOP_ATTACK|GIVE_CATS|GIVE_ITEM|TAKE_CATS|TAKE_ITEM)@/i', strval($a))));
                stobeLogWarn('Negotiation rejected by deterministic validation', [
                    'npc'=>$targetNpc, 'error'=>strval($dealResult['error'] ?? 'unknown')
                ]);
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
if (!$narratorMode && function_exists('stobeNegAttachPendingForChat')) {
    try {
        $responseActions = stobeNegAttachPendingForChat($targetNpc, $responseActions);
    } catch (Throwable $negAttachError) {
        stobeLogWarn('Negotiation dispatch attach failed', ['npc'=>$targetNpc, 'error'=>$negAttachError->getMessage()]);
    }
}
// An NPC attacking the player over a private matter keeps it one-on-one.
if (!$narratorMode && function_exists('stobeNegRegisterPersonalFight') && is_array($npcData) && !npcIsInPlayerFaction($npcData)) {
    foreach ($responseActions as $responseAction) {
        if (preg_match('/^ATTACK@(.+)$/i', strval($responseAction), $attackMatch)
            && strcasecmp(normalizeParticipantNameToken($attackMatch[1]), $playerName) === 0) {
            try { stobeNegRegisterPersonalFight($targetNpc, $npcData, strval($responseText ?? '')); } catch (Throwable $e) {}
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
    }
} else {
    stobeStreamDialogueResponse(
        $targetNpc,
        $npcData,
        $responseText,
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
