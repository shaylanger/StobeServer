<?php

declare(strict_types=1);

try {
    require __DIR__ . '/../lib/bootstrap.php';
} catch (Throwable $exception) {
    $stderr = fopen('php://stderr', 'wb');
    $message = 'FAIL: bootstrap threw ' . get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL;
    if ($stderr !== false) {
        fwrite($stderr, $message);
        fclose($stderr);
    } else {
        echo $message;
    }
    exit(1);
}

function contractTestFail(string $message): void
{
    $stderr = fopen('php://stderr', 'wb');
    if ($stderr !== false) {
        fwrite($stderr, 'FAIL: ' . $message . PHP_EOL);
        fclose($stderr);
    } else {
        echo 'FAIL: ' . $message . PHP_EOL;
    }
    exit(1);
}

function contractAssertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        contractTestFail($message);
    }
}

function contractAssertSame(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        contractTestFail($message . ' (expected="' . $expected . '", actual="' . $actual . '")');
    }
}

function contractAssertSameList(array $expected, array $actual, string $message): void
{
    if ($expected !== $actual) {
        contractTestFail(
            $message
            . ' (expected=' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . ', actual=' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . ')'
        );
    }
}

function contractAssertOrderedFields(string $text, array $fields, string $message): void
{
    $previousPos = -1;
    foreach ($fields as $field) {
        $pos = strpos($text, $field);
        if ($pos === false) {
            contractTestFail($message . ' (missing field ' . $field . ')');
        }
        if ($pos <= $previousPos) {
            contractTestFail($message . ' (field out of order: ' . $field . ')');
        }
        $previousPos = $pos;
    }
}

$expectedFieldOrder = [
    'character',
    'listener',
    'message',
    'mood',
    'action',
    'target',
    'item',
    'lang',
    'amount',
];

$contractPrompt = stobeBuildOutputContractUserPrompt('Esata the Stone Golem', false, false, null, 'chat');
contractAssertOrderedFields(
    $contractPrompt,
    array_map(static fn(string $field): string => '"' . $field . '"', $expectedFieldOrder),
    'Structured dialogue prompt should keep Herika-style field ordering'
);

$responseFormat = stobeBuildStructuredDialogueResponseFormat('Esata the Stone Golem', false, null, 'chat');
contractAssertSame('json_schema', strval($responseFormat['type'] ?? ''), 'Structured response format should use json_schema');
$schemaProperties = is_array($responseFormat['json_schema']['schema']['properties'] ?? null)
    ? array_keys($responseFormat['json_schema']['schema']['properties'])
    : [];
contractAssertSameList($expectedFieldOrder, $schemaProperties, 'Schema property order should match prompt contract');
$requiredFields = is_array($responseFormat['json_schema']['schema']['required'] ?? null)
    ? $responseFormat['json_schema']['schema']['required']
    : [];
contractAssertSameList($expectedFieldOrder, $requiredFields, 'Schema required fields should match prompt contract');

$narrator = new Narrator();
$originalInlineNarrationMode = strval($narrator->get('inline_narration_mode') ?? 'disabled');
$narrator->set('inline_narration_mode', 'narrator');
$inlineResponseFormat = stobeBuildStructuredDialogueResponseFormat('Esata the Stone Golem', false, null, 'chat');
$inlineMessageDescription = strval(
    $inlineResponseFormat['json_schema']['schema']['properties']['message']['description'] ?? ''
);
contractAssertTrue(
    str_contains($inlineMessageDescription, 'begin with one brief third-person scene description in single asterisks'),
    'Enabled inline narration should be required by the structured response schema'
);
$inlineContractPrompt = stobeBuildOutputContractUserPrompt(
    'Esata the Stone Golem',
    false,
    false,
    null,
    'chat'
);
contractAssertTrue(
    str_contains($inlineContractPrompt, 'begin with one brief third-person scene description in single asterisks'),
    'Enabled inline narration should be required by the fallback JSON contract'
);
$narrator->set('inline_narration_mode', $originalInlineNarrationMode);

$herikaStyle = stobeParseStructuredDialogueResponse(
    '{"character":"Dagur","listener":"RANGROO","message":"Well met, traveler.","mood":"kindly","action":"Talk","target":"RANGROO","item":"","lang":"en","amount":0}',
    'chat'
);
contractAssertTrue(boolval($herikaStyle['is_structured'] ?? false), 'Herika-style JSON should parse as structured');
contractAssertSame('Dagur', trim(strval($herikaStyle['character'] ?? '')), 'Herika-style JSON should preserve character');
contractAssertSame('RANGROO', trim(strval($herikaStyle['listener'] ?? '')), 'Herika-style JSON should preserve listener');
contractAssertSame('Well met, traveler.', trim(strval($herikaStyle['message'] ?? '')), 'Herika-style JSON should preserve message');
contractAssertSame('en', trim(strval($herikaStyle['lang'] ?? '')), 'Herika-style JSON should preserve lang');

