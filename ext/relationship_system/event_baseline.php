<?php
/**
 * Relationship event baseline helpers for StobeServer.
 *
 * Builds event-history context for relationship analysis without relying on
 * legacy TEXT relationship fields.
 */

if (!function_exists('stobeRelBaselineNormalizeName')) {
    function stobeRelBaselineNormalizeName(string $raw): string
    {
        if (function_exists('normalizeParticipantNameToken')) {
            return normalizeParticipantNameToken($raw);
        }
        return trim($raw);
    }
}

if (!function_exists('stobeRelBaselineTextLength')) {
    function stobeRelBaselineTextLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }
        return strlen($value);
    }
}

if (!function_exists('stobeRelBaselineTextSlice')) {
    function stobeRelBaselineTextSlice(string $value, int $limit): string
    {
        if ($limit <= 0) {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit, 'UTF-8');
        }
        return substr($value, 0, $limit);
    }
}

if (!function_exists('stobeRelBaselineTruncate')) {
    function stobeRelBaselineTruncate(string $value, int $maxLength): string
    {
        $clean = trim($value);
        if ($maxLength <= 0 || stobeRelBaselineTextLength($clean) <= $maxLength) {
            return $clean;
        }
        if ($maxLength <= 3) {
            return stobeRelBaselineTextSlice($clean, $maxLength);
        }
        return rtrim(stobeRelBaselineTextSlice($clean, $maxLength - 3)) . '...';
    }
}

if (!function_exists('stobeRelBaselineContainsWholeName')) {
    function stobeRelBaselineContainsWholeName(string $haystack, string $name): bool
    {
        $source = trim($haystack);
        $needle = trim($name);
        if ($source === '' || $needle === '') {
            return false;
        }

        $quoted = preg_quote($needle, '/');
        return preg_match('/(?<![\p{L}\p{N}])' . $quoted . '(?![\p{L}\p{N}])/iu', $source) === 1;
    }
}

if (!function_exists('stobeRelBaselineExtractParticipants')) {
    function stobeRelBaselineExtractParticipants(array $row): array
    {
        $namesByKey = [];
        $add = static function ($raw) use (&$namesByKey): void {
            if (!is_scalar($raw) || $raw === null) {
                return;
            }
            $name = stobeRelBaselineNormalizeName(strval($raw));
            if ($name === '' || strcasecmp($name, 'The Narrator') === 0) {
                return;
            }
            $namesByKey[strtolower($name)] = $name;
        };

        if (function_exists('extractParticipantNames')) {
            $fromPeople = extractParticipantNames([
                'people' => strval($row['people'] ?? ''),
            ]);
            if (is_array($fromPeople)) {
                foreach ($fromPeople as $name) {
                    $add($name);
                }
            }
        }

        $data = strval($row['data'] ?? '');
        if ($data !== '') {
            $parsed = null;
            if (function_exists('parseDialogueEventData')) {
                $parsed = parseDialogueEventData($data);
            } elseif (function_exists('stobeRegularMemoryParseDialogueData')) {
                $parsed = stobeRegularMemoryParseDialogueData($data);
            }

            if (is_array($parsed)) {
                $add($parsed['speaker'] ?? '');
                $add($parsed['target'] ?? '');
            }
        }

        return array_values($namesByKey);
    }
}

