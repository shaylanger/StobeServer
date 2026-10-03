<?php

/**
 * Rechat processor - NPC-to-NPC dialogue continuation.
 */

$normalizedEventType = strtolower(trim(strval($eventType)));
$normalizeDialogueSignature = static function (string $rawEventData): string {
    $sanitized = trim($rawEventData);
    // Some client echoes append a stray numeric token after "(talking to: ...)".
    $sanitized = preg_replace('/(\(talking to:\s*[^\)]+\))\s*\d+\s*$/i', '$1', $sanitized) ?? $sanitized;
    $parsed = parseDialogueEventData($sanitized);
    $speaker = strtolower(trim(strval($parsed['speaker'] ?? '')));
    $target = strtolower(trim(strval($parsed['target'] ?? '')));
    $message = strtolower(trim(preg_replace('/\s+/', ' ', strval($parsed['message'] ?? '')) ?? ''));
    if ($speaker === '' && $target === '' && $message === '') {
        return '';
    }
    return $speaker . '|' . $target . '|' . $message;
};
$normalizedEventData = trim($eventData);
$normalizedEventData = preg_replace('/(\(talking to:\s*[^\)]+\))\s*\d+\s*$/i', '$1', $normalizedEventData) ?? $normalizedEventData;
if ($normalizedEventData !== trim($eventData)) {
    $eventData = $normalizedEventData;
}

$storeIncomingEvent = $normalizedEventType !== 'rechat';
if (!$storeIncomingEvent) {
    // `rechat` requests are trigger echoes for the *previous* spoken line.
    // Persisting them causes duplicate chat/rechat rows in eventlog.
} else {
    storeEvent($eventType, $timestamp, $gamets, $eventData);
}

if (!stobeInteractionAllowed()) { echo 'ok'; return; }

$campaign = 'Default';
$requestMode = strtolower(trim(strval($_GET['mode'] ?? '')));
if ($requestMode === 'whisper' || $requestMode === 'narrator') {
    stobeLogInfo('Rechat skipped: private mode', [
        'event_type' => $eventType,
        'mode' => $requestMode,
    ]);
    echo "ok";
    return;
}
$playerName = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
$speakerRechatEnabled = getSettingBool('SPEAKER_RECHAT', false);
$incomingProfile = normalizeParticipantNameToken(trim(strval($_GET['profile'] ?? '')));
$peopleRaw = strval($GLOBALS["CACHE_PEOPLE"] ?? ($_GET['people'] ?? ''));
$requestedDepthRaw = intval($_GET['rechat_depth'] ?? 0);
$requestedDepth = $requestedDepthRaw > 0 ? $requestedDepthRaw : 0;
$initiatorIdentity = extractParticipantIdentityToken(strval($_GET['initiator'] ?? ''));
$initiatorName = normalizeParticipantNameToken(strval($initiatorIdentity['name'] ?? ''));
$initiatorStorageId = normalizeStorageIdToken(
    strval($_GET['initiator_sid'] ?? ($initiatorIdentity['storage_id'] ?? ''))
);
$requestedRechatTargetIdentity = extractParticipantIdentityToken(strval($_GET['rechat_target'] ?? ''));
$requestedRechatTargetName = normalizeParticipantNameToken(strval($requestedRechatTargetIdentity['name'] ?? ''));
$requestedRechatTargetStorageId = normalizeStorageIdToken(
    strval($_GET['rechat_target_sid'] ?? ($requestedRechatTargetIdentity['storage_id'] ?? ''))
);

$dialogueData = parseDialogueEventData($eventData);
$previousSpeaker = normalizeParticipantNameToken(strval($dialogueData['speaker'] ?? ''));
$previousMessage = trim(strval($dialogueData['message'] ?? ''));
$previousTarget = normalizeParticipantNameToken(strval($dialogueData['target'] ?? ''));
if ($previousSpeaker === '') {
    $previousSpeaker = $incomingProfile;
}

if ($previousSpeaker === '') {
    stobeLogInfo('Rechat skipped: previous speaker missing', [
        'event_type' => $eventType,
        'data_preview' => substr($eventData, 0, 120),
    ]);
    echo "ok";
    return;
}
if (stobeIsNarratorName($previousSpeaker)) {
    stobeLogInfo('Rechat skipped: narrator speaker', [
        'event_type' => $eventType,
        'speaker' => $previousSpeaker,
    ]);
    echo "ok";
    return;
}

if (stobeTryTriggerRandomNarration(
    intval($gamets),
    $previousSpeaker,
    $previousMessage,
    $previousTarget,
    'rechat',
    $playerName,
    intval($timestamp)
)) {
    echo "ok";
    return;
}

$toBool = static function (mixed $value, bool $default = false): bool {
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return floatval($value) != 0.0;
    }
    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return $default;
        }
        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'n', 'off'], true)) {
            return false;
        }
    }
    return $default;
};

$decodeJsonArray = static function (mixed $value): array {
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value)) {
        return [];
    }
    $trimmed = trim($value);
    if ($trimmed === '') {
        return [];
    }
    $decoded = json_decode($trimmed, true);
    return is_array($decoded) ? $decoded : [];
};