$legacyStyle = stobeParseStructuredDialogueResponse(
    '{"character":"Esata the Stone Golem","message":"State your purpose. Now.","listener":"Herika","mood":"irritated","action":"Talk"}',
    'chat'
);
contractAssertTrue(boolval($legacyStyle['is_structured'] ?? false), 'Legacy Stobe JSON should remain parseable');
contractAssertSame('Esata the Stone Golem', trim(strval($legacyStyle['character'] ?? '')), 'Legacy Stobe JSON should preserve character');
contractAssertSame('Herika', trim(strval($legacyStyle['listener'] ?? '')), 'Legacy Stobe JSON should preserve listener');
contractAssertSame('State your purpose. Now.', trim(strval($legacyStyle['message'] ?? '')), 'Legacy Stobe JSON should preserve message');

$arrayReply = ['character' => 'Beep', 'message' => 'Stay close.', 'listener' => 'Drifter', 'action' => 'FOLLOW', 'target' => 'Drifter'];
$objectReply = stobeParseStructuredDialogueResponse(json_encode($arrayReply), 'chat');
foreach ([[$arrayReply], ['response' => [$arrayReply]], [['data' => json_encode($arrayReply)]]] as $wrappedReply) {
    $parsedReply = stobeParseStructuredDialogueResponse(json_encode($wrappedReply), 'chat');
    contractAssertTrue($parsedReply === $objectReply, 'A single wrapped reply should preserve dialogue, listener and validated action');
}
$multipleReplies = stobeParseStructuredDialogueResponse(json_encode([$arrayReply, $arrayReply]), 'chat');
contractAssertSame('', $multipleReplies['message'], 'Multiple replies must not silently select a speaker');
contractAssertSame('', $multipleReplies['action_tag'], 'Multiple replies must not execute an arbitrary action');

$stopAttackTag = stobeBuildActionTagFromStructuredPayload(
    'StopAttack',
    'RANGROO',
    '',
    'It was a misunderstanding. Stand down.',
    'RANGROO'
);
contractAssertSame(
    'STOP_ATTACK@RANGROO',
    $stopAttackTag,
    'StopAttack should target the opposing faction through a named nearby actor'
);
contractAssertSame(
    '',
    stobeBuildActionTagFromStructuredPayload('StopAttack', '', '', 'Stand down.', 'RANGROO'),
    'StopAttack should be rejected when no opposing target is provided'
);
contractAssertSame(
    'STOP_ATTACK@RANGROO',
    normalizeActionTagToken('STOPATTACK@RANGROO', ['allowlist' => ['STOP_ATTACK']]),
    'StopAttack aliases should normalize to the targeted STOP_ATTACK command'
);
contractAssertSame(
    '',
    normalizeActionTagToken(
        'STOP_ATTACK@RANGROO',
        ['allowlist' => ['STOP_ATTACK'], 'disallow_stop_attack' => true]
    ),
    'StopAttack should be rejected outside combat'
);
$nonCombatContract = stobeResolveStructuredDialogueContractParts(
    'Gate Guard',
    ['metadata' => ['is_in_combat' => false, 'is_attacking' => false]],
    false,
    'chat'
);
contractAssertTrue(
    !in_array('StopAttack', $nonCombatContract['actions'] ?? [], true),
    'StopAttack should not be exposed to an NPC outside combat'
);
$combatContract = stobeResolveStructuredDialogueContractParts(
    'Gate Guard',
    ['metadata' => ['is_in_combat' => true]],
    false,
    'chat'
);
contractAssertTrue(
    in_array('StopAttack', $combatContract['actions'] ?? [], true),
    'StopAttack should be exposed to an NPC in combat'
);
$combatPrompt = stobeBuildOutputContractUserPrompt(
    'Gate Guard',
    false,
    false,
    false,
    'chat',
    '',
    ['metadata' => ['is_in_combat' => true]]
);
contractAssertTrue(
    str_contains($combatPrompt, 'action MUST be StopAttack'),
    'Combat dialogue prompt should forbid claiming a ceasefire without StopAttack'
);
contractAssertTrue(
    str_contains($combatPrompt, 'If this NPC refuses the ceasefire'),
    'Combat dialogue prompt should preserve the NPC choice to refuse a ceasefire'
);
$partialStructured = stobeParseStructuredDialogueResponse(
    '{"character":"Dagur","listener":"RANGROO","message":"Well met, traveler',
    'chat'
);
contractAssertTrue(boolval($partialStructured['is_structured'] ?? false), 'Partial structured JSON should still parse heuristically');
contractAssertSame('Dagur', trim(strval($partialStructured['character'] ?? '')), 'Partial structured JSON should preserve character');
contractAssertSame('RANGROO', trim(strval($partialStructured['listener'] ?? '')), 'Partial structured JSON should preserve listener');
contractAssertSame('Well met, traveler', trim(strval($partialStructured['message'] ?? '')), 'Partial structured JSON should expose message early');

