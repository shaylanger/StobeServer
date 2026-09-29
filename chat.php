<?php
require_once __DIR__ . '/lib/stobe_interaction.php';
stobeInteractionRequire();

require_once __DIR__ . "/lib/playthrough_guard.php";
pgr_http_preflight("chat_json");

/**
 * JSON chat endpoint used by the in-game ChatMenu async pipeline.
 * Request:  { npc, player, message, mode, gamets, nearby, context, npcs }
 * Response: { ok, npc, text, actions, mode }
 */

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
require($path . "lib/bootstrap.php");

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    return;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload']);
    return;
}

$targetNpc = trim(strval($payload['npc'] ?? ''));
$speaker = trim(strval($payload['player'] ?? getSetting('PLAYER_NAME', 'Drifter')));
$message = trim(strval($payload['message'] ?? ''));
$targetExtract = extractDialogueTarget($message);
$cleanedMessage = trim(strval($targetExtract['cleaned'] ?? ''));
if ($cleanedMessage !== '') {
    $message = $cleanedMessage;
}
$extractedTargetNpc = normalizeParticipantNameToken(strval($targetExtract['target'] ?? ''));
if ($targetNpc === '' && $extractedTargetNpc !== '') {
    $targetNpc = $extractedTargetNpc;
}
$sanitizeChatMessage = static function (string $value): string {
    $clean = sanitizeForKenshi(trim($value));
    if ($clean !== '' && function_exists('stobeSanitizeDialogueMessageForLog')) {
        $clean = stobeSanitizeDialogueMessageForLog($clean);
    }
    return trim($clean);
};
$message = $sanitizeChatMessage($message);
$mode = strtolower(trim(strval($payload['mode'] ?? 'talk')));
$allowedModes = ['talk', 'shout', 'whisper', 'autochat', 'cheat', 'narrator'];
if (!in_array($mode, $allowedModes, true)) {
    $mode = 'talk';
}
$narratorMode = ($mode === 'narrator');
$narratorName = stobeNarratorName();
if ($narratorMode) {
    if (!stobeNarratorModeEnabled()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Narrator mode is disabled']);
        return;
    }
    $targetNpc = $narratorName;
}
$gamets = intval($payload['gamets'] ?? 0);
$nearby = is_array($payload['nearby'] ?? null) ? $payload['nearby'] : [];
$context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
$npcs = is_array($payload['npcs'] ?? null) ? $payload['npcs'] : [];

if (function_exists('stobeHandlePotentialGametsRollback')) {
    stobeHandlePotentialGametsRollback($gamets, 'chat_json');
}

if ($targetNpc === '' || $message === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing npc or message']);
    return;
}

$speakerProfileName = normalizeParticipantNameToken($speaker);
if (!$narratorMode && $speakerProfileName !== '' && function_exists('stobeNpcCannotRespondInDirectChat')) {
    $speakerNpcData = getNpcData($speakerProfileName);
    if (is_array($speakerNpcData) && stobeNpcCannotRespondInDirectChat($speakerNpcData)) {
        $speakerState = function_exists('stobeResolveNpcAwarenessState')
            ? stobeResolveNpcAwarenessState($speakerNpcData)
            : strtolower(trim(strval($speakerNpcData['character_state'] ?? '')));
        stobeLogInfo('JSON chat rejected: speaker cannot speak in current state', [
            'speaker' => $speakerProfileName,
            'target_npc' => $targetNpc,
            'mode' => $mode,
            'state' => $speakerState,
            'gamets' => intval($gamets),
        ]);
        echo json_encode([
            'ok' => false,
            'error' => 'Speaker cannot speak while incapacitated.',
        ]);
        return;
    }
}

$ingestRows = [];
$ingestSource = 'chat_payload';
if (count($context) > 0) {
    $contextSnapshot = $context;
    if (!array_key_exists('name', $contextSnapshot) || trim(strval($contextSnapshot['name'])) === '') {
        $contextSnapshot['name'] = $targetNpc;
    }
    $ingestRows[] = [
        'kind' => 'context_target',
        'entry' => $contextSnapshot,
    ];
}
foreach ($nearby as $entry) {
    if (!is_array($entry)) {
        continue;
    }
    $ingestRows[] = [
        'kind' => 'nearby',
        'entry' => $entry,
    ];
}