if (!function_exists('stobeRelBaselineRowIncludesNpc')) {
    function stobeRelBaselineRowIncludesNpc(array $row, string $npcName): bool
    {
        $safeNpc = stobeRelBaselineNormalizeName($npcName);
        if ($safeNpc === '') {
            return false;
        }
        foreach (stobeEventAudienceTokens($row['people'] ?? '') as $token) {
            $identity = stobeExtractParticipantIdentityWithAwarenessState($token);
            if (strcasecmp($identity['name'], $safeNpc) === 0
                && !in_array($identity['state'], ['sleeping', 'unconscious', 'knocked_out'], true)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('stobeRelBaselineFormatLine')) {
    function stobeRelBaselineFormatLine(array $row, int $maxDataLength = 180): string
    {
        $historyType = strtolower(trim(strval($row['type'] ?? 'event')));
        if ($historyType === '') {
            $historyType = 'event';
        }

        $historyData = trim(strval($row['data'] ?? ''));
        if ($historyData === '') {
            return '';
        }

        if (function_exists('stobeNormalizeContextHistoryDataLine')) {
            $normalized = stobeNormalizeContextHistoryDataLine($historyData);
            if ($normalized !== '') {
                $historyData = $normalized;
            }
        }

        $historyData = stobeRelBaselineTruncate($historyData, max(60, $maxDataLength));
        if ($historyData === '') {
            return '';
        }

        $gamets = max(0, intval($row['gamets'] ?? 0));
        $dateLabel = '';
        if ($gamets > 0 && function_exists('stobeGametsDateLabel')) {
            $dateLabel = trim(strval(stobeGametsDateLabel($gamets)));
        }

        if ($dateLabel !== '') {
            return '[' . $historyType . ' @ ' . $dateLabel . '] ' . $historyData;
        }
        if ($gamets > 0) {
            return '[' . $historyType . ' @ gamets=' . strval($gamets) . '] ' . $historyData;
        }
        return '[' . $historyType . '] ' . $historyData;
    }
}

if (!function_exists('stobeRelBuildEventBaseline')) {
    function stobeRelBuildEventBaseline(string $npcName, int $eventLimit = 200, int $scanLimit = 0): array
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) {
            return [
                'ok' => false,
                'npc_name' => '',
                'event_count' => 0,
                'history' => '',
                'lines' => [],
                'counterparts' => [],
                'error' => 'Database connection unavailable.',
            ];
        }

        $safeNpc = stobeRelBaselineNormalizeName($npcName);
        if ($safeNpc === '') {
            return [
                'ok' => false,
                'npc_name' => '',
                'event_count' => 0,
                'history' => '',
                'lines' => [],
                'counterparts' => [],
                'error' => 'NPC name is required.',
            ];
        }

        if ($eventLimit < 10) {
            $eventLimit = 10;
        } elseif ($eventLimit > 400) {
            $eventLimit = 400;
        }

        if ($scanLimit <= 0) {
            $scanLimit = $eventLimit * 8;
        }
        if ($scanLimit < 300) {
            $scanLimit = 300;
        } elseif ($scanLimit > 3500) {
            $scanLimit = 3500;
        }

        $excludeTypes = "'prechat','setconf','status_msg','user_input','npc_snapshot','playerinfo'";
        $params = [];
        $audienceSql = stobeEventAudienceSql($safeNpc, $params);
        $rows = $db->fetchAll(
            "SELECT rowid, type, data, gamets, localts, ts, people, location
             FROM eventlog
             WHERE type NOT IN ({$excludeTypes})
               AND {$audienceSql}
             ORDER BY rowid DESC
             LIMIT " . intval($scanLimit),
            $params
        );

        $linesDesc = [];
        $counterparts = [];
        $npcLower = strtolower($safeNpc);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $line = stobeRelBaselineFormatLine($row, 180);
                if ($line === '') {
                    continue;
                }
                $linesDesc[] = $line;

                $participants = stobeRelBaselineExtractParticipants($row);
                foreach ($participants as $participant) {
                    $name = stobeRelBaselineNormalizeName($participant);
                    if ($name === '' || strtolower($name) === $npcLower) {
                        continue;
                    }
                    $counterparts[strtolower($name)] = $name;
                }

                if (count($linesDesc) >= $eventLimit) {
                    break;
                }
            }
        }

        $lines = array_reverse($linesDesc);
        $history = implode("\n", $lines);
        $counterpartList = array_values($counterparts);
        sort($counterpartList, SORT_NATURAL | SORT_FLAG_CASE);

        if (count($lines) === 0) {
            return [
                'ok' => false,
                'npc_name' => $safeNpc,
                'event_count' => 0,
                'history' => '',
                'lines' => [],
                'counterparts' => [],
                'error' => 'No event history found for this NPC.',
            ];
        }

        return [
            'ok' => true,
            'npc_name' => $safeNpc,
            'event_count' => count($lines),
            'history' => $history,
            'lines' => $lines,
            'counterparts' => $counterpartList,
            'error' => '',
        ];
    }
}