$unusablePrefix = stobeParseStructuredDialogueResponse('{ "character":', 'chat');
contractAssertTrue(
    !boolval($unusablePrefix['is_structured'] ?? false),
    'A JSON prefix without dialogue should not parse as a structured response'
);
contractAssertTrue(
    !stobeStructuredStreamResponseIsUsable(
        '{ "character":',
        boolval($unusablePrefix['is_structured'] ?? false),
        trim(strval($unusablePrefix['message'] ?? ''))
    ),
    'A JSON prefix without dialogue should be rejected before streaming'
);
contractAssertTrue(
    stobeStructuredStreamResponseIsUsable(
        '{"character":"Dagur","listener":"RANGROO","message":"Well met, traveler',
        boolval($partialStructured['is_structured'] ?? false),
        trim(strval($partialStructured['message'] ?? ''))
    ),
    'A partial structured response with usable dialogue should remain compatible'
);
contractAssertTrue(
    stobeStructuredStreamResponseIsUsable('Plain text fallback.', false, 'Plain text fallback.'),
    'A plain-text provider fallback should remain compatible'
);

contractAssertTrue(stobeFindFastSentencePosition('Hello.') === 5, 'Completed first sentence should flush before next whitespace');
contractAssertTrue(stobeFindFastSentencePosition('The price is 3.') === false, 'Partial decimal should stay buffered');
$longClause = str_repeat('A natural opening phrase ', 4) . ', and the story continues without a period';
contractAssertTrue(stobeFindFastSentencePosition($longClause) !== false, 'Long natural clause should flush early');
// MoveTo must preserve exact identity and never accept invented coordinates.
$moveScene = ['extended_data' => normalizeCoreNpcExtendedData([
    'nearby_actors' => [['name' => 'Beep', 'refid' => 'hand_101']],
    'nearby_items' => [['name' => 'Sword', 'refid' => 'hand_202']],
    'points_of_interest' => [
        ['name' => 'Gate', 'refid' => 'hand_303'],
        ['name' => 'Gate', 'refid' => 'hand_304'],
    ],
])];
contractAssertSame('MOVE_TO@hand_303', stobeBuildActionTagFromStructuredPayload('MoveTo', 'hand_303', '', ''), 'MoveTo uses target');
contractAssertSame('', stobeBuildActionTagFromStructuredPayload('MoveTo', '', 'Gate', ''), 'MoveTo requires an explicit target');
contractAssertSame('MOVE_TO@hand_303', normalizeActionTagToken('MoveTo@hand_303'), 'MoveTo aliases normalize');
contractAssertSame('', normalizeActionTagToken('MoveTo@hand_303', ['allowlist' => ['FOLLOW']]), 'MoveTo respects the allowlist');
contractAssertSame('MOVE_TO@ref;101;Beep', stobeTransformActionForDispatch('MOVE_TO@Beep', $moveScene), 'Named NPC resolves to identity');
contractAssertSame('MOVE_TO@ref;202;Sword', stobeTransformActionForDispatch('MOVE_TO@hand_202', $moveScene), 'Ground object resolves');
contractAssertSame('MOVE_TO@ref;304;Gate', stobeTransformActionForDispatch('MOVE_TO@hand_304', $moveScene), 'Duplicate object names retain identity');
contractAssertTrue(str_starts_with(stobeTransformActionForDispatch('MOVE_TO@Gate', $moveScene), 'ROLEPLAY_ACTION@'), 'Ambiguous names fail');
contractAssertTrue(str_starts_with(stobeTransformActionForDispatch('MOVE_TO@hand_999', $moveScene), 'ROLEPLAY_ACTION@'), 'Unknown references fail');
contractAssertTrue(str_starts_with(stobeTransformActionForDispatch('MOVE_TO@point;1;2;3;here', $moveScene), 'ROLEPLAY_ACTION@'), 'Model coordinates fail');
$_GET['initiator_sid'] = '404';
contractAssertSame('MOVE_TO@ref;404;the speaker', stobeTransformActionForDispatch('MOVE_TO@player', $moveScene), 'Player alias binds the request initiator');
unset($_GET['initiator_sid']);
contractAssertTrue(str_starts_with(stobeTransformActionForDispatch('MOVE_TO@player', $moveScene), 'ROLEPLAY_ACTION@'), 'Missing initiator must not select another player');
$db->exec('BEGIN');
try {
    $db->exec("INSERT INTO location_zones (zone_name, city_name, x, y, z) VALUES ('MoveTo Test Gate', 'MoveTo Test City', 10, 20, 30)");
    contractAssertSame('MOVE_TO@point;10;20;30;MoveTo Test Gate', stobeTransformActionForDispatch('MOVE_TO@MoveTo Test Gate', $moveScene), 'Known point supplies server coordinates');
    contractAssertTrue(str_starts_with(stobeTransformActionForDispatch('MOVE_TO@MoveTo Test', $moveScene), 'ROLEPLAY_ACTION@'), 'Partial location names must not silently choose a point');
    $db->exec("INSERT INTO location_zones (zone_name, city_name, x, y, z) VALUES ('MoveTo Test Other Gate', 'MoveTo Test City', 40, 20, 30)");
    contractAssertTrue(str_starts_with(stobeTransformActionForDispatch('MOVE_TO@MoveTo Test City', $moveScene), 'ROLEPLAY_ACTION@'), 'Ambiguous known points fail');
} finally {
    $db->exec('ROLLBACK');
}

echo "PASS: structured dialogue contract regression\n";
