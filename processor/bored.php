<?php

/**
 * Bored processor - spontaneous nearby NPC dialogue trigger.
 */

storeEvent($eventType, $timestamp, $gamets, $eventData);

$campaign = 'Default';
$requestMode = strtolower(trim(strval($_GET['mode'] ?? '')));
$forceDirectorMode = ($requestMode === 'director');
$forceDirectiveTurn = false; // goal reports: skip the chance gate, no director (bug 66)
$playerName = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
$incomingProfile = normalizeParticipantNameToken(trim(strval($_GET['profile'] ?? '')));
$peopleRaw = strval($GLOBALS["CACHE_PEOPLE"] ?? ($_GET['people'] ?? ''));

$participants = extractParticipantNames([
    'people' => $peopleRaw,
    'profile' => $incomingProfile,
]);

$candidateNames = [];
$seen = [];
$pushCandidate = static function (string $candidate) use (&$candidateNames, &$seen, $playerName): void {
    $name = normalizeParticipantNameToken($candidate);
    if ($name === '') {
        return;
    }
    if ($playerName !== '' && strcasecmp($name, $playerName) === 0) {
        return;
    }
    $key = strtolower($name);
    if (isset($seen[$key])) {
        return;
    }
    $seen[$key] = true;
    $candidateNames[] = $name;
};

if ($incomingProfile !== '') {
    $pushCandidate($incomingProfile);
}
if (count($participants) > 1) {
    shuffle($participants);
}
foreach ($participants as $participant) {
    $pushCandidate(strval($participant));
}

if (count($candidateNames) === 0) {
    stobeLogInfo('Bored event skipped: no NPC candidates', [
        'event_type' => $eventType,
        'profile' => $incomingProfile,
        'people' => $peopleRaw,
    ]);
    echo "ok";
    return;
}

$speakerNpc = '';
$speakerData = false;
foreach ($candidateNames as $candidateName) {
    $candidateData = getNpcData($candidateName);
    if (!$candidateData) {
        storeNpcProfile($candidateName, []);
        $candidateData = getNpcData($candidateName);
    } elseif (npcNeedsBootstrap($candidateData)) {
        storeNpcProfile($candidateName, []);
        $candidateData = getNpcData($candidateName) ?: $candidateData;
    }
    if (!$candidateData) {
        continue;
    }
    $speakerNpc = $candidateName;
    $speakerData = $candidateData;
    break;
}

// Negotiation directives (surrender offers, help requests, settlements, truce
// re-issues, betrayal, breach reactions) take over this spontaneous turn.
if (function_exists('stobeGoalReportQueuePending')) {
    try { stobeGoalReportQueuePending(); } catch (Throwable $e) { stobeLogWarn('Goal report queue failed', ['error' => $e->getMessage()]); }
}
$negDirective = function_exists('stobeNegClaimDirective') ? stobeNegClaimDirective($candidateNames) : null;
if (is_array($negDirective)) {
    $negSpeakerData = getNpcData(strval($negDirective['npc_name']));
    if (is_array($negSpeakerData)) {
        $speakerNpc = strval($negDirective['npc_name']);
        $speakerData = $negSpeakerData;
        if (strval($negDirective['kind'] ?? '') === 'goal_report') {
            $forceDirectiveTurn = true;
            $forceDirectorMode = false; // the DLL may have asked for director mode (bug 72)
        } else {
            $forceDirectorMode = true;
        }
        stobeLogInfo('Bored event taken by negotiation directive', ['speaker'=>$speakerNpc, 'kind'=>$negDirective['kind']]);
    } else {
        $negDirective = null;
    }
}

if ($speakerNpc === '' || !$speakerData) {
    stobeLogInfo('Bored event skipped: speaker profile unavailable', [
        'candidate_count' => count($candidateNames),
    ]);
    echo "ok";
    return;
}

$boredChance = getNpcProfileIntegerSetting(
    is_array($speakerData) ? $speakerData : [],
    ['BORED_EVENT_CHANCE', 'BORED_EVENT'],
    'BORED_EVENT_CHANCE',
    50,
    0,
    100
);
$initiativeScore = 0;
if (function_exists('stobeLifelikeFetchEvents') && function_exists('stobeLifelikeEventScore')) {
    foreach (array_slice(stobeLifelikeFetchEvents($speakerNpc, 24), 0, 12) as $initiativeRow) {
        if (!is_array($initiativeRow)) {
            continue;
        }
        $initiativeScore = max($initiativeScore, stobeLifelikeEventScore($initiativeRow));
    }
    if ($initiativeScore >= 85) {
        $boredChance = max($boredChance, 95);
    } elseif ($initiativeScore >= 65) {
        $boredChance = max($boredChance, 85);
    } elseif ($initiativeScore >= 45) {
        $boredChance = max($boredChance, 70);
    }
}
$roll = mt_rand(0, 99);
if (!$forceDirectorMode && !$forceDirectiveTurn && $roll >= $boredChance) {
    stobeLogInfo('Bored event skipped: chance gate', [
        'speaker' => $speakerNpc,
        'roll' => $roll,
        'chance' => $boredChance,
        'initiative_score' => $initiativeScore,
    ]);
    echo "ok";
    return;
}

