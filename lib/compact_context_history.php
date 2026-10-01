<?php

require_once __DIR__ . '/prompt_formatting.php';

/**
 * Compact Markdown formatting for recent NPC chat history.
 *
 * Current-turn prompts, retrieved memories, and response schemas remain
 * separate so enabling this setting only changes chronological history.
 */

function stobeCompactHistoryWhitespace(string $text): string
{
    return trim(strval(preg_replace('/\s+/u', ' ', $text)));
}

function stobeShouldCompactChatHistory(string $actorName): bool
{
    $actorName = trim($actorName);
    if ($actorName === '') {
        return false;
    }
    if (
        (function_exists('stobeIsNarratorName') && stobeIsNarratorName($actorName))
        || strcasecmp($actorName, 'The Narrator') === 0
    ) {
        return false;
    }
    return function_exists('getSettingBool')
        && getSettingBool('COMPACT_CHAT_HISTORY_ENABLED', true);
}

function stobeCompactHistoryDialogue(string $content, string $fallbackSpeaker = ''): string
{
    $content = trim($content);
    $speaker = stobeCompactHistoryWhitespace($fallbackSpeaker);
    $listener = '';
    $delivery = 'speaking';

    if (
        preg_match(
            '/\s*\((talking|whispering|shouting)\s+to:?\s*([^\)]+)\)\s*\.?\s*$/iu',
            $content,
            $targetMatch
        ) === 1
    ) {
        $deliveryToken = strtolower(stobeCompactHistoryWhitespace(strval($targetMatch[1] ?? 'talking')));
        $delivery = match ($deliveryToken) {
            'whispering' => 'whispering',
            'shouting' => 'shouting',
            default => 'speaking',
        };
        $listener = stobeCompactHistoryWhitespace(strval($targetMatch[2] ?? ''));
        $content = trim(strval(preg_replace(
            '/\s*\((?:talking|whispering|shouting)\s+to:?\s*[^\)]+\)\s*\.?\s*$/iu',
            '',
            $content
        )));
    }

    if (preg_match('/^([^:\r\n]{1,100}):\s*(.+)$/us', $content, $speakerMatch) === 1) {
        $speaker = stobeCompactHistoryWhitespace(strval($speakerMatch[1] ?? $speaker));
        $content = strval($speakerMatch[2] ?? '');
    }

    $content = stobeCompactHistoryWhitespace($content);
    if ($speaker === '') {
        return $content;
    }
    if ($listener !== '') {
        return "{$speaker}, {$delivery} to {$listener}: {$content}";
    }
    return "{$speaker}: {$content}";
}

function stobeCompactAssistantHistoryEntry(string $content, string $actorName): string
{
    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        return stobeCompactHistoryDialogue($content, $actorName);
    }

    $speaker = stobeCompactHistoryWhitespace(strval($decoded['character'] ?? $actorName));
    $listener = stobeCompactHistoryWhitespace(strval($decoded['listener'] ?? ''));
    $message = stobeCompactHistoryWhitespace(strval($decoded['message'] ?? ''));
    $action = stobeCompactHistoryWhitespace(strval($decoded['action'] ?? ''));
    $target = stobeCompactHistoryWhitespace(strval($decoded['target'] ?? ''));

    $line = $listener !== '' ? "{$speaker}, speaking to {$listener}" : $speaker;
    if ($message !== '') {
        $line .= ': ' . $message;
    }
    if ($action !== '' && strcasecmp($action, 'Talk') !== 0 && strcasecmp($action, 'JustTalk') !== 0) {
        $line .= ' [Action: ' . $action . ($target !== '' ? ", targeting {$target}" : '') . ']';
    }
    return trim($line);
}

function stobeCompactUserHistoryEntry(string $content): string
{
    $content = trim($content);
    if (preg_match('/^\(\.\.\.\s*(.*?)\s*\.\.\.\)$/us', $content, $ambientMatch) === 1) {
        $content = trim(strval($ambientMatch[1] ?? ''));
    }

    if (
        preg_match(
            '/\((?:talking|whispering|shouting)\s+to:?\s*[^\)]+\)\s*\.?\s*$/iu',
            $content
        ) === 1
    ) {
        return stobeCompactHistoryDialogue($content);
    }

    return stobeCompactHistoryWhitespace($content);
}