$ingestAttempted = 0;
$ingestStored = 0;
$ingestRenamed = 0;
$ingestRenamedStored = 0;
$ingestRenamedFailed = 0;
foreach ($ingestRows as $row) {
    $entry = is_array($row['entry'] ?? null) ? $row['entry'] : [];
    $entryName = normalizeParticipantNameToken(strval($entry['name'] ?? ''));
    if ($entryName === '') {
        continue;
    }
    $entry['name'] = $entryName;
    $ingestAttempted++;
    $isRenamed = strpos($entryName, '[') !== false;
    if ($isRenamed) {
        $ingestRenamed++;
    }

    $storedSnapshot = storeNpcSnapshot($entry, max(0, $gamets));
    if ($storedSnapshot) {
        $ingestStored++;
        if ($isRenamed) {
            $ingestRenamedStored++;
        }
        continue;
    }

    if ($isRenamed) {
        $ingestRenamedFailed++;
        stobeLogImport('chat payload renamed snapshot ingest failed', [
            'name' => $entryName,
            'storage_id' => normalizeStorageIdToken($entry['storage_id'] ?? ''),
            'kind' => strval($row['kind'] ?? ''),
            'source' => $ingestSource,
            'keys' => array_keys($entry),
            'has_medical' => is_array($entry['medical'] ?? null) && count($entry['medical']) > 0,
            'has_stats' => is_array($entry['stats'] ?? null) && count($entry['stats']) > 0,
        ], 'WARN');
    }
}

if ($ingestAttempted > 0) {
    stobeLogImport('chat payload snapshot ingest summary', [
        'source' => $ingestSource,
        'attempted' => $ingestAttempted,
        'stored' => $ingestStored,
        'renamed' => $ingestRenamed,
        'renamed_stored' => $ingestRenamedStored,
        'renamed_failed' => $ingestRenamedFailed,
    ], $ingestRenamedFailed > 0 ? 'WARN' : 'DEBUG');
}

$participantIdentities = extractParticipantIdentities([
    'people' => $payload['people'] ?? [],
    'profile' => $targetNpc,
    'speaker' => $speaker,
    'npcs' => $npcs,
    'nearby' => $nearby,
]);
$ensureResult = ensureNpcProfilesFromParticipantIdentities($participantIdentities);

// Profile discovery can include remote NPCs; only the supplied audience owns events.
$GLOBALS['CACHE_PEOPLE'] = stobeAnnotatePeopleTokensWithNpcStates(
    stobeEncodePeopleTokenList(stobeEventAudienceTokens($payload['people'] ?? []))
);

stobeLogInfo('JSON chat request received', [
    'target_npc' => $targetNpc,
    'speaker' => $speaker,
    'mode' => $mode,
    'gamets' => $gamets,
    'nearby_count' => count($nearby),
    'npcs_count' => count($npcs),
    'participants_count' => intval($ensureResult['parsed'] ?? 0),
    'profiles_created' => intval($ensureResult['created'] ?? 0),
    'profiles_updated' => intval($ensureResult['updated'] ?? 0),
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
        stobeLogInfo('NPC profile JIT-created via chat.php', ['target_npc' => $targetNpc]);
    } elseif (npcNeedsBootstrap($npcData)) {
        storeNpcProfile($targetNpc, []);
        $npcData = getNpcData($targetNpc) ?: $npcData;
        stobeLogInfo('NPC profile baseline refreshed via chat.php', ['target_npc' => $targetNpc]);
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
        stobeLogInfo('JSON chat target remapped to canonical NPC name', [
            'requested_target_npc' => $targetNpc,
            'resolved_target_npc' => $canonicalTargetNpc,
        ]);
        $targetNpc = $canonicalTargetNpc;
    }
}

$contextHistory = getNpcProfileIntegerSetting(
    $npcData,
    ['CONTEXT_HISTORY'],
    '',
    50,
    10,
    250
);
$eventHistory = $narratorMode
    ? DataEventLog($contextHistory)
    : DataEventLog($contextHistory, $targetNpc);
$eventHistory = stobeFilterNarratorRowsForContext($eventHistory, $targetNpc, $mode, $speaker);
$historyLines = [];
foreach (array_reverse($eventHistory) as $row) {
    $line = stobeFormatEventHistoryLine(is_array($row) ? $row : [], false);
    if ($line !== '') {
        $historyLines[] = $line;
    }
}
$historyText = implode("\n", $historyLines);
$historyMessages = stobeBuildRecentContextMessages(
    $eventHistory,
    intval($gamets),
    64,
    $narratorMode ? '' : $targetNpc
);
$memoryContextMessages = stobeBuildMemoryEventContextMessages(
    is_array($npcData) ? $npcData : [],
    $targetNpc,
    $message,
    intval($gamets)
);