$extractEnvironment = static function (array|false $npcData, string $fallbackName = '') use ($decodeJsonArray, $toBool): array {
    $npcRow = is_array($npcData) ? $npcData : [];
    $name = normalizeParticipantNameToken(strval($npcRow['name'] ?? $fallbackName));
    $extendedData = $decodeJsonArray($npcRow['extended_data'] ?? []);
    $environment = [];
    if (isset($extendedData['environment']) && is_array($extendedData['environment'])) {
        $environment = $extendedData['environment'];
    }
    if (!is_array($environment) || count($environment) === 0) {
        $candidateKeys = ['nearby_snapshot', 'entry'];
        foreach ($candidateKeys as $candidateKey) {
            if (!isset($extendedData[$candidateKey]) || !is_array($extendedData[$candidateKey])) {
                continue;
            }
            $nested = $extendedData[$candidateKey];
            if (isset($nested['environment']) && is_array($nested['environment'])) {
                $environment = $nested['environment'];
                break;
            }
            $environment = $nested;
            break;
        }
    }
    if (!is_array($environment)) {
        $environment = [];
    }

    $indoorsKnown = array_key_exists('indoors', $environment) || array_key_exists('outdoors', $environment);
    $indoors = false;
    if (array_key_exists('indoors', $environment)) {
        $indoors = $toBool($environment['indoors'], false);
    } elseif (array_key_exists('outdoors', $environment)) {
        $indoors = !$toBool($environment['outdoors'], true);
    }

    $buildingSerial = '';
    foreach (['building_serial', 'building_id', 'indoors_serial'] as $serialKey) {
        if (!array_key_exists($serialKey, $environment)) {
            continue;
        }
        $candidateSerial = trim(strval($environment[$serialKey]));
        if ($candidateSerial !== '' && $candidateSerial !== '0') {
            $buildingSerial = $candidateSerial;
            break;
        }
    }

    $buildingName = '';
    foreach (['building_name', 'indoors_name'] as $nameKey) {
        if (!array_key_exists($nameKey, $environment)) {
            continue;
        }
        $candidateName = trim(strval($environment[$nameKey]));
        if ($candidateName !== '') {
            $buildingName = $candidateName;
            break;
        }
    }

    $townName = '';
    foreach (['town_name', 'town'] as $townKey) {
        if (!array_key_exists($townKey, $environment)) {
            continue;
        }
        $candidateTown = trim(strval($environment[$townKey]));
        if ($candidateTown !== '') {
            $townName = $candidateTown;
            break;
        }
    }

    $floorKnown = false;
    $floor = 0;
    foreach (['floor', 'floor_num', 'current_floor'] as $floorKey) {
        if (!array_key_exists($floorKey, $environment)) {
            continue;
        }
        $rawFloor = $environment[$floorKey];
        if (is_int($rawFloor) || is_float($rawFloor) || (is_string($rawFloor) && preg_match('/^-?[0-9]+$/', trim($rawFloor)) === 1)) {
            $floor = intval($rawFloor);
            $floorKnown = true;
            break;
        }
    }

    $known = $indoorsKnown || $buildingSerial !== '' || $buildingName !== '' || $townName !== '';

    return [
        'name' => $name,
        'known' => $known,
        'indoors' => $indoors,
        'building_serial' => $buildingSerial,
        'building_name' => $buildingName,
        'floor_known' => $floorKnown,
        'floor' => $floor,
        'town_name' => $townName,
    ];
};

