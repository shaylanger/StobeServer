<?php
require_once __DIR__ . "/tts_filter_presets.php";

/**
 * Core data functions for StobeServer.
 * Handles event logging, NPC data, and context building.
 */

function stobePromptsTableAvailable(): bool {
    static $available = null;
    if ($available !== null) {
        return boolval($available);
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        $available = false;
        return false;
    }

    $row = $db->fetchOne("SELECT to_regclass('public.prompts') AS rel");
    $available = is_array($row) && trim(strval($row['rel'] ?? '')) !== '';
    return boolval($available);
}

function stobeGetPromptTemplateValue(string $promptKey, string $fallback = ''): string {
    static $cache = [];

    $key = trim($promptKey);
    if ($key === '') {
        return $fallback;
    }
    $cacheKey = strtolower($key);
    if (array_key_exists($cacheKey, $cache)) {
        return strval($cache[$cacheKey]);
    }

    $value = '';
    $db = $GLOBALS['db'] ?? null;
    if ($db && stobePromptsTableAvailable()) {
        $row = $db->fetchOne(
            "SELECT custom_prompt, default_prompt
             FROM prompts
             WHERE prompt_key = $1
             LIMIT 1",
            [$key]
        );

        if (is_array($row)) {
            $customPrompt = strval($row['custom_prompt'] ?? '');
            if (trim($customPrompt) !== '') {
                $value = $customPrompt;
            } else {
                $defaultPrompt = strval($row['default_prompt'] ?? '');
                if (trim($defaultPrompt) !== '') {
                    $value = $defaultPrompt;
                }
            }
        }
    }

    if ($value === '') {
        $value = $fallback;
    }

    $cache[$cacheKey] = $value;
    return $value;
}

function stobeNormalizeGeoLabel(string $value): string {
    $normalized = trim($value);
    if ($normalized === '') {
        return '';
    }
    $lower = strtolower($normalized);
    if (in_array($lower, ['unknown', 'none', 'null', 'n/a', '-'], true)) {
        return '';
    }
    return $normalized;
}

function stobeKenshiZoneAliasMap(): array {
    static $map = null;
    if (is_array($map)) {
        return $map;
    }

    $canonicalZones = [
        'Arach',
        'Bast',
        'Black Desert',
        'Bonefields',
        'Border Zone',
        'Burning Forest',
        'Cannibal Plains',
        'Darkfinger',
        'Deadlands',
        'Dreg',
        'Fishman Island',
        'Floodlands',
        'Fog Islands',
        'Forbidden Isle',
        'Great Desert',
        'Great Plateau',
        'Greenbeach',
        'Gut',
        'Heng',
        'Hidden Forest',
        'High Bonefields',
        'Howler Maze',
        'Iron Valleys',
        'Leviathan Coast',
        'Narrow Valley',
        "Okran's Gulf",
        "Okran's Pride",
        'Outlands',
        'Purple Sands',
        'Raptor Island',
        'Royal Valley',
        'Shem',
        'Shun',
        'Sinkuun',
        "Skinner's Roam",
        'Sonorous Dark',
        "Stobe's Gamble",
        'Stenn Desert',
        'Stormgap Coast',
        'Swamp',
        'The Crater',
        'The Grid',
        'The Hook',
        'The Pits',
        'The Pits East',
        'Unwanted Zone',
        "Watcher's Rim",
        'Wend',
    ];

    $map = [];
    foreach ($canonicalZones as $zone) {
        $key = strtolower($zone);
        $map[$key] = $zone;
        $map[strtolower('The ' . $zone)] = $zone;
        $withoutThe = preg_replace('/^the\s+/i', '', $zone);
        if (is_string($withoutThe) && $withoutThe !== '') {
            $map[strtolower($withoutThe)] = $zone;
        }
    }

    // Explicit aliases commonly seen in payloads/log text.
    $map['the great desert'] = 'Great Desert';
    $map['great desert'] = 'Great Desert';
    $map['the border zone'] = 'Border Zone';
    $map['border zone'] = 'Border Zone';
    $map['the hook'] = 'The Hook';
    $map['hook'] = 'The Hook';
    $map['the grid'] = 'The Grid';
    $map['grid'] = 'The Grid';
    $map["stobes gamble"] = "Stobe's Gamble";
    $map["stobe's gamble"] = "Stobe's Gamble";
    $map["skinners roam"] = "Skinner's Roam";
    $map["skinner's roam"] = "Skinner's Roam";

    return $map;
}

function stobeKenshiTownToZoneMap(): array {
    static $map = null;
    if (is_array($map)) {
        return $map;
    }

    // Intentionally disabled best-effort town->zone mapping.
    // Keep an empty map so callers still receive a valid array.
    $map = [];
    return $map;
}

function stobeIsKnownWorldRegionName(string $rawRegion): bool {
    $region = stobeCanonicalizeKenshiZoneName($rawRegion);
    if ($region === '') {
        return false;
    }
    static $known = null;
    if (!is_array($known)) {
        $known = [];
        foreach (stobeKenshiZoneAliasMap() as $alias => $canonical) {
            $canonicalKey = strtolower(trim(strval($canonical)));
            if ($canonicalKey !== '') {
                $known[$canonicalKey] = true;
            }
        }
    }
    return isset($known[strtolower($region)]);
}

function stobeIsKnownZoneName(string $rawZone): bool {
    $zone = stobeNormalizeGeoLabel($rawZone);
    if ($zone === '') {
        return false;
    }

    static $cache = [];
    $cacheKey = strtolower($zone);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if (stobeResolveKenshiZoneFromTown($zone) !== '' || stobeIsKnownWorldRegionName($zone)) {
        $cache[$cacheKey] = true;
        return true;
    }

    $db = $GLOBALS["db"] ?? null;
    if ($db) {
        try {
            $row = $db->fetchOne(
                "SELECT 1 AS ok
                 FROM location_zones
                 WHERE LOWER(COALESCE(zone_name, '')) = LOWER($1)
                    OR LOWER(COALESCE(city_name, '')) = LOWER($1)
                 LIMIT 1",
                [$zone]
            );
            if (is_array($row) && isset($row['ok'])) {
                $cache[$cacheKey] = true;
                return true;
            }
        } catch (Throwable $exception) {
            // Ignore lookup errors and fall back to static checks.
        }
    }

    $cache[$cacheKey] = false;
    return false;
}

function stobeResolveSeenZoneFromCity(string $rawTown): string {
    $town = stobeNormalizeGeoLabel($rawTown);
    if ($town === '') {
        return '';
    }

    // Prefer explicit canonical town->zone mapping for deterministic zones.
    $mapped = stobeResolveKenshiZoneFromTown($town);
    if ($mapped !== '') {
        return $mapped;
    }

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        return '';
    }

    try {
        $rows = $db->fetchAll(
            "SELECT zone_name
             FROM location_zones
             WHERE LOWER(city_name) = LOWER($1)
             ORDER BY last_seen_ts DESC, id DESC
             LIMIT 12",
            [$town]
        );
    } catch (Throwable $exception) {
        return '';
    }

    if (!is_array($rows) || count($rows) === 0) {
        return '';
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $zone = stobeCanonicalizeKenshiZoneName(strval($row['zone_name'] ?? ''));
        if ($zone === '') {
            continue;
        }
        if (strtolower($zone) === strtolower($town)) {
            continue;
        }
        if (stobeIsKnownWorldRegionName($zone) || stobeResolveKenshiZoneFromTown($zone) !== '') {
            return $zone;
        }
    }

    return '';
}

function stobeCanonicalizeKenshiZoneName(string $rawZone): string {
    $zone = stobeNormalizeGeoLabel($rawZone);
    if ($zone === '') {
        return '';
    }
    $aliasMap = stobeKenshiZoneAliasMap();
    $lookup = strtolower($zone);
    return $aliasMap[$lookup] ?? $zone;
}

function stobeResolveKenshiZoneFromTown(string $rawTown): string {
    $town = stobeNormalizeGeoLabel($rawTown);
    if ($town === '') {
        return '';
    }
    $map = stobeKenshiTownToZoneMap();
    $lookup = strtolower($town);
    return $map[$lookup] ?? '';
}

function stobeResolveRegionFromAnyToken(string $rawToken): string {
    $token = stobeNormalizeGeoLabel($rawToken);
    if ($token === '') {
        return '';
    }

    $canonical = stobeCanonicalizeKenshiZoneName($token);
    if ($canonical !== '' && stobeIsKnownWorldRegionName($canonical)) {
        return $canonical;
    }

    $seen = stobeResolveSeenZoneFromCity($token);
    if ($seen !== '') {
        return $seen;
    }

    $mapped = stobeResolveKenshiZoneFromTown($token);
    if ($mapped !== '') {
        return $mapped;
    }

    return '';
}

function stobeNormalizeGeoContext(array $geo): array {
    $allowUnknownZone = coerceBoolean($geo['allow_unknown_zone'] ?? false);
    $rawLocationToken = stobeNormalizeGeoLabel(strval($geo['location'] ?? ''));
    $rawBuildingToken = stobeNormalizeGeoLabel(strval($geo['building'] ?? ($geo['loc_building'] ?? '')));
    $zoneToken = stobeNormalizeGeoLabel(strval(
        $geo['zone']
            ?? ($geo['zone_name']
            ?? ($geo['loc_zone'] ?? ''))
    ));
    $cityToken = stobeNormalizeGeoLabel(strval(
        $geo['city']
            ?? ($geo['town_name']
            ?? ($geo['town']
            ?? ($geo['settlement']
            ?? ($geo['village'] ?? ''))))
    ));
    // Legacy compatibility: older payloads sometimes put zone into city/town keys.
    if ($zoneToken === '' && $cityToken !== '') {
        $zoneToken = $cityToken;
    }

    $normalized = [
        'location' => $rawLocationToken,
        'building' => $rawBuildingToken,
        'zone' => $zoneToken,
        'city' => $cityToken,
        'region' => stobeNormalizeGeoLabel(strval($geo['region'] ?? '')),
    ];

    $rawZoneBeforeCanonical = $normalized['zone'];
    $rawRegionBeforeCanonical = $normalized['region'];
    $normalized['zone'] = stobeCanonicalizeKenshiZoneName($normalized['zone']);
    $zoneDropped = false;
    $zoneRejected = '';
    if ($normalized['region'] === '' && $normalized['zone'] !== '') {
        $mappedRegion = stobeResolveKenshiZoneFromTown($normalized['zone']);
        if ($mappedRegion !== '') {
            $normalized['region'] = $mappedRegion;
        }
    }
    // Zone is canonical source of truth; keep city as zone alias for legacy readers.
    if (!$allowUnknownZone && $normalized['zone'] !== '' && !stobeIsKnownZoneName($normalized['zone'])) {
        $zoneDropped = true;
        $zoneRejected = $normalized['zone'];
        $normalized['zone'] = '';
    }
    $normalized['city'] = $normalized['zone'] !== '' ? $normalized['zone'] : $normalized['city'];
    $regionDropped = false;
    $regionRejected = '';
    $regionMappedFrom = '';
    $normalized['region'] = stobeCanonicalizeKenshiZoneName($normalized['region']);
    if ($normalized['region'] !== '' && !stobeIsKnownWorldRegionName($normalized['region'])) {
        $mappedRegion = stobeResolveKenshiZoneFromTown($normalized['region']);
        if ($mappedRegion !== '') {
            $regionMappedFrom = $normalized['region'];
            $normalized['region'] = $mappedRegion;
        } else {
            $regionDropped = true;
            $regionRejected = $normalized['region'];
            $normalized['region'] = '';
        }
    }
    if ($normalized['region'] === '') {
        $candidateRegionToken = '';
        if ($normalized['zone'] !== '') {
            $candidateRegionToken = $normalized['zone'];
        } elseif ($normalized['city'] !== '') {
            $candidateRegionToken = $normalized['city'];
        } elseif ($normalized['location'] !== '') {
            $candidateRegionToken = $normalized['location'];
        }
        if ($candidateRegionToken !== '') {
            $mappedRegion = stobeResolveRegionFromAnyToken($candidateRegionToken);
            if ($mappedRegion !== '') {
                $regionMappedFrom = $candidateRegionToken;
                $normalized['region'] = $mappedRegion;
            }
        }
    }

    $parts = [];
    if ($normalized['building'] !== '') {
        $parts[] = $normalized['building'];
    }
    if ($normalized['zone'] !== '') {
        $parts[] = $normalized['zone'];
    }
    if ($normalized['region'] !== '') {
        $parts[] = $normalized['region'];
    }
    if (count($parts) === 0) {
        // Strict mode: do not keep free-form location text as source of truth.
        $normalized['location'] = '';
    } else {
        $normalized['location'] = implode(', ', array_values(array_unique($parts)));
    }
    if ($zoneDropped || $regionDropped || $regionMappedFrom !== '') {
        stobeLogDebug('GEO_DEBUG_NORMALIZE', [
            'raw' => [
                'location' => $rawLocationToken,
                'building' => $rawBuildingToken,
                'zone' => $rawZoneBeforeCanonical,
                'city' => $cityToken,
                'region' => $rawRegionBeforeCanonical,
                'allow_unknown_zone' => $allowUnknownZone ? '1' : '0',
            ],
            'zone_dropped' => $zoneDropped ? '1' : '0',
            'zone_rejected' => $zoneRejected,
            'region_dropped' => $regionDropped ? '1' : '0',
            'region_rejected' => $regionRejected,
            'region_mapped_from' => $regionMappedFrom,
            'normalized' => $normalized,
        ]);
    }
    return $normalized;
}

function mergeEventGeoContext(array $base, array $extra): array {
    foreach (['location', 'building', 'zone', 'city', 'region'] as $key) {
        if (!array_key_exists($key, $base)) {
            $base[$key] = '';
        }
        $candidate = trim(strval($extra[$key] ?? ''));
        if ($base[$key] === '' && $candidate !== '') {
            $base[$key] = $candidate;
        }
    }
    return $base;
}

function stobeNormalizeItemDescriptionText(string $value): string {
    $normalized = trim($value);
    if ($normalized === '') {
        return '';
    }
    $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    $normalized = trim($normalized);
    if ($normalized === '') {
        return '';
    }
    $lower = strtolower($normalized);
    if (in_array($lower, ['unknown', 'none', 'null', 'n/a', '-', '(none)'], true)) {
        return '';
    }
    if (strlen($normalized) > 700) {
        $normalized = substr($normalized, 0, 700);
    }
    return $normalized;
}

function stobeBuildSyntheticItemStringId(string $name): string {
    $normalized = strtolower(trim($name));
    if ($normalized === '') {
        return '';
    }
    $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? $normalized;
    $normalized = trim($normalized, '_');
    if ($normalized === '') {
        return '';
    }
    if (strlen($normalized) > 110) {
        $normalized = substr($normalized, 0, 110);
        $normalized = rtrim($normalized, '_');
    }
    if ($normalized === '') {
        return '';
    }
    return 'name_' . $normalized;
}

function stobeNormalizeVoiceLookupValue(string $value): string {
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        return '';
    }
    $normalized = preg_replace('/\s*\[[^\]]+\]\s*$/u', '', $normalized) ?? $normalized;
    $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    return trim($normalized);
}

function stobeNormalizeVoiceFactionValue(string $value): string {
    $raw = trim($value);
    if ($raw === '') {
        return '';
    }
    $barPos = strpos($raw, '|');
    if ($barPos !== false) {
        $raw = trim(substr($raw, 0, $barPos));
    }
    $raw = preg_replace('/\s*\[[^\]]+\]\s*$/u', '', $raw) ?? $raw;
    return stobeNormalizeVoiceLookupValue($raw);
}

function stobeNormalizeVoiceGender(string $value): string {
    $normalized = strtolower(trim($value));
    if ($normalized === 'male' || $normalized === 'female' || $normalized === 'any') {
        return $normalized;
    }
    if ($normalized === '') {
        return 'any';
    }
    return 'any';
}

function stobeBuildVoiceNameCandidates(string $name, string $originalName = ''): array {
    $candidates = [];
    $push = static function (array &$target, string $raw): void {
        $value = stobeNormalizeVoiceLookupValue($raw);
        if ($value === '') {
            return;
        }
        if (!in_array($value, $target, true)) {
            $target[] = $value;
        }
    };

    $push($candidates, $name);
    $push($candidates, $originalName);

    $open = strpos($name, '[');
    $close = strrpos($name, ']');
    if ($open !== false && $close !== false && $close > $open) {
        $inside = trim(substr($name, $open + 1, $close - $open - 1));
        $before = trim(substr($name, 0, $open));
        $push($candidates, $inside);
        $push($candidates, $before);
    }

    return $candidates;
}

function stobeGetCombinedVoiceRows(): array {
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        $cache = [];
        return $cache;
    }

    try {
        $rows = $db->fetchAll(
            "SELECT
                id,
                voiceid,
                sample_file,
                gender,
                race,
                faction,
                \"unique\",
                notes
             FROM combined_core_voiceid
             ORDER BY id ASC"
        );
    } catch (Throwable $exception) {
        $rows = [];
    }

    $customVoiceMap = [];
    try {
        $customRows = $db->fetchAll("SELECT voiceid FROM core_voiceid_custom");
        foreach ($customRows as $customRow) {
            $customVoiceId = strtolower(trim(strval($customRow['voiceid'] ?? '')));
            if ($customVoiceId !== '') {
                $customVoiceMap[$customVoiceId] = true;
            }
        }
    } catch (Throwable $exception) {
        $customVoiceMap = [];
    }

    $normalized = [];
    foreach ($rows as $row) {
        $voiceId = trim(strval($row['voiceid'] ?? ''));
        if ($voiceId === '') {
            continue;
        }
        $voiceIdKey = strtolower($voiceId);
        $normalized[] = [
            'id' => intval($row['id'] ?? 0),
            'voiceid' => $voiceId,
            'sample_file' => trim(strval($row['sample_file'] ?? '')),
            'gender' => stobeNormalizeVoiceGender(strval($row['gender'] ?? 'any')),
            'race' => stobeNormalizeVoiceLookupValue(strval($row['race'] ?? 'any')),
            'faction' => stobeNormalizeVoiceLookupValue(strval($row['faction'] ?? 'any')),
            'unique' => stobeNormalizeVoiceLookupValue(strval($row['unique'] ?? '')),
            'notes' => trim(strval($row['notes'] ?? '')),
            'is_custom' => isset($customVoiceMap[$voiceIdKey]),
        ];
    }

    $cache = $normalized;
    return $cache;
}

function stobeShouldAllowVoiceRowInRandomPool(array $row): bool {
    $uniqueTag = trim(strval($row['unique'] ?? ''));
    return $uniqueTag === '';
}

function stobeHasUniqueVoiceCandidateForNpc(string $name, string $originalName = ''): bool {
    $rows = stobeGetCombinedVoiceRows();
    if (count($rows) === 0) {
        return false;
    }

    $candidateNames = stobeBuildVoiceNameCandidates($name, $originalName);
    if (count($candidateNames) === 0) {
        return false;
    }
    $candidateNameMap = array_fill_keys($candidateNames, true);

    foreach ($rows as $row) {
        $uniqueName = trim(strval($row['unique'] ?? ''));
        if ($uniqueName === '') {
            continue;
        }
        if (isset($candidateNameMap[$uniqueName])) {
            return true;
        }
    }

    return false;
}

function stobeBuildVoiceStableKey(string $name, array $profile = []): string {
    $metadata = normalizeCoreNpcMetadata($profile['metadata'] ?? []);
    $storageId = normalizeStorageIdToken($metadata['storage_id'] ?? '');
    if ($storageId !== '') {
        return strtolower($storageId);
    }

    $fallback = strtolower(trim($name));
    if ($fallback !== '') {
        return $fallback;
    }

    $encoded = json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || $encoded === '') {
        return 'voice-fallback';
    }
    return md5($encoded);
}

function stobePickDeterministicVoice(array $candidates, string $stableKey, string $salt = ''): string {
    if (count($candidates) === 0) {
        return '';
    }
    $seed = $stableKey . '|' . $salt;
    $hash = sprintf('%u', crc32($seed));
    $index = intval($hash) % count($candidates);
    $picked = $candidates[$index] ?? [];
    return trim(strval($picked['voiceid'] ?? ''));
}

function stobeVoiceTagMatches(string $rowValue, string $npcValue): bool {
    $row = stobeNormalizeVoiceLookupValue($rowValue);
    if ($row === '' || $row === 'any') {
        return true;
    }
    $npc = stobeNormalizeVoiceLookupValue($npcValue);
    if ($npc === '') {
        return false;
    }
    return strcasecmp($row, $npc) === 0;
}

// Resolve broad voice families without discarding exact subrace matching.
function stobeResolveVoiceRaceGroup(string $race, array $profile = []): string {
    $metadata = normalizeCoreNpcMetadata($profile['metadata'] ?? []);
    if (coerceBoolean($metadata['race_is_robot'] ?? false)) {
        return 'skeleton';
    }

    $normalized = stobeNormalizeVoiceLookupValue($race);
    if ($normalized === '') {
        return '';
    }
    if (str_contains($normalized, 'hive')) {
        return 'hive';
    }
    if (str_contains($normalized, 'skeleton')) {
        return 'skeleton';
    }
    if (str_contains($normalized, 'cannibal')) {
        return 'cannibal';
    }

    $legacyRobotRaces = [
        'hazards unit',
        'p2 unit',
        'p3 unit',
        'p4 unit',
        'type 389',
        'soldierbot',
        'screamer mk i',
        'screamer mk ii',
        'log-head mk i',
        'log-head mk ii',
    ];
    return in_array($normalized, $legacyRobotRaces, true) ? 'skeleton' : '';
}

function stobeVoiceRaceMatches(string $rowValue, string $npcValue, string $npcRaceGroup = ''): bool {
    $row = stobeNormalizeVoiceLookupValue($rowValue);
    if ($row === '' || $row === 'any') {
        return true;
    }
    $npc = stobeNormalizeVoiceLookupValue($npcValue);
    if ($npc !== '' && strcasecmp($row, $npc) === 0) {
        return true;
    }
    return $npcRaceGroup !== ''
        && in_array($row, ['skeleton', 'hive', 'cannibal'], true)
        && $row === $npcRaceGroup;
}

function stobeSelectVoiceIdForNpc(
    string $name,
    string $race = '',
    string $gender = '',
    string $faction = '',
    array $profile = []
): string {
    $rows = stobeGetCombinedVoiceRows();
    if (count($rows) === 0) {
        return '';
    }

    $stableKey = stobeBuildVoiceStableKey($name, $profile);
    $candidateNames = stobeBuildVoiceNameCandidates($name, trim(strval($profile['original_name'] ?? '')));
    $candidateNameMap = array_fill_keys($candidateNames, true);

    $uniqueMatches = [];
    foreach ($rows as $row) {
        $uniqueName = trim(strval($row['unique'] ?? ''));
        if ($uniqueName === '') {
            continue;
        }
        if (isset($candidateNameMap[$uniqueName])) {
            $uniqueMatches[] = $row;
        }
    }
    $pickedUnique = stobePickDeterministicVoice($uniqueMatches, $stableKey, 'unique');
    if ($pickedUnique !== '') {
        return $pickedUnique;
    }

    $normalizedGender = stobeNormalizeVoiceGender($gender);
    $normalizedRace = stobeNormalizeVoiceLookupValue($race);
    $normalizedFaction = stobeNormalizeVoiceFactionValue($faction);
    $normalizedRaceGroup = stobeResolveVoiceRaceGroup($race, $profile);

    $nonUniqueRows = [];
    foreach ($rows as $row) {
        if (!stobeShouldAllowVoiceRowInRandomPool($row)) {
            continue;
        }
        $nonUniqueRows[] = $row;
    }

    // Unknown races must stay on the legacy gender pools instead of borrowing a racial voice.
    if (in_array($normalizedRace, ['', 'unknown', 'none', 'null', 'n/a', '-', '(none)', 'any'], true)) {
        $genericGenderMatches = [];
        foreach ($nonUniqueRows as $row) {
            if (!stobeVoiceTagMatches(strval($row['gender'] ?? 'any'), $normalizedGender)) {
                continue;
            }
            $rowRace = stobeNormalizeVoiceLookupValue(strval($row['race'] ?? 'any'));
            if ($rowRace !== '' && $rowRace !== 'any') {
                continue;
            }
            $genericGenderMatches[] = $row;
        }

        return stobePickDeterministicVoice($genericGenderMatches, $stableKey, 'unknown_race_gender');
    }

    $exactRaceMatches = [];
    foreach ($nonUniqueRows as $row) {
        if (!stobeVoiceTagMatches(strval($row['gender'] ?? 'any'), $normalizedGender)) {
            continue;
        }
        $rowRace = stobeNormalizeVoiceLookupValue(strval($row['race'] ?? 'any'));
        if ($rowRace === '' || $rowRace === 'any') {
            continue;
        }
        if ($normalizedRace === '' || strcasecmp($rowRace, $normalizedRace) !== 0) {
            continue;
        }
        if (!stobeVoiceTagMatches(strval($row['faction'] ?? 'any'), $normalizedFaction)) {
            continue;
        }
        $exactRaceMatches[] = $row;
    }
    $pickedExactRace = stobePickDeterministicVoice($exactRaceMatches, $stableKey, 'exact_race');
    if ($pickedExactRace !== '') {
        return $pickedExactRace;
    }

    $groupRaceMatches = [];
    if ($normalizedRaceGroup !== '') {
        foreach ($nonUniqueRows as $row) {
            if (!stobeVoiceTagMatches(strval($row['gender'] ?? 'any'), $normalizedGender)) {
                continue;
            }
            $rowRace = stobeNormalizeVoiceLookupValue(strval($row['race'] ?? 'any'));
            if ($rowRace !== $normalizedRaceGroup) {
                continue;
            }
            if (!stobeVoiceTagMatches(strval($row['faction'] ?? 'any'), $normalizedFaction)) {
                continue;
            }
            $groupRaceMatches[] = $row;
        }
    }
    $pickedGroupRace = stobePickDeterministicVoice($groupRaceMatches, $stableKey, 'group_race');
    if ($pickedGroupRace !== '') {
        return $pickedGroupRace;
    }

    $strictMatches = [];
    foreach ($nonUniqueRows as $row) {
        if (!stobeVoiceTagMatches(strval($row['gender'] ?? 'any'), $normalizedGender)) {
            continue;
        }
        if (!stobeVoiceRaceMatches(strval($row['race'] ?? 'any'), $normalizedRace, $normalizedRaceGroup)) {
            continue;
        }
        if (!stobeVoiceTagMatches(strval($row['faction'] ?? 'any'), $normalizedFaction)) {
            continue;
        }
        $strictMatches[] = $row;
    }
    $pickedStrict = stobePickDeterministicVoice($strictMatches, $stableKey, 'strict');
    if ($pickedStrict !== '') {
        return $pickedStrict;
    }

    $genderRaceMatches = [];
    foreach ($nonUniqueRows as $row) {
        if (!stobeVoiceTagMatches(strval($row['gender'] ?? 'any'), $normalizedGender)) {
            continue;
        }
        if (!stobeVoiceRaceMatches(strval($row['race'] ?? 'any'), $normalizedRace, $normalizedRaceGroup)) {
            continue;
        }
        $genderRaceMatches[] = $row;
    }
    $pickedGenderRace = stobePickDeterministicVoice($genderRaceMatches, $stableKey, 'gender_race');
    if ($pickedGenderRace !== '') {
        return $pickedGenderRace;
    }

    $genderFactionMatches = [];
    foreach ($nonUniqueRows as $row) {
        if (!stobeVoiceTagMatches(strval($row['gender'] ?? 'any'), $normalizedGender)) {
            continue;
        }
        if (!stobeVoiceTagMatches(strval($row['faction'] ?? 'any'), $normalizedFaction)) {
            continue;
        }
        $genderFactionMatches[] = $row;
    }
    $pickedGenderFaction = stobePickDeterministicVoice($genderFactionMatches, $stableKey, 'gender_faction');
    if ($pickedGenderFaction !== '') {
        return $pickedGenderFaction;
    }

    $genderMatches = [];
    foreach ($nonUniqueRows as $row) {
        if (!stobeVoiceTagMatches(strval($row['gender'] ?? 'any'), $normalizedGender)) {
            continue;
        }
        $genderMatches[] = $row;
    }
    $pickedGender = stobePickDeterministicVoice($genderMatches, $stableKey, 'gender');
    if ($pickedGender !== '') {
        return $pickedGender;
    }

    return stobePickDeterministicVoice($nonUniqueRows, $stableKey, 'fallback');
}

function stobeAssignVoiceIdIfMissing(
    string $npcName,
    string $race = '',
    string $gender = '',
    string $faction = '',
    array $profile = []
): string {
    $safeName = trim($npcName);
    if ($safeName === '') {
        return '';
    }

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        return '';
    }

    $metadata = normalizeCoreNpcMetadata($profile['metadata'] ?? []);
    $storageId = normalizeStorageIdToken($metadata['storage_id'] ?? '');

    $row = false;
    if ($storageId !== '') {
        $row = $db->fetchOne(
            "SELECT id,
                    voiceid,
                    race,
                    gender,
                    faction,
                    original_name,
                    metadata
             FROM core_npc_master
             WHERE LOWER(COALESCE(metadata->>'storage_id', '')) = LOWER($1)
             ORDER BY
                CASE WHEN COALESCE(BTRIM(voiceid), '') <> '' THEN 0 ELSE 1 END,
                gamets_last_updated DESC,
                updated_at DESC,
                id DESC
             LIMIT 1",
            [$storageId]
        );
    }
    if (!$row) {
        $row = $db->fetchOne(
            "SELECT id,
                    voiceid,
                    race,
                    gender,
                    faction,
                    original_name,
                    metadata
             FROM core_npc_master
             WHERE LOWER(name) = LOWER($1)
             ORDER BY
                CASE WHEN COALESCE(BTRIM(voiceid), '') <> '' THEN 0 ELSE 1 END,
                CASE WHEN COALESCE(metadata->>'storage_id', '') <> '' THEN 0 ELSE 1 END,
                gamets_last_updated DESC,
                updated_at DESC,
                id DESC
             LIMIT 1",
            [$safeName]
        );
    }
    $existingVoice = trim(strval($row['voiceid'] ?? ''));
    if ($existingVoice !== '') {
        $db->exec(
            "UPDATE core_npc_master
             SET voiceid = $2,
                 updated_at = NOW()
             WHERE LOWER(name) = LOWER($1)
               AND (voiceid IS NULL OR BTRIM(voiceid) = '')",
            [$safeName, $existingVoice]
        );
        return $existingVoice;
    }

    $existingRace = trim(strval($row['race'] ?? ''));
    $existingGender = trim(strval($row['gender'] ?? ''));
    $existingFaction = trim(strval($row['faction'] ?? ''));
    $existingOriginalName = trim(strval($row['original_name'] ?? ''));
    $existingMetadata = normalizeCoreNpcMetadata($row['metadata'] ?? []);
    $providedMetadata = normalizeCoreNpcMetadata($profile['metadata'] ?? []);
    $effectiveMetadata = count($providedMetadata) > 0 ? $providedMetadata : $existingMetadata;

    $effectiveRace = trim($race) !== '' ? trim($race) : $existingRace;
    $effectiveGender = trim($gender) !== '' ? trim($gender) : $existingGender;
    $effectiveFaction = trim($faction) !== '' ? trim($faction) : $existingFaction;
    $effectiveOriginalName = trim(strval($profile['original_name'] ?? ''));
    if ($effectiveOriginalName === '') {
        $effectiveOriginalName = $existingOriginalName;
    }

    $hasUniqueCandidate = stobeHasUniqueVoiceCandidateForNpc($safeName, $effectiveOriginalName);
    $normalizedGender = stobeNormalizeVoiceGender($effectiveGender);
    $effectiveRaceGroup = stobeResolveVoiceRaceGroup($effectiveRace, ['metadata' => $effectiveMetadata]);
    if (
        !$hasUniqueCandidate
        && $effectiveRaceGroup !== 'skeleton'
        && $normalizedGender !== 'male'
        && $normalizedGender !== 'female'
    ) {
        return '';
    }

    $selectionProfile = $profile;
    $selectionProfile['metadata'] = $effectiveMetadata;
    $selectionProfile['original_name'] = $effectiveOriginalName;
    $selected = stobeSelectVoiceIdForNpc($safeName, $effectiveRace, $effectiveGender, $effectiveFaction, $selectionProfile);
    if ($selected === '') {
        return '';
    }

    $rowId = intval($row['id'] ?? 0);
    if ($rowId > 0) {
        $db->exec(
            "UPDATE core_npc_master
             SET voiceid = $2,
                 updated_at = NOW()
             WHERE id = $1
               AND (voiceid IS NULL OR BTRIM(voiceid) = '')",
            [$rowId, $selected]
        );
    }
    $db->exec(
        "UPDATE core_npc_master
         SET voiceid = $2,
             updated_at = NOW()
         WHERE LOWER(name) = LOWER($1)
           AND (voiceid IS NULL OR BTRIM(voiceid) = '')",
        [$safeName, $selected]
    );

    return $selected;
}

function stobeUpsertDescriptionsFromInventoryEntries(array $inventoryEntries, string $source = '', string $npcName = '', int $gamets = 0): int {
    $db = $GLOBALS["db"] ?? null;
    if (!$db || count($inventoryEntries) === 0) {
        return 0;
    }

    $rowsByStringId = [];
    foreach ($inventoryEntries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $itemName = trim(strval($entry['name'] ?? ''));
        if ($itemName === '') {
            continue;
        }

        $itemDescription = stobeNormalizeItemDescriptionText(strval($entry['description'] ?? ''));
        if ($itemDescription === '') {
            continue;
        }

        $itemId = trim(strval(
            $entry['item_id']
                ?? ($entry['itemId']
                ?? ($entry['string_id']
                ?? ($entry['stringid']
                ?? ($entry['sid']
                ?? ($entry['baseid']
                ?? ($entry['id'] ?? ''))))))
        ));

        $stringId = $itemId !== '' ? $itemId : stobeBuildSyntheticItemStringId($itemName);
        if ($stringId === '') {
            continue;
        }

        $mapKey = strtolower($stringId);
        if (!array_key_exists($mapKey, $rowsByStringId)) {
            $rowsByStringId[$mapKey] = [
                'stringid' => $stringId,
                'name' => $itemName,
                'description' => $itemDescription,
            ];
            continue;
        }

        $existingDescription = strval($rowsByStringId[$mapKey]['description'] ?? '');
        if (strlen($itemDescription) > strlen($existingDescription)) {
            $rowsByStringId[$mapKey]['description'] = $itemDescription;
            $rowsByStringId[$mapKey]['name'] = $itemName;
        }
    }

    if (count($rowsByStringId) === 0) {
        return 0;
    }

    $upserted = 0;
    foreach ($rowsByStringId as $row) {
        try {
            $db->exec(
                "INSERT INTO descriptions (stringid, name, description)
                 VALUES ($1, $2, $3)
                 ON CONFLICT (stringid) DO UPDATE
                 SET
                    name = EXCLUDED.name,
                    description = EXCLUDED.description
                 WHERE COALESCE(descriptions.name, '') <> COALESCE(EXCLUDED.name, '')
                    OR COALESCE(descriptions.description, '') <> COALESCE(EXCLUDED.description, '')",
                [
                    strval($row['stringid'] ?? ''),
                    strval($row['name'] ?? ''),
                    strval($row['description'] ?? ''),
                ]
            );
            $upserted++;
        } catch (Throwable $exception) {
            stobeLogException($exception, 'Failed to upsert live item description', [
                'stringid' => strval($row['stringid'] ?? ''),
                'name' => strval($row['name'] ?? ''),
                'source' => $source,
                'npc_name' => $npcName,
                'gamets' => max(0, intval($gamets)),
            ]);
        }
    }

    return $upserted;
}

function extractEventGeoFromArray(array $payload): array {
    $context = ['location' => '', 'building' => '', 'zone' => '', 'city' => '', 'region' => ''];

    $pickValue = static function (array $source, array $keys): string {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $candidate = trim(strval($source[$key]));
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return '';
    };

    $extractFrom = static function (array $source) use (&$context, $pickValue): void {
        $location = $pickValue($source, ['location', 'location_name', 'place', 'area_name', 'area', 'cell']);
        $building = $pickValue($source, ['building_name', 'building', 'loc_building']);
        $zone = $pickValue($source, ['zone', 'zone_name', 'loc_zone', 'city', 'town_name', 'town', 'settlement', 'village']);
        $region = $pickValue($source, [
            'region', 'region_name', 'regionName', 'loc_region',
            'province', 'district', 'territory', 'biome'
        ]);

        if ($context['building'] === '' && $building !== '') {
            $context['building'] = $building;
        }
        if ($context['zone'] === '' && $zone !== '') {
            $context['zone'] = $zone;
        }
        if ($context['city'] === '' && $zone !== '') {
            $context['city'] = $zone;
        }
        if ($context['region'] === '' && $region !== '') {
            $context['region'] = $region;
        }
        if ($context['location'] === '') {
            if ($location !== '') {
                $context['location'] = $location;
            }
        }
    };

    $extractFrom($payload);

    foreach (['environment', 'location', 'context', 'metadata', 'entry'] as $nestedKey) {
        $nested = $payload[$nestedKey] ?? null;
        if (is_array($nested)) {
            $extractFrom($nested);
        }
    }

    return stobeNormalizeGeoContext($context);
}

function extractEventGeoFromString(string $eventData): array {
    $text = trim($eventData);
    if ($text === '') {
        return ['location' => '', 'building' => '', 'zone' => '', 'city' => '', 'region' => ''];
    }

    if ($text[0] === '{') {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return extractEventGeoFromArray($decoded);
        }
    }

    $pairs = [];
    $chunks = preg_split('/[|,;\n\r]+/', $text) ?: [];
    foreach ($chunks as $chunk) {
        $part = trim(strval($chunk));
        if ($part === '') {
            continue;
        }
        $delimiter = '';
        if (strpos($part, '=') !== false) {
            $delimiter = '=';
        } elseif (strpos($part, ':') !== false) {
            $delimiter = ':';
        }
        if ($delimiter === '') {
            continue;
        }
        $kv = explode($delimiter, $part, 2);
        if (count($kv) !== 2) {
            continue;
        }
        $key = strtolower(trim(strval($kv[0])));
        $value = trim(strval($kv[1]));
        if ($key === '' || $value === '') {
            continue;
        }
        $pairs[$key] = $value;
    }

    if (count($pairs) > 0) {
        return extractEventGeoFromArray($pairs);
    }

    return stobeNormalizeGeoContext([
        'location' => $text,
        'building' => '',
        'zone' => '',
        'city' => '',
        'region' => '',
    ]);
}

function extractEventGeoFromLocationUpdateMessage(string $eventData): array {
    $text = trim($eventData);
    if ($text === '') {
        return ['location' => '', 'building' => '', 'zone' => '', 'city' => '', 'region' => ''];
    }

    if (preg_match('/location\s*update\s*:\s*(.+)$/iu', $text, $matches) !== 1) {
        return ['location' => '', 'building' => '', 'zone' => '', 'city' => '', 'region' => ''];
    }

    $locationText = trim(strval($matches[1] ?? ''));
    if ($locationText === '') {
        return ['location' => '', 'building' => '', 'zone' => '', 'city' => '', 'region' => ''];
    }

    if ($locationText[0] === '{') {
        return extractEventGeoFromString($locationText);
    }

    $parts = preg_split('/\s*,\s*/u', $locationText) ?: [];
    $parts = array_values(array_filter(array_map(static function ($part): string {
        return trim(strval($part));
    }, $parts), static function ($part): bool {
        return $part !== '';
    }));

    $building = '';
    $zone = '';
    $region = '';
    if (count($parts) >= 3) {
        $buildingParts = array_slice($parts, 0, count($parts) - 2);
        $building = trim(implode(', ', $buildingParts));
        $zone = strval($parts[count($parts) - 2]);
        $region = strval($parts[count($parts) - 1]);
    } elseif (count($parts) === 2) {
        $first = strval($parts[0]);
        $second = strval($parts[1]);
        $zoneAliases = stobeKenshiZoneAliasMap();
        $secondLooksLikeRegion = isset($zoneAliases[strtolower($second)]);
        if ($secondLooksLikeRegion) {
            $zone = $first;
            $region = $second;
        } else {
            $building = $first;
            $zone = $second;
        }
    } elseif (count($parts) === 1) {
        $zone = strval($parts[0]);
    }

    if ($region === '' && $zone !== '') {
        $mappedRegion = stobeResolveKenshiZoneFromTown($zone);
        if ($mappedRegion !== '') {
            $region = $mappedRegion;
        }
    }

    return stobeNormalizeGeoContext([
        'location' => $locationText,
        'building' => $building,
        'zone' => $zone,
        'city' => $zone,
        'region' => $region,
    ]);
}

function getEventGeoFromNpcName(string $name): array {
    $normalizedName = normalizeParticipantNameToken($name);
    if ($normalizedName === '') {
        return ['location' => '', 'building' => '', 'zone' => '', 'city' => '', 'region' => ''];
    }

    static $cache = [];
    $cacheKey = strtolower($normalizedName);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $db = $GLOBALS["db"];
    $row = $db->fetchOne(
        "SELECT metadata, extended_data
         FROM core_npc
         WHERE LOWER(name) = LOWER($1)
         ORDER BY gamets_last_updated DESC, updated_at DESC
         LIMIT 1",
        [$normalizedName]
    );

    if (!$row) {
        $cache[$cacheKey] = ['location' => '', 'building' => '', 'zone' => '', 'city' => '', 'region' => ''];
        return $cache[$cacheKey];
    }

    $resolved = ['location' => '', 'building' => '', 'zone' => '', 'city' => '', 'region' => ''];
    foreach (['extended_data', 'metadata'] as $field) {
        $raw = $row[$field] ?? '{}';
        if (is_array($raw)) {
            $resolved = mergeEventGeoContext($resolved, extractEventGeoFromArray($raw));
            continue;
        }
        $decoded = json_decode(strval($raw), true);
        if (is_array($decoded)) {
            $resolved = mergeEventGeoContext($resolved, extractEventGeoFromArray($decoded));
        }
    }

    $cache[$cacheKey] = $resolved;
    return $resolved;
}

function getEventGeoFromPlayerSnapshot(): array {
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $playerName = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    $db = $GLOBALS["db"];
    $row = $db->fetchOne(
        "SELECT metadata, extended_data
         FROM core_npc
         WHERE LOWER(name) = LOWER($1)
            OR LOWER(COALESCE(metadata->>'is_player_character', 'false')) IN ('true', '1', 'yes', 'on')
         ORDER BY
            CASE WHEN LOWER(name) = LOWER($1) THEN 0 ELSE 1 END,
            gamets_last_updated DESC,
            updated_at DESC
         LIMIT 1",
        [$playerName]
    );

    if (!$row) {
        $cached = ['location' => '', 'building' => '', 'zone' => '', 'city' => '', 'region' => ''];
        return $cached;
    }

    $resolved = ['location' => '', 'building' => '', 'zone' => '', 'city' => '', 'region' => ''];
    foreach (['extended_data', 'metadata'] as $field) {
        $raw = $row[$field] ?? '{}';
        if (is_array($raw)) {
            $resolved = mergeEventGeoContext($resolved, extractEventGeoFromArray($raw));
            continue;
        }
        $decoded = json_decode(strval($raw), true);
        if (is_array($decoded)) {
            $resolved = mergeEventGeoContext($resolved, extractEventGeoFromArray($decoded));
        }
    }

    $cached = $resolved;
    return $cached;
}

function getEventGeoFromCoordinateFallback(?float $x, ?float $y, ?float $z, bool $allowBuilding): array {
    $empty = ['location' => '', 'building' => '', 'zone' => '', 'city' => '', 'region' => ''];
    if ($x === null || $z === null) {
        return $empty;
    }

    static $cache = [];
    $cacheKey = implode('|', [
        number_format($x, 0, '.', ''),
        number_format($z, 0, '.', ''),
        $allowBuilding ? '1' : '0',
    ]);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        $cache[$cacheKey] = $empty;
        return $empty;
    }

    $rows = $db->fetchAll(
        "SELECT
            COALESCE(
                NULLIF(TRIM(env_ctx.env->>'zone_name'), ''),
                NULLIF(TRIM(env_ctx.env->>'town_name'), ''),
                NULLIF(TRIM(core_npc.metadata->>'zone'), ''),
                NULLIF(TRIM(core_npc.metadata->>'town'), '')
            ) AS zone_name,
            COALESCE(
                NULLIF(TRIM(env_ctx.env->>'region'), ''),
                NULLIF(TRIM(core_npc.metadata->>'region'), '')
            ) AS region_name,
            NULLIF(TRIM(env_ctx.env->>'building_name'), '') AS building_name,
            ((CAST(env_ctx.env->>'x' AS DOUBLE PRECISION) - $1) * (CAST(env_ctx.env->>'x' AS DOUBLE PRECISION) - $1)
             + (CAST(env_ctx.env->>'z' AS DOUBLE PRECISION) - $2) * (CAST(env_ctx.env->>'z' AS DOUBLE PRECISION) - $2)) AS dist2
         FROM core_npc
         CROSS JOIN LATERAL (
            SELECT
                CASE
                  WHEN jsonb_typeof(core_npc.extended_data) = 'object'
                    THEN core_npc.extended_data->'environment'
                  ELSE '{}'::jsonb
                END AS env
         ) AS env_ctx
         WHERE (env_ctx.env->>'x') ~ '^-?[0-9]+(?:\\.[0-9]+)?$'
           AND (env_ctx.env->>'z') ~ '^-?[0-9]+(?:\\.[0-9]+)?$'
           AND (
                COALESCE(
                    NULLIF(TRIM(env_ctx.env->>'zone_name'), ''),
                    NULLIF(TRIM(env_ctx.env->>'town_name'), ''),
                    NULLIF(TRIM(core_npc.metadata->>'zone'), ''),
                    NULLIF(TRIM(core_npc.metadata->>'town'), '')
                ) IS NOT NULL
                OR COALESCE(
                    NULLIF(TRIM(env_ctx.env->>'region'), ''),
                    NULLIF(TRIM(core_npc.metadata->>'region'), '')
                ) IS NOT NULL
           )
         ORDER BY dist2 ASC
         LIMIT 8",
        [$x, $z]
    );

    if (!is_array($rows) || count($rows) === 0) {
        $cache[$cacheKey] = $empty;
        return $empty;
    }

    $best = $rows[0];
    $bestDist2 = floatval($best['dist2'] ?? 0.0);
    $bestDistance = $bestDist2 > 0.0 ? sqrt($bestDist2) : 0.0;
    $maxDistance = 120000.0;
    if ($bestDistance > $maxDistance) {
        $cache[$cacheKey] = $empty;
        return $empty;
    }

    $regionScores = [];
    $regionBestRow = [];
    $regionBestDist = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $zoneCandidate = trim(strval($row['zone_name'] ?? ''));
        $regionCandidate = trim(strval($row['region_name'] ?? ''));
        if ($regionCandidate === '' && $zoneCandidate !== '') {
            $regionCandidate = stobeResolveRegionFromAnyToken($zoneCandidate);
        }
        if ($regionCandidate === '') {
            continue;
        }
        $dist2 = floatval($row['dist2'] ?? 0.0);
        $distance = $dist2 > 0.0 ? sqrt($dist2) : 0.0;
        $weight = 1.0 / max(1.0, $distance);
        $regionKey = strtolower($regionCandidate);
        if (!array_key_exists($regionKey, $regionScores)) {
            $regionScores[$regionKey] = 0.0;
            $regionBestRow[$regionKey] = $row;
            $regionBestDist[$regionKey] = $distance;
        }
        $regionScores[$regionKey] += $weight;
        if ($distance < floatval($regionBestDist[$regionKey] ?? INF)) {
            $regionBestDist[$regionKey] = $distance;
            $regionBestRow[$regionKey] = $row;
        }
    }

    if (count($regionScores) === 0) {
        $cache[$cacheKey] = $empty;
        return $empty;
    }
    arsort($regionScores);
    $regionKeys = array_keys($regionScores);
    $selectedRegionKey = strval($regionKeys[0] ?? '');
    if ($selectedRegionKey === '') {
        $cache[$cacheKey] = $empty;
        return $empty;
    }
    $selectedRegionRow = is_array($regionBestRow[$selectedRegionKey] ?? null) ? $regionBestRow[$selectedRegionKey] : $best;
    $zone = trim(strval($selectedRegionRow['zone_name'] ?? ''));
    $region = trim(strval($selectedRegionRow['region_name'] ?? ''));
    if ($region === '' && $zone !== '') {
        $region = stobeResolveRegionFromAnyToken($zone);
    }
    if ($region === '') {
        $region = trim(strval($selectedRegionKey));
    }
    $selectedDistance = floatval($regionBestDist[$selectedRegionKey] ?? $bestDistance);
    $zoneConfidenceDistance = 12000.0;
    if ($selectedDistance > $zoneConfidenceDistance) {
        $zone = '';
    }

    $building = '';
    if ($allowBuilding && $zone !== '' && $selectedDistance <= 3000.0) {
        $building = trim(strval($selectedRegionRow['building_name'] ?? ''));
    }

    $rawLocationParts = [];
    if ($building !== '') {
        $rawLocationParts[] = $building;
    }
    if ($zone !== '') {
        $rawLocationParts[] = $zone;
    }
    if ($region !== '') {
        $rawLocationParts[] = $region;
    }
    $candidate = [
        'location' => count($rawLocationParts) > 0 ? implode(', ', array_values(array_unique($rawLocationParts))) : '',
        'building' => $building,
        'zone' => $zone,
        'city' => $zone,
        'region' => $region,
    ];

    $normalized = stobeNormalizeGeoContext($candidate);
    if (trim(strval($normalized['zone'] ?? '')) === '' && trim(strval($normalized['region'] ?? '')) === '') {
        $cache[$cacheKey] = $empty;
        return $empty;
    }

    stobeLogDebug('GEO_DEBUG_COORDINATE_FALLBACK', [
        'x' => $x,
        'y' => $y,
        'z' => $z,
        'allow_building' => $allowBuilding ? '1' : '0',
        'best_distance' => $bestDistance,
        'selected_distance' => $selectedDistance,
        'selected_region' => $region,
        'region_scores' => $regionScores,
        'candidate' => $candidate,
        'normalized' => $normalized,
    ]);

    $cache[$cacheKey] = $normalized;
    return $normalized;
}

function getRecentEventGeoFallback(string $participant = '', int $windowSeconds = 1800): array {
    static $cache = [];
    $key = strtolower(trim($participant)) . '|' . strval($windowSeconds);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $db = $GLOBALS["db"];
    $hasGeoColumn = stobeEnsureEventlogGeoColumn();
    $window = $windowSeconds;
    if ($window < 60) {
        $window = 60;
    } elseif ($window > 86400) {
        $window = 86400;
    }
    $cutoff = time() - $window;

    $row = null;
    $normalized = normalizeParticipantNameToken($participant);
    if ($normalized !== '') {
        $nameLower = strtolower($normalized);
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $nameLower);
        $row = $db->fetchOne(
            ($hasGeoColumn
                ? "SELECT location, geo
              FROM eventlog
              WHERE localts > $1
                AND (
                    COALESCE(location, '') <> ''
                    OR COALESCE(geo::text, '{}') <> '{}'
                )
                AND (
                     LOWER(data) LIKE $2 ESCAPE '\\'
                     OR LOWER(people) LIKE $3 ESCAPE '\\'
                )
              ORDER BY localts DESC, ts DESC, rowid DESC
              LIMIT 1"
                : "SELECT location
              FROM eventlog
              WHERE localts > $1
                AND COALESCE(location, '') <> ''
                AND (
                     LOWER(data) LIKE $2 ESCAPE '\\'
                     OR LOWER(people) LIKE $3 ESCAPE '\\'
                )
              ORDER BY localts DESC, ts DESC, rowid DESC
              LIMIT 1"),
            [
                $cutoff,
                $escaped . ':%',
                '%"' . $escaped . '|%',
            ]
        );
    }

    if (!$row) {
        $row = $db->fetchOne(
            ($hasGeoColumn
                ? "SELECT location, geo
              FROM eventlog
              WHERE localts > $1
                AND (
                    COALESCE(location, '') <> ''
                    OR COALESCE(geo::text, '{}') <> '{}'
                )
              ORDER BY localts DESC, ts DESC, rowid DESC
              LIMIT 1"
                : "SELECT location
              FROM eventlog
              WHERE localts > $1
                AND COALESCE(location, '') <> ''
              ORDER BY localts DESC, ts DESC, rowid DESC
              LIMIT 1"),
            [$cutoff]
        );
    }

    $geoRaw = $row['geo'] ?? null;
    if (is_string($geoRaw) && trim($geoRaw) !== '') {
        $decodedGeo = json_decode($geoRaw, true);
        if (is_array($decodedGeo)) {
            $cache[$key] = stobeNormalizeGeoContext($decodedGeo);
            return $cache[$key];
        }
    } elseif (is_array($geoRaw)) {
        $cache[$key] = stobeNormalizeGeoContext($geoRaw);
        return $cache[$key];
    }

    $location = trim(strval($row['location'] ?? ''));
    $cache[$key] = $location !== ''
        ? stobeNormalizeGeoContext(['location' => $location, 'building' => '', 'zone' => '', 'region' => ''])
        : ['location' => '', 'building' => '', 'zone' => '', 'city' => '', 'region' => ''];
    return $cache[$key];
}

function resolveEventGeoContext(string $normalizedType, string $eventData): array {
    $resolved = ['location' => '', 'building' => '', 'zone' => '', 'city' => '', 'region' => ''];
    $speakerHint = '';
    $targetHint = '';

    $queryBuilding = trim(strval($_GET['loc_building'] ?? ''));
    $queryZone = trim(strval($_GET['loc_zone'] ?? ''));
    $queryRegion = trim(strval($_GET['loc_region'] ?? ''));

    $fromQuery = [
        'location' => trim(strval($_GET['location'] ?? '')),
        'building' => $queryBuilding,
        'zone' => trim(strval($_GET['city'] ?? '')),
        'city' => trim(strval($_GET['city'] ?? '')),
        'region' => trim(strval($_GET['region'] ?? '')),
    ];
    if ($fromQuery['zone'] === '' && $queryZone !== '') {
        $fromQuery['zone'] = $queryZone;
        $fromQuery['city'] = $queryZone;
    }
    if ($fromQuery['region'] === '' && $queryRegion !== '') {
        $fromQuery['region'] = $queryRegion;
    }
    if ($fromQuery['region'] === '') {
        $queryTownLike = trim(strval($fromQuery['zone'] !== '' ? $fromQuery['zone'] : ($fromQuery['city'] ?? '')));
        if ($queryTownLike === '') {
            $queryTownLike = trim(strval($fromQuery['location'] ?? ''));
        }
        if ($queryTownLike !== '') {
            $mappedRegion = stobeResolveRegionFromAnyToken($queryTownLike);
            if ($mappedRegion !== '') {
                $fromQuery['region'] = $mappedRegion;
            }
        }
    }
    $queryIndoorsRaw = strtolower(trim(strval($_GET['loc_indoors'] ?? '')));
    $queryOutdoors = in_array($queryIndoorsRaw, ['0', 'false', 'no', 'off'], true);
    $queryIndoors = in_array($queryIndoorsRaw, ['1', 'true', 'yes', 'on'], true);
    $queryX = stobeParseNullableCoordinate($_GET['loc_x'] ?? null);
    $queryY = stobeParseNullableCoordinate($_GET['loc_y'] ?? null);
    $queryZ = stobeParseNullableCoordinate($_GET['loc_z'] ?? null);
    stobeLogDebug('GEO_DEBUG_RESOLVE_START', [
        'type' => $normalizedType,
        'event_data' => substr($eventData, 0, 220),
        'query' => [
            'location' => strval($_GET['location'] ?? ''),
            'city' => strval($_GET['city'] ?? ''),
            'region' => strval($_GET['region'] ?? ''),
            'loc_building' => strval($_GET['loc_building'] ?? ''),
            'loc_zone' => strval($_GET['loc_zone'] ?? ''),
            'loc_region' => strval($_GET['loc_region'] ?? ''),
            'loc_indoors' => strval($_GET['loc_indoors'] ?? ''),
            'loc_x' => strval($_GET['loc_x'] ?? ''),
            'loc_y' => strval($_GET['loc_y'] ?? ''),
            'loc_z' => strval($_GET['loc_z'] ?? ''),
        ],
    ]);
    if ($queryOutdoors) {
        // Outdoors world context should not retain stale building values.
        // Keep zone/town when present (for example "Rot, Swamp").
        if ($fromQuery['region'] === '') {
            $queryTownLike = trim(strval($fromQuery['zone'] !== '' ? $fromQuery['zone'] : ($fromQuery['city'] ?? '')));
            if ($queryTownLike === '') {
                $queryTownLike = trim(strval($fromQuery['location'] ?? ''));
            }
            if ($queryTownLike !== '') {
                $mappedRegion = stobeResolveRegionFromAnyToken($queryTownLike);
                if ($mappedRegion !== '') {
                    $fromQuery['region'] = $mappedRegion;
                }
            }
        }
        $fromQuery['building'] = '';
        if ($fromQuery['zone'] !== '') {
            $fromQuery['city'] = $fromQuery['zone'];
            $fromQuery['location'] = $fromQuery['zone'];
        } elseif ($fromQuery['region'] !== '') {
            $fromQuery['location'] = $fromQuery['region'];
        }
        $hasOutdoorGeo = (
            $fromQuery['location'] !== ''
            || $fromQuery['zone'] !== ''
            || $fromQuery['region'] !== ''
        );
        if (!$hasOutdoorGeo) {
            $derivedRegion = trim(strval($GLOBALS["CACHE_REGION"] ?? ''));
            if ($derivedRegion === '') {
                $profileHint = normalizeParticipantNameToken(strval($_GET['profile'] ?? ''));
                $fallbackGeo = getRecentEventGeoFallback($profileHint, 86400);
                $derivedRegion = trim(strval($fallbackGeo['region'] ?? ''));
            }
            if ($derivedRegion !== '') {
                $fromQuery['region'] = $derivedRegion;
                $fromQuery['location'] = $derivedRegion;
                $fromQuery['zone'] = '';
                $fromQuery['city'] = '';
                stobeLogDebug('GEO_DEBUG_RESOLVE_OUTDOOR_REGION_FALLBACK', [
                    'type' => $normalizedType,
                    'region' => $derivedRegion,
                    'profile' => strval($_GET['profile'] ?? ''),
                ]);
            }
        }
    }
    $hasQueryGeo = (
        $fromQuery['location'] !== ''
        || $fromQuery['building'] !== ''
        || $fromQuery['zone'] !== ''
        || $fromQuery['region'] !== ''
    );
    $resolved = mergeEventGeoContext($resolved, $fromQuery);

    // Current request geo is authoritative and should not be cross-filled by
    // historical fallbacks.
    if ($hasQueryGeo) {
        if (($resolved['zone'] === '' || $resolved['region'] === '') && ($queryX !== null || $queryZ !== null)) {
            $resolved = mergeEventGeoContext(
                $resolved,
                getEventGeoFromCoordinateFallback($queryX, $queryY, $queryZ, $queryIndoors && !$queryOutdoors)
            );
        }
        $queryNormalizedInput = $resolved;
        $queryNormalizedInput['allow_unknown_zone'] = true;
        $normalized = stobeNormalizeGeoContext($queryNormalizedInput);
        $normalized['_source_query'] = '1';
        stobeLogDebug('GEO_DEBUG_RESOLVE_RESULT', [
            'type' => $normalizedType,
            'source' => 'query',
            'resolved' => $normalized,
        ]);
        return $normalized;
    }

    if (in_array($normalizedType, ['location', 'infoloc'], true)) {
        $parsedEventGeo = extractEventGeoFromLocationUpdateMessage($eventData);
        if (
            $parsedEventGeo['location'] === '' &&
            trim(strval($parsedEventGeo['zone'] ?? ($parsedEventGeo['city'] ?? ''))) === '' &&
            $parsedEventGeo['region'] === ''
        ) {
            $parsedEventGeo = extractEventGeoFromString($eventData);
        }
        $resolved = mergeEventGeoContext($resolved, $parsedEventGeo);
    }

    $coordinateOnlyQuery = (
        !$hasQueryGeo
        && ($queryX !== null || $queryZ !== null)
    );
    $coordinateFallbackLocked = false;
    if ($coordinateOnlyQuery) {
        $coordinateGeo = getEventGeoFromCoordinateFallback(
            $queryX,
            $queryY,
            $queryZ,
            $queryIndoors && !$queryOutdoors
        );
        if (
            trim(strval($coordinateGeo['zone'] ?? '')) !== ''
            || trim(strval($coordinateGeo['region'] ?? '')) !== ''
        ) {
            $resolved = mergeEventGeoContext($resolved, $coordinateGeo);
            $coordinateFallbackLocked = true;
        }
    }

    $profileName = normalizeParticipantNameToken(strval($_GET['profile'] ?? ''));
    if (!$coordinateFallbackLocked && $profileName !== '') {
        $resolved = mergeEventGeoContext($resolved, getEventGeoFromNpcName($profileName));
    }

    if (
        !$coordinateFallbackLocked
        && ($resolved['zone'] === '' || $resolved['region'] === '')
    ) {
        $resolved = mergeEventGeoContext(
            $resolved,
            getEventGeoFromCoordinateFallback($queryX, $queryY, $queryZ, $queryIndoors && !$queryOutdoors)
        );
    }

    if (
        !$coordinateFallbackLocked &&
        in_array($normalizedType, ['inputtext', 'inputtext_s', 'chat', 'rechat', 'bored', 'action', 'infoaction', 'lockpicked', 'lockpiked', 'infonpc', 'infoloc'], true) &&
        strpos($eventData, ':') !== false
    ) {
        $speakerHint = normalizeParticipantNameToken(explode(':', $eventData, 2)[0] ?? '');
        if ($speakerHint !== '') {
            $resolved = mergeEventGeoContext($resolved, getEventGeoFromNpcName($speakerHint));
        }
    }
    if (
        !$coordinateFallbackLocked &&
        in_array($normalizedType, ['inputtext', 'inputtext_s', 'chat', 'rechat', 'bored', 'action', 'infoaction', 'lockpicked', 'lockpiked', 'infonpc', 'infoloc'], true) &&
        stripos($eventData, '(talking to:') !== false
    ) {
        $targetExtract = extractDialogueTarget($eventData);
        $targetHint = normalizeParticipantNameToken(strval($targetExtract['target'] ?? ''));
        if ($targetHint !== '') {
            $resolved = mergeEventGeoContext($resolved, getEventGeoFromNpcName($targetHint));
        }
    }

    $peopleRaw = strval($GLOBALS["CACHE_PEOPLE"] ?? ($_GET['people'] ?? ''));
    if (!$coordinateFallbackLocked && trim($peopleRaw) !== '') {
        $decodedPeople = json_decode($peopleRaw, true);
        if (is_array($decodedPeople)) {
            foreach ($decodedPeople as $participantToken) {
                if (!is_string($participantToken)) {
                    continue;
                }
                $participantName = normalizeParticipantNameToken($participantToken);
                if ($participantName === '') {
                    continue;
                }
                $resolved = mergeEventGeoContext($resolved, getEventGeoFromNpcName($participantName));
                if (
                    $resolved['zone'] !== '' &&
                    $resolved['region'] !== ''
                ) {
                    break;
                }
            }
        }
    }

    if (!$coordinateFallbackLocked) {
        if ($resolved['location'] === '' || $resolved['zone'] === '' || $resolved['region'] === '') {
            $fallbackParticipant = $speakerHint !== '' ? $speakerHint : $targetHint;
            $resolved = mergeEventGeoContext($resolved, getRecentEventGeoFallback($fallbackParticipant, 86400));
        }
        if ($resolved['location'] === '' || $resolved['zone'] === '' || $resolved['region'] === '') {
            $resolved = mergeEventGeoContext($resolved, getRecentEventGeoFallback('', 86400));
        }
    }

    $normalized = stobeNormalizeGeoContext($resolved);
    stobeLogDebug('GEO_DEBUG_RESOLVE_RESULT', [
        'type' => $normalizedType,
        'source' => 'fallback',
        'speaker_hint' => $speakerHint,
        'target_hint' => $targetHint,
        'resolved' => $normalized,
    ]);
    return $normalized;
}

function composeEventLocationText(array $geo): string {
    $normalizeInput = $geo;
    if (!array_key_exists('allow_unknown_zone', $normalizeInput)) {
        if (strval($normalizeInput['_source_query'] ?? '') === '1') {
            $normalizeInput['allow_unknown_zone'] = true;
        }
    }
    $normalizedGeo = stobeNormalizeGeoContext($normalizeInput);
    $location = trim(strval($normalizedGeo['location'] ?? ''));
    $building = trim(strval($normalizedGeo['building'] ?? ''));
    $zone = trim(strval($normalizedGeo['zone'] ?? ($normalizedGeo['city'] ?? '')));
    $region = trim(strval($normalizedGeo['region'] ?? ''));

    $parts = [];
    $pushPart = static function (array &$into, string $value): void {
        $candidate = trim($value);
        if ($candidate === '') {
            return;
        }
        foreach ($into as $existing) {
            if (strcasecmp($existing, $candidate) === 0) {
                return;
            }
        }
        $into[] = $candidate;
    };

    $pushPart($parts, $building);
    $pushPart($parts, $zone);
    $pushPart($parts, $region);

    if (count($parts) > 0) {
        return implode(', ', $parts);
    }

    return '';
}

function stobeNormalizeZoneLocationToken(string $value): string {
    return stobeNormalizeGeoLabel($value);
}

function stobeParseNullableCoordinate(mixed $value): ?float {
    if (is_int($value) || is_float($value)) {
        return floatval($value);
    }
    if (is_string($value)) {
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }
        if (preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $normalized) === 1) {
            return floatval($normalized);
        }
    }
    return null;
}

function stobeExtractZoneSnapshotContext(array $snapshot): array {
    $environment = [];
    if (isset($snapshot['environment']) && is_array($snapshot['environment'])) {
        $environment = $snapshot['environment'];
    } elseif (isset($snapshot['environment']) && is_string($snapshot['environment'])) {
        $decodedEnvironment = json_decode(strval($snapshot['environment']), true);
        if (is_array($decodedEnvironment)) {
            $environment = $decodedEnvironment;
        }
    }

    $pickText = static function (array $source, array $keys): string {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            if (is_array($source[$key]) || is_object($source[$key])) {
                continue;
            }
            $value = stobeNormalizeZoneLocationToken(strval($source[$key]));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    };

    $pickCoordinate = static function (array $primary, array $fallback, string $key): ?float {
        $fromPrimary = stobeParseNullableCoordinate($primary[$key] ?? null);
        if ($fromPrimary !== null) {
            return $fromPrimary;
        }
        return stobeParseNullableCoordinate($fallback[$key] ?? null);
    };

    $zoneName = $pickText($environment, ['zone_name', 'zone']);
    if ($zoneName === '') {
        $zoneName = $pickText($snapshot, ['zone_name', 'zone']);
    }
    $zoneName = stobeCanonicalizeKenshiZoneName($zoneName);

    $cityName = $pickText($environment, ['town_name', 'town', 'city', 'settlement']);
    if ($cityName === '') {
        $cityName = $pickText($snapshot, ['town_name', 'town', 'city', 'settlement']);
    }
    $cityName = stobeNormalizeZoneLocationToken($cityName);

    if ($zoneName === '') {
        $zoneName = stobeResolveKenshiZoneFromTown($cityName);
    }
    if ($zoneName === '') {
        if (stobeIsKnownZoneName($cityName)) {
            $zoneName = $cityName;
        }
    }
    // Keep location_zones keyed on zone only.

    $x = $pickCoordinate($environment, $snapshot, 'x');
    $y = $pickCoordinate($environment, $snapshot, 'y');
    $z = $pickCoordinate($environment, $snapshot, 'z');

    $metadata = [];
    foreach (['indoors', 'outdoors', 'in_town', 'weather', 'weather_name', 'weather_strength', 'weather_affect_strength', 'wind_speed', 'wind_direction', 'wetness', 'active_environmental_effects'] as $field) {
        if (array_key_exists($field, $environment)) {
            $metadata[$field] = $environment[$field];
        }
    }

    return [
        'zone_name' => $zoneName,
        'city_name' => $cityName,
        'x' => $x,
        'y' => $y,
        'z' => $z,
        'metadata' => $metadata,
    ];
}

function stobeUpsertLocationZoneFromSnapshot(array $snapshot, int $gamets, string $observerName = ''): bool {
    if (!coerceBoolean($snapshot['is_player_character'] ?? false)) {
        return false;
    }

    $zoneContext = stobeExtractZoneSnapshotContext($snapshot);
    $zoneName = stobeNormalizeZoneLocationToken(strval($zoneContext['zone_name'] ?? ''));
    if ($zoneName === '') {
        return false;
    }

    $cityName = stobeNormalizeZoneLocationToken(strval($zoneContext['city_name'] ?? ''));
    $x = $zoneContext['x'] ?? null;
    $y = $zoneContext['y'] ?? null;
    $z = $zoneContext['z'] ?? null;
    $safeGamets = max(0, intval($gamets));
    $nowTs = time();

    $metadata = is_array($zoneContext['metadata'] ?? null) ? $zoneContext['metadata'] : [];
    if ($observerName !== '') {
        $metadata['observer'] = $observerName;
    }
    $metadata['visited'] = true;
    $metadata['knowledge_only'] = false;
    $metadataJson = normalizeJsonString($metadata);

    $db = $GLOBALS["db"];
    try {
        $db->exec(
            "INSERT INTO location_zones (
                zone_name,
                city_name,
                x,
                y,
                z,
                first_game_ts,
                last_game_ts,
                first_seen_ts,
                last_seen_ts,
                metadata,
                updated_at
            ) VALUES (
                $1, $2, $3, $4, $5, $6, $7, $8, $8, $9::jsonb, NOW()
            )
            ON CONFLICT (zone_name) DO UPDATE SET
                city_name = CASE
                    WHEN NULLIF(EXCLUDED.city_name, '') IS NOT NULL THEN EXCLUDED.city_name
                    ELSE location_zones.city_name
                END,
                x = COALESCE(EXCLUDED.x, location_zones.x),
                y = COALESCE(EXCLUDED.y, location_zones.y),
                z = COALESCE(EXCLUDED.z, location_zones.z),
                first_game_ts = CASE
                    WHEN COALESCE(location_zones.first_game_ts, 0) <= 0 THEN EXCLUDED.first_game_ts
                    WHEN COALESCE(EXCLUDED.first_game_ts, 0) <= 0 THEN location_zones.first_game_ts
                    WHEN EXCLUDED.first_game_ts < location_zones.first_game_ts THEN EXCLUDED.first_game_ts
                    ELSE location_zones.first_game_ts
                END,
                last_game_ts = CASE
                    WHEN EXCLUDED.last_game_ts > location_zones.last_game_ts THEN EXCLUDED.last_game_ts
                    ELSE location_zones.last_game_ts
                END,
                last_seen_ts = EXCLUDED.last_seen_ts,
                metadata = CASE
                    WHEN EXCLUDED.metadata = '{}'::jsonb THEN location_zones.metadata
                    ELSE location_zones.metadata || EXCLUDED.metadata
                END,
                updated_at = NOW()",
            [
                $zoneName,
                $cityName,
                $x,
                $y,
                $z,
                $safeGamets,
                $safeGamets,
                $nowTs,
                $metadataJson,
            ]
        );
    } catch (Throwable $exception) {
        stobeLogException($exception, 'Failed to upsert location zone', [
            'zone_name' => $zoneName,
            'observer' => $observerName,
            'gamets' => $safeGamets,
        ]);
        return false;
    }

    return true;
}

function stobeStoreTownKnowledgeSnapshot(array $towns, int $gamets): array {
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return ['stored' => 0, 'removed' => 0, 'rejected' => count($towns)];
    }

    $safeGamets = max(0, $gamets);
    $nowTs = time();
    $stored = 0;
    $rejected = 0;
    $knownNames = [];

    foreach (array_slice($towns, 0, 512) as $town) {
        if (!is_array($town) || !coerceBoolean($town['discovered'] ?? false)) {
            $rejected++;
            continue;
        }

        $name = stobeNormalizeZoneLocationToken(strval($town['name'] ?? ''));
        if ($name === '') {
            $rejected++;
            continue;
        }

        $coordinate = static function (mixed $value): ?float {
            if (!is_numeric($value)) {
                return null;
            }
            $number = floatval($value);
            return is_finite($number) ? $number : null;
        };
        $x = $coordinate($town['x'] ?? null);
        $y = $coordinate($town['y'] ?? null);
        $z = $coordinate($town['z'] ?? null);
        $explored = coerceBoolean($town['explored'] ?? false);
        $metadata = [
            'knowledge_source' => 'town_list',
            'discovered' => true,
            'explored' => $explored,
            'knowledge_only' => !$explored,
            'town_type' => intval($town['town_type'] ?? 0),
        ];

        try {
            $db->exec(
                "INSERT INTO location_zones (
                    zone_name, city_name, x, y, z,
                    first_game_ts, last_game_ts, first_seen_ts, last_seen_ts,
                    metadata, updated_at
                ) VALUES ($1, $1, $2, $3, $4, 0, $5, $6, $6, $7::jsonb, NOW())
                ON CONFLICT (zone_name) DO UPDATE SET
                    city_name = CASE
                        WHEN NULLIF(location_zones.city_name, '') IS NULL THEN EXCLUDED.city_name
                        ELSE location_zones.city_name
                    END,
                    x = COALESCE(location_zones.x, EXCLUDED.x),
                    y = COALESCE(location_zones.y, EXCLUDED.y),
                    z = COALESCE(location_zones.z, EXCLUDED.z),
                    last_game_ts = GREATEST(location_zones.last_game_ts, EXCLUDED.last_game_ts),
                    last_seen_ts = EXCLUDED.last_seen_ts,
                    metadata = location_zones.metadata || EXCLUDED.metadata ||
                        CASE
                            WHEN COALESCE(location_zones.first_game_ts, 0) > 0
                              OR location_zones.metadata->>'visited' = 'true'
                            THEN '{\"knowledge_only\":false,\"visited\":true}'::jsonb
                            ELSE '{}'::jsonb
                        END,
                    updated_at = NOW()",
                [$name, $x, $y, $z, $safeGamets, $nowTs, normalizeJsonString($metadata)]
            );
            $knownNames[strtolower($name)] = true;
            $stored++;
        } catch (Throwable $exception) {
            $rejected++;
            stobeLogException($exception, 'Failed to store discovered town knowledge', [
                'town' => $name,
                'gamets' => $safeGamets,
            ]);
        }
    }

    $removed = 0;
    try {
        $existing = $db->fetchAll(
            "SELECT id, zone_name
             FROM location_zones
             WHERE metadata->>'knowledge_source' = 'town_list'
               AND metadata->>'knowledge_only' = 'true'"
        );
        foreach ($existing as $row) {
            $existingName = strtolower(stobeNormalizeZoneLocationToken(strval($row['zone_name'] ?? '')));
            if ($existingName !== '' && isset($knownNames[$existingName])) {
                continue;
            }
            $id = intval($row['id'] ?? 0);
            if ($id > 0) {
                $db->exec('DELETE FROM location_zones WHERE id = $1', [$id]);
                $removed++;
            }
        }
    } catch (Throwable $exception) {
        stobeLogException($exception, 'Failed to prune stale town knowledge');
    }

    return ['stored' => $stored, 'removed' => $removed, 'rejected' => $rejected];
}

function stobeEnsureEventlogGeoColumn(): bool {
    static $checked = false;
    static $available = false;
    if ($checked) {
        return $available;
    }
    $checked = true;

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        $available = false;
        return false;
    }

    try {
        $row = $db->fetchOne(
            "SELECT 1 AS ok
             FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name = 'eventlog'
               AND column_name = 'geo'
             LIMIT 1"
        );
        if (is_array($row) && isset($row['ok'])) {
            $available = true;
            return true;
        }
    } catch (Throwable $exception) {
        $available = false;
        return false;
    }

    try {
        $db->exec("ALTER TABLE eventlog ADD COLUMN IF NOT EXISTS geo JSONB DEFAULT '{}'::jsonb");
        $available = true;
        return true;
    } catch (Throwable $exception) {
        stobeLogException($exception, 'Failed to ensure eventlog.geo column');
        $available = false;
        return false;
    }
}

function stobeEnsureEventlogDeliveryColumns(): bool {
    static $checked = false;
    static $available = false;
    if ($checked) {
        return $available;
    }
    $checked = true;

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        $available = false;
        return false;
    }

    try {
        $columns = $db->fetchAll(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name = 'eventlog'
               AND column_name IN ('utterance_id', 'delivery_state')"
        );
        $present = [];
        foreach ((array)$columns as $columnRow) {
            $columnName = strtolower(trim(strval($columnRow['column_name'] ?? '')));
            if ($columnName !== '') {
                $present[$columnName] = true;
            }
        }
        if (isset($present['utterance_id']) && isset($present['delivery_state'])) {
            $available = true;
            return true;
        }
    } catch (Throwable $exception) {
        $available = false;
        return false;
    }

    try {
        $db->exec("ALTER TABLE eventlog ADD COLUMN IF NOT EXISTS utterance_id TEXT");
        $db->exec("ALTER TABLE eventlog ADD COLUMN IF NOT EXISTS delivery_state TEXT");
        $available = true;
        return true;
    } catch (Throwable $exception) {
        stobeLogException($exception, 'Failed to ensure eventlog delivery columns');
        $available = false;
        return false;
    }
}

function stobeBuildEventlogDeliveryVisibilitySql(string $tableAlias = 'eventlog'): string {
    if (!stobeEnsureEventlogDeliveryColumns()) {
        return '1=1';
    }

    $qualified = trim($tableAlias);
    if ($qualified !== '') {
        $qualified = rtrim($qualified, '.') . '.';
    }

    return "(COALESCE(NULLIF({$qualified}utterance_id, ''), '') = ''"
        . " OR LOWER(COALESCE({$qualified}delivery_state, 'spoken')) = 'spoken')";
}

function stobeEventlogRowIsVisibleForContext(array $row): bool {
    $utteranceId = trim(strval($row['utterance_id'] ?? ''));
    if ($utteranceId === '') {
        return true;
    }

    $deliveryState = strtolower(trim(strval($row['delivery_state'] ?? 'spoken')));
    return ($deliveryState === 'spoken');
}

function storeEvent(
    string $type,
    int $ts,
    int $gamets,
    string $data,
    string $sess = 'pending',
    string $utteranceId = '',
    string $deliveryState = '',
    bool $trackMemory = true
): ?array {
    $normalizedType = strtolower(trim($type));
    if ($normalizedType === 'npc_snapshot' || $normalizedType === 'playerinfo') {
        return null;
    }

    $safeUtteranceId = trim($utteranceId);
    $normalizedDeliveryState = strtolower(trim($deliveryState));
    if ($safeUtteranceId !== '' && $normalizedDeliveryState === '') {
        $normalizedDeliveryState = 'pending';
    }
    if ($safeUtteranceId === '') {
        $normalizedDeliveryState = '';
    }

    $rawData = strval($data);
    $data = stobeSanitizeEventDataForLog($normalizedType, $rawData);

    $geo = resolveEventGeoContext($normalizedType, $data);
    $eventLocation = composeEventLocationText($geo);
    if ($eventLocation !== '') {
        $GLOBALS["CACHE_LOCATION"] = $eventLocation;
    }
    $GLOBALS["CACHE_CITY"] = trim(strval($geo['zone'] ?? ($geo['city'] ?? '')));
    $GLOBALS["CACHE_REGION"] = trim(strval($geo['region'] ?? ''));

    $db = $GLOBALS["db"];
    $eventLocalTs = time();
    $peopleCache = $GLOBALS["CACHE_PEOPLE"] ?? '';
    $locationCache = $GLOBALS["CACHE_LOCATION"] ?? '';

    if (
        $safeUtteranceId === ''
        && (
        $normalizedType === 'chat'
        && strcasecmp(trim($sess), 'inputtext_chat_mirror') !== 0
        && stobeShouldSkipMirroredPlayerChatRow($db, $data, $eventLocalTs)
        )
    ) {
        return null;
    }
    if (
        $safeUtteranceId === ''
        && (
        ($normalizedType === 'chat' || $normalizedType === 'rechat')
        && stobeShouldSkipDuplicateDialogueEchoRow($db, $normalizedType, $data, $eventLocalTs, $gamets)
        )
    ) {
        return null;
    }

    $geoPayloadInput = [
        'location' => trim(strval($geo['location'] ?? '')),
        'building' => trim(strval($geo['building'] ?? '')),
        'zone' => trim(strval($geo['zone'] ?? ($geo['city'] ?? ''))),
        'region' => trim(strval($geo['region'] ?? '')),
    ];
    if (strval($geo['_source_query'] ?? '') === '1') {
        $geoPayloadInput['allow_unknown_zone'] = true;
    }
    $geoPayload = stobeNormalizeGeoContext($geoPayloadInput);
    $geoPayload['city'] = trim(strval($geoPayload['zone'] ?? ''));
    $geoJson = json_encode($geoPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($geoJson) || trim($geoJson) === '') {
        $geoJson = '{}';
    }
    stobeLogDebug('GEO_DEBUG_STORE_EVENT', [
        'type' => $normalizedType,
        'gamets' => $gamets,
        'data' => substr($data, 0, 220),
        'geo' => $geo,
        'event_location' => $eventLocation,
        'cache_location' => strval($GLOBALS["CACHE_LOCATION"] ?? ''),
        'geo_payload' => $geoPayload,
    ]);

    $inserted = null;
    $hasGeoColumn = stobeEnsureEventlogGeoColumn();
    $hasDeliveryColumns = stobeEnsureEventlogDeliveryColumns();
    if ($hasGeoColumn && $hasDeliveryColumns) {
        $inserted = $db->fetchOne(
            "INSERT INTO eventlog (
                type,
                ts,
                gamets,
                data,
                sess,
                localts,
                people,
                location,
                geo,
                utterance_id,
                delivery_state
             )
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9::jsonb, $10, $11)
             RETURNING rowid, localts",
            [
                $type,
                $ts,
                $gamets,
                $data,
                $sess,
                $eventLocalTs,
                $peopleCache,
                $locationCache,
                $geoJson,
                ($safeUtteranceId !== '' ? $safeUtteranceId : null),
                ($normalizedDeliveryState !== '' ? $normalizedDeliveryState : null),
            ]
        );
    } elseif ($hasGeoColumn) {
        $inserted = $db->fetchOne(
            "INSERT INTO eventlog (type, ts, gamets, data, sess, localts, people, location, geo)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9::jsonb)
             RETURNING rowid, localts",
            [
                $type,
                $ts,
                $gamets,
                $data,
                $sess,
                $eventLocalTs,
                $peopleCache,
                $locationCache,
                $geoJson,
            ]
        );
    } else {
        $inserted = $db->fetchOne(
            "INSERT INTO eventlog (type, ts, gamets, data, sess, localts, people, location)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
             RETURNING rowid, localts",
            [
                $type,
                $ts,
                $gamets,
                $data,
                $sess,
                $eventLocalTs,
                $peopleCache,
                $locationCache,
            ]
        );
    }

    if (is_array($inserted)) {
        $eventLocalTs = intval($inserted['localts'] ?? $eventLocalTs);
    }

    if ($data !== $rawData) {
        stobeLogDebug('Event data sanitized before eventlog insert', [
            'type' => $normalizedType,
            'before' => substr($rawData, 0, 240),
            'after' => substr($data, 0, 240),
        ]);
    }

    $shouldTrackMemory = (
        $trackMemory
        && ($safeUtteranceId === '' || $normalizedDeliveryState === '' || $normalizedDeliveryState === 'spoken')
    );
    if ($shouldTrackMemory && function_exists('stobeMemoryTrackEvent')) {
        stobeMemoryTrackEvent(
            strval($type),
            intval($ts),
            intval($gamets),
            strval($data),
            strval($peopleCache),
            strval($locationCache),
            $eventLocalTs
        );
    }

    return is_array($inserted) ? $inserted : null;
}

function stobePersistGeneratedSpeechChunk(
    array $chunk,
    int $gamets,
    string $defaultEventType = '',
    string $defaultListener = ''
): ?array {
    stobeInteractionRequire();
    $fallbackEventType = strtolower(trim($defaultEventType));
    $fallbackListener = normalizeParticipantNameToken($defaultListener);
    if ($fallbackListener === '') {
        $fallbackListener = trim($defaultListener);
    }

    $action = trim(strval($chunk['action'] ?? ''));
    if ($action !== '' && strcasecmp($action, 'ScriptQueue') !== 0) {
        return null;
    }

    $actor = normalizeParticipantNameToken(strval($chunk['actor'] ?? ''));
    if ($actor === '') {
        $actor = trim(strval($chunk['actor'] ?? ''));
    }
    $message = stobeStripParentheticalDialogueText(trim(strval($chunk['message'] ?? '')));
    $utteranceId = trim(strval($chunk['utterance_id'] ?? ''));
    if ($actor === '' || $message === '' || $utteranceId === '' || $gamets <= 0) {
        return null;
    }

    $chunkEventType = strtolower(trim(strval($chunk['event_type'] ?? '')));
    if ($chunkEventType === '') {
        $chunkEventType = $fallbackEventType;
    }
    if ($chunkEventType === '') {
        $chunkEventType = 'chat';
    }

    $chunkListener = normalizeParticipantNameToken(strval($chunk['listener'] ?? ''));
    if ($chunkListener === '') {
        $chunkListener = trim(strval($chunk['listener'] ?? ''));
    }
    if (function_exists('stobeNormalizeDialogueTargetToken')) {
        $chunkListener = stobeNormalizeDialogueTargetToken($chunkListener);
    }
    if ($chunkListener === '') {
        $chunkListener = $fallbackListener;
    }
    if ($chunkListener !== '' && function_exists('stobeNormalizeDialogueTargetToken')) {
        $chunkListener = stobeNormalizeDialogueTargetToken($chunkListener);
    }

    $chatEventData = $actor . ': ' . $message;
    if ($chunkListener !== '') {
        $chatEventData .= ' (talking to: ' . $chunkListener . ')';
    }
    $inserted = storeEvent(
        $chunkEventType,
        time(),
        $gamets,
        $chatEventData,
        'pending',
        $utteranceId,
        'pending',
        false
    );
    $persistedRowId = intval($inserted['rowid'] ?? 0);
    $persistedLocalTs = intval($inserted['localts'] ?? 0);

    return [
        'rowid' => $persistedRowId,
        'localts' => $persistedLocalTs,
        'utterance_id' => $utteranceId,
        'event_type' => $chunkEventType,
        'listener' => $chunkListener,
    ];
}

function stobePersistGeneratedSpeechChunks(
    array $chunks,
    int $gamets,
    string $defaultEventType = '',
    string $defaultListener = ''
): int {
    $stored = 0;
    foreach ($chunks as $chunk) {
        if (!is_array($chunk)) {
            continue;
        }
        if (is_array(stobePersistGeneratedSpeechChunk($chunk, $gamets, $defaultEventType, $defaultListener))) {
            $stored++;
        }
    }

    return $stored;
}

function stobeFetchEventlogRowsByUtteranceId(
    $db,
    string $utteranceId,
    int $waitForRowsMs = 0,
    int $pollIntervalMs = 100
): array {
    $safeUtteranceId = trim($utteranceId);
    if ($safeUtteranceId === '' || !$db) {
        return [];
    }

    $remainingMs = max(0, intval($waitForRowsMs));
    $sleepMs = max(25, intval($pollIntervalMs));
    $attempt = 0;
    $maxWaitMs = $remainingMs;

    while (true) {
        $rows = $db->fetchAll(
            "SELECT rowid, type, ts, gamets, data, people, location, localts, delivery_state
             FROM eventlog
             WHERE utterance_id = $1
             ORDER BY rowid ASC",
            [$safeUtteranceId]
        );
        if (is_array($rows) && count($rows) > 0) {
            return $rows;
        }
        if ($remainingMs <= 0) {
            stobeLogWarn('Speech delivery lookup missed utterance row', [
                'utterance_id' => $safeUtteranceId,
                'attempts' => $attempt,
                'waited_ms' => max(0, $maxWaitMs - $remainingMs),
            ]);
            return [];
        }

        usleep($sleepMs * 1000);
        $remainingMs -= $sleepMs;
        $attempt++;
    }
}

function stobeApplySpeechDeliveryUpdates(array $updates): array {
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobeEnsureEventlogDeliveryColumns()) {
        return [
            'applied' => 0,
            'spoken' => 0,
            'cancelled' => 0,
            'memory_rows' => 0,
        ];
    }

    $normalizedUpdates = [];
    foreach ($updates as $update) {
        if (!is_array($update)) {
            continue;
        }
        $utteranceId = trim(strval($update['utterance_id'] ?? ''));
        $deliveryState = strtolower(trim(strval($update['delivery_state'] ?? '')));
        if ($utteranceId === '' || !in_array($deliveryState, ['spoken', 'cancelled'], true)) {
            continue;
        }
        if (!isset($normalizedUpdates[$utteranceId]) || $deliveryState === 'spoken') {
            $normalizedUpdates[$utteranceId] = $deliveryState;
        }
    }

    $result = [
        'applied' => 0,
        'spoken' => 0,
        'cancelled' => 0,
        'memory_rows' => 0,
    ];

    foreach ($normalizedUpdates as $utteranceId => $deliveryState) {
        $rows = stobeFetchEventlogRowsByUtteranceId($db, $utteranceId, 5000, 100);
        if (!is_array($rows) || count($rows) === 0) {
            stobeLogWarn('Speech delivery update had no matching utterance rows', [
                'utterance_id' => $utteranceId,
                'requested_state' => $deliveryState,
            ]);
            continue;
        }

        if ($deliveryState === 'spoken') {
            // A cancelled Director turn must never be resurrected by a late playback acknowledgement.
            $directorGuard = str_starts_with($utteranceId, 'director-')
                ? " AND LOWER(COALESCE(delivery_state, 'pending')) = 'pending'" : '';
            $deliveryResult = $db->exec(
                "UPDATE eventlog
                 SET delivery_state = 'spoken',
                     sess = CASE
                         WHEN LOWER(COALESCE(sess, '')) IN ('', 'pending', 'cancelled') THEN 'complete'
                         ELSE sess
                     END
                 WHERE utterance_id = $1
                   AND LOWER(COALESCE(delivery_state, 'pending')) <> 'spoken'" . $directorGuard
                    . ($directorGuard !== '' ? ' RETURNING *' : ''),
                [$utteranceId]
            );

            // Only the request that transitions a Director line may commit it to memory.
            if ($directorGuard !== '') $rows = $deliveryResult ? (pg_fetch_all($deliveryResult) ?: []) : [];

            foreach ($rows as $row) {
                $previousState = strtolower(trim(strval($row['delivery_state'] ?? 'pending')));
                if ($directorGuard === '' && $previousState === 'spoken') {
                    continue;
                }
                $result['spoken']++;
                $result['applied']++;
                if (function_exists('stobeMemoryTrackEvent')) {
                    $memoryStored = stobeMemoryTrackEvent(
                        strval($row['type'] ?? ''),
                        intval($row['ts'] ?? 0),
                        intval($row['gamets'] ?? 0),
                        strval($row['data'] ?? ''),
                        strval($row['people'] ?? ''),
                        strval($row['location'] ?? ''),
                        intval($row['localts'] ?? 0)
                    );
                    $result['memory_rows'] += max(0, $memoryStored);
                }
            }
            continue;
        }

        $db->exec(
            "UPDATE eventlog
             SET delivery_state = 'cancelled',
                 sess = 'cancelled'
             WHERE utterance_id = $1
               AND LOWER(COALESCE(delivery_state, 'pending')) = 'pending'",
            [$utteranceId]
        );

        foreach ($rows as $row) {
            $previousState = strtolower(trim(strval($row['delivery_state'] ?? 'pending')));
            if ($previousState !== 'pending' && $previousState !== '') {
                continue;
            }
            $result['cancelled']++;
            $result['applied']++;
        }
    }

    return $result;
}

function stobeNormalizeDialogueForMirrorDedupe(string $rawMessage): string {
    // The game echoes literal speech without the server's non-spoken mood cue.
    $rawMessage = preg_replace('/\s*\[Player tone: [^\]]*\]\s*$/u', '', $rawMessage) ?? $rawMessage;
    $normalized = strtolower(trim(stobeSanitizeDialogueMessageForLog($rawMessage)));
    // Common corruption tail: stray trailing digits (for example "...join us6").
    $normalized = preg_replace('/(?<=\p{L})\d{1,2}\s*$/u', '', $normalized) ?? $normalized;
    $normalized = preg_replace('/[[:space:][:punct:]]+$/u', '', $normalized) ?? $normalized;
    $normalized = preg_replace('/\s{2,}/u', ' ', $normalized) ?? $normalized;
    return trim($normalized);
}

function stobeShouldSkipMirroredPlayerChatRow($db, string $chatData, int $eventLocalTs): bool {
    if (!$db) {
        return false;
    }

    $parsedChat = parseDialogueEventData($chatData);
    $chatSpeaker = normalizeParticipantNameToken(strval($parsedChat['speaker'] ?? ''));
    $chatTarget = normalizeParticipantNameToken(strval($parsedChat['target'] ?? ''));
    $chatMessageNorm = stobeNormalizeDialogueForMirrorDedupe(strval($parsedChat['message'] ?? ''));
    if ($chatSpeaker === '' || $chatMessageNorm === '') {
        return false;
    }

    $recentRows = $db->fetchAll(
        "SELECT rowid, data, localts
         FROM eventlog
         WHERE type IN ('inputtext', 'inputtext_s')
            AND localts >= $1
            AND LOWER(data) LIKE LOWER($2)
         ORDER BY rowid DESC
         LIMIT 8",
        [
            $eventLocalTs - 8,
            $chatSpeaker . ':%',
        ]
    );

    if (!is_array($recentRows) || count($recentRows) === 0) {
        return false;
    }

    foreach ($recentRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $inputData = strval($row['data'] ?? '');
        if ($inputData === '') {
            continue;
        }
        $parsedInput = parseDialogueEventData($inputData);
        $inputSpeaker = normalizeParticipantNameToken(strval($parsedInput['speaker'] ?? ''));
        if ($inputSpeaker === '' || strcasecmp($inputSpeaker, $chatSpeaker) !== 0) {
            continue;
        }
        $inputMessageNorm = stobeNormalizeDialogueForMirrorDedupe(strval($parsedInput['message'] ?? ''));
        if ($inputMessageNorm === '') {
            continue;
        }
        if ($inputMessageNorm !== $chatMessageNorm) {
            continue;
        }

        stobeLogDebug('Skipped mirrored player chat row', [
            'speaker' => $chatSpeaker,
            'target' => $chatTarget,
            'message' => $chatMessageNorm,
            'matched_input_rowid' => intval($row['rowid'] ?? 0),
            'matched_input_localts' => intval($row['localts'] ?? 0),
        ]);
        return true;
    }

    return false;
}

function stobeSanitizeDialogueMessageForLog(string $message): string {
    $moodCue = '';
    if (preg_match('/\s*(\[Player tone: [^\]]*\])\s*$/u', $message, $match) === 1) {
        // Mood text is already normalized; do not treat Unicode custom cues as game corruption.
        $moodCue = ' ' . $match[1];
        $message = substr($message, 0, -strlen($match[0]));
    }
    $clean = sanitizeForKenshi(strval($message));
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $clean) ?? $clean;
    $clean = trim($clean);

    // Strip common corrupted UTF-8/question-mark suffixes emitted by game payload noise.
    // Example: "want to join our cult6??х??????Ѽ? ɕ???" -> "want to join our cult"
    $clean = preg_replace('/(?<=\p{L})\d+\?{2,}.*$/u', '', $clean) ?? $clean;
    $clean = preg_replace('/\?{2,}[^\r\n]*[^\x00-\x7F][^\r\n]*$/u', '', $clean) ?? $clean;
    $clean = preg_replace('/[^\x00-\x7F]{2,}[^\r\n]*$/u', '', $clean) ?? $clean;

    // Strip repeated trailing question marks that are emitted as corruption tails.
    $clean = preg_replace('/\s+\?{2,}\s*$/u', '', $clean) ?? $clean;
    // Strip known Kenshi payload corruption where a solitary "7" is appended to dialogue.
    $clean = preg_replace('/(?<=\D)7+\s*$/u', '', $clean) ?? $clean;
    // Strip trailing numeric tails after punctuation, e.g. ")7" or "!?12".
    $clean = preg_replace('/([\.!\?\)\]])\d{1,3}\s*$/u', '$1', $clean) ?? $clean;
    $clean = preg_replace('/\s{2,}/u', ' ', $clean) ?? $clean;

    return trim($clean) . $moodCue;
}

function stobeChooseIndefiniteArticle(string $value): string {
    $trimmed = trim($value);
    if ($trimmed === '') {
        return 'a';
    }
    $first = strtolower(substr($trimmed, 0, 1));
    return in_array($first, ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';
}

function stobeNormalizeWeaponPhraseWithArticle(string $rawWeapon): string {
    $weapon = trim($rawWeapon);
    if ($weapon === '') {
        return '';
    }
    $weapon = preg_replace('/\s{2,}/u', ' ', $weapon) ?? $weapon;
    if (preg_match('/^(?:a|an|the)\b/iu', $weapon) === 1) {
        return $weapon;
    }
    return stobeChooseIndefiniteArticle($weapon) . ' ' . $weapon;
}

function stobeNormalizeKnockoutEventData(string $rawEventData): string {
    $source = trim($rawEventData);
    if ($source === '') {
        return '';
    }
    if (!function_exists('parseDialogueEventData')) {
        return $source;
    }

    $parsed = parseDialogueEventData($source);
    $attacker = normalizeParticipantNameToken(strval($parsed['speaker'] ?? ''));
    $victim = normalizeParticipantNameToken(strval($parsed['target'] ?? ''));
    $message = trim(strval($parsed['message'] ?? ''));
    if ($message === '') {
        return $source;
    }
    if (preg_match('/^knocked\s+out\s+with\s+(.+)$/iu', $message, $match) !== 1) {
        return $source;
    }

    $weapon = stobeNormalizeWeaponPhraseWithArticle(strval($match[1] ?? ''));
    if ($weapon === '') {
        $weapon = 'an unknown weapon';
    }
    if ($victim === '') {
        $victim = $attacker !== '' ? $attacker : 'Unknown';
    }

    $rewritten = 'Knocked out by ' . $weapon;
    if ($attacker !== '' && strcasecmp($attacker, $victim) !== 0) {
        $rewritten .= ' from ' . $attacker;
    }

    return $victim . ': ' . $rewritten;
}

function stobeNormalizeLimbLossLabel(string $rawLimb): string {
    $limb = strtolower(trim($rawLimb));
    if ($limb === '') {
        return '';
    }

    $limb = preg_replace('/\s{2,}/u', ' ', $limb) ?? $limb;
    $limb = preg_replace('/^(?:the|a|an|their)\s+/iu', '', $limb) ?? $limb;
    $token = strtoupper(str_replace(' ', '_', $limb));

    $map = [
        'LEFT_ARM' => 'left arm',
        'RIGHT_ARM' => 'right arm',
        'LEFT_LEG' => 'left leg',
        'RIGHT_LEG' => 'right leg',
        'LEFT_HAND' => 'left hand',
        'RIGHT_HAND' => 'right hand',
        'LEFT_FOOT' => 'left foot',
        'RIGHT_FOOT' => 'right foot',
        'HEAD' => 'head',
        'TORSO' => 'torso',
        'CHEST' => 'chest',
        'STOMACH' => 'stomach',
    ];
    if (isset($map[$token])) {
        return $map[$token];
    }

    $normalized = strtolower(str_replace('_', ' ', $token));
    $normalized = preg_replace('/\s{2,}/u', ' ', $normalized) ?? $normalized;
    return trim($normalized);
}

function stobeParseLimbLossEventData(string $rawEventData): array {
    $source = trim($rawEventData);
    if ($source === '') {
        return [
            'attacker' => '',
            'victim' => '',
            'limb' => '',
            'weapon' => '',
            'message' => '',
        ];
    }

    $parsed = function_exists('parseDialogueEventData')
        ? parseDialogueEventData($source)
        : ['speaker' => '', 'target' => '', 'message' => $source];
    $attacker = normalizeParticipantNameToken(strval($parsed['speaker'] ?? ''));
    $victim = normalizeParticipantNameToken(strval($parsed['target'] ?? ''));
    $message = trim(strval($parsed['message'] ?? $source));
    if ($message === '') {
        $message = $source;
    }

    $limb = '';
    $weapon = '';

    if (preg_match('/\b(?:severed|cuts?\s+off|chopped\s+off)\s+(?:the\s+)?(.+?)\s+from\s+(.+?)(?:\s+with\s+(.+))?$/iu', $message, $match) === 1) {
        $limb = stobeNormalizeLimbLossLabel(strval($match[1] ?? ''));
        $victimFromMessage = normalizeParticipantNameToken(strval($match[2] ?? ''));
        if ($victim === '' && $victimFromMessage !== '') {
            $victim = $victimFromMessage;
        }
        $weapon = trim(strval($match[3] ?? ''));
    } elseif (preg_match('/\b(?:lost|loses|has\s+lost)\s+(?:the\s+|their\s+)?(.+?)(?:\s+to\s+(.+))?$/iu', $message, $match) === 1) {
        $limb = stobeNormalizeLimbLossLabel(strval($match[1] ?? ''));
        if ($victim === '' && $attacker !== '') {
            $victim = $attacker;
        }
        $weapon = trim(strval($match[2] ?? ''));
    }

    return [
        'attacker' => $attacker,
        'victim' => $victim,
        'limb' => $limb,
        'weapon' => $weapon,
        'message' => $message,
    ];
}

function stobeCanonicalizeCarryActionCommand(string $rawCommand): string {
    $upper = strtoupper(trim($rawCommand));
    if ($upper === '') {
        return '';
    }

    $aliases = [
        'STOPCARRYING' => 'STOP_CARRYING',
        'DROPNPC' => 'STOP_CARRYING',
        'DROP_NPC' => 'STOP_CARRYING',
        'DROP-NPC' => 'STOP_CARRYING',
        'PUTDOWNNPC' => 'STOP_CARRYING',
        'PUT_DOWN_NPC' => 'STOP_CARRYING',
        'PUT-DOWN-NPC' => 'STOP_CARRYING',
        'RELEASENPC' => 'STOP_CARRYING',
        'RELEASE_NPC' => 'STOP_CARRYING',
        'RELEASE-NPC' => 'STOP_CARRYING',
        'PICKUPNPC' => 'PICKUP_NPC',
        'PICKUP-NPC' => 'PICKUP_NPC',
        'KIDNAP' => 'PICKUP_NPC',
    ];
    if (array_key_exists($upper, $aliases)) {
        return $aliases[$upper];
    }

    $upper = str_replace('-', '_', $upper);
    return preg_replace('/\s+/', '_', $upper) ?? $upper;
}

function stobeParseCarryActionEventData(string $rawEventData): array {
    $result = [
        'ok' => false,
        'actor' => '',
        'command' => '',
        'target' => '',
        'raw_action' => '',
    ];

    $line = trim($rawEventData);
    if ($line === '') {
        return $result;
    }
    $line = preg_replace('/^\[[^\]]+\]\s*/u', '', $line) ?? $line;

    $actor = '';
    $body = $line;
    if (preg_match('/^([^:]+):\s*(.+)$/u', $line, $parts) === 1) {
        $actor = normalizeParticipantNameToken(strval($parts[1] ?? ''));
        $body = trim(strval($parts[2] ?? ''));
    }
    if ($body === '') {
        return $result;
    }

    $body = preg_replace('/^\s*action\s+command\s+received\s*:\s*/iu', '', $body) ?? $body;
    $body = preg_replace('/\s*\[source:[^\]]+\]\s*$/iu', '', $body) ?? $body;
    $body = preg_replace('/\s*\(source:[^)]+\)\s*$/iu', '', $body) ?? $body;
    $body = preg_replace('/\s*\(\s*talking to:[^)]+\)\s*$/iu', '', $body) ?? $body;
    $body = trim($body);
    if ($body === '') {
        return $result;
    }

    if (preg_match('/^([A-Za-z_\-]+)\s*@\s*(.*)$/u', $body, $match) !== 1) {
        return $result;
    }

    $command = stobeCanonicalizeCarryActionCommand(strval($match[1] ?? ''));
    if (!in_array($command, ['PICKUP_NPC', 'STOP_CARRYING'], true)) {
        return $result;
    }

    $target = normalizeParticipantNameToken(strval($match[2] ?? ''));
    if (in_array(strtolower($target), ['someone', 'their carried target'], true)) {
        $target = '';
    }

    $result['ok'] = true;
    $result['actor'] = $actor;
    $result['command'] = $command;
    $result['target'] = $target;
    $result['raw_action'] = $body;
    return $result;
}

function stobeFetchNpcMetadataByName(string $npcName): array {
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return [];
    }
    $safeName = normalizeParticipantNameToken($npcName);
    if ($safeName === '') {
        return [];
    }

    $row = $db->fetchOne(
        "SELECT metadata
         FROM core_npc_master
         WHERE LOWER(name) = LOWER($1)
         LIMIT 1",
        [$safeName]
    );
    if (!$row) {
        return [];
    }
    return normalizeCoreNpcMetadata($row['metadata'] ?? []);
}

function stobePatchNpcMetadataByName(string $npcName, array $patch): bool {
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return false;
    }
    $safeName = normalizeParticipantNameToken($npcName);
    if ($safeName === '') {
        return false;
    }

    $row = $db->fetchOne(
        "SELECT metadata
         FROM core_npc_master
         WHERE LOWER(name) = LOWER($1)
         LIMIT 1",
        [$safeName]
    );
    if (!$row) {
        return false;
    }

    $metadata = normalizeCoreNpcMetadata($row['metadata'] ?? []);
    $changed = false;
    foreach ($patch as $key => $value) {
        $metaKey = trim(strval($key));
        if ($metaKey === '') {
            continue;
        }

        if ($value === null || (is_string($value) && trim($value) === '')) {
            if (array_key_exists($metaKey, $metadata)) {
                unset($metadata[$metaKey]);
                $changed = true;
            }
            continue;
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        if (!array_key_exists($metaKey, $metadata) || $metadata[$metaKey] !== $value) {
            $metadata[$metaKey] = $value;
            $changed = true;
        }
    }

    if (!$changed) {
        return true;
    }

    $metadataJson = normalizeJsonString($metadata);
    $result = $db->exec(
        "UPDATE core_npc_master
         SET metadata = $2::jsonb,
             updated_at = NOW()
         WHERE LOWER(name) = LOWER($1)",
        [$safeName, $metadataJson]
    );
    return $result !== false;
}

function stobeApplyCarryMetadataFromActionEvent(string $eventData, int $gamets = 0, string $eventType = ''): bool {
    $parsed = stobeParseCarryActionEventData($eventData);
    if (!boolval($parsed['ok'] ?? false)) {
        return false;
    }

    $actorName = normalizeParticipantNameToken(strval($parsed['actor'] ?? ''));
    $command = trim(strval($parsed['command'] ?? ''));
    $targetName = normalizeParticipantNameToken(strval($parsed['target'] ?? ''));
    if ($actorName === '' || $command === '') {
        return false;
    }

    $actorMetadata = stobeFetchNpcMetadataByName($actorName);
    $priorTarget = normalizeParticipantNameToken(strval($actorMetadata['carrying_target_name'] ?? ''));
    $eventTypeLabel = trim(strtolower($eventType));

    if ($command === 'PICKUP_NPC') {
        $actorPatch = [
            'is_carrying' => true,
        ];
        if ($targetName !== '') {
            $actorPatch['carrying_target_name'] = $targetName;
        } else {
            $actorPatch['carrying_target_name'] = null;
        }
        stobePatchNpcMetadataByName($actorName, $actorPatch);

        if ($targetName !== '') {
            if ($priorTarget !== '' && strcasecmp($priorTarget, $targetName) !== 0) {
                stobePatchNpcMetadataByName($priorTarget, [
                    'is_being_carried' => false,
                    'carried_by_name' => null,
                ]);
            }
            stobePatchNpcMetadataByName($targetName, [
                'is_being_carried' => true,
                'carried_by_name' => $actorName,
            ]);
        }

        stobeLogImport('Carry metadata updated from action event', [
            'event_type' => $eventTypeLabel,
            'command' => $command,
            'actor' => $actorName,
            'target' => $targetName,
            'gamets' => max(0, intval($gamets)),
        ], 'DEBUG');
        return true;
    }

    if ($command === 'STOP_CARRYING') {
        $resolvedTarget = $targetName !== '' ? $targetName : $priorTarget;
        stobePatchNpcMetadataByName($actorName, [
            'is_carrying' => false,
            'carrying_target_name' => null,
        ]);

        if ($resolvedTarget !== '') {
            stobePatchNpcMetadataByName($resolvedTarget, [
                'is_being_carried' => false,
                'carried_by_name' => null,
            ]);
        }

        stobeLogImport('Carry metadata updated from action event', [
            'event_type' => $eventTypeLabel,
            'command' => $command,
            'actor' => $actorName,
            'target' => $resolvedTarget,
            'gamets' => max(0, intval($gamets)),
        ], 'DEBUG');
        return true;
    }

    return false;
}

function stobeLimbLossHasNearbyRemoveLimbAction(int $limbLossRowId, string $victimName = ''): bool {
    if ($limbLossRowId <= 0) {
        return false;
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return false;
    }

    $windowStart = max(1, $limbLossRowId - 24);
    $windowEnd = $limbLossRowId + 4;
    $rows = $db->fetchAll(
        "SELECT data
         FROM eventlog
         WHERE rowid >= $1
           AND rowid <= $2
           AND type IN ('action', 'infoaction')
         ORDER BY rowid DESC
         LIMIT 24",
        [$windowStart, $windowEnd]
    );
    if (!is_array($rows) || count($rows) === 0) {
        return false;
    }

    $victim = normalizeParticipantNameToken($victimName);
    $victimNeedle = strtolower($victim);
    foreach ($rows as $row) {
        $data = strval($row['data'] ?? '');
        if ($data === '') {
            continue;
        }
        $dataLower = strtolower($data);
        if (strpos($dataLower, 'remove_limb@') === false) {
            continue;
        }
        if ($victimNeedle !== '' && strpos($dataLower, 'remove_limb@' . strtolower($victim) . '@') === false) {
            continue;
        }
        return true;
    }

    return false;
}

function stobeGetLimbLossRechatCursorKey(): string {
    return 'RECHAT_LIMB_LOSS_LAST_ROWID';
}

function stobeGetLimbLossRechatCooldownKey(): string {
    return 'RECHAT_LIMB_LOSS_COOLDOWN_UNTIL_TS';
}

function stobeGetLimbLossRechatCooldownSeconds(): int {
    return 30;
}

function stobeArmLimbLossRechatCooldown(int $seconds = 0): void {
    if ($seconds <= 0) {
        $seconds = stobeGetLimbLossRechatCooldownSeconds();
    }
    if ($seconds <= 0) {
        return;
    }
    setConfOpt(stobeGetLimbLossRechatCooldownKey(), strval(time() + $seconds), true);
}

function stobeConsumeLimbLossRechatReaction(int $rowId, bool $applyCooldown = true): void {
    if ($rowId <= 0) {
        return;
    }

    $cursorKey = stobeGetLimbLossRechatCursorKey();
    $cursor = max(0, intval(getConfOpt($cursorKey, '0')));
    if ($rowId <= $cursor) {
        return;
    }
    setConfOpt($cursorKey, strval($rowId), true);
    if ($applyCooldown) {
        stobeArmLimbLossRechatCooldown();
    }
}

function stobeResolvePendingLimbLossRechatReaction(array $conversationParticipants = [], int $windowSeconds = 240): array {
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return [];
    }

    $window = $windowSeconds;
    if ($window < 30) {
        $window = 30;
    } elseif ($window > 1800) {
        $window = 1800;
    }

    $participantSet = [];
    foreach ($conversationParticipants as $rawName) {
        $name = normalizeParticipantNameToken(strval($rawName));
        if ($name === '') {
            continue;
        }
        $participantSet[strtolower($name)] = $name;
    }
    $scopeByParticipants = count($participantSet) > 0;

    $cursorKey = stobeGetLimbLossRechatCursorKey();
    $cursor = max(0, intval(getConfOpt($cursorKey, '0')));
    $cooldownUntil = max(0, intval(getConfOpt(stobeGetLimbLossRechatCooldownKey(), '0')));
    $rows = $db->fetchAll(
        "SELECT rowid, ts, gamets, localts, data
         FROM eventlog
         WHERE type = 'limb_loss'
           AND rowid > $1
         ORDER BY rowid DESC
         LIMIT 32",
        [$cursor]
    );
    if (!is_array($rows) || count($rows) === 0) {
        return [];
    }

    $nowTs = time();
    if ($cooldownUntil > $nowTs) {
        $newestRowId = intval($rows[0]['rowid'] ?? 0);
        if ($newestRowId > $cursor) {
            // Drop limb-loss triggers that happen during cooldown.
            setConfOpt($cursorKey, strval($newestRowId), true);
        }
        stobeLogDebug('Limb-loss rechat suppressed by cooldown', [
            'cooldown_until' => $cooldownUntil,
            'seconds_remaining' => $cooldownUntil - $nowTs,
            'dropped_up_to_rowid' => $newestRowId,
        ]);
        return [];
    }

    $highestStaleRowId = 0;
    foreach ($rows as $row) {
        $rowId = intval($row['rowid'] ?? 0);
        if ($rowId <= 0) {
            continue;
        }

        $localTs = intval($row['localts'] ?? 0);
        if ($localTs > 0 && $localTs < ($nowTs - $window)) {
            if ($rowId > $highestStaleRowId) {
                $highestStaleRowId = $rowId;
            }
            continue;
        }

        $parsed = stobeParseLimbLossEventData(strval($row['data'] ?? ''));
        $victim = normalizeParticipantNameToken(strval($parsed['victim'] ?? ''));
        if ($victim === '') {
            continue;
        }

        if ($scopeByParticipants && !isset($participantSet[strtolower($victim)])) {
            continue;
        }

        $weapon = trim(strval($parsed['weapon'] ?? ''));
        $hacksawContext = stripos($weapon, 'hacksaw') !== false;
        if (!$hacksawContext) {
            $hacksawContext = stobeLimbLossHasNearbyRemoveLimbAction($rowId, $victim);
        }

        return [
            'rowid' => $rowId,
            'gamets' => intval($row['gamets'] ?? 0),
            'localts' => $localTs,
            'data' => strval($row['data'] ?? ''),
            'attacker' => normalizeParticipantNameToken(strval($parsed['attacker'] ?? '')),
            'victim' => $victim,
            'limb' => stobeNormalizeLimbLossLabel(strval($parsed['limb'] ?? '')),
            'weapon' => $weapon,
            'hacksaw' => $hacksawContext,
        ];
    }

    // Advance past stale rows so old unmatched sever events do not linger forever.
    if ($highestStaleRowId > $cursor) {
        setConfOpt($cursorKey, strval($highestStaleRowId), true);
    }

    return [];
}

function stobeExtractStatusEventActorName(string $eventType, string $eventData): string {
    $normalizedType = strtolower(trim($eventType));
    $clean = trim($eventData);
    if ($clean === '') {
        return '';
    }

    if (function_exists('parseDialogueEventData')) {
        $parsed = parseDialogueEventData($clean);
        $speaker = normalizeParticipantNameToken(strval($parsed['speaker'] ?? ''));
        if ($speaker !== '') {
            return $speaker;
        }
    }

    if (preg_match('/^([^:]+)\s*:/u', $clean, $match) === 1) {
        $actor = normalizeParticipantNameToken(strval($match[1] ?? ''));
        if ($actor !== '') {
            return $actor;
        }
    }

    if ($normalizedType === 'knockout' && preg_match('/^(.+?)\s+was\s+knocked\s+out\b/iu', $clean, $match) === 1) {
        return normalizeParticipantNameToken(strval($match[1] ?? ''));
    }
    if ($normalizedType === 'death' && preg_match('/^(.+?)\s+(?:has\s+)?(?:died|was\s+slain|is\s+dead)\b/iu', $clean, $match) === 1) {
        return normalizeParticipantNameToken(strval($match[1] ?? ''));
    }
    if ($normalizedType === 'recovered' && preg_match('/^(.+?)\s+regained\s+consciousness\b/iu', $clean, $match) === 1) {
        return normalizeParticipantNameToken(strval($match[1] ?? ''));
    }

    return '';
}

function stobeShouldSuppressLimbLossRechatForVictim(
    string $victimName,
    int $limbLossRowId,
    int $limbLossLocalTs = 0,
    array|false $victimData = false,
    int $recoveryGraceSeconds = 3
): array {
    $victim = normalizeParticipantNameToken($victimName);
    if ($victim === '') {
        return [
            'suppress' => true,
            'reason' => 'missing_victim',
        ];
    }

    if (!is_array($victimData)) {
        $victimData = getNpcData($victim);
    }
    $currentState = '';
    if (is_array($victimData)) {
        $currentState = stobeResolveNpcAwarenessState($victimData);
        if (in_array($currentState, ['dead', 'unconscious', 'knocked_out', 'sleeping'], true)) {
            return [
                'suppress' => true,
                'reason' => 'victim_state_' . $currentState,
                'state' => $currentState,
            ];
        }
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db || $limbLossRowId <= 0) {
        return [
            'suppress' => false,
            'reason' => '',
            'state' => $currentState,
        ];
    }

    $sinceLocalTs = $limbLossLocalTs > 0 ? max(0, $limbLossLocalTs - 2) : max(0, time() - 180);
    $rows = $db->fetchAll(
        "SELECT rowid, type, data, localts
         FROM eventlog
         WHERE rowid > $1
           AND type IN ('knockout', 'death', 'recovered')
           AND localts >= $2
         ORDER BY rowid ASC
         LIMIT 96",
        [$limbLossRowId, $sinceLocalTs]
    );

    $latestRecoveredLocalTs = 0;
    $latestRecoveredRowId = 0;
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $eventType = strtolower(trim(strval($row['type'] ?? '')));
            $actor = stobeExtractStatusEventActorName($eventType, strval($row['data'] ?? ''));
            if ($actor === '' || strcasecmp($actor, $victim) !== 0) {
                continue;
            }

            $rowId = intval($row['rowid'] ?? 0);
            $rowLocalTs = intval($row['localts'] ?? 0);
            if ($eventType === 'knockout' || $eventType === 'death') {
                return [
                    'suppress' => true,
                    'reason' => $eventType . '_after_limb_loss',
                    'event_type' => $eventType,
                    'event_rowid' => $rowId,
                    'event_localts' => $rowLocalTs,
                    'state' => $currentState,
                ];
            }
            if ($eventType === 'recovered') {
                if ($rowLocalTs >= $latestRecoveredLocalTs) {
                    $latestRecoveredLocalTs = $rowLocalTs;
                    $latestRecoveredRowId = $rowId;
                }
            }
        }
    }

    if ($recoveryGraceSeconds > 0 && $latestRecoveredLocalTs > 0) {
        $elapsed = time() - $latestRecoveredLocalTs;
        if ($elapsed >= 0 && $elapsed < $recoveryGraceSeconds) {
            return [
                'suppress' => true,
                'reason' => 'recovery_grace_window',
                'event_type' => 'recovered',
                'event_rowid' => $latestRecoveredRowId,
                'event_localts' => $latestRecoveredLocalTs,
                'elapsed_seconds' => $elapsed,
                'state' => $currentState,
            ];
        }
    }

    return [
        'suppress' => false,
        'reason' => '',
        'state' => $currentState,
    ];
}

function stobeLoadRecentDialogueTargetForSpeaker(string $speakerName): string {
    $speaker = normalizeParticipantNameToken($speakerName);
    if ($speaker === '') {
        return '';
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return '';
    }

    $row = $db->fetchOne(
        "SELECT data
         FROM eventlog
         WHERE type IN ('chat', 'rechat', 'bored', 'inputtext', 'inputtext_s')
           AND localts >= $2
           AND LOWER(data) LIKE LOWER($1)
           AND data ILIKE '%(talking to:%'
         ORDER BY rowid DESC
         LIMIT 1",
        [$speaker . ':%', time() - 1200]
    );
    if (!is_array($row)) {
        return '';
    }

    $data = trim(strval($row['data'] ?? ''));
    if ($data === '') {
        return '';
    }

    $targetExtract = extractDialogueTarget($data);
    $target = function_exists('stobeNormalizeDialogueTargetToken')
        ? stobeNormalizeDialogueTargetToken(strval($targetExtract['target'] ?? ''))
        : normalizeParticipantNameToken(strval($targetExtract['target'] ?? ''));
    if ($target === '' || strcasecmp($target, $speaker) === 0) {
        return '';
    }
    return $target;
}

function stobeInferDialogueTargetForLog(string $speakerName): string {
    $speaker = normalizeParticipantNameToken($speakerName);
    if ($speaker === '') {
        return '';
    }

    $playerName = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    $profileName = normalizeParticipantNameToken(strval($_GET['profile'] ?? ''));
    if (
        $profileName !== ''
        && strcasecmp($profileName, $speaker) !== 0
        && ($playerName === '' || strcasecmp($profileName, $playerName) !== 0)
    ) {
        return $profileName;
    }

    $peopleRaw = strval($GLOBALS['CACHE_PEOPLE'] ?? ($_GET['people'] ?? ''));
    $participants = extractParticipantIdentities([
        'people' => $peopleRaw,
        'profile' => $profileName,
        'speaker' => $speaker,
    ]);

    $candidateByKey = [];
    foreach ($participants as $participant) {
        if (!is_array($participant)) {
            continue;
        }
        $name = normalizeParticipantNameToken(strval($participant['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        if (strcasecmp($name, $speaker) === 0) {
            continue;
        }
        if ($playerName !== '' && strcasecmp($name, $playerName) === 0) {
            continue;
        }
        if (strcasecmp($name, 'The Narrator') === 0) {
            continue;
        }
        $candidateByKey[strtolower($name)] = $name;
    }

    if (count($candidateByKey) === 1) {
        return array_values($candidateByKey)[0];
    }

    $recentTarget = stobeLoadRecentDialogueTargetForSpeaker($speaker);
    if ($recentTarget !== '') {
        return $recentTarget;
    }

    return '';
}

function stobeSanitizeEventDataForLog(string $normalizedType, string $rawData): string {
    $clean = sanitizeForKenshi(strval($rawData));

    // Strip control bytes that can leak in from malformed payload fragments.
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $clean) ?? $clean;
    $clean = trim($clean);

    // Malformed target suffixes can arrive as "9talking to)" or "(talking to: )".
    $clean = preg_replace('/\s*\(?\d+\s*talking to\)\s*$/iu', '', $clean) ?? $clean;
    $clean = preg_replace('/\s*\(talking to:\s*\)\s*$/iu', '', $clean) ?? $clean;

    // Common corruption pattern: garbage suffix after a complete "(talking to: X)" segment.
    $clean = preg_replace(
        '/(\(talking to:\s*[^\)]+\))\s*(?:\?+|[0-9]{1,3})\s*$/iu',
        '$1',
        $clean
    ) ?? $clean;

    // Corruption can also appear immediately before a complete "(talking to: X)" segment.
    // Examples: "... 7 (talking to: Slowline)", "... ?? (talking to: Slowline)".
    $clean = preg_replace(
        '/\s+(?:\?{2,}|[0-9]{1,3})\s*(?=\(talking to:\s*[^\)]+\)\s*$)/iu',
        ' ',
        $clean
    ) ?? $clean;

    $speechTypes = ['inputtext', 'inputtext_s', 'chat', 'rechat', 'bored'];
    if (in_array($normalizedType, $speechTypes, true)) {
        $parsed = parseDialogueEventData($clean);
        $speaker = normalizeParticipantNameToken(strval($parsed['speaker'] ?? ''));
        $message = stobeSanitizeDialogueMessageForLog(strval($parsed['message'] ?? ''));
        if ($message === '') {
            $message = stobeSanitizeDialogueMessageForLog($clean);
        }
        $target = function_exists('stobeNormalizeDialogueTargetToken')
            ? stobeNormalizeDialogueTargetToken(strval($parsed['target'] ?? ''))
            : normalizeParticipantNameToken(strval($parsed['target'] ?? ''));
        if ($target === '' && $speaker !== '') {
            $target = stobeInferDialogueTargetForLog($speaker);
        }

        if ($speaker !== '' && $message !== '') {
            $clean = $speaker . ': ' . $message;
            if ($target !== '') {
                $clean .= ' (talking to: ' . $target . ')';
            }
        } else {
            $clean = stobeSanitizeDialogueMessageForLog($clean);
        }
    }

    if ($normalizedType === 'knockout') {
        $clean = stobeNormalizeKnockoutEventData($clean);
    }

    $contextOnlyTypes = ['infonpc', 'infonpc_close', 'infoloc', 'location', 'infoitems', 'carry'];
    if (in_array($normalizedType, $contextOnlyTypes, true)) {
        // Context telemetry rows are not directional dialogue.
        $clean = preg_replace('/\s*\(talking to:\s*[^\)]*\)\s*/iu', ' ', $clean) ?? $clean;
        $clean = preg_replace('/\s*\(?\d+\s*talking to\)\s*/iu', ' ', $clean) ?? $clean;
    }

    // Strip common corrupted dialogue tails while preserving normal punctuation.
    // Examples: "...7", ")7", "*Glug glug* ??".
    $clean = preg_replace('/([\.!\?\)\]])\d{1,3}\s*$/u', '$1', $clean) ?? $clean;
    $clean = preg_replace('/\s+\?{2,}\s*$/u', '', $clean) ?? $clean;

    if (!in_array($normalizedType, $speechTypes, true)) {
        // Non-dialogue events should not end with repeated "??" noise.
        $clean = preg_replace('/\s*\?{2,}\s*$/u', '', $clean) ?? $clean;
        // Strip accidental trailing digits like "consciousness7".
        $clean = preg_replace('/(?<=\p{L}|\))\d{1,2}\s*$/u', '', $clean) ?? $clean;
    }

    $clean = preg_replace('/\s{2,}/u', ' ', $clean) ?? $clean;
    return trim($clean);
}

function baseNameWithoutBracketSuffix(string $name): string {
    $trimmed = trim($name);
    if ($trimmed === '') {
        return '';
    }
    return trim((string)preg_replace('/\s*\[[^\]]+\]\s*$/u', '', $trimmed));
}

function stobeBuildFactionOccupationText(string $rawFaction): string {
    $factionName = baseNameWithoutBracketSuffix($rawFaction);
    if ($factionName === '') {
        $factionName = trim($rawFaction);
    }
    $factionName = trim((string)preg_replace('/^faction\s*:\s*/iu', '', $factionName));
    $factionName = trim((string)preg_replace('/\s{2,}/u', ' ', $factionName));
    if ($factionName === '') {
        return '';
    }
    if (preg_match('/\bfaction$/iu', $factionName) === 1) {
        return 'A member of the ' . $factionName;
    }
    return 'A member of the ' . $factionName . ' Faction';
}

function stobeBuildCanonicalFactionOccupationText(string $rawFaction): string {
    $factionName = baseNameWithoutBracketSuffix($rawFaction);
    if ($factionName === '') {
        $factionName = trim($rawFaction);
    }
    $factionName = trim((string)preg_replace('/^faction\s*:\s*/iu', '', $factionName));
    $factionName = trim((string)preg_replace('/\s{2,}/u', ' ', $factionName));
    if ($factionName === '') {
        return '';
    }
    return 'Faction: ' . $factionName;
}

function stobeNormalizeOccupationText(string $rawOccupation, string $fallbackFaction = ''): string {
    $occupation = trim($rawOccupation);
    if ($occupation === '') {
        $fallbackText = stobeBuildCanonicalFactionOccupationText($fallbackFaction);
        return $fallbackText;
    }

    if (preg_match('/^\s*faction(?:\s+member)?\s*:\s*(.+)$/iu', $occupation, $match) === 1) {
        $normalized = stobeBuildCanonicalFactionOccupationText(strval($match[1] ?? ''));
        if ($normalized !== '') {
            return $normalized;
        }
    }

    if (preg_match('/^\s*A member of the\s+(.+?)(?:\s+Faction)?\s*$/iu', $occupation, $match) === 1) {
        $normalizedFaction = trim($fallbackFaction) !== '' ? $fallbackFaction : strval($match[1] ?? '');
        $normalized = stobeBuildCanonicalFactionOccupationText($normalizedFaction);
        if ($normalized !== '') {
            return $normalized;
        }
    }

    $occupation = preg_replace_callback(
        '/\bFaction\s*:\s*([^|\n\r]+)/iu',
        static function (array $match): string {
            $normalized = stobeBuildCanonicalFactionOccupationText(strval($match[1] ?? ''));
            return $normalized !== '' ? $normalized : trim(strval($match[0] ?? ''));
        },
        $occupation
    ) ?? $occupation;

    $occupation = preg_replace_callback(
        '/(^|\|\s*)A member of the\s+([^|\n\r]+?)(?:\s+Faction)?(?=\s*(?:\||$))/iu',
        function (array $match) use ($fallbackFaction): string {
            $prefix = strval($match[1] ?? '');
            $normalizedFaction = trim($fallbackFaction) !== '' ? $fallbackFaction : strval($match[2] ?? '');
            $normalized = stobeBuildCanonicalFactionOccupationText($normalizedFaction);
            return $prefix . ($normalized !== '' ? $normalized : trim(strval($match[0] ?? '')));
        },
        $occupation
    ) ?? $occupation;

    return trim((string)preg_replace('/\s{2,}/u', ' ', $occupation));
}

function stobeNormalizeParticipantAwarenessStateToken(string $rawState): string {
    $value = strtolower(trim($rawState));
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/[\(\)\[\]]+/u', ' ', $value) ?? $value;
    $value = str_replace(['_', '-', '/', '\\'], ' ', $value);
    $value = trim((string)preg_replace('/\s{2,}/u', ' ', $value));
    if ($value === '' || in_array($value, ['normal', 'awake', 'none', 'n/a', 'na'], true)) {
        return '';
    }

    if (in_array($value, ['sleep', 'sleeping', 'asleep', 'sleep state'], true)) {
        return 'sleeping';
    }
    if (in_array($value, ['ko', 'knocked out', 'knockout', 'knockedout'], true)) {
        return 'knocked_out';
    }
    if (in_array($value, ['unconscious', 'passed out', 'passedout', 'blackout', 'incapacitated'], true)) {
        return 'unconscious';
    }
    if (in_array($value, ['dead', 'deceased', 'death'], true)) {
        return 'dead';
    }

    return '';
}

function stobeExtractParticipantAwarenessStateFromDisplayName(string $rawName): string {
    $value = trim($rawName);
    if ($value === '') {
        return '';
    }
    if (preg_match('/\(([^()]*)\)\s*$/u', $value, $matches) !== 1) {
        return '';
    }
    return stobeNormalizeParticipantAwarenessStateToken(strval($matches[1] ?? ''));
}

function stobeParticipantDisplayNameWithoutAwarenessTag(string $rawName): string {
    $value = trim($rawName);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^(.*)\(([^()]*)\)\s*$/u', $value, $matches) === 1) {
        $state = stobeNormalizeParticipantAwarenessStateToken(strval($matches[2] ?? ''));
        if ($state !== '') {
            $value = trim(strval($matches[1] ?? ''));
        }
    }

    return trim($value);
}

function stobeParticipantAwarenessTagLabel(string $state): string {
    $normalized = stobeNormalizeParticipantAwarenessStateToken($state);
    if ($normalized === 'sleeping') {
        return 'sleeping';
    }
    if ($normalized === 'knocked_out') {
        return 'knocked out';
    }
    if ($normalized === 'unconscious') {
        return 'unconscious';
    }
    return '';
}

function stobeApplyParticipantAwarenessTag(string $name, string $state): string {
    $baseName = stobeParticipantDisplayNameWithoutAwarenessTag($name);
    if ($baseName === '') {
        return '';
    }

    $label = stobeParticipantAwarenessTagLabel($state);
    if ($label === '') {
        return $baseName;
    }
    return $baseName . ' (' . $label . ')';
}

function stobeNpcArrayFieldToAssoc(mixed $raw): array {
    if (is_array($raw)) {
        return $raw;
    }
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return [];
}

function stobeResolveNpcAwarenessState(array $npcData): string {
    $state = stobeNormalizeParticipantAwarenessStateToken(strval($npcData['character_state'] ?? ''));
    if ($state !== '') {
        return $state;
    }

    $metadata = stobeNpcArrayFieldToAssoc($npcData['metadata'] ?? []);
    if (is_array($metadata)) {
        $metaState = stobeNormalizeParticipantAwarenessStateToken(strval($metadata['character_state'] ?? ''));
        if ($metaState !== '') {
            return $metaState;
        }
        $medical = stobeNpcArrayFieldToAssoc($metadata['medical'] ?? []);
        if (!empty($medical['is_unconscious']) || !empty($medical['is_knocked_out']) || !empty($medical['is_knockedout'])) {
            return 'unconscious';
        }
        if (!empty($metadata['is_sleeping']) || !empty($metadata['sleeping'])) {
            return 'sleeping';
        }
    }

    $extended = stobeNpcArrayFieldToAssoc($npcData['extended_data'] ?? []);
    if (is_array($extended)) {
        $extState = stobeNormalizeParticipantAwarenessStateToken(strval($extended['character_state'] ?? ''));
        if ($extState !== '') {
            return $extState;
        }
        $medical = stobeNpcArrayFieldToAssoc($extended['medical'] ?? []);
        if (!empty($medical['is_unconscious']) || !empty($medical['is_knocked_out']) || !empty($medical['is_knockedout'])) {
            return 'unconscious';
        }
        if (!empty($extended['is_sleeping']) || !empty($extended['sleeping'])) {
            return 'sleeping';
        }
    }

    return '';
}

function stobeNpcCannotRespondInDirectChat(array $npcData): bool {
    $state = stobeResolveNpcAwarenessState($npcData);
    return in_array($state, ['dead', 'unconscious', 'knocked_out'], true);
}

function normalizeParticipantNameToken(string $raw): string {
    $value = trim($raw);
    if ($value === '') {
        return '';
    }

    $pipePos = strpos($value, '|');
    if ($pipePos !== false) {
        $left = trim(substr($value, 0, $pipePos));
        if ($left !== '') {
            $value = $left;
        }
    }

    $value = stobeParticipantDisplayNameWithoutAwarenessTag($value);
    return trim($value);
}

function normalizeStorageIdToken(mixed $raw): string {
    if (is_array($raw) || is_object($raw)) {
        return '';
    }

    $value = trim(strval($raw));
    if ($value === '') {
        return '';
    }

    $lower = strtolower($value);
    if (in_array($lower, ['player', 'unknown', 'none', 'null'], true)) {
        return '';
    }

    $extractNumericPart = $value;
    if (preg_match('/^hand_(.+)$/i', $value, $m) === 1) {
        $extractNumericPart = trim(strval($m[1]));
    }

    if (preg_match('/^-?[0-9]+$/', $extractNumericPart) === 1) {
        $numeric = intval($extractNumericPart);
        if ($numeric < 0 && $numeric >= -2147483648) {
            $numeric = $numeric + 4294967296;
        }
        if ($numeric >= 0) {
            return 'hand_' . strval($numeric);
        }
    }

    return $value;
}

function buildStorageIdSearchVariants(string $storageId): array {
    $variants = [];
    $normalized = normalizeStorageIdToken($storageId);
    if ($normalized !== '') {
        $variants[strtolower($normalized)] = $normalized;
    }

    $trimmed = trim($storageId);
    if ($trimmed !== '') {
        $variants[strtolower($trimmed)] = $trimmed;
    }

    if (preg_match('/^hand_(-?[0-9]+)$/i', $trimmed, $m) === 1) {
        $numeric = intval($m[1]);
        $variants[strtolower($m[1])] = $m[1];
        if ($numeric < 0 && $numeric >= -2147483648) {
            $unsigned = $numeric + 4294967296;
            $variants['hand_' . strval($unsigned)] = 'hand_' . strval($unsigned);
            $variants[strval($unsigned)] = strval($unsigned);
        } elseif ($numeric > 2147483647 && $numeric <= 4294967295) {
            $signed = $numeric - 4294967296;
            $variants['hand_' . strval($signed)] = 'hand_' . strval($signed);
            $variants[strval($signed)] = strval($signed);
        }
    } elseif (preg_match('/^-?[0-9]+$/', $trimmed) === 1) {
        $numeric = intval($trimmed);
        $variants['hand_' . strtolower($trimmed)] = 'hand_' . $trimmed;
        if ($numeric < 0 && $numeric >= -2147483648) {
            $unsigned = $numeric + 4294967296;
            $variants['hand_' . strval($unsigned)] = 'hand_' . strval($unsigned);
            $variants[strval($unsigned)] = strval($unsigned);
        } elseif ($numeric > 2147483647 && $numeric <= 4294967295) {
            $signed = $numeric - 4294967296;
            $variants['hand_' . strval($signed)] = 'hand_' . strval($signed);
            $variants[strval($signed)] = strval($signed);
        }
    }

    return array_values($variants);
}

function composeFactionWithId(string $factionName, string $factionId): string {
    $name = trim($factionName);
    $id = trim($factionId);

    if ($id === '') {
        return $name;
    }
    if ($name === '') {
        return $id;
    }
    if (strpos($name, '[' . $id . ']') !== false) {
        return $name;
    }
    return $name . ' [' . $id . ']';
}

function extractParticipantIdentityToken(string $raw): array {
    $value = trim($raw);
    if ($value === '') {
        return ['name' => '', 'storage_id' => ''];
    }

    $name = normalizeParticipantNameToken($value);
    if ($name === '') {
        return ['name' => '', 'storage_id' => ''];
    }

    $storageId = '';
    $pipePos = strpos($value, '|');
    if ($pipePos !== false) {
        $storageId = normalizeStorageIdToken(substr($value, $pipePos + 1));
    }

    return ['name' => $name, 'storage_id' => $storageId];
}

function extractParticipantNames(array $options): array {
    $namesByKey = [];
    $addName = function (string $rawName) use (&$namesByKey): void {
        $normalized = normalizeParticipantNameToken($rawName);
        if ($normalized === '') {
            return;
        }
        $key = strtolower($normalized);
        if (!isset($namesByKey[$key])) {
            $namesByKey[$key] = $normalized;
        }
    };

    $peopleRaw = $options['people'] ?? '';
    if (is_array($peopleRaw)) {
        foreach ($peopleRaw as $entry) {
            if (is_string($entry)) {
                $addName($entry);
            }
        }
    } elseif (is_string($peopleRaw) && trim($peopleRaw) !== '') {
        $decodedPeople = json_decode($peopleRaw, true);
        if (is_array($decodedPeople)) {
            foreach ($decodedPeople as $entry) {
                if (is_string($entry)) {
                    $addName($entry);
                }
            }
        } else {
            $addName($peopleRaw);
        }
    }

    $profileName = $options['profile'] ?? '';
    if (is_string($profileName)) {
        $addName($profileName);
    }

    $speakerName = $options['speaker'] ?? '';
    if (is_string($speakerName)) {
        $addName($speakerName);
    }

    $npcs = $options['npcs'] ?? [];
    if (is_array($npcs)) {
        foreach ($npcs as $entry) {
            if (is_string($entry)) {
                $addName($entry);
                continue;
            }
            if (is_array($entry)) {
                $candidate = strval($entry['name'] ?? ($entry['npc'] ?? ''));
                if ($candidate !== '') {
                    $addName($candidate);
                }
            }
        }
    }

    $nearby = $options['nearby'] ?? [];
    if (is_array($nearby)) {
        foreach ($nearby as $entry) {
            if (is_array($entry)) {
                $candidate = strval($entry['name'] ?? '');
                if ($candidate !== '') {
                    $addName($candidate);
                }
            } elseif (is_string($entry)) {
                $addName($entry);
            }
        }
    }

    return array_values($namesByKey);
}

function extractParticipantIdentities(array $options): array {
    $identitiesByKey = [];
    $addIdentity = function (string $rawName, $rawStorageId = null) use (&$identitiesByKey): void {
        $parsed = extractParticipantIdentityToken($rawName);
        $name = strval($parsed['name'] ?? '');
        if ($name === '') {
            return;
        }

        $storageId = strval($parsed['storage_id'] ?? '');
        if ($rawStorageId !== null && !is_array($rawStorageId) && !is_object($rawStorageId)) {
            $rawStorageIdText = normalizeStorageIdToken($rawStorageId);
            if ($rawStorageIdText !== '') {
                $storageId = $rawStorageIdText;
            }
        }
        $storageId = normalizeStorageIdToken($storageId);

        $nameKey = '__name__:' . strtolower($name);
        if ($storageId !== '') {
            $storageKey = '__sid__:' . strtolower($storageId);
            if (isset($identitiesByKey[$storageKey])) {
                if ($identitiesByKey[$storageKey]['name'] === '') {
                    $identitiesByKey[$storageKey]['name'] = $name;
                }
                return;
            }
            if (isset($identitiesByKey[$nameKey]) && strval($identitiesByKey[$nameKey]['storage_id'] ?? '') === '') {
                $entry = $identitiesByKey[$nameKey];
                unset($identitiesByKey[$nameKey]);
                $entry['storage_id'] = $storageId;
                $identitiesByKey[$storageKey] = $entry;
                return;
            }
            $identitiesByKey[$storageKey] = [
                'name' => $name,
                'storage_id' => $storageId,
            ];
            return;
        }

        if (!isset($identitiesByKey[$nameKey])) {
            $identitiesByKey[$nameKey] = [
                'name' => $name,
                'storage_id' => '',
            ];
        }
    };

    $peopleRaw = $options['people'] ?? '';
    if (is_array($peopleRaw)) {
        foreach ($peopleRaw as $entry) {
            if (is_string($entry)) {
                $addIdentity($entry);
            }
        }
    } elseif (is_string($peopleRaw) && trim($peopleRaw) !== '') {
        $decodedPeople = json_decode($peopleRaw, true);
        if (is_array($decodedPeople)) {
            foreach ($decodedPeople as $entry) {
                if (is_string($entry)) {
                    $addIdentity($entry);
                }
            }
        } else {
            $addIdentity($peopleRaw);
        }
    }

    $profileName = $options['profile'] ?? '';
    if (is_string($profileName)) {
        $addIdentity($profileName);
    }

    $speakerName = $options['speaker'] ?? '';
    if (is_string($speakerName)) {
        $addIdentity($speakerName);
    }

    $npcs = $options['npcs'] ?? [];
    if (is_array($npcs)) {
        foreach ($npcs as $entry) {
            if (is_string($entry)) {
                $addIdentity($entry);
                continue;
            }
            if (is_array($entry)) {
                $candidate = strval($entry['name'] ?? ($entry['npc'] ?? ''));
                $candidateId = $entry['storage_id'] ?? ($entry['id'] ?? ($entry['refid'] ?? ($entry['handle'] ?? null)));
                if ($candidate !== '') {
                    $addIdentity($candidate, $candidateId);
                }
            }
        }
    }

    $nearby = $options['nearby'] ?? [];
    if (is_array($nearby)) {
        foreach ($nearby as $entry) {
            if (is_array($entry)) {
                $candidate = strval($entry['name'] ?? '');
                $candidateId = $entry['storage_id'] ?? ($entry['id'] ?? ($entry['refid'] ?? ($entry['handle'] ?? null)));
                if ($candidate !== '') {
                    $addIdentity($candidate, $candidateId);
                }
            } elseif (is_string($entry)) {
                $addIdentity($entry);
            }
        }
    }

    return array_values($identitiesByKey);
}

function stobeExtractParticipantIdentityWithAwarenessState(string $raw): array {
    $value = trim($raw);
    if ($value === '') {
        return ['name' => '', 'storage_id' => '', 'state' => ''];
    }

    $namePart = $value;
    $storageId = '';
    $pipePos = strpos($value, '|');
    if ($pipePos !== false) {
        $namePart = trim(substr($value, 0, $pipePos));
        $storageId = normalizeStorageIdToken(substr($value, $pipePos + 1));
    }

    $state = stobeExtractParticipantAwarenessStateFromDisplayName($namePart);
    $name = normalizeParticipantNameToken($namePart);
    return [
        'name' => $name,
        'storage_id' => $storageId,
        'state' => $state,
    ];
}

function stobeFetchParticipantAwarenessStateMap(array $participantIdentities): array {
    $db = $GLOBALS['db'] ?? null;
    if (!$db || count($participantIdentities) === 0) {
        return [];
    }

    $nameParams = [];
    $storageParams = [];
    foreach ($participantIdentities as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $nameValue = normalizeParticipantNameToken(strval($entry['name'] ?? ''));
        if ($nameValue !== '') {
            $nameParams[strtolower($nameValue)] = $nameValue;
        }
        $storageValue = normalizeStorageIdToken(strval($entry['storage_id'] ?? ''));
        if ($storageValue !== '') {
            $storageParams[strtolower($storageValue)] = $storageValue;
        }
    }
    if (count($nameParams) === 0 && count($storageParams) === 0) {
        return [];
    }

    $whereParts = [];
    $params = [];
    $index = 1;
    if (count($nameParams) > 0) {
        $namePlaceholders = [];
        foreach (array_values($nameParams) as $nameValue) {
            $params[] = $nameValue;
            $namePlaceholders[] = 'LOWER($' . $index . ')';
            $index++;
        }
        $whereParts[] = 'LOWER(name) IN (' . implode(',', $namePlaceholders) . ')';
        $whereParts[] = 'LOWER(COALESCE(original_name, \'\')) IN (' . implode(',', $namePlaceholders) . ')';
    }
    if (count($storageParams) > 0) {
        $storagePlaceholders = [];
        foreach (array_values($storageParams) as $storageValue) {
            $params[] = $storageValue;
            $storagePlaceholders[] = 'LOWER($' . $index . ')';
            $index++;
        }
        $whereParts[] = 'LOWER(COALESCE(metadata->>\'storage_id\', \'\')) IN (' . implode(',', $storagePlaceholders) . ')';
    }
    if (count($whereParts) === 0) {
        return [];
    }

    $rows = $db->fetchAll(
        'SELECT name,
                original_name,
                metadata,
                extended_data,
                COALESCE(metadata->>\'storage_id\', \'\') AS storage_id
         FROM core_npc
         WHERE ' . implode(' OR ', $whereParts) . '
         ORDER BY gamets_last_updated DESC, updated_at DESC',
        $params
    );

    $stateByLookup = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $state = stobeResolveNpcAwarenessState($row);
        if (!in_array($state, ['sleeping', 'unconscious', 'knocked_out', 'dead'], true)) {
            continue;
        }

        $rowName = normalizeParticipantNameToken(strval($row['name'] ?? ''));
        if ($rowName !== '') {
            $nameKey = 'name:' . strtolower($rowName);
            if (!isset($stateByLookup[$nameKey])) {
                $stateByLookup[$nameKey] = $state;
            }
        }

        $rowOriginal = normalizeParticipantNameToken(strval($row['original_name'] ?? ''));
        if ($rowOriginal !== '') {
            $originalKey = 'original:' . strtolower($rowOriginal);
            if (!isset($stateByLookup[$originalKey])) {
                $stateByLookup[$originalKey] = $state;
            }
        }

        $rowStorageId = normalizeStorageIdToken(strval($row['storage_id'] ?? ''));
        if ($rowStorageId !== '') {
            $storageKey = 'storage:' . strtolower($rowStorageId);
            if (!isset($stateByLookup[$storageKey])) {
                $stateByLookup[$storageKey] = $state;
            }
        }
    }

    $result = [];
    foreach ($participantIdentities as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $name = normalizeParticipantNameToken(strval($entry['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $storageId = normalizeStorageIdToken(strval($entry['storage_id'] ?? ''));
        $lookupOrder = [];
        if ($storageId !== '') {
            $lookupOrder[] = 'storage:' . strtolower($storageId);
        }
        $lookupOrder[] = 'name:' . strtolower($name);
        $lookupOrder[] = 'original:' . strtolower($name);

        $resolvedState = '';
        foreach ($lookupOrder as $lookupKey) {
            if (isset($stateByLookup[$lookupKey])) {
                $resolvedState = strval($stateByLookup[$lookupKey]);
                break;
            }
        }

        $entryKey = $storageId !== ''
            ? ('__sid__:' . strtolower($storageId))
            : ('__name__:' . strtolower($name));
        $result[$entryKey] = $resolvedState;
    }

    return $result;
}

function stobeAnnotatePeopleTokensWithNpcStates(mixed $rawPeople): string {
    if (is_string($rawPeople)) {
        $trimmed = trim($rawPeople);
        if ($trimmed === '') {
            return '[]';
        }
        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            return $trimmed;
        }
    } elseif (!is_array($rawPeople)) {
        return '[]';
    }

    $identities = extractParticipantIdentities([
        'people' => $rawPeople,
    ]);
    if (count($identities) === 0) {
        return '[]';
    }

    $stateMap = stobeFetchParticipantAwarenessStateMap($identities);
    $tokens = [];
    foreach ($identities as $identity) {
        if (!is_array($identity)) {
            continue;
        }
        $name = normalizeParticipantNameToken(strval($identity['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $storageId = normalizeStorageIdToken(strval($identity['storage_id'] ?? ''));
        $key = $storageId !== ''
            ? ('__sid__:' . strtolower($storageId))
            : ('__name__:' . strtolower($name));
        $state = strval($stateMap[$key] ?? '');
        $displayName = stobeApplyParticipantAwarenessTag($name, $state);
        if ($displayName === '') {
            continue;
        }
        $tokens[] = $storageId !== '' ? ($displayName . '|' . $storageId) : $displayName;
    }

    return stobeEncodePeopleTokenList($tokens);
}

function stobeEventRowActorHasAwarenessSuppressedTag(array $row, string $actorName): bool {
    $safeActor = normalizeParticipantNameToken($actorName);
    if ($safeActor === '') {
        return false;
    }
    $actorKey = strtolower($safeActor);

    $rawPeople = $row['people'] ?? '';
    $tokens = [];
    if (is_array($rawPeople)) {
        $tokens = $rawPeople;
    } else {
        $peopleText = trim(strval($rawPeople));
        if ($peopleText === '') {
            return false;
        }
        $decoded = json_decode($peopleText, true);
        if (is_array($decoded)) {
            $tokens = $decoded;
        } else {
            $tokens = preg_split('/[,;]+/u', $peopleText) ?: [];
        }
    }

    foreach ($tokens as $token) {
        if (!is_string($token)) {
            continue;
        }
        $parsed = stobeExtractParticipantIdentityWithAwarenessState($token);
        $name = strtolower(strval($parsed['name'] ?? ''));
        if ($name === '' || $name !== $actorKey) {
            continue;
        }
        $state = strval($parsed['state'] ?? '');
        if (in_array($state, ['sleeping', 'unconscious', 'knocked_out'], true)) {
            return true;
        }
    }

    return false;
}

function stobeEncodePeopleTokenList(array $tokens): string {
    $normalized = [];
    foreach ($tokens as $token) {
        if (!is_string($token)) {
            continue;
        }
        $trimmed = trim($token);
        if ($trimmed === '') {
            continue;
        }
        $normalized[] = $trimmed;
    }
    $encoded = json_encode(array_values($normalized), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || $encoded === '') {
        return '[]';
    }
    return $encoded;
}

function stobePeopleTokenListFromRaw(mixed $rawPeople): array {
    $peopleSource = '';
    if (is_string($rawPeople)) {
        $peopleSource = $rawPeople;
    } elseif (is_array($rawPeople)) {
        $peopleSource = $rawPeople;
    }

    $identities = extractParticipantIdentities([
        'people' => $peopleSource,
    ]);

    $tokens = [];
    foreach ($identities as $identity) {
        if (!is_array($identity)) {
            continue;
        }
        $name = normalizeParticipantNameToken(strval($identity['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $storageId = normalizeStorageIdToken($identity['storage_id'] ?? '');
        $token = $storageId !== '' ? ($name . '|' . $storageId) : $name;
        $tokens[] = $token;
    }
    return $tokens;
}

// Read only the audience captured for this event, including legacy pipe rosters.
function stobeEventAudienceTokens(mixed $people): array {
    if (is_array($people)) {
        return array_values(array_filter($people, 'is_string'));
    }
    $raw = trim(strval($people ?? ''));
    if ($raw === '') {
        return [];
    }
    if ($raw[0] === '[') {
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
    return preg_split('/\|(?!(?:hand_)?-?[0-9]+(?:\||$))/i', trim($raw, '|')) ?: [];
}

// Apply identity and event-time awareness before LIMIT, never searching event prose.
function stobeEventAudienceSql(string $npcName, array &$params, array $aliases = [], array|false|null $npcData = null): string {
    $name = normalizeParticipantNameToken($npcName);
    if ($name === '') {
        return 'FALSE';
    }
    $npcData = $npcData ?? getNpcData($name);
    $metadata = normalizeCoreNpcMetadata($npcData['metadata'] ?? []);
    $storageId = normalizeStorageIdToken($metadata['storage_id'] ?? '');
    $names = array_values(array_unique(array_filter(array_map('normalizeParticipantNameToken', array_merge(
        [$name], $aliases, stobeResolveNpcEventHistoryAliases($npcData, $name)
    )))));
    $nameParam = '$' . (count($params) + 1);
    $params[] = json_encode($names, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $idParam = '$' . (count($params) + 1);
    $params[] = json_encode($storageId === '' ? [] : buildStorageIdSearchVariants($storageId));

    // Validate string-array syntax before casting old/untrusted text to JSONB.
    $stringPattern = '"([^"\\\\[:cntrl:]]|\\\\(["\\\\/bfnrt]|u(?!0000|[dD][89a-fA-F])[0-9a-fA-F]{4}|u[dD][89abAB][0-9a-fA-F]{2}\\\\u[dD][c-fC-F][0-9a-fA-F]{2}))*"';
    $arrayPattern = '^\s*\[\s*(' . $stringPattern . '\s*(,\s*' . $stringPattern . '\s*)*)?\]\s*$';
    $validParam = '$' . (count($params) + 1);
    $params[] = $arrayPattern;
    // Cheap candidate filtering avoids decoding every remote event in large histories.
    // Include escaped legacy JSON names and every numeric/signed handle spelling.
    $searchTokens = array_merge($names, $storageId === '' ? [] : buildStorageIdSearchVariants($storageId));
    $patterns = [];
    foreach ($searchTokens as $token) {
        foreach ([$token, substr(json_encode($token), 1, -1)] as $spelling) {
            $patterns[] = '%' . strtr($spelling, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        }
    }
    $patternParam = '$' . (count($params) + 1);
    $params[] = json_encode(array_values(array_unique($patterns)));
    return "(people ILIKE ANY(ARRAY(SELECT value FROM jsonb_array_elements_text({$patternParam}::jsonb))) AND EXISTS (
        SELECT 1 FROM jsonb_array_elements_text(
            CASE WHEN btrim(COALESCE(people, '')) ~ {$validParam}
                THEN people::jsonb
                WHEN left(btrim(COALESCE(people, '')), 1) = '[' THEN '[]'::jsonb
                ELSE to_jsonb(regexp_split_to_array(trim(BOTH '|' FROM COALESCE(people, '')),
                    '\\|(?!(?:hand_)?-?[0-9]+(?:\\||$))', 'i')) END
        ) AS audience(token)
        WHERE split_part(token, '|', 1) !~* ' \\((sleeping|unconscious|knocked[ _]out)\\)$'
          AND CASE WHEN strpos(token, '|') > 0 AND jsonb_array_length({$idParam}::jsonb) > 0
              THEN lower(btrim(split_part(token, '|', 2))) IN
                  (SELECT lower(value) FROM jsonb_array_elements_text({$idParam}::jsonb))
              ELSE lower(regexp_replace(btrim(split_part(token, '|', 1)),
                  '( \\((busy|hostile|in combat|restrained|dead)\\))+$', '', 'i')) IN
                  (SELECT lower(value) FROM jsonb_array_elements_text({$nameParam}::jsonb)) END
    ))";
}

function stobeRecoverSparsePeopleForCriticalEvent(string $eventType, string $eventData, string $incomingPeople): string {
    // A previous event cannot prove who witnessed a death at another location.
    return stobeEncodePeopleTokenList(stobeEventAudienceTokens($incomingPeople));
}

function ensureOriginalName(string $name, string $fallbackOriginal = ''): string {
    $db = $GLOBALS["db"];
    $normalizedName = normalizeParticipantNameToken($name);
    if ($normalizedName === '') {
        return '';
    }

    $fallback = baseNameWithoutBracketSuffix($fallbackOriginal);
    if ($fallback === '') {
        $fallback = baseNameWithoutBracketSuffix($normalizedName);
    }
    if ($fallback === '') {
        return '';
    }

    $existing = $db->fetchOne(
        "SELECT original_name FROM core_npc WHERE name = $1",
        [$normalizedName]
    );
    if (!$existing) {
        return $fallback;
    }

    $currentOriginal = trim(strval($existing['original_name'] ?? ''));
    if ($currentOriginal !== '') {
        return $currentOriginal;
    }

    $db->exec(
        "UPDATE core_npc
         SET original_name = $2
         WHERE name = $1
           AND (original_name IS NULL OR BTRIM(original_name) = '')",
        [$normalizedName, $fallback]
    );

    return $fallback;
}

function stobeBuildNpcIdentityNameVariants(string $rawName): array {
    $variants = [];
    $append = static function (array &$target, string $candidate): void {
        $normalized = normalizeParticipantNameToken($candidate);
        if ($normalized === '') {
            return;
        }
        $target[strtolower($normalized)] = true;
        $base = normalizeParticipantNameToken(baseNameWithoutBracketSuffix($normalized));
        if ($base !== '') {
            $target[strtolower($base)] = true;
        }
    };

    $append($variants, $rawName);
    return $variants;
}

function stobeSnapshotIdentityNamesCompatible(
    string $incomingName,
    string $candidateName,
    string $candidateOriginalName = ''
): bool {
    $incomingVariants = stobeBuildNpcIdentityNameVariants($incomingName);
    if (count($incomingVariants) === 0) {
        return false;
    }

    $candidateVariants = stobeBuildNpcIdentityNameVariants($candidateName);
    foreach (array_keys(stobeBuildNpcIdentityNameVariants($candidateOriginalName)) as $variantKey) {
        $candidateVariants[$variantKey] = true;
    }

    foreach (array_keys($incomingVariants) as $incomingVariantKey) {
        if (isset($candidateVariants[$incomingVariantKey])) {
            return true;
        }
    }

    return false;
}

function resolveSnapshotTargetNpcName(string $incomingName, string $incomingStorageId = ''): array {
    $db = $GLOBALS["db"];
    $normalizedIncomingName = normalizeParticipantNameToken($incomingName);
    if ($normalizedIncomingName === '') {
        return [
            'name' => '',
            'matched_by' => 'none',
        ];
    }

    $storageIdVariants = buildStorageIdSearchVariants($incomingStorageId);
    if (count($storageIdVariants) > 0) {
        $params = [];
        $variantPlaceholders = [];
        $index = 1;
        foreach ($storageIdVariants as $variant) {
            $params[] = $variant;
            $variantPlaceholders[] = "LOWER($" . $index . ")";
            $index++;
        }

        $byStorageId = $db->fetchOne(
            "SELECT name,
                    original_name
             FROM core_npc
             WHERE LOWER(COALESCE(metadata->>'storage_id', '')) IN (" . implode(',', $variantPlaceholders) . ")
             ORDER BY updated_at DESC, gamets_last_updated DESC
             LIMIT 1",
            $params
        );
        if ($byStorageId) {
            $resolvedName = normalizeParticipantNameToken(strval($byStorageId['name'] ?? ''));
            $resolvedOriginalName = normalizeParticipantNameToken(strval($byStorageId['original_name'] ?? ''));
            if ($resolvedName !== '') {
                if (stobeSnapshotIdentityNamesCompatible($normalizedIncomingName, $resolvedName, $resolvedOriginalName)) {
                    return [
                        'name' => $resolvedName,
                        'matched_by' => 'storage_id',
                    ];
                }

                stobeLogImport('Snapshot storage_id match rejected as incompatible', [
                    'incoming_name' => $normalizedIncomingName,
                    'incoming_storage_id' => $incomingStorageId,
                    'matched_name' => $resolvedName,
                    'matched_original_name' => $resolvedOriginalName,
                    'storage_id_variants' => $storageIdVariants,
                ], 'WARN');
            }
        }

        stobeLogImport('Snapshot storage_id did not match existing profile', [
            'incoming_name' => $normalizedIncomingName,
            'incoming_storage_id' => $incomingStorageId,
            'storage_id_variants' => $storageIdVariants,
        ], 'DEBUG');
    }

    $byExactName = $db->fetchOne(
        "SELECT name,
                race,
                faction,
                gender,
                equipment,
                skills,
                COALESCE(metadata->>'storage_id', '') AS storage_id
         FROM core_npc
         WHERE LOWER(name) = LOWER($1)
         ORDER BY gamets_last_updated DESC, updated_at DESC
         LIMIT 1",
        [$normalizedIncomingName]
    );
    if ($byExactName) {
        $resolvedName = normalizeParticipantNameToken(strval($byExactName['name'] ?? ''));
        if ($resolvedName !== '') {
            $exactStorageId = normalizeStorageIdToken(strval($byExactName['storage_id'] ?? ''));
            $exactRace = strtolower(trim(strval($byExactName['race'] ?? '')));
            $exactFaction = trim(strval($byExactName['faction'] ?? ''));
            $exactGender = trim(strval($byExactName['gender'] ?? ''));
            $exactEquipment = trim(strval($byExactName['equipment'] ?? ''));
            $exactSkills = trim(strval($byExactName['skills'] ?? ''));
            $exactLooksPlaceholder =
                $exactStorageId === '' &&
                ($exactRace === '' || $exactRace === 'unknown') &&
                $exactFaction === '' &&
                $exactGender === '' &&
                $exactEquipment === '' &&
                $exactSkills === '';

            if ($exactLooksPlaceholder) {
                $preferredByOriginal = $db->fetchOne(
                    "SELECT name
                     FROM core_npc
                     WHERE LOWER(COALESCE(original_name, '')) = LOWER($1)
                       AND COALESCE(metadata->>'storage_id', '') <> ''
                     ORDER BY updated_at DESC, gamets_last_updated DESC
                     LIMIT 1",
                    [$normalizedIncomingName]
                );
                if ($preferredByOriginal) {
                    $preferredName = normalizeParticipantNameToken(strval($preferredByOriginal['name'] ?? ''));
                    if ($preferredName !== '' && strcasecmp($preferredName, $resolvedName) !== 0) {
                        stobeLogImport('Snapshot target remapped away from placeholder exact match', [
                            'incoming_name' => $normalizedIncomingName,
                            'placeholder_name' => $resolvedName,
                            'preferred_name' => $preferredName,
                        ], 'DEBUG');
                        return [
                            'name' => $preferredName,
                            'matched_by' => 'original_name_preferred_over_placeholder',
                        ];
                    }
                }
            }

            return [
                'name' => $resolvedName,
                'matched_by' => 'name',
            ];
        }
    }

    $baseIncomingName = baseNameWithoutBracketSuffix($normalizedIncomingName);
    $originalNameCandidates = [];
    $originalNameCandidates[] = $normalizedIncomingName;
    if (
        $baseIncomingName !== '' &&
        strtolower($baseIncomingName) !== strtolower($normalizedIncomingName)
    ) {
        $originalNameCandidates[] = $baseIncomingName;
    }

    foreach ($originalNameCandidates as $candidateOriginalName) {
        $byOriginalName = $db->fetchOne(
            "SELECT name
             FROM core_npc
             WHERE LOWER(original_name) = LOWER($1)
             ORDER BY updated_at DESC, gamets_last_updated DESC
             LIMIT 1",
            [$candidateOriginalName]
        );
        if ($byOriginalName) {
            $resolvedName = normalizeParticipantNameToken(strval($byOriginalName['name'] ?? ''));
            if ($resolvedName !== '') {
                $ambiguousCount = intval(($db->fetchOne(
                    "SELECT COUNT(*) AS cnt
                     FROM core_npc
                     WHERE LOWER(original_name) = LOWER($1)",
                    [$candidateOriginalName]
                )['cnt'] ?? 0));
                if ($ambiguousCount > 1) {
                    stobeLogImport('Snapshot original_name match is ambiguous', [
                        'incoming_name' => $normalizedIncomingName,
                        'incoming_storage_id' => $incomingStorageId,
                        'candidate_original_name' => $candidateOriginalName,
                        'match_count' => $ambiguousCount,
                        'resolved_name' => $resolvedName,
                    ], 'DEBUG');
                }
                return [
                    'name' => $resolvedName,
                    'matched_by' => 'original_name',
                ];
            }
        }
    }

    return [
        'name' => $normalizedIncomingName,
        'matched_by' => 'incoming',
    ];
}

function ensureNpcProfilesFromParticipants(array $participantNames): array {
    $identities = [];
    foreach ($participantNames as $name) {
        $candidate = normalizeParticipantNameToken(strval($name));
        if ($candidate === '') {
            continue;
        }
        $identities[] = [
            'name' => $candidate,
            'storage_id' => '',
        ];
    }
    return ensureNpcProfilesFromParticipantIdentities($identities);
}

function ensureNpcProfilesFromParticipantIdentities(array $participantIdentities): array {
    $db = $GLOBALS["db"];
    $normalized = [];
    foreach ($participantIdentities as $entry) {
        if (is_string($entry)) {
            $parsed = extractParticipantIdentityToken($entry);
            $name = strval($parsed['name'] ?? '');
            $storageId = strval($parsed['storage_id'] ?? '');
        } elseif (is_array($entry)) {
            $name = normalizeParticipantNameToken(strval($entry['name'] ?? ''));
            $storageId = normalizeStorageIdToken($entry['storage_id'] ?? ($entry['id'] ?? ($entry['refid'] ?? ($entry['handle'] ?? ''))));
        } else {
            continue;
        }

        if ($name === '') {
            continue;
        }
        $storageId = normalizeStorageIdToken($storageId);
        $key = $storageId !== ''
            ? ('__sid__:' . strtolower($storageId))
            : ('__name__:' . strtolower($name));
        if (!isset($normalized[$key])) {
            $normalized[$key] = [
                'name' => $name,
                'storage_id' => $storageId,
            ];
            continue;
        }
        if ($normalized[$key]['storage_id'] === '' && $storageId !== '') {
            $normalized[$key]['storage_id'] = $storageId;
        }
    }

    $entries = array_values($normalized);
    if (count($entries) === 0) {
        return ['parsed' => 0, 'created' => 0, 'updated' => 0, 'participants' => []];
    }

    $nameParams = [];
    $storageParams = [];
    foreach ($entries as $entry) {
        $nameValue = normalizeParticipantNameToken(strval($entry['name'] ?? ''));
        if ($nameValue !== '') {
            $nameParams[strtolower($nameValue)] = $nameValue;
        }
        $storageValue = normalizeStorageIdToken(strval($entry['storage_id'] ?? ''));
        if ($storageValue !== '') {
            $storageParams[strtolower($storageValue)] = $storageValue;
        }
    }

    $whereParts = [];
    $params = [];
    $index = 1;
    if (count($nameParams) > 0) {
        $namePlaceholders = [];
        foreach (array_values($nameParams) as $nameValue) {
            $params[] = $nameValue;
            $namePlaceholders[] = "LOWER($" . $index . ")";
            $index++;
        }
        $whereParts[] = "LOWER(name) IN (" . implode(',', $namePlaceholders) . ")";
        $whereParts[] = "LOWER(COALESCE(original_name, '')) IN (" . implode(',', $namePlaceholders) . ")";
    }
    if (count($storageParams) > 0) {
        $storagePlaceholders = [];
        foreach (array_values($storageParams) as $storageValue) {
            $params[] = $storageValue;
            $storagePlaceholders[] = "LOWER($" . $index . ")";
            $index++;
        }
        $whereParts[] = "LOWER(COALESCE(metadata->>'storage_id', '')) IN (" . implode(',', $storagePlaceholders) . ")";
    }
    if (count($whereParts) === 0) {
        return ['parsed' => count($entries), 'created' => 0, 'updated' => 0, 'participants' => array_map(static fn(array $entry): string => strval($entry['name']), $entries)];
    }

    $rows = $db->fetchAll(
        "SELECT id, name, original_name, COALESCE(metadata->>'storage_id', '') AS storage_id
         FROM core_npc
         WHERE " . implode(' OR ', $whereParts),
        $params
    );
    $existingByLookup = [];
    foreach ($rows as $row) {
        $existingName = normalizeParticipantNameToken(strval($row['name'] ?? ''));
        if ($existingName !== '') {
            $existingByLookup['name:' . strtolower($existingName)] = $row;
        }
        $existingOriginal = normalizeParticipantNameToken(strval($row['original_name'] ?? ''));
        if ($existingOriginal !== '') {
            $existingByLookup['original:' . strtolower($existingOriginal)] = $row;
        }
        $existingStorageId = normalizeStorageIdToken(strval($row['storage_id'] ?? ''));
        if ($existingStorageId !== '') {
            $existingByLookup['storage:' . strtolower($existingStorageId)] = $row;
        }
    }

    $created = 0;
    $updated = 0;
    foreach ($entries as $entry) {
        $name = strval($entry['name']);
        $storageId = normalizeStorageIdToken(strval($entry['storage_id'] ?? ''));
        $isBracketName = (strpos($name, '[') !== false || strpos($name, ']') !== false);
        $lookupOrder = [];
        if ($isBracketName) {
            $lookupOrder[] = 'name:' . strtolower($name);
            $lookupOrder[] = 'original:' . strtolower($name);
            if ($storageId !== '') {
                $lookupOrder[] = 'storage:' . strtolower($storageId);
            }
        } else {
            if ($storageId !== '') {
                $lookupOrder[] = 'storage:' . strtolower($storageId);
            }
            $lookupOrder[] = 'name:' . strtolower($name);
            $lookupOrder[] = 'original:' . strtolower($name);
        }

        $matchedRow = null;
        foreach ($lookupOrder as $lookupKey) {
            if (isset($existingByLookup[$lookupKey])) {
                $candidateRow = $existingByLookup[$lookupKey];
                if (
                    strpos($lookupKey, 'storage:') === 0 &&
                    !stobeSnapshotIdentityNamesCompatible(
                        $name,
                        strval($candidateRow['name'] ?? ''),
                        strval($candidateRow['original_name'] ?? '')
                    )
                ) {
                    if ($isBracketName) {
                        stobeLogImport('Bracket participant JIT rejected incompatible storage_id match', [
                            'name' => $name,
                            'storage_id' => $storageId,
                            'matched_name' => strval($candidateRow['name'] ?? ''),
                            'matched_original_name' => strval($candidateRow['original_name'] ?? ''),
                            'matched_storage_id' => normalizeStorageIdToken(strval($candidateRow['storage_id'] ?? '')),
                        ], 'WARN');
                    }
                    continue;
                }
                $matchedRow = $candidateRow;
                break;
            }
        }

        if (!$matchedRow) {
            $bootstrapProfile = [];
            if ($storageId !== '') {
                $bootstrapProfile['metadata'] = ['storage_id' => $storageId];
            }
            storeNpcProfile($name, $bootstrapProfile);
            ensureOriginalName($name, $name);
            $created++;
            if (strpos($name, '[') !== false) {
                stobeLogImport('Bracket participant JIT created placeholder', [
                    'name' => $name,
                    'storage_id' => $storageId,
                    'lookup_order' => $lookupOrder,
                ], 'WARN');
            }
            continue;
        }

        $existingStorageId = normalizeStorageIdToken(strval($matchedRow['storage_id'] ?? ''));
        if ($storageId !== '' && $existingStorageId === '') {
            $db->exec(
                "UPDATE core_npc
                 SET metadata = CASE
                     WHEN metadata IS NULL
                       OR metadata = '[]'::jsonb
                       OR jsonb_typeof(metadata) <> 'object'
                     THEN jsonb_build_object('storage_id', $2::text)
                     ELSE metadata || jsonb_build_object('storage_id', $2::text)
                 END,
                 updated_at = NOW()
                 WHERE id = $1",
                [intval($matchedRow['id'] ?? 0), $storageId]
            );
            $updated++;
            if (strpos($name, '[') !== false) {
                stobeLogImport('Bracket participant JIT attached storage_id', [
                    'name' => $name,
                    'storage_id' => $storageId,
                    'matched_name' => strval($matchedRow['name'] ?? ''),
                    'matched_original_name' => strval($matchedRow['original_name'] ?? ''),
                ], 'DEBUG');
            }
        } elseif (strpos($name, '[') !== false) {
            stobeLogImport('Bracket participant JIT matched existing', [
                'name' => $name,
                'storage_id' => $storageId,
                'matched_name' => strval($matchedRow['name'] ?? ''),
                'matched_original_name' => strval($matchedRow['original_name'] ?? ''),
                'matched_storage_id' => $existingStorageId,
            ], 'DEBUG');
        }
    }

    return [
        'parsed' => count($entries),
        'created' => $created,
        'updated' => $updated,
        'participants' => array_map(static fn(array $entry): string => strval($entry['name']), $entries),
    ];
}

function normalizeGenderHint(string $gender): string {
    $genderKey = strtolower(trim($gender));
    if (strpos($genderKey, 'f') === 0) {
        return 'female';
    }
    if (strpos($genderKey, 'm') === 0) {
        return 'male';
    }
    if (strpos($genderKey, 'n') === 0) {
        return 'neutral';
    }
    return '';
}

function normalizeNamePoolTrait(string $value): string {
    $normalized = strtolower(trim((string)preg_replace('/\s*\[[^\]]+\]\s*$/u', '', $value)));
    if ($normalized === '' || $normalized === 'unknown' || $normalized === 'null') {
        return '';
    }
    return $normalized;
}

function extractBracketSuffixName(string $name): string {
    $trimmed = trim($name);
    if ($trimmed === '') {
        return '';
    }
    if (preg_match('/\[\s*([^\]]+?)\s*\]\s*$/u', $trimmed, $matches) !== 1) {
        return '';
    }
    return trim(strval($matches[1] ?? ''));
}

function getBioRandomLookupNames(string $name = '', string $originalName = ''): array {
    $names = [];
    $append = static function (string $candidate) use (&$names): void {
        $normalized = normalizeNamePoolTrait($candidate);
        if ($normalized === '') {
            return;
        }
        if (!in_array($normalized, $names, true)) {
            $names[] = $normalized;
        }
    };

    $append($originalName);
    $append(extractBracketSuffixName($name));
    $append(baseNameWithoutBracketSuffix($name));
    $append($name);

    return $names;
}

function normalizeBioUniqueName(string $name): string {
    $normalized = strtolower(trim((string)preg_replace('/\s+/u', ' ', $name)));
    if ($normalized === '' || $normalized === 'unknown' || $normalized === 'null') {
        return '';
    }
    return $normalized;
}

function normalizeBioTraitType(string $value): string {
    $raw = strtolower(trim($value));
    if ($raw === '') {
        return '';
    }

    $compact = preg_replace('/[^a-z]/', '', $raw);
    if (!is_string($compact) || $compact === '') {
        return '';
    }

    if ($compact === 'personality') {
        return 'personality';
    }
    if ($compact === 'backstory') {
        return 'backstory';
    }
    if ($compact === 'speechstyle' || $compact === 'speechtyle') {
        return 'speechstyle';
    }
    if ($compact === 'occupation') {
        return 'occupation';
    }
    if ($compact === 'appearance' || $compact === 'appearence') {
        return 'appearance';
    }
    if ($compact === 'goal' || $compact === 'goals') {
        return 'goals';
    }

    return '';
}

function normalizeBioAppearanceExtra(string $value): string {
    $normalized = trim((string)(preg_replace('/\s+/u', ' ', $value) ?? $value));
    if ($normalized === '') {
        return '';
    }
    $lowered = strtolower($normalized);
    if ($lowered === 'unknown' || $lowered === 'null' || $lowered === 'n/a') {
        return '';
    }
    return $normalized;
}

function buildBioAppearanceExtraKey(string $value): string {
    $normalized = normalizeBioAppearanceExtra($value);
    if ($normalized === '') {
        return '';
    }
    return strtolower((string)(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized));
}

function createEmptyBioTraitSelections(): array {
    $selections = [];
    foreach (getRequiredBioTraitTypes() as $type) {
        $selections[$type] = [
            'description' => '',
        ];
    }
    return $selections;
}

function appendBioAppearanceExtrasToText(string $baseAppearance, array $extras): string {
    $result = trim((string)(preg_replace('/\s+/u', ' ', $baseAppearance) ?? $baseAppearance));
    $seenKeys = [];
    if ($result !== '') {
        $resultKey = buildBioAppearanceExtraKey($result);
        if ($resultKey !== '') {
            $seenKeys[$resultKey] = true;
        }
    }

    foreach ($extras as $extra) {
        $appearance = normalizeBioAppearanceExtra(strval($extra));
        if ($appearance === '') {
            continue;
        }
        $key = buildBioAppearanceExtraKey($appearance);
        if ($key === '' || isset($seenKeys[$key])) {
            continue;
        }
        if ($result !== '' && stripos($result, $appearance) !== false) {
            $seenKeys[$key] = true;
            continue;
        }
        if ($result === '') {
            $result = $appearance;
        } else {
            if (preg_match('/[.!?]$/u', $result) !== 1) {
                $result .= '.';
            }
            $result .= ' ' . $appearance;
        }
        $seenKeys[$key] = true;
    }

    return $result;
}

function getRequiredBioTraitTypes(): array {
    return ['personality', 'backstory', 'speechstyle', 'occupation', 'appearance', 'goals'];
}

function getBioUniqueLookupKeys(string $name): array {
    $keys = [];
    $appendKey = static function (string $candidate) use (&$keys): void {
        if ($candidate === '') {
            return;
        }
        if (!in_array($candidate, $keys, true)) {
            $keys[] = $candidate;
        }
    };

    $appendKey(normalizeBioUniqueName($name));
    $appendKey(normalizeBioUniqueName(baseNameWithoutBracketSuffix($name)));
    return $keys;
}

function loadBioUniqueTraitSelections(string $name): array {
    static $cache = [];
    $result = createEmptyBioTraitSelections();

    $lookupKeys = getBioUniqueLookupKeys($name);
    if (count($lookupKeys) === 0) {
        return $result;
    }

    $cacheKey = implode('|', $lookupKeys);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $db = $GLOBALS["db"];
    $rows = [];
    try {
        if (count($lookupKeys) === 1) {
            $rows = $db->fetchAll(
                "SELECT name, type, description, tts_filter_preset
                 FROM combined_bio_unique
                 WHERE LOWER(name) = $1
                   AND COALESCE(is_enabled, TRUE) = TRUE
                 ORDER BY id ASC",
                [$lookupKeys[0]]
            );
        } else {
            $rows = $db->fetchAll(
                "SELECT name, type, description, tts_filter_preset
                 FROM combined_bio_unique
                 WHERE LOWER(name) IN ($1, $2)
                   AND COALESCE(is_enabled, TRUE) = TRUE
                 ORDER BY
                    CASE
                        WHEN LOWER(name) = $1 THEN 0
                        WHEN LOWER(name) = $2 THEN 1
                        ELSE 2
                    END,
                    id ASC",
                [$lookupKeys[0], $lookupKeys[1]]
            );
        }
    } catch (Throwable $exception) {
        $cache[$cacheKey] = $result;
        return $cache[$cacheKey];
    }

    foreach ($rows as $row) {
        $type = normalizeBioTraitType(strval($row['type'] ?? ''));
        if ($type === '' || !array_key_exists($type, $result)) {
            continue;
        }
        if (trim(strval($result[$type]['description'] ?? '')) !== '') {
            continue;
        }
        $description = trim(strval($row['description'] ?? ''));
        if ($description === '') {
            continue;
        }
        $result[$type] = [
            'description' => $description,
            'tts_filter_preset' => $row['tts_filter_preset'] ?? null,
        ];
    }

    $cache[$cacheKey] = $result;
    return $result;
}

function loadBioUniqueTraits(string $name): array {
    $result = [];
    foreach (loadBioUniqueTraitSelections($name) as $type => $selection) {
        $result[$type] = trim(strval($selection['description'] ?? ''));
    }
    return $result;
}

function loadBioRandomCandidates(
    string $race = '',
    string $gender = '',
    string $faction = '',
    string $name = '',
    string $originalName = ''
): array {
    static $cache = [];
    $genderHint = normalizeGenderHint($gender);
    $raceKey = normalizeNamePoolTrait($race);
    $factionKey = normalizeNamePoolTrait($faction);
    $lookupNames = getBioRandomLookupNames($name, $originalName);
    $cacheKey = $genderHint . '|' . $raceKey . '|' . $factionKey . '|' . implode(',', $lookupNames);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $requiredTypes = getRequiredBioTraitTypes();
    $specificityLevels = [7, 6, 5, 4, 3, 2, 1, 0];
    $candidatesByType = [];
    foreach ($requiredTypes as $requiredType) {
        $candidatesByType[$requiredType] = [];
        foreach ($specificityLevels as $level) {
            $candidatesByType[$requiredType][$level] = [];
        }
    }
    $lookupNameSet = [];
    foreach ($lookupNames as $candidateName) {
        $lookupNameSet[$candidateName] = true;
    }

    $db = $GLOBALS["db"];
    $rows = [];
    try {
        $rows = $db->fetchAll(
            "SELECT type, description, race, gender, faction, name, tts_filter_preset
             FROM combined_bio_random
             WHERE COALESCE(is_enabled, TRUE) = TRUE
             ORDER BY id ASC"
        );
    } catch (Throwable $exception) {
        $cache[$cacheKey] = $candidatesByType;
        return $cache[$cacheKey];
    }

    foreach ($rows as $row) {
        $type = normalizeBioTraitType(strval($row['type'] ?? ''));
        if ($type === '' || !isset($candidatesByType[$type])) {
            continue;
        }

        $description = trim(strval($row['description'] ?? ''));
        if ($description === '') {
            continue;
        }

        $rowRace = normalizeNamePoolTrait(strval($row['race'] ?? ''));
        $rowFaction = normalizeNamePoolTrait(strval($row['faction'] ?? ''));
        $rowGender = normalizeGenderHint(strval($row['gender'] ?? ''));
        $rowName = normalizeNamePoolTrait(strval($row['name'] ?? ''));

        if ($rowName !== '' && !isset($lookupNameSet[$rowName])) {
            continue;
        }

        if ($rowRace !== '' && ($raceKey === '' || $rowRace !== $raceKey)) {
            continue;
        }
        if ($rowFaction !== '' && ($factionKey === '' || $rowFaction !== $factionKey)) {
            continue;
        }
        if ($rowGender !== '' && $rowGender !== 'neutral' && ($genderHint === '' || $rowGender !== $genderHint)) {
            continue;
        }

        $specificity = 0;
        if ($rowName !== '') {
            // Name-matched rows should win over generic race/gender/faction rows.
            $specificity += 4;
        }
        if ($rowRace !== '') {
            $specificity++;
        }
        if ($rowFaction !== '') {
            $specificity++;
        }
        if ($rowGender !== '' && $rowGender !== 'neutral') {
            $specificity++;
        }
        if ($specificity < 0) {
            $specificity = 0;
        } elseif ($specificity > 7) {
            $specificity = 7;
        }

        $descriptionKey = strtolower($description);
        if (!isset($candidatesByType[$type][$specificity][$descriptionKey])) {
            $candidatesByType[$type][$specificity][$descriptionKey] = [
                'description' => $description,
            'tts_filter_preset' => $row['tts_filter_preset'] ?? null,
            ];
        }
    }

    foreach ($requiredTypes as $requiredType) {
        foreach ($specificityLevels as $specificity) {
            $candidatesByType[$requiredType][$specificity] = array_values($candidatesByType[$requiredType][$specificity]);
        }
    }

    $cache[$cacheKey] = $candidatesByType;
    return $candidatesByType;
}

function selectRandomBioTraitSelections(
    string $seedName = '',
    string $race = '',
    string $gender = '',
    string $faction = '',
    string $name = '',
    string $originalName = ''
): array {
    $requiredTypes = getRequiredBioTraitTypes();
    $specificityLevels = [7, 6, 5, 4, 3, 2, 1, 0];
    $candidatesByType = loadBioRandomCandidates($race, $gender, $faction, $name, $originalName);
    $selections = createEmptyBioTraitSelections();

    foreach ($requiredTypes as $type) {
        if (!isset($candidatesByType[$type])) {
            continue;
        }

        $options = [];
        foreach ($specificityLevels as $specificity) {
            if (count($candidatesByType[$type][$specificity] ?? []) > 0) {
                $options = $candidatesByType[$type][$specificity];
                break;
            }
        }

        if (count($options) === 0) {
            continue;
        }

        if ($seedName !== '') {
            $bestIndex = 0;
            $bestRank = null;
            foreach ($options as $index => $option) {
                $description = trim(strval($option['description'] ?? ''));
                $rank = intval(sprintf('%u', crc32(strtolower($seedName . '|' . $type . '|' . $description))));
                if ($bestRank === null || $rank < $bestRank) {
                    $bestRank = $rank;
                    $bestIndex = $index;
                }
            }
            $selections[$type] = [
                'description' => trim(strval($options[$bestIndex]['description'] ?? '')),
                'tts_filter_preset' => $options[$bestIndex]['tts_filter_preset'] ?? null,
            ];
            continue;
        }

        $randomIndex = random_int(0, count($options) - 1);
        $selections[$type] = [
            'description' => trim(strval($options[$randomIndex]['description'] ?? '')),
            'tts_filter_preset' => $options[$randomIndex]['tts_filter_preset'] ?? null,
        ];
    }

    return $selections;
}

function selectRandomBioTraits(
    string $seedName = '',
    string $race = '',
    string $gender = '',
    string $faction = '',
    string $name = '',
    string $originalName = ''
): array {
    $traits = [];
    foreach (selectRandomBioTraitSelections($seedName, $race, $gender, $faction, $name, $originalName) as $type => $selection) {
        $traits[$type] = trim(strval($selection['description'] ?? ''));
    }
    return $traits;
}

function selectBioTraitsForNpc(
    string $name = '',
    string $race = '',
    string $gender = '',
    string $faction = '',
    string $originalName = ''
): array {
    $requiredTypes = getRequiredBioTraitTypes();
    $uniqueSelections = loadBioUniqueTraitSelections($name);
    $randomSelections = selectRandomBioTraitSelections($name, $race, $gender, $faction, $name, $originalName);
    $resolvedTraits = [];
    $traitSources = [];

    foreach ($requiredTypes as $type) {
        $uniqueTrait = trim(strval($uniqueSelections[$type]['description'] ?? ''));
        if ($uniqueTrait !== '') {
            $resolvedTraits[$type] = $uniqueTrait;
            $traitSources[$type] = 'unique';
            continue;
        }

        $randomTrait = trim(strval($randomSelections[$type]['description'] ?? ''));
        if ($randomTrait !== '') {
            $resolvedTraits[$type] = $randomTrait;
            $traitSources[$type] = 'random';
            continue;
        }

        $traitSources[$type] = 'default';
    }

    // Unique presets win; use the established trait order within each pool.
    $voiceFilter = null;
    foreach (['unique' => $uniqueSelections, 'random' => $randomSelections] as $source => $selections) {
        foreach ($requiredTypes as $type) {
            if (($traitSources[$type] ?? '') !== $source) continue;
            $preset = $selections[$type]['tts_filter_preset'] ?? null;
            if ($preset === null || $preset === '') continue;
            $voiceFilter = stobeNormalizeTtsFilterPresetId($preset);
            break 2;
        }
    }
    return [
        'tts_filter_preset' => $voiceFilter,
        'traits' => $resolvedTraits,
        'sources' => $traitSources,
    ];
}

function loadGlobalNamePool(string $gender = '', string $race = '', string $faction = ''): array {
    static $cache = [];
    $genderHint = normalizeGenderHint($gender);
    $raceKey = normalizeNamePoolTrait($race);
    $factionKey = normalizeNamePoolTrait($faction);
    $cacheKey = $genderHint . '|' . $raceKey . '|' . $factionKey;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $db = $GLOBALS["db"];
    $rows = [];

    $relationExists = static function (string $name, string $kind) use ($db): bool {
        $tableName = trim($name);
        if ($tableName === '') {
            return false;
        }
        if ($kind === 'view') {
            $probe = $db->fetchOne(
                "SELECT 1 AS present
                 FROM information_schema.views
                 WHERE table_schema = 'public'
                   AND table_name = $1
                 LIMIT 1",
                [$tableName]
            );
            return is_array($probe) && count($probe) > 0;
        }
        $probe = $db->fetchOne(
            "SELECT 1 AS present
             FROM information_schema.tables
             WHERE table_schema = 'public'
               AND table_name = $1
             LIMIT 1",
            [$tableName]
        );
        return is_array($probe) && count($probe) > 0;
    };

    $viewSource = '';
    if ($relationExists('combined_rename_global', 'view')) {
        $viewSource = 'combined_rename_global';
    }

    if ($viewSource !== '') {
        $rows = $db->fetchAll(
            "SELECT name, gender, race, faction
             FROM {$viewSource}
             WHERE COALESCE(is_enabled, TRUE) = TRUE
             ORDER BY name ASC"
        );
    } else {
        // Last-resort fallback: compose pool from rename_global tables.
        $poolByName = [];
        $customTables = ['rename_global_custom'];
        $baseTables = ['rename_global'];

        foreach ($customTables as $tableName) {
            if (!$relationExists($tableName, 'table')) {
                continue;
            }
            $tableRows = $db->fetchAll(
                "SELECT name, gender, race, faction
                 FROM {$tableName}
                 WHERE COALESCE(is_enabled, TRUE) = TRUE
                 ORDER BY name ASC"
            );
            foreach ($tableRows as $row) {
                $candidate = trim(strval($row['name'] ?? ''));
                if ($candidate === '') {
                    continue;
                }
                $poolByName[strtolower($candidate)] = $row;
            }
        }

        foreach ($baseTables as $tableName) {
            if (!$relationExists($tableName, 'table')) {
                continue;
            }
            $tableRows = $db->fetchAll(
                "SELECT name, gender, race, faction
                 FROM {$tableName}
                 WHERE COALESCE(is_enabled, TRUE) = TRUE
                 ORDER BY name ASC"
            );
            foreach ($tableRows as $row) {
                $candidate = trim(strval($row['name'] ?? ''));
                if ($candidate === '') {
                    continue;
                }
                $key = strtolower($candidate);
                if (isset($poolByName[$key])) {
                    continue;
                }
                $poolByName[$key] = $row;
            }
        }

        $rows = array_values($poolByName);
    }

    $tierExact = [];
    $tierPartial = [];
    $tierGeneral = [];
    foreach ($rows as $row) {
        $candidate = baseNameWithoutBracketSuffix(trim(strval($row['name'] ?? '')));
        if ($candidate === '') {
            continue;
        }

        $rowGender = normalizeGenderHint(strval($row['gender'] ?? ''));
        if ($genderHint !== '' && $rowGender !== '' && $rowGender !== 'neutral' && $rowGender !== $genderHint) {
            continue;
        }

        $rowRace = normalizeNamePoolTrait(strval($row['race'] ?? ''));
        $rowFaction = normalizeNamePoolTrait(strval($row['faction'] ?? ''));
        if ($rowRace !== '' && ($raceKey === '' || $rowRace !== $raceKey)) {
            continue;
        }
        if ($rowFaction !== '' && ($factionKey === '' || $rowFaction !== $factionKey)) {
            continue;
        }

        $key = strtolower($candidate);
        $isRaceSpecific = $rowRace !== '';
        $isFactionSpecific = $rowFaction !== '';
        if ($isRaceSpecific && $isFactionSpecific) {
            if (!isset($tierExact[$key])) {
                $tierExact[$key] = $candidate;
            }
            continue;
        }
        if ($isRaceSpecific || $isFactionSpecific) {
            if (!isset($tierExact[$key]) && !isset($tierPartial[$key])) {
                $tierPartial[$key] = $candidate;
            }
            continue;
        }
        if (!isset($tierExact[$key]) && !isset($tierPartial[$key]) && !isset($tierGeneral[$key])) {
            $tierGeneral[$key] = $candidate;
        }
    }

    $cache[$cacheKey] = array_values(array_merge($tierExact, $tierPartial, $tierGeneral));
    return $cache[$cacheKey];
}

function isNpcNameTaken(string $name): bool {
    $db = $GLOBALS["db"];
    $row = $db->fetchOne(
        "SELECT id FROM core_npc WHERE LOWER(name) = LOWER($1) LIMIT 1",
        [$name]
    );
    return $row !== false;
}

function normalizeGeneratedLoreNameBase(string $name): string {
    $base = baseNameWithoutBracketSuffix(trim($name));
    $base = preg_replace('/\s+[0-9]+$/', '', $base) ?? $base;
    return strtolower(trim($base));
}

function loadNpcLoreNameReservations(): array {
    $db = $GLOBALS["db"];
    $rows = $db->fetchAll("SELECT name FROM core_npc WHERE COALESCE(name, '') <> ''");
    $exact = [];
    $bases = [];
    foreach ($rows as $row) {
        $name = baseNameWithoutBracketSuffix(trim(strval($row['name'] ?? '')));
        if ($name === '') {
            continue;
        }
        $exact[strtolower($name)] = true;
        $base = normalizeGeneratedLoreNameBase($name);
        if ($base !== '') {
            $bases[$base] = true;
        }
    }
    return ['exact' => $exact, 'bases' => $bases];
}

function buildIdentityRenameNameSeed(string $currentName, string $storageId = '', string $serial = ''): string {
    $identityKey = trim($storageId);
    if ($identityKey === '') {
        $identityKey = trim($serial);
    }
    return strtolower(trim($currentName)) . '|' . strtolower($identityKey);
}

function generateUniqueLoreName(string $gender = '', string $seedName = '', string $race = '', string $faction = ''): string {
    $pool = loadGlobalNamePool($gender, $race, $faction);
    if (count($pool) === 0 && ($gender !== '' || $race !== '' || $faction !== '')) {
        $pool = loadGlobalNamePool('', '', '');
    }
    if (count($pool) === 0) {
        return 'Wanderer';
    }

    $seed = $seedName !== '' ? $seedName : (string)microtime(true);
    $unsignedHash = intval(sprintf('%u', crc32(strtolower($seed))));
    $startIndex = $unsignedHash % count($pool);
    $reservations = loadNpcLoreNameReservations();
    $reservedBases = is_array($reservations['bases'] ?? null) ? $reservations['bases'] : [];
    $reservedExact = is_array($reservations['exact'] ?? null) ? $reservations['exact'] : [];

    for ($offset = 0; $offset < count($pool); $offset++) {
        $candidate = $pool[($startIndex + $offset) % count($pool)];
        $candidateBase = normalizeGeneratedLoreNameBase($candidate);
        if ($candidateBase !== '' && !isset($reservedBases[$candidateBase])) {
            return $candidate;
        }
    }

    $base = $pool[$startIndex];
    $suffix = 2;
    while (isset($reservedExact[strtolower($base . ' ' . $suffix)])) {
        $suffix++;
    }
    return $base . ' ' . $suffix;
}

function shouldBracketOriginalNameOnAutoRename(): bool {
    static $cached = null;
    if ($cached !== null) {
        return boolval($cached);
    }
    $cached = getSettingBool('BRACKET_ORIGINAL_NAME', true);
    return boolval($cached);
}

function formatAutoRenameWithOriginal(string $generatedName, string $originalName): string {
    $generated = baseNameWithoutBracketSuffix(trim($generatedName));
    $original = baseNameWithoutBracketSuffix(trim($originalName));
    if ($generated === '' || $original === '') {
        return $generated !== '' ? $generated : $generatedName;
    }
    if (strtolower($generated) === strtolower($original)) {
        return $generated;
    }
    if (!shouldBracketOriginalNameOnAutoRename()) {
        return $generated;
    }
    return $generated . ' [' . $original . ']';
}

function buildIdentityRenameBootstrapProfile(array $entry, string $storageId): array {
    $pick = static function (array $source, array $keys): string {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            if (is_array($source[$key]) || is_object($source[$key])) {
                continue;
            }
            $value = trim(strval($source[$key]));
            if ($value === '') {
                continue;
            }
            return $value;
        }
        return '';
    };

    $race = $pick($entry, ['race', 'race_name']);
    if (strtolower($race) === 'unknown') {
        $race = '';
    }

    $faction = $pick($entry, ['faction', 'faction_name', 'origin_faction']);
    if (strtolower($faction) === 'unknown') {
        $faction = '';
    }
    $factionId = $pick($entry, ['faction_id', 'factionID']);
    $faction = composeFactionWithId($faction, $factionId);

    $gender = $pick($entry, ['gender', 'sex']);
    if (strtolower($gender) === 'unknown') {
        $gender = '';
    }

    $equipment = $pick($entry, ['equipment']);
    $inventory = $pick($entry, ['inventory']);
    $skills = $pick($entry, ['skills']);

    $profile = [];
    if ($race !== '') {
        $profile['race'] = $race;
    }
    if ($faction !== '') {
        $profile['faction'] = $faction;
        $profile['occupation'] = stobeBuildFactionOccupationText($faction);
        $profile['goals'] = 'Survive, recover, and pursue faction priorities.';
    }
    if ($gender !== '') {
        $profile['gender'] = $gender;
    }
    if ($equipment !== '') {
        $profile['equipment'] = $equipment;
    }
    if ($inventory !== '') {
        $profile['inventory'] = $inventory;
    }
    if ($skills !== '') {
        $profile['skills'] = $skills;
    }

    if ($storageId !== '') {
        $profile['metadata'] = [
            'storage_id' => $storageId,
            'source' => 'batch_identity_rename',
        ];
    }

    return $profile;
}

function loadServerRenameEligibilityTokens(): array {
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $tokens = [];
    $db = $GLOBALS["db"] ?? null;
    if ($db) {
        $viewProbe = $db->fetchOne(
            "SELECT to_regclass('public.combined_rename_token_global') AS rel"
        );
        $hasView = trim(strval($viewProbe['rel'] ?? '')) !== '';
        if ($hasView) {
            $rows = $db->fetchAll(
                "SELECT token
                 FROM combined_rename_token_global
                 WHERE COALESCE(is_enabled, TRUE) = TRUE
                 ORDER BY token ASC"
            );
            foreach ($rows as $row) {
                $token = trim(strval($row['token'] ?? ''));
                if ($token === '') {
                    continue;
                }
                $tokens[] = strtolower($token);
            }
        }
    }

    $normalized = [];
    $seen = [];
    foreach ($tokens as $token) {
        $clean = strtolower(trim(strval($token)));
        if ($clean === '') {
            continue;
        }
        if (isset($seen[$clean])) {
            continue;
        }
        $seen[$clean] = true;
        $normalized[] = $clean;
    }

    if (count($normalized) === 0) {
        stobeLogWarn('Rename eligibility token list is empty; no auto-renames will trigger', [
            'source' => 'combined_rename_token_global',
        ]);
    }

    $cache = $normalized;
    return $cache;
}

// Biography enablement controls prompt content, not whether a named NPC is unique.
function loadServerProtectedUniqueNames(): array {
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $protected = [];
    $db = $GLOBALS["db"] ?? null;
    if ($db) {
        $rows = $db->fetchAll(
            "SELECT DISTINCT name
             FROM combined_bio_unique
             WHERE BTRIM(COALESCE(name, '')) <> ''"
        );
        foreach ($rows as $row) {
            $normalized = normalizeBioUniqueName(strval($row['name'] ?? ''));
            if ($normalized !== '') {
                $protected[$normalized] = true;
            }
        }
    }

    $cache = $protected;
    return $cache;
}

function isServerRenameEligibleName(string $name): bool {
    $normalized = normalizeParticipantNameToken($name);
    if ($normalized === '') {
        return false;
    }

    if (strpos($normalized, '[') !== false || strpos($normalized, ']') !== false) {
        return false;
    }

    $uniqueKey = normalizeBioUniqueName($normalized);
    $protectedUniqueNames = loadServerProtectedUniqueNames();
    if ($uniqueKey !== '' && isset($protectedUniqueNames[$uniqueKey])) {
        return false;
    }

    $tokens = loadServerRenameEligibilityTokens();
    $lower = strtolower($normalized);
    foreach ($tokens as $token) {
        if ($token === '') {
            continue;
        }
        if (strpos($lower, strtolower($token)) !== false) {
            return true;
        }
    }
    return false;
}

function batchIdentityRenameDecisions(array $identities): array {
    $normalizedNames = [];
    foreach ($identities as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $name = normalizeParticipantNameToken(strval($entry['name'] ?? ''));
        // Avoid creating placeholder profiles for names that are about to be
        // auto-renamed; those placeholders can later conflict by storage_id.
        if ($name !== '' && !isServerRenameEligibleName($name)) {
            $normalizedNames[] = $name;
        }
    }
    if (count($normalizedNames) > 0) {
        ensureNpcProfilesFromParticipants($normalizedNames);
    }

    $results = [];
    foreach ($identities as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $context = [];
        if (isset($entry['context']) && is_array($entry['context'])) {
            $context = $entry['context'];
        }
        $identitySource = $entry;
        if (count($context) > 0) {
            $contextBridgeKeys = [
                'gender', 'sex',
                'race', 'race_name',
                'faction', 'faction_name', 'origin_faction',
                'faction_id', 'factionID',
                'equipment',
                'skills',
                'inventory',
                'storage_id', 'id', 'refid', 'handle',
            ];
            foreach ($contextBridgeKeys as $bridgeKey) {
                if (!array_key_exists($bridgeKey, $context)) {
                    continue;
                }
                if (array_key_exists($bridgeKey, $identitySource)) {
                    $existing = $identitySource[$bridgeKey];
                    if (!is_array($existing) && !is_object($existing) && trim(strval($existing)) !== '') {
                        continue;
                    }
                }
                $identitySource[$bridgeKey] = $context[$bridgeKey];
            }
        }

        $serial = strval($entry['serial'] ?? '');
        $currentName = normalizeParticipantNameToken(strval($identitySource['name'] ?? ''));
        $storageId = normalizeStorageIdToken(
            $identitySource['storage_id'] ?? ($identitySource['id'] ?? ($identitySource['refid'] ?? ($identitySource['handle'] ?? $serial)))
        );
        $gender = strval($identitySource['gender'] ?? ($identitySource['sex'] ?? ''));
        $race = strval($identitySource['race'] ?? ($identitySource['race_name'] ?? ''));
        $faction = strval($identitySource['faction'] ?? ($identitySource['faction_name'] ?? ($identitySource['origin_faction'] ?? '')));

        if ($currentName === '') {
            $results[] = ['serial' => $serial, 'status' => 'ok'];
            continue;
        }
        $existingNpc = getNpcData($currentName);
        if (is_array($existingNpc)) {
            $existingOriginal = normalizeParticipantNameToken(strval($existingNpc['original_name'] ?? ''));
            if ($existingOriginal !== '' && strcasecmp($existingOriginal, $currentName) !== 0) {
                stobeLogImport('Batch identity rename skipped (already renamed)', [
                    'serial' => $serial,
                    'current_name' => $currentName,
                    'original_name' => $existingOriginal,
                    'storage_id' => $storageId,
                ], 'DEBUG');
                $results[] = ['serial' => $serial, 'status' => 'ok'];
                continue;
            }
        }
        if (!isServerRenameEligibleName($currentName)) {
            stobeLogImport('Batch identity rename skipped (not eligible)', [
                'serial' => $serial,
                'current_name' => $currentName,
                'storage_id' => $storageId,
            ], 'DEBUG');
            $results[] = ['serial' => $serial, 'status' => 'ok'];
            continue;
        }

        $firstSeenOriginal = ensureOriginalName($currentName, $currentName);
        $nameSeed = buildIdentityRenameNameSeed($currentName, $storageId, $serial);
        $generated = generateUniqueLoreName($gender, $nameSeed, $race, $faction);
        $generatedBase = baseNameWithoutBracketSuffix($generated);
        if ($generatedBase === '') {
            $generatedBase = 'Wanderer';
        }
        $renameCandidates = [];
        $renameCandidates[] = formatAutoRenameWithOriginal($generatedBase, $firstSeenOriginal);
        for ($suffix = 2; $suffix <= 12; $suffix++) {
            $renameCandidates[] = formatAutoRenameWithOriginal($generatedBase . ' ' . $suffix, $firstSeenOriginal);
        }

        $uniqueCandidates = [];
        $seenCandidates = [];
        foreach ($renameCandidates as $candidateName) {
            $candidateNormalized = normalizeParticipantNameToken($candidateName);
            if ($candidateNormalized === '' || strtolower($candidateNormalized) === strtolower($currentName)) {
                continue;
            }
            $key = strtolower($candidateNormalized);
            if (isset($seenCandidates[$key])) {
                continue;
            }
            $seenCandidates[$key] = true;
            $uniqueCandidates[] = $candidateNormalized;
        }
        if (count($uniqueCandidates) === 0) {
            $results[] = ['serial' => $serial, 'status' => 'ok'];
            continue;
        }

        $bootstrapProfile = buildIdentityRenameBootstrapProfile($identitySource, $storageId);
        $renamePersistResult = ['status' => 'error', 'message' => 'No rename candidates attempted'];
        $newName = '';
        foreach ($uniqueCandidates as $candidateName) {
            if (strpos($candidateName, '[') !== false) {
                stobeLogImport('Batch identity rename candidate', [
                    'serial' => $serial,
                    'storage_id' => $storageId,
                    'current_name' => $currentName,
                    'new_name' => $candidateName,
                    'input_gender' => trim($gender),
                    'input_race' => trim($race),
                    'input_faction' => trim($faction),
                    'seeded_keys' => array_keys($bootstrapProfile),
                    'has_context' => count($context) > 0,
                ], count($bootstrapProfile) > 1 ? 'DEBUG' : 'WARN');
            }

            $renamePersistResult = persistManualRename($currentName, $candidateName, $storageId, $bootstrapProfile);
            $persistStatus = strtolower(trim(strval($renamePersistResult['status'] ?? '')));
            if ($persistStatus === 'ok') {
                $newName = $candidateName;
                $persistedNewName = normalizeParticipantNameToken(strval($renamePersistResult['new_name'] ?? ''));
                if ($persistedNewName !== '') {
                    $newName = $persistedNewName;
                }
                break;
            }

            $persistMessage = strtolower(trim(strval($renamePersistResult['message'] ?? '')));
            if ($persistMessage === 'name already exists') {
                continue;
            }
            break;
        }
        if ($newName === '') {
            stobeLogImport('Batch identity rename persistence failed', [
                'serial' => $serial,
                'storage_id' => $storageId,
                'current_name' => $currentName,
                'attempted_candidates' => $uniqueCandidates,
                'persist_result' => $renamePersistResult,
            ], 'WARN');
            $results[] = ['serial' => $serial, 'status' => 'retry'];
            continue;
        }
        if (count($context) > 0) {
            $snapshot = $context;
            $snapshot['name'] = $newName;
            if ($storageId !== '' && (!isset($snapshot['storage_id']) || trim(strval($snapshot['storage_id'])) === '')) {
                $snapshot['storage_id'] = $storageId;
            }
            if (!isset($snapshot['race']) && isset($bootstrapProfile['race'])) {
                $snapshot['race'] = $bootstrapProfile['race'];
            }
            if (!isset($snapshot['faction']) && isset($bootstrapProfile['faction'])) {
                $snapshot['faction'] = $bootstrapProfile['faction'];
            }
            if (!isset($snapshot['gender']) && isset($bootstrapProfile['gender'])) {
                $snapshot['gender'] = $bootstrapProfile['gender'];
            }
            $contextGameTs = intval($context['game_ts'] ?? ($entry['game_ts'] ?? 0));
            if ($contextGameTs < 0) {
                $contextGameTs = 0;
            }
            $contextHydrated = storeNpcSnapshot($snapshot, $contextGameTs);
            stobeLogImport('Batch identity rename snapshot hydrate', [
                'serial' => $serial,
                'new_name' => $newName,
                'storage_id' => normalizeStorageIdToken($snapshot['storage_id'] ?? ''),
                'stored' => $contextHydrated,
                'game_ts' => $contextGameTs,
                'snapshot_keys' => array_keys($snapshot),
                'stats_count' => is_array($snapshot['stats'] ?? null) ? count($snapshot['stats']) : 0,
                'has_medical' => is_array($snapshot['medical'] ?? null) && count($snapshot['medical']) > 0,
                'equipment_len' => strlen(trim(strval($snapshot['equipment'] ?? ''))),
            ], $contextHydrated ? 'DEBUG' : 'WARN');
        }

        $results[] = [
            'serial' => $serial,
            'status' => 'rename',
            'new_name' => $newName,
        ];
    }

    return $results;
}

function persistManualRename(
    string $oldName,
    string $newName,
    string $storageId = '',
    array $bootstrapProfile = []
): array {
    $db = $GLOBALS["db"];
    $oldNormalized = normalizeParticipantNameToken($oldName);
    $newNormalized = normalizeParticipantNameToken($newName);
    $storageIdNormalized = normalizeStorageIdToken($storageId);

    if ($oldNormalized === '' || $newNormalized === '') {
        return ['status' => 'error', 'message' => 'Missing names'];
    }

    if (strtolower($oldNormalized) === strtolower($newNormalized)) {
        return ['status' => 'ok', 'new_name' => $newNormalized];
    }

    $oldRow = $db->fetchOne(
        "SELECT id, name, original_name FROM core_npc WHERE LOWER(name) = LOWER($1) LIMIT 1",
        [$oldNormalized]
    );
    if ($storageIdNormalized !== '') {
        $storageIdVariants = buildStorageIdSearchVariants($storageIdNormalized);
        if (count($storageIdVariants) > 0) {
            $storageParams = [];
            $variantPlaceholders = [];
            $variantIndex = 1;
            foreach ($storageIdVariants as $variant) {
                $storageParams[] = $variant;
                $variantPlaceholders[] = "LOWER($" . $variantIndex . ")";
                $variantIndex++;
            }
            $storageMatchedRow = $db->fetchOne(
                "SELECT id, name, original_name
                 FROM core_npc
                 WHERE LOWER(COALESCE(metadata->>'storage_id', '')) IN (" . implode(',', $variantPlaceholders) . ")
                 ORDER BY updated_at DESC, gamets_last_updated DESC
                 LIMIT 1",
                $storageParams
            );
            if ($storageMatchedRow) {
                if (!$oldRow) {
                    $oldRow = $storageMatchedRow;
                    stobeLogImport('Manual rename source resolved by storage_id', [
                        'requested_old_name' => $oldNormalized,
                        'resolved_old_name' => strval($oldRow['name'] ?? ''),
                        'new_name' => $newNormalized,
                        'storage_id' => $storageIdNormalized,
                    ], 'DEBUG');
                } else {
                    $oldRowId = intval($oldRow['id'] ?? 0);
                    $storageRowId = intval($storageMatchedRow['id'] ?? 0);
                    $oldRowName = normalizeParticipantNameToken(strval($oldRow['name'] ?? ''));
                    $storageRowName = normalizeParticipantNameToken(strval($storageMatchedRow['name'] ?? ''));
                    $oldLooksPlaceholder = $oldRowName !== '' && strcasecmp($oldRowName, $oldNormalized) === 0;
                    $storageIsDifferentName = $storageRowName !== '' && strcasecmp($storageRowName, $oldNormalized) !== 0;
                    if ($oldLooksPlaceholder && $storageIsDifferentName && $oldRowId > 0 && $storageRowId > 0 && $oldRowId !== $storageRowId) {
                        $oldRow = $storageMatchedRow;
                        stobeLogImport('Manual rename source switched to storage_id canonical row', [
                            'requested_old_name' => $oldNormalized,
                            'placeholder_row_id' => $oldRowId,
                            'canonical_row_id' => $storageRowId,
                            'canonical_name' => $storageRowName,
                            'new_name' => $newNormalized,
                            'storage_id' => $storageIdNormalized,
                        ], 'DEBUG');
                    }
                }
            }
        }
    }
    $newRow = $db->fetchOne(
        "SELECT id, name, COALESCE(metadata->>'storage_id', '') AS storage_id
         FROM core_npc
         WHERE LOWER(name) = LOWER($1)
         LIMIT 1",
        [$newNormalized]
    );

    if (!$oldRow) {
        if ($newRow) {
            $newRowStorageId = normalizeStorageIdToken(strval($newRow['storage_id'] ?? ''));
            $sameStorageId = false;
            if ($storageIdNormalized !== '' && $newRowStorageId !== '') {
                $requestedVariants = array_map('strtolower', buildStorageIdSearchVariants($storageIdNormalized));
                $existingVariants = array_map('strtolower', buildStorageIdSearchVariants($newRowStorageId));
                $sameStorageId = count(array_intersect($requestedVariants, $existingVariants)) > 0;
            }
            if (!$sameStorageId) {
                return ['status' => 'error', 'message' => 'Name already exists'];
            }
            if (count($bootstrapProfile) > 0) {
                storeNpcProfile($newNormalized, $bootstrapProfile);
            }
            return ['status' => 'ok', 'new_name' => strval($newRow['name'])];
        }
        $resolvedBootstrap = $bootstrapProfile;
        if ($storageIdNormalized !== '') {
            $existingMetadata = [];
            if (isset($resolvedBootstrap['metadata']) && is_array($resolvedBootstrap['metadata'])) {
                $existingMetadata = $resolvedBootstrap['metadata'];
            }
            $existingMetadata['storage_id'] = $storageIdNormalized;
            $resolvedBootstrap['metadata'] = $existingMetadata;
        }
        storeNpcProfile($newNormalized, $resolvedBootstrap);
        if (strpos($newNormalized, '[') !== false) {
            stobeLogImport('Manual rename bootstrap created row', [
                'requested_old_name' => $oldNormalized,
                'new_name' => $newNormalized,
                'storage_id' => $storageIdNormalized,
                'bootstrap_keys' => array_keys($resolvedBootstrap),
            ], count($resolvedBootstrap) > 1 ? 'DEBUG' : 'WARN');
        }
        ensureOriginalName($newNormalized, baseNameWithoutBracketSuffix($oldNormalized));
        return ['status' => 'ok', 'new_name' => $newNormalized];
    }

    $oldId = intval($oldRow['id'] ?? 0);
    $newId = intval($newRow['id'] ?? 0);
    if ($newRow && $newId !== $oldId) {
        return ['status' => 'error', 'message' => 'Name already exists'];
    }

    $existingOriginal = trim(strval($oldRow['original_name'] ?? ''));
    if ($existingOriginal === '') {
        $existingOriginal = baseNameWithoutBracketSuffix(strval($oldRow['name'] ?? $oldNormalized));
    }

    $result = $db->exec(
        "UPDATE core_npc
         SET name = $1,
             original_name = CASE
                 WHEN original_name IS NULL OR BTRIM(original_name) = '' THEN $2
                 ELSE original_name
             END,
             updated_at = NOW()
         WHERE id = $3",
        [$newNormalized, $existingOriginal, $oldId]
    );
    if ($result === false) {
        return ['status' => 'error', 'message' => 'Failed to update profile'];
    }

    $bootstrapOccupation = stobeNormalizeOccupationText(
        trim(strval($bootstrapProfile['occupation'] ?? '')),
        trim(strval($bootstrapProfile['faction'] ?? ($bootstrapProfile['faction_name'] ?? '')))
    );
    if ($bootstrapOccupation !== '') {
        $db->exec(
            "UPDATE core_npc
             SET occupation = $1,
                 updated_at = NOW()
             WHERE id = $2",
            [$bootstrapOccupation, $oldId]
        );
    }
    $bootstrapGoals = trim(strval($bootstrapProfile['goals'] ?? ''));
    if ($bootstrapGoals !== '') {
        $db->exec(
            "UPDATE core_npc
             SET goals = $1,
                 updated_at = NOW()
             WHERE id = $2",
            [$bootstrapGoals, $oldId]
        );
    }

    if ($storageIdNormalized !== '') {
        $db->exec(
            "UPDATE core_npc
             SET metadata = CASE
                 WHEN metadata IS NULL
                   OR metadata = '[]'::jsonb
                   OR jsonb_typeof(metadata) <> 'object'
                 THEN jsonb_build_object('storage_id', $2::text)
                 ELSE metadata || jsonb_build_object('storage_id', $2::text)
             END,
             updated_at = NOW()
             WHERE id = $1",
            [$oldId, $storageIdNormalized]
        );
    }

    if (count($bootstrapProfile) > 0) {
        $resolvedBootstrap = $bootstrapProfile;
        if ($storageIdNormalized !== '') {
            $existingMetadata = [];
            if (isset($resolvedBootstrap['metadata']) && is_array($resolvedBootstrap['metadata'])) {
                $existingMetadata = $resolvedBootstrap['metadata'];
            }
            $existingMetadata['storage_id'] = $storageIdNormalized;
            $resolvedBootstrap['metadata'] = $existingMetadata;
        }
        storeNpcProfile($newNormalized, $resolvedBootstrap);
        if (strpos($newNormalized, '[') !== false) {
            stobeLogImport('Manual rename bootstrap merged profile', [
                'new_name' => $newNormalized,
                'storage_id' => $storageIdNormalized,
                'bootstrap_keys' => array_keys($resolvedBootstrap),
            ], count($resolvedBootstrap) > 1 ? 'DEBUG' : 'WARN');
        }
    }

    return ['status' => 'ok', 'new_name' => $newNormalized];
}

/**
 * Build context from eventlog for LLM prompts.
 * Returns the most recent N events as formatted history.
 */
function stobeResolveNpcEventHistoryAliases(array|false $npcData, string $primaryName): array {
    if (!is_array($npcData) || count($npcData) === 0) {
        return [];
    }

    $primaryKey = strtolower(normalizeParticipantNameToken($primaryName));
    $metadata = normalizeCoreNpcMetadata($npcData['metadata'] ?? []);
    $aliases = [];
    foreach ([$npcData['original_name'] ?? '', $metadata['original_name'] ?? ''] as $candidate) {
        $alias = normalizeParticipantNameToken(strval($candidate));
        $aliasKey = strtolower($alias);
        if ($alias === '' || $aliasKey === $primaryKey || isset($aliases[$aliasKey])) {
            continue;
        }
        $aliases[$aliasKey] = $alias;
    }
    return array_values($aliases);
}

function DataEventLog(
    int $limit = 0,
    string $actorFilter = '',
    string $campaignFilter = '',
    array $actorAliases = []
): array {
    $db = $GLOBALS["db"];

    if ($limit <= 0) {
        $limit = 50;
    }
    $excludeTypes = "'prechat','setconf','status_msg','user_input'";
    $deliveryVisibilitySql = stobeBuildEventlogDeliveryVisibilitySql('eventlog');

    $query = "SELECT * FROM eventlog
              WHERE type NOT IN ({$excludeTypes})
                AND {$deliveryVisibilitySql}";
    $params = [];

    // Exclude user-selected types from prompt history without changing recorded events.
    $filteredTypes = array_values(array_unique(array_filter(array_map(
        static fn($type): string => strtolower(trim($type)),
        explode(',', getSetting('EVENT_TYPE_FILTER', ''))
    ), static fn($type): bool => $type !== '')));
    if ($filteredTypes !== []) {
        $typeParams = [];
        foreach ($filteredTypes as $type) {
            $params[] = $type;
            $typeParams[] = '$' . count($params);
        }
        $query .= ' AND LOWER(type) NOT IN (' . implode(', ', $typeParams) . ')';
    }

    if (normalizeParticipantNameToken($actorFilter) !== '') {
        $query .= ' AND ' . stobeEventAudienceSql($actorFilter, $params, $actorAliases);
    }
    // Filter before limiting so unrelated names cannot crowd out actual witnesses.
    // Fetch a little extra: unverified gameplay-action rows are dropped below.
    $query .= " ORDER BY COALESCE(NULLIF(localts, 0), ts, 0) DESC, ts DESC, rowid DESC LIMIT " . intval($limit + 20);
    $rows = $db->fetchAll($query, $params);
    $filtered = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        // Older STOBE versions recorded selected gameplay bridge actions in history
        // before Kenshi confirmed they actually happened. Do not let those rows
        // override current live world state in future prompts.
        if (
            strtolower(trim(strval($row['type'] ?? ''))) === 'action'
            && function_exists('stobeActionRequiresVerifiedWorldOutcome')
        ) {
            $actionData = trim(strval($row['data'] ?? ''));
            $colon = strpos($actionData, ':');
            $actionToken = $colon === false ? $actionData : trim(substr($actionData, $colon + 1));
            if ($actionToken !== '' && stobeActionRequiresVerifiedWorldOutcome($actionToken)) {
                continue;
            }
        }
        $filtered[] = $row;
        if (count($filtered) >= $limit) {
            break;
        }
    }
    return $filtered;
}

function storeGameData(string $name, string $type, array $data): bool {
    $db = $GLOBALS["db"];
    $safeName = normalizeParticipantNameToken($name);
    if ($safeName === '') {
        return false;
    }

    $jsonColumns = ['stats', 'equipment', 'inventory', 'skills', 'limbs'];
    $scalarColumns = ['race', 'faction', 'squad_name', 'bio'];
    if (!in_array($type, $jsonColumns, true) && !in_array($type, $scalarColumns, true)) {
        return false;
    }

    $existing = $db->fetchOne(
        "SELECT metadata, race, faction, tags
         FROM core_npc
         WHERE LOWER(name) = LOWER($1)
         LIMIT 1",
        [$safeName]
    );

    $metadata = [];
    if ($existing && isset($existing['metadata'])) {
        $decodedMetadata = json_decode(strval($existing['metadata']), true);
        if (is_array($decodedMetadata)) {
            $metadata = $decodedMetadata;
        }
    }

    $playerData = [];
    if (isset($metadata['player_data']) && is_array($metadata['player_data'])) {
        $playerData = $metadata['player_data'];
    }

    $incomingRace = '';
    $incomingFaction = '';
    if (in_array($type, $jsonColumns, true)) {
        $playerData[$type] = $data;
    } else {
        $value = strval($data['value'] ?? '');
        $playerData[$type] = $value;
        if ($type === 'race') {
            $incomingRace = trim($value);
        } elseif ($type === 'faction') {
            $incomingFaction = trim($value);
        }
    }
    $playerData['updated_at'] = time();
    $metadata['player_data'] = $playerData;
    $metadata['last_gamedata_type'] = $type;
    $metadata['last_gamedata_source'] = 'gamedata.php';

    $currentRace = trim(strval($existing['race'] ?? ''));
    $currentFaction = trim(strval($existing['faction'] ?? ''));
    $race = $incomingRace !== '' ? $incomingRace : $currentRace;
    $faction = $incomingFaction !== '' ? $incomingFaction : $currentFaction;
    $tags = trim(strval($existing['tags'] ?? ''));
    if ($tags === '') {
        $tags = '';
    }
    $ruleProfileId = stobeResolveProfileIdFromImportRules($safeName, $race, '', $faction);
    $selectedProfileId = $ruleProfileId > 0 ? $ruleProfileId : getDefaultNpcProfileId();
    $selectedProfileIdOrNull = $selectedProfileId > 0 ? $selectedProfileId : null;
    if (
        $ruleProfileId > 0
        && strtolower(trim(strval($metadata['profile_assignment_source'] ?? ''))) !== 'manual'
    ) {
        $metadata['profile_assignment_source'] = 'rule';
    }
    $metadataJson = normalizeJsonString($metadata);

    $result = $db->exec(
        "INSERT INTO core_npc_master (
            name,
            race,
            faction,
            is_animal,
            metadata,
            tags,
            bounty,
            profile_id,
            updated_at
         ) VALUES (
            $1, $2, $3, FALSE, $4::jsonb, $5, '{}'::jsonb, $6, NOW()
         )
         ON CONFLICT (name) DO UPDATE SET
            race = CASE
                WHEN NULLIF($2, '') IS NOT NULL THEN $2
                ELSE core_npc_master.race
            END,
            faction = CASE
                WHEN NULLIF($3, '') IS NOT NULL THEN $3
                ELSE core_npc_master.faction
            END,
            is_animal = FALSE,
            metadata = $4::jsonb,
            tags = COALESCE(NULLIF(core_npc_master.tags, ''), $5),
            profile_id = COALESCE(core_npc_master.profile_id, EXCLUDED.profile_id),
            updated_at = NOW()",
        [$safeName, $race, $faction, $metadataJson, $tags, $selectedProfileIdOrNull]
    );

    if ($result !== false) {
        stobeApplyPlayerFactionProfileForNpcName($safeName, $faction);
    }

    return $result !== false;
}

function storeWorldStateEntries(array $payload): int {
    $db = $GLOBALS["db"];

    $gameTs = intval($payload['game_ts'] ?? 0);
    if ($gameTs < 0) {
        $gameTs = 0;
    }

    $source = trim(strval($payload['source'] ?? 'world_event_state_query'));
    if ($source === '') {
        $source = 'world_event_state_query';
    }

    $queries = $payload['queries'] ?? [];
    if (!is_array($queries) && is_string($queries) && trim($queries) !== '') {
        $decodedQueries = json_decode($queries, true);
        if (is_array($decodedQueries)) {
            $queries = $decodedQueries;
        }
    }
    if (!is_array($queries)) {
        $queries = [];
    }

    $parseBool = static function (mixed $value): ?bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return intval($value) !== 0;
        }
        $text = strtolower(trim(strval($value)));
        if ($text === '') {
            return null;
        }
        if (in_array($text, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($text, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return null;
    };

    $normalizeList = static function (mixed $value): array {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    };

    $entriesByKey = [];
    $buildEntryKey = static function (
        string $source,
        string $queryName,
        string $queryStringId,
        int $queryNumericId,
        bool $playerInvolvement,
        string $ruleCategory,
        string $entityName,
        string $entityStringId,
        int $entityNumericId
    ): string {
        return implode('|', [
            strtolower(trim($source)),
            strtolower(trim($queryName)),
            strtolower(trim($queryStringId)),
            strval($queryNumericId),
            $playerInvolvement ? '1' : '0',
            strtolower(trim($ruleCategory)),
            strtolower(trim($entityName)),
            strtolower(trim($entityStringId)),
            strval($entityNumericId),
        ]);
    };
    $setEntry = static function (
        int $gameTs,
        string $source,
        string $queryName,
        string $queryStringId,
        int $queryNumericId,
        bool $playerInvolvement,
        string $ruleCategory,
        string $entityName,
        string $entityStringId,
        int $entityNumericId,
        string $stateValue,
        ?bool $boolValue
    ) use (&$entriesByKey, $buildEntryKey): void {
        $key = $buildEntryKey(
            $source,
            $queryName,
            $queryStringId,
            $queryNumericId,
            $playerInvolvement,
            $ruleCategory,
            $entityName,
            $entityStringId,
            $entityNumericId
        );
        $entriesByKey[$key] = [
            'merge_key' => $key,
            'game_ts' => $gameTs,
            'source' => $source,
            'query_name' => $queryName,
            'query_string_id' => $queryStringId,
            'query_numeric_id' => $queryNumericId,
            'player_involvement' => $playerInvolvement,
            'rule_category' => $ruleCategory,
            'entity_name' => $entityName,
            'entity_string_id' => $entityStringId,
            'entity_numeric_id' => $entityNumericId,
            'state_value' => $stateValue,
            'bool_value' => $boolValue,
        ];
    };

    foreach ($queries as $query) {
        if (!is_array($query)) {
            continue;
        }

        $queryName = trim(strval($query['query_name'] ?? ''));
        $queryStringId = trim(strval($query['query_string_id'] ?? ''));
        $queryNumericId = intval($query['query_numeric_id'] ?? 0);
        $playerInvolvement = boolval($query['player_involvement'] ?? false);

        $insertStateList = static function (
            mixed $listValue,
            string $ruleCategory,
            string $source,
            int $gameTs,
            string $queryName,
            string $queryStringId,
            int $queryNumericId,
            bool $playerInvolvement
        ) use ($normalizeList, $setEntry): bool {
            $list = $normalizeList($listValue);
            foreach ($list as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $entityName = trim(strval($entry['name'] ?? ''));
                $entityStringId = trim(strval($entry['string_id'] ?? ''));
                $entityNumericId = intval($entry['numeric_id'] ?? 0);
                $stateValue = strtolower(trim(strval($entry['state'] ?? '')));
                if (!in_array($stateValue, ['dead', 'alive', 'imprisoned'], true)) {
                    $stateValue = '';
                }
                if (
                    $entityName === '' &&
                    $entityStringId === '' &&
                    $entityNumericId <= 0 &&
                    $stateValue === ''
                ) {
                    continue;
                }
                $setEntry(
                    $gameTs,
                    $source,
                    $queryName,
                    $queryStringId,
                    $queryNumericId,
                    $playerInvolvement,
                    $ruleCategory,
                    $entityName,
                    $entityStringId,
                    $entityNumericId,
                    $stateValue,
                    null
                );
            }
            return true;
        };

        $ok = $insertStateList(
            $query['unique_npcs_are'] ?? [],
            'unique_npcs_are',
            $source,
            $gameTs,
            $queryName,
            $queryStringId,
            $queryNumericId,
            $playerInvolvement
        );
        if (!$ok) {
            return -1;
        }
        $ok = $insertStateList(
            $query['unique_npcs_are_not'] ?? [],
            'unique_npcs_are_not',
            $source,
            $gameTs,
            $queryName,
            $queryStringId,
            $queryNumericId,
            $playerInvolvement
        );
        if (!$ok) {
            return -1;
        }
        $ok = $insertStateList(
            $query['towns'] ?? [],
            'towns',
            $source,
            $gameTs,
            $queryName,
            $queryStringId,
            $queryNumericId,
            $playerInvolvement
        );
        if (!$ok) {
            return -1;
        }

        $insertBoolList = static function (
            mixed $listValue,
            string $ruleCategory,
            string $boolKey,
            string $source,
            int $gameTs,
            string $queryName,
            string $queryStringId,
            int $queryNumericId,
            bool $playerInvolvement
        ) use ($normalizeList, $parseBool, $setEntry): bool {
            $list = $normalizeList($listValue);
            foreach ($list as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $entityName = trim(strval($entry['name'] ?? ''));
                $entityStringId = trim(strval($entry['string_id'] ?? ''));
                $entityNumericId = intval($entry['numeric_id'] ?? 0);
                $boolValue = $parseBool($entry[$boolKey] ?? null);
                if (
                    $entityName === '' &&
                    $entityStringId === '' &&
                    $entityNumericId <= 0 &&
                    $boolValue === null
                ) {
                    continue;
                }
                $setEntry(
                    $gameTs,
                    $source,
                    $queryName,
                    $queryStringId,
                    $queryNumericId,
                    $playerInvolvement,
                    $ruleCategory,
                    $entityName,
                    $entityStringId,
                    $entityNumericId,
                    '',
                    $boolValue
                );
            }
            return true;
        };

        $ok = $insertBoolList(
            $query['is_ally_of'] ?? [],
            'is_ally_of',
            'is_ally',
            $source,
            $gameTs,
            $queryName,
            $queryStringId,
            $queryNumericId,
            $playerInvolvement
        );
        if (!$ok) {
            return -1;
        }
        $ok = $insertBoolList(
            $query['is_enemy_of'] ?? [],
            'is_enemy_of',
            'is_enemy',
            $source,
            $gameTs,
            $queryName,
            $queryStringId,
            $queryNumericId,
            $playerInvolvement
        );
        if (!$ok) {
            return -1;
        }
    }

    $txStarted = false;
    try {
        $beginOk = $db->exec("BEGIN");
        if ($beginOk === false) {
            return -1;
        }
        $txStarted = true;

        foreach ($entriesByKey as $entry) {
            $ok = $db->exec(
                "INSERT INTO world_state (
                    merge_key,
                    game_ts,
                    source,
                    query_name,
                    query_string_id,
                    query_numeric_id,
                    player_involvement,
                    rule_category,
                    entity_name,
                    entity_string_id,
                    entity_numeric_id,
                    state_value,
                    bool_value,
                    created_at
                ) VALUES (
                    $1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, NOW()
                )
                ON CONFLICT (merge_key) DO UPDATE SET
                    game_ts = EXCLUDED.game_ts,
                    source = EXCLUDED.source,
                    query_name = EXCLUDED.query_name,
                    query_string_id = EXCLUDED.query_string_id,
                    query_numeric_id = EXCLUDED.query_numeric_id,
                    player_involvement = EXCLUDED.player_involvement,
                    rule_category = EXCLUDED.rule_category,
                    entity_name = EXCLUDED.entity_name,
                    entity_string_id = EXCLUDED.entity_string_id,
                    entity_numeric_id = EXCLUDED.entity_numeric_id,
                    state_value = EXCLUDED.state_value,
                    bool_value = EXCLUDED.bool_value,
                    created_at = NOW()",
                [
                    strval($entry['merge_key'] ?? ''),
                    intval($entry['game_ts'] ?? 0),
                    strval($entry['source'] ?? ''),
                    strval($entry['query_name'] ?? ''),
                    strval($entry['query_string_id'] ?? ''),
                    intval($entry['query_numeric_id'] ?? 0),
                    boolval($entry['player_involvement'] ?? false),
                    strval($entry['rule_category'] ?? ''),
                    strval($entry['entity_name'] ?? ''),
                    strval($entry['entity_string_id'] ?? ''),
                    intval($entry['entity_numeric_id'] ?? 0),
                    strval($entry['state_value'] ?? ''),
                    $entry['bool_value'] ?? null,
                ]
            );
            if ($ok === false) {
                $db->exec("ROLLBACK");
                return -1;
            }
        }

        $commitOk = $db->exec("COMMIT");
        if ($commitOk === false) {
            $db->exec("ROLLBACK");
            return -1;
        }
    } catch (Throwable $exception) {
        if ($txStarted) {
            $db->exec("ROLLBACK");
        }
        stobeLogException($exception, 'storeWorldStateEntries failed', [
            'source' => $source,
            'game_ts' => $gameTs,
        ]);
        return -1;
    }

    return count($entriesByKey);
}

function stobeShouldSkipDuplicateDialogueEchoRow(
    $db,
    string $eventType,
    string $eventData,
    int $eventLocalTs,
    int $eventGamets
): bool {
    if (!$db) {
        return false;
    }

    $parsedCurrent = parseDialogueEventData($eventData);
    $currentSpeaker = normalizeParticipantNameToken(strval($parsedCurrent['speaker'] ?? ''));
    $currentTarget = normalizeParticipantNameToken(strval($parsedCurrent['target'] ?? ''));
    $currentMessageNorm = stobeNormalizeDialogueForMirrorDedupe(strval($parsedCurrent['message'] ?? ''));
    if ($currentSpeaker === '' || $currentMessageNorm === '') {
        return false;
    }

    $recentRows = $db->fetchAll(
        "SELECT rowid, type, data, localts, gamets
         FROM eventlog
         WHERE type IN ('chat', 'rechat')
           AND localts >= $1
           AND LOWER(data) LIKE LOWER($2)
         ORDER BY rowid DESC
         LIMIT 12",
        [
            $eventLocalTs - 5,
            $currentSpeaker . ':%',
        ]
    );

    if (!is_array($recentRows) || count($recentRows) === 0) {
        return false;
    }

    $currentType = strtolower(trim($eventType));
    foreach ($recentRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowData = strval($row['data'] ?? '');
        if ($rowData === '') {
            continue;
        }

        $parsedRow = parseDialogueEventData($rowData);
        $rowSpeaker = normalizeParticipantNameToken(strval($parsedRow['speaker'] ?? ''));
        if ($rowSpeaker === '' || strcasecmp($rowSpeaker, $currentSpeaker) !== 0) {
            continue;
        }

        $rowMessageNorm = stobeNormalizeDialogueForMirrorDedupe(strval($parsedRow['message'] ?? ''));
        if ($rowMessageNorm === '' || $rowMessageNorm !== $currentMessageNorm) {
            continue;
        }

        $rowTarget = normalizeParticipantNameToken(strval($parsedRow['target'] ?? ''));
        $targetCompatible = false;
        $targetReason = 'target_exact';
        if ($currentTarget === '' || $rowTarget === '') {
            $targetCompatible = true;
            $targetReason = 'target_partial';
        } elseif (strcasecmp($currentTarget, $rowTarget) === 0) {
            $targetCompatible = true;
            $targetReason = 'target_exact';
        }

        $rowType = strtolower(trim(strval($row['type'] ?? '')));
        $rowGamets = intval($row['gamets'] ?? 0);
        $gametsDelta = abs($eventGamets - $rowGamets);
        if ($eventGamets > 0 && $rowGamets > 0 && $gametsDelta > 120) {
            // Same words spoken by same NPC much later is likely intentional.
            continue;
        }
        $localTsDelta = abs($eventLocalTs - intval($row['localts'] ?? 0));

        // Accept strict target mismatch dedupe only for very short windows to catch
        // known duplicate chat echo races where "talking to" differs but line is identical.
        if (!$targetCompatible) {
            if ($localTsDelta > 4 || ($eventGamets > 0 && $rowGamets > 0 && $gametsDelta > 60)) {
                continue;
            }
            $targetReason = 'target_mismatch_short_window';
            $targetCompatible = true;
        }

        stobeLogDebug('Skipped duplicate dialogue echo row', [
            'incoming_type' => $currentType,
            'incoming_speaker' => $currentSpeaker,
            'incoming_target' => $currentTarget,
            'incoming_message' => $currentMessageNorm,
            'matched_rowid' => intval($row['rowid'] ?? 0),
            'matched_type' => $rowType,
            'matched_target' => $rowTarget,
            'matched_localts' => intval($row['localts'] ?? 0),
            'incoming_gamets' => $eventGamets,
            'matched_gamets' => $rowGamets,
            'gamets_delta' => $gametsDelta,
            'localts_delta' => $localTsDelta,
            'target_match' => $targetReason,
        ]);
        return true;
    }

    return false;
}

function stobeEnsureFactionRelationTables(): bool {
    static $ensured = false;
    if ($ensured) {
        return true;
    }

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        return false;
    }

    try {
        $ok = $db->exec(
            "CREATE TABLE IF NOT EXISTS faction_relation_state (
                id BIGSERIAL PRIMARY KEY,
                merge_key TEXT NOT NULL UNIQUE,
                source_name TEXT NOT NULL DEFAULT '',
                source_string_id TEXT NOT NULL DEFAULT '',
                source_numeric_id INT NOT NULL DEFAULT 0,
                target_name TEXT NOT NULL DEFAULT '',
                target_string_id TEXT NOT NULL DEFAULT '',
                target_numeric_id INT NOT NULL DEFAULT 0,
                relation DOUBLE PRECISION NOT NULL DEFAULT 0,
                alliance BOOLEAN NOT NULL DEFAULT FALSE,
                war BOOLEAN NOT NULL DEFAULT FALSE,
                coexists BOOLEAN NOT NULL DEFAULT FALSE,
                game_ts BIGINT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )"
        );
        if ($ok === false) {
            return false;
        }

        $db->exec("CREATE INDEX IF NOT EXISTS idx_faction_relation_state_game_ts ON faction_relation_state (game_ts DESC, id DESC)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_faction_relation_state_source_lower ON faction_relation_state (LOWER(source_name))");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_faction_relation_state_target_lower ON faction_relation_state (LOWER(target_name))");

        $ensured = true;
        return true;
    } catch (Throwable $exception) {
        stobeLogException($exception, 'Failed to ensure faction relation tables');
        return false;
    }
}

function stobeFactionRelationStableToken(string $stringId, int $numericId, string $name): string {
    $sid = trim($stringId);
    if ($sid !== '') {
        return 'sid:' . strtolower($sid);
    }
    if ($numericId > 0) {
        return 'id:' . strval($numericId);
    }
    $trimmedName = trim($name);
    if ($trimmedName !== '') {
        return 'name:' . strtolower($trimmedName);
    }
    return '';
}

function stobeFactionRelationMergeKey(
    string $sourceStringId,
    int $sourceNumericId,
    string $sourceName,
    string $targetStringId,
    int $targetNumericId,
    string $targetName
): string {
    $sourceToken = stobeFactionRelationStableToken($sourceStringId, $sourceNumericId, $sourceName);
    $targetToken = stobeFactionRelationStableToken($targetStringId, $targetNumericId, $targetName);
    if ($sourceToken === '' || $targetToken === '') {
        return '';
    }
    return $sourceToken . '->' . $targetToken;
}

/**
 * Persist faction relation deltas/snapshots from Stobe.dll.
 *
 * Returns:
 * [
 *   'changed' => int,
 *   'unchanged' => int,
 *   'removed' => int,
 * ]
 */
function storeFactionRelationEntries(array $payload): array|false {
    if (!stobeEnsureFactionRelationTables()) {
        return false;
    }

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        return false;
    }

    $gameTs = intval($payload['game_ts'] ?? 0);
    if ($gameTs < 0) {
        $gameTs = 0;
    }

    $parseBool = static function (mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return intval($value) !== 0;
        }
        $text = strtolower(trim(strval($value)));
        if ($text === '') {
            return false;
        }
        return in_array($text, ['1', 'true', 'yes', 'on'], true);
    };

    $normalizeList = static function (mixed $value): array {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    };

    $normalizeRelationEntry = static function (mixed $entry) use ($parseBool): ?array {
        if (!is_array($entry)) {
            return null;
        }

        $sourceName = trim(strval($entry['source_name'] ?? ''));
        $sourceStringId = trim(strval($entry['source_string_id'] ?? ''));
        $sourceNumericId = intval($entry['source_numeric_id'] ?? 0);
        $targetName = trim(strval($entry['target_name'] ?? ''));
        $targetStringId = trim(strval($entry['target_string_id'] ?? ''));
        $targetNumericId = intval($entry['target_numeric_id'] ?? 0);

        $mergeKey = trim(strval($entry['merge_key'] ?? ''));
        if ($mergeKey === '') {
            $mergeKey = stobeFactionRelationMergeKey(
                $sourceStringId,
                $sourceNumericId,
                $sourceName,
                $targetStringId,
                $targetNumericId,
                $targetName
            );
        }
        if ($mergeKey === '') {
            return null;
        }

        $relationRaw = $entry['relation'] ?? 0;
        if (!is_numeric($relationRaw)) {
            $relationRaw = str_replace(',', '.', strval($relationRaw));
        }
        $relation = is_numeric($relationRaw) ? floatval($relationRaw) : 0.0;
        if ($relation > 100.0) {
            $relation = 100.0;
        } elseif ($relation < -100.0) {
            $relation = -100.0;
        }

        return [
            'merge_key' => $mergeKey,
            'source_name' => $sourceName,
            'source_string_id' => $sourceStringId,
            'source_numeric_id' => $sourceNumericId,
            'target_name' => $targetName,
            'target_string_id' => $targetStringId,
            'target_numeric_id' => $targetNumericId,
            'relation' => $relation,
            'alliance' => $parseBool($entry['alliance'] ?? false),
            'war' => $parseBool($entry['war'] ?? false),
            'coexists' => $parseBool($entry['coexists'] ?? false),
        ];
    };

    $relationList = $normalizeList($payload['relations'] ?? []);
    $removedList = $normalizeList($payload['removed'] ?? []);

    $relationsByKey = [];
    foreach ($relationList as $entry) {
        $normalized = $normalizeRelationEntry($entry);
        if (!is_array($normalized)) {
            continue;
        }
        $relationsByKey[strval($normalized['merge_key'])] = $normalized;
    }

    $removedKeys = [];
    foreach ($removedList as $entry) {
        $mergeKey = '';
        if (is_string($entry)) {
            $mergeKey = trim($entry);
        } elseif (is_array($entry)) {
            $mergeKey = trim(strval($entry['merge_key'] ?? ''));
            if ($mergeKey === '') {
                $mergeKey = stobeFactionRelationMergeKey(
                    trim(strval($entry['source_string_id'] ?? '')),
                    intval($entry['source_numeric_id'] ?? 0),
                    trim(strval($entry['source_name'] ?? '')),
                    trim(strval($entry['target_string_id'] ?? '')),
                    intval($entry['target_numeric_id'] ?? 0),
                    trim(strval($entry['target_name'] ?? ''))
                );
            }
        }
        if ($mergeKey !== '') {
            $removedKeys[$mergeKey] = true;
        }
    }

    foreach ($removedKeys as $mergeKey => $_unused) {
        unset($relationsByKey[$mergeKey]);
    }

    $changed = 0;
    $unchanged = 0;
    $removed = 0;

    $txStarted = false;
    try {
        $beginOk = $db->exec("BEGIN");
        if ($beginOk === false) {
            return false;
        }
        $txStarted = true;

        foreach ($removedKeys as $mergeKey => $_unused) {
            $existing = $db->fetchOne(
                "SELECT *
                 FROM faction_relation_state
                 WHERE merge_key = $1
                 LIMIT 1",
                [$mergeKey]
            );
            if (!is_array($existing)) {
                continue;
            }

            $ok = $db->exec(
                "DELETE FROM faction_relation_state
                 WHERE merge_key = $1",
                [$mergeKey]
            );
            if ($ok === false) {
                $db->exec("ROLLBACK");
                return false;
            }
            $removed++;
        }

        foreach ($relationsByKey as $entry) {
            $mergeKey = strval($entry['merge_key'] ?? '');
            if ($mergeKey === '') {
                continue;
            }

            $existing = $db->fetchOne(
                "SELECT *
                 FROM faction_relation_state
                 WHERE merge_key = $1
                 LIMIT 1",
                [$mergeKey]
            );

            $isChanged = !is_array($existing);
            if (is_array($existing)) {
                $prevRelation = floatval($existing['relation'] ?? 0);
                $nextRelation = floatval($entry['relation'] ?? 0);
                if (abs($prevRelation - $nextRelation) > 0.001) {
                    $isChanged = true;
                } elseif (boolval($existing['alliance'] ?? false) !== boolval($entry['alliance'] ?? false)) {
                    $isChanged = true;
                } elseif (boolval($existing['war'] ?? false) !== boolval($entry['war'] ?? false)) {
                    $isChanged = true;
                } elseif (boolval($existing['coexists'] ?? false) !== boolval($entry['coexists'] ?? false)) {
                    $isChanged = true;
                } elseif (trim(strval($existing['source_name'] ?? '')) !== trim(strval($entry['source_name'] ?? ''))) {
                    $isChanged = true;
                } elseif (trim(strval($existing['source_string_id'] ?? '')) !== trim(strval($entry['source_string_id'] ?? ''))) {
                    $isChanged = true;
                } elseif (intval($existing['source_numeric_id'] ?? 0) !== intval($entry['source_numeric_id'] ?? 0)) {
                    $isChanged = true;
                } elseif (trim(strval($existing['target_name'] ?? '')) !== trim(strval($entry['target_name'] ?? ''))) {
                    $isChanged = true;
                } elseif (trim(strval($existing['target_string_id'] ?? '')) !== trim(strval($entry['target_string_id'] ?? ''))) {
                    $isChanged = true;
                } elseif (intval($existing['target_numeric_id'] ?? 0) !== intval($entry['target_numeric_id'] ?? 0)) {
                    $isChanged = true;
                }
            }

            $ok = $db->exec(
                "INSERT INTO faction_relation_state (
                    merge_key,
                    source_name,
                    source_string_id,
                    source_numeric_id,
                    target_name,
                    target_string_id,
                    target_numeric_id,
                    relation,
                    alliance,
                    war,
                    coexists,
                    game_ts,
                    created_at,
                    updated_at
                 ) VALUES (
                    $1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, NOW(), NOW()
                 )
                 ON CONFLICT (merge_key) DO UPDATE SET
                    source_name = EXCLUDED.source_name,
                    source_string_id = EXCLUDED.source_string_id,
                    source_numeric_id = EXCLUDED.source_numeric_id,
                    target_name = EXCLUDED.target_name,
                    target_string_id = EXCLUDED.target_string_id,
                    target_numeric_id = EXCLUDED.target_numeric_id,
                    relation = EXCLUDED.relation,
                    alliance = EXCLUDED.alliance,
                    war = EXCLUDED.war,
                    coexists = EXCLUDED.coexists,
                    game_ts = EXCLUDED.game_ts,
                    updated_at = NOW()",
                [
                    $mergeKey,
                    strval($entry['source_name'] ?? ''),
                    strval($entry['source_string_id'] ?? ''),
                    intval($entry['source_numeric_id'] ?? 0),
                    strval($entry['target_name'] ?? ''),
                    strval($entry['target_string_id'] ?? ''),
                    intval($entry['target_numeric_id'] ?? 0),
                    floatval($entry['relation'] ?? 0),
                    boolval($entry['alliance'] ?? false),
                    boolval($entry['war'] ?? false),
                    boolval($entry['coexists'] ?? false),
                    $gameTs,
                ]
            );
            if ($ok === false) {
                $db->exec("ROLLBACK");
                return false;
            }

            if (!$isChanged) {
                $unchanged++;
                continue;
            }

            $changed++;
        }

        $commitOk = $db->exec("COMMIT");
        if ($commitOk === false) {
            $db->exec("ROLLBACK");
            return false;
        }
    } catch (Throwable $exception) {
        if ($txStarted) {
            $db->exec("ROLLBACK");
        }
        stobeLogException($exception, 'storeFactionRelationEntries failed', [
            'game_ts' => $gameTs,
        ]);
        return false;
    }

    return [
        'changed' => $changed,
        'unchanged' => $unchanged,
        'removed' => $removed,
    ];
}

function getNpcData(string $name): array|false {
    $db = $GLOBALS["db"];
    $normalizedName = normalizeParticipantNameToken($name);
    if ($normalizedName === '') {
        return false;
    }

    $exact = $db->fetchOne(
        "SELECT *
         FROM core_npc
         WHERE LOWER(name) = LOWER($1)
         ORDER BY gamets_last_updated DESC, updated_at DESC
         LIMIT 1",
        [$normalizedName]
    );
    if ($exact) {
        $metadata = normalizeCoreNpcMetadata($exact['metadata'] ?? '{}');
        $exactStorageId = normalizeStorageIdToken(strval($metadata['storage_id'] ?? ''));
        $exactRace = strtolower(trim(strval($exact['race'] ?? '')));
        $exactFaction = trim(strval($exact['faction'] ?? ''));
        $exactGender = trim(strval($exact['gender'] ?? ''));
        $exactEquipment = trim(strval($exact['equipment'] ?? ''));
        $exactSkills = trim(strval($exact['skills'] ?? ''));
        $exactLooksPlaceholder =
            $exactStorageId === '' &&
            ($exactRace === '' || $exactRace === 'unknown') &&
            $exactFaction === '' &&
            $exactGender === '' &&
            $exactEquipment === '' &&
            $exactSkills === '';

        if ($exactLooksPlaceholder) {
            $preferred = $db->fetchOne(
                "SELECT *
                 FROM core_npc
                 WHERE LOWER(COALESCE(original_name, '')) = LOWER($1)
                   AND COALESCE(metadata->>'storage_id', '') <> ''
                 ORDER BY updated_at DESC, gamets_last_updated DESC
                 LIMIT 1",
                [$normalizedName]
            );
            if ($preferred) {
                return $preferred;
            }
        }
        return $exact;
    }

    return $db->fetchOne(
        "SELECT *
         FROM core_npc
         WHERE LOWER(COALESCE(original_name, '')) = LOWER($1)
         ORDER BY
           CASE WHEN COALESCE(metadata->>'storage_id', '') <> '' THEN 0 ELSE 1 END,
           gamets_last_updated DESC,
           updated_at DESC
         LIMIT 1",
        [$normalizedName]
    );
}

function getApiBadgeById(int $id): array|false {
    $db = $GLOBALS["db"];
    return $db->fetchOne(
        "SELECT * FROM core_api_badge WHERE id = $1",
        [$id]
    );
}

function getApiBadgeByLabel(string $label): array|false {
    $db = $GLOBALS["db"];
    return $db->fetchOne(
        "SELECT * FROM core_api_badge WHERE LOWER(label) = LOWER($1) LIMIT 1",
        [$label]
    );
}

function getAllApiBadges(): array {
    $db = $GLOBALS["db"];
    return $db->fetchAll(
        "SELECT * FROM core_api_badge ORDER BY LOWER(label) ASC"
    );
}

function saveApiBadge(array $fields): int {
    $db = $GLOBALS["db"];
    $id = intval($fields['id'] ?? 0);
    $label = trim(strval($fields['label'] ?? ''));
    $apiKey = strval($fields['api_key'] ?? '');

    if ($label === '') {
        return 0;
    }

    if ($id > 0) {
        $result = $db->fetchOne(
            "UPDATE core_api_badge
             SET label = $1,
                 api_key = $2
             WHERE id = $3
             RETURNING id",
            [$label, $apiKey, $id]
        );
        return $result ? intval($result['id']) : 0;
    }

    $existing = getApiBadgeByLabel($label);
    if ($existing) {
        $existingId = intval($existing['id'] ?? 0);
        if ($existingId > 0) {
            $result = $db->fetchOne(
                "UPDATE core_api_badge
                 SET label = $1,
                     api_key = $2
                 WHERE id = $3
                 RETURNING id",
                [$label, $apiKey, $existingId]
            );
            return $result ? intval($result['id']) : 0;
        }
    }

    $result = $db->fetchOne(
        "INSERT INTO core_api_badge (label, api_key)
         VALUES ($1, $2)
         RETURNING id",
        [$label, $apiKey]
    );
    return $result ? intval($result['id']) : 0;
}

function deleteApiBadge(int $id): void {
    $db = $GLOBALS["db"];
    $db->exec("DELETE FROM core_api_badge WHERE id = $1", [$id]);
}

function stobeSortArrayRecursive(mixed $value): mixed {
    if (!is_array($value)) {
        return $value;
    }

    $isSequential = array_keys($value) === range(0, count($value) - 1);
    if ($isSequential) {
        foreach ($value as $index => $entry) {
            $value[$index] = stobeSortArrayRecursive($entry);
        }
        return $value;
    }

    ksort($value);
    foreach ($value as $key => $entry) {
        $value[$key] = stobeSortArrayRecursive($entry);
    }
    return $value;
}

function stobeRelationshipExtendedDataKeys(): array {
    return [
        'relationships',
        'relationships_analyzed',
        'relationships_inferred',
        'relationships_last_eval',
        'relationships_model',
        'relationships_updated',
    ];
}

function stobePersistentNpcSettingExtendedDataKeys(): array {
    return ['individual_memory_enabled'];
}

function stobeRelationshipTimelineState(mixed $extendedData): ?array {
    if (is_string($extendedData) && trim($extendedData) !== '') {
        $extendedData = json_decode($extendedData, true);
    }
    if (!is_array($extendedData)) {
        return null;
    }

    $state = [];
    foreach (['relationships', 'relationships_analyzed', 'relationships_inferred', 'relationships_model'] as $key) {
        if (array_key_exists($key, $extendedData)) {
            $state[$key] = $extendedData[$key];
        }
    }
    return $state;
}

// Build an atomic JSONB update that keeps selected keys from the current stored object.
function stobeExtendedDataPreservingKeysSql(
    string $incomingExpression,
    string $currentExpression,
    array $preservedKeys
): string {
    $incomingWithoutPreservedKeys = '(' . $incomingExpression . ')';
    foreach ($preservedKeys as $key) {
        $incomingWithoutPreservedKeys .= " - '" . $key . "'";
    }

    $preservedPairs = [];
    foreach ($preservedKeys as $key) {
        $preservedPairs[] = "'" . $key . "', (" . $currentExpression . ") -> '" . $key . "'";
    }

    return '(' . $incomingWithoutPreservedKeys . ') || jsonb_strip_nulls(jsonb_build_object('
        . implode(', ', $preservedPairs)
        . '))';
}

// Keep relationship state and persistent NPC settings out of generic whole-object replacements.
function stobeRelationshipPreservingExtendedDataSql(string $incomingExpression, string $currentExpression): string {
    return stobeExtendedDataPreservingKeysSql(
        $incomingExpression,
        $currentExpression,
        array_merge(stobeRelationshipExtendedDataKeys(), stobePersistentNpcSettingExtendedDataKeys())
    );
}

function stobeIndividualMemoryPreservingExtendedDataSql(string $incomingExpression, string $currentExpression): string {
    return stobeExtendedDataPreservingKeysSql(
        $incomingExpression,
        $currentExpression,
        stobePersistentNpcSettingExtendedDataKeys()
    );
}

function stobeAcquireNpcRelationshipLock(int $npcId): ?int {
    $db = $GLOBALS['db'] ?? null;
    if (!$db || $npcId <= 0) {
        return null;
    }

    $lockId = 1001000000 + $npcId;
    $locked = $db->fetchOne('SELECT pg_advisory_lock($1) AS locked', [$lockId]);
    if (!is_array($locked)) {
        throw new RuntimeException('failed to acquire NPC relationship lock');
    }
    return $lockId;
}

function stobeReleaseNpcRelationshipLock(?int $lockId): void {
    if ($lockId === null || !isset($GLOBALS['db'])) {
        return;
    }
    $GLOBALS['db']->exec('SELECT pg_advisory_unlock($1)', [$lockId]);
}

function stobeRunWithRelationshipExtendedDataWrite(callable $callback, int $npcId = 0, bool $allowCustomInfo = false): mixed {
    $hadPrevious = array_key_exists('STOBE_ALLOW_RELATIONSHIP_EXTENDED_DATA_WRITE', $GLOBALS);
    $previous = $hadPrevious ? $GLOBALS['STOBE_ALLOW_RELATIONSHIP_EXTENDED_DATA_WRITE'] : null;
    $hadCustomInfo = array_key_exists('STOBE_ALLOW_RELATIONSHIP_CUSTOM_INFO_WRITE', $GLOBALS);
    $previousCustomInfo = $GLOBALS['STOBE_ALLOW_RELATIONSHIP_CUSTOM_INFO_WRITE'] ?? null;
    $lockId = stobeAcquireNpcRelationshipLock($npcId);
    $GLOBALS['STOBE_ALLOW_RELATIONSHIP_EXTENDED_DATA_WRITE'] = true;
    $GLOBALS['STOBE_ALLOW_RELATIONSHIP_CUSTOM_INFO_WRITE'] = $allowCustomInfo;

    try {
        return $callback();
    } finally {
        if ($hadPrevious) {
            $GLOBALS['STOBE_ALLOW_RELATIONSHIP_EXTENDED_DATA_WRITE'] = $previous;
        } else {
            unset($GLOBALS['STOBE_ALLOW_RELATIONSHIP_EXTENDED_DATA_WRITE']);
        }
        if ($hadCustomInfo) {
            $GLOBALS['STOBE_ALLOW_RELATIONSHIP_CUSTOM_INFO_WRITE'] = $previousCustomInfo;
        } else {
            unset($GLOBALS['STOBE_ALLOW_RELATIONSHIP_CUSTOM_INFO_WRITE']);
        }
        stobeReleaseNpcRelationshipLock($lockId);
    }
}

// Anchor relationship changes to Kenshi time and retain every distinct durable state for rollback.
function stobeRelationshipTimelineStamp(int $npcId): bool {
    try {
        $db = $GLOBALS['db'] ?? null;
        if (!$db || $npcId <= 0) {
            return false;
        }

        $gamets = 0;
        if (isset($GLOBALS['gameRequest'][2]) && is_numeric($GLOBALS['gameRequest'][2])) {
            $gamets = max(0, intval($GLOBALS['gameRequest'][2]));
        }
        if ($gamets <= 0 && function_exists('getConfOpt')) {
            $gamets = max(0, intval(getConfOpt('PLAYTHROUGH_LAST_SEEN_GAMETS', '0')));
        }
        if ($gamets <= 0) {
            $latest = $db->fetchOne('SELECT COALESCE(MAX(gamets), 0) AS gamets FROM eventlog');
            $gamets = max(0, intval($latest['gamets'] ?? 0));
        }

        if ($gamets > 0) {
            $updated = $db->exec(
                'UPDATE core_npc SET gamets_last_updated = $1, updated_at = NOW() WHERE id = $2',
                [$gamets, $npcId]
            );
            if ($updated === false) {
                throw new RuntimeException('failed to stamp game timestamp');
            }
        }

        $current = stobeFetchNpcRowForHistoryById($npcId);
        if (!$current) {
            throw new RuntimeException('NPC row not found after relationship write');
        }
        $currentState = stobeRelationshipTimelineState($current['extended_data'] ?? null);
        if ($currentState === null) {
            throw new RuntimeException('live extended_data is not valid relationship JSON');
        }

        $params = [$npcId];
        $eligibleClause = '';
        if ($gamets > 0) {
            $params[] = $gamets;
            $eligibleClause = 'AND gamets_last_updated <= $2';
        }
        $history = $db->fetchOne(
            "SELECT extended_data
             FROM core_npc_master_history
             WHERE npc_id = $1
               {$eligibleClause}
             ORDER BY gamets_last_updated DESC,
                      CASE WHEN snapshot_reason = 'relationship' THEN 1 ELSE 0 END DESC,
                      history_id DESC
             LIMIT 1",
            $params
        );
        $historyState = $history
            ? stobeRelationshipTimelineState($history['extended_data'] ?? null)
            : null;
        if ($historyState !== null && $currentState == $historyState) {
            return true;
        }

        if (!stobeInsertNpcHistorySnapshotFromRow($current, 'relationship')) {
            throw new RuntimeException('failed to store relationship history snapshot');
        }
        return true;
    } catch (Throwable $exception) {
        if (function_exists('stobeLogWarn')) {
            stobeLogWarn('RELATIONSHIP: timeline snapshot failed', [
                'npc_id' => $npcId,
                'error' => $exception->getMessage(),
            ]);
        } else {
            error_log('[REL] Timeline snapshot failed for npc_id ' . strval($npcId) . ': ' . $exception->getMessage());
        }
        return false;
    }
}

function stobeNormalizeJsonArrayValue(mixed $value): array {
    if (is_array($value)) {
        return $value;
    }
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return [];
}

function stobeBuildNpcHistoryCanonicalFromRow(array $row): array {
    $bounty = stobeNormalizeBountyPayload($row['bounty'] ?? '{}');
    $dynamicProfileEnabled = null;
    if (array_key_exists('dynamic_profile', $row) && $row['dynamic_profile'] !== null && $row['dynamic_profile'] !== '') {
        $dynamicProfileEnabled = coerceBoolean($row['dynamic_profile']);
    }

    return [
        'name' => trim(strval($row['name'] ?? '')),
        'original_name' => trim(strval($row['original_name'] ?? '')),
        'npc_favorite' => coerceBoolean($row['npc_favorite'] ?? false),
        'lock_profile' => coerceBoolean($row['lock_profile'] ?? false),
        'prompt_head' => strval($row['prompt_head'] ?? ''),
        'personality' => strval($row['personality'] ?? ''),
        'backstory' => strval($row['backstory'] ?? ''),
        'emote_moods' => strval($row['emote_moods'] ?? ''),
        'occupation' => strval($row['occupation'] ?? ''),
        'appearance' => strval($row['appearance'] ?? ''),
        'equipment' => strval($row['equipment'] ?? ''),
        'inventory' => strval($row['inventory'] ?? ''),
        'skills' => strval($row['skills'] ?? ''),
        'speechstyle' => strval($row['speechstyle'] ?? ''),
        'goals' => strval($row['goals'] ?? ''),
        'relationships' => strval($row['relationships'] ?? ''),
        'relationship_state' => stobeSortArrayRecursive(stobeRelationshipTimelineState($row['extended_data'] ?? '{}') ?? []),
        'plugin_extended_data' => stobeSortArrayRecursive(json_decode($row['plugin_extended_data'] ?? '{}', true, 512, JSON_THROW_ON_ERROR)),
        'voiceid' => strval($row['voiceid'] ?? ''),
        'race' => strval($row['race'] ?? ''),
        'faction' => strval($row['faction'] ?? ''),
        'gender' => strval($row['gender'] ?? ''),
        'profile_id' => intval($row['profile_id'] ?? 0),
        'dynamic_profile' => $dynamicProfileEnabled,
        'md5' => strval($row['md5'] ?? ''),
        'bounty' => stobeSortArrayRecursive($bounty),
        'tags' => strval($row['tags'] ?? ''),
        'is_animal' => coerceBoolean($row['is_animal'] ?? false),
        'is_slave' => coerceBoolean($row['is_slave'] ?? false),
        'world_knowledge_tags' => strval($row['world_knowledge_tags'] ?? ''),
    ];
}

function stobeGetNpcHistoryChangedFields(array $beforeRow, array $afterRow): array {
    $beforeCanonical = stobeBuildNpcHistoryCanonicalFromRow($beforeRow);
    $afterCanonical = stobeBuildNpcHistoryCanonicalFromRow($afterRow);
    $changedFields = [];

    foreach ($beforeCanonical as $key => $beforeValue) {
        $afterValue = $afterCanonical[$key] ?? null;
        if ($beforeValue !== $afterValue) {
            $changedFields[] = $key;
        }
    }

    return $changedFields;
}

function stobeBuildNpcHistoryHashFromRow(array $row): string {
    $canonical = stobeBuildNpcHistoryCanonicalFromRow($row);

    $encoded = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || $encoded === '') {
        return '';
    }
    return md5($encoded);
}

function stobeFetchNpcRowForHistoryById(int $id): array|false {
    if ($id <= 0) {
        return false;
    }
    $db = $GLOBALS["db"];
    return $db->fetchOne(
        "SELECT * FROM core_npc WHERE id = $1 LIMIT 1",
        [$id]
    );
}

function stobeFetchNpcRowForHistoryByName(string $name): array|false {
    $safeName = trim($name);
    if ($safeName === '') {
        return false;
    }
    $db = $GLOBALS["db"];
    return $db->fetchOne(
        "SELECT * FROM core_npc WHERE LOWER(name) = LOWER($1) LIMIT 1",
        [$safeName]
    );
}

function stobePruneNpcHistorySnapshots(int $npcId, int $keep = 50): void {
    if ($npcId <= 0) {
        return;
    }
    if ($keep < 1) {
        $keep = 1;
    }

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        return;
    }

    $db->exec(
        "DELETE FROM core_npc_master_history
         WHERE history_id IN (
            SELECT history_id
            FROM core_npc_master_history
            WHERE npc_id = $1
            ORDER BY history_id DESC
            OFFSET $2
         )",
        [$npcId, $keep]
    );
}

function stobeBuildNpcHistorySnapshotPayloadFromRow(array $row, string $reason = 'snapshot'): array|false {
    $npcId = intval($row['id'] ?? 0);
    $name = trim(strval($row['name'] ?? ''));
    if ($npcId <= 0 || $name === '') {
        return false;
    }

    $snapshotHash = stobeBuildNpcHistoryHashFromRow($row);
    if ($snapshotHash === '') {
        return false;
    }

    $safeReason = trim($reason);
    if ($safeReason === '') {
        $safeReason = 'snapshot';
    }

    $metadata = normalizeJsonString(normalizeCoreNpcMetadata($row['metadata'] ?? '{}'));
    $extendedData = normalizeJsonString(normalizeCoreNpcExtendedData($row['extended_data'] ?? '{}'));
    $limbs = normalizeJsonString(stobeNormalizeJsonArrayValue($row['limbs'] ?? '{}'));
    $bounty = stobeNormalizeBountyJsonString($row['bounty'] ?? '{}');

    $dynamicProfileEnabled = false;
    $metadataArray = normalizeCoreNpcMetadata($metadata);
    if (array_key_exists('DYNAMIC_PROFILE_ENABLED', $metadataArray)) {
        $dynamicProfileEnabled = coerceBoolean($metadataArray['DYNAMIC_PROFILE_ENABLED']);
    } elseif (array_key_exists('dynamic_profile', $row) && $row['dynamic_profile'] !== null && $row['dynamic_profile'] !== '') {
        $dynamicProfileEnabled = coerceBoolean($row['dynamic_profile']);
    }

    return [
        'npc_id' => $npcId,
        'name' => $name,
        'original_name' => strval($row['original_name'] ?? ''),
        'npc_favorite' => coerceBoolean($row['npc_favorite'] ?? false),
        'lock_profile' => coerceBoolean($row['lock_profile'] ?? false),
        'prompt_head' => strval($row['prompt_head'] ?? ''),
        'personality' => strval($row['personality'] ?? ''),
        'backstory' => strval($row['backstory'] ?? ''),
        'emote_moods' => strval($row['emote_moods'] ?? ''),
        'occupation' => strval($row['occupation'] ?? ''),
        'appearance' => strval($row['appearance'] ?? ''),
        'equipment' => strval($row['equipment'] ?? ''),
        'inventory' => strval($row['inventory'] ?? ''),
        'skills' => strval($row['skills'] ?? ''),
        'speechstyle' => strval($row['speechstyle'] ?? ''),
        'goals' => strval($row['goals'] ?? ''),
        'relationships' => strval($row['relationships'] ?? ''),
        'voiceid' => strval($row['voiceid'] ?? ''),
        'metadata' => $metadata,
        'race' => strval($row['race'] ?? ''),
        'faction' => strval($row['faction'] ?? ''),
        'gender' => strval($row['gender'] ?? ''),
        'profile_id' => ($row['profile_id'] ?? '') === '' ? null : intval($row['profile_id']),
        'dynamic_profile' => $dynamicProfileEnabled,
        'extended_data' => $extendedData,
        'plugin_extended_data' => $row['plugin_extended_data'] ?? '{}',
        'md5' => strval($row['md5'] ?? ''),
        'gamets_last_updated' => intval($row['gamets_last_updated'] ?? 0),
        'bounty' => $bounty,
        'limbs' => $limbs,
        'blood' => strval($row['blood'] ?? '0/0'),
        'hunger' => strval($row['hunger'] ?? '300/300'),
        'tags' => strval($row['tags'] ?? ''),
        'is_animal' => coerceBoolean($row['is_animal'] ?? false),
        'is_slave' => coerceBoolean($row['is_slave'] ?? false),
        'world_knowledge_tags' => strval($row['world_knowledge_tags'] ?? ''),
        'snapshot_reason' => $safeReason,
        'snapshot_hash' => $snapshotHash,
        'source_created_at' => ($row['created_at'] ?? '') === '' ? null : strval($row['created_at']),
        'source_updated_at' => ($row['updated_at'] ?? '') === '' ? null : strval($row['updated_at']),
    ];
}

function stobeInsertNpcHistorySnapshotFromRow(array $row, string $reason = 'snapshot'): bool {
    $payload = stobeBuildNpcHistorySnapshotPayloadFromRow($row, $reason);
    if (!is_array($payload)) {
        return false;
    }

    $db = $GLOBALS["db"];
    $npcId = intval($payload['npc_id'] ?? 0);
    $name = trim(strval($payload['name'] ?? ''));
    $snapshotHash = trim(strval($payload['snapshot_hash'] ?? ''));
    $safeReason = trim(strval($payload['snapshot_reason'] ?? 'snapshot'));
    if ($safeReason === '') {
        $safeReason = 'snapshot';
    }

    $latest = $db->fetchOne(
        "SELECT snapshot_hash, created
         FROM core_npc_master_history
         WHERE npc_id = $1
         ORDER BY history_id DESC
         LIMIT 1",
        [$npcId]
    );
    $latestHash = trim(strval($latest['snapshot_hash'] ?? ''));
    if ($latestHash !== '' && hash_equals($latestHash, $snapshotHash)) {
        return false;
    }

    $cooldownBypassReason = (
        str_starts_with($safeReason, 'rollback_')
        || $safeReason === 'delete_before'
        || $safeReason === 'relationship'
    );
    if (!$cooldownBypassReason) {
        $latestCreatedUnix = strtotime(strval($latest['created'] ?? ''));
        if ($latestCreatedUnix !== false) {
            $cooldownSeconds = 600; // 10 real-world minutes
            $elapsedSeconds = max(0, time() - intval($latestCreatedUnix));
            if ($elapsedSeconds < $cooldownSeconds) {
                stobeLogDebug('NPC history snapshot skipped by cooldown', [
                    'npc_id' => $npcId,
                    'name' => $name,
                    'reason' => $safeReason,
                    'elapsed_seconds' => $elapsedSeconds,
                    'cooldown_seconds' => $cooldownSeconds,
                ]);
                return false;
            }
        }
    }

    $result = $db->fetchOne(
        "INSERT INTO core_npc_master_history (
            npc_id, name, original_name, npc_favorite, lock_profile,
            prompt_head, personality, backstory, emote_moods, occupation,
            appearance, equipment, inventory, skills, speechstyle, goals, relationships,
            voiceid, metadata, race, faction, gender, profile_id, dynamic_profile,
            extended_data, md5, gamets_last_updated, bounty, limbs, blood,
            hunger, tags, is_animal, is_slave, world_knowledge_tags, snapshot_reason,
            snapshot_hash, source_created_at, source_updated_at, plugin_extended_data, created
        ) VALUES (
            $1, $2, $3, $4, $5,
            $6, $7, $8, $9, $10,
            $11, $12, $13, $14, $15, $16, $17,
            $18, $19::jsonb, $20, $21, $22, $23, $24,
            $25::jsonb, $26, $27, $28::jsonb, $29::jsonb, $30,
            $31, $32, $33, $34, $35, $36,
            $37, $38, $39, $40::jsonb, NOW()
        )
        RETURNING history_id",
        [
            $payload['npc_id'],
            $payload['name'],
            $payload['original_name'],
            $payload['npc_favorite'],
            $payload['lock_profile'],
            $payload['prompt_head'],
            $payload['personality'],
            $payload['backstory'],
            $payload['emote_moods'],
            $payload['occupation'],
            $payload['appearance'],
            $payload['equipment'],
            $payload['inventory'],
            $payload['skills'],
            $payload['speechstyle'],
            $payload['goals'],
            $payload['relationships'],
            $payload['voiceid'],
            $payload['metadata'],
            $payload['race'],
            $payload['faction'],
            $payload['gender'],
            $payload['profile_id'],
            $payload['dynamic_profile'],
            $payload['extended_data'],
            $payload['md5'],
            $payload['gamets_last_updated'],
            $payload['bounty'],
            $payload['limbs'],
            $payload['blood'],
            $payload['hunger'],
            $payload['tags'],
            $payload['is_animal'],
            $payload['is_slave'],
            $payload['world_knowledge_tags'],
            $safeReason,
            $snapshotHash,
            $payload['source_created_at'],
            $payload['source_updated_at'],
            $payload['plugin_extended_data'],
        ]
    );

    if ($result) {
        stobePruneNpcHistorySnapshots($npcId, 50);
        stobeLogInfo('NPC history snapshot stored', [
            'npc_id' => $npcId,
            'name' => $name,
            'reason' => $safeReason,
            'history_id' => intval($result['history_id'] ?? 0),
        ]);
    }

    return $result !== false;
}

function stobeRewriteNpcHistorySnapshotById(int $historyId, array $row, string $reason): bool {
    if ($historyId <= 0) {
        return false;
    }

    $beforePayload = stobeBuildNpcHistorySnapshotPayloadFromRow($row, $reason);
    if (!is_array($beforePayload)) {
        return false;
    }

    $db = $GLOBALS["db"];
    $updated = $db->exec(
        "UPDATE core_npc_master_history
         SET name = $1,
             original_name = $2,
             npc_favorite = $3,
             lock_profile = $4,
             prompt_head = $5,
             personality = $6,
             backstory = $7,
             emote_moods = $8,
             occupation = $9,
             appearance = $10,
             equipment = $11,
             inventory = $12,
             skills = $13,
             speechstyle = $14,
             goals = $15,
             relationships = $16,
             voiceid = $17,
             metadata = $18::jsonb,
             race = $19,
             faction = $20,
             gender = $21,
             profile_id = $22,
             dynamic_profile = $23,
             extended_data = $24::jsonb,
             md5 = $25,
             gamets_last_updated = $26,
             bounty = $27::jsonb,
             limbs = $28::jsonb,
             blood = $29,
             hunger = $30,
             tags = $31,
             is_animal = $32,
             is_slave = $33,
             world_knowledge_tags = $34,
             snapshot_reason = $35,
             snapshot_hash = $36,
             source_created_at = $37,
             source_updated_at = $38
         WHERE history_id = $39",
        [
            $beforePayload['name'],
            $beforePayload['original_name'],
            $beforePayload['npc_favorite'],
            $beforePayload['lock_profile'],
            $beforePayload['prompt_head'],
            $beforePayload['personality'],
            $beforePayload['backstory'],
            $beforePayload['emote_moods'],
            $beforePayload['occupation'],
            $beforePayload['appearance'],
            $beforePayload['equipment'],
            $beforePayload['inventory'],
            $beforePayload['skills'],
            $beforePayload['speechstyle'],
            $beforePayload['goals'],
            $beforePayload['relationships'],
            $beforePayload['voiceid'],
            $beforePayload['metadata'],
            $beforePayload['race'],
            $beforePayload['faction'],
            $beforePayload['gender'],
            $beforePayload['profile_id'],
            $beforePayload['dynamic_profile'],
            $beforePayload['extended_data'],
            $beforePayload['md5'],
            $beforePayload['gamets_last_updated'],
            $beforePayload['bounty'],
            $beforePayload['limbs'],
            $beforePayload['blood'],
            $beforePayload['hunger'],
            $beforePayload['tags'],
            $beforePayload['is_animal'],
            $beforePayload['is_slave'],
            $beforePayload['world_knowledge_tags'],
            $beforePayload['snapshot_reason'],
            $beforePayload['snapshot_hash'],
            $beforePayload['source_created_at'],
            $beforePayload['source_updated_at'],
            $historyId,
        ]
    );
    if ($updated === false) {
        return false;
    }

    stobeLogInfo('NPC history snapshot rewritten', [
        'npc_id' => intval($beforePayload['npc_id'] ?? 0),
        'name' => $beforePayload['name'],
        'reason' => $beforePayload['snapshot_reason'],
        'history_id' => $historyId,
    ]);
    return true;
}

function stobeMaybeSnapshotNpcHistoryBeforeAfter(array|false $beforeRow, array|false $afterRow, string $reason): bool {
    if (!$beforeRow || !$afterRow) {
        return false;
    }
    $beforeHash = stobeBuildNpcHistoryHashFromRow($beforeRow);
    $afterHash = stobeBuildNpcHistoryHashFromRow($afterRow);
    if ($beforeHash === '' || $afterHash === '' || hash_equals($beforeHash, $afterHash)) {
        return false;
    }
    $changedFields = stobeGetNpcHistoryChangedFields($beforeRow, $afterRow);
    if (!empty($changedFields)) {
        $snapshotNoiseFields = ['skills', 'relationships'];
        $meaningfulChanges = array_values(array_diff($changedFields, $snapshotNoiseFields));
        if (count($meaningfulChanges) === 0) {
            stobeLogDebug('NPC history snapshot skipped for noisy-only field changes', [
                'npc_id' => intval($beforeRow['id'] ?? ($afterRow['id'] ?? 0)),
                'name' => trim(strval($afterRow['name'] ?? ($beforeRow['name'] ?? ''))),
                'reason' => trim(strval($reason)),
                'changed_fields' => $changedFields,
            ]);
            return false;
        }
    }
    return stobeInsertNpcHistorySnapshotFromRow($beforeRow, $reason);
}

function stobeDefaultEmoteMoods(): string {
    // Keep parity with HerikaServer conf.sample.php default EMOTEMOODS.
    // Runtime normalization maps deprecated aliases to canonical moods.
    return 'sassy,assertive,sexy,smug,kindly,lovely,seductive,sarcastic,sardonic,smirking,amused,default,assisting,irritated,playful,neutral,teasing,mocking,desperate,distressed,pleading,sad';
}

function stobeDefaultEmoteMoodList(): array {
    return [
        'sassy',
        'assertive',
        'sexy',
        'smug',
        'kindly',
        'lovely',
        'seductive',
        'sarcastic',
        'smirking',
        'amused',
        'irritated',
        'playful',
        'neutral',
        'teasing',
        'desperate',
        'scared',
        'pleading',
        'sad',
    ];
}

function stobeExpandEmoteMoodToken(string $token): array {
    $token = strtolower(trim($token));
    if ($token === '') {
        return [];
    }

    $defaultMoods = stobeDefaultEmoteMoodList();
    $defaultLookup = array_fill_keys($defaultMoods, true);
    $deprecatedMoodAliases = [
        'sardonic' => ['sarcastic'],
        'mocking' => ['sarcastic'],
        'default' => ['neutral'],
        'distressed' => ['scared'],
        'assisting' => [],
    ];

    if (isset($defaultLookup[$token])) {
        return [$token];
    }
    if (isset($deprecatedMoodAliases[$token])) {
        return $deprecatedMoodAliases[$token];
    }
    if (str_contains($token, ':')) {
        return [$token];
    }

    $compactToken = preg_replace('/[^a-z]/', '', $token);
    if ($compactToken === '') {
        return [];
    }
    if (isset($defaultLookup[$compactToken])) {
        return [$compactToken];
    }
    if (isset($deprecatedMoodAliases[$compactToken])) {
        return $deprecatedMoodAliases[$compactToken];
    }

    $sortedMoods = array_values(array_unique(array_merge($defaultMoods, array_keys($deprecatedMoodAliases))));
    usort($sortedMoods, static function (string $left, string $right): int {
        return strlen($right) <=> strlen($left);
    });

    $pattern = '/' . implode('|', array_map(static function (string $mood): string {
        return preg_quote($mood, '/');
    }, $sortedMoods)) . '/';

    preg_match_all($pattern, $compactToken, $matches);
    $expandedMoods = $matches[0] ?? [];
    if (!empty($expandedMoods) && implode('', $expandedMoods) === $compactToken) {
        $normalizedExpandedMoods = [];
        foreach ($expandedMoods as $expandedMood) {
            foreach (stobeExpandEmoteMoodToken($expandedMood) as $normalizedMood) {
                $normalizedExpandedMoods[] = $normalizedMood;
            }
        }
        return $normalizedExpandedMoods;
    }

    return [$token];
}

function stobeNormalizeEmoteMoods(mixed $rawMoods): array {
    if (is_array($rawMoods)) {
        $tokens = $rawMoods;
    } else {
        $repaired = str_replace(
            'mockingdesperatedistressedpleadingsad',
            'mocking,desperate,distressed,pleading,sad',
            trim((string)$rawMoods)
        );
        $tokens = preg_split('/[\r\n,|]+/', $repaired);
    }

    $normalizedMoods = [];
    $seenMoods = [];
    foreach ($tokens as $token) {
        foreach (stobeExpandEmoteMoodToken((string)$token) as $mood) {
            if ($mood === '' || isset($seenMoods[$mood])) {
                continue;
            }
            $normalizedMoods[] = $mood;
            $seenMoods[$mood] = true;
        }
    }

    return $normalizedMoods;
}

function stobeNormalizeEmoteMoodsCsv(string $csv): string {
    return implode(',', stobeNormalizeEmoteMoods($csv));
}

function stobeResolveGlobalEmoteMoods(): string {
    $configured = trim(strval(getSetting('EMOTEMOODS', '')));
    if ($configured !== '') {
        $normalized = stobeNormalizeEmoteMoodsCsv($configured);
        if ($normalized !== '') {
            return $normalized;
        }
    }
    return stobeNormalizeEmoteMoodsCsv(stobeDefaultEmoteMoods());
}

function stobeExtractFirstEmoteMood(mixed $rawMoods, string $fallback = ''): string {
    $normalizedMoods = stobeNormalizeEmoteMoods($rawMoods);
    if (!empty($normalizedMoods)) {
        return $normalizedMoods[0];
    }
    return trim($fallback);
}

function stobeParseNonNegativeInt(mixed $value, int $fallback = 0): int {
    if (is_int($value)) {
        return max(0, $value);
    }
    if (is_float($value)) {
        return max(0, intval($value));
    }
    if (is_string($value)) {
        $text = trim($value);
        if ($text !== '' && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $text) === 1) {
            return max(0, intval(floatval($text)));
        }
    }
    return max(0, $fallback);
}

function stobeNormalizeBountyReasonList(mixed $value): array {
    $tokens = [];
    if (is_array($value)) {
        $tokens = $value;
    } elseif (is_string($value)) {
        $raw = trim($value);
        if ($raw === '') {
            return [];
        }
        $tokens = preg_split('/[|,;]+/', $raw);
        if (!is_array($tokens) || count($tokens) === 0) {
            $tokens = [$raw];
        }
    } else {
        return [];
    }

    $normalized = [];
    $seen = [];
    foreach ($tokens as $token) {
        if (is_array($token) || is_object($token)) {
            continue;
        }
        $text = trim(strval($token));
        if ($text === '') {
            continue;
        }
        $dedupe = strtolower($text);
        if (isset($seen[$dedupe])) {
            continue;
        }
        $seen[$dedupe] = true;
        $normalized[] = $text;
        if (count($normalized) >= 24) {
            break;
        }
    }
    return $normalized;
}

function stobeExtractBountyTracking(mixed $rawBounty): array {
    $details = [];
    $amount = 0;

    if (is_string($rawBounty)) {
        $trimmed = trim($rawBounty);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $rawBounty = $decoded;
            }
        }
    }

    if (is_int($rawBounty) || is_float($rawBounty) || is_string($rawBounty)) {
        $amount = stobeParseNonNegativeInt($rawBounty, 0);
        if ($amount > 0) {
            $details['total'] = $amount;
        }
        return [
            'amount' => $amount,
            'details' => $details,
        ];
    }

    $payload = [];
    if (is_array($rawBounty)) {
        $payload = $rawBounty;
    } elseif (is_object($rawBounty)) {
        $payload = json_decode(json_encode($rawBounty), true);
        if (!is_array($payload)) {
            $payload = [];
        }
    }
    if (count($payload) === 0) {
        return [
            'amount' => 0,
            'details' => [],
        ];
    }

    foreach (['amount', 'total', 'value', 'bounty', 'cats'] as $amountKey) {
        if (!array_key_exists($amountKey, $payload)) {
            continue;
        }
        $amount = stobeParseNonNegativeInt($payload[$amountKey], $amount);
        if ($amount > 0) {
            break;
        }
    }

    $rawEntries = [];
    if (array_is_list($payload)) {
        $rawEntries = $payload;
    } else {
        foreach (['factions', 'entries', 'by_faction', 'list'] as $entryKey) {
            if (isset($payload[$entryKey]) && is_array($payload[$entryKey])) {
                $rawEntries = $payload[$entryKey];
                break;
            }
        }
    }

    if (count($rawEntries) > 0 && !array_is_list($rawEntries)) {
        $looksLikeSingleEntry = false;
        foreach (['faction', 'faction_name', 'faction_id', 'factionID', 'name', 'amount', 'total', 'value', 'bounty', 'cats', 'what_for', 'crimes', 'crime_mask', 'crimes_mask'] as $entryField) {
            if (array_key_exists($entryField, $rawEntries)) {
                $looksLikeSingleEntry = true;
                break;
            }
        }
        if ($looksLikeSingleEntry) {
            $rawEntries = [$rawEntries];
        }
    }

    if (count($rawEntries) === 0) {
        $singleFaction = trim(strval($payload['faction'] ?? ($payload['faction_name'] ?? ($payload['name'] ?? ''))));
        $singleFactionId = trim(strval($payload['faction_id'] ?? ($payload['factionID'] ?? '')));
        if (
            $singleFaction !== '' ||
            $singleFactionId !== '' ||
            $amount > 0 ||
            array_key_exists('what_for', $payload) ||
            array_key_exists('crimes', $payload)
        ) {
            $rawEntries = [[
                'faction' => $singleFaction,
                'faction_id' => $singleFactionId,
                'amount' => $amount,
                'what_for' => $payload['what_for'] ?? ($payload['crimes'] ?? []),
                'crime_mask' => $payload['crime_mask'] ?? ($payload['crimes_mask'] ?? 0),
                'claimed_once' => $payload['claimed_once'] ?? ($payload['claimed'] ?? false),
                'assigned_game_ts' => $payload['assigned_game_ts'] ?? ($payload['assignment_ts'] ?? 0),
            ]];
        }
    }

    $normalizedEntries = [];
    $sumAmount = 0;
    $maxAmount = 0;
    $primaryFaction = '';
    $primaryFactionId = '';
    foreach ($rawEntries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $entryAmount = 0;
        foreach (['amount', 'total', 'value', 'bounty', 'cats'] as $entryAmountKey) {
            if (!array_key_exists($entryAmountKey, $entry)) {
                continue;
            }
            $entryAmount = stobeParseNonNegativeInt($entry[$entryAmountKey], $entryAmount);
            if ($entryAmount > 0) {
                break;
            }
        }
        $entryFaction = trim(strval($entry['faction'] ?? ($entry['faction_name'] ?? ($entry['name'] ?? ''))));
        $entryFactionId = trim(strval($entry['faction_id'] ?? ($entry['factionID'] ?? '')));
        $entryCrimeMask = stobeParseNonNegativeInt(
            $entry['crime_mask'] ?? ($entry['crimes_mask'] ?? ($entry['crime_bits'] ?? 0)),
            0
        );
        $entryReasons = stobeNormalizeBountyReasonList(
            $entry['what_for'] ?? ($entry['crimes'] ?? ($entry['reasons'] ?? ($entry['reason'] ?? [])))
        );

        if (
            $entryAmount <= 0 &&
            $entryFaction === '' &&
            $entryFactionId === '' &&
            $entryCrimeMask <= 0 &&
            count($entryReasons) === 0
        ) {
            continue;
        }

        $normalizedEntry = [];
        if ($entryFaction !== '') {
            $normalizedEntry['faction'] = $entryFaction;
        }
        if ($entryFactionId !== '') {
            $normalizedEntry['faction_id'] = $entryFactionId;
        }
        if ($entryAmount > 0) {
            $normalizedEntry['amount'] = $entryAmount;
        }
        if ($entryCrimeMask > 0) {
            $normalizedEntry['crime_mask'] = $entryCrimeMask;
        }
        if (count($entryReasons) > 0) {
            $normalizedEntry['what_for'] = $entryReasons;
        }
        if (array_key_exists('claimed_once', $entry) || array_key_exists('claimed', $entry)) {
            $normalizedEntry['claimed_once'] = coerceBoolean($entry['claimed_once'] ?? $entry['claimed']);
        }
        $assignedTs = stobeParseNonNegativeInt(
            $entry['assigned_game_ts'] ?? ($entry['assignment_ts'] ?? ($entry['start_ts'] ?? 0)),
            0
        );
        if ($assignedTs > 0) {
            $normalizedEntry['assigned_game_ts'] = $assignedTs;
        }

        $normalizedEntries[] = $normalizedEntry;
        $sumAmount += $entryAmount;
        if ($entryAmount > $maxAmount) {
            $maxAmount = $entryAmount;
            $primaryFaction = $entryFaction;
            $primaryFactionId = $entryFactionId;
        }
        if (count($normalizedEntries) >= 24) {
            break;
        }
    }

    if ($amount <= 0 && $sumAmount > 0) {
        $amount = $sumAmount;
    }

    if ($amount > 0) {
        $details['total'] = $amount;
    }
    if ($primaryFaction !== '') {
        $details['primary_faction'] = $primaryFaction;
    }
    if ($primaryFactionId !== '') {
        $details['primary_faction_id'] = $primaryFactionId;
    }
    if (count($normalizedEntries) > 0) {
        $details['factions'] = $normalizedEntries;
    }

    return [
        'amount' => max(0, $amount),
        'details' => $details,
    ];
}

function stobeNormalizeBountyPayload(mixed $rawBounty, mixed $rawBountyInfo = null): array {
    $bountyParsed = stobeExtractBountyTracking($rawBounty);
    $bounty = intval($bountyParsed['amount'] ?? 0);
    $bountyInfo = is_array($bountyParsed['details'] ?? null) ? $bountyParsed['details'] : [];

    if ($rawBountyInfo !== null) {
        $bountyInfoParsed = stobeExtractBountyTracking($rawBountyInfo);
        $bountyInfoDetails = is_array($bountyInfoParsed['details'] ?? null) ? $bountyInfoParsed['details'] : [];
        if ($bounty <= 0) {
            $bounty = intval($bountyInfoParsed['amount'] ?? 0);
        }
        if (count($bountyInfo) === 0) {
            $bountyInfo = $bountyInfoDetails;
        } elseif (count($bountyInfoDetails) > 0) {
            if (!isset($bountyInfo['factions']) && isset($bountyInfoDetails['factions'])) {
                $bountyInfo['factions'] = $bountyInfoDetails['factions'];
            }
            if (!isset($bountyInfo['primary_faction']) && isset($bountyInfoDetails['primary_faction'])) {
                $bountyInfo['primary_faction'] = $bountyInfoDetails['primary_faction'];
            }
            if (!isset($bountyInfo['primary_faction_id']) && isset($bountyInfoDetails['primary_faction_id'])) {
                $bountyInfo['primary_faction_id'] = $bountyInfoDetails['primary_faction_id'];
            }
            if (!isset($bountyInfo['total']) && isset($bountyInfoDetails['total'])) {
                $bountyInfo['total'] = $bountyInfoDetails['total'];
            }
        }
    }

    if ($bounty < 0) {
        $bounty = 0;
    }
    if ($bounty <= 0 && isset($bountyInfo['total'])) {
        $bounty = stobeParseNonNegativeInt($bountyInfo['total'], 0);
    }
    if ($bounty > 0) {
        $bountyInfo['total'] = $bounty;
    } elseif (isset($bountyInfo['total'])) {
        $total = stobeParseNonNegativeInt($bountyInfo['total'], 0);
        if ($total > 0) {
            $bountyInfo['total'] = $total;
        } else {
            unset($bountyInfo['total']);
        }
    }

    if (!is_array($bountyInfo) || count($bountyInfo) === 0) {
        return [];
    }
    return $bountyInfo;
}

function stobeNormalizeBountyJsonString(mixed $rawBounty, mixed $rawBountyInfo = null): string {
    $payload = stobeNormalizeBountyPayload($rawBounty, $rawBountyInfo);
    if (!is_array($payload) || count($payload) === 0) {
        return '{}';
    }
    if (array_is_list($payload)) {
        $reparsed = stobeExtractBountyTracking($payload);
        $payload = is_array($reparsed['details'] ?? null) ? $reparsed['details'] : [];
        if (!is_array($payload) || count($payload) === 0 || array_is_list($payload)) {
            return '{}';
        }
    }
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded) || $encoded === '' || $encoded === '[]') {
        return '{}';
    }
    return $encoded;
}

function stobeBountyAmountFromPayload(mixed $rawBounty, mixed $rawBountyInfo = null): int {
    $payload = stobeNormalizeBountyPayload($rawBounty, $rawBountyInfo);
    if (!is_array($payload) || count($payload) === 0) {
        return 0;
    }
    return stobeParseNonNegativeInt($payload['total'] ?? 0, 0);
}

function stobeBuildBountyText(array $bountyDetails): string {
    if (!is_array($bountyDetails) || count($bountyDetails) === 0) {
        return '';
    }
    $factions = $bountyDetails['factions'] ?? [];
    if (!is_array($factions) || count($factions) === 0) {
        $total = intval($bountyDetails['total'] ?? 0);
        return $total > 0 ? ('Bounty: ' . number_format($total) . ' cats') : '';
    }

    $parts = [];
    $maxFactionsToShow = 3;
    foreach ($factions as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if (count($parts) >= $maxFactionsToShow) {
            break;
        }

        $factionName = trim(strval($entry['faction'] ?? ($entry['faction_id'] ?? 'Unknown faction')));
        $amount = intval($entry['amount'] ?? 0);
        $whatForRaw = $entry['what_for'] ?? [];
        $whatFor = [];
        if (is_array($whatForRaw)) {
            foreach ($whatForRaw as $crime) {
                $crimeText = trim(strval($crime));
                if ($crimeText !== '') {
                    $whatFor[] = $crimeText;
                }
            }
        } elseif (is_string($whatForRaw) && trim($whatForRaw) !== '') {
            $whatFor = array_values(array_filter(array_map('trim', preg_split('/[|,;]+/', $whatForRaw))));
        }
        if (count($whatFor) > 8) {
            $whatFor = array_slice($whatFor, 0, 8);
        }

        $segment = $factionName;
        if ($amount > 0) {
            $segment .= ' (' . number_format($amount) . ' cats)';
        }
        if (count($whatFor) > 0) {
            $segment .= ' - Wanted for: ' . implode(', ', $whatFor);
        }
        $parts[] = $segment;
    }

    if (count($factions) > $maxFactionsToShow) {
        $parts[] = '+' . strval(count($factions) - $maxFactionsToShow) . ' more faction(s)';
    }
    return implode(' | ', $parts);
}

function storeNpcProfile(string $name, array $profile, array $options = []): void {
    $db = $GLOBALS["db"];
    $safeName = trim($name);
    if ($safeName === '') {
        return;
    }
    $historyEnabled = !coerceBoolean($options['skip_history'] ?? false);
    $deferVoiceAssignment = coerceBoolean($options['defer_voice_assignment'] ?? false);
    $forceAppearanceUpdate = coerceBoolean($options['force_update_appearance'] ?? false);
    $historyReason = trim(strval($options['history_reason'] ?? 'profile_update'));
    if ($historyReason === '') {
        $historyReason = 'profile_update';
    }
    $historyBeforeRow = $historyEnabled ? stobeFetchNpcRowForHistoryByName($safeName) : false;

    $speechStyleRaw = trim(strval($profile['speechstyle'] ?? ($profile['speech_quirks'] ?? '')));
    $voiceIdRaw = trim(strval($profile['voiceid'] ?? ($profile['voice_model'] ?? '')));
    $promptHeadRaw = trim(strval($profile['prompt_head'] ?? ''));
    $personalityRaw = trim(strval($profile['personality'] ?? ''));
    $backstoryRaw = trim(strval($profile['backstory'] ?? ''));
    $emoteMoodsRaw = trim(strval($profile['emote_moods'] ?? ''));
    $occupationRaw = trim(strval($profile['occupation'] ?? ''));
    $appearanceRaw = trim(strval($profile['appearance'] ?? ''));
    $equipmentRaw = trim(strval($profile['equipment'] ?? ''));
    $inventoryRaw = trim(strval($profile['inventory'] ?? ''));
    $skillsRaw = trim(strval($profile['skills'] ?? ''));
    $goalsRaw = trim(strval($profile['goals'] ?? ''));
    $raceRaw = trim(strval($profile['race'] ?? ''));
    $factionRaw = trim(strval($profile['faction'] ?? ''));
    $factionIdRaw = trim(strval($profile['faction_id'] ?? ($profile['factionID'] ?? '')));
    $genderRaw = trim(strval($profile['gender'] ?? ''));
    $originalNameRaw = trim(strval($profile['original_name'] ?? ''));
    $tagsRaw = trim(strval($profile['tags'] ?? ''));
    $world_knowledgeRaw = trim(strval($profile['world_knowledge_tags'] ?? ''));
    $bountyRaw = $profile['bounty'] ?? 0;
    $bountyInfoRaw = $profile['bounty_info'] ?? ($profile['bounty_details'] ?? null);
    $factionCombined = composeFactionWithId($factionRaw, $factionIdRaw);
    $traitSelection = selectBioTraitsForNpc($safeName, $raceRaw, $genderRaw, $factionCombined, $originalNameRaw);
    $resolvedTraits = is_array($traitSelection['traits'] ?? null) ? $traitSelection['traits'] : [];

    $promptHead = $promptHeadRaw;
    $personality = $personalityRaw !== '' ? $personalityRaw : trim(strval($resolvedTraits['personality'] ?? ''));
    if ($personality === '') {
        $personality = 'Pragmatic wasteland survivor.';
    }
    $backstory = $backstoryRaw !== '' ? $backstoryRaw : trim(strval($resolvedTraits['backstory'] ?? ''));
    if ($backstory === '') {
        $backstory = 'A drifter surviving the harsh world of Kenshi.';
    }
    $emoteMoods = $emoteMoodsRaw !== '' ? stobeNormalizeEmoteMoodsCsv($emoteMoodsRaw) : stobeResolveGlobalEmoteMoods();
    if ($emoteMoods === '') {
        $emoteMoods = stobeDefaultEmoteMoods();
    }
    $occupation = $occupationRaw !== '' ? $occupationRaw : trim(strval($resolvedTraits['occupation'] ?? ''));
    $occupation = stobeNormalizeOccupationText($occupation, $factionCombined);
    $appearanceExtra = normalizeBioAppearanceExtra(trim(strval($resolvedTraits['appearance'] ?? '')));
    $appearance = appendBioAppearanceExtrasToText($appearanceRaw, $appearanceExtra !== '' ? [$appearanceExtra] : []);
    $equipment = $equipmentRaw;
    $inventory = $inventoryRaw;
    $skills = $skillsRaw;
    $speechStyle = $speechStyleRaw !== '' ? $speechStyleRaw : trim(strval($resolvedTraits['speechstyle'] ?? ''));
    if ($speechStyle === '') {
        $speechStyle = 'Direct and practical.';
    }
    $goals = $goalsRaw !== '' ? $goalsRaw : trim(strval($resolvedTraits['goals'] ?? ''));
    $voiceId = $voiceIdRaw;
    if (!$deferVoiceAssignment && $voiceId === '') {
        $voiceId = stobeSelectVoiceIdForNpc(
            $safeName,
            $raceRaw,
            $genderRaw,
            $factionCombined,
            [
                'metadata' => $profile['metadata'] ?? [],
                'original_name' => $originalNameRaw,
            ]
        );
    }
    $race = $raceRaw !== '' ? $raceRaw : 'Unknown';
    $faction = $factionCombined;
    $gender = $genderRaw;
    $tags = $tagsRaw !== '' ? $tagsRaw : '';
    $world_knowledge = $world_knowledgeRaw !== '' ? $world_knowledgeRaw : '';
    $bountyPayload = stobeNormalizeBountyPayload($bountyRaw, $bountyInfoRaw);
    $bountyJson = stobeNormalizeBountyJsonString($bountyPayload);

    $isAnimal = coerceBoolean($profile['is_animal'] ?? false);
    $isBracketName = strpos($safeName, '[') !== false;

    $isPlaceholderWrite = (
        ($raceRaw === '' || strtolower($raceRaw) === 'unknown') &&
        trim($factionRaw) === '' &&
        trim($genderRaw) === '' &&
        trim($equipmentRaw) === '' &&
        trim($skillsRaw) === '' &&
        trim($inventoryRaw) === ''
    );

    $metadataArray = normalizeCoreNpcMetadata($profile['metadata'] ?? '{}');
    if (isset($traitSelection['tts_filter_preset']) && !array_key_exists('tts_filter_preset', $metadataArray)) {
        $metadataArray['tts_filter_preset'] = $traitSelection['tts_filter_preset'];
    }
    unset($metadataArray['bounty_info'], $metadataArray['bounty_text']);
    $metadataSource = trim(strval($metadataArray['source'] ?? ''));
    if ($isBracketName && $isPlaceholderWrite) {
        stobeLogImport('Bracket profile write is placeholder-like', [
            'name' => $safeName,
            'race_raw' => $raceRaw,
            'faction_raw' => $factionRaw,
            'gender_raw' => $genderRaw,
            'storage_id' => normalizeStorageIdToken($metadataArray['storage_id'] ?? ''),
            'metadata_source' => $metadataSource,
            'profile_keys' => array_keys($profile),
        ], 'DEBUG');
    }

    $extendedDataJson = normalizeJsonString(normalizeCoreNpcExtendedData($profile['extended_data'] ?? '{}'));
    $hasExplicitProfileId = array_key_exists('profile_id', $profile);
    $explicitProfileId = $hasExplicitProfileId ? intval($profile['profile_id'] ?? 0) : 0;
    $ruleProfileId = $hasExplicitProfileId ? 0 : stobeResolveProfileIdFromImportRules($safeName, $race, $gender, $faction);
    $selectedProfileId = $hasExplicitProfileId
        ? $explicitProfileId
        : ($ruleProfileId > 0 ? $ruleProfileId : getDefaultNpcProfileId());
    $selectedProfileIdOrNull = $selectedProfileId > 0 ? $selectedProfileId : null;
    if ($hasExplicitProfileId && $explicitProfileId > 0) {
        $metadataArray['profile_assignment_source'] = 'manual';
    } elseif ($ruleProfileId > 0) {
        $metadataArray['profile_assignment_source'] = 'rule';
    }
    $metadataJson = normalizeJsonString($metadataArray);

    $profilePersisted = $db->exec(
        "INSERT INTO core_npc_master (
            name,
            npc_favorite,
            lock_profile,
            prompt_head,
            personality,
            backstory,
            emote_moods,
            occupation,
            appearance,
            equipment,
            inventory,
            skills,
            speechstyle,
            goals,
            voiceid,
            metadata,
            race,
            faction,
            gender,
            profile_id,
            extended_data,
            gamets_last_updated,
            bounty,
            tags,
            is_animal,
            world_knowledge_tags,
            updated_at
         )
         VALUES (
            $1, FALSE, FALSE, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14::jsonb, $15, $16, $17,
            $18, $19::jsonb, 0, $20::jsonb, $21, $22, $23, NOW()
         )
         ON CONFLICT (name) DO UPDATE SET
            prompt_head = COALESCE(NULLIF(core_npc_master.prompt_head, ''), EXCLUDED.prompt_head),
            personality = COALESCE(NULLIF(core_npc_master.personality, ''), EXCLUDED.personality),
            backstory = COALESCE(NULLIF(core_npc_master.backstory, ''), EXCLUDED.backstory),
            emote_moods = COALESCE(NULLIF(core_npc_master.emote_moods, ''), EXCLUDED.emote_moods),
            occupation = CASE
                WHEN core_npc_master.occupation IS NULL OR BTRIM(core_npc_master.occupation) = '' THEN EXCLUDED.occupation
                WHEN (
                    core_npc_master.occupation ~ '\[[^\]]+\]'
                    AND (
                        LOWER(core_npc_master.occupation) LIKE 'faction:%'
                        OR LOWER(core_npc_master.occupation) LIKE 'faction member:%'
                        OR core_npc_master.occupation ~* '\bfaction\s*:\s*[^|\n\r]*\[[^\]]+\]'
                    )
                ) THEN EXCLUDED.occupation
                ELSE core_npc_master.occupation
            END,
            appearance = CASE
                WHEN core_npc_master.appearance IS NULL OR BTRIM(core_npc_master.appearance) = '' THEN EXCLUDED.appearance
                WHEN $24 THEN EXCLUDED.appearance
                ELSE core_npc_master.appearance
            END,
            equipment = CASE
                WHEN NULLIF(EXCLUDED.equipment, '') IS NOT NULL THEN EXCLUDED.equipment
                ELSE COALESCE(core_npc_master.equipment, '')
            END,
            inventory = CASE
                WHEN NULLIF(EXCLUDED.inventory, '') IS NOT NULL THEN EXCLUDED.inventory
                ELSE COALESCE(core_npc_master.inventory, '')
            END,
            skills = CASE
                WHEN NULLIF(EXCLUDED.skills, '') IS NOT NULL THEN EXCLUDED.skills
                ELSE COALESCE(core_npc_master.skills, '')
            END,
            speechstyle = COALESCE(NULLIF(core_npc_master.speechstyle, ''), EXCLUDED.speechstyle),
            goals = COALESCE(NULLIF(core_npc_master.goals, ''), EXCLUDED.goals),
            race = CASE
                WHEN NULLIF(EXCLUDED.race, '') IS NOT NULL
                  AND LOWER(EXCLUDED.race) <> 'unknown'
                THEN EXCLUDED.race
                ELSE COALESCE(NULLIF(core_npc_master.race, ''), EXCLUDED.race)
            END,
            faction = CASE
                WHEN NULLIF(EXCLUDED.faction, '') IS NOT NULL THEN EXCLUDED.faction
                WHEN core_npc_master.faction IS NULL OR TRIM(core_npc_master.faction) = '' OR LOWER(core_npc_master.faction) = 'unknown' THEN ''
                ELSE core_npc_master.faction
            END,
            gender = CASE
                WHEN NULLIF(EXCLUDED.gender, '') IS NOT NULL THEN EXCLUDED.gender
                WHEN core_npc_master.gender IS NULL OR TRIM(core_npc_master.gender) = '' OR LOWER(core_npc_master.gender) = 'unknown' THEN ''
                ELSE core_npc_master.gender
            END,
            profile_id = COALESCE(core_npc_master.profile_id, EXCLUDED.profile_id),
            voiceid = COALESCE(NULLIF(core_npc_master.voiceid, ''), EXCLUDED.voiceid),
            metadata = CASE
                WHEN core_npc_master.metadata IS NULL OR core_npc_master.metadata = '{}'::jsonb OR core_npc_master.metadata = '[]'::jsonb THEN (EXCLUDED.metadata - 'tts_filter_preset')
                ELSE core_npc_master.metadata
            END,
            extended_data = CASE
                WHEN core_npc_master.extended_data IS NULL OR core_npc_master.extended_data = '{}'::jsonb OR core_npc_master.extended_data = '[]'::jsonb THEN EXCLUDED.extended_data
                ELSE core_npc_master.extended_data
            END,
            bounty = CASE
                WHEN EXCLUDED.bounty <> '{}'::jsonb THEN EXCLUDED.bounty
                ELSE COALESCE(core_npc_master.bounty, '{}'::jsonb)
            END,
            tags = COALESCE(NULLIF(core_npc_master.tags, ''), EXCLUDED.tags),
            world_knowledge_tags = COALESCE(NULLIF(core_npc_master.world_knowledge_tags, ''), EXCLUDED.world_knowledge_tags),
            is_animal = COALESCE(core_npc_master.is_animal, EXCLUDED.is_animal),
            updated_at = NOW()",
        [
            $safeName,
            $promptHead,
            $personality,
            $backstory,
            $emoteMoods,
            $occupation,
            $appearance,
            $equipment,
            $inventory,
            $skills,
            $speechStyle,
            $goals,
            $voiceId,
            $metadataJson,
            $race,
            $faction,
            $gender,
            $selectedProfileIdOrNull,
            $extendedDataJson,
            $bountyJson,
            $tags,
            $isAnimal,
            $world_knowledge,
            $forceAppearanceUpdate,
        ]
    );
    if ($profilePersisted === false) {
        stobeLogImport('storeNpcProfile core_npc_master upsert failed', [
            'name' => $safeName,
            'storage_id' => normalizeStorageIdToken($metadataArray['storage_id'] ?? ''),
            'db_error' => $db->GetLastError(),
        ], 'WARN');
        return;
    }
    if ($historyEnabled) {
        $historyAfterRow = stobeFetchNpcRowForHistoryByName($safeName);
        stobeMaybeSnapshotNpcHistoryBeforeAfter($historyBeforeRow, $historyAfterRow, $historyReason);
    }
    stobeApplyPlayerFactionProfileForNpcName($safeName, $faction);

    if ($isBracketName) {
        $rowAfter = $db->fetchOne(
            "SELECT race, faction, gender, equipment, skills, gamets_last_updated,
                    COALESCE(metadata->>'storage_id', '') AS storage_id
             FROM core_npc_master
             WHERE LOWER(name) = LOWER($1)
             LIMIT 1",
            [$safeName]
        );
        if ($rowAfter) {
            $raceAfter = trim(strval($rowAfter['race'] ?? ''));
            $factionAfter = trim(strval($rowAfter['faction'] ?? ''));
            $genderAfter = trim(strval($rowAfter['gender'] ?? ''));
            $appearanceAfter = trim(strval($rowAfter['appearance'] ?? ''));
            $equipmentAfter = trim(strval($rowAfter['equipment'] ?? ''));
            $skillsAfter = trim(strval($rowAfter['skills'] ?? ''));
            $isUnresolved = (
                ($raceAfter === '' || strtolower($raceAfter) === 'unknown') &&
                $factionAfter === '' &&
                $genderAfter === '' &&
                $equipmentAfter === '' &&
                $skillsAfter === ''
            );
            stobeLogImport('Bracket profile persisted', [
                'name' => $safeName,
                'storage_id' => strval($rowAfter['storage_id'] ?? ''),
                'race' => $raceAfter,
                'faction' => $factionAfter,
                'gender' => $genderAfter,
                'appearance' => $appearanceAfter,
                'force_update_appearance' => $forceAppearanceUpdate,
                'equipment_len' => strlen($equipmentAfter),
                'skills_len' => strlen($skillsAfter),
                'gamets_last_updated' => intval($rowAfter['gamets_last_updated'] ?? 0),
                'metadata_source' => $metadataSource,
                'placeholder_like_input' => $isPlaceholderWrite,
            ], $isUnresolved ? 'WARN' : 'DEBUG');
        }
    }
}

function npcNeedsBootstrap(array $npcData): bool {
    $requiredTextFields = [
        'personality',
        'backstory',
        'speechstyle',
        'occupation',
        'goals',
        'world_knowledge_tags',
    ];

    foreach ($requiredTextFields as $field) {
        if (!isset($npcData[$field])) {
            return true;
        }
        if (trim(strval($npcData[$field])) === '') {
            return true;
        }
    }

    if (!isset($npcData['metadata']) || trim(strval($npcData['metadata'])) === '') {
        return true;
    }

    if (!isset($npcData['extended_data']) || trim(strval($npcData['extended_data'])) === '') {
        return true;
    }

    $faction = strtolower(trim(strval($npcData['faction'] ?? '')));
    $gender = strtolower(trim(strval($npcData['gender'] ?? '')));
    if ($faction === 'unknown' || $gender === 'unknown') {
        return true;
    }

    $metadataRaw = trim(strval($npcData['metadata'] ?? ''));
    $extendedRaw = trim(strval($npcData['extended_data'] ?? ''));
    if ($metadataRaw === '[]' || $extendedRaw === '[]') {
        return true;
    }

    return false;
}

function storeNpcSnapshot(array $snapshot, int $gamets = 0): bool {
    $db = $GLOBALS["db"];
    $incomingName = trim(strval($snapshot['name'] ?? ''));
    if ($incomingName === '') {
        return false;
    }
    $name = $incomingName;
    $snapshotSource = '';
    if (array_key_exists('source', $snapshot)) {
        $snapshotSource = trim(strval($snapshot['source']));
    } elseif (isset($snapshot['metadata']) && is_array($snapshot['metadata'])) {
        $snapshotSource = trim(strval($snapshot['metadata']['source'] ?? ''));
    }
    $isInventoryLiveSync = (strcasecmp($snapshotSource, 'inventory_live_sync') === 0);

    $parseToggleValue = static function (mixed $value): mixed {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return intval($value) !== 0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return null;
            }
            if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enable', 'enabled'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off', 'disable', 'disabled'], true)) {
                return false;
            }
        }
        return null;
    };

    $ordersPayload = [];
    if (isset($snapshot['orders']) && is_array($snapshot['orders'])) {
        $ordersPayload = $snapshot['orders'];
    } elseif (isset($snapshot['orders']) && is_string($snapshot['orders'])) {
        $decodedOrders = json_decode(strval($snapshot['orders']), true);
        if (is_array($decodedOrders)) {
            $ordersPayload = $decodedOrders;
        }
    }

    $hasToggleInput = static function (array $source, string $key): bool {
        if (!array_key_exists($key, $source)) {
            return false;
        }
        $value = $source[$key];
        if ($value === null) {
            return false;
        }
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return true;
        }
        return trim(strval($value)) !== '';
    };

    $orderToggleKeys = ['block', 'hold', 'passive', 'jobs', 'ranged', 'taunt', 'sneak', 'resource', 'medic'];
    foreach ($orderToggleKeys as $toggleKey) {
        $hasTopLevelValue = $hasToggleInput($snapshot, $toggleKey);
        if (!$hasTopLevelValue && array_key_exists($toggleKey, $ordersPayload)) {
            $snapshot[$toggleKey] = $ordersPayload[$toggleKey];
        }
        if (array_key_exists($toggleKey, $snapshot)) {
            $normalizedToggle = $parseToggleValue($snapshot[$toggleKey]);
            if ($normalizedToggle !== null) {
                $snapshot[$toggleKey] = $normalizedToggle;
            } else {
                unset($snapshot[$toggleKey]);
            }
        }
    }

    if (!array_key_exists('job_list', $snapshot) && array_key_exists('job_list', $ordersPayload)) {
        $snapshot['job_list'] = $ordersPayload['job_list'];
    }
    if (array_key_exists('job_list', $snapshot)) {
        $jobList = [];
        if (is_array($snapshot['job_list'])) {
            $jobList = $snapshot['job_list'];
        } elseif (is_string($snapshot['job_list']) && trim(strval($snapshot['job_list'])) !== '') {
            $decodedJobList = json_decode(strval($snapshot['job_list']), true);
            if (is_array($decodedJobList)) {
                $jobList = $decodedJobList;
            }
        }
        $normalizedJobList = [];
        foreach ($jobList as $jobName) {
            $jobText = trim(strval($jobName));
            if ($jobText === '') {
                continue;
            }
            $normalizedJobList[] = $jobText;
            if (count($normalizedJobList) >= 64) {
                break;
            }
        }
        $snapshot['job_list'] = $normalizedJobList;
        if (!array_key_exists('jobs', $snapshot)) {
            $snapshot['jobs'] = count($normalizedJobList) > 0;
        }
    }

    $pickText = static function (array $source, array $keys): array {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $raw = $source[$key];
            if (is_array($raw) || is_object($raw)) {
                continue;
            }
            $text = trim(strval($raw));
            if ($text === '') {
                continue;
            }
            return ['value' => $text, 'source' => $key];
        }
        return ['value' => '', 'source' => ''];
    };

    $pickMixed = static function (array $source, array $keys): array {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            return ['value' => $source[$key], 'source' => $key];
        }
        return ['value' => null, 'source' => ''];
    };

    $parseNonNegativeInt = static function (mixed $value, int $fallback = 0): int {
        if (is_int($value)) {
            return max(0, $value);
        }
        if (is_float($value)) {
            return max(0, intval($value));
        }
        if (is_string($value) && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', trim($value)) === 1) {
            return max(0, intval(floatval($value)));
        }
        return max(0, $fallback);
    };

    $carryFlagPick = $pickMixed($snapshot, ['is_carrying']);
    $carryFlag = null;
    if ($carryFlagPick['source'] !== '') {
        $carryFlag = $parseToggleValue($carryFlagPick['value']);
    }

    $carryingTargetPick = $pickText($snapshot, ['carrying_target_name']);
    $carryingTargetName = normalizeParticipantNameToken(strval($carryingTargetPick['value']));
    if (in_array(strtolower($carryingTargetName), ['someone', 'their carried target'], true)) {
        $carryingTargetName = '';
    }

    $beingCarriedPick = $pickMixed($snapshot, ['is_being_carried']);
    $isBeingCarried = null;
    if ($beingCarriedPick['source'] !== '') {
        $isBeingCarried = $parseToggleValue($beingCarriedPick['value']);
    }

    $carriedByPick = $pickText($snapshot, ['carried_by_name']);
    $carriedByName = normalizeParticipantNameToken(strval($carriedByPick['value']));
    if (in_array(strtolower($carriedByName), ['someone', 'their carrier'], true)) {
        $carriedByName = '';
    }

    if ($carryFlag === null && $carryingTargetName !== '') {
        $carryFlag = true;
    }
    if ($isBeingCarried === null && $carriedByName !== '') {
        $isBeingCarried = true;
    }

    if ($carryFlag === true) {
        $snapshot['is_carrying'] = true;
        if ($carryingTargetName !== '') {
            $snapshot['carrying_target_name'] = $carryingTargetName;
        } else {
            unset($snapshot['carrying_target_name']);
        }
    } elseif ($carryFlag === false) {
        $snapshot['is_carrying'] = false;
        unset($snapshot['carrying_target_name']);
    } else {
        unset($snapshot['is_carrying']);
        if ($carryingTargetName !== '') {
            $snapshot['carrying_target_name'] = $carryingTargetName;
        } else {
            unset($snapshot['carrying_target_name']);
        }
    }

    if ($isBeingCarried === true) {
        $snapshot['is_being_carried'] = true;
        if ($carriedByName !== '') {
            $snapshot['carried_by_name'] = $carriedByName;
        } else {
            unset($snapshot['carried_by_name']);
        }
    } elseif ($isBeingCarried === false) {
        $snapshot['is_being_carried'] = false;
        unset($snapshot['carried_by_name']);
    } else {
        unset($snapshot['is_being_carried']);
        if ($carriedByName !== '') {
            $snapshot['carried_by_name'] = $carriedByName;
        } else {
            unset($snapshot['carried_by_name']);
        }
    }

    $racePick = $pickText($snapshot, ['race', 'race_name']);
    $race = strval($racePick['value']);
    if (strtolower($race) === 'unknown') {
        $race = '';
    }

    $factionPick = $pickText($snapshot, ['faction', 'faction_name', 'origin_faction']);
    $faction = strval($factionPick['value']);
    if (strtolower($faction) === 'unknown') {
        $faction = '';
    }
    $factionIdPick = $pickText($snapshot, ['faction_id', 'factionID']);
    $factionId = strval($factionIdPick['value']);
    $faction = composeFactionWithId($faction, $factionId);

    $genderPick = $pickText($snapshot, ['gender', 'sex']);
    $gender = strval($genderPick['value']);
    if ($gender === '' && array_key_exists('is_female', $snapshot)) {
        $gender = coerceBoolean($snapshot['is_female']) ? 'female' : 'male';
        $genderPick['source'] = 'is_female';
    }
    if (strtolower($gender) === 'unknown') {
        $gender = '';
    }

    $storageIdPick = $pickText($snapshot, ['storage_id', 'id', 'refid', 'handle']);
    $storageId = normalizeStorageIdToken(strval($storageIdPick['value']));
    $resolvedIdentity = resolveSnapshotTargetNpcName($name, $storageId);
    $resolvedName = trim(strval($resolvedIdentity['name'] ?? ''));
    if ($resolvedName !== '') {
        $name = $resolvedName;
    }
    $matchedBy = strval($resolvedIdentity['matched_by'] ?? 'incoming');
    if (strcasecmp($name, $incomingName) !== 0) {
        stobeLogImport('Snapshot name remapped', [
            'incoming_name' => $incomingName,
            'resolved_name' => $name,
            'storage_id' => $storageId,
            'matched_by' => $matchedBy,
        ], 'DEBUG');
    }
    $historyRowBefore = stobeFetchNpcRowForHistoryByName($name);
    $historyBaselineId = 0;
    if ($historyRowBefore) {
        $historyBaselineRow = $db->fetchOne(
            "SELECT COALESCE(MAX(history_id), 0) AS history_id
             FROM core_npc_master_history
             WHERE npc_id = $1",
            [intval($historyRowBefore['id'] ?? 0)]
        );
        $historyBaselineId = intval($historyBaselineRow['history_id'] ?? 0);
    }
    $isBracketSnapshot = (strpos($incomingName, '[') !== false || strpos($name, '[') !== false);
    $rowBefore = false;
    if ($isBracketSnapshot) {
        $rowBefore = $db->fetchOne(
            "SELECT race, faction, gender, equipment, skills, gamets_last_updated,
                    COALESCE(metadata->>'storage_id', '') AS storage_id
             FROM core_npc_master
             WHERE LOWER(name) = LOWER($1)
             LIMIT 1",
            [$name]
        );
    }

    $isAnimal = coerceBoolean($snapshot['is_animal'] ?? false);
    $isPlayerCharacter = coerceBoolean($snapshot['is_player_character'] ?? false);
    $tags = trim(strval($snapshot['tags'] ?? ''));
    if ($tags === '') {
        // Import snapshots should not auto-tag rows as jit.
        $tags = '';
    }

    $bountyRaw = $snapshot['bounty'] ?? 0;
    $bountyInfoRaw = $snapshot['bounty_info'] ?? ($snapshot['bounty_details'] ?? null);
    $bountyPayload = stobeNormalizeBountyPayload($bountyRaw, $bountyInfoRaw);
    $hasBountySignal = static function (mixed $value): bool {
        if ($value === null) {
            return false;
        }
        if (is_int($value) || is_float($value)) {
            return $value > 0;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || $trimmed === '0' || $trimmed === '{}' || $trimmed === '[]') {
                return false;
            }
            return true;
        }
        if (is_array($value)) {
            return count($value) > 0;
        }
        if (is_object($value)) {
            return count(get_object_vars($value)) > 0;
        }
        return false;
    };
    if (count($bountyPayload) === 0 && ($hasBountySignal($bountyRaw) || $hasBountySignal($bountyInfoRaw))) {
        stobeLogImport('Snapshot bounty payload empty after normalization', [
            'name' => $name,
            'gamets' => max(0, intval($gamets)),
            'bounty_type' => get_debug_type($bountyRaw),
            'bounty_info_type' => get_debug_type($bountyInfoRaw),
            'bounty_preview' => is_array($bountyRaw) || is_object($bountyRaw)
                ? json_encode($bountyRaw, JSON_UNESCAPED_UNICODE)
                : substr(strval($bountyRaw), 0, 300),
            'bounty_info_preview' => is_array($bountyInfoRaw) || is_object($bountyInfoRaw)
                ? json_encode($bountyInfoRaw, JSON_UNESCAPED_UNICODE)
                : substr(strval($bountyInfoRaw), 0, 300),
        ], 'WARN');
    }
    if (count($bountyPayload) > 0) {
        $snapshot['bounty'] = $bountyPayload;
    } else {
        unset($snapshot['bounty']);
    }
    unset($snapshot['bounty_info'], $snapshot['bounty_details'], $snapshot['bounty_text']);

    if ($isPlayerCharacter) {
        $playerCatsValue = null;
        $moneyPick = $pickMixed($snapshot, ['money', 'cats', 'player_money', 'player_cats']);
        if ($moneyPick['source'] !== '') {
            $rawMoney = $moneyPick['value'];
            if (is_int($rawMoney)) {
                $playerCatsValue = max(0, $rawMoney);
            } elseif (is_float($rawMoney)) {
                $playerCatsValue = max(0, intval($rawMoney));
            } elseif (is_string($rawMoney)) {
                $moneyText = trim($rawMoney);
                if ($moneyText !== '' && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $moneyText) === 1) {
                    $playerCatsValue = max(0, intval(floatval($moneyText)));
                }
            }
        }
        if ($playerCatsValue !== null) {
            $catsChanged = setConfOpt('PLAYER_CATS', strval($playerCatsValue), true);
            if ($catsChanged) {
                stobeLogInfo('Player cats synced to conf_opts', [
                    'name' => $name,
                    'cats' => $playerCatsValue,
                    'source_key' => strval($moneyPick['source']),
                    'gamets' => max(0, $gamets),
                ]);
            }
        }
    }

    $medical = [];
    if (isset($snapshot['medical']) && is_array($snapshot['medical'])) {
        $medical = $snapshot['medical'];
    } elseif (isset($snapshot['medical']) && is_string($snapshot['medical'])) {
        $decodedMedical = json_decode(strval($snapshot['medical']), true);
        if (is_array($decodedMedical)) {
            $medical = $decodedMedical;
        }
    }
    $hasMedicalPayload = count($medical) > 0;

    $limbsPayload = [];
    $appendLimbValue = static function (array &$target, string $key, mixed $value) use ($parseNonNegativeInt): void {
        $normalizedKey = trim($key);
        if ($normalizedKey === '') {
            return;
        }
        $target[$normalizedKey] = $parseNonNegativeInt($value, 0);
    };

    $limbSources = [];
    if (isset($medical['limbs']) && is_array($medical['limbs'])) {
        $limbSources[] = $medical['limbs'];
    } elseif (isset($medical['limbs']) && is_string($medical['limbs'])) {
        $decodedLimbSource = json_decode(strval($medical['limbs']), true);
        if (is_array($decodedLimbSource)) {
            $limbSources[] = $decodedLimbSource;
        }
    }
    if (isset($snapshot['limbs']) && is_array($snapshot['limbs'])) {
        $limbSources[] = $snapshot['limbs'];
    } elseif (isset($snapshot['limbs']) && is_string($snapshot['limbs'])) {
        $decodedLimbSource = json_decode(strval($snapshot['limbs']), true);
        if (is_array($decodedLimbSource)) {
            $limbSources[] = $decodedLimbSource;
        }
    }

    foreach ($limbSources as $limbSource) {
        foreach ($limbSource as $limbKey => $limbValue) {
            $normalizedLimb = trim(strval($limbKey));
            if ($normalizedLimb === '') {
                continue;
            }
            if (is_array($limbValue)) {
                $currentPick = $pickMixed($limbValue, ['current', 'value', 'hp', 'flesh']);
                $maxPick = $pickMixed($limbValue, ['max', 'max_hp', 'maxHealth', 'maximum']);
                if ($currentPick['source'] !== '') {
                    $appendLimbValue($limbsPayload, $normalizedLimb . '_current', $currentPick['value']);
                }
                if ($maxPick['source'] !== '') {
                    $appendLimbValue($limbsPayload, $normalizedLimb . '_max', $maxPick['value']);
                }
                if ($currentPick['source'] === '' && $maxPick['source'] === '') {
                    $appendLimbValue($limbsPayload, $normalizedLimb, 0);
                }
                continue;
            }
            $appendLimbValue($limbsPayload, $normalizedLimb, $limbValue);
        }
    }

    if (count($limbsPayload) === 0) {
        $directLimbKeys = [
            'head', 'head_max',
            'stomach', 'stomach_max',
            'left_arm', 'left_arm_max',
            'right_arm', 'right_arm_max',
            'left_leg', 'left_leg_max',
            'right_leg', 'right_leg_max',
        ];
        foreach ($directLimbKeys as $limbKey) {
            if (array_key_exists($limbKey, $medical)) {
                $appendLimbValue($limbsPayload, $limbKey, $medical[$limbKey]);
            } elseif (array_key_exists($limbKey, $snapshot)) {
                $appendLimbValue($limbsPayload, $limbKey, $snapshot[$limbKey]);
            }
        }
    }

    $parseCurrentMax = static function (
        mixed $currentCandidate,
        mixed $maxCandidate,
        int $defaultMax,
        callable $parseNonNegativeInt
    ): array {
        $hasValue = ($currentCandidate !== null || $maxCandidate !== null);
        $current = 0;
        $max = $defaultMax;

        if (is_string($currentCandidate)) {
            $trimmed = trim($currentCandidate);
            if (preg_match('/^([0-9]+)\s*\/\s*([0-9]+)$/', $trimmed, $matches) === 1) {
                $current = $parseNonNegativeInt($matches[1], 0);
                $max = $parseNonNegativeInt($matches[2], $defaultMax);
                $hasValue = true;
            } elseif ($trimmed !== '') {
                $current = $parseNonNegativeInt($trimmed, 0);
            }
        } elseif (is_array($currentCandidate)) {
            $nestedCurrent = $currentCandidate['current'] ?? ($currentCandidate['value'] ?? null);
            $nestedMax = $currentCandidate['max'] ?? ($currentCandidate['maximum'] ?? null);
            if ($nestedCurrent !== null) {
                $current = $parseNonNegativeInt($nestedCurrent, 0);
                $hasValue = true;
            }
            if ($nestedMax !== null) {
                $max = $parseNonNegativeInt($nestedMax, $defaultMax);
                $hasValue = true;
            }
        } elseif ($currentCandidate !== null) {
            $current = $parseNonNegativeInt($currentCandidate, 0);
        }

        if ($maxCandidate !== null) {
            $max = $parseNonNegativeInt($maxCandidate, $defaultMax);
        }

        if ($max <= 0) {
            $max = $defaultMax > 0 ? $defaultMax : max($current, 0);
        }

        return [
            'current' => $current,
            'max' => $max,
            'has_value' => $hasValue,
        ];
    };

    $deriveBloodFromLimbs = static function (array $limbs) use ($parseNonNegativeInt): array {
        $totalCurrent = 0;
        $totalMax = 0;
        $pairCount = 0;

        foreach ($limbs as $limbKey => $limbCurrentValue) {
            $normalizedKey = trim(strval($limbKey));
            if ($normalizedKey === '' || preg_match('/_max$/', $normalizedKey) === 1) {
                continue;
            }
            $maxKey = $normalizedKey . '_max';
            if (!array_key_exists($maxKey, $limbs)) {
                continue;
            }

            $current = $parseNonNegativeInt($limbCurrentValue, 0);
            $max = $parseNonNegativeInt($limbs[$maxKey], 0);
            if ($max <= 0) {
                continue;
            }
            if ($current > $max) {
                $current = $max;
            }
            $totalCurrent += $current;
            $totalMax += $max;
            $pairCount++;
        }

        if ($pairCount <= 0 || $totalMax <= 0) {
            return [
                'current' => 0,
                'max' => 0,
                'has_value' => false,
            ];
        }

        $ratio = floatval($totalCurrent) / floatval($totalMax);
        if ($ratio < 0.0) {
            $ratio = 0.0;
        } elseif ($ratio > 1.0) {
            $ratio = 1.0;
        }

        $derivedMax = 100;
        $derivedCurrent = intval(round($ratio * floatval($derivedMax)));
        if ($derivedCurrent < 0) {
            $derivedCurrent = 0;
        } elseif ($derivedCurrent > $derivedMax) {
            $derivedCurrent = $derivedMax;
        }

        return [
            'current' => $derivedCurrent,
            'max' => $derivedMax,
            'has_value' => true,
        ];
    };

    $bloodCurrentPick = $pickMixed($medical, ['blood', 'blood_current']);
    if ($bloodCurrentPick['source'] === '') {
        $bloodCurrentPick = $pickMixed($snapshot, ['blood', 'blood_current']);
    }
    $bloodMaxPick = $pickMixed($medical, ['max_blood', 'blood_max']);
    if ($bloodMaxPick['source'] === '') {
        $bloodMaxPick = $pickMixed($snapshot, ['max_blood', 'blood_max']);
    }
    $bloodData = $parseCurrentMax($bloodCurrentPick['value'], $bloodMaxPick['value'], 100, $parseNonNegativeInt);

    $hungerCurrentPick = $pickMixed($medical, ['hunger', 'hunger_current']);
    if ($hungerCurrentPick['source'] === '') {
        $hungerCurrentPick = $pickMixed($snapshot, ['hunger', 'hunger_current']);
    }
    $hungerMaxPick = $pickMixed($medical, ['hunger_max', 'max_hunger']);
    if ($hungerMaxPick['source'] === '') {
        $hungerMaxPick = $pickMixed($snapshot, ['hunger_max', 'max_hunger']);
    }
    $hungerData = $parseCurrentMax($hungerCurrentPick['value'], $hungerMaxPick['value'], 300, $parseNonNegativeInt);

    $bloodDerivedFromLimbs = false;
    if (!boolval($bloodData['has_value']) || intval($bloodData['max']) <= 0) {
        $derivedBlood = $deriveBloodFromLimbs($limbsPayload);
        if (boolval($derivedBlood['has_value'])) {
            $bloodData = $derivedBlood;
            $bloodDerivedFromLimbs = true;
        }
    }

    if (boolval($bloodData['has_value']) && intval($bloodData['max']) <= 0) {
        $bloodData['max'] = 100;
    }
    if (intval($bloodData['current']) < 0) {
        $bloodData['current'] = 0;
    }
    if (intval($bloodData['max']) > 0 && intval($bloodData['current']) > intval($bloodData['max'])) {
        $bloodData['current'] = intval($bloodData['max']);
    }

    $hasLimbs = count($limbsPayload) > 0;
    $hasVitals = boolval($bloodData['has_value']) || boolval($hungerData['has_value']);
    $hasMedical = $hasMedicalPayload || $hasLimbs || $hasVitals;
    $limbsJson = normalizeJsonString($limbsPayload);
    $bloodValue = strval(intval($bloodData['current'])) . '/' . strval(intval($bloodData['max']));
    $hungerValue = strval(intval($hungerData['current'])) . '/' . strval(intval($hungerData['max']));
    $statsPayload = $snapshot['stats'] ?? [];
    $statsCount = is_array($statsPayload) ? count($statsPayload) : 0;

    $missingFields = [];
    if ($faction === '') {
        $missingFields[] = 'faction';
    }
    if ($gender === '') {
        $missingFields[] = 'gender';
    }
    if (!$hasLimbs) {
        $missingFields[] = 'limbs';
    }
    if (!$hasVitals) {
        $missingFields[] = 'vitals';
    }
    if (count($missingFields) > 0) {
        stobeLogImport('Snapshot field gaps detected', [
            'name' => $name,
            'incoming_name' => $incomingName,
            'identity_match' => $matchedBy,
            'missing_fields' => $missingFields,
            'race_source' => strval($racePick['source']),
            'faction_source' => strval($factionPick['source']),
            'faction_id_source' => strval($factionIdPick['source']),
            'gender_source' => strval($genderPick['source']),
            'blood_sources' => [
                'current' => strval($bloodCurrentPick['source']),
                'max' => strval($bloodMaxPick['source']),
            ],
            'hunger_sources' => [
                'current' => strval($hungerCurrentPick['source']),
                'max' => strval($hungerMaxPick['source']),
            ],
            'snapshot_keys' => array_keys($snapshot),
            'medical_keys' => array_keys($medical),
        ], 'WARN');
    }
    if ($bloodDerivedFromLimbs) {
        stobeLogImport('Blood derived from limb payload', [
            'name' => $name,
            'derived_blood' => $bloodData,
            'blood_sources' => [
                'current' => strval($bloodCurrentPick['source']),
                'max' => strval($bloodMaxPick['source']),
            ],
        ], 'DEBUG');
    }

    $town = trim(strval($snapshot['town'] ?? ''));

    $characterState = strtolower(trim(strval($snapshot['character_state'] ?? 'normal')));
    $isSlave = false;
    if (array_key_exists('is_slave', $snapshot)) {
        $isSlave = coerceBoolean($snapshot['is_slave']);
    } elseif ($characterState === 'enslaved' || $characterState === 'escaped-slave') {
        $isSlave = true;
    }
    $equipmentSummary = '';
    $inventorySummary = '';
    $inventoryEntriesRaw = $snapshot['inventory'] ?? [];
    $inventoryEntries = [];
    if (is_array($inventoryEntriesRaw)) {
        $inventoryEntries = $inventoryEntriesRaw;
    } elseif (is_string($inventoryEntriesRaw) && trim($inventoryEntriesRaw) !== '') {
        $decodedInventoryEntries = json_decode($inventoryEntriesRaw, true);
        if (is_array($decodedInventoryEntries)) {
            $inventoryEntries = $decodedInventoryEntries;
        }
    }
    $inventoryCounts = [];
    $equipmentCounts = [];
    $resolveQualityLabelFromLevel = static function (int $level): string {
        if ($level >= 95) {
            return 'Masterwork';
        }
        if ($level >= 80) {
            return 'Specialist';
        }
        if ($level >= 60) {
            return 'High';
        }
        if ($level >= 40) {
            return 'Standard';
        }
        if ($level >= 20) {
            return 'Shoddy';
        }
        return 'Prototype';
    };
    $resolveEntryQuality = static function (array $entry) use ($resolveQualityLabelFromLevel): array {
        $qualityLabel = trim(strval(
            $entry['quality']
                ?? ($entry['quality_label']
                ?? ($entry['quality_name'] ?? ''))
        ));
        if ($qualityLabel !== '') {
            $qualityLabel = preg_replace('/\s+/u', ' ', $qualityLabel) ?? $qualityLabel;
            $qualityLabel = trim($qualityLabel);
        }

        $qualityLevel = -1;
        foreach (['quality_level', 'qualityLevel', 'level', 'gear_level'] as $qualityField) {
            if (!array_key_exists($qualityField, $entry)) {
                continue;
            }
            $rawQualityLevel = $entry[$qualityField];
            if (is_int($rawQualityLevel) || is_float($rawQualityLevel)) {
                $qualityLevel = intval(round(floatval($rawQualityLevel)));
                break;
            }
            if (is_string($rawQualityLevel)) {
                $text = trim($rawQualityLevel);
                if ($text === '' || preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $text) !== 1) {
                    continue;
                }
                $qualityLevel = intval(round(floatval($text)));
                break;
            }
        }
        if ($qualityLevel >= 0) {
            if ($qualityLevel > 100) {
                $qualityLevel = 100;
            }
            if ($qualityLabel === '') {
                $qualityLabel = $resolveQualityLabelFromLevel($qualityLevel);
            }
        } else {
            $qualityLevel = -1;
        }

        return [
            'label' => $qualityLabel,
            'level' => $qualityLevel,
        ];
    };
    $resolveEntryWeaponModel = static function (array $entry): string {
        $weaponModel = trim(strval(
            $entry['weapon_model']
                ?? ($entry['weaponModel']
                ?? ($entry['model'] ?? ''))
        ));
        if ($weaponModel === '') {
            return '';
        }
        $weaponModel = preg_replace('/\s+/u', ' ', $weaponModel) ?? $weaponModel;
        $weaponModel = trim($weaponModel);
        if ($weaponModel === '') {
            return '';
        }
        if (preg_match('/^\[(.*)\]$/u', $weaponModel, $matches) === 1) {
            $weaponModel = trim(strval($matches[1] ?? ''));
        }
        return $weaponModel;
    };
    $formatItemNameWithQuality = static function (string $name, array $entry): string {
        $trimmedName = trim($name);
        if ($trimmedName === '') {
            return '';
        }
        $tags = [];
        $qualityLabel = trim(strval($entry['quality'] ?? ''));
        if ($qualityLabel !== '') {
            $tags[] = $qualityLabel;
        }
        $weaponModel = trim(strval($entry['weapon_model'] ?? ''));
        if ($weaponModel !== '') {
            $alreadyPresent = false;
            foreach ($tags as $tag) {
                if (strcasecmp($tag, $weaponModel) === 0) {
                    $alreadyPresent = true;
                    break;
                }
            }
            if (!$alreadyPresent) {
                $tags[] = $weaponModel;
            }
        }
        if (count($tags) === 0) {
            return $trimmedName;
        }
        $out = $trimmedName;
        foreach ($tags as $tag) {
            $cleanTag = trim(strval($tag));
            if ($cleanTag === '') {
                continue;
            }
            if (stripos($out, '[' . $cleanTag . ']') !== false) {
                continue;
            }
            $out .= ' [' . $cleanTag . ']';
        }
        return $out;
    };
    $inventoryDescriptionCount = 0;
    if (is_array($inventoryEntries)) {
        foreach ($inventoryEntries as $entryKey => $entry) {
            if (!is_array($entry)) {
                $itemNameFromString = '';
                $itemCountFromScalar = 1;
                if (is_string($entryKey) && trim($entryKey) !== '') {
                    $itemNameFromString = trim($entryKey);
                    $itemCountFromScalar = $parseNonNegativeInt($entry, 1);
                    if ($itemCountFromScalar <= 0) {
                        $itemCountFromScalar = 1;
                    }
                } else {
                    $itemNameFromString = trim(strval($entry));
                }
                if ($itemNameFromString === '') {
                    continue;
                }
                $itemCountKey = strtolower($itemNameFromString);
                if (!array_key_exists($itemCountKey, $inventoryCounts)) {
                    $inventoryCounts[$itemCountKey] = [
                        'name' => $itemNameFromString,
                        'item_id' => '',
                        'quality' => '',
                        'quality_level' => -1,
                        'count' => 0,
                        'value_each' => null,
                    ];
                }
                $inventoryCounts[$itemCountKey]['count'] += $itemCountFromScalar;
                continue;
            }
            $itemName = trim(strval($entry['name'] ?? (is_string($entryKey) ? $entryKey : '')));
            if ($itemName === '') {
                continue;
            }
            $itemCountRaw = $entry['count'] ?? ($entry['quantity'] ?? 1);
            $itemCount = $parseNonNegativeInt($itemCountRaw, 1);
            if ($itemCount <= 0) {
                $itemCount = 1;
            }
            $itemDescription = stobeNormalizeItemDescriptionText(strval($entry['description'] ?? ''));
            if ($itemDescription !== '') {
                $inventoryDescriptionCount++;
            }
            $itemValueEach = 0;
            $itemValueEachPick = $pickMixed($entry, [
                'value_each',
                'value_single',
                'single_value',
                'unit_value',
                'avg_value',
                'item_value',
            ]);
            if ($itemValueEachPick['source'] !== '') {
                $itemValueEach = $parseNonNegativeInt($itemValueEachPick['value'], 0);
            } else {
                $itemValueTotalPick = $pickMixed($entry, [
                    'value_total',
                    'value',
                    'total_value',
                    'price_total',
                    'price',
                    'cost',
                    'sell_value',
                    'buy_value',
                ]);
                if ($itemValueTotalPick['source'] !== '') {
                    $itemValueTotal = $parseNonNegativeInt($itemValueTotalPick['value'], 0);
                    if ($itemValueTotal > 0) {
                        $itemValueEach = intval(round(floatval($itemValueTotal) / floatval(max(1, $itemCount))));
                    }
                }
            }
            $itemIdPick = $pickText($entry, ['item_id', 'itemId', 'string_id', 'stringid', 'sid', 'baseid', 'id']);
            $itemId = trim(strval($itemIdPick['value']));
            $entryQuality = $resolveEntryQuality($entry);
            $itemQualityLabel = trim(strval($entryQuality['label'] ?? ''));
            $itemQualityLevel = intval($entryQuality['level'] ?? -1);
            $itemWeaponModel = $resolveEntryWeaponModel($entry);
            $itemCountKeyId = $itemId !== '' ? strtolower($itemId) : strtolower($itemName);
            $qualityKey = $itemQualityLevel >= 0
                ? ('q:' . strval($itemQualityLevel))
                : ('q:' . strtolower($itemQualityLabel !== '' ? $itemQualityLabel : 'none'));
            $weaponModelKey = 'wm:' . strtolower($itemWeaponModel !== '' ? $itemWeaponModel : 'none');
            $itemCountKey = $itemCountKeyId . '|' . $qualityKey . '|' . $weaponModelKey;
            $isEquippedEntry = coerceBoolean($entry['equipped'] ?? ($entry['is_equipped'] ?? false));
            if ($isEquippedEntry) {
                if (!array_key_exists($itemCountKey, $equipmentCounts)) {
                    $equipmentCounts[$itemCountKey] = [
                        'name' => $itemName,
                        'item_id' => $itemId,
                        'description' => $itemDescription,
                        'quality' => $itemQualityLabel,
                        'quality_level' => $itemQualityLevel,
                        'weapon_model' => $itemWeaponModel,
                        'count' => 0,
                        'value_each' => null,
                    ];
                }
                $equipmentCounts[$itemCountKey]['count'] += $itemCount;
                if (trim(strval($equipmentCounts[$itemCountKey]['item_id'] ?? '')) === '' && $itemId !== '') {
                    $equipmentCounts[$itemCountKey]['item_id'] = $itemId;
                }
                if (trim(strval($equipmentCounts[$itemCountKey]['description'] ?? '')) === '' && $itemDescription !== '') {
                    $equipmentCounts[$itemCountKey]['description'] = $itemDescription;
                }
                if (trim(strval($equipmentCounts[$itemCountKey]['quality'] ?? '')) === '' && $itemQualityLabel !== '') {
                    $equipmentCounts[$itemCountKey]['quality'] = $itemQualityLabel;
                }
                if (intval($equipmentCounts[$itemCountKey]['quality_level'] ?? -1) < 0 && $itemQualityLevel >= 0) {
                    $equipmentCounts[$itemCountKey]['quality_level'] = $itemQualityLevel;
                }
                if (trim(strval($equipmentCounts[$itemCountKey]['weapon_model'] ?? '')) === '' && $itemWeaponModel !== '') {
                    $equipmentCounts[$itemCountKey]['weapon_model'] = $itemWeaponModel;
                }
                if (
                    $itemValueEach > 0 &&
                    (!isset($equipmentCounts[$itemCountKey]['value_each']) || intval($equipmentCounts[$itemCountKey]['value_each']) <= 0)
                ) {
                    $equipmentCounts[$itemCountKey]['value_each'] = $itemValueEach;
                }
                continue;
            }
            if (!array_key_exists($itemCountKey, $inventoryCounts)) {
                $inventoryCounts[$itemCountKey] = [
                    'name' => $itemName,
                    'item_id' => $itemId,
                    'description' => $itemDescription,
                    'quality' => $itemQualityLabel,
                    'quality_level' => $itemQualityLevel,
                    'weapon_model' => $itemWeaponModel,
                    'count' => 0,
                    'value_each' => null,
                ];
            }
            $inventoryCounts[$itemCountKey]['count'] += $itemCount;
            if (trim(strval($inventoryCounts[$itemCountKey]['item_id'] ?? '')) === '' && $itemId !== '') {
                $inventoryCounts[$itemCountKey]['item_id'] = $itemId;
            }
            if (trim(strval($inventoryCounts[$itemCountKey]['description'] ?? '')) === '' && $itemDescription !== '') {
                $inventoryCounts[$itemCountKey]['description'] = $itemDescription;
            }
            if (trim(strval($inventoryCounts[$itemCountKey]['quality'] ?? '')) === '' && $itemQualityLabel !== '') {
                $inventoryCounts[$itemCountKey]['quality'] = $itemQualityLabel;
            }
            if (intval($inventoryCounts[$itemCountKey]['quality_level'] ?? -1) < 0 && $itemQualityLevel >= 0) {
                $inventoryCounts[$itemCountKey]['quality_level'] = $itemQualityLevel;
            }
            if (trim(strval($inventoryCounts[$itemCountKey]['weapon_model'] ?? '')) === '' && $itemWeaponModel !== '') {
                $inventoryCounts[$itemCountKey]['weapon_model'] = $itemWeaponModel;
            }
            if (
                $itemValueEach > 0 &&
                (!isset($inventoryCounts[$itemCountKey]['value_each']) || intval($inventoryCounts[$itemCountKey]['value_each']) <= 0)
            ) {
                $inventoryCounts[$itemCountKey]['value_each'] = $itemValueEach;
            }
        }
    }
    stobeUpsertDescriptionsFromInventoryEntries($inventoryEntries, $snapshotSource, $name, $gamets);
    if (count($inventoryCounts) > 0) {
        $inventoryParts = [];
        foreach ($inventoryCounts as $entry) {
            $entryName = trim(strval($entry['name'] ?? ''));
            if ($entryName === '') {
                continue;
            }
            $entryDisplayName = $formatItemNameWithQuality($entryName, $entry);
            if ($entryDisplayName === '') {
                $entryDisplayName = $entryName;
            }
            $entryCount = intval($entry['count'] ?? 1);
            if ($entryCount <= 0) {
                $entryCount = 1;
            }
            $entryValueEach = intval($entry['value_each'] ?? 0);
            $inventoryEntryText = $entryDisplayName . ' x' . strval($entryCount);
            if ($entryValueEach > 0) {
                $inventoryEntryText .= ' value ' . strval($entryValueEach);
            }
            $inventoryParts[] = $inventoryEntryText;
            if (count($inventoryParts) >= 60) {
                break;
            }
        }
        $inventorySummary = implode(', ', $inventoryParts);
    }
    if ($isInventoryLiveSync) {
        stobeLogImport('Inventory live sync ingest', [
            'name' => $name,
            'source' => $snapshotSource,
            'inventory_entry_count' => count($inventoryEntries),
            'inventory_description_count' => $inventoryDescriptionCount,
            'inventory_summary_len' => strlen($inventorySummary),
            'gamets' => max(0, $gamets),
        ], 'DEBUG');
    }
    if (count($equipmentCounts) > 0) {
        $equipmentParts = [];
        foreach ($equipmentCounts as $entry) {
            $entryName = trim(strval($entry['name'] ?? ''));
            if ($entryName === '') {
                continue;
            }
            $entryDisplayName = $formatItemNameWithQuality($entryName, $entry);
            if ($entryDisplayName === '') {
                $entryDisplayName = $entryName;
            }
            $entryCount = intval($entry['count'] ?? 1);
            if ($entryCount <= 0) {
                $entryCount = 1;
            }
            $equipmentEntryText = $entryDisplayName . ' x' . strval($entryCount);
            $entryValueEach = intval($entry['value_each'] ?? 0);
            if ($entryValueEach > 0) {
                $equipmentEntryText .= ' value ' . strval($entryValueEach);
            }
            $equipmentParts[] = $equipmentEntryText;
            if (count($equipmentParts) >= 30) {
                break;
            }
        }
        $equipmentSummary = implode(', ', $equipmentParts);
    }
    if ($equipmentSummary === '') {
        $equipmentSummary = trim(strval($snapshot['equipment'] ?? ''));
    }

    $inventoryPromptItems = [];
    if (count($equipmentCounts) > 0) {
        foreach ($equipmentCounts as $entry) {
            $entryName = trim(strval($entry['name'] ?? ''));
            if ($entryName === '') {
                continue;
            }
            $entryCount = intval($entry['count'] ?? 1);
            if ($entryCount <= 0) {
                $entryCount = 1;
            }
            $inventoryPromptItems[] = [
                'name' => $entryName,
                'count' => $entryCount,
                'equipped' => true,
                'item_id' => trim(strval($entry['item_id'] ?? '')),
                'quality' => trim(strval($entry['quality'] ?? '')),
                'quality_level' => intval($entry['quality_level'] ?? -1),
                'weapon_model' => trim(strval($entry['weapon_model'] ?? '')),
                'description' => stobeNormalizeItemDescriptionText(strval($entry['description'] ?? '')),
                'value_each' => intval($entry['value_each'] ?? 0),
            ];
            if (count($inventoryPromptItems) >= 220) {
                break;
            }
        }
    }
    if (count($inventoryPromptItems) < 220 && count($inventoryCounts) > 0) {
        foreach ($inventoryCounts as $entry) {
            $entryName = trim(strval($entry['name'] ?? ''));
            if ($entryName === '') {
                continue;
            }
            $entryCount = intval($entry['count'] ?? 1);
            if ($entryCount <= 0) {
                $entryCount = 1;
            }
            $inventoryPromptItems[] = [
                'name' => $entryName,
                'count' => $entryCount,
                'equipped' => false,
                'item_id' => trim(strval($entry['item_id'] ?? '')),
                'quality' => trim(strval($entry['quality'] ?? '')),
                'quality_level' => intval($entry['quality_level'] ?? -1),
                'weapon_model' => trim(strval($entry['weapon_model'] ?? '')),
                'description' => stobeNormalizeItemDescriptionText(strval($entry['description'] ?? '')),
                'value_each' => intval($entry['value_each'] ?? 0),
            ];
            if (count($inventoryPromptItems) >= 220) {
                break;
            }
        }
    }

    $parseAppearanceBool = static function (array $source, array $keys): bool {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            return coerceBoolean($source[$key]);
        }
        return false;
    };
    $parseNormalizedFloat = static function (array $source, array $keys, float $fallback = 0.5): float {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $raw = $source[$key];
            if (is_int($raw) || is_float($raw)) {
                $value = floatval($raw);
            } elseif (is_string($raw)) {
                $text = trim($raw);
                if ($text === '' || preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $text) !== 1) {
                    continue;
                }
                $value = floatval($text);
            } else {
                continue;
            }
            if ($value < 0.0) {
                $value = 0.0;
            } elseif ($value > 1.0) {
                $value = 1.0;
            }
            return $value;
        }
        if ($fallback < 0.0) {
            return 0.0;
        }
        if ($fallback > 1.0) {
            return 1.0;
        }
        return $fallback;
    };
    $heightBucketFromNorm = static function (float $norm): string {
        if ($norm < 0.33) {
            return 'short';
        }
        if ($norm > 0.66) {
            return 'tall';
        }
        return 'average';
    };
    $ageBucketFromNorm = static function (float $norm): string {
        if ($norm < 0.33) {
            return 'young';
        }
        if ($norm > 0.66) {
            return 'old';
        }
        return 'middle-aged';
    };
    $buildAppearanceFallback = static function (
        string $race,
        string $gender,
        bool $hasBeard,
        bool $isShaved,
        bool $isFlayed,
        float $heightNorm,
        float $ageNorm
    ) use ($heightBucketFromNorm, $ageBucketFromNorm): string {
        $raceText = trim($race);
        if ($raceText === '' || strcasecmp($raceText, 'Unknown') === 0) {
            $raceText = '';
        }
        $genderText = strtolower(trim($gender));
        if ($genderText !== 'male' && $genderText !== 'female') {
            $genderText = '';
        }

        $identityParts = [];
        if ($genderText !== '') {
            $identityParts[] = $genderText;
        }
        if ($raceText !== '') {
            $identityParts[] = $raceText;
        }
        $identity = count($identityParts) > 0 ? implode(' ', $identityParts) : 'wasteland drifter';
        $identity = ucfirst($identity);

        $heightBucket = $heightBucketFromNorm($heightNorm);
        $ageBucket = $ageBucketFromNorm($ageNorm);
        $ageText = $ageBucket;
        if ($ageBucket === 'old') {
            $ageText = 'older';
        }

        $sentences = [];
        $sentences[] = $identity . ' with a ' . $ageText . ' look.';
        $sentences[] = 'Build appears ' . $heightBucket . '.';
        if ($hasBeard) {
            $sentences[] = 'Keeps visible facial hair.';
        }
        if ($isShaved) {
            $sentences[] = 'Head is shaved.';
        }
        if ($isFlayed) {
            $sentences[] = 'Body shows flayed skin and heavy scarring.';
        }
        return implode(' ', $sentences);
    };
    $appearanceHasBeard = $parseAppearanceBool($snapshot, ['has_beard', 'beard', 'is_bearded']);
    $appearanceIsShaved = $parseAppearanceBool($snapshot, ['is_shaved', 'shaved']);
    $appearanceIsFlayed = $parseAppearanceBool($snapshot, ['is_flayed', 'flayed']);
    $appearanceHeightNorm = $parseNormalizedFloat($snapshot, ['height_norm', 'height_0to1', 'height_normalized'], 0.5);
    $appearanceAgeNorm = $parseNormalizedFloat($snapshot, ['age_norm', 'age_0to1'], 0.5);
    $appearanceFallback = $buildAppearanceFallback(
        $race,
        $gender,
        $appearanceHasBeard,
        $appearanceIsShaved,
        $appearanceIsFlayed,
        $appearanceHeightNorm,
        $appearanceAgeNorm
    );
    $parseHornAverageNormalized = static function (mixed $value): ?float {
        if (is_int($value) || is_float($value)) {
            $normalized = floatval($value);
        } elseif (is_string($value)) {
            $text = trim($value);
            if ($text === '' || preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $text) !== 1) {
                return null;
            }
            $normalized = floatval($text);
        } else {
            return null;
        }
        if ($normalized > 1.0) {
            $normalized = $normalized / 100.0;
        }
        if ($normalized < 0.0) {
            $normalized = 0.0;
        } elseif ($normalized > 1.0) {
            $normalized = 1.0;
        }
        return $normalized;
    };
    $extractHornAverageNormalized = static function (array $source) use ($parseHornAverageNormalized): ?float {
        $hornPayload = $source['horn_sliders'] ?? null;
        if (is_string($hornPayload) && trim($hornPayload) !== '') {
            $decodedHornPayload = json_decode($hornPayload, true);
            if (is_array($decodedHornPayload)) {
                $hornPayload = $decodedHornPayload;
            }
        }
        if (!is_array($hornPayload)) {
            return null;
        }

        $averageValue = $parseHornAverageNormalized($hornPayload['average'] ?? null);
        if ($averageValue !== null) {
            return $averageValue;
        }

        $collected = [];
        foreach (['body', 'upper', 'lower'] as $hornKey) {
            $normalized = $parseHornAverageNormalized($hornPayload[$hornKey] ?? null);
            if ($normalized === null) {
                continue;
            }
            $collected[] = $normalized;
        }
        if (count($collected) === 0) {
            return null;
        }
        return array_sum($collected) / count($collected);
    };
    $buildShekHornAppearanceSentence = static function (?float $averageNormalized): string {
        if ($averageNormalized === null) {
            return '';
        }
        if ($averageNormalized >= 0.999) {
            return 'Their horns have been cut off.';
        }
        if ($averageNormalized <= 0.20) {
            return 'They have very large horns.';
        }
        if ($averageNormalized <= 0.40) {
            return 'They have large horns.';
        }
        if ($averageNormalized <= 0.60) {
            return 'They have average horns.';
        }
        if ($averageNormalized <= 0.80) {
            return 'They have small horns.';
        }
        return 'They have very small horns.';
    };
    $applyHornAppearanceSentence = static function (string $appearanceText, string $hornSentence): string {
        $appearanceText = trim($appearanceText);
        $hornSentence = trim($hornSentence);
        if ($hornSentence === '') {
            return $appearanceText;
        }

        $patterns = [
            '/\bThey have very large horns\.\s*/i',
            '/\bThey have large horns\.\s*/i',
            '/\bThey have average horns\.\s*/i',
            '/\bThey have small horns\.\s*/i',
            '/\bThey have very small horns\.\s*/i',
            '/\bTheir horns have been cut off\.\s*/i',
        ];
        $appearanceText = preg_replace($patterns, '', $appearanceText) ?? $appearanceText;
        $appearanceText = preg_replace('/\s{2,}/', ' ', trim($appearanceText)) ?? trim($appearanceText);
        if ($appearanceText === '') {
            return $hornSentence;
        }
        if (preg_match('/[.!?]$/', $appearanceText) !== 1) {
            $appearanceText .= '.';
        }
        return $appearanceText . ' ' . $hornSentence;
    };
    $appearance = '';
    $hornAverageNormalized = null;
    $hornAppearanceSentence = '';
    if (stripos($race, 'shek') !== false) {
        $hornAverageNormalized = $extractHornAverageNormalized($snapshot);
        $hornAppearanceSentence = $buildShekHornAppearanceSentence($hornAverageNormalized);
    }
    $forceAppearanceUpdate = false;

    $stats = $statsPayload;
    $skills = '';
    if (is_array($stats)) {
        $parseSkillInt = static function (mixed $value): int {
            if (is_int($value)) {
                return $value;
            }
            if (is_float($value)) {
                return intval($value);
            }
            if (is_string($value) && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', trim($value)) === 1) {
                return intval(floatval($value));
            }
            return 0;
        };

        $statAliases = [
            'melee_defense' => 'melee_defence',
            'martialarts' => 'martial_arts',
            'heavyweapons' => 'heavy_weapons',
            'weapon_smith' => 'weapon_smithing',
            'armor_smithing' => 'armour_smithing',
            'armour_smith' => 'armour_smithing',
            'bow_smith' => 'bow_smithing',
            'hivemedic' => 'hive_medic',
        ];
        foreach ($statAliases as $aliasKey => $canonicalKey) {
            if (array_key_exists($canonicalKey, $stats) || !array_key_exists($aliasKey, $stats)) {
                continue;
            }
            $stats[$canonicalKey] = $stats[$aliasKey];
        }

        $attributeMap = [
            'strength' => 'STR',
            'dexterity' => 'DEX',
            'toughness' => 'TGH',
            'perception' => 'PER',
        ];
        $combatMap = [
            'melee_attack' => 'MAtk',
            'melee_defence' => 'MDef',
            'dodge' => 'Dodge',
            'martial_arts' => 'MA',
            'katanas' => 'Katana',
            'sabres' => 'Sabre',
            'hackers' => 'Hacker',
            'heavy_weapons' => 'Heavy',
            'blunt' => 'Blunt',
            'polearms' => 'Polearm',
            'crossbows' => 'Crossbow',
            'turrets' => 'Turret',
            'athletics' => 'Athletics',
            'stealth' => 'Stealth',
            'assassination' => 'Assassin',
            'swimming' => 'Swim',
            'survival' => 'Survival',
        ];
        $coreMap = [
            'labouring' => 'Labour',
            'thieving' => 'Thieving',
            'lockpicking' => 'Lockpick',
            'medic' => 'Medic',
            'science' => 'Science',
            'engineering' => 'Engineer',
            'robotics' => 'Robotics',
            'farming' => 'Farming',
            'cooking' => 'Cooking',
            'weapon_smithing' => 'WpnSmith',
            'armour_smithing' => 'ArmSmith',
            'bow_smithing' => 'BowSmith',
            'hive_medic' => 'HiveMedic',
            'vet' => 'Vet',
        ];

        $attributeParts = [];
        foreach ($attributeMap as $key => $label) {
            if (!array_key_exists($key, $stats)) {
                continue;
            }
            $attributeParts[] = $label . ' ' . strval($parseSkillInt($stats[$key]));
        }

        $combatParts = [];
        foreach ($combatMap as $key => $label) {
            if (!array_key_exists($key, $stats)) {
                continue;
            }
            $combatParts[] = $label . ' ' . strval($parseSkillInt($stats[$key]));
        }

        $coreParts = [];
        foreach ($coreMap as $key => $label) {
            if (!array_key_exists($key, $stats)) {
                continue;
            }
            $coreParts[] = $label . ' ' . strval($parseSkillInt($stats[$key]));
        }

        $skillSections = [];
        if (count($attributeParts) > 0) {
            $skillSections[] = 'ATTR: ' . implode(' | ', $attributeParts);
        }
        if (count($combatParts) > 0) {
            $skillSections[] = 'COMBAT: ' . implode(', ', $combatParts);
        }
        if (count($coreParts) > 0) {
            $skillSections[] = 'CORE: ' . implode(', ', $coreParts);
        }
        $skills = implode(' || ', $skillSections);
    }

    $traderShopSourcesRaw = $snapshot['trader_shop_sources'] ?? [];
    $traderShopSources = [];
    if (is_array($traderShopSourcesRaw)) {
        $traderShopSources = $traderShopSourcesRaw;
    } elseif (is_string($traderShopSourcesRaw) && trim($traderShopSourcesRaw) !== '') {
        $decodedTraderShopSources = json_decode($traderShopSourcesRaw, true);
        if (is_array($decodedTraderShopSources)) {
            $traderShopSources = $decodedTraderShopSources;
        }
    }

    $traderShopSourceSummaries = [];
    $traderShopInventoryCounts = [];
    $traderShopInventoryTotalCount = 0;
    foreach ($traderShopSources as $source) {
        if (!is_array($source)) {
            continue;
        }

        $sourceName = trim(strval($source['name'] ?? ''));
        if ($sourceName === '') {
            $sourceName = 'shop storage';
        }
        $sourceSerial = $parseNonNegativeInt($source['serial'] ?? 0, 0);
        $sourceDist = 0.0;
        if (isset($source['dist']) && is_numeric($source['dist'])) {
            $sourceDist = floatval($source['dist']);
            if (!is_finite($sourceDist) || $sourceDist < 0.0) {
                $sourceDist = 0.0;
            }
        }
        $sourceBuildingClass = trim(strval($source['building_class'] ?? ''));
        $sourceSpecialFunction = trim(strval($source['special_function'] ?? ''));

        $sourceInventoryRaw = $source['inventory'] ?? [];
        $sourceInventory = [];
        if (is_array($sourceInventoryRaw)) {
            $sourceInventory = $sourceInventoryRaw;
        } elseif (is_string($sourceInventoryRaw) && trim($sourceInventoryRaw) !== '') {
            $decodedSourceInventory = json_decode($sourceInventoryRaw, true);
            if (is_array($decodedSourceInventory)) {
                $sourceInventory = $decodedSourceInventory;
            }
        }

        $sourceInventoryTotalCount = 0;
        foreach ($sourceInventory as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $itemName = trim(strval($entry['name'] ?? ''));
            if ($itemName === '') {
                continue;
            }
            $itemCount = $parseNonNegativeInt($entry['count'] ?? ($entry['quantity'] ?? 1), 1);
            if ($itemCount <= 0) {
                $itemCount = 1;
            }
            $itemId = trim(strval(
                $entry['item_id']
                    ?? ($entry['itemId']
                    ?? ($entry['string_id']
                    ?? ($entry['stringid']
                    ?? ($entry['sid']
                    ?? ($entry['baseid']
                    ?? ($entry['id'] ?? ''))))))
            ));
            $itemDescription = stobeNormalizeItemDescriptionText(strval($entry['description'] ?? ''));
            $entryQuality = $resolveEntryQuality($entry);
            $itemQualityLabel = trim(strval($entryQuality['label'] ?? ''));
            $itemQualityLevel = intval($entryQuality['level'] ?? -1);
            $itemWeaponModel = $resolveEntryWeaponModel($entry);
            $itemValueEach = $parseNonNegativeInt(
                $entry['value_each'] ?? ($entry['value_single'] ?? ($entry['value'] ?? 0)),
                0
            );

            $qualityKey = $itemQualityLevel >= 0
                ? ('q:' . strval($itemQualityLevel))
                : ('q:' . strtolower($itemQualityLabel !== '' ? $itemQualityLabel : 'none'));
            $weaponModelKey = 'wm:' . strtolower($itemWeaponModel !== '' ? $itemWeaponModel : 'none');
            $itemKey = strtolower($itemId !== '' ? ('id:' . $itemId) : ('name:' . $itemName)) . '|' . $qualityKey . '|' . $weaponModelKey;
            if (!array_key_exists($itemKey, $traderShopInventoryCounts)) {
                $traderShopInventoryCounts[$itemKey] = [
                    'name' => $itemName,
                    'count' => 0,
                    'equipped' => false,
                    'item_id' => $itemId,
                    'quality' => $itemQualityLabel,
                    'quality_level' => $itemQualityLevel,
                    'weapon_model' => $itemWeaponModel,
                    'description' => $itemDescription,
                    'value_each' => $itemValueEach,
                ];
            }
            $traderShopInventoryCounts[$itemKey]['count'] += $itemCount;
            if (trim(strval($traderShopInventoryCounts[$itemKey]['item_id'] ?? '')) === '' && $itemId !== '') {
                $traderShopInventoryCounts[$itemKey]['item_id'] = $itemId;
            }
            if (
                trim(strval($traderShopInventoryCounts[$itemKey]['description'] ?? '')) === '' &&
                $itemDescription !== ''
            ) {
                $traderShopInventoryCounts[$itemKey]['description'] = $itemDescription;
            }
            if (
                trim(strval($traderShopInventoryCounts[$itemKey]['quality'] ?? '')) === '' &&
                $itemQualityLabel !== ''
            ) {
                $traderShopInventoryCounts[$itemKey]['quality'] = $itemQualityLabel;
            }
            if (
                intval($traderShopInventoryCounts[$itemKey]['quality_level'] ?? -1) < 0 &&
                $itemQualityLevel >= 0
            ) {
                $traderShopInventoryCounts[$itemKey]['quality_level'] = $itemQualityLevel;
            }
            if (
                trim(strval($traderShopInventoryCounts[$itemKey]['weapon_model'] ?? '')) === '' &&
                $itemWeaponModel !== ''
            ) {
                $traderShopInventoryCounts[$itemKey]['weapon_model'] = $itemWeaponModel;
            }
            if (
                intval($traderShopInventoryCounts[$itemKey]['value_each'] ?? 0) <= 0 &&
                $itemValueEach > 0
            ) {
                $traderShopInventoryCounts[$itemKey]['value_each'] = $itemValueEach;
            }
            $sourceInventoryTotalCount += $itemCount;
            $traderShopInventoryTotalCount += $itemCount;
            if (count($traderShopInventoryCounts) >= 260) {
                break;
            }
        }

        $sourceItemCountFromPayload = $parseNonNegativeInt(
            $source['inventory_item_count'] ?? $sourceInventoryTotalCount,
            $sourceInventoryTotalCount
        );
        if ($sourceItemCountFromPayload <= 0) {
            $sourceItemCountFromPayload = $sourceInventoryTotalCount;
        }

        $traderShopSourceSummaries[] = [
            'name' => $sourceName,
            'serial' => $sourceSerial,
            'dist' => $sourceDist,
            'building_class' => $sourceBuildingClass,
            'special_function' => $sourceSpecialFunction,
            'inventory_item_count' => $sourceItemCountFromPayload,
        ];
        if (count($traderShopSourceSummaries) >= 8) {
            break;
        }
    }

    $snapshotTraderInventoryItemsRaw = $snapshot['trader_inventory_items'] ?? [];
    $snapshotTraderInventoryItems = [];
    if (is_array($snapshotTraderInventoryItemsRaw)) {
        $snapshotTraderInventoryItems = $snapshotTraderInventoryItemsRaw;
    } elseif (is_string($snapshotTraderInventoryItemsRaw) && trim($snapshotTraderInventoryItemsRaw) !== '') {
        $decodedSnapshotTraderInventoryItems = json_decode($snapshotTraderInventoryItemsRaw, true);
        if (is_array($decodedSnapshotTraderInventoryItems)) {
            $snapshotTraderInventoryItems = $decodedSnapshotTraderInventoryItems;
        }
    }
    $snapshotTraderInventoryEntryCount = 0;
    foreach ($snapshotTraderInventoryItems as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $entryName = trim(strval($entry['name'] ?? ''));
        if ($entryName === '') {
            continue;
        }
        $snapshotTraderInventoryEntryCount++;
    }

    $traderShopSourceCount = count($traderShopSourceSummaries);
    $traderShopItemCountFromSnapshot = $parseNonNegativeInt(
        $snapshot['trader_shop_item_count'] ?? $traderShopInventoryTotalCount,
        $traderShopInventoryTotalCount
    );
    $traderShopItemCount = max($traderShopInventoryTotalCount, $traderShopItemCountFromSnapshot);
    $isLikelyTraderContext = coerceBoolean($snapshot['is_trader'] ?? false) ||
        $traderShopSourceCount > 0 || $snapshotTraderInventoryEntryCount > 0;

    $factionForOccupation = baseNameWithoutBracketSuffix($faction);
    if ($factionForOccupation === '' && $faction !== '') {
        $factionForOccupation = $faction;
    }

    $occupationParts = [];
    if ($isLikelyTraderContext) {
        $occupationParts[] = 'Trader';
    }
    if (coerceBoolean($snapshot['is_leader'] ?? false)) {
        $occupationParts[] = 'Leader';
    }
    $occupationFactionText = stobeBuildFactionOccupationText($factionForOccupation);
    if ($factionForOccupation !== '') {
        $occupationParts[] = $occupationFactionText !== '' ? $occupationFactionText : ('A member of the ' . $factionForOccupation . ' Faction');
    }
    if ($town !== '') {
        $occupationParts[] = 'Town: ' . $town;
    }
    $occupationDefault = implode(' | ', $occupationParts);

    $personalityDefault = 'Pragmatic wasteland survivor.';
    if ($characterState === 'dead') {
        $personalityDefault = 'No longer active.';
    } elseif ($characterState === 'imprisoned') {
        $personalityDefault = 'Currently imprisoned and cautious.';
    } elseif ($characterState === 'enslaved' || $characterState === 'escaped-slave') {
        $personalityDefault = 'Marked by hardship and survival instincts.';
    } elseif ($characterState === 'unconscious') {
        $personalityDefault = 'Incapacitated at the moment.';
    }

    $backstoryParts = [];
    if ($race !== '') {
        $backstoryParts[] = $race;
    }
    if ($faction !== '') {
        $backstoryParts[] = 'aligned with ' . $faction;
    }
    if ($town !== '') {
        $backstoryParts[] = 'seen around ' . $town;
    }
    $backstoryDefault = count($backstoryParts) > 0
        ? ('Observed in Kenshi: ' . implode(', ', $backstoryParts) . '.')
        : 'Observed in Kenshi world state.';

    $goalsDefault = $isPlayerCharacter
        ? 'Lead the squad and survive the wasteland.'
        : 'Survive, recover, and pursue faction priorities.';
    $speechstyleDefault = 'Direct and practical.';
    $originalNameForTraits = trim(strval($snapshot['original_name'] ?? ''));
    $traitSelection = selectBioTraitsForNpc($name, $race, $gender, $faction, $originalNameForTraits);
    $resolvedTraits = is_array($traitSelection['traits'] ?? null) ? $traitSelection['traits'] : [];
    $traitSources = is_array($traitSelection['sources'] ?? null) ? $traitSelection['sources'] : [];
    $personality = trim(strval($resolvedTraits['personality'] ?? ''));
    if ($personality === '') {
        $personality = $personalityDefault;
    }
    $backstory = trim(strval($resolvedTraits['backstory'] ?? ''));
    if ($backstory === '') {
        $backstory = $backstoryDefault;
    }
    $speechstyle = trim(strval($resolvedTraits['speechstyle'] ?? ''));
    if ($speechstyle === '') {
        $speechstyle = $speechstyleDefault;
    }
    $occupation = trim(strval($resolvedTraits['occupation'] ?? ''));
    if ($occupation === '') {
        $occupation = $occupationDefault;
    }
    if (
        strval($traitSources['occupation'] ?? 'default') === 'default' &&
        $factionForOccupation !== '' &&
        $occupationFactionText !== '' &&
        stripos($occupation, $factionForOccupation) === false
    ) {
        $occupation = trim($occupation) !== ''
            ? ($occupation . ' | ' . $occupationFactionText)
            : $occupationFactionText;
    }
    $occupation = stobeNormalizeOccupationText($occupation, $factionForOccupation);
    $goals = trim(strval($resolvedTraits['goals'] ?? ''));
    if ($goals === '') {
        $goals = $goalsDefault;
    } elseif (
        strval($traitSources['goals'] ?? 'default') === 'default' &&
        stripos($goals, 'survive') === false
    ) {
        $goals .= ' | ' . $goalsDefault;
    }
    $appearanceTrait = normalizeBioAppearanceExtra(trim(strval($resolvedTraits['appearance'] ?? '')));
    $appearance = appendBioAppearanceExtrasToText($appearanceFallback, $appearanceTrait !== '' ? [$appearanceTrait] : []);
    $emoteMoods = stobeResolveGlobalEmoteMoods();
    if ($characterState !== 'normal' && $characterState !== '') {
        $emoteMoods .= ',state:' . $characterState;
    }

    $snapshotExtendedPayload = normalizeCoreNpcExtendedData([
        'environment' => $snapshot['environment'] ?? [],
        'nearby_actors' => $snapshot['nearby'] ?? [],
        'nearby_items' => $snapshot['nearby_items'] ?? [],
        'points_of_interest' => $snapshot['points_of_interest'] ?? [],
        'trader_shop_sources' => $traderShopSourceSummaries,
        'trader_shop_source_count' => $traderShopSourceCount,
        'trader_shop_item_count' => $traderShopItemCount,
        'player_base' => function_exists('stobeNormalizePlayerBaseSnapshot')
            ? stobeNormalizePlayerBaseSnapshot($snapshot['player_base'] ?? [], true)
            : [],
    ]);

    if ($storageId !== '') {
        $snapshot['storage_id'] = $storageId;
    }
    $metadataForStorage = normalizeCoreNpcMetadata($snapshot);
    if ($storageId !== '' && !array_key_exists('storage_id', $metadataForStorage)) {
        $metadataForStorage['storage_id'] = $storageId;
    }
    // Cats balance is deal evidence. Stamp every observation so verification can
    // compare readings taken after a transfer rather than trusting a stale value.
    if (array_key_exists('money', $metadataForStorage) && is_numeric($metadataForStorage['money'])) {
        $metadataForStorage['money'] = max(0, intval($metadataForStorage['money']));
        $metadataForStorage['money_observed_at'] = time();
        $metadataForStorage['money_observed_gamets'] = max(0, $gamets);
    } else {
        unset($metadataForStorage['money']);
    }
    $existingMasterMetadata = [];
    $existingMasterRow = $db->fetchOne(
        "SELECT metadata
                , appearance
         FROM core_npc_master
         WHERE LOWER(name) = LOWER($1)
         LIMIT 1",
        [$name]
    );
    $existingMasterAppearance = '';
    if ($existingMasterRow) {
        $existingMasterMetadata = normalizeCoreNpcMetadata($existingMasterRow['metadata'] ?? []);
        $existingMasterAppearance = trim(strval($existingMasterRow['appearance'] ?? ''));
    }
    if (!$existingMasterRow && isset($traitSelection['tts_filter_preset']) && !array_key_exists('tts_filter_preset', $metadataForStorage)) {
        $metadataForStorage['tts_filter_preset'] = $traitSelection['tts_filter_preset'];
    }
    $existingAppearanceShowsCutHorns = stripos($existingMasterAppearance, 'Their horns have been cut off.') !== false;
    $existingHornsCut = coerceBoolean($existingMasterMetadata['horns_cut'] ?? false) || $existingAppearanceShowsCutHorns;
    if (
        stripos($race, 'shek') !== false &&
        $hornAverageNormalized !== null &&
        $hornAverageNormalized >= 0.999
    ) {
        $existingHornsCut = true;
    }
    if (stripos($race, 'shek') !== false && $existingHornsCut) {
        $metadataForStorage['horns_cut'] = true;
        if ($hornAverageNormalized === null || $hornAverageNormalized < 0.999) {
            stobeLogImport('Preserving stored cut-horns appearance state', [
                'name' => $name,
                'gamets' => max(0, $gamets),
                'incoming_horn_average_normalized' => $hornAverageNormalized,
                'existing_metadata_horns_cut' => coerceBoolean($existingMasterMetadata['horns_cut'] ?? false),
                'existing_appearance_has_cut_horns' => $existingAppearanceShowsCutHorns,
                'source' => $snapshotSource,
            ], 'DEBUG');
        }
        $hornAverageNormalized = 1.0;
        $hornAppearanceSentence = $buildShekHornAppearanceSentence($hornAverageNormalized);
    }

    $preservedMetadataKeys = ['portrait', 'portrait_url', 'portrait_path', 'profile_assignment_source'];
    $preservedMetadataApplied = [];
    foreach ($preservedMetadataKeys as $preservedKey) {
        if (
            !array_key_exists($preservedKey, $metadataForStorage) &&
            array_key_exists($preservedKey, $existingMasterMetadata)
        ) {
            $metadataForStorage[$preservedKey] = $existingMasterMetadata[$preservedKey];
            $preservedMetadataApplied[] = $preservedKey;
        }
    }
    if (count($preservedMetadataApplied) > 0) {
        stobeLogImport('Snapshot preserved portrait metadata', [
            'name' => $name,
            'keys' => $preservedMetadataApplied,
            'gamets' => max(0, $gamets),
            'source' => $snapshotSource,
        ], 'DEBUG');
    }

    $preservedCarryKeys = ['is_carrying', 'carrying_target_name', 'is_being_carried', 'carried_by_name'];
    $preservedCarryApplied = [];
    foreach ($preservedCarryKeys as $preservedKey) {
        if (
            !array_key_exists($preservedKey, $metadataForStorage) &&
            array_key_exists($preservedKey, $existingMasterMetadata)
        ) {
            $metadataForStorage[$preservedKey] = $existingMasterMetadata[$preservedKey];
            $preservedCarryApplied[] = $preservedKey;
        }
    }
    if (count($preservedCarryApplied) > 0) {
        stobeLogImport('Snapshot preserved carry metadata', [
            'name' => $name,
            'keys' => $preservedCarryApplied,
            'gamets' => max(0, $gamets),
            'source' => $snapshotSource,
        ], 'DEBUG');
    }

    // Live inventory syncs do not carry money; keep the last observed balance and its stamp.
    if (!array_key_exists('money', $metadataForStorage)) {
        foreach (['money', 'money_observed_at', 'money_observed_gamets'] as $preservedKey) {
            if (array_key_exists($preservedKey, $existingMasterMetadata)) {
                $metadataForStorage[$preservedKey] = $existingMasterMetadata[$preservedKey];
            }
        }
    }

    $hasIncomingActivityState = array_key_exists('current_action', $metadataForStorage)
        || array_key_exists('is_in_combat', $metadataForStorage)
        || array_key_exists('is_attacking', $metadataForStorage);
    if ($isInventoryLiveSync) {
        foreach (['character_state', 'is_incapacitated'] as $preservedKey) {
            if (
                !array_key_exists($preservedKey, $metadataForStorage)
                && array_key_exists($preservedKey, $existingMasterMetadata)
            ) {
                $metadataForStorage[$preservedKey] = $existingMasterMetadata[$preservedKey];
            }
        }
    }
    if ($isInventoryLiveSync && !$hasIncomingActivityState) {
        $preservedActivityKeys = [
            'current_action',
            'is_moving',
            'is_running',
            'is_sneaking',
            'is_in_combat',
            'is_attacking',
            'movement_speed',
            'attack_target',
        ];
        foreach ($preservedActivityKeys as $preservedKey) {
            if (array_key_exists($preservedKey, $existingMasterMetadata)) {
                $metadataForStorage[$preservedKey] = $existingMasterMetadata[$preservedKey];
            }
        }
    }

    $shouldPreserveTraderMetadata =
        $snapshotTraderInventoryEntryCount <= 0 &&
        $traderShopSourceCount <= 0 &&
        !array_key_exists('trader_inventory_items', $metadataForStorage) &&
        !array_key_exists('trader_shop_sources', $metadataForStorage);
    if ($shouldPreserveTraderMetadata) {
        $preservedTraderKeys = [
            'is_trader',
            'trader_inventory_items',
            'trader_inventory_item_count',
            'trader_inventory_snapshot_gamets',
            'trader_shop_sources',
            'trader_shop_source_count',
            'trader_shop_item_count',
        ];
        $preservedApplied = [];
        foreach ($preservedTraderKeys as $preservedKey) {
            if (
                !array_key_exists($preservedKey, $metadataForStorage) &&
                array_key_exists($preservedKey, $existingMasterMetadata)
            ) {
                $metadataForStorage[$preservedKey] = $existingMasterMetadata[$preservedKey];
                $preservedApplied[] = $preservedKey;
            }
        }
        if (count($preservedApplied) > 0) {
            stobeLogImport('Snapshot preserved trader metadata', [
                'name' => $name,
                'keys' => $preservedApplied,
                'gamets' => max(0, $gamets),
                'source' => $snapshotSource,
            ], 'DEBUG');
        }
    }
    if (count($inventoryPromptItems) > 0) {
        $metadataForStorage['inventory_items'] = $inventoryPromptItems;
    }
    if ($snapshotTraderInventoryEntryCount > 0) {
        $metadataForStorage['trader_inventory_items'] = $snapshotTraderInventoryItems;
        if (!array_key_exists('trader_inventory_item_count', $metadataForStorage)) {
            $metadataForStorage['trader_inventory_item_count'] = $snapshotTraderInventoryEntryCount;
        }
        if (!array_key_exists('trader_inventory_snapshot_gamets', $metadataForStorage)) {
            $metadataForStorage['trader_inventory_snapshot_gamets'] = max(0, $gamets);
        }
    }
    if ($traderShopSourceCount > 0) {
        $metadataForStorage['trader_shop_sources'] = $traderShopSourceSummaries;
        $metadataForStorage['trader_shop_source_count'] = $traderShopSourceCount;
        $metadataForStorage['trader_shop_item_count'] = $traderShopItemCount;
    }
    if ($hornAppearanceSentence !== '') {
        $hornAppearanceBase = $appearance !== '' ? $appearance : $existingMasterAppearance;
        $appearance = $applyHornAppearanceSentence($hornAppearanceBase, $hornAppearanceSentence);
        $forceAppearanceUpdate = true;
        stobeLogImport('Horn appearance sentence applied', [
            'name' => $name,
            'gamets' => max(0, $gamets),
            'horn_average_normalized' => $hornAverageNormalized,
            'horn_sentence' => $hornAppearanceSentence,
            'appearance_before' => $hornAppearanceBase,
            'appearance_after' => $appearance,
            'source' => $snapshotSource,
        ], 'DEBUG');
    }
    if ($appearanceTrait !== '') {
        $forceAppearanceUpdate = true;
    }
    if ($isLikelyTraderContext) {
        $mergeTraderEntry = static function (array &$bucket, array $entry) use ($resolveEntryWeaponModel): void {
            $entryName = trim(strval($entry['name'] ?? ''));
            if ($entryName === '') {
                return;
            }
            $entryCount = intval($entry['count'] ?? 1);
            if ($entryCount <= 0) {
                $entryCount = 1;
            }
            $entryItemId = trim(strval($entry['item_id'] ?? ''));
            $entryDescription = stobeNormalizeItemDescriptionText(strval($entry['description'] ?? ''));
            $entryQualityLabel = trim(strval(
                $entry['quality']
                    ?? ($entry['quality_label']
                    ?? ($entry['quality_name'] ?? ''))
            ));
            $entryQualityLevel = intval($entry['quality_level'] ?? ($entry['qualityLevel'] ?? -1));
            if ($entryQualityLevel > 100) {
                $entryQualityLevel = 100;
            }
            if ($entryQualityLevel < 0) {
                $entryQualityLevel = -1;
            }
            $entryWeaponModel = $resolveEntryWeaponModel($entry);
            $entryValueEach = intval($entry['value_each'] ?? 0);
            if ($entryValueEach < 0) {
                $entryValueEach = 0;
            }

            $qualityKey = $entryQualityLevel >= 0
                ? ('q:' . strval($entryQualityLevel))
                : ('q:' . strtolower($entryQualityLabel !== '' ? $entryQualityLabel : 'none'));
            $weaponModelKey = 'wm:' . strtolower($entryWeaponModel !== '' ? $entryWeaponModel : 'none');
            $entryKey = strtolower($entryItemId !== '' ? ('id:' . $entryItemId) : ('name:' . $entryName)) . '|' . $qualityKey . '|' . $weaponModelKey;
            if (!array_key_exists($entryKey, $bucket)) {
                $bucket[$entryKey] = [
                    'name' => $entryName,
                    'count' => 0,
                    'equipped' => false,
                    'item_id' => $entryItemId,
                    'quality' => $entryQualityLabel,
                    'quality_level' => $entryQualityLevel,
                    'weapon_model' => $entryWeaponModel,
                    'description' => $entryDescription,
                    'value_each' => $entryValueEach,
                ];
            }

            $bucket[$entryKey]['count'] += $entryCount;
            if (trim(strval($bucket[$entryKey]['item_id'] ?? '')) === '' && $entryItemId !== '') {
                $bucket[$entryKey]['item_id'] = $entryItemId;
            }
            if (
                trim(strval($bucket[$entryKey]['description'] ?? '')) === '' &&
                $entryDescription !== ''
            ) {
                $bucket[$entryKey]['description'] = $entryDescription;
            }
            if (
                trim(strval($bucket[$entryKey]['quality'] ?? '')) === '' &&
                $entryQualityLabel !== ''
            ) {
                $bucket[$entryKey]['quality'] = $entryQualityLabel;
            }
            if (intval($bucket[$entryKey]['quality_level'] ?? -1) < 0 && $entryQualityLevel >= 0) {
                $bucket[$entryKey]['quality_level'] = $entryQualityLevel;
            }
            if (
                trim(strval($bucket[$entryKey]['weapon_model'] ?? '')) === '' &&
                $entryWeaponModel !== ''
            ) {
                $bucket[$entryKey]['weapon_model'] = $entryWeaponModel;
            }
            if (
                intval($bucket[$entryKey]['value_each'] ?? 0) <= 0 &&
                $entryValueEach > 0
            ) {
                $bucket[$entryKey]['value_each'] = $entryValueEach;
            }
        };

        $traderInventoryByKey = [];
        $traderInventoryItems = [];
        $traderInventoryCount = 0;
        // Prefer the plugin's direct merchant snapshot; older payloads use the legacy reconstruction.
        if ($snapshotTraderInventoryEntryCount > 0) {
            foreach ($snapshotTraderInventoryItems as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $mergeTraderEntry($traderInventoryByKey, $entry);
            }
        } else {
            foreach ($inventoryPromptItems as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (coerceBoolean($entry['equipped'] ?? false)) {
                    continue;
                }
                $mergeTraderEntry($traderInventoryByKey, $entry);
            }
            foreach ($traderShopInventoryCounts as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $mergeTraderEntry($traderInventoryByKey, $entry);
            }
        }

        foreach ($traderInventoryByKey as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $traderInventoryItems[] = $entry;
            $entryCount = intval($entry['count'] ?? 1);
            if ($entryCount <= 0) {
                $entryCount = 1;
            }
            $traderInventoryCount += $entryCount;
            if (count($traderInventoryItems) >= 260) {
                break;
            }
        }

        // Fallback: if slot classification is unavailable, keep the full snapshot list.
        if (count($traderInventoryItems) === 0 && count($inventoryPromptItems) > 0) {
            $traderInventoryItems = $inventoryPromptItems;
            $traderInventoryCount = 0;
            foreach ($traderInventoryItems as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $entryCount = intval($entry['count'] ?? 1);
                if ($entryCount <= 0) {
                    $entryCount = 1;
                }
                $traderInventoryCount += $entryCount;
            }
        }
        if ($traderInventoryCount <= 0) {
            $traderInventoryCount = $traderShopItemCount;
        }

        $metadataForStorage['trader_inventory_items'] = $traderInventoryItems;
        $metadataForStorage['trader_inventory_item_count'] = $traderInventoryCount;
        $metadataForStorage['trader_inventory_snapshot_gamets'] = max(0, $gamets);
    }
    storeNpcProfile($name, [
        'race' => $race,
        'faction' => $faction,
        'gender' => $gender,
        'appearance' => $appearance,
        'equipment' => $equipmentSummary,
        'inventory' => $inventorySummary,
        'occupation' => $occupation,
        'skills' => $skills,
        'speechstyle' => $speechstyle,
        'goals' => $goals,
        'personality' => $personality,
        'backstory' => $backstory,
        'emote_moods' => $emoteMoods,
        'bounty' => $bountyPayload,
        'is_animal' => $isAnimal,
        'tags' => $tags,
        'metadata' => $metadataForStorage,
        'extended_data' => $snapshotExtendedPayload,
    ], [
        'skip_history' => true,
        'defer_voice_assignment' => true,
        'force_update_appearance' => $forceAppearanceUpdate,
    ]);

    $metadataJson = normalizeJsonString($metadataForStorage);
    $extendedDataJson = normalizeJsonString($snapshotExtendedPayload);
    $bountyJson = stobeNormalizeBountyJsonString($bountyPayload);
    $safeGamets = max(0, $gamets);
    $ruleProfileId = stobeResolveProfileIdFromImportRules($name, $race, $gender, $faction);
    $selectedProfileId = $ruleProfileId > 0 ? $ruleProfileId : getDefaultNpcProfileId();
    $selectedProfileIdOrNull = $selectedProfileId > 0 ? $selectedProfileId : null;
    if ($ruleProfileId > 0 && strtolower(trim(strval($metadataForStorage['profile_assignment_source'] ?? ''))) !== 'manual') {
        $metadataForStorage['profile_assignment_source'] = 'rule';
        $metadataJson = normalizeJsonString($metadataForStorage);
    }

    $preservedSnapshotExtendedDataSql = stobeRelationshipPreservingExtendedDataSql(
        '$10::jsonb',
        'core_npc_master.extended_data'
    );
    $result = $db->exec(
        "INSERT INTO core_npc_master (
            name,
            race,
            faction,
            gender,
            profile_id,
            is_animal,
            is_slave,
            metadata,
            gamets_last_updated,
            extended_data,
            tags,
            bounty,
            equipment,
            inventory,
            limbs,
            blood,
            hunger,
            updated_at
        ) VALUES (
            $1, $2, $3, $4, $5, $6, $7, $8::jsonb, $9, $10::jsonb, $11, $12::jsonb,
            $13, $14, $15::jsonb, $16, $17,
            NOW()
        )
        ON CONFLICT (name) DO UPDATE SET
            race = COALESCE(NULLIF($2, ''), core_npc_master.race),
            faction = COALESCE(NULLIF($3, ''), core_npc_master.faction),
            gender = COALESCE(NULLIF($4, ''), core_npc_master.gender),
            profile_id = COALESCE(core_npc_master.profile_id, EXCLUDED.profile_id),
            is_animal = $6,
            is_slave = $7,
            metadata = ($8::jsonb - 'tts_filter_preset') || CASE
                WHEN core_npc_master.metadata ? 'tts_filter_preset'
                THEN jsonb_build_object('tts_filter_preset', core_npc_master.metadata->'tts_filter_preset')
                ELSE '{}'::jsonb END,
            gamets_last_updated = CASE
                WHEN $9 > core_npc_master.gamets_last_updated THEN $9
                ELSE core_npc_master.gamets_last_updated
            END,
            extended_data = CASE
                WHEN $10::jsonb = '{}'::jsonb OR $10::jsonb = '[]'::jsonb THEN core_npc_master.extended_data
                ELSE " . $preservedSnapshotExtendedDataSql . "
            END,
            tags = CASE
                WHEN NULLIF($11, '') IS NOT NULL THEN $11
                ELSE ''
            END,
            bounty = CASE
                WHEN $12::jsonb <> '{}'::jsonb THEN $12::jsonb
                ELSE COALESCE(core_npc_master.bounty, '{}'::jsonb)
            END,
            equipment = CASE
                WHEN NULLIF($13, '') IS NOT NULL THEN $13
                ELSE COALESCE(core_npc_master.equipment, '')
            END,
            inventory = CASE
                WHEN $20 THEN $14
                WHEN NULLIF($14, '') IS NOT NULL THEN $14
                ELSE COALESCE(core_npc_master.inventory, '')
            END,
            limbs = CASE WHEN $18 THEN $15::jsonb ELSE core_npc_master.limbs END,
            blood = CASE WHEN $19 THEN $16 ELSE core_npc_master.blood END,
            hunger = CASE WHEN $19 THEN $17 ELSE core_npc_master.hunger END,
            updated_at = NOW()",
        [
            $name, $race, $faction, $gender, $selectedProfileIdOrNull, $isAnimal, $isSlave, $metadataJson, $safeGamets, $extendedDataJson, $tags, $bountyJson,
            $equipmentSummary, $inventorySummary, $limbsJson, $bloodValue, $hungerValue, $hasLimbs, $hasVitals, $isInventoryLiveSync
        ]
    );

    if ($result === false) {
        stobeLogImport('Snapshot core_npc_master upsert failed', [
            'name' => $name,
            'storage_id' => normalizeStorageIdToken($metadataForStorage['storage_id'] ?? ''),
            'gamets' => $safeGamets,
            'db_error' => $db->GetLastError(),
        ], 'WARN');
        return false;
    }

    stobeUpsertLocationZoneFromSnapshot($snapshot, $safeGamets, $name);
    stobeAssignVoiceIdIfMissing(
        $name,
        $race,
        $gender,
        $faction,
        [
            'metadata' => $metadataForStorage,
            'original_name' => $originalNameForTraits,
        ]
    );

    $isSparseSnapshot = (
        $race === '' &&
        $faction === '' &&
        $gender === '' &&
        !$hasMedical &&
        !is_array($snapshot['stats'] ?? null)
    );
    if ($isSparseSnapshot) {
        stobeLogImport('Sparse snapshot persisted', [
            'name' => $name,
            'gamets' => $safeGamets,
            'has_limbs' => $hasLimbs,
            'has_medical' => $hasMedical,
            'keys' => array_keys($snapshot),
        ], 'WARN');
    }

    if ($isBracketSnapshot) {
        $rowAfter = $db->fetchOne(
            "SELECT race, faction, gender, equipment, skills, gamets_last_updated,
                    COALESCE(metadata->>'storage_id', '') AS storage_id
             FROM core_npc_master
             WHERE LOWER(name) = LOWER($1)
             LIMIT 1",
            [$name]
        );
        if ($rowAfter) {
            $raceAfter = trim(strval($rowAfter['race'] ?? ''));
            $factionAfter = trim(strval($rowAfter['faction'] ?? ''));
            $genderAfter = trim(strval($rowAfter['gender'] ?? ''));
            $equipmentAfter = trim(strval($rowAfter['equipment'] ?? ''));
            $skillsAfter = trim(strval($rowAfter['skills'] ?? ''));
            $isUnresolved = (
                ($raceAfter === '' || strtolower($raceAfter) === 'unknown') &&
                $factionAfter === '' &&
                $genderAfter === '' &&
                $equipmentAfter === '' &&
                $skillsAfter === ''
            );
            stobeLogImport('Bracket snapshot ingest end', [
                'incoming_name' => $incomingName,
                'resolved_name' => $name,
                'storage_id' => strval($rowAfter['storage_id'] ?? ''),
                'matched_by' => $matchedBy,
                'result_ok' => ($result !== false),
                'race' => $raceAfter,
                'faction' => $factionAfter,
                'gender' => $genderAfter,
                'equipment_len' => strlen($equipmentAfter),
                'skills_len' => strlen($skillsAfter),
                'gamets_last_updated' => intval($rowAfter['gamets_last_updated'] ?? 0),
                'stats_count' => $statsCount,
                'has_medical_payload' => $hasMedicalPayload,
                'has_medical' => $hasMedical,
            ], $isUnresolved ? 'WARN' : 'DEBUG');
            if ($isUnresolved) {
                stobeLogImport('Bracket snapshot unresolved after upsert', [
                    'incoming_name' => $incomingName,
                    'resolved_name' => $name,
                    'storage_id' => strval($rowAfter['storage_id'] ?? ''),
                    'matched_by' => $matchedBy,
                    'gamets' => $safeGamets,
                    'snapshot_keys' => array_keys($snapshot),
                ], 'WARN');
            }
        }
    }

    $historyRowAfter = stobeFetchNpcRowForHistoryByName($name);
    $rewroteSnapshotHistory = false;
    if ($historyRowBefore && $historyRowAfter) {
        $changedFields = stobeGetNpcHistoryChangedFields($historyRowBefore, $historyRowAfter);
        $snapshotNoiseFields = ['gamets_last_updated', 'skills', 'relationships'];
        $meaningfulSnapshotChanges = array_values(array_diff($changedFields, $snapshotNoiseFields));
        $freshHistoryRows = $db->fetchAll(
            "SELECT history_id, snapshot_reason, created
             FROM core_npc_master_history
             WHERE npc_id = $1
               AND history_id > $2
             ORDER BY history_id ASC",
            [intval($historyRowAfter['id'] ?? 0), $historyBaselineId]
        );
        $freshAutoSnapshotIds = [];
        foreach ($freshHistoryRows as $freshHistoryRow) {
            $freshHistoryId = intval($freshHistoryRow['history_id'] ?? 0);
            $freshHistoryReason = trim(strval($freshHistoryRow['snapshot_reason'] ?? ''));
            $freshHistoryCreatedUnix = strtotime(strval($freshHistoryRow['created'] ?? ''));
            if (
                $freshHistoryId > 0
                && $freshHistoryReason === 'snapshot'
                && $freshHistoryCreatedUnix !== false
                && max(0, time() - intval($freshHistoryCreatedUnix)) <= 30
            ) {
                $freshAutoSnapshotIds[] = $freshHistoryId;
            }
        }

        if (count($freshAutoSnapshotIds) > 0 && count($meaningfulSnapshotChanges) === 0) {
            $db->exec(
                "DELETE FROM core_npc_master_history
                 WHERE history_id = ANY($1::int[])",
                ['{' . implode(',', $freshAutoSnapshotIds) . '}']
            );
            $rewroteSnapshotHistory = true;
        } elseif (count($freshAutoSnapshotIds) > 0) {
            $rewriteHistoryId = intval(end($freshAutoSnapshotIds));
            if (stobeRewriteNpcHistorySnapshotById($rewriteHistoryId, $historyRowBefore, 'snapshot_sync')) {
                $staleAutoSnapshotIds = array_values(array_filter($freshAutoSnapshotIds, static function (int $candidateId) use ($rewriteHistoryId): bool {
                    return $candidateId !== $rewriteHistoryId;
                }));
                if (count($staleAutoSnapshotIds) > 0) {
                    $db->exec(
                        "DELETE FROM core_npc_master_history
                         WHERE history_id = ANY($1::int[])",
                        ['{' . implode(',', $staleAutoSnapshotIds) . '}']
                    );
                }
                $rewroteSnapshotHistory = true;
            }
        }
    }
    if (!$rewroteSnapshotHistory) {
        stobeMaybeSnapshotNpcHistoryBeforeAfter($historyRowBefore, $historyRowAfter, 'snapshot_sync');
    }

    return true;
}

function getNpcById(int $id): array|false {
    $db = $GLOBALS["db"];
    return $db->fetchOne(
        "SELECT n.*,
                p.label AS profile_name,
                llm.name AS profile_response_connector_name,
                tts.name AS profile_tts_connector_name
         FROM core_npc n
         LEFT JOIN core_profiles p ON p.id = n.profile_id
         LEFT JOIN core_llm_connector llm ON llm.id = p.response_connector
         LEFT JOIN core_tts_connector tts ON tts.id = p.tts_connector_id
         WHERE n.id = $1",
        [$id]
    );
}

function deleteNpc(int $id): void {
    $db = $GLOBALS["db"];
    $historyRowBefore = stobeFetchNpcRowForHistoryById($id);
    if ($historyRowBefore) {
        stobeInsertNpcHistorySnapshotFromRow($historyRowBefore, 'delete_before');
    }
    $db->exec("DELETE FROM core_npc WHERE id = $1", [$id]);
}

function updateNpcById(int $id, array $fields): bool {
    $db = $GLOBALS["db"];
    $historyRowBefore = stobeFetchNpcRowForHistoryById($id);
    if (!empty($GLOBALS['STOBE_ALLOW_RELATIONSHIP_EXTENDED_DATA_WRITE'])
        && empty($GLOBALS['STOBE_ALLOW_RELATIONSHIP_CUSTOM_INFO_WRITE'])
        && array_key_exists('extended_data', $fields)) {
        $extended = normalizeCoreNpcExtendedData($fields['extended_data']);
        $extended['relationships'] = stobePreserveRelationshipCustomInfo(
            stobeGetNpcRelationshipMap($historyRowBefore ?: []),
            is_array($extended['relationships'] ?? null) ? $extended['relationships'] : []
        );
        $fields['extended_data'] = $extended;
    }
    $hasIndividualMemoryUpdate = array_key_exists('individual_memory_enabled', $fields);
    $individualMemoryEnabled = $hasIndividualMemoryUpdate
        ? coerceBoolean($fields['individual_memory_enabled'])
        : false;
    unset($fields['individual_memory_enabled']);

    if ($hasIndividualMemoryUpdate && array_key_exists('extended_data', $fields)) {
        $extendedData = normalizeCoreNpcExtendedData($fields['extended_data']);
        if ($individualMemoryEnabled) {
            $extendedData['individual_memory_enabled'] = 1;
        } else {
            unset($extendedData['individual_memory_enabled']);
        }
        $fields['extended_data'] = $extendedData;
    }
    if (array_key_exists('dynamic_profile', $fields) || array_key_exists('middle_term_enabled', $fields)) {
        $metadata = [];
        if (array_key_exists('metadata', $fields)) {
            $metadata = normalizeCoreNpcMetadata($fields['metadata']);
        } elseif ($historyRowBefore) {
            $metadata = normalizeCoreNpcMetadata($historyRowBefore['metadata'] ?? []);
        }

        if (array_key_exists('dynamic_profile', $fields)) {
            $raw = $fields['dynamic_profile'];
            if ($raw === '' || $raw === null) {
                unset($metadata['DYNAMIC_PROFILE_ENABLED']);
            } else {
                $metadata['DYNAMIC_PROFILE_ENABLED'] = coerceBoolean($raw);
            }
            unset($fields['dynamic_profile']);
        }

        if (array_key_exists('middle_term_enabled', $fields)) {
            $raw = $fields['middle_term_enabled'];
            if ($raw === '' || $raw === null) {
                unset($metadata['MIDDLE_TERM_MEMORY_ENABLED']);
            } else {
                $metadata['MIDDLE_TERM_MEMORY_ENABLED'] = coerceBoolean($raw);
            }
            unset($fields['middle_term_enabled']);
        }

        $fields['metadata'] = $metadata;
    }

    if (array_key_exists('profile_id', $fields) && $historyRowBefore) {
        $currentProfileId = intval($historyRowBefore['profile_id'] ?? 0);
        $selectedProfileId = intval($fields['profile_id'] ?? 0);
        if ($selectedProfileId !== $currentProfileId) {
            $metadata = array_key_exists('metadata', $fields)
                ? normalizeCoreNpcMetadata($fields['metadata'])
                : normalizeCoreNpcMetadata($historyRowBefore['metadata'] ?? []);
            $isPlayerFactionNpc = stobeNpcIsInPlayerFactionForProfileOverride($historyRowBefore);
            $automaticProfileId = $isPlayerFactionNpc
                ? getPlayerFactionProfileId()
                : getDefaultNpcProfileId();
            if ($selectedProfileId <= 0 || $selectedProfileId === $automaticProfileId) {
                unset($metadata['profile_assignment_source']);
            } else {
                $metadata['profile_assignment_source'] = 'manual';
            }
            $fields['metadata'] = $metadata;
        }
    }

    $allowedColumns = [
        'race' => 'text',
        'faction' => 'text',
        'gender' => 'text',
        'bounty' => 'bounty_json',
        'limbs' => 'json',
        'blood' => 'text',
        'hunger' => 'text',
        'tags' => 'text',
        'prompt_head' => 'text',
        'personality' => 'text',
        'backstory' => 'text',
        'appearance' => 'text',
        'equipment' => 'text',
        'inventory' => 'text',
        'occupation' => 'text',
        'skills' => 'text',
        'speechstyle' => 'text',
        'goals' => 'text',
        'emote_moods' => 'text',
        'voiceid' => 'text',
        'world_knowledge_tags' => 'text',
        'profile_id' => 'int_or_null',
        'npc_favorite' => 'bool',
        'lock_profile' => 'bool',
        'is_animal' => 'bool',
        'is_slave' => 'bool',
        'metadata' => 'json',
        'extended_data' => 'json',
        'gamets_last_updated' => 'int',
        'md5' => 'text',
    ];

    $setClauses = [];
    $params = [];
    $paramIndex = 1;

    foreach ($allowedColumns as $column => $type) {
        if (!array_key_exists($column, $fields)) {
            continue;
        }

        if ($type === 'json') {
            $incomingExpression = '$' . $paramIndex . '::jsonb';
            if ($column === 'extended_data') {
                $preservedKeys = [];
                if (empty($GLOBALS['STOBE_ALLOW_RELATIONSHIP_EXTENDED_DATA_WRITE'])) {
                    $preservedKeys = stobeRelationshipExtendedDataKeys();
                }
                if (!$hasIndividualMemoryUpdate) {
                    $preservedKeys = array_merge($preservedKeys, stobePersistentNpcSettingExtendedDataKeys());
                }

                if (count($preservedKeys) > 0) {
                    $setClauses[] = $column . ' = ' . stobeExtendedDataPreservingKeysSql(
                        $incomingExpression,
                        $column,
                        $preservedKeys
                    );
                } else {
                    $setClauses[] = "{$column} = " . $incomingExpression;
                }
            } else {
                $setClauses[] = "{$column} = " . $incomingExpression;
            }
            $params[] = normalizeJsonString($fields[$column]);
            $paramIndex++;
            continue;
        }

        if ($type === 'bounty_json') {
            $setClauses[] = "{$column} = $" . $paramIndex . "::jsonb";
            $params[] = stobeNormalizeBountyJsonString($fields[$column]);
            $paramIndex++;
            continue;
        }

        if ($type === 'bool') {
            $setClauses[] = "{$column} = $" . $paramIndex;
            $params[] = coerceBoolean($fields[$column]);
            $paramIndex++;
            continue;
        }

        if ($type === 'int_or_null') {
            $rawValue = $fields[$column];
            $normalized = null;
            if ($rawValue !== null && strval($rawValue) !== '') {
                $normalized = intval($rawValue);
            }
            $setClauses[] = "{$column} = $" . $paramIndex;
            $params[] = $normalized;
            $paramIndex++;
            continue;
        }

        if ($type === 'int') {
            $setClauses[] = "{$column} = $" . $paramIndex;
            $params[] = intval($fields[$column]);
            $paramIndex++;
            continue;
        }

        $setClauses[] = "{$column} = $" . $paramIndex;
        $params[] = strval($fields[$column]);
        $paramIndex++;
    }

    if (!empty($GLOBALS['STOBE_ALLOW_RELATIONSHIP_CUSTOM_INFO_WRITE'])
        && array_key_exists('extended_data', $fields)) {
        $extended = normalizeCoreNpcExtendedData($fields['extended_data']);
        if (array_key_exists('relationships', $extended)) {
            // Keep the legacy copy in sync so deliberately deleted notes cannot return.
            $setClauses[] = 'relationships = $' . $paramIndex;
            $params[] = normalizeJsonString($extended['relationships']);
            $paramIndex++;
        }
    }

    if ($hasIndividualMemoryUpdate && !array_key_exists('extended_data', $fields)) {
        $setClauses[] = "extended_data = CASE
            WHEN $" . $paramIndex . " THEN jsonb_set(
                COALESCE(extended_data, '{}'::jsonb) - 'individual_memory_enabled',
                '{individual_memory_enabled}',
                '1'::jsonb,
                true
            )
            ELSE COALESCE(extended_data, '{}'::jsonb) - 'individual_memory_enabled'
        END";
        $params[] = $individualMemoryEnabled;
        $paramIndex++;
    }

    if (count($setClauses) === 0) {
        return true;
    }

    $params[] = $id;
    $idIndex = $paramIndex;
    $query = "UPDATE core_npc SET " . implode(', ', $setClauses) . ", updated_at = NOW() WHERE id = $" . $idIndex;
    $updated = $db->exec($query, $params);
    if ($updated === false) {
        return false;
    }
    $historyRowAfter = stobeFetchNpcRowForHistoryById($id);
    $rewroteUiHistorySnapshot = false;
    if ($historyRowBefore && $historyRowAfter) {
        $latestHistory = $db->fetchOne(
            "SELECT history_id, snapshot_reason, created
             FROM core_npc_master_history
             WHERE npc_id = $1
             ORDER BY history_id DESC
             LIMIT 1",
            [$id]
        );
        $latestHistoryId = intval($latestHistory['history_id'] ?? 0);
        $latestHistoryReason = trim(strval($latestHistory['snapshot_reason'] ?? ''));
        $latestHistoryCreatedUnix = strtotime(strval($latestHistory['created'] ?? ''));
        if (
            $latestHistoryId > 0
            && $latestHistoryReason === 'snapshot'
            && $latestHistoryCreatedUnix !== false
            && max(0, time() - intval($latestHistoryCreatedUnix)) <= 30
        ) {
            $rewroteUiHistorySnapshot = stobeRewriteNpcHistorySnapshotById($latestHistoryId, $historyRowBefore, 'ui_update');
        }
    }
    if (!$rewroteUiHistorySnapshot) {
        stobeMaybeSnapshotNpcHistoryBeforeAfter($historyRowBefore, $historyRowAfter, 'ui_update');
    }
    if ($historyRowAfter) {
        $incomingFaction = array_key_exists('faction', $fields) ? strval($fields['faction']) : '';
        $npcName = strval($historyRowAfter['name'] ?? '');
        if ($npcName !== '') {
            stobeApplyPlayerFactionProfileForNpcName($npcName, $incomingFaction);
        }
    }
    return true;
}

function stobeEnsureCoreProfileImportRulesTable(): void {
    static $ensured = false;

    $db = $GLOBALS["db"];
    if ($ensured) {
        $probe = $db->fetchOne("SELECT to_regclass('public.core_profile_import_rules') AS rel");
        if (trim(strval($probe['rel'] ?? '')) !== '') {
            return;
        }
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS core_profile_import_rules (
            id SERIAL PRIMARY KEY,
            description TEXT DEFAULT '' NOT NULL,
            match_name TEXT,
            match_race TEXT,
            match_gender TEXT,
            match_faction TEXT,
            profile INT REFERENCES core_profiles(id) ON DELETE CASCADE,
            priority INT DEFAULT 0 NOT NULL,
            enabled BOOLEAN DEFAULT TRUE NOT NULL,
            created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW() NOT NULL,
            updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW() NOT NULL
        )"
    );
    $db->exec("CREATE INDEX IF NOT EXISTS idx_core_profile_import_rules_enabled_priority ON core_profile_import_rules (enabled, priority DESC, id DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_core_profile_import_rules_profile ON core_profile_import_rules (profile)");

    $ensured = true;
}

function stobeGetCoreProfileImportRules(): array {
    stobeEnsureCoreProfileImportRulesTable();
    $db = $GLOBALS["db"];
    return $db->fetchAll(
        "SELECT id, description, match_name, match_race, match_gender, match_faction, profile, priority, enabled, created_at, updated_at
         FROM core_profile_import_rules
         ORDER BY priority DESC, id DESC"
    );
}

function stobeBuildExactProfileRuleRegex(string $value): ?string {
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $escaped = preg_replace_callback(
        '/[\\\\.^$|()\[\]{}*+?]/u',
        static function (array $match): string {
            return '\\' . $match[0];
        },
        $value
    );
    if (!is_string($escaped)) {
        return null;
    }

    return '^(?:' . $escaped . ')$';
}

// Decode only exact literal regexes generated by the dropdown editor.
function stobeParseExactProfileRuleRegex(?string $pattern): ?array {
    $pattern = trim(strval($pattern ?? ''));
    if ($pattern === '') {
        return [];
    }

    if (str_starts_with($pattern, '^(?:') && str_ends_with($pattern, ')$')) {
        $body = substr($pattern, 4, -2);
    } elseif (str_starts_with($pattern, '^') && str_ends_with($pattern, '$')) {
        $body = substr($pattern, 1, -1);
    } else {
        return null;
    }

    $values = [];
    $current = '';
    $escaped = false;
    $length = strlen($body);
    for ($index = 0; $index < $length; $index++) {
        $char = $body[$index];
        if ($escaped) {
            if (strpos('\\.^$|()[]{}*+?/-', $char) === false) {
                return null;
            }
            $current .= $char;
            $escaped = false;
            continue;
        }
        if ($char === '\\') {
            $escaped = true;
            continue;
        }
        if ($char === '|') {
            $values[] = $current;
            $current = '';
            continue;
        }
        if (strpos('.^$()[]{}*+?', $char) !== false) {
            return null;
        }
        $current .= $char;
    }

    if ($escaped) {
        return null;
    }

    $values[] = $current;
    $values = array_values(array_filter($values, static function (string $value): bool {
        return $value !== '';
    }));
    return count($values) > 0 ? $values : null;
}

// Load the distinct NPC values offered by the simple Profile Rules editor.
function stobeGetCoreProfileImportRuleEditorOptions(): array {
    $db = $GLOBALS["db"];
    $loadValues = static function (string $column) use ($db): array {
        if (!in_array($column, ['race', 'faction'], true)) {
            return [];
        }
        $rows = $db->fetchAll(
            "SELECT MIN(BTRIM({$column})) AS value
             FROM core_npc
             GROUP BY LOWER(BTRIM({$column}))
             HAVING LOWER(BTRIM({$column})) NOT IN ('', 'unknown')"
        );
        $values = [];
        foreach ($rows as $row) {
            $value = trim(strval($row['value'] ?? ''));
            if ($value !== '') {
                $values[] = $value;
            }
        }
        natcasesort($values);
        return array_values($values);
    };

    return [
        'races' => $loadValues('race'),
        'factions' => $loadValues('faction'),
    ];
}

function stobeCreateCoreProfileImportRule(array $fields): int {
    stobeEnsureCoreProfileImportRulesTable();
    $db = $GLOBALS["db"];

    $description = trim(strval($fields['description'] ?? ''));
    if ($description === '') {
        $description = 'New Profile Rule';
    }
    $matchName = trim(strval($fields['match_name'] ?? ''));
    $matchRace = trim(strval($fields['match_race'] ?? ''));
    $matchGender = trim(strval($fields['match_gender'] ?? ''));
    $matchFaction = trim(strval($fields['match_faction'] ?? ''));
    $profile = intval($fields['profile'] ?? 0);
    $priority = intval($fields['priority'] ?? 0);
    $enabled = coerceBoolean($fields['enabled'] ?? true);

    $row = $db->fetchOne(
        "INSERT INTO core_profile_import_rules (
            description,
            match_name,
            match_race,
            match_gender,
            match_faction,
            profile,
            priority,
            enabled,
            updated_at
        ) VALUES (
            $1, NULLIF($2, ''), NULLIF($3, ''), NULLIF($4, ''), NULLIF($5, ''), $6, $7, $8, NOW()
        )
        RETURNING id",
        [
            $description,
            $matchName,
            $matchRace,
            $matchGender,
            $matchFaction,
            $profile > 0 ? $profile : null,
            $priority,
            $enabled,
        ]
    );

    return intval($row['id'] ?? 0);
}

function stobeUpdateCoreProfileImportRule(int $id, array $fields): bool {
    stobeEnsureCoreProfileImportRulesTable();
    if ($id <= 0) {
        return false;
    }
    $db = $GLOBALS["db"];

    $description = trim(strval($fields['description'] ?? ''));
    if ($description === '') {
        $description = 'Profile Rule';
    }
    $matchName = trim(strval($fields['match_name'] ?? ''));
    $matchRace = trim(strval($fields['match_race'] ?? ''));
    $matchGender = trim(strval($fields['match_gender'] ?? ''));
    $matchFaction = trim(strval($fields['match_faction'] ?? ''));
    $profile = intval($fields['profile'] ?? 0);
    $priority = intval($fields['priority'] ?? 0);
    $enabled = coerceBoolean($fields['enabled'] ?? false);

    $db->exec(
        "UPDATE core_profile_import_rules
         SET description = $1,
             match_name = NULLIF($2, ''),
             match_race = NULLIF($3, ''),
             match_gender = NULLIF($4, ''),
             match_faction = NULLIF($5, ''),
             profile = $6,
             priority = $7,
             enabled = $8,
             updated_at = NOW()
         WHERE id = $9",
        [
            $description,
            $matchName,
            $matchRace,
            $matchGender,
            $matchFaction,
            $profile > 0 ? $profile : null,
            $priority,
            $enabled,
            $id,
        ]
    );

    return true;
}

function stobeDeleteCoreProfileImportRule(int $id): bool {
    stobeEnsureCoreProfileImportRulesTable();
    if ($id <= 0) {
        return false;
    }
    $db = $GLOBALS["db"];
    $db->exec("DELETE FROM core_profile_import_rules WHERE id = $1", [$id]);
    return true;
}

function stobeProfileRuleRegexMatches(?string $pattern, string $value): bool {
    $rule = trim(strval($pattern ?? ''));
    if ($rule === '') {
        return true;
    }
    $delim = '~';
    $expr = $delim . str_replace($delim, '\\' . $delim, $rule) . $delim . 'iu';
    $result = @preg_match($expr, $value);
    return $result === 1;
}

function stobeResolveProfileIdFromRuleRows(array $rules, string $npcName, string $race = '', string $gender = '', string $faction = ''): int {
    $nameVal = trim($npcName);
    if ($nameVal === '') {
        return 0;
    }
    $raceVal = trim($race);
    $genderVal = trim($gender);
    $factionVal = trim($faction);

    foreach ($rules as $rule) {
        if (!coerceBoolean($rule['enabled'] ?? false)) {
            continue;
        }
        $profileId = intval($rule['profile'] ?? 0);
        if ($profileId <= 0) {
            continue;
        }
        if (!stobeProfileRuleRegexMatches(strval($rule['match_name'] ?? ''), $nameVal)) {
            continue;
        }
        if (!stobeProfileRuleRegexMatches(strval($rule['match_race'] ?? ''), $raceVal)) {
            continue;
        }
        if (!stobeProfileRuleRegexMatches(strval($rule['match_gender'] ?? ''), $genderVal)) {
            continue;
        }
        if (!stobeProfileRuleRegexMatches(strval($rule['match_faction'] ?? ''), $factionVal)) {
            continue;
        }
        return $profileId;
    }

    return 0;
}

function stobeResolveProfileIdFromImportRules(string $npcName, string $race = '', string $gender = '', string $faction = ''): int {
    stobeEnsureCoreProfileImportRulesTable();
    return stobeResolveProfileIdFromRuleRows(stobeGetCoreProfileImportRules(), $npcName, $race, $gender, $faction);
}

// Re-evaluate automatic assignments after a rule changes while preserving manual profile choices.
function stobeBackfillCoreProfileImportRules(): int {
    $db = $GLOBALS["db"];
    $defaultProfileId = getDefaultNpcProfileId();
    $playerFactionProfileId = getPlayerFactionProfileId();
    $rules = stobeGetCoreProfileImportRules();
    $rows = $db->fetchAll(
        "SELECT id, name, race, gender, faction, profile_id, metadata
         FROM core_npc
         WHERE LOWER(name) <> LOWER('The Narrator')
         ORDER BY id ASC"
    );

    $updatedCount = 0;
    foreach ($rows as $row) {
        $currentProfileId = intval($row['profile_id'] ?? 0);
        $metadata = normalizeCoreNpcMetadata($row['metadata'] ?? []);
        $source = strtolower(trim(strval($metadata['profile_assignment_source'] ?? '')));
        if ($source === 'manual') {
            continue;
        }

        // Existing custom assignments predate assignment-source tracking and are treated as manual.
        if (
            $source === ''
            && $currentProfileId > 0
            && $currentProfileId !== $defaultProfileId
            && $currentProfileId !== $playerFactionProfileId
        ) {
            continue;
        }

        $ruleProfileId = stobeResolveProfileIdFromRuleRows(
            $rules,
            strval($row['name'] ?? ''),
            strval($row['race'] ?? ''),
            strval($row['gender'] ?? ''),
            strval($row['faction'] ?? '')
        );
        if ($ruleProfileId > 0) {
            $targetProfileId = $ruleProfileId;
            $metadata['profile_assignment_source'] = 'rule';
        } elseif ($source === 'rule') {
            $targetProfileId = stobeNpcIsInPlayerFactionForProfileOverride($row)
                ? $playerFactionProfileId
                : $defaultProfileId;
            unset($metadata['profile_assignment_source']);
        } else {
            continue;
        }

        if ($targetProfileId <= 0) {
            $targetProfileId = 0;
        }
        $targetSource = strtolower(trim(strval($metadata['profile_assignment_source'] ?? '')));
        if ($currentProfileId === $targetProfileId && $source === $targetSource) {
            continue;
        }

        $db->exec(
            "UPDATE core_npc
             SET profile_id = $1,
                 metadata = $2::jsonb,
                 updated_at = NOW()
             WHERE id = $3",
            [$targetProfileId > 0 ? $targetProfileId : null, normalizeJsonString($metadata), intval($row['id'] ?? 0)]
        );
        $updatedCount++;
    }

    return $updatedCount;
}

function getAllCoreProfiles(): array {
    $db = $GLOBALS["db"];
    return $db->fetchAll(
        "SELECT p.*,
                rc.name AS response_connector_name,
                lp.name AS llm_primary_name,
                ls.name AS llm_secondary_name,
                lt.name AS llm_tertiary_name,
                lq.name AS llm_quaternary_name,
                dc.name AS diary_connector_name,
                ac.name AS autochat_connector_name,
                mc.name AS middleterm_connector_name,
                tc.name AS tts_connector_name
         FROM core_profiles p
         LEFT JOIN core_llm_connector rc ON rc.id = p.response_connector
         LEFT JOIN core_llm_connector lp ON lp.id = p.llm_primary_id
         LEFT JOIN core_llm_connector ls ON ls.id = p.llm_secondary_id
         LEFT JOIN core_llm_connector lt ON lt.id = p.llm_tertiary_id
         LEFT JOIN core_llm_connector lq ON lq.id = p.llm_quaternary_id
         LEFT JOIN core_llm_connector dc ON dc.id = p.diary_connector
         LEFT JOIN core_llm_connector ac ON ac.id = p.autochat_connector
         LEFT JOIN core_llm_connector mc ON mc.id = p.middleterm_connector
         LEFT JOIN core_tts_connector tc ON tc.id = p.tts_connector_id
         ORDER BY p.is_default_npc DESC, p.is_player_faction_profile DESC, p.label ASC"
    );
}

function getCoreProfileById(int $id): array|false {
    $db = $GLOBALS["db"];
    return $db->fetchOne(
        "SELECT p.*,
                rc.name AS response_connector_name,
                lp.name AS llm_primary_name,
                ls.name AS llm_secondary_name,
                lt.name AS llm_tertiary_name,
                lq.name AS llm_quaternary_name,
                dc.name AS diary_connector_name,
                ac.name AS autochat_connector_name,
                mc.name AS middleterm_connector_name,
                tc.name AS tts_connector_name
         FROM core_profiles p
         LEFT JOIN core_llm_connector rc ON rc.id = p.response_connector
         LEFT JOIN core_llm_connector lp ON lp.id = p.llm_primary_id
         LEFT JOIN core_llm_connector ls ON ls.id = p.llm_secondary_id
         LEFT JOIN core_llm_connector lt ON lt.id = p.llm_tertiary_id
         LEFT JOIN core_llm_connector lq ON lq.id = p.llm_quaternary_id
         LEFT JOIN core_llm_connector dc ON dc.id = p.diary_connector
         LEFT JOIN core_llm_connector ac ON ac.id = p.autochat_connector
         LEFT JOIN core_llm_connector mc ON mc.id = p.middleterm_connector
         LEFT JOIN core_tts_connector tc ON tc.id = p.tts_connector_id
         WHERE p.id = $1
         LIMIT 1",
        [$id]
    );
}

function saveCoreProfile(array $fields): int {
    $db = $GLOBALS["db"];
    $id = intval($fields['id'] ?? 0);
    $label = trim(strval($fields['label'] ?? ''));
    if ($label === '') {
        return 0;
    }

    $isDefaultNpc = coerceBoolean($fields['is_default_npc'] ?? false);
    $isPlayerFactionProfile = coerceBoolean($fields['is_player_faction_profile'] ?? false);
    $promptHead = trim(strval($fields['prompt_head'] ?? ''));
    $profilePrompt = trim(strval($fields['profile_prompt'] ?? ''));
    $legacyResponseConnector = ($fields['response_connector'] ?? '') === '' ? null : intval($fields['response_connector']);
    $primaryConnector = ($fields['llm_primary_id'] ?? '') === ''
        ? $legacyResponseConnector
        : intval($fields['llm_primary_id']);
    $secondaryConnector = ($fields['llm_secondary_id'] ?? '') === '' ? null : intval($fields['llm_secondary_id']);
    $tertiaryConnector = ($fields['llm_tertiary_id'] ?? '') === '' ? null : intval($fields['llm_tertiary_id']);
    $quaternaryConnector = ($fields['llm_quaternary_id'] ?? '') === '' ? null : intval($fields['llm_quaternary_id']);
    $diaryConnector = ($fields['diary_connector'] ?? '') === '' ? null : intval($fields['diary_connector']);
    $autochatConnector = ($fields['autochat_connector'] ?? '') === '' ? null : intval($fields['autochat_connector']);
    $middletermConnector = ($fields['middleterm_connector'] ?? '') === '' ? null : intval($fields['middleterm_connector']);
    $backgroundlifeConnector = ($fields['backgroundlife_connector'] ?? '') === '' ? null : intval($fields['backgroundlife_connector']);
    $dynamicConnector = ($fields['dynamic_connector'] ?? '') === '' ? null : intval($fields['dynamic_connector']);
    $relationshipConnector = ($fields['relationship_connector'] ?? '') === '' ? null : intval($fields['relationship_connector']);
    $ttsConnector = ($fields['tts_connector_id'] ?? '') === '' ? null : intval($fields['tts_connector_id']);
    $metadata = $fields['metadata'] ?? [];
    if (is_string($metadata)) {
        $decodedMetadata = json_decode($metadata, true);
        $metadata = is_array($decodedMetadata) ? $decodedMetadata : [];
    }
    if (!is_array($metadata)) {
        $metadata = [];
    }
    unset($metadata['LLM_RESPONSE_MODE']);
    $metadataJson = normalizeJsonString($metadata);
    $wasPlayerFactionProfile = false;
    if ($id > 0) {
        $existingFlags = $db->fetchOne(
            "SELECT is_player_faction_profile
             FROM core_profiles
             WHERE id = $1
             LIMIT 1",
            [$id]
        );
        $wasPlayerFactionProfile = coerceBoolean($existingFlags['is_player_faction_profile'] ?? false);
    }

    $savedId = 0;
    if ($id > 0) {
        if ($isDefaultNpc) {
            // Ensure only one default profile by clearing others before assigning this one.
            $db->exec(
                "UPDATE core_profiles
                 SET is_default_npc = FALSE,
                     updated_at = NOW()
                 WHERE id <> $1
                   AND is_default_npc = TRUE",
                [$id]
            );
        }
        if ($isPlayerFactionProfile) {
            // Ensure only one player-faction profile by clearing others before assigning this one.
            $db->exec(
                "UPDATE core_profiles
                 SET is_player_faction_profile = FALSE,
                     updated_at = NOW()
                 WHERE id <> $1
                   AND is_player_faction_profile = TRUE",
                [$id]
            );
        }
        $row = $db->fetchOne(
            "UPDATE core_profiles
             SET label = $1,
                 is_default_npc = $2,
                 is_player_faction_profile = $3,
                  prompt_head = $4,
                  profile_prompt = $5,
                  llm_primary_id = $6,
                  llm_secondary_id = $7,
                  llm_tertiary_id = $8,
                  llm_quaternary_id = $9,
                  response_connector = $6,
                  diary_connector = $10,
                  autochat_connector = $11,
                  middleterm_connector = $12,
                  backgroundlife_connector = $13,
                  dynamic_connector = $14,
                  relationship_connector = $15,
                  tts_connector_id = $16,
                  metadata = $17::jsonb,
                  updated_at = NOW()
             WHERE id = $18
             RETURNING id",
            [
                $label,
                $isDefaultNpc,
                $isPlayerFactionProfile,
                $promptHead,
                $profilePrompt,
                $primaryConnector,
                $secondaryConnector,
                $tertiaryConnector,
                $quaternaryConnector,
                $diaryConnector,
                $autochatConnector,
                $middletermConnector,
                $backgroundlifeConnector,
                $dynamicConnector,
                $relationshipConnector,
                $ttsConnector,
                $metadataJson,
                $id,
            ]
        );
        $savedId = intval($row['id'] ?? 0);
    } else {
        if ($isDefaultNpc) {
            // For new profiles marked default, unset any previous default first.
            $db->exec(
                "UPDATE core_profiles
                 SET is_default_npc = FALSE,
                     updated_at = NOW()
                 WHERE is_default_npc = TRUE"
            );
        }
        if ($isPlayerFactionProfile) {
            // For new profiles marked player-faction, unset any previous one first.
            $db->exec(
                "UPDATE core_profiles
                 SET is_player_faction_profile = FALSE,
                     updated_at = NOW()
                 WHERE is_player_faction_profile = TRUE"
            );
        }
        $row = $db->fetchOne(
            "INSERT INTO core_profiles (
                label,
                is_default_npc,
                is_player_faction_profile,
                prompt_head,
                profile_prompt,
                llm_primary_id,
                llm_secondary_id,
                llm_tertiary_id,
                llm_quaternary_id,
                response_connector,
                diary_connector,
                autochat_connector,
                middleterm_connector,
                backgroundlife_connector,
                dynamic_connector,
                relationship_connector,
                tts_connector_id,
                metadata
             ) VALUES (
                $1,$2,$3,$4,$5,$6,$7,$8,$9,$6,$10,$11,$12,$13,$14,$15,$16,$17::jsonb
             )
             RETURNING id",
            [
                $label,
                $isDefaultNpc,
                $isPlayerFactionProfile,
                $promptHead,
                $profilePrompt,
                $primaryConnector,
                $secondaryConnector,
                $tertiaryConnector,
                $quaternaryConnector,
                $diaryConnector,
                $autochatConnector,
                $middletermConnector,
                $backgroundlifeConnector,
                $dynamicConnector,
                $relationshipConnector,
                $ttsConnector,
                $metadataJson,
            ]
        );
        $savedId = intval($row['id'] ?? 0);
    }

    if ($savedId > 0 && $isDefaultNpc) {
        $db->exec(
            "UPDATE core_profiles
             SET is_default_npc = FALSE, updated_at = NOW()
             WHERE id <> $1
               AND is_default_npc = TRUE",
            [$savedId]
        );
    }
    if ($savedId > 0 && $isPlayerFactionProfile) {
        $db->exec(
            "UPDATE core_profiles
             SET is_player_faction_profile = FALSE, updated_at = NOW()
             WHERE id <> $1
               AND is_player_faction_profile = TRUE",
            [$savedId]
        );
    }
    if ($savedId > 0 && ($isPlayerFactionProfile || $wasPlayerFactionProfile)) {
        getPlayerFactionProfileId(true);
        stobeSyncPlayerFactionProfileAssignments();
    }

    return $savedId;
}

function deleteCoreProfile(int $id): void {
    $db = $GLOBALS["db"];
    if ($id <= 0) {
        return;
    }
    $profileRow = $db->fetchOne(
        "SELECT id, COALESCE(is_player_faction_profile, FALSE) AS is_player_faction_profile
         FROM core_profiles
         WHERE id = $1
         LIMIT 1",
        [$id]
    );
    $wasPlayerFactionProfile = coerceBoolean($profileRow['is_player_faction_profile'] ?? false);

    $defaultProfileId = getDefaultNpcProfileId();
    if ($defaultProfileId > 0) {
        $db->exec(
            "UPDATE core_npc
             SET profile_id = CASE
                    WHEN profile_id = $2 THEN $1
                    ELSE profile_id
                 END,
                 profile_id_before_player_faction = CASE
                    WHEN profile_id_before_player_faction = $2 THEN $1
                    ELSE profile_id_before_player_faction
                 END,
                 updated_at = NOW()
             WHERE profile_id = $2
                OR profile_id_before_player_faction = $2",
            [$defaultProfileId, $id]
        );
    }
    $db->exec("DELETE FROM core_profiles WHERE id = $1", [$id]);
    if ($wasPlayerFactionProfile) {
        getPlayerFactionProfileId(true);
        stobeSyncPlayerFactionProfileAssignments();
    }
}

function getAllLlmConnectors(): array {
    $db = $GLOBALS["db"];
    return $db->fetchAll(
        "SELECT c.*, b.label AS api_badge_label,
                CASE WHEN COALESCE(b.api_key, '') <> '' THEN TRUE ELSE FALSE END AS has_api_key
         FROM core_llm_connector c
         LEFT JOIN core_api_badge b ON b.id = c.api_badge_id
         ORDER BY c.is_default DESC, c.name ASC"
    );
}

function getLlmConnectorById(int $id): array|false {
    $db = $GLOBALS["db"];
    return $db->fetchOne(
        "SELECT c.*, b.label AS api_badge_label, COALESCE(b.api_key, '') AS api_badge_key,
                CASE WHEN COALESCE(b.api_key, '') <> '' THEN TRUE ELSE FALSE END AS has_api_key
         FROM core_llm_connector c
         LEFT JOIN core_api_badge b ON b.id = c.api_badge_id
         WHERE c.id = $1",
        [$id]
    );
}

function getDefaultLlmConnector(): array|false {
    $db = $GLOBALS["db"];
    $defaultConnector = $db->fetchOne(
        "SELECT c.*, b.label AS api_badge_label, COALESCE(b.api_key, '') AS api_badge_key
         FROM core_llm_connector c
         LEFT JOIN core_api_badge b ON b.id = c.api_badge_id
         WHERE c.is_default = TRUE
         ORDER BY c.id ASC
         LIMIT 1"
    );
    if ($defaultConnector) {
        return $defaultConnector;
    }
    return $db->fetchOne(
        "SELECT c.*, b.label AS api_badge_label, COALESCE(b.api_key, '') AS api_badge_key
         FROM core_llm_connector c
         LEFT JOIN core_api_badge b ON b.id = c.api_badge_id
         ORDER BY c.id ASC
         LIMIT 1"
    );
}

function stobeNormalizeLlmConnectorTypeForStorage(string $rawType): string {
    $normalized = strtolower(trim($rawType));
    if ($normalized === '') {
        return 'openrouterjson';
    }

    if (function_exists('stobeNormalizeLlmConnectorType')) {
        return stobeNormalizeLlmConnectorType($normalized);
    }

    $aliases = [
        'openrouter' => 'openrouterjson',
        'openrouterjson' => 'openrouterjson',
        'openai' => 'openaijson',
        'openaijson' => 'openaijson',
        'custom' => 'openaijson',
        'google' => 'google_openaijson',
        'google_openaijson' => 'google_openaijson',
        'groq' => 'groqjson',
        'groqjson' => 'groqjson',
        'koboldcpp' => 'koboldcppjson',
        'koboldcppjson' => 'koboldcppjson',
        'player2' => 'player2json',
        'player2json' => 'player2json',
    ];

    return $aliases[$normalized] ?? 'openaijson';
}

function saveLlmConnector(array $fields): int {
    $db = $GLOBALS["db"];
    $id = intval($fields['id'] ?? 0);
    $name = trim(strval($fields['name'] ?? ''));
    $connectorType = stobeNormalizeLlmConnectorTypeForStorage(strval($fields['connector_type'] ?? 'openrouterjson'));
    $apiBadgeId = null;
    if (array_key_exists('api_badge_id', $fields) && strval($fields['api_badge_id']) !== '') {
        $apiBadgeId = intval($fields['api_badge_id']);
    }
    $apiKey = strval($fields['api_key'] ?? '');
    $baseUrl = strval($fields['base_url'] ?? '');
    $model = strval($fields['model'] ?? '');
    $maxTokens = intval($fields['max_tokens'] ?? 2048);
    $temperature = floatval($fields['temperature'] ?? 0.8);
    $isDefault = coerceBoolean($fields['is_default'] ?? false);
    $configJson = normalizeJsonString($fields['config'] ?? '{}');

    if ($name === '') {
        return 0;
    }

    $savedId = 0;
    if ($id > 0) {
        $result = $db->fetchOne(
            "UPDATE core_llm_connector
             SET name = $1,
                 connector_type = $2,
                 api_badge_id = $3,
                 api_key = $4,
                 base_url = $5,
                 model = $6,
                 max_tokens = $7,
                 temperature = $8,
                 is_default = $9,
                 config = $10::jsonb
             WHERE id = $11
             RETURNING id",
            [$name, $connectorType, $apiBadgeId, $apiKey, $baseUrl, $model, $maxTokens, $temperature, $isDefault, $configJson, $id]
        );
        $savedId = $result ? intval($result['id']) : 0;
    } else {
        $result = $db->fetchOne(
            "INSERT INTO core_llm_connector
             (name, connector_type, api_badge_id, api_key, base_url, model, max_tokens, temperature, is_default, config)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10::jsonb)
             RETURNING id",
            [$name, $connectorType, $apiBadgeId, $apiKey, $baseUrl, $model, $maxTokens, $temperature, $isDefault, $configJson]
        );
        $savedId = $result ? intval($result['id']) : 0;
    }

    if ($savedId > 0 && $isDefault) {
        $db->exec(
            "UPDATE core_llm_connector SET is_default = FALSE WHERE id <> $1",
            [$savedId]
        );
    }

    return $savedId;
}

function deleteLlmConnector(int $id): void {
    $db = $GLOBALS["db"];
    $db->exec("DELETE FROM core_llm_connector WHERE id = $1", [$id]);
}

function getAllTtsConnectors(): array {
    $db = $GLOBALS["db"];
    return $db->fetchAll(
        "SELECT * FROM core_tts_connector ORDER BY is_default DESC, name ASC"
    );
}

function getTtsConnectorById(int $id): array|false {
    $db = $GLOBALS["db"];
    return $db->fetchOne(
        "SELECT *
         FROM core_tts_connector
         WHERE id = $1
         LIMIT 1",
        [$id]
    );
}

function getDefaultTtsConnector(): array|false {
    $db = $GLOBALS["db"];
    $defaultConnector = $db->fetchOne(
        "SELECT *
         FROM core_tts_connector
         WHERE is_default = TRUE
         ORDER BY id ASC
         LIMIT 1"
    );
    if ($defaultConnector) {
        return $defaultConnector;
    }
    return $db->fetchOne(
        "SELECT *
         FROM core_tts_connector
         ORDER BY id ASC
         LIMIT 1"
    );
}

function stobeNormalizeTtsConnectorTypeForStorage(string $rawType): string {
    $normalized = strtolower(trim($rawType));
    if ($normalized === '') {
        return 'pocket_tts';
    }

    $normalized = str_replace('-', '_', $normalized);
    $normalized = preg_replace('/\s+/', '_', $normalized) ?? $normalized;

    return match ($normalized) {
        'pockettts', 'pocketts', 'pocket_tts' => 'pocket_tts',
        'xtts', 'xtts_fastapi' => 'xtts',
        'chatterbox' => 'chatterbox',
        'omnivoice', 'omni_voice', 'omni_tts' => 'omnivoice',
        'higgs' => 'higgs',
        'cartesia' => 'cartesia',
        'inworld' => 'inworld',
        default => 'pocket_tts',
    };
}

function saveTtsConnector(array $fields): int {
    $db = $GLOBALS["db"];
    $id = intval($fields['id'] ?? 0);
    $name = trim(strval($fields['name'] ?? ''));
    if ($name === '') {
        return 0;
    }

    $connectorType = stobeNormalizeTtsConnectorTypeForStorage(strval($fields['connector_type'] ?? 'pocket_tts'));
    $baseUrl = trim(strval($fields['base_url'] ?? ''));
    $isDefault = coerceBoolean($fields['is_default'] ?? false);
    $configJson = normalizeJsonString($fields['config'] ?? '{}');

    $savedId = 0;
    if ($id > 0) {
        $result = $db->fetchOne(
            "UPDATE core_tts_connector
             SET name = $1,
                 connector_type = $2,
                 base_url = $3,
                 is_default = $4,
                 config = $5::jsonb
             WHERE id = $6
             RETURNING id",
            [$name, $connectorType, $baseUrl, $isDefault, $configJson, $id]
        );
        $savedId = intval($result['id'] ?? 0);
    } else {
        $result = $db->fetchOne(
            "INSERT INTO core_tts_connector
             (name, connector_type, base_url, is_default, config)
             VALUES ($1, $2, $3, $4, $5::jsonb)
             RETURNING id",
            [$name, $connectorType, $baseUrl, $isDefault, $configJson]
        );
        $savedId = intval($result['id'] ?? 0);
    }

    if ($savedId > 0 && $isDefault) {
        $db->exec(
            "UPDATE core_tts_connector
             SET is_default = FALSE
             WHERE id <> $1",
            [$savedId]
        );
    }

    return $savedId;
}

function deleteTtsConnector(int $id): void {
    $db = $GLOBALS["db"];
    $db->exec("DELETE FROM core_tts_connector WHERE id = $1", [$id]);
}

function getDefaultPocketTtsConnectorConfig(): array {
    return [
        'language' => 'en',
        'fallback_male' => 'male1',
        'fallback_female' => 'female1',
        'stream_chunk_size' => 20,
        'temperature' => 0.9,
        'speed' => 1.0,
        'length_penalty' => 1.0,
        'repetition_penalty' => 5.0,
        'top_p' => 0.85,
        'top_k' => 50,
        'enable_text_splitting' => true,
    ];
}

function ensurePocketTtsPlaceholderConnectorId(): int {
    $db = $GLOBALS["db"];
    $connectorName = 'Pocket TTS Default';
    $defaultEndpoint = 'http://127.0.0.1:8024';
    $defaultConfig = normalizeJsonString(getDefaultPocketTtsConnectorConfig());
    $existing = $db->fetchOne(
        "SELECT id
         FROM core_tts_connector
         WHERE LOWER(name) = LOWER($1)
         LIMIT 1",
        [$connectorName]
    );
    $existingId = intval($existing['id'] ?? 0);
    if ($existingId > 0) {
        $db->exec(
            "UPDATE core_tts_connector
              SET connector_type = $2,
                  base_url = CASE
                    WHEN COALESCE(NULLIF(BTRIM(base_url), ''), '') = '' THEN $3
                    ELSE base_url
                  END,
                  is_default = TRUE,
                  config = CASE
                    WHEN config IS NULL OR jsonb_typeof(config) <> 'object' THEN $4::jsonb
                    ELSE $4::jsonb || config
                  END
              WHERE id = $1",
            [$existingId, 'pocket_tts', $defaultEndpoint, $defaultConfig]
        );
        return $existingId;
    }

    $inserted = $db->fetchOne(
        "INSERT INTO core_tts_connector (
            name,
            connector_type,
            base_url,
            is_default,
            config
        ) VALUES (
            $1, $2, $3, TRUE, $4::jsonb
        )
        RETURNING id",
        [$connectorName, 'pocket_tts', $defaultEndpoint, $defaultConfig]
    );
    return intval($inserted['id'] ?? 0);
}

function getDefaultCoreProfileMetadata(): array {
    return [
        'DYNAMIC_PROFILE_ENABLED' => false,
        'MIDDLE_TERM_MEMORY_ENABLED' => false,
        'SHORT_TERM_MEMORY_ENABLED' => false,
        'SHORT_TERM_MEMORY_MAX' => 10,
        'AUTO_DIARY_ENABLED' => false,
        'LATEST_DIARY_CONTEXT_ENABLED' => false,
        'DIARY_DAYS' => 1,
        'AUTO_DIARY_MIN_EVENTS' => 50,
        'AUTO_DIARY_HOUR' => 21,
        'DYNAMIC_PROFILE_FIELDS' => [
            'personality',
            'occupation',
            'speechstyle',
            'goals',
        ],
        'RECHAT_RESPONSES' => 3,
        'RECHAT_PROBABILITY' => 66,
        'DIARY_PROMPT' => "Please write a short summary of the last #DAYS_SINCE_LAST_DIARY# in-game day(s) of #PLAYER_NAME# and #NPC_NAME#'s dialogues and events written above into #NPC_NAME#'s diary. WRITE AS IF YOU WERE #NPC_NAME#. Start the diary entry with the current date and time.",
        'DIARY_COOLDOWN' => 120,
        'CONTEXT_HISTORY' => 75,
        'CONTEXT_HISTORY_DIARY' => 100,
        'CONTEXT_HISTORY_DYNAMIC_PROFILE' => 50,
        'BORED_EVENT_CHANCE' => 50,
        'RELATIONSHIP_UPDATE_CHANCE' => 50,
    ];
}

function getDefaultCoreProfileMetadataJson(): string {
    return normalizeJsonString(getDefaultCoreProfileMetadata());
}

function ensureDefaultCoreProfile(): int {
    $db = $GLOBALS["db"];
    // Respect an existing user-selected default profile if one is already set.
    $existingDefault = $db->fetchOne(
        "SELECT id
         FROM core_profiles
         WHERE is_default_npc = TRUE
         ORDER BY id ASC
         LIMIT 1"
    );
    $existingDefaultId = intval($existingDefault['id'] ?? 0);
    if ($existingDefaultId > 0) {
        $db->exec(
            "UPDATE core_profiles
             SET is_default_npc = FALSE
             WHERE id <> $1
               AND is_default_npc = TRUE",
            [$existingDefaultId]
        );
        return $existingDefaultId;
    }

    $profileLabel = 'Default Profile';
    $defaultMetadataJson = getDefaultCoreProfileMetadataJson();

    $connectorIdByName = static function (string $name) use ($db): int {
        $row = $db->fetchOne(
            "SELECT id
             FROM core_llm_connector
             WHERE LOWER(name) = LOWER($1)
             LIMIT 1",
            [$name]
        );
        return intval($row['id'] ?? 0);
    };

    $primaryConnectorId = $connectorIdByName('DeepSeek V4 Flash');
    if ($primaryConnectorId <= 0) {
        $primaryConnectorId = $connectorIdByName('GLM 4.7');
    }
    if ($primaryConnectorId <= 0) {
        $primaryConnectorId = $connectorIdByName('Gemini 2.5 Flash');
    }
    if ($primaryConnectorId <= 0) {
        $defaultLlm = getDefaultLlmConnector();
        $primaryConnectorId = intval($defaultLlm['id'] ?? 0);
    }
    if ($primaryConnectorId <= 0) {
        $primaryConnectorId = $connectorIdByName('OpenRouter Default');
    }
    $primaryConnectorIdOrNull = $primaryConnectorId > 0 ? $primaryConnectorId : null;

    $secondaryConnectorId = $connectorIdByName('Gemini 2.5 Flash Lite');
    if ($secondaryConnectorId <= 0) {
        $secondaryConnectorId = $primaryConnectorId;
    }
    $secondaryConnectorIdOrNull = $secondaryConnectorId > 0 ? $secondaryConnectorId : null;

    $tertiaryConnectorId = $connectorIdByName('GLM 5.2');
    if ($tertiaryConnectorId <= 0) {
        $tertiaryConnectorId = $primaryConnectorId;
    }
    $tertiaryConnectorIdOrNull = $tertiaryConnectorId > 0 ? $tertiaryConnectorId : null;

    $quaternaryConnectorId = $connectorIdByName('DeepSeek V4 Pro');
    if ($quaternaryConnectorId <= 0) {
        $quaternaryConnectorId = $primaryConnectorId;
    }
    $quaternaryConnectorIdOrNull = $quaternaryConnectorId > 0 ? $quaternaryConnectorId : null;

    $diaryConnectorIdOrNull = $primaryConnectorIdOrNull;

    $autochatConnectorId = $connectorIdByName('Gemini 2.5 Flash Lite');
    if ($autochatConnectorId <= 0) {
        $autochatConnectorId = $primaryConnectorId;
    }
    $autochatConnectorIdOrNull = $autochatConnectorId > 0 ? $autochatConnectorId : null;

    $memoryConnectorId = $connectorIdByName('Mistral Small 3.2 24B');
    if ($memoryConnectorId <= 0) {
        $memoryConnectorId = $primaryConnectorId;
    }
    $memoryConnectorIdOrNull = $memoryConnectorId > 0 ? $memoryConnectorId : null;
    $backgroundlifeConnectorIdOrNull = $memoryConnectorIdOrNull;
    $dynamicConnectorIdOrNull = $primaryConnectorIdOrNull;
    $relationshipConnectorIdOrNull = $primaryConnectorIdOrNull;

    $ttsConnectorId = ensurePocketTtsPlaceholderConnectorId();
    $ttsConnectorIdOrNull = $ttsConnectorId > 0 ? $ttsConnectorId : null;

    $existingProfile = $db->fetchOne(
        "SELECT id
         FROM core_profiles
         WHERE LOWER(label) = LOWER($1)
         LIMIT 1",
        [$profileLabel]
    );
    $profileId = intval($existingProfile['id'] ?? 0);

    if ($profileId > 0) {
        $db->exec(
            "UPDATE core_profiles
             SET is_default_npc = TRUE,
                 prompt_head = COALESCE(prompt_head, ''),
                 profile_prompt = COALESCE(profile_prompt, ''),
                  llm_primary_id = COALESCE(llm_primary_id, response_connector, $2::INT),
                  llm_secondary_id = COALESCE(llm_secondary_id, $3::INT),
                  llm_tertiary_id = COALESCE(llm_tertiary_id, $4::INT),
                  llm_quaternary_id = COALESCE(llm_quaternary_id, $5::INT),
                  response_connector = COALESCE(response_connector, llm_primary_id, $2::INT),
                  diary_connector = COALESCE($6::INT, diary_connector),
                  autochat_connector = COALESCE($7::INT, autochat_connector),
                  middleterm_connector = COALESCE($8::INT, middleterm_connector),
                  backgroundlife_connector = COALESCE($9::INT, backgroundlife_connector),
                  dynamic_connector = COALESCE($10::INT, dynamic_connector),
                  relationship_connector = COALESCE($11::INT, relationship_connector),
                  tts_connector_id = COALESCE($12::INT, tts_connector_id),
                  metadata = CASE
                    WHEN metadata IS NULL
                      OR metadata = '[]'::jsonb
                      OR jsonb_typeof(metadata) <> 'object'
                    THEN $13::jsonb
                    ELSE $13::jsonb || metadata
                 END,
                 updated_at = NOW()
             WHERE id = $1",
            [
                $profileId,
                $primaryConnectorIdOrNull,
                $secondaryConnectorIdOrNull,
                $tertiaryConnectorIdOrNull,
                $quaternaryConnectorIdOrNull,
                $diaryConnectorIdOrNull,
                $autochatConnectorIdOrNull,
                $memoryConnectorIdOrNull,
                $backgroundlifeConnectorIdOrNull,
                $dynamicConnectorIdOrNull,
                $relationshipConnectorIdOrNull,
                $ttsConnectorIdOrNull,
                $defaultMetadataJson
            ]
        );
    } else {
        $insertedProfile = $db->fetchOne(
            "INSERT INTO core_profiles (
                label,
                is_default_npc,
                prompt_head,
                profile_prompt,
                llm_primary_id,
                llm_secondary_id,
                llm_tertiary_id,
                llm_quaternary_id,
                response_connector,
                diary_connector,
                autochat_connector,
                middleterm_connector,
                backgroundlife_connector,
                dynamic_connector,
                relationship_connector,
                tts_connector_id,
                metadata
             ) VALUES (
                $1, TRUE, '', '', $2, $3, $4, $5, $2, $6, $7, $8, $9, $10, $11, $12, $13::jsonb
            )
            RETURNING id",
            [
                $profileLabel,
                $primaryConnectorIdOrNull,
                $secondaryConnectorIdOrNull,
                $tertiaryConnectorIdOrNull,
                $quaternaryConnectorIdOrNull,
                $diaryConnectorIdOrNull,
                $autochatConnectorIdOrNull,
                $memoryConnectorIdOrNull,
                $backgroundlifeConnectorIdOrNull,
                $dynamicConnectorIdOrNull,
                $relationshipConnectorIdOrNull,
                $ttsConnectorIdOrNull,
                $defaultMetadataJson
            ]
        );
        $profileId = intval($insertedProfile['id'] ?? 0);
    }

    if ($profileId > 0) {
        $db->exec(
            "UPDATE core_profiles
             SET is_default_npc = FALSE
             WHERE id <> $1
               AND is_default_npc = TRUE",
            [$profileId]
        );
        return $profileId;
    }

    $fallbackProfile = $db->fetchOne(
        "SELECT id
         FROM core_profiles
         WHERE is_default_npc = TRUE
         ORDER BY id ASC
         LIMIT 1"
    );
    return intval($fallbackProfile['id'] ?? 0);
}

function getDefaultNpcProfileId(): int {
    static $cachedProfileId = null;

    if (is_int($cachedProfileId) && $cachedProfileId > 0) {
        return $cachedProfileId;
    }

    $profileId = ensureDefaultCoreProfile();
    if ($profileId <= 0) {
        $db = $GLOBALS["db"];
        $fallbackProfile = $db->fetchOne(
            "SELECT id
             FROM core_profiles
             WHERE is_default_npc = TRUE
             ORDER BY id ASC
             LIMIT 1"
        );
        $profileId = intval($fallbackProfile['id'] ?? 0);
    }

    $cachedProfileId = $profileId;
    return $cachedProfileId;
}

function getPlayerFactionProfileId(bool $refresh = false): int {
    static $cachedProfileId = null;

    if (!$refresh && is_int($cachedProfileId)) {
        return max(0, $cachedProfileId);
    }

    $db = $GLOBALS["db"];
    $row = $db->fetchOne(
        "SELECT id
         FROM core_profiles
         WHERE COALESCE(is_player_faction_profile, FALSE) = TRUE
         ORDER BY id ASC
         LIMIT 1"
    );
    $cachedProfileId = intval($row['id'] ?? 0);
    return max(0, $cachedProfileId);
}

function stobeProfileIdExists(int $profileId): bool {
    if ($profileId <= 0) {
        return false;
    }

    static $cache = [];
    $cacheKey = strval($profileId);
    if (array_key_exists($cacheKey, $cache)) {
        return boolval($cache[$cacheKey]);
    }

    $db = $GLOBALS["db"];
    $row = $db->fetchOne("SELECT 1 AS ok FROM core_profiles WHERE id = $1 LIMIT 1", [$profileId]);
    $exists = boolval($row && intval($row['ok'] ?? 0) === 1);
    $cache[$cacheKey] = $exists;
    return $exists;
}

function stobeParseFactionIdentityForProfileOverride(string $rawFaction): array {
    if (function_exists('parseFactionIdentityToken')) {
        $parsed = parseFactionIdentityToken($rawFaction);
        return [
            'name' => trim(strval($parsed['name'] ?? '')),
            'id' => trim(strval($parsed['id'] ?? '')),
        ];
    }

    $raw = trim($rawFaction);
    if ($raw === '') {
        return ['name' => '', 'id' => ''];
    }

    $name = $raw;
    $id = '';
    if (preg_match('/^(.*?)\s*\[\[([^\]]+)\]\]\s*$/u', $raw, $matches) === 1) {
        $name = trim(strval($matches[1] ?? ''));
        $id = trim(strval($matches[2] ?? ''));
    } elseif (preg_match('/^(.*?)\s*\[([^\]]+)\]\s*$/u', $raw, $matches) === 1) {
        $name = trim(strval($matches[1] ?? ''));
        $id = trim(strval($matches[2] ?? ''));
    }
    $name = trim(strval(preg_replace('/\s*\[\[[^\]]+\]\]\s*$/u', '', $name)));
    $name = trim(strval(preg_replace('/\s*\[[^\]]+\]\s*$/u', '', $name)));

    return ['name' => $name, 'id' => $id];
}

function stobeExtractNpcFactionIdentityForProfileOverride(array $npcRow, string $incomingFaction = ''): array {
    $rawFaction = trim($incomingFaction);
    if ($rawFaction === '') {
        $rawFaction = trim(strval($npcRow['faction'] ?? ''));
    }

    $identity = stobeParseFactionIdentityForProfileOverride($rawFaction);
    $metadata = normalizeCoreNpcMetadata($npcRow['metadata'] ?? []);
    $metadataFactionId = trim(strval($metadata['faction_id'] ?? ($metadata['factionID'] ?? '')));
    if ($identity['id'] === '' && $metadataFactionId !== '') {
        $identity['id'] = $metadataFactionId;
    }
    if ($identity['name'] === '') {
        $metadataFaction = trim(strval($metadata['faction'] ?? ''));
        if ($metadataFaction !== '') {
            $metaIdentity = stobeParseFactionIdentityForProfileOverride($metadataFaction);
            if ($metaIdentity['name'] !== '') {
                $identity['name'] = $metaIdentity['name'];
            }
            if ($identity['id'] === '' && $metaIdentity['id'] !== '') {
                $identity['id'] = $metaIdentity['id'];
            }
        }
    }

    return [
        'name' => trim(strval($identity['name'] ?? '')),
        'id' => trim(strval($identity['id'] ?? '')),
    ];
}

function stobeResolveCurrentPlayerFactionIdentityForProfileOverride(): array {
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $identity = ['name' => '', 'id' => ''];
    if (function_exists('getCurrentPlayerFactionIdentity')) {
        try {
            $candidate = getCurrentPlayerFactionIdentity();
            if (is_array($candidate)) {
                $identity = [
                    'name' => trim(strval($candidate['name'] ?? '')),
                    'id' => trim(strval($candidate['id'] ?? '')),
                ];
            }
        } catch (Throwable $exception) {
        }
    }

    if ($identity['name'] === '' && $identity['id'] === '') {
        $identity = ['name' => 'Nameless', 'id' => '204-gamedata.base'];
    }

    $cached = $identity;
    return $cached;
}

function stobeNpcNameInPlayerFactionSquadForProfileOverride(string $npcName): bool {
    $normalized = normalizeParticipantNameToken($npcName);
    if ($normalized === '') {
        return false;
    }

    static $memberSet = null;
    if (!is_array($memberSet)) {
        $memberSet = [];
        if (function_exists('getPlayerSquadMembershipSnapshot')) {
            try {
                $snapshot = getPlayerSquadMembershipSnapshot();
                $squadMembers = is_array($snapshot['squad_members'] ?? null) ? $snapshot['squad_members'] : [];
                foreach ($squadMembers as $members) {
                    if (!is_array($members)) {
                        continue;
                    }
                    foreach ($members as $memberName) {
                        $cleanName = normalizeParticipantNameToken(strval($memberName));
                        if ($cleanName !== '') {
                            $memberSet[strtolower($cleanName)] = true;
                        }
                    }
                }
            } catch (Throwable $exception) {
            }
        }
    }

    return boolval($memberSet[strtolower($normalized)] ?? false);
}

function stobeNpcIsInPlayerFactionForProfileOverride(array $npcRow, string $incomingFaction = ''): bool {
    $rowForCheck = $npcRow;
    if (trim($incomingFaction) !== '') {
        $rowForCheck['faction'] = $incomingFaction;
    }

    if (function_exists('npcIsInPlayerFaction')) {
        try {
            if (npcIsInPlayerFaction($rowForCheck)) {
                return true;
            }
        } catch (Throwable $exception) {
        }
    }

    $playerFaction = stobeResolveCurrentPlayerFactionIdentityForProfileOverride();
    $npcFaction = stobeExtractNpcFactionIdentityForProfileOverride($rowForCheck, $incomingFaction);

    $playerFactionId = strtolower(trim(strval($playerFaction['id'] ?? '')));
    $npcFactionId = strtolower(trim(strval($npcFaction['id'] ?? '')));
    if ($playerFactionId !== '' && $npcFactionId !== '' && $playerFactionId === $npcFactionId) {
        return true;
    }

    $playerFactionName = strtolower(trim(strval($playerFaction['name'] ?? '')));
    $npcFactionName = strtolower(trim(strval($npcFaction['name'] ?? '')));
    if ($playerFactionName !== '' && $npcFactionName !== '' && $playerFactionName === $npcFactionName) {
        return true;
    }

    if ($npcFactionId === '204-gamedata.base') {
        return true;
    }
    if ($npcFactionName === 'nameless') {
        return true;
    }

    $npcName = strval($rowForCheck['name'] ?? ($rowForCheck['npc_name'] ?? ''));
    return stobeNpcNameInPlayerFactionSquadForProfileOverride($npcName);
}

function stobeApplyPlayerFactionProfileForNpcName(string $npcName, string $incomingFaction = ''): bool {
    $safeName = normalizeParticipantNameToken($npcName);
    if ($safeName === '') {
        return false;
    }
    if (strcasecmp($safeName, 'The Narrator') === 0) {
        return false;
    }

    $db = $GLOBALS["db"];
    $row = $db->fetchOne(
        "SELECT id, name, race, gender, faction, metadata, profile_id, profile_id_before_player_faction
         FROM core_npc
         WHERE LOWER(name) = LOWER($1)
         LIMIT 1",
        [$safeName]
    );
    if (!$row) {
        return false;
    }

    $npcId = intval($row['id'] ?? 0);
    if ($npcId <= 0) {
        return false;
    }

    $playerFactionProfileId = getPlayerFactionProfileId();
    $currentProfileId = intval($row['profile_id'] ?? 0);
    $backupProfileId = intval($row['profile_id_before_player_faction'] ?? 0);
    $metadata = normalizeCoreNpcMetadata($row['metadata'] ?? []);
    $assignmentSource = strtolower(trim(strval($metadata['profile_assignment_source'] ?? '')));
    if (
        in_array($assignmentSource, ['manual', 'rule'], true)
        && $currentProfileId > 0
        && stobeProfileIdExists($currentProfileId)
    ) {
        return false;
    }
    $isInPlayerFaction = stobeNpcIsInPlayerFactionForProfileOverride($row, $incomingFaction);

    if ($isInPlayerFaction && $playerFactionProfileId > 0) {
        $backupToStore = $backupProfileId;
        if ($backupToStore <= 0 && $currentProfileId > 0 && $currentProfileId !== $playerFactionProfileId) {
            $backupToStore = $currentProfileId;
        }
        if ($backupToStore <= 0) {
            $defaultProfileId = getDefaultNpcProfileId();
            if ($defaultProfileId > 0 && $defaultProfileId !== $playerFactionProfileId) {
                $backupToStore = $defaultProfileId;
            }
        }
        if ($backupToStore > 0 && !stobeProfileIdExists($backupToStore)) {
            $backupToStore = 0;
        }

        if ($currentProfileId === $playerFactionProfileId) {
            if ($backupProfileId <= 0 && $backupToStore > 0) {
                $db->exec(
                    "UPDATE core_npc
                     SET profile_id_before_player_faction = $1,
                         updated_at = NOW()
                     WHERE id = $2",
                    [$backupToStore, $npcId]
                );
                return true;
            }
            return false;
        }

        $db->exec(
            "UPDATE core_npc
             SET profile_id = $1,
                 profile_id_before_player_faction = $2,
                 updated_at = NOW()
             WHERE id = $3",
            [$playerFactionProfileId, $backupToStore > 0 ? $backupToStore : null, $npcId]
        );
        return true;
    }

    $shouldRestore = false;
    $restoreProfileId = 0;
    if ($backupProfileId > 0) {
        $shouldRestore = true;
        $restoreProfileId = $backupProfileId;
    } elseif ($playerFactionProfileId > 0 && $currentProfileId === $playerFactionProfileId) {
        $shouldRestore = true;
        $restoreProfileId = getDefaultNpcProfileId();
    }

    if (!$shouldRestore) {
        return false;
    }

    if ($restoreProfileId > 0 && !stobeProfileIdExists($restoreProfileId)) {
        $restoreProfileId = getDefaultNpcProfileId();
    }
    if ($restoreProfileId > 0 && !stobeProfileIdExists($restoreProfileId)) {
        $restoreProfileId = 0;
    }

    if ($currentProfileId === $restoreProfileId && $backupProfileId <= 0) {
        return false;
    }

    $db->exec(
        "UPDATE core_npc
         SET profile_id = $1,
             profile_id_before_player_faction = NULL,
             updated_at = NOW()
         WHERE id = $2",
        [$restoreProfileId > 0 ? $restoreProfileId : null, $npcId]
    );
    return true;
}

function stobeSyncPlayerFactionProfileAssignments(): int {
    $db = $GLOBALS["db"];
    $rows = $db->fetchAll(
        "SELECT name
         FROM core_npc
         WHERE LOWER(name) <> LOWER('The Narrator')
         ORDER BY id ASC"
    );

    $updatedCount = 0;
    foreach ($rows as $row) {
        $name = strval($row['name'] ?? '');
        if ($name === '') {
            continue;
        }
        if (stobeApplyPlayerFactionProfileForNpcName($name)) {
            $updatedCount++;
        }
    }

    return $updatedCount;
}

function stobeBuildLlmConfigFromConnector(array|false $connector): array {
    if (!$connector) {
        return [
            'api_key' => '',
            'base_url' => 'https://openrouter.ai/api/v1',
            'model' => 'openrouter/auto',
            'max_tokens' => 384,
            'temperature' => 0.8,
            'connector_type' => 'openrouterjson',
            'config' => [],
        ];
    }

    $resolvedApiKey = strval($connector['api_badge_key'] ?? '');
    if ($resolvedApiKey === '') {
        $resolvedApiKey = strval($connector['api_key'] ?? '');
    }
    $maxTokens = intval($connector['max_tokens'] ?? 2048);
    if ($maxTokens <= 0) {
        $maxTokens = 2048;
    }

    $baseUrl = trim(strval($connector['base_url'] ?? ''));
    $model = trim(strval($connector['model'] ?? ''));

    $temperature = floatval($connector['temperature'] ?? 0.8);
    if ($temperature < 0.0) {
        $temperature = 0.8;
    }

    $extraConfig = [];
    $rawConfig = $connector['config'] ?? '{}';
    if (is_array($rawConfig)) {
        $extraConfig = $rawConfig;
    } else {
        $decodedConfig = json_decode(strval($rawConfig), true);
        if (is_array($decodedConfig)) {
            $extraConfig = $decodedConfig;
        }
    }

    $connectorType = stobeNormalizeLlmConnectorTypeForStorage(strval($connector['connector_type'] ?? 'openrouterjson'));

    if ($connectorType === 'openrouterjson') {
        if ($baseUrl === '') {
            $baseUrl = 'https://openrouter.ai/api/v1';
        }
        if ($model === '') {
            $model = 'openrouter/auto';
        }
    } elseif ($connectorType === 'google_openaijson') {
        if ($baseUrl === '') {
            $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/openai';
        }
        if ($model === '') {
            $model = 'gemini-2.5-flash';
        }
    } elseif ($connectorType === 'groqjson') {
        if ($baseUrl === '') {
            $baseUrl = 'https://api.groq.com/openai/v1';
        }
        if ($model === '') {
            $model = 'llama-3.3-70b-versatile';
        }
    } elseif ($connectorType === 'koboldcppjson') {
        if ($baseUrl === '') {
            $baseUrl = 'http://127.0.0.1:5001/v1';
        }
    } elseif ($connectorType === 'player2json') {
        if ($baseUrl === '') {
            $baseUrl = 'http://127.0.0.1:4315/v1';
        }
    }

    return [
        'api_key' => $resolvedApiKey,
        'base_url' => $baseUrl,
        'model' => $model,
        'max_tokens' => $maxTokens,
        'temperature' => $temperature,
        'connector_type' => $connectorType,
        'config' => $extraConfig,
    ];
}

function stobeResolveNpcProfileId(array|false $npcData): int {
    $profileId = 0;
    if (is_array($npcData)) {
        $profileId = intval($npcData['profile_id'] ?? 0);
    }
    if ($profileId <= 0) {
        $profileId = getDefaultNpcProfileId();
    }
    return $profileId > 0 ? $profileId : 0;
}

function stobeGetProfileMetadataById(int $profileId): array {
    static $metadataCache = [];
    if ($profileId <= 0) {
        return [];
    }
    if (array_key_exists($profileId, $metadataCache)) {
        $cached = $metadataCache[$profileId];
        return is_array($cached) ? $cached : [];
    }

    $db = $GLOBALS["db"];
    $row = $db->fetchOne(
        "SELECT metadata
         FROM core_profiles
         WHERE id = $1
         LIMIT 1",
        [$profileId]
    );
    if (!$row) {
        $metadataCache[$profileId] = [];
        return [];
    }

    $rawMetadata = $row['metadata'] ?? '{}';
    $metadata = [];
    if (is_array($rawMetadata)) {
        $metadata = $rawMetadata;
    } else {
        $decoded = json_decode(strval($rawMetadata), true);
        if (is_array($decoded)) {
            $metadata = $decoded;
        }
    }
    $metadataCache[$profileId] = $metadata;
    return $metadata;
}

function stobeNormalizeSettingKey(string $key): string {
    return strtoupper(trim($key));
}

function stobeGetNpcRuntimeOverrideMap(array|false $npcData): array {
    if (!is_array($npcData)) {
        return [];
    }

    static $overrideCache = [];
    $cacheKey = '';
    $npcId = intval($npcData['id'] ?? 0);
    $npcMd5 = trim(strval($npcData['md5'] ?? ''));
    $npcUpdated = trim(strval($npcData['updated_at'] ?? ($npcData['gamets_last_updated'] ?? '')));
    if ($npcId > 0) {
        $cacheKey = 'id:' . $npcId . '|u:' . $npcUpdated;
    } elseif ($npcMd5 !== '') {
        $cacheKey = 'md5:' . $npcMd5 . '|u:' . $npcUpdated;
    }
    if ($cacheKey !== '' && array_key_exists($cacheKey, $overrideCache)) {
        $cached = $overrideCache[$cacheKey];
        return is_array($cached) ? $cached : [];
    }

    $overrides = [];
    $applyScalarMap = static function (array $input) use (&$overrides): void {
        foreach ($input as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (!(is_scalar($value) || $value === null)) {
                continue;
            }
            $normalizedKey = stobeNormalizeSettingKey($key);
            if ($normalizedKey === '') {
                continue;
            }
            $overrides[$normalizedKey] = $value;
        }
    };

    $metadata = normalizeCoreNpcMetadata($npcData['metadata'] ?? []);
    $applyScalarMap($metadata);

    $extended = normalizeCoreNpcExtendedData($npcData['extended_data'] ?? []);
    $nestedOverrides = [];
    if (isset($extended['setting_overrides']) && is_array($extended['setting_overrides'])) {
        $nestedOverrides = $extended['setting_overrides'];
    }
    $applyScalarMap($nestedOverrides);

    $reservedTopLevel = [
        'environment',
        'nearby_actors',
        'nearby_items',
        'points_of_interest',
        'middle_term_memory',
        'setting_overrides',
    ];
    $compatScalarOverrides = [];
    foreach ($extended as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        if (in_array($key, $reservedTopLevel, true)) {
            continue;
        }
        $compatScalarOverrides[$key] = $value;
    }
    $applyScalarMap($compatScalarOverrides);

    if ($cacheKey !== '') {
        $overrideCache[$cacheKey] = $overrides;
    }
    return $overrides;
}

function stobeReadLayeredSettingRaw(
    array|false $npcData,
    array $preferredKeys,
    string $fallbackSettingId,
    mixed &$valueOut,
    string &$sourceOut
): bool {
    $valueOut = null;
    $sourceOut = '';

    $candidateKeys = [];
    foreach ($preferredKeys as $key) {
        if (!is_string($key)) {
            continue;
        }
        $normalized = stobeNormalizeSettingKey($key);
        if ($normalized === '' || in_array($normalized, $candidateKeys, true)) {
            continue;
        }
        $candidateKeys[] = $normalized;
    }
    $fallbackNormalized = stobeNormalizeSettingKey($fallbackSettingId);
    if ($fallbackNormalized !== '' && !in_array($fallbackNormalized, $candidateKeys, true)) {
        $candidateKeys[] = $fallbackNormalized;
    }

    $npcOverrides = stobeGetNpcRuntimeOverrideMap($npcData);
    foreach ($candidateKeys as $key) {
        if (array_key_exists($key, $npcOverrides)) {
            $valueOut = $npcOverrides[$key];
            $sourceOut = 'npc';
            return true;
        }
    }

    $profileMetadata = getCoreProfileMetadataForNpc($npcData);
    $profileLookup = [];
    if (is_array($profileMetadata)) {
        foreach ($profileMetadata as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $normalized = stobeNormalizeSettingKey($key);
            if ($normalized === '') {
                continue;
            }
            $profileLookup[$normalized] = $value;
        }
    }
    foreach ($candidateKeys as $key) {
        if (array_key_exists($key, $profileLookup)) {
            $valueOut = $profileLookup[$key];
            $sourceOut = 'profile';
            return true;
        }
    }

    $fallbackRaw = trim($fallbackSettingId);
    if ($fallbackRaw !== '') {
        $missingSentinel = '__STOBE_MISSING_SETTING__';
        $globalValue = getSetting($fallbackRaw, $missingSentinel);
        if ($globalValue === $missingSentinel && $fallbackNormalized !== '' && $fallbackNormalized !== $fallbackRaw) {
            $globalValue = getSetting($fallbackNormalized, $missingSentinel);
        }
        if ($globalValue !== $missingSentinel) {
            $valueOut = $globalValue;
            $sourceOut = 'global';
            return true;
        }
    }

    return false;
}

function stobeProfileLlmTierDefinitions(): array {
    return [
        1 => ['field' => 'llm_primary_id', 'label' => 'Standard'],
        2 => ['field' => 'llm_secondary_id', 'label' => 'Fast'],
        3 => ['field' => 'llm_tertiary_id', 'label' => 'Powerful'],
        4 => ['field' => 'llm_quaternary_id', 'label' => 'Experimental'],
    ];
}

function stobeGetGlobalProfileLlmSlot(): int {
    $slot = intval(getConfOpt('stobe_profile_model', '1'));
    return $slot >= 1 && $slot <= 4 ? $slot : 1;
}

/**
 * Resolves the globally selected response tier while retaining legacy profile fallbacks.
 */
function stobeResolveProfileResponseConnectorId(array $profileRow): int {
    $tiers = stobeProfileLlmTierDefinitions();
    $slot = stobeGetGlobalProfileLlmSlot();
    $candidateFields = [
        $tiers[$slot]['field'],
        $tiers[1]['field'],
        'response_connector',
        $tiers[2]['field'],
        $tiers[3]['field'],
        $tiers[4]['field'],
    ];
    foreach (array_unique($candidateFields) as $field) {
        $connectorId = intval($profileRow[$field] ?? 0);
        if ($connectorId > 0) {
            return $connectorId;
        }
    }
    return 0;
}

function stobePurposeToProfileConnectorColumn(string $purposeKey): string {
    if ($purposeKey === 'autochat') {
        return 'autochat_connector';
    }
    if ($purposeKey === 'diary') {
        return 'diary_connector';
    }
    if ($purposeKey === 'middleterm') {
        return 'middleterm_connector';
    }
    if ($purposeKey === 'backgroundlife') {
        return 'backgroundlife_connector';
    }
    if ($purposeKey === 'dynamic') {
        return 'dynamic_connector';
    }
    if ($purposeKey === 'relationship') {
        return 'relationship_connector';
    }
    return 'response_connector';
}

function stobeResolveNpcConnectorOverrideId(array|false $npcData, string $purposeKey): int {
    $overrides = stobeGetNpcRuntimeOverrideMap($npcData);
    if (count($overrides) === 0) {
        return 0;
    }

    $prefix = strtoupper(trim($purposeKey));
    if ($prefix === '') {
        $prefix = 'RESPONSE';
    }
    $candidateKeys = [
        $prefix . '_CONNECTOR',
        $prefix . '_CONNECTOR_ID',
    ];
    foreach ($candidateKeys as $key) {
        if (!array_key_exists($key, $overrides)) {
            continue;
        }
        $parsed = parseIntLike($overrides[$key], 0);
        if ($parsed > 0) {
            return $parsed;
        }
    }
    return 0;
}

function stobeFetchLlmConnectorById(int $connectorId): array|false {
    if ($connectorId <= 0) {
        return false;
    }
    $db = $GLOBALS["db"];
    $row = $db->fetchOne(
        "SELECT c.*, b.label AS api_badge_label, COALESCE(b.api_key, '') AS api_badge_key
         FROM core_llm_connector c
         LEFT JOIN core_api_badge b ON b.id = c.api_badge_id
         WHERE c.id = $1
         LIMIT 1",
        [$connectorId]
    );
    if (!$row || intval($row['id'] ?? 0) <= 0) {
        return false;
    }
    return $row;
}

function getProfileLlmConnectorForNpcByPurpose(array|false $npcData, string $purpose = 'response'): array|false {
    $purposeKey = strtolower(trim($purpose));
    if ($purposeKey === '') {
        $purposeKey = 'response';
    }
    // NPC override layer (highest precedence)
    $overrideConnectorId = stobeResolveNpcConnectorOverrideId($npcData, $purposeKey);
    if ($overrideConnectorId > 0) {
        $overrideConnector = stobeFetchLlmConnectorById($overrideConnectorId);
        if ($overrideConnector) {
            return $overrideConnector;
        }
    }

    $profileId = stobeResolveNpcProfileId($npcData);
    if ($profileId <= 0) {
        return false;
    }

    $db = $GLOBALS["db"];
    if ($purposeKey === 'response') {
        $profileRow = $db->fetchOne(
            "SELECT llm_primary_id,
                    llm_secondary_id,
                    llm_tertiary_id,
                    llm_quaternary_id,
                    response_connector,
                    metadata
             FROM core_profiles
             WHERE id = $1
             LIMIT 1",
            [$profileId]
        );
        if (!$profileRow) {
            return false;
        }
        return stobeFetchLlmConnectorById(stobeResolveProfileResponseConnectorId($profileRow));
    }

    $profileColumn = stobePurposeToProfileConnectorColumn($purposeKey);
    $row = $db->fetchOne(
        "SELECT c.*, b.label AS api_badge_label, COALESCE(b.api_key, '') AS api_badge_key
         FROM core_profiles p
         LEFT JOIN core_llm_connector c ON c.id = p.{$profileColumn}
         LEFT JOIN core_api_badge b ON b.id = c.api_badge_id
         WHERE p.id = $1
         LIMIT 1",
        [$profileId]
    );
    if (!$row || intval($row['id'] ?? 0) <= 0) {
        return false;
    }
    return $row;
}

function getLlmConfigForNpcPurpose(array|false $npcData, string $purpose = 'response'): array {
    $purposeKey = strtolower(trim($purpose));

    $connector = getProfileLlmConnectorForNpcByPurpose($npcData, $purposeKey);
    if ($connector) {
        return stobeBuildLlmConfigFromConnector($connector);
    }

    return stobeBuildLlmConfigFromConnector(getDefaultLlmConnector());
}

function getMemoryTxtaiUrl(): string {
    return stobeGetMiniMeServiceUrl();
}

function getMemoryUseText2Vec(): bool {
    // Stobe memory config: text2vec is always enabled.
    return true;
}

function getLlmConfigForNpc(array|false $npcData): array {
    $connector = getProfileLlmConnectorForNpcByPurpose($npcData, 'response');

    if (!$connector) {
        $connector = getDefaultLlmConnector();
    }

    return stobeBuildLlmConfigFromConnector($connector);
}

function normalizeJsonString(mixed $value): string {
    if (is_array($value)) {
        $encodedArray = json_encode($value, JSON_UNESCAPED_UNICODE);
        return is_string($encodedArray) ? $encodedArray : '{}';
    }

    $text = trim(strval($value));
    if ($text === '') {
        return '{}';
    }

    $decoded = json_decode($text, true);
    if ($decoded === null && strtolower($text) !== 'null') {
        return '{}';
    }

    if (!is_array($decoded)) {
        return '{}';
    }

    $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE);
    return is_string($encoded) ? $encoded : '{}';
}

function normalizeCoreNpcMetadata(mixed $value): array {
    $metadata = [];
    if (is_array($value)) {
        $metadata = $value;
    } elseif (is_string($value)) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $metadata = $decoded;
        }
    }

    if (count($metadata) === 0) {
        return [];
    }

    // These are persisted in first-class core_npc columns and do not need
    // duplication in metadata.
    $redundantTopLevel = [
        'name',
        'race',
        'faction',
        'faction_id',
        'factionID',
        'gender',
        'stringid',
        'id',
        'refid',
        'handle',
        'is_player_character',
        'is_animal',
        'is_slave',
        'bounty',
        'tags',
        'appearance',
        'equipment',
        'inventory',
        'skills',
        'speechstyle',
        'goals',
        'personality',
        'backstory',
        'emote_moods',
        'voiceid',
        'world_knowledge_tags',
        'blood',
        'hunger',
        'limbs',
        'medical',
        'stats',
        'nearby',
        'environment',
        'dist',
        'health',
        'source',
        'observer',
        'block',
        'hold',
        'passive',
        'jobs',
        'job_list',
        'ranged',
        'taunt',
        'sneak',
        'resource',
        'medic',
        'inventory',
        'metadata',
        'extended_data',
    ];
    foreach ($redundantTopLevel as $key) {
        if (array_key_exists($key, $metadata)) {
            unset($metadata[$key]);
        }
    }

    $stripNestedCoreFields = static function (array $entry): array {
        $redundantNested = [
            'name',
            'race',
            'faction',
            'faction_id',
            'factionID',
            'gender',
            'equipment',
            'storage_id',
            'stringid',
            'id',
            'refid',
            'handle',
        ];
        foreach ($redundantNested as $key) {
            if (array_key_exists($key, $entry)) {
                unset($entry[$key]);
            }
        }
        return $entry;
    };

    foreach (['nearby_snapshot', 'entry'] as $nestedKey) {
        if (!isset($metadata[$nestedKey]) || !is_array($metadata[$nestedKey])) {
            continue;
        }
        $metadata[$nestedKey] = $stripNestedCoreFields($metadata[$nestedKey]);
        unset($metadata[$nestedKey]);
    }

    if (array_key_exists('storage_id', $metadata)) {
        $normalizedStorageId = normalizeStorageIdToken($metadata['storage_id']);
        if ($normalizedStorageId === '') {
            unset($metadata['storage_id']);
        } else {
            $metadata['storage_id'] = $normalizedStorageId;
        }
    }

    return $metadata;
}

function normalizeCoreNpcExtendedData(mixed $value): array {
    $extended = [];
    if (is_array($value)) {
        $extended = $value;
    } elseif (is_string($value)) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $extended = $decoded;
        }
    }

    if (count($extended) === 0) {
        return [];
    }

    $extractEnvironmentFields = static function (array $candidate): array {
        $allowedKeys = [
            'indoors',
            'outdoors',
            'in_town',
            'building_serial',
            'building_id',
            'indoors_serial',
            'building_name',
            'indoors_name',
            'town_name',
            'town',
            'floor',
            'floor_num',
            'current_floor',
            'region',
            'zone',
            'zone_name',
            'weather',
            'x',
            'y',
            'z',
        ];
        $environmentSubset = [];
        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $candidate)) {
                continue;
            }
            $environmentSubset[$key] = $candidate[$key];
        }
        return $environmentSubset;
    };

    $extractSceneRows = static function (
        mixed $candidate,
        array $allowedKeys,
        int $maxRows = 24
    ): array {
        $rows = [];
        $decoded = [];
        if (is_array($candidate)) {
            $decoded = $candidate;
        } elseif (is_string($candidate)) {
            $parsed = json_decode($candidate, true);
            if (is_array($parsed)) {
                $decoded = $parsed;
            }
        }
        if (count($decoded) === 0) {
            return [];
        }

        $seen = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry) && !is_string($entry)) {
                continue;
            }

            if (is_string($entry)) {
                $name = trim($entry);
                if ($name === '') {
                    continue;
                }
                $entry = ['name' => $name];
            }

            $clean = [];
            foreach ($allowedKeys as $key) {
                if (!array_key_exists($key, $entry)) {
                    continue;
                }
                $clean[$key] = $entry[$key];
            }
            if (count($clean) === 0) {
                continue;
            }

            $dedupe = '';
            if (array_key_exists('refid', $clean)) {
                $dedupe = strtolower(trim(strval($clean['refid'])));
            }
            if ($dedupe === '' && array_key_exists('name', $clean)) {
                $dedupe = strtolower(trim(strval($clean['name'])));
            }
            if ($dedupe === '') {
                $dedupe = md5(json_encode($clean));
            }
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;

            $rows[] = $clean;
            if (count($rows) >= $maxRows) {
                break;
            }
        }
        return $rows;
    };

    $environment = [];
    if (isset($extended['environment']) && is_array($extended['environment'])) {
        $environment = $extractEnvironmentFields($extended['environment']);
    }
    if (count($environment) === 0) {
        foreach (['nearby_snapshot', 'entry'] as $candidateKey) {
            if (!isset($extended[$candidateKey]) || !is_array($extended[$candidateKey])) {
                continue;
            }
            $nested = $extended[$candidateKey];
            if (isset($nested['environment']) && is_array($nested['environment'])) {
                $environment = $extractEnvironmentFields($nested['environment']);
                break;
            }
            $environment = $extractEnvironmentFields($nested);
            break;
        }
    }

    $nearbyActors = $extractSceneRows(
        $extended['nearby_actors'] ?? ($extended['nearby'] ?? []),
        [
            'name',
            'refid',
            'serial',
            'race',
            'gender',
            'faction',
            'equipment',
            'current_action',
            'is_carrying',
            'carrying_target_name',
            'is_being_carried',
            'carried_by_name',
            'drunk_level',
            'is_drunk',
            'drunk_status',
            'drunk_seconds_remaining',
            'is_high',
            'high_status',
            'high_seconds_remaining',
            'high_hunger_rate_multiplier',
            'dist',
            'is_player_character',
            'is_animal',
        ],
        32
    );

    $nearbyItems = $extractSceneRows(
        $extended['nearby_items'] ?? [],
        ['name', 'refid', 'dist', 'quantity'],
        40
    );

    $pointsOfInterest = $extractSceneRows(
        $extended['points_of_interest'] ?? [],
        ['name', 'refid', 'serial', 'type', 'kind', 'location', 'dist'],
        32
    );
    $traderShopSources = $extractSceneRows(
        $extended['trader_shop_sources'] ?? [],
        ['name', 'serial', 'dist', 'building_class', 'special_function', 'inventory_item_count'],
        8
    );
    $traderShopSourceCount = parseIntLike(
        $extended['trader_shop_source_count'] ?? count($traderShopSources),
        count($traderShopSources)
    );
    if ($traderShopSourceCount < count($traderShopSources)) {
        $traderShopSourceCount = count($traderShopSources);
    }
    $traderShopItemCount = parseIntLike(
        $extended['trader_shop_item_count'] ?? 0,
        0
    );
    if ($traderShopItemCount < 0) {
        $traderShopItemCount = 0;
    }
    foreach ($traderShopSources as $source) {
        if (!is_array($source)) {
            continue;
        }
        $sourceItemCount = parseIntLike($source['inventory_item_count'] ?? 0, 0);
        if ($sourceItemCount > 0) {
            $traderShopItemCount += $sourceItemCount;
        }
    }
    $playerBase = function_exists('stobeNormalizePlayerBaseSnapshot')
        ? stobeNormalizePlayerBaseSnapshot($extended['player_base'] ?? [], false)
        : [];

    $redundantTopLevel = [
        'stats',
        'medical',
        'nearby',
        'inventory',
        'limbs',
        'blood',
        'hunger',
        'skills',
        'equipment',
        'metadata',
        'nearby_snapshot',
        'entry',
    ];
    foreach ($redundantTopLevel as $key) {
        if (array_key_exists($key, $extended)) {
            unset($extended[$key]);
        }
    }

    if (is_array($environment) && count($environment) > 0) {
        $extended['environment'] = $environment;
    } elseif (array_key_exists('environment', $extended)) {
        unset($extended['environment']);
    }

    if (count($nearbyActors) > 0) {
        $extended['nearby_actors'] = $nearbyActors;
    } elseif (array_key_exists('nearby_actors', $extended)) {
        unset($extended['nearby_actors']);
    }

    if (count($nearbyItems) > 0) {
        $extended['nearby_items'] = $nearbyItems;
    } elseif (array_key_exists('nearby_items', $extended)) {
        unset($extended['nearby_items']);
    }

    if (count($pointsOfInterest) > 0) {
        $extended['points_of_interest'] = $pointsOfInterest;
    } elseif (array_key_exists('points_of_interest', $extended)) {
        unset($extended['points_of_interest']);
    }

    if (count($traderShopSources) > 0) {
        $extended['trader_shop_sources'] = $traderShopSources;
        $extended['trader_shop_source_count'] = $traderShopSourceCount;
        $extended['trader_shop_item_count'] = $traderShopItemCount;
    } else {
        foreach (['trader_shop_sources', 'trader_shop_source_count', 'trader_shop_item_count'] as $key) {
            if (array_key_exists($key, $extended)) {
                unset($extended[$key]);
            }
        }
    }

    if (count($playerBase) > 0) {
        $extended['player_base'] = $playerBase;
    } elseif (array_key_exists('player_base', $extended)) {
        unset($extended['player_base']);
    }

    return $extended;
}

function coerceBoolean(mixed $value): bool {
    if (is_bool($value)) {
        return $value;
    }
    $normalized = strtolower(trim(strval($value)));
    return in_array($normalized, ['1', 'true', 't', 'yes', 'y', 'on', 'enabled', 'checked'], true);
}

function sanitizeForKenshi(string $text): string {
    $replacements = [
        "\u{2018}" => "'", "\u{2019}" => "'",
        "\u{201c}" => '"', "\u{201d}" => '"',
        "\u{2013}" => '-', "\u{2014}" => '-',
        "\u{2026}" => '...',
        "\u{00a0}" => ' ',
    ];
    $text = str_replace(array_keys($replacements), array_values($replacements), $text);
    $text = str_replace(["\r\n", "\\n", "\\r"], ["\n", "\n", ""], $text);
    return $text;
}

function parseIntLike(mixed $value, int $fallback): int {
    if (is_int($value)) {
        return $value;
    }
    if (is_float($value)) {
        return intval($value);
    }
    if (is_string($value)) {
        $normalized = trim($value);
        if ($normalized === '') {
            return $fallback;
        }
        if (substr($normalized, -1) === '%') {
            $normalized = rtrim(substr($normalized, 0, -1));
        }
        if (preg_match('/^-?[0-9]+$/', $normalized) === 1) {
            return intval($normalized);
        }
    }
    return $fallback;
}

function getCoreProfileMetadataForNpc(array|false $npcData): array {
    $profileId = stobeResolveNpcProfileId($npcData);
    if ($profileId <= 0) {
        return [];
    }
    return stobeGetProfileMetadataById($profileId);
}

function getNpcProfileIntegerSetting(
    array|false $npcData,
    array $metadataKeys,
    string $fallbackSettingId,
    int $default,
    int $min,
    int $max
): int {
    $resolvedRaw = null;
    $source = '';
    $value = $default;
    if (stobeReadLayeredSettingRaw($npcData, $metadataKeys, $fallbackSettingId, $resolvedRaw, $source)) {
        $value = parseIntLike($resolvedRaw, $default);
    }

    if ($value < $min) {
        $value = $min;
    }
    if ($value > $max) {
        $value = $max;
    }
    return $value;
}

function getNpcProfileBoolSetting(
    array|false $npcData,
    array $metadataKeys,
    string $fallbackSettingId,
    bool $default
): bool {
    $resolvedRaw = null;
    $source = '';
    if (stobeReadLayeredSettingRaw($npcData, $metadataKeys, $fallbackSettingId, $resolvedRaw, $source)) {
        return coerceBoolean($resolvedRaw);
    }
    return $default;
}

function getNpcProfileStringSetting(
    array|false $npcData,
    array $metadataKeys,
    string $fallbackSettingId,
    string $default
): string {
    $resolvedRaw = null;
    $source = '';
    if (stobeReadLayeredSettingRaw($npcData, $metadataKeys, $fallbackSettingId, $resolvedRaw, $source)) {
        if (is_scalar($resolvedRaw) || $resolvedRaw === null) {
            return strval($resolvedRaw ?? '');
        }
    }
    return $default;
}

function stobeGetNpcLayeredStringSetting(
    array|false $npcData,
    array $keys,
    string $fallbackSettingId,
    string $default = ''
): string {
    return getNpcProfileStringSetting($npcData, $keys, $fallbackSettingId, $default);
}

function DataRechatHistory(string $campaignFilter = '', int $windowSeconds = 120, int $limit = 10): array {
    $db = $GLOBALS["db"];

    $window = $windowSeconds;
    if ($window < 10) {
        $window = 10;
    } elseif ($window > 3600) {
        $window = 3600;
    }

    $safeLimit = $limit;
    if ($safeLimit < 1) {
        $safeLimit = 1;
    } elseif ($safeLimit > 50) {
        $safeLimit = 50;
    }

    return $db->fetchAll(
        "SELECT gamets
         FROM eventlog
         WHERE type IN ('rechat', 'narration', 'inputtext', 'inputtext_s')
           AND localts > $1
         ORDER BY gamets DESC, ts DESC
         LIMIT " . intval($safeLimit),
        [time() - $window]
    );
}

function stobeNpcIsIncapacitatedForRechat(array $npcData): bool {
    $state = stobeResolveNpcAwarenessState($npcData);
    if (in_array($state, ['dead', 'unconscious', 'knocked_out', 'sleeping'], true)) {
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
        $metaState = stobeNormalizeParticipantAwarenessStateToken(strval($metadata['character_state'] ?? ''));
        if (in_array($metaState, ['dead', 'unconscious', 'knocked_out', 'sleeping'], true)) {
            return true;
        }
        $medical = $metadata['medical'] ?? null;
        if (is_array($medical) && (!empty($medical['is_unconscious']) || !empty($medical['is_knocked_out']) || !empty($medical['is_knockedout']))) {
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
        $medical = $extended['medical'] ?? null;
        if (is_array($medical) && (!empty($medical['is_unconscious']) || !empty($medical['is_knocked_out']) || !empty($medical['is_knockedout']))) {
            return true;
        }
    }

    return false;
}

function isRechatEligible(array|false $npcData = false, string $campaignFilter = '', int $requestedDepth = 0): bool {
    if (is_array($npcData) && stobeNpcIsIncapacitatedForRechat($npcData)) {
        return false;
    }
    $maxRounds = getNpcProfileIntegerSetting(
        $npcData,
        ['RECHAT_RESPONSES'],
        '',
        3,
        1,
        12
    );

    // New clients provide explicit chain depth so limits are scoped per
    // conversation chain rather than by global recent rechat traffic.
    if ($requestedDepth > 0) {
        return $requestedDepth <= $maxRounds;
    }

    // Legacy fallback: if depth is unknown, keep the previous global
    // short-window guard to avoid unbounded loops.
    $rechatHistory = DataRechatHistory($campaignFilter, 120, 10);
    if (count($rechatHistory) >= $maxRounds) {
        return false;
    }
    return true;
}