$listener = '';
$dialogueData = parseDialogueEventData($eventData);
$suggestedTarget = normalizeParticipantNameToken(strval($dialogueData['target'] ?? ''));
if ($suggestedTarget !== '' &&
    strcasecmp($suggestedTarget, $speakerNpc) !== 0 &&
    ($forceDirectorMode || $playerName === '' || strcasecmp($suggestedTarget, $playerName) !== 0)) {
    $listener = $suggestedTarget;
}
if (is_array($negDirective) && $playerName !== '') {
    $listener = $playerName;
}
if ($listener === '') {
    $listeners = [];
    foreach ($candidateNames as $candidateName) {
        if (strcasecmp($candidateName, $speakerNpc) === 0) {
            continue;
        }
        $listeners[] = $candidateName;
    }
    if (count($listeners) > 0) {
        $listener = $listeners[array_rand($listeners)];
    }
}
if ($listener === '') {
    stobeLogInfo('Bored event skipped: no eligible NPC listener', [
        'speaker' => $speakerNpc,
        'candidate_count' => count($candidateNames),
    ]);
    echo "ok";
    return;
}

if ($forceDirectorMode) {
    require_once dirname(__DIR__) . '/lib/director_scene.php';
    try {
        stobeGenerateDirectorScene($candidateNames, $speakerNpc, $listener,
            (string)($_GET['direction'] ?? ''), (int)$gamets);
        return;
    } catch (Throwable $error) {
        // e.g. "No eligible Director cast" with only the player and one NPC:
        // speak a normal turn instead of dropping it (bug 72)
        stobeLogWarn('Director scene failed; normal turn instead', ['error' => $error->getMessage(), 'speaker' => $speakerNpc]);
        $forceDirectorMode = false;
    }
}

$cuePool = [
    'comment on the current location',
    'remark on the weather or atmosphere',
    'share a practical survival thought',
    'mention a rumor from nearby settlements',
    'reflect on recent dangers in the area',
    'make a quick comment about local factions',
    'talk about work, trade, or supplies',
    'share a short personal observation',
];
$cue = $cuePool[array_rand($cuePool)];

$contextHistory = getNpcProfileIntegerSetting(
    is_array($speakerData) ? $speakerData : [],
    ['CONTEXT_HISTORY'],
    '',
    30,
    10,
    120
);
$eventHistory = DataEventLog($contextHistory, $speakerNpc, $campaign);
$eventHistory = stobeFilterNarratorRowsForContext($eventHistory, $speakerNpc, 'bored');
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
    $speakerNpc
);
$memoryContextMessages = stobeBuildMemoryEventContextMessages(
    is_array($speakerData) ? $speakerData : [],
    $speakerNpc,
    $cue,
    intval($gamets)
);

$systemPrompt = stobeBuildGameTimePromptBlock($gamets, is_array($speakerData) ? $speakerData : [])
    . "\n\n"
    . buildSystemPrompt($speakerNpc, is_array($speakerData) ? $speakerData : [], $listener, '', false, 'bored', intval($gamets));