$isEnvironmentCompatible = static function (array $speakerEnv, array $candidateEnv): array {
    if (!boolval($speakerEnv['known'] ?? false)) {
        return ['ok' => true, 'reason' => 'speaker_unknown'];
    }
    if (!boolval($candidateEnv['known'] ?? false)) {
        return ['ok' => false, 'reason' => 'candidate_unknown'];
    }

    $speakerIndoors = boolval($speakerEnv['indoors'] ?? false);
    $candidateIndoors = boolval($candidateEnv['indoors'] ?? false);
    if ($speakerIndoors) {
        if (!$candidateIndoors) {
            return ['ok' => false, 'reason' => 'speaker_indoors_candidate_outdoors'];
        }
        $speakerSerial = trim(strval($speakerEnv['building_serial'] ?? ''));
        $candidateSerial = trim(strval($candidateEnv['building_serial'] ?? ''));
        if ($speakerSerial !== '' && $candidateSerial !== '') {
            if ($speakerSerial === $candidateSerial) {
                $speakerFloorKnown = boolval($speakerEnv['floor_known'] ?? false);
                $candidateFloorKnown = boolval($candidateEnv['floor_known'] ?? false);
                if ($speakerFloorKnown && $candidateFloorKnown) {
                    $speakerFloor = intval($speakerEnv['floor'] ?? 0);
                    $candidateFloor = intval($candidateEnv['floor'] ?? 0);
                    if ($speakerFloor !== $candidateFloor) {
                        return ['ok' => false, 'reason' => 'different_floor'];
                    }
                    return ['ok' => true, 'reason' => 'same_building_same_floor'];
                }
                return ['ok' => true, 'reason' => 'same_building_serial_floor_unknown'];
            }
            return ['ok' => false, 'reason' => 'different_building_serial'];
        }
        $speakerBuildingName = strtolower(trim(strval($speakerEnv['building_name'] ?? '')));
        $candidateBuildingName = strtolower(trim(strval($candidateEnv['building_name'] ?? '')));
        if ($speakerBuildingName !== '' && $candidateBuildingName !== '') {
            if ($speakerBuildingName === $candidateBuildingName) {
                $speakerFloorKnown = boolval($speakerEnv['floor_known'] ?? false);
                $candidateFloorKnown = boolval($candidateEnv['floor_known'] ?? false);
                if ($speakerFloorKnown && $candidateFloorKnown) {
                    $speakerFloor = intval($speakerEnv['floor'] ?? 0);
                    $candidateFloor = intval($candidateEnv['floor'] ?? 0);
                    if ($speakerFloor !== $candidateFloor) {
                        return ['ok' => false, 'reason' => 'different_floor'];
                    }
                    return ['ok' => true, 'reason' => 'same_building_same_floor'];
                }
                return ['ok' => true, 'reason' => 'same_building_name_floor_unknown'];
            }
            return ['ok' => false, 'reason' => 'different_building_name'];
        }
        return ['ok' => false, 'reason' => 'missing_building_identity'];
    }

    if ($candidateIndoors) {
        return ['ok' => false, 'reason' => 'speaker_outdoors_candidate_indoors'];
    }

    $speakerFloorKnown = boolval($speakerEnv['floor_known'] ?? false);
    $candidateFloorKnown = boolval($candidateEnv['floor_known'] ?? false);
    if ($speakerFloorKnown && $candidateFloorKnown) {
        $speakerFloor = intval($speakerEnv['floor'] ?? 0);
        $candidateFloor = intval($candidateEnv['floor'] ?? 0);
        if ($candidateFloor > $speakerFloor) {
            return ['ok' => false, 'reason' => 'speaker_outdoors_candidate_higher_floor'];
        }
    }

    return ['ok' => true, 'reason' => 'both_outdoors'];
};

$participants = extractParticipantIdentities([
    'people' => $peopleRaw,
    'profile' => $incomingProfile,
    'speaker' => $previousSpeaker,
]);
$resolveParticipantName = static function (array $participantList, string $preferredName, string $preferredStorageId): string {
    $preferredName = normalizeParticipantNameToken($preferredName);
    $preferredStorageId = normalizeStorageIdToken($preferredStorageId);
    if ($preferredStorageId !== '') {
        foreach ($participantList as $participant) {
            if (!is_array($participant)) {
                continue;
            }
            $candidateStorageId = normalizeStorageIdToken(strval($participant['storage_id'] ?? ''));
            if ($candidateStorageId === '') {
                continue;
            }
            if (strcasecmp($candidateStorageId, $preferredStorageId) !== 0) {
                continue;
            }
            $candidateName = normalizeParticipantNameToken(strval($participant['name'] ?? ''));
            if ($candidateName !== '') {
                return $candidateName;
            }
        }
    }
    if ($preferredName !== '') {
        foreach ($participantList as $participant) {
            if (!is_array($participant)) {
                continue;
            }
            $candidateName = normalizeParticipantNameToken(strval($participant['name'] ?? ''));
            if ($candidateName === '') {
                continue;
            }
            if (strcasecmp($candidateName, $preferredName) === 0) {
                return $candidateName;
            }
        }
        return $preferredName;
    }
    return '';
};
$resolvedRechatTargetName = $resolveParticipantName(
    $participants,
    $requestedRechatTargetName,
    $requestedRechatTargetStorageId
);

$conversationScopeNames = [];
$pushConversationScopeName = static function (string $rawName) use (&$conversationScopeNames): void {
    $name = normalizeParticipantNameToken($rawName);
    if ($name === '') {
        return;
    }
    $conversationScopeNames[strtolower($name)] = $name;
};
foreach ($participants as $participant) {
    if (is_array($participant)) {
        $pushConversationScopeName(strval($participant['name'] ?? ''));
    } elseif (is_string($participant)) {
        $parsedIdentity = extractParticipantIdentityToken($participant);
        $pushConversationScopeName(strval($parsedIdentity['name'] ?? ''));
    }
}
$pushConversationScopeName($previousSpeaker);
$pushConversationScopeName($previousTarget);
$pushConversationScopeName($incomingProfile);
$pushConversationScopeName($initiatorName);
$pushConversationScopeName($resolvedRechatTargetName);

if ($normalizedEventType === 'limb_loss') {
    $limbLossParsed = stobeParseLimbLossEventData($eventData);
    $pushConversationScopeName(strval($limbLossParsed['victim'] ?? ''));
    $pushConversationScopeName(strval($limbLossParsed['attacker'] ?? ''));
}

