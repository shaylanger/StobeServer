<?php

/**
 * Database-driven settings for StobeServer.
 * All configuration lives in the general_settings table.
 * No conf.php, no .ini files.
 */

function getSetting(string $id, string $default = ''): string {
    $db = $GLOBALS["db"];
    $row = $db->fetchOne(
        "SELECT value FROM general_settings WHERE id = $1",
        [$id]
    );
    $value = $row ? $row['value'] : $default;
    if ($id === 'PLAYER_NAME' && $value !== '' && strtolower($value) === $value) {
        // Typed as "shay" in settings: use the in-game casing ("Shay") in prompts and actions.
        static $playerNameCase = [];
        if (!isset($playerNameCase[$value])) {
            $named = $db->fetchOne("SELECT name FROM core_npc_master WHERE LOWER(name) = LOWER($1) LIMIT 1", [$value]);
            $playerNameCase[$value] = is_array($named) && trim(strval($named['name'] ?? '')) !== '' ? strval($named['name']) : $value;
        }
        $value = $playerNameCase[$value];
    }
    return $value;
}

function getConfOpt(string $id, string $default = ''): string {
    $db = $GLOBALS["db"];
    $row = $db->fetchOne(
        "SELECT value FROM conf_opts WHERE id = $1",
        [$id]
    );
    return $row ? strval($row['value'] ?? '') : $default;
}

function setConfOpt(string $id, string $value, bool $onlyIfChanged = false): bool {
    $db = $GLOBALS["db"];
    $safeId = trim($id);
    if ($safeId === '') {
        return false;
    }

    if ($onlyIfChanged) {
        $existing = $db->fetchOne(
            "SELECT value FROM conf_opts WHERE id = $1 LIMIT 1",
            [$safeId]
        );
        if ($existing && strval($existing['value'] ?? '') === $value) {
            return false;
        }
    }

    $db->exec(
        "INSERT INTO conf_opts (id, value, updated_at)
         VALUES ($1, $2, NOW())
         ON CONFLICT (id) DO UPDATE
         SET value = EXCLUDED.value,
             updated_at = NOW()",
        [$safeId, $value]
    );
    return true;
}

function setSetting(string $id, string $value, string $category = 'general', string $description = ''): void {
    $db = $GLOBALS["db"];
    if ($description) {
        $db->exec(
            "INSERT INTO general_settings (id, value, description, updated_at)
             VALUES ($1, $2, $3, NOW())
             ON CONFLICT (id) DO UPDATE
             SET value = $2,
                 description = EXCLUDED.description,
                 updated_at = NOW()",
            [$id, $value, $description]
        );
    } else {
        $db->exec(
            "INSERT INTO general_settings (id, value, updated_at)
             VALUES ($1, $2, NOW())
             ON CONFLICT (id) DO UPDATE SET value = $2, updated_at = NOW()",
            [$id, $value]
        );
    }
}

function getSettingBool(string $id, bool $default = false): bool {
    $val = getSetting($id, $default ? 'true' : 'false');
    return in_array(strtolower($val), ['true', '1', 'yes'], true);
}

function getSettingInt(string $id, int $default = 0): int {
    return intval(getSetting($id, strval($default)));
}

function getSettingFloat(string $id, float $default = 0.0): float {
    return floatval(getSetting($id, strval($default)));
}