$enginePath = $GLOBALS["ENGINE_PATH"] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($enginePath . 'connector/openaijson.php');

if ($mode === 'autochat') {
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
        stobeLogInfo('JSON autochat rewrite generated', [
            'speaker' => $speaker,
            'target_npc' => $targetNpc,
            'model' => strval($rewriteResult['model'] ?? ''),
            'rewritten_length' => strlen($message),
        ]);
    } else {
        stobeLogInfo('JSON autochat rewrite fallback', [
            'speaker' => $speaker,
            'target_npc' => $targetNpc,
            'model' => strval($rewriteResult['model'] ?? ''),
            'reason' => strval($rewriteResult['error'] ?? 'unknown'),
        ]);
    }
} elseif ($mode === 'cheat') {
    stobeLogInfo('JSON cheat mode active', [
        'speaker' => $speaker,
        'target_npc' => $targetNpc,
        'message_length' => strlen($message),
    ]);
}

$eventType = 'inputtext';
$message = $sanitizeChatMessage($message);
$playerMoodCue = stobeResolvePlayerMoodCue($payload, $mode, $speaker);
$eventData = $speaker . ': ' . $message . $playerMoodCue . ' (talking to: ' . $targetNpc . ')';
storeEvent($eventType, time(), $gamets, $eventData);
if (!$narratorMode) {
    // Mirror player input as chat immediately so timeline order is stable even
    // when game-emitted chat events arrive later.
    storeEvent('chat', time() + 1, $gamets, $eventData, 'inputtext_chat_mirror');
}

$promptNpcData = $npcData;
if (is_array($promptNpcData) && count($nearby) > 0) {
    $extended = normalizeNpcExtendedDataPayload($promptNpcData['extended_data'] ?? []);
    $extended['nearby_actors'] = $nearby;
    $extended['nearby'] = $nearby;
    $promptNpcData['extended_data'] = $extended;
}

$systemPrompt = stobeBuildGameTimePromptBlock($gamets, $npcData)
    . "\n\n"
    . buildSystemPrompt(
        $targetNpc,
        $promptNpcData,
        $speaker,
        $message,
        !$narratorMode,
        'chat',
        intval($gamets)
    );
$nearbyPlayerAlliesPrompt = buildNearbyPlayerAlliesPrompt($nearby, $speaker);
if ($nearbyPlayerAlliesPrompt !== '') {
    $systemPrompt .= "\n\n" . $nearbyPlayerAlliesPrompt;
}
$deliveryStyleInstruction = '';
if ($mode === 'whisper') {
    $deliveryStyleInstruction = 'The player is whispering. Respond in a quiet, discreet tone.';
} elseif ($mode === 'shout') {
    $deliveryStyleInstruction = 'The player is shouting. Respond with urgency and stronger emotional intensity.';
} elseif ($mode === 'autochat') {
    $deliveryStyleInstruction = 'The player triggered a bored-event automatic chat. Keep responses brief and natural for overheard conversation.';
} elseif ($mode === 'narrator') {
    $deliveryStyleInstruction = 'You are The Narrator in a private one-on-one conversation. Reply directly to the current speaker as conversation. Never narrate scenes, atmosphere, or actions in this mode. Never include action tags.';
}
if ($deliveryStyleInstruction !== '') {
    $systemPrompt .= "\n\n<speech_mode>\n"
        . "  <mode>" . stobePromptXmlEscape($mode) . "</mode>\n"
        . "  <instruction>" . stobePromptXmlEscape($deliveryStyleInstruction) . "</instruction>\n"
        . "</speech_mode>";
}
$userMessage = stobeBuildPlayerInputPromptContent($speaker, $targetNpc, $message . $playerMoodCue);
if ($mode === 'cheat') {
    $priorityInstruction = "PRIORITY INSTRUCTION - {$targetNpc} must do this, even if it breaks character roleplay: {$message}";
    $systemPrompt .= "\n\n<cheatmode>\n"
        . "  <priority_instruction>" . stobePromptXmlEscape($priorityInstruction) . "</priority_instruction>\n"
        . "</cheatmode>";
    $userMessage = "<cheat_request>\n"
        . "  <speaker>" . stobePromptXmlEscape($speaker) . "</speaker>\n"
        . "  <target>" . stobePromptXmlEscape($targetNpc) . "</target>\n"
        . "  <request>" . stobePromptXmlEscape($message) . "</request>\n"
        . "</cheat_request>";
}
if (!empty($nearby) && stobePromptContextOptionEnabled('enabled_sections', 'nearby_context_json')) {
    $nearbyJson = json_encode($nearby, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($nearbyJson) && $nearbyJson !== '') {
        $systemPrompt .= "\n\n<nearby_context_json>"
            . stobePromptXmlEscape($nearbyJson)
            . "</nearby_context_json>";
    }
}
if (!empty($context) && stobePromptContextOptionEnabled('enabled_sections', 'detailed_context_json')) {
    $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($contextJson) && $contextJson !== '') {
        $systemPrompt .= "\n\n<detailed_context_json>"
            . stobePromptXmlEscape($contextJson)
            . "</detailed_context_json>";
    }
}