$forcedLimbLossReaction = stobeResolvePendingLimbLossRechatReaction(array_values($conversationScopeNames), 300);
$forcedResponder = normalizeParticipantNameToken(strval($forcedLimbLossReaction['victim'] ?? ''));
if ($forcedResponder !== '') {
    if (($playerName !== '' && strcasecmp($forcedResponder, $playerName) === 0) || stobeIsNarratorName($forcedResponder)) {
        $forcedLimbLossReaction = [];
        $forcedResponder = '';
    }
}
if ($forcedResponder !== '') {
    stobeLogInfo('Rechat pending forced limb-loss reaction detected', [
        'rowid' => intval($forcedLimbLossReaction['rowid'] ?? 0),
        'victim' => $forcedResponder,
        'attacker' => strval($forcedLimbLossReaction['attacker'] ?? ''),
        'limb' => strval($forcedLimbLossReaction['limb'] ?? ''),
        'hacksaw' => boolval($forcedLimbLossReaction['hacksaw'] ?? false),
    ]);

    $forcedSuppression = stobeShouldSuppressLimbLossRechatForVictim(
        $forcedResponder,
        intval($forcedLimbLossReaction['rowid'] ?? 0),
        intval($forcedLimbLossReaction['localts'] ?? 0),
        false,
        3
    );
    if (boolval($forcedSuppression['suppress'] ?? false)) {
        stobeConsumeLimbLossRechatReaction(intval($forcedLimbLossReaction['rowid'] ?? 0));
        stobeLogInfo('Rechat forced limb-loss reaction canceled before selection', [
            'rowid' => intval($forcedLimbLossReaction['rowid'] ?? 0),
            'victim' => $forcedResponder,
            'reason' => strval($forcedSuppression['reason'] ?? ''),
            'event_type' => strval($forcedSuppression['event_type'] ?? ''),
            'event_rowid' => intval($forcedSuppression['event_rowid'] ?? 0),
            'state' => strval($forcedSuppression['state'] ?? ''),
        ]);
        $forcedLimbLossReaction = [];
        $forcedResponder = '';
    }
}

$resolvedInitiatorName = $resolveParticipantName(
    $participants,
    $initiatorName,
    $initiatorStorageId
);
$audienceNames = stobeNormalizeRechatActorList(array_values($conversationScopeNames));

/*
 * A direct player -> NPC chat already produced its NPC reply in chat.php.
 * Do not let the follow-up rechat daemon turn that private exchange into an
 * unsolicited nearby-NPC group conversation. Ambient NPC-initiated chatter is
 * unaffected, and forced limb-loss reactions are still allowed through.
 */
$initiatorIsPlayer = false;
if ($resolvedInitiatorName !== '') {
    $initiatorNpcData = getNpcData($resolvedInitiatorName);
    if (is_array($initiatorNpcData)) {
        $initiatorMetadataRaw = $initiatorNpcData['metadata'] ?? [];
        $initiatorMetadata = is_array($initiatorMetadataRaw)
            ? $initiatorMetadataRaw
            : json_decode(strval($initiatorMetadataRaw), true);
        if (is_array($initiatorMetadata)) {
            $initiatorIsPlayer = strtolower(trim(strval($initiatorMetadata['type'] ?? ''))) === 'player';
        }
    }
}
if (
    $forcedResponder === ''
    && $resolvedInitiatorName !== ''
    && (
        $initiatorIsPlayer
        || ($playerName !== '' && strcasecmp($resolvedInitiatorName, $playerName) === 0)
    )
) {
    stobeLogInfo('Rechat stopped after direct player conversation', [
        'initiator' => $resolvedInitiatorName,
        'previous_speaker' => $previousSpeaker,
        'previous_target' => $previousTarget,
        'requested_depth' => $requestedDepth,
    ]);
    echo "ok";
    return;
}

$speakerNpcData = getNpcData($previousSpeaker);
$speakerEnvironment = $extractEnvironment($speakerNpcData, $previousSpeaker);
$maxRoundsForDepth = getNpcProfileIntegerSetting(
    $speakerNpcData,
    ['RECHAT_RESPONSES'],
    '',
    3,
    1,
    12
);
$rechatProbability = getNpcProfileIntegerSetting(
    $speakerNpcData,
    ['RECHAT_PROBABILITY'],
    '',
    66,
    0,
    100
);

$chainParticipants = array_values($conversationScopeNames);
if (count($chainParticipants) > 1) {
    usort($chainParticipants, static function (string $a, string $b): int {
        return strcasecmp($a, $b);
    });
}
$rechatModeSeed = implode('|', [
    'initiator=' . strtolower($resolvedInitiatorName),
    'initiator_sid=' . strtolower($initiatorStorageId),
    'participants=' . strtolower(implode('|', $chainParticipants)),
    'request_mode=' . strtolower($requestMode),
]);
$configuredRechatMode = stobeGetConfiguredRechatMode();
$effectiveRechatMode = stobeResolveEffectiveRechatMode(
    $configuredRechatMode,
    array_merge(
        [$previousSpeaker, $previousTarget, $resolvedRechatTargetName, $incomingProfile, $resolvedInitiatorName],
        $audienceNames
    ),
    $rechatModeSeed
);
$chainSeed = implode('|', [
    'initiator=' . strtolower($resolvedInitiatorName),
    'initiator_sid=' . strtolower($initiatorStorageId),
    'profile=' . strtolower($incomingProfile),
    'participants=' . strtolower(implode('|', $chainParticipants)),
    'mode=' . strtolower($requestMode),
]);