function stobeNormalizeMiniMeServiceUrl(string $configuredUrl): string {
    $defaultUrl = 'http://127.0.0.1:8082';
    $configuredUrl = rtrim(trim($configuredUrl), '/');
    if ($configuredUrl === '' || filter_var($configuredUrl, FILTER_VALIDATE_URL) === false) {
        return $defaultUrl;
    }

    $parts = parse_url($configuredUrl);
    $scheme = strtolower(strval($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
        return $defaultUrl;
    }
    return $configuredUrl;
}

function stobeGetMiniMeServiceUrl(): string {
    return stobeNormalizeMiniMeServiceUrl(getSetting('TXTAI_URL', 'http://127.0.0.1:8082'));
}

function stobeMiniMeServiceEndpoint(string $path, array $query = [], ?string $baseUrl = null): string {
    $url = stobeNormalizeMiniMeServiceUrl($baseUrl ?? stobeGetMiniMeServiceUrl())
        . '/' . ltrim($path, '/');
    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    return $url;
}

function stobeMiniMeServiceSocket(?string $baseUrl = null): array {
    $parts = parse_url(stobeNormalizeMiniMeServiceUrl($baseUrl ?? stobeGetMiniMeServiceUrl()));
    $scheme = strtolower(strval($parts['scheme'] ?? 'http'));
    $host = strval($parts['host'] ?? '127.0.0.1');
    $port = intval($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    return [
        'host' => $host,
        'port' => $port,
        'socket_host' => ($scheme === 'https' ? 'ssl://' : '') . $host,
    ];
}

function stobeGetPromptContextOptionCatalog(): array {
    return [
        'enabled_sections' => [
            'world' => [
                'label' => '<world>',
                'description' => 'In-game date, location, weather, and floor context.',
            ],
            'knowledge' => [
                'label' => '<knowledge>',
                'description' => 'Top-level knowledge block for lore hints and optional player-faction prompt entries.',
            ],
            'player_faction_funds' => [
                'label' => '<player_faction_funds>',
                'description' => 'Shared cats available to player-faction NPCs.',
            ],
            'player_base' => [
                'label' => '<player_base>',
                'description' => 'Current player-base power, security, infrastructure, supplies, production, farms, and squad presence while this actor is inside its perimeter.',
            ],
            'available_actions_list' => [
                'label' => '<available_actions_list>',
                'description' => 'Available in-game actions the actor may choose from.',
            ],
            'nearby_actors' => [
                'label' => '<nearby_actors>',
                'description' => 'Nearby NPCs, creatures, and social scene participants.',
            ],
            'nearby_player_allies' => [
                'label' => '<nearby_player_allies>',
                'description' => 'Nearby squadmates or allied player-faction members.',
            ],
            'nearby_items' => [
                'label' => '<nearby_items>',
                'description' => 'Nearby item list with short descriptions.',
            ],
            'points_of_interest' => [
                'label' => '<points_of_interest>',
                'description' => 'Nearby locations or notable destinations.',
            ],
            'combat_priority' => [
                'label' => '<combat_priority>',
                'description' => 'Emergency combat-priority guidance when the actor is actively fighting.',
            ],
            'nearby_context_json' => [
                'label' => '<nearby_context_json>',
                'description' => 'Raw nearby actor payload appended as escaped JSON.',
            ],
            'detailed_context_json' => [
                'label' => '<detailed_context_json>',
                'description' => 'Raw detailed scene payload appended as escaped JSON.',
            ],
        ],
        'enabled_character_subsections' => [
            'basic_summary' => [
                'label' => '<basic_summary>',
                'description' => 'Core backstory or biography summary.',
            ],
            'personality' => [
                'label' => '<personality>',
                'description' => 'Behavioral traits and temperament.',
            ],
            'appearance' => [
                'label' => '<appearance>',
                'description' => 'Physical appearance and identifying features.',
            ],
            'relationships' => [
                'label' => '<relationships>',
                'description' => 'Named relationship map and social ties.',
            ],
            'occupation' => [
                'label' => '<occupation>',
                'description' => 'Job, role, or social function.',
            ],
            'bounty' => [
                'label' => '<bounty>',
                'description' => 'Bounty summary when applicable.',
            ],
            'skills' => [
                'label' => '<skills>',
                'description' => 'Grouped skill proficiency summary.',
            ],
            'speech_style' => [
                'label' => '<speech_style>',
                'description' => 'How the actor tends to speak.',
            ],
            'goals' => [
                'label' => '<goals>',
                'description' => 'Current motivations and ambitions.',
            ],
            'middle_term_memory' => [
                'label' => '<middle_term_memory>',
                'description' => 'Latest packed middle-term memory summary.',
            ],
        ],
        'enabled_state_subsections' => [
            'current_condition' => [
                'label' => '<character_state>.condition',
                'description' => 'Condition and physiology details such as health, blood, hunger, limbs, and intoxication.',
            ],
            'activity_state' => [
                'label' => '<character_state>.activity',
                'description' => 'Current action, combat/activity flags, and attack target.',
            ],
            'equipment' => [
                'label' => '<character_state>.equipment',
                'description' => 'Currently equipped gear.',
            ],
            'personal_inventory' => [
                'label' => '<character_state>.inventory',
                'description' => 'Personal inventory contents.',
            ],
            'merchant_inventory' => [
                'label' => '<character_state>.merchant_inventory',
                'description' => 'Merchant inventory when the actor is a trader.',
            ],
        ],
        'enabled_knowledge_subsections' => [
            'world_knowledge' => [
                'label' => '<knowledge>.world_entries',
                'description' => 'World-knowledge entries retrieved from Stobe lore data.',
            ],
            'player_faction_prompt' => [
                'label' => '<knowledge>.player_faction_prompt',
                'description' => 'Optional player-faction instruction entry inside the knowledge block.',
            ],
        ],
    ];
}

function stobeGetDefaultPromptContextOptions(): array
{
    $catalog = stobeGetPromptContextOptionCatalog();
    $defaults = [];
    foreach ($catalog as $bucket => $options) {
        $defaults[$bucket] = array_keys($options);
    }

    return $defaults;
}

function stobeNormalizePromptContextOptions($rawOptions): array
{
    $catalog = stobeGetPromptContextOptionCatalog();
    $defaults = stobeGetDefaultPromptContextOptions();

    if (is_string($rawOptions) && trim($rawOptions) !== '') {
        $decoded = json_decode($rawOptions, true);
        if (is_array($decoded)) {
            $rawOptions = $decoded;
        }
    }

    if (!is_array($rawOptions)) {
        return $defaults;
    }

    $normalized = [];
    foreach ($defaults as $bucket => $defaultIds) {
        $hasBucket = array_key_exists($bucket, $rawOptions);
        $rawIds = $hasBucket ? $rawOptions[$bucket] : $defaultIds;
        if (!is_array($rawIds)) {
            $rawIds = $hasBucket ? [] : $defaultIds;
        }

        $allowedIds = array_keys($catalog[$bucket] ?? []);
        $enabled = [];
        foreach ($rawIds as $id) {
            $id = strval($id);
            if ($id !== '' && in_array($id, $allowedIds, true) && !in_array($id, $enabled, true)) {
                $enabled[] = $id;
            }
        }

        $normalized[$bucket] = $hasBucket
            ? $enabled
            : (!empty($enabled) ? $enabled : $defaultIds);
    }

    return $normalized;
}

function stobeGetPromptContextOptions(): array
{
    static $cachedRaw = null;
    static $cachedOptions = null;

    $rawValue = getSetting('PROMPT_CONTEXT_OPTIONS', '');
    if (is_array($cachedOptions) && $cachedRaw === $rawValue) {
        return $cachedOptions;
    }

    $cachedRaw = $rawValue;
    $cachedOptions = stobeNormalizePromptContextOptions($rawValue);
    return $cachedOptions;
}

function stobePromptContextOptionEnabled(string $bucket, string $id): bool
{
    $options = stobeGetPromptContextOptions();
    $enabled = $options[$bucket] ?? [];
    return in_array($id, $enabled, true);
}

function normalizeDialogueMode(string $mode): string {
    $normalized = strtolower(trim($mode));
    if ($normalized === '') {
        return 'talk';
    }

    $allowed = ['talk', 'shout', 'whisper', 'autochat', 'cheat', 'narrator'];
    if (!in_array($normalized, $allowed, true)) {
        return 'talk';
    }

    return $normalized;
}

function getDialogueMode(): string {
    return 'talk';
}

function setDialogueMode(string $mode): string {
    $normalized = normalizeDialogueMode($mode);
    return $normalized;
}

function stobeStringIsTruthy(string $value): bool {
    $normalized = strtolower(trim($value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function stobeIsRelationshipSystemEnabled(): bool {
    static $cached = null;
    if (is_bool($cached)) {
        return $cached;
    }

    $missingSentinel = '__STOBE_RELATIONSHIP_SYSTEM_MISSING__';

    $primary = getSetting('RELATIONSHIP_SYSTEM', $missingSentinel);
    if ($primary !== $missingSentinel) {
        $cached = stobeStringIsTruthy(strval($primary));
        return $cached;
    }

    $legacy = getSetting('RELATIONSHIP_SYSTEM_ENABLED', $missingSentinel);
    if ($legacy !== $missingSentinel) {
        $cached = stobeStringIsTruthy(strval($legacy));
        return $cached;
    }

    $cached = true;
    return $cached;
}

function stobeMarkQuickstartCompleted(bool $completed = true): void {
    setSetting(
        'STOBE_QUICKSTART_COMPLETED',
        $completed ? 'true' : 'false',
        'general',
        'When false, first dashboard visit redirects to the quickstart menu.'
    );
}

function stobeIsQuickstartCompleted(): bool {
    return stobeStringIsTruthy(getSetting('STOBE_QUICKSTART_COMPLETED', 'false'));
}

function stobeShouldRedirectToQuickstart(): bool {
    $rawSetting = getSetting('STOBE_QUICKSTART_COMPLETED', '');
    if ($rawSetting !== '') {
        return !stobeStringIsTruthy($rawSetting);
    }

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        return true;
    }

    // Existing installs with live data should not be forced through quickstart.
    $hasEventLog = false;
    $hasNpcData = false;
    $hasApiKey = false;
    try {
        $row = $db->fetchOne("SELECT 1 AS v FROM eventlog LIMIT 1");
        $hasEventLog = is_array($row) && isset($row['v']);
    } catch (Throwable $exception) {
        $hasEventLog = false;
    }
    try {
        $row = $db->fetchOne("SELECT 1 AS v FROM core_npc LIMIT 1");
        $hasNpcData = is_array($row) && isset($row['v']);
    } catch (Throwable $exception) {
        $hasNpcData = false;
    }
    try {
        $row = $db->fetchOne(
            "SELECT 1 AS v
             FROM core_api_badge
             WHERE BTRIM(COALESCE(api_key, '')) <> ''
             LIMIT 1"
        );
        $hasApiKey = is_array($row) && isset($row['v']);
    } catch (Throwable $exception) {
        $hasApiKey = false;
    }

    $isExistingInstall = ($hasEventLog || $hasNpcData || $hasApiKey);
    stobeMarkQuickstartCompleted($isExistingInstall);
    return !$isExistingInstall;
}
