<?php

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tts_filter_presets.php';

class Narrator
{
    public const CANONICAL_NAME = 'The Narrator';
    public const DEFAULT_ROLEPLAY_NAME = self::CANONICAL_NAME;

    private string $table = 'core_narrator';
    private sql $db;

    public function __construct()
    {
        if (!isset($GLOBALS["db"]) || !($GLOBALS["db"] instanceof sql)) {
            throw new Exception("Database connection not initialized for Narrator.");
        }
        $this->db = $GLOBALS["db"];
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} (
                id TEXT PRIMARY KEY,
                value TEXT
            )"
        );
    }

    public static function defaultSeedValues(int $profileId = 1): array
    {
        $safeProfileId = $profileId > 0 ? $profileId : 1;
        return [
            'roleplay_name' => self::DEFAULT_ROLEPLAY_NAME,
            'profile_id' => strval($safeProfileId),
            'voiceid' => 'stobenarrator',
            'tts_filter_preset' => 'none',
            'core' => "The Narrator is a male voice within the player's mind. His job is to help the player as they navigate the world of Kenshi. Provide unique insight and descriptions of what is going on in the world.",
            'background' => "A guiding voice that describes the world, events, and transitions. He is not a character, but a voice within the player's mind.",
            'personality' => 'Laid-back, observant, and friendly; describes scenes with calm confidence.',
            'speechstyle' => 'Relaxed and conversational, with vivid scene descriptions in one or two concise sentences.',
            'goals' => '',
            'oghma_knowledge' => 'knowall',
            'gender' => 'male',
            'prompt_head' => '',
        ];
    }

    private function escape(string $value): string
    {
        return $this->db->escape($value);
    }

    public function get(string $key): ?string
    {
        $safe = $this->escape($key);
        $row = $this->db->fetchOne(
            "SELECT value FROM {$this->table} WHERE id = '{$safe}' LIMIT 1"
        );
        if (!$row || !array_key_exists('value', $row)) {
            return null;
        }
        return strval($row['value']);
    }

    public function getAll(): array
    {
        $rows = $this->db->fetchAll("SELECT id, value FROM {$this->table}");
        $result = [];
        foreach ($rows as $row) {
            $id = trim(strval($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $result[$id] = strval($row['value'] ?? '');
        }
        return $result;
    }

    public function set(string $key, string $value): bool
    {
        $safeKey = trim($key);
        if ($safeKey === '') {
            return false;
        }
        $this->db->exec(
            "INSERT INTO {$this->table} (id, value)
             VALUES ($1, $2)
             ON CONFLICT (id) DO UPDATE
             SET value = EXCLUDED.value",
            [$safeKey, $value]
        );
        return true;
    }

    public function setMultiple(array $values): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            if (!$this->set(strval($key), strval($value))) {
                $ok = false;
            }
        }
        return $ok;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return $default;
        }
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        if ($value === null || trim($value) === '') {
            return $default;
        }
        return intval($value);
    }

    /**
     * Normalize the prompt-facing narrator name without changing its canonical identity.
     */
    public static function normalizeRoleplayName(mixed $value): string
    {
        $name = preg_replace('/\s+/u', ' ', trim(strval($value))) ?? '';
        if ($name === '') {
            return self::DEFAULT_ROLEPLAY_NAME;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($length > 64) {
            throw new InvalidArgumentException('Narrator name must be 64 characters or fewer.');
        }
        if (preg_match("/^[\\p{L}\\p{M}\\p{N} .'’\\-]+$/u", $name) !== 1) {
            throw new InvalidArgumentException('Narrator name may only contain letters, numbers, spaces, apostrophes, periods, and hyphens.');
        }
        if (in_array(strtolower($name), ['player', 'everyone'], true)) {
            throw new InvalidArgumentException("Narrator name cannot be '{$name}'.");
        }
        return $name;
    }

    public function getRoleplayName(): string
    {
        try {
            return self::normalizeRoleplayName($this->get('roleplay_name'));
        } catch (InvalidArgumentException) {
            return self::DEFAULT_ROLEPLAY_NAME;
        }
    }

    public function getProfileId(): ?int
    {
        $raw = $this->get('profile_id');
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $profileId = intval($raw);
        return $profileId > 0 ? $profileId : null;
    }

    public function loadIntoGlobals(): void
    {
        $all = $this->getAll();
        $keyMapping = [
            'roleplay_name' => ['NARRATOR_ROLEPLAY_NAME', 'string', self::DEFAULT_ROLEPLAY_NAME],
            'enabled' => ['NARRATOR_TALKS', 'bool', true],
            'welcome_enabled' => ['NARRATOR_WELCOME', 'bool', false],
            'welcome_cooldown' => ['NARRATOR_WELCOME_COOLDOWN', 'int', 10],
            'random_enabled' => ['RANDOM_NARATION', 'bool', false],
            'random_chance' => ['RANDOM_NARATION_CHANCE', 'int', 15],
            'random_cooldown' => ['RANDOM_NARRATION_COOLDOWN', 'int', 10],
            'diary_enabled' => ['NARRATOR_DIARY_ENABLED', 'bool', false],
            'auto_diary_enabled' => ['NARRATOR_AUTO_DIARY_ENABLED', 'bool', false],
            'only_diary_access' => ['NARRATOR_ONLY_DIARY_ACCESS', 'bool', false],
            'inline_narration_mode' => ['INLINE_NARRATION_MODE', 'string', 'disabled'],
            'preserve_inline_narration_context' => ['PRESERVE_INLINE_NARRATION_CONTEXT', 'bool', false],
            'dynamic_profile' => ['DYNAMIC_PROFILE', 'bool', false],
            'connector_id' => ['NARRATOR_CONNECTOR_ID', 'int', null],
        ];

        foreach ($keyMapping as $dbKey => $config) {
            [$globalKey, $type, $default] = $config;
            if (array_key_exists($dbKey, $all)) {
                $raw = strval($all[$dbKey]);
                if ($type === 'bool') {
                    $GLOBALS[$globalKey] = in_array(
                        strtolower(trim($raw)),
                        ['1', 'true', 'yes', 'on'],
                        true
                    );
                } elseif ($type === 'int') {
                    $value = intval($raw);
                    if ($dbKey === 'random_cooldown') {
                        $value = max(0, min(30, $value));
                    }
                    $GLOBALS[$globalKey] = $value;
                } else {
                    $GLOBALS[$globalKey] = $raw;
                }
                continue;
            }
            if (!isset($GLOBALS[$globalKey])) {
                $GLOBALS[$globalKey] = $default;
            }
        }
    }

    public function getNarratorData(): array
    {
        $all = $this->getAll();
        $defaults = self::defaultSeedValues(1);
        $resolvedProfileId = $this->getProfileId();
        if ($resolvedProfileId === null || $resolvedProfileId <= 0) {
            $resolvedProfileId = intval($defaults['profile_id']);
        }
        return [
            'id' => 1,
            'npc_name' => self::CANONICAL_NAME,
            'roleplay_name' => $this->getRoleplayName(),
            'profile_id' => $resolvedProfileId,
            'voiceid' => $all['voiceid'] ?? $defaults['voiceid'],
            'tts_filter_preset' => stobeNormalizeTtsFilterPresetId($all['tts_filter_preset'] ?? $defaults['tts_filter_preset']),
            'core' => $all['core'] ?? $defaults['core'],
            'npc_static_bio' => $all['background'] ?? $defaults['background'],
            'personality' => $all['personality'] ?? $defaults['personality'],
            'speechstyle' => $all['speechstyle'] ?? $defaults['speechstyle'],
            'goals' => $all['goals'] ?? $defaults['goals'],
            'oghma_knowledge_tags' => $all['oghma_knowledge'] ?? $defaults['oghma_knowledge'],
            'gender' => $all['gender'] ?? $defaults['gender'],
            'prompt_head' => $all['prompt_head'] ?? $defaults['prompt_head'],
            'lock_profile' => 1,
            'npc_favorite' => 1,
            'md5' => md5(self::CANONICAL_NAME),
            'dynamic_profile' => $this->getBool('dynamic_profile', false) ? 1 : 0,
        ];
    }

    public function getDynamicProfileFields(): array
    {
        $raw = $this->get('dynamic_profile_fields');
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $valid = ['personality', 'speechstyle', 'goals'];
        $result = [];
        foreach ($decoded as $field) {
            $fieldKey = strtolower(trim(strval($field)));
            if ($fieldKey === '' || !in_array($fieldKey, $valid, true)) {
                continue;
            }
            if (!in_array($fieldKey, $result, true)) {
                $result[] = $fieldKey;
            }
        }
        return $result;
    }

    public function setDynamicProfileFields(array $fields): bool
    {
        $valid = ['personality', 'speechstyle', 'goals'];
        $result = [];
        foreach ($fields as $field) {
            $fieldKey = strtolower(trim(strval($field)));
            if ($fieldKey === '' || !in_array($fieldKey, $valid, true)) {
                continue;
            }
            if (!in_array($fieldKey, $result, true)) {
                $result[] = $fieldKey;
            }
        }
        $encoded = json_encode($result);
        if (!is_string($encoded)) {
            $encoded = '[]';
        }
        return $this->set('dynamic_profile_fields', $encoded);
    }
}