$calculateRechatChainBudget = static function (int $maxRounds, int $probability, string $seed): int {
    $maxRounds = max(1, min(12, $maxRounds));
    $probability = max(0, min(100, $probability));

    if ($probability >= 100) {
        return $maxRounds;
    }
    if ($probability <= 0) {
        return 1;
    }

    $budget = 0;
    for ($depth = 1; $depth <= $maxRounds; $depth++) {
        $depthHash = hash('sha256', $seed . '|depth=' . $depth);
        $roll = hexdec(substr($depthHash, 0, 8)) / 4294967295.0;
        $rollPercent = $roll * 100.0;
        if ($rollPercent <= floatval($probability)) {
            $budget++;
        } else {
            break;
        }
    }

    if ($budget < 1) {
        $budget = 1;
    }
    if ($budget > $maxRounds) {
        $budget = $maxRounds;
    }
    return $budget;
};

$effectiveRechatMaxDepth = $maxRoundsForDepth;
if ($requestedDepth > 0) {
    $effectiveRechatMaxDepth = $calculateRechatChainBudget(
        $maxRoundsForDepth,
        $rechatProbability,
        $chainSeed
    );
}

$forcedReactionActive = ($forcedResponder !== '');
if (!$forcedReactionActive && $requestedDepth > 0 && $requestedDepth > $effectiveRechatMaxDepth) {
    stobeLogInfo('Rechat skipped: depth budget reached', [
        'previous_speaker' => $previousSpeaker,
        'requested_depth' => $requestedDepth,
        'max_rounds' => $maxRoundsForDepth,
        'effective_max_depth' => $effectiveRechatMaxDepth,
        'rechat_probability' => $rechatProbability,
        'chain_seed' => substr($chainSeed, 0, 180),
    ]);
    echo "ok";
    return;
}
$isFinalRechatTurn = ($requestedDepth > 0 && $requestedDepth >= $effectiveRechatMaxDepth);

$enginePath = $GLOBALS["ENGINE_PATH"] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($enginePath . 'connector/llm_dispatcher.php');

$responderCandidates = stobeBuildRechatResponderCandidates(
    $effectiveRechatMode,
    $previousSpeaker,
    $previousTarget,
    $resolvedRechatTargetName,
    $incomingProfile,
    $audienceNames,
    $playerName,
    $speakerRechatEnabled,
    $resolvedInitiatorName,
    $forcedReactionActive ? $forcedResponder : ''
);
if ($effectiveRechatMode === 'group') {
    $appendDirectFallbackCandidate = static function (string $rawName, string $source) use (
        &$responderCandidates,
        $previousSpeaker,
        $playerName,
        $speakerRechatEnabled,
        $resolvedInitiatorName
    ): void {
        $candidateName = normalizeParticipantNameToken($rawName);
        if ($candidateName === '' || stobeIsNarratorName($candidateName)) {
            return;
        }
        if ($previousSpeaker !== '' && strcasecmp($candidateName, $previousSpeaker) === 0) {
            return;
        }
        if ($playerName !== '' && strcasecmp($candidateName, $playerName) === 0) {
            return;
        }
        if (
            !$speakerRechatEnabled
            && $resolvedInitiatorName !== ''
            && strcasecmp($candidateName, $resolvedInitiatorName) === 0
        ) {
            return;
        }
        foreach ($responderCandidates as $existingCandidate) {
            $existingName = normalizeParticipantNameToken(strval($existingCandidate['name'] ?? ''));
            if ($existingName !== '' && strcasecmp($existingName, $candidateName) === 0) {
                return;
            }
        }
        $responderCandidates[] = [
            'name' => $candidateName,
            'source' => $source,
        ];
    };

    $appendDirectFallbackCandidate($resolvedRechatTargetName, 'group_direct_rechat_target');
    $appendDirectFallbackCandidate($previousTarget, 'group_direct_previous_target');
    $appendDirectFallbackCandidate($incomingProfile, 'group_direct_incoming_profile');
}

$respondingNpc = '';
$npcData = false;
$selectionSource = '';
$skipCounts = [
    'missing_data' => 0,
    'incapacitated' => 0,
    'ineligible' => 0,
    'environment_mismatch' => 0,
];
$skipSamples = [];
$selectionOrder = [];