function stobeCompactToolHistoryEntry(array $entry): string
{
    $content = $entry['content'] ?? '';
    if (is_string($content) || is_scalar($content)) {
        $content = stobeCompactHistoryWhitespace(strval($content));
        if ($content !== '') {
            return 'Tool result: ' . $content;
        }
    }

    $toolCalls = $entry['tool_calls'] ?? [];
    if (!is_array($toolCalls)) {
        return '';
    }

    $calls = [];
    foreach ($toolCalls as $toolCall) {
        if (!is_array($toolCall) || !is_array($toolCall['function'] ?? null)) {
            continue;
        }
        $name = stobeCompactHistoryWhitespace(strval($toolCall['function']['name'] ?? ''));
        if ($name !== '') {
            $calls[] = $name;
        }
    }
    return count($calls) > 0 ? 'Requested action: ' . implode(', ', $calls) . '.' : '';
}

function stobeFormatCompactChatHistory(array $historyMessages, string $actorName): string
{
    $lines = [];
    foreach ($historyMessages as $message) {
        if (!is_array($message)) {
            continue;
        }

        $role = strtolower(trim(strval($message['role'] ?? '')));
        if ($role === 'assistant') {
            $line = isset($message['tool_calls'])
                ? stobeCompactToolHistoryEntry($message)
                : stobeCompactAssistantHistoryEntry(strval($message['content'] ?? ''), $actorName);
        } elseif ($role === 'user') {
            $line = stobeCompactUserHistoryEntry(strval($message['content'] ?? ''));
        } elseif ($role === 'tool') {
            $line = stobeCompactToolHistoryEntry($message);
        } else {
            $line = stobeCompactHistoryWhitespace(strval($message['content'] ?? ''));
        }

        if ($line !== '') {
            $lines[] = '# ' . $line;
        }
    }
    return implode("\n", $lines);
}

/** "Drera [Pacifier Rowanne]" -> "drera"; used to tell who a history line involves. */
function stobeCompactBaseName(string $name): string
{
    return strtolower(trim(preg_replace('/\s*\[[^\]]*\]\s*/u', ' ', $name) ?? $name));
}

/**
 * Marks lines this NPC only overheard ("Malzin, speaking to Shay: ..." in
 * Thaddeus's history), so the NPC does not answer as if it had been asked or
 * take on deals and promises made between other people.
 */
function stobeCompactMarkOverheard(string $historyBlock, string $actorName): string
{
    $actor = stobeCompactBaseName($actorName);
    if ($actor === '') {
        return $historyBlock;
    }
    $out = [];
    foreach (explode("\n", $historyBlock) as $line) {
        if (preg_match('/^(# )(.+?), (?:speaking|talking|whispering|shouting) to (.+?): /u', $line, $m) === 1
            && stobeCompactBaseName($m[2]) !== $actor
            && stobeCompactBaseName($m[3]) !== $actor) {
            $line = $m[1] . '(overheard) ' . substr($line, strlen($m[1]));
        }
        $out[] = $line;
    }
    return implode("\n", $out);
}

function stobeApplyCompactChatHistory(
    string $systemPrompt,
    array $historyMessages,
    string $actorName,
    bool $enabled,
    bool $markdownEnabled = false
): array {
    $systemPrompt = stobeFormatPromptHeadSection($systemPrompt, $markdownEnabled);
    if (!$enabled) {
        return [
            'system_prompt' => $systemPrompt,
            'history_messages' => $historyMessages,
        ];
    }

    $historyBlock = stobeFormatCompactChatHistory($historyMessages, $actorName);
    if ($historyBlock === '') {
        return [
            'system_prompt' => $systemPrompt,
            'history_messages' => [],
        ];
    }

    $historyBlock = stobeCompactMarkOverheard($historyBlock, $actorName);
    $overheardNote = str_contains($historyBlock, '(overheard) ')
        ? "Lines marked (overheard) were said between other people near you. You were not part of them: never answer as if you were the one asked, and never take on their deals, promises or refusals as your own.\n\n"
        : '';
    if ($markdownEnabled) {
        $historyBlock = "# Conversation History\n\n" . $overheardNote . preg_replace('/^# /m', '- ', $historyBlock);
    } elseif ($overheardNote !== '') {
        $historyBlock = '# ' . trim($overheardNote) . "\n" . $historyBlock;
    }

    return [
        'system_prompt' => rtrim($systemPrompt) . "\n\n" . $historyBlock,
        'history_messages' => [],
    ];
}