$compactHistory = stobeApplyCompactChatHistory(
    $systemPrompt,
    $historyMessages,
    $targetNpc,
    stobeShouldCompactChatHistory($targetNpc),
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
    'content' => $userMessage,
];
$messages[] = [
    'role' => 'user',
    'content' => $narratorMode
        ? stobeBuildNarratorDirectReplyGuidanceUserPrompt($speaker, $message)
        : stobeBuildTurnGuidanceUserPrompt(
            $targetNpc,
            $speaker,
            false,
            $mode === 'cheat',
            $mode === 'cheat' ? $message : ''
        ),
];
$messages[] = [
    'role' => 'user',
    'content' => $narratorMode
        ? 'Output contract: return only a direct conversational reply to the current speaker. Do not include scene narration, atmospheric description, third-person prose, or action tags.'
        : stobeBuildOutputContractUserPrompt($targetNpc, $mode === 'cheat', false, null, 'chat', '', $npcData),
];

$llmConfig = getLlmConfigForNpc($npcData);
$actionConfig = stobeBuildActionConfigForNpc('chat', $npcData);
if ($narratorMode) {
    $actionConfig['enabled'] = false;
    $actionConfig['max_actions'] = 1;
}

$responseText = '';
$structuredParsed = false;
if ($llmConfig['api_key'] === '') {
    $responseText = 'No OpenRouter API key configured yet.';
    stobeLogWarn('JSON chat LLM call skipped because API key is missing', ['target_npc' => $targetNpc]);
} else {
    $llmMeta = [
        'npc_name' => $targetNpc,
        'event_type' => 'chat',
        'speaker' => $speaker,
        'mode' => $mode,
    ];
    if (!$narratorMode) {
        $llmMeta['response_format'] = stobeBuildStructuredDialogueResponseFormat($targetNpc, $npcData, null, 'chat');
    }
    $rawResponse = callLLM($messages, $llmConfig, $llmMeta);
    if ($rawResponse === false || trim($rawResponse) === '') {
        $responseText = '...';
        stobeLogWarn('JSON chat LLM returned empty response', [
            'target_npc' => $targetNpc,
            'model' => $llmConfig['model'] ?? '',
        ]);
    } else {
        $structured = stobeParseStructuredDialogueResponse($rawResponse, 'chat');
        $structuredParsed = boolval($structured['is_structured'] ?? false);
        $responseText = sanitizeForKenshi(trim(strval($structured['message'] ?? '')));
        $structuredAction = trim(strval($structured['action_tag'] ?? ''));
        if ($structuredAction !== '') {
            $actions = [$structuredAction];
        } else {
            $actions = [];
        }
    }
}
$cleanText = sanitizeForKenshi(trim(strval($responseText)));
if (!isset($actions) || !is_array($actions)) {
    $actions = [];
}
if ($narratorMode) {
    $actions = [];
}
if ($cleanText === '' && count($actions) === 0) {
    $cleanText = '...';
}

$storedText = $cleanText;
if ($storedText === '' && count($actions) > 0) {
    $storedText = '[action issued]';
} elseif ($storedText === '') {
    $storedText = '...';
}

storeActionEvents($targetNpc, $actions, $gamets, $speaker, 'chat');
storeEvent('chat', time(), $gamets, $targetNpc . ': ' . $storedText);

stobeLogInfo('JSON chat response generated', [
    'target_npc' => $targetNpc,
    'mode' => $mode,
    'response_length' => strlen($cleanText),
    'structured_json' => $structuredParsed,
    'actions_count' => count($actions),
]);

echo json_encode(
    [
        'ok' => true,
        'npc' => $targetNpc,
        'text' => $cleanText,
        'actions' => $actions,
        'mode' => $mode,
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