foreach ($responderCandidates as $candidate) {
    $candidateName = normalizeParticipantNameToken(strval($candidate['name'] ?? ''));
    $candidateSource = trim(strval($candidate['source'] ?? ''));
    if ($candidateName === '') {
        continue;
    }
    if ($candidateSource === '') {
        $candidateSource = 'unknown';
    }
    $isForcedLimbCandidate = (
        $forcedReactionActive
        && $forcedResponder !== ''
        && strcasecmp($candidateSource, 'forced_limb_loss') === 0
        && strcasecmp($candidateName, $forcedResponder) === 0
    );

    $selectionOrder[] = $candidateName . ':' . $candidateSource;

    $candidateData = getNpcData($candidateName);
    if (!$candidateData) {
        storeNpcProfile($candidateName, []);
        $candidateData = getNpcData($candidateName);
    } elseif (npcNeedsBootstrap($candidateData)) {
        storeNpcProfile($candidateName, []);
        $candidateData = getNpcData($candidateName) ?: $candidateData;
    }
    if (!$candidateData) {
        $skipCounts['missing_data']++;
        if (count($skipSamples) < 6) {
            $skipSamples[] = $candidateName . ':missing_data';
        }
        continue;
    }

    if (stobeNpcIsIncapacitatedForRechat($candidateData)) {
        $skipCounts['incapacitated']++;
        if (count($skipSamples) < 6) {
            $stateLabel = strtolower(trim(strval($candidateData['character_state'] ?? 'unknown')));
            if ($stateLabel === '') {
                $stateLabel = 'unknown';
            }
            $skipSamples[] = $candidateName . ':incapacitated_' . $stateLabel;
        }
        continue;
    }

    if (!$isForcedLimbCandidate && !isRechatEligible($candidateData, $campaign, $requestedDepth)) {
        $skipCounts['ineligible']++;
        if (count($skipSamples) < 6) {
            $skipSamples[] = $candidateName . ':ineligible';
        }
        continue;
    }

    $candidateEnvironment = $extractEnvironment($candidateData, $candidateName);
    $environmentCompatibility = $isEnvironmentCompatible($speakerEnvironment, $candidateEnvironment);
    if (!$isForcedLimbCandidate && !boolval($environmentCompatibility['ok'] ?? false)) {
        $skipCounts['environment_mismatch']++;
        if (count($skipSamples) < 6) {
            $skipSamples[] = $candidateName . ':environment_' . strval($environmentCompatibility['reason'] ?? 'mismatch');
        }
        continue;
    }

    $respondingNpc = $candidateName;
    $npcData = $candidateData;
    $selectionSource = $candidateSource;
    break;
}

if ($respondingNpc === '' || !$npcData) {
    if ($forcedReactionActive) {
        stobeConsumeLimbLossRechatReaction(intval($forcedLimbLossReaction['rowid'] ?? 0));
    }
    stobeLogInfo('Rechat skipped: no valid responder after mode-based selection', [
        'previous_speaker' => $previousSpeaker,
        'previous_target' => $previousTarget,
        'requested_profile' => $incomingProfile,
        'requested_rechat_target' => $requestedRechatTargetName,
        'requested_rechat_target_sid' => $requestedRechatTargetStorageId,
        'resolved_rechat_target' => $resolvedRechatTargetName,
        'configured_rechat_mode' => $configuredRechatMode,
        'effective_rechat_mode' => $effectiveRechatMode,
        'forced_limb_loss_rowid' => intval($forcedLimbLossReaction['rowid'] ?? 0),
        'forced_limb_loss_victim' => $forcedResponder,
        'selection_order' => $selectionOrder,
        'skip_counts' => $skipCounts,
        'skip_samples' => $skipSamples,
        'speaker_environment' => $speakerEnvironment,
    ]);
    echo "ok";
    return;
}