$nearbyPartyPrompt = stobeBuildNearbyPlayerFactionPartyPrompt($speakerData, $speakerNpc);
if ($nearbyPartyPrompt !== '') {
    $systemPrompt .= "\n\n" . $nearbyPartyPrompt;
}
$compactHistory = stobeApplyCompactChatHistory(
    $systemPrompt,
    $historyMessages,
    $speakerNpc,
    stobeShouldCompactChatHistory($speakerNpc),
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
$boredInstruction = function_exists('stobeBuildLifelikeBoredInstruction')
    ? stobeBuildLifelikeBoredInstruction(
        $speakerNpc,
        $listener,
        is_array($speakerData) ? $speakerData : [],
        intval($gamets)
    )
    : 'Start a brief spontaneous conversation to the listener about the current situation.';
if (is_array($negDirective)) {
    $boredInstruction = strval($negDirective['payload']['instruction'] ?? $boredInstruction);
    if (in_array(strval($negDirective['kind']), ['surrender','assist'], true)) {
        $messages[0]['content'] .= "\n\n" . stobeDealPromptBlock($speakerNpc, $speakerData, '');
    }
}
$messages[] = [
    'role' => 'user',
    'content' => "<bored_event_request>\n"
        . "  <speaker>" . stobePromptXmlEscape($speakerNpc) . "</speaker>\n"
        . "  <listener>" . stobePromptXmlEscape($listener) . "</listener>\n"
        . "  <instruction>" . stobePromptXmlEscape($boredInstruction) . "</instruction>\n"
        . "</bored_event_request>",
];
$messages[] = [
    'role' => 'user',
    'content' => stobeBuildTurnGuidanceUserPrompt($speakerNpc, $listener),
];
$messages[] = [
    'role' => 'user',
    'content' => stobeBuildOutputContractUserPrompt(
        $speakerNpc,
        false,
        false,
        npcIsInPlayerFaction($speakerData),
        'bored'
    ),
];

$llmConfig = getLlmConfigForNpc($speakerData);
$actionConfig = stobeBuildActionConfigForNpc('bored', $speakerData);
$enginePath = $GLOBALS["ENGINE_PATH"] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($enginePath . 'connector/llm_dispatcher.php');

if (trim(strval($llmConfig['api_key'] ?? '')) === '') {
    stobeLogWarn('Bored event skipped: missing API key', ['speaker' => $speakerNpc]);
    echo "ok";
    return;
}

$responseText = '';
$responseActions = [];
$alreadyStreamed = false;
$structuredJson = false;
$actionsStreamedInLlm = false;

$streamResult = stobeStreamDialogueViaLlm(
    $speakerNpc,
    $speakerData,
    $messages,
    $llmConfig,
    'bored',
    [
        'npc_name' => $speakerNpc,
        'event_type' => 'bored',
        'action_config' => $actionConfig,
        'stream_event_type' => 'bored',
        'stream_listener' => $listener, // bug 95: the turn's real addressee
        'stream_gamets' => $gamets,
        'defer_structured_stream' => is_array($negDirective),
        'response_format' => (is_array($negDirective) && in_array(strval($negDirective['kind']), ['surrender','assist'], true))
            ? stobeDealResponseFormat(stobeBuildStructuredDialogueResponseFormat($speakerNpc, $speakerData, false, 'bored'))
            : stobeBuildStructuredDialogueResponseFormat($speakerNpc, $speakerData, npcIsInPlayerFaction($speakerData), 'bored'),
    ]
);

if (boolval($streamResult['ok'] ?? false)) {
    $responseText = sanitizeForKenshi(trim(strval($streamResult['response_text'] ?? '')));
    $responseActions = is_array($streamResult['actions'] ?? null) ? $streamResult['actions'] : [];
    $alreadyStreamed = intval($streamResult['chunks_emitted'] ?? 0) > 0;
    $structuredJson = boolval($streamResult['structured_json'] ?? false);
    $actionsStreamedInLlm = boolval($streamResult['actions_streamed'] ?? false);
} else {
    stobeLogWarn('Bored event LLM stream failed', ['speaker' => $speakerNpc]);
    if (is_array($negDirective)) {
        // Nothing reached the game: put the negotiation directive back so it is not lost.
        $GLOBALS['db']->exec('UPDATE stobe_negotiation_directive SET consumed_unix=0 WHERE id=$1', [intval($negDirective['id'])]);
    }
    echo "ok";
    return;
}
$responseActions = stobeDedupeActionList($responseActions, 'bored', $actionConfig);
if (is_array($negDirective)) {
    $negOutcome = stobeNegCompleteDirective(
        $negDirective, strval($streamResult['raw_response'] ?? ''), $speakerNpc, $playerName,
        $speakerData, $responseText, $responseActions
    );
    $responseText = strval($negOutcome['text']);
    $responseActions = $negOutcome['actions'];
}
$relationshipEval = stobeEvaluateRelationshipsForTurn(
    $speakerNpc,
    $listener,
    $eventData,
    $responseText,
    $speakerData,
    'bored'
);
$responseText = sanitizeForKenshi(trim(strval($relationshipEval['clean_response'] ?? $responseText)));
if (!stobeInlineNarrationApplies($speakerNpc, 'bored')) {
    $responseText = stobeStripParentheticalDialogueText($responseText);
}

if ($responseText === '' && count($responseActions) === 0) {
    echo "ok";
    return;
}

storeActionEvents($speakerNpc, $responseActions, $gamets, $listener, 'bored');

stobeLogInfo('Bored event response generated', [
    'speaker' => $speakerNpc,
    'listener' => $listener,
    'force_director_mode' => $forceDirectorMode,
    'roll' => $roll,
    'chance' => $boredChance,
    'response_length' => strlen($responseText),
    'structured_json' => $structuredJson,
    'actions_count' => count($responseActions),
    'actions' => $responseActions,
    'already_streamed' => $alreadyStreamed,
    'actions_streamed' => $actionsStreamedInLlm,
]);

if ($alreadyStreamed) {
    if (count($responseActions) > 0 && !$actionsStreamedInLlm) {
        streamResponse($speakerNpc, 'ScriptQueue', '', $speakerData, $responseActions, 'bored', $listener, $gamets);
    }
} else {
    stobeStreamDialogueResponse(
        $speakerNpc,
        $speakerData,
        $responseText,
        $responseActions,
        'bored',
        $listener,
        intval($gamets)
    );
}