if ($forcedReactionActive && strcasecmp($respondingNpc, $forcedResponder) !== 0) {
    stobeConsumeLimbLossRechatReaction(intval($forcedLimbLossReaction['rowid'] ?? 0));
    stobeLogInfo('Rechat forced limb-loss responder unavailable; used fallback responder', [
        'forced_limb_loss_rowid' => intval($forcedLimbLossReaction['rowid'] ?? 0),
        'forced_limb_loss_victim' => $forcedResponder,
        'fallback_responder' => $respondingNpc,
        'fallback_source' => $selectionSource,
        'selection_order' => $selectionOrder,
        'skip_counts' => $skipCounts,
        'skip_samples' => $skipSamples,
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
$historyAliases = stobeResolveNpcEventHistoryAliases($npcData, $respondingNpc);
$eventHistory = DataEventLog($contextHistory, $respondingNpc, $campaign, $historyAliases);
$eventHistory = stobeFilterNarratorRowsForContext($eventHistory, $respondingNpc, 'rechat');
if (is_array($npcData)) {
    $npcData = stobeAttachRecentCombatPromptEvents($npcData, $eventHistory, intval($gamets));
    $npcData = stobeAttachFightSoFarEvents($npcData, $respondingNpc, intval($gamets), $historyAliases); // Item 91
}
$historyLines = [];
foreach (array_reverse($eventHistory) as $row) {
    $line = stobeFormatEventHistoryLine($row, true);
    if ($line === '') {
        continue;
    }
    $historyLines[] = $line;
}
$historyText = implode("\n", $historyLines);
$historyMessages = stobeBuildRecentContextMessages(
    $eventHistory,
    intval($gamets),
    64,
    $respondingNpc
);
$memoryContextMessages = stobeBuildMemoryEventContextMessages(
    is_array($npcData) ? $npcData : [],
    $respondingNpc,
    $previousMessage,
    intval($gamets)
);

$rechatSpecialContext = [];
$hasLimbLossSpecialContext = false;
if (
    $forcedReactionActive
    && $forcedResponder !== ''
    && strcasecmp($respondingNpc, $forcedResponder) === 0
) {
    $rechatSpecialContext = [
        'mode' => 'limb_loss_reaction',
        'victim' => $forcedResponder,
        'attacker' => strval($forcedLimbLossReaction['attacker'] ?? ''),
        'limb' => strval($forcedLimbLossReaction['limb'] ?? ''),
        'weapon' => strval($forcedLimbLossReaction['weapon'] ?? ''),
        'hacksaw' => boolval($forcedLimbLossReaction['hacksaw'] ?? false),
        'rowid' => intval($forcedLimbLossReaction['rowid'] ?? 0),
        'localts' => intval($forcedLimbLossReaction['localts'] ?? 0),
    ];
    $hasLimbLossSpecialContext = true;
}
if ($isFinalRechatTurn) {
    $rechatSpecialContext['final_turn'] = true;
    $rechatSpecialContext['current_depth'] = $requestedDepth;
    $rechatSpecialContext['max_depth'] = $effectiveRechatMaxDepth;
}
$strictRechatListener = '';
if (!$hasLimbLossSpecialContext && stobeIsStrictRechatResponseEnabled()) {
    $strictRechatListener = $previousSpeaker;
}

$systemPrompt = buildRechatSystemPrompt(
    $respondingNpc,
    is_array($npcData) ? $npcData : [],
    $previousSpeaker,
    $previousMessage,
    $previousTarget,
    intval($gamets),
    $rechatSpecialContext,
    $strictRechatListener
);
$gameTimePrompt = stobeBuildGameTimePromptBlock($gamets, is_array($npcData) ? $npcData : []);
if ($gameTimePrompt !== '') {
    $systemPrompt .= "\n\n" . $gameTimePrompt;
}
$nearbyPartyPrompt = stobeBuildNearbyPlayerFactionPartyPrompt($npcData, $respondingNpc);
if ($nearbyPartyPrompt !== '') {
    $systemPrompt .= "\n\n" . $nearbyPartyPrompt;
}
$userLine = stobeBuildRechatPromptContent($previousSpeaker, $previousTarget, $previousMessage);

$compactHistory = stobeApplyCompactChatHistory(
    $systemPrompt,
    $historyMessages,
    $respondingNpc,
    stobeShouldCompactChatHistory($respondingNpc),
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
    'content' => $userLine,
];
$messages[] = [
    'role' => 'user',
    'content' => stobeBuildTurnGuidanceUserPrompt(
        $respondingNpc,
        $previousSpeaker,
        $isFinalRechatTurn,
        false,
        '',
        $strictRechatListener
    ),
];
$messages[] = [
    'role' => 'user',
    'content' => stobeBuildOutputContractUserPrompt(
        $respondingNpc,
        false,
        false,
        npcIsInPlayerFaction($npcData),
        'rechat',
        $strictRechatListener,
        $npcData
    ),
];

$llmConfig = getLlmConfigForNpc($npcData);
$actionConfig = stobeBuildActionConfigForNpc('rechat', $npcData);

$responseText = '';
$responseActions = [];
$responseListener = '';
$alreadyStreamed = false;
$structuredJson = false;
$actionsStreamedInLlm = false;
if (trim(strval($llmConfig['api_key'] ?? '')) === '') {
    stobeLogWarn('Rechat skipped: missing API key', ['responding_npc' => $respondingNpc]);
    echo "ok";
    return;
}

$tentativeStreamListener = $strictRechatListener;
if ($tentativeStreamListener === '') {
    $tentativeStreamListener = $previousSpeaker;
}
if ($tentativeStreamListener === '') {
    $tentativeStreamListener = $previousTarget;
}
if ($tentativeStreamListener === '') {
    $tentativeStreamListener = $playerName;
}
$suppressInitiatorTts = stobeShouldSuppressRechatInitiatorTts(
    $respondingNpc,
    $resolvedInitiatorName,
    getSettingBool('PLAYER_DIALOGUE_AUDIO_ENABLED', true)
);

$streamResult = stobeStreamDialogueViaLlm(
    $respondingNpc,
    $npcData,
    $messages,
    $llmConfig,
    'rechat',
    [
        'npc_name' => $respondingNpc,
        'event_type' => 'rechat',
        'action_config' => $actionConfig,
        'stream_event_type' => 'rechat',
        'stream_listener' => $tentativeStreamListener,
        'stream_gamets' => $gamets,
        'suppress_tts' => $suppressInitiatorTts,
        'response_format' => stobeBuildStructuredDialogueResponseFormat(
            $respondingNpc,
            $npcData,
            npcIsInPlayerFaction($npcData),
            'rechat',
            $strictRechatListener
        ),
    ]
);

if (boolval($streamResult['ok'] ?? false)) {
    $responseText = sanitizeForKenshi(trim(strval($streamResult['response_text'] ?? '')));
    $responseActions = is_array($streamResult['actions'] ?? null) ? $streamResult['actions'] : [];
    $responseListener = normalizeParticipantNameToken(strval($streamResult['listener'] ?? ''));
    $alreadyStreamed = intval($streamResult['chunks_emitted'] ?? 0) > 0;
    $structuredJson = boolval($streamResult['structured_json'] ?? false);
    $actionsStreamedInLlm = boolval($streamResult['actions_streamed'] ?? false);
} else {
    stobeLogWarn('Rechat LLM stream failed', ['responding_npc' => $respondingNpc]);
    echo "ok";
    return;
}
$responseActions = stobeDedupeActionList($responseActions, 'rechat', $actionConfig);
$defaultReplyTarget = $previousSpeaker;
if ($defaultReplyTarget === '') {
    $defaultReplyTarget = $previousTarget;
}
if ($defaultReplyTarget === '') {
    $defaultReplyTarget = $playerName;
}

$listenerCandidates = array_values($conversationScopeNames);
$listenerCandidates[] = $previousSpeaker;
$listenerCandidates[] = $previousTarget;
$listenerCandidates[] = $playerName;
$listenerCandidates[] = $resolvedRechatTargetName;
$listenerCandidates[] = $respondingNpc;
foreach ($audienceNames as $candidateName) {
    $listenerCandidates[] = $candidateName;
}
$replyTarget = stobeResolveRechatReplyTarget(
    $responseListener,
    $listenerCandidates,
    $defaultReplyTarget,
    $strictRechatListener,
    $respondingNpc
);
if ($replyTarget === '') {
    $replyTarget = $defaultReplyTarget;
}

$relationshipEval = stobeEvaluateRelationshipsForTurn(
    $respondingNpc,
    $replyTarget,
    trim($previousSpeaker . ': ' . $previousMessage),
    $responseText,
    $npcData,
    'rechat'
);
$responseText = sanitizeForKenshi(trim(strval($relationshipEval['clean_response'] ?? $responseText)));
if (!stobeInlineNarrationApplies($respondingNpc, 'rechat')) {
    $responseText = stobeStripParentheticalDialogueText($responseText);
}

if ($responseText === '' && count($responseActions) === 0) {
    echo "ok";
    return;
}

if ($hasLimbLossSpecialContext) {
    $lateSuppression = stobeShouldSuppressLimbLossRechatForVictim(
        $respondingNpc,
        intval($rechatSpecialContext['rowid'] ?? 0),
        intval($rechatSpecialContext['localts'] ?? 0),
        is_array($npcData) ? $npcData : false,
        3
    );
    if (boolval($lateSuppression['suppress'] ?? false)) {
        stobeConsumeLimbLossRechatReaction(intval($rechatSpecialContext['rowid'] ?? 0));
        stobeLogInfo('Rechat forced limb-loss reaction canceled before event write', [
            'rowid' => intval($rechatSpecialContext['rowid'] ?? 0),
            'victim' => $respondingNpc,
            'reason' => strval($lateSuppression['reason'] ?? ''),
            'event_type' => strval($lateSuppression['event_type'] ?? ''),
            'event_rowid' => intval($lateSuppression['event_rowid'] ?? 0),
            'state' => strval($lateSuppression['state'] ?? ''),
        ]);
        echo "ok";
        return;
    }
}

$responseEventType = 'rechat';
storeActionEvents($respondingNpc, $responseActions, $gamets, $replyTarget, $responseEventType);
if ($hasLimbLossSpecialContext) {
    stobeConsumeLimbLossRechatReaction(intval($rechatSpecialContext['rowid'] ?? 0));
}

stobeLogInfo('Rechat response generated', [
    'responding_npc' => $respondingNpc,
    'previous_speaker' => $previousSpeaker,
    'target' => $replyTarget,
    'configured_rechat_mode' => $configuredRechatMode,
    'effective_rechat_mode' => $effectiveRechatMode,
    'strict_listener' => $strictRechatListener,
    'speaker_environment' => $speakerEnvironment,
    'responder_environment' => $extractEnvironment($npcData, $respondingNpc),
    'response_length' => strlen($responseText),
    'structured_json' => $structuredJson,
    'actions_count' => count($responseActions),
    'actions' => $responseActions,
    'already_streamed' => $alreadyStreamed,
    'actions_streamed' => $actionsStreamedInLlm,
    'forced_limb_loss' => $hasLimbLossSpecialContext,
    'forced_limb_loss_rowid' => intval($rechatSpecialContext['rowid'] ?? 0),
    'is_final_turn' => $isFinalRechatTurn,
    'requested_depth' => $requestedDepth,
    'effective_max_depth' => $effectiveRechatMaxDepth,
    'rechat_probability' => $rechatProbability,
]);

if ($alreadyStreamed) {
    if (count($responseActions) > 0 && !$actionsStreamedInLlm) {
        streamResponse($respondingNpc, 'ScriptQueue', '', $npcData, $responseActions, 'rechat', $replyTarget, $gamets);
    }
} else {
    stobeStreamDialogueResponse(
        $respondingNpc,
        $npcData,
        $responseText,
        $responseActions,
        'rechat',
        $replyTarget,
        intval($gamets),
        ['suppress_tts' => $suppressInitiatorTts]
    );
}
