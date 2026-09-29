<?php

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tts_filter_presets.php';

/**
 * Legacy-style NPC master adapter.
 * Exposes Herika-era method names while using Stobe core_npc data helpers.
 */
class NpcMaster
{
    private sql $db;
    private string $lastError = '';

    public function __construct()
    {
        $this->db = $GLOBALS['db'];
    }

    // Plugin state uses NPC IDs and a separate namespace from core profile data.
    private function validatePluginDataTarget(int $npcId, string $pluginId): void
    {
        if ($npcId <= 0 || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $pluginId)) {
            throw new InvalidArgumentException('A positive NPC ID and a lowercase plugin ID are required.');
        }
    }

    public function getPluginData(int $npcId, string $pluginId): ?array
    {
        $this->validatePluginDataTarget($npcId, $pluginId);
        $row = $this->db->fetchOne(
            'SELECT plugin_extended_data -> $2::text AS plugin_data
             FROM core_npc_master WHERE id = $1',
            [$npcId, $pluginId]
        );
        if (!isset($row['plugin_data'])) {
            return null;
        }
        $data = json_decode($row['plugin_data'], false, 512, JSON_THROW_ON_ERROR);
        if (!$data instanceof stdClass) {
            throw new UnexpectedValueException('Stored plugin data must be a JSON object.');
        }
        // Preserve nested JSON objects and arrays when callers read and write a namespace.
        return get_object_vars($data);
    }

    // Replace only this plugin's object in one UPDATE; concurrent plugins retain their keys.
    public function setPluginData(int $npcId, string $pluginId, array $data): bool
    {
        $this->validatePluginDataTarget($npcId, $pluginId);
        foreach (array_keys($data) as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Plugin data must have string object keys.');
            }
        }
        $json = json_encode((object) $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $row = $this->db->fetchOne(
            'UPDATE core_npc_master
             SET plugin_extended_data = jsonb_set(plugin_extended_data, ARRAY[$2::text], $3::jsonb, true)
             WHERE id = $1 RETURNING id',
            [$npcId, $pluginId, $json]
        );
        return isset($row['id']);
    }

    public function deletePluginData(int $npcId, string $pluginId): bool
    {
        $this->validatePluginDataTarget($npcId, $pluginId);
        $row = $this->db->fetchOne(
            'UPDATE core_npc_master
             SET plugin_extended_data = plugin_extended_data - $2::text
             WHERE id = $1 RETURNING id',
            [$npcId, $pluginId]
        );
        return isset($row['id']);
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function getMessage(): string
    {
        return $this->lastError;
    }

    public function escape(string $value): string
    {
        return $this->db->escape($value);
    }

    public function fetchOne(string $query, array $params = []): array|false
    {
        return $this->db->fetchOne($query, $params);
    }

    public function fetchAll(string $query, array $params = []): array
    {
        return $this->db->fetchAll($query, $params);
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return ((int)$value) !== 0;
        }
        $raw = strtolower(trim((string)$value));
        if (in_array($raw, ['1', 'true', 'yes', 'on', 't', 'y'], true)) {
            return true;
        }
        if (in_array($raw, ['0', 'false', 'no', 'off', 'f', 'n', ''], true)) {
            return false;
        }
        return false;
    }

    private static function parseJsonValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    private static function applyBooleanMetadataOverride(array &$mapped, string $metadataKey, mixed $value): void
    {
        $metadata = [];
        if (isset($mapped['metadata']) && is_array($mapped['metadata'])) {
            $metadata = $mapped['metadata'];
        }
        if ($value === '' || $value === null) {
            unset($metadata[$metadataKey]);
        } else {
            $metadata[$metadataKey] = self::toBool($value);
        }
        $mapped['metadata'] = $metadata;
    }

    private function mapIncoming(array $input): array
    {
        $mapped = [];
        $name = trim((string)($input['npc_name'] ?? $input['name'] ?? ''));
        if ($name !== '') {
            $mapped['name'] = $name;
        }

        $fieldMap = [
            'prompt_head' => 'prompt_head',
            'npc_static_bio' => 'backstory',
            'backstory' => 'backstory',
            'personality' => 'personality',
            'relationships' => 'relationships',
            'occupation' => 'occupation',
            'appearance' => 'appearance',
            'equipment' => 'equipment',
            'inventory' => 'inventory',
            'skills' => 'skills',
            'speechstyle' => 'speechstyle',
            'goals' => 'goals',
            'voiceid' => 'voiceid',
            'gender' => 'gender',
            'race' => 'race',
            'faction' => 'faction',
            'profile_id' => 'profile_id',
            'gamets_last_updated' => 'gamets_last_updated',
            'bounty' => 'bounty',
            'tags' => 'tags',
            'md5' => 'md5',
            'blood' => 'blood',
            'hunger' => 'hunger',
            'world_knowledge_tags' => 'world_knowledge_tags',
            'emote_moods' => 'emote_moods',
            'npc_favorite' => 'npc_favorite',
            'lock_profile' => 'lock_profile',
            'is_animal' => 'is_animal',
            'is_slave' => 'is_slave',
        ];

        foreach ($fieldMap as $source => $target) {
            if (!array_key_exists($source, $input)) {
                continue;
            }
            $value = $input[$source];
            if (in_array($target, ['npc_favorite', 'lock_profile', 'is_animal', 'is_slave'], true)) {
                $mapped[$target] = self::toBool($value);
                continue;
            }
            if ($target === 'profile_id') {
                $raw = trim((string)$value);
                $mapped[$target] = ($raw === '') ? null : intval($raw);
                continue;
            }
            if ($target === 'gamets_last_updated') {
                $mapped[$target] = intval($value);
                continue;
            }
            if ($target === 'bounty') {
                $mapped[$target] = $value;
                continue;
            }
            $mapped[$target] = strval($value);
        }

        if (array_key_exists('metadata', $input)) {
            $mapped['metadata'] = self::parseJsonValue($input['metadata']);
            $storedPreset = stobeTtsFilterPresetFromMetadata($mapped['metadata']);
            $mapped['metadata'] = stobeMergeTtsFilterPresetIntoMetadata($mapped['metadata'], $storedPreset);
        }
        if (array_key_exists('tts_filter_preset', $input)) {
            $mapped['metadata'] = stobeMergeTtsFilterPresetIntoMetadata(
                $mapped['metadata'] ?? [],
                $input['tts_filter_preset']
            );
        }
        if (array_key_exists('extended_data', $input)) {
            $mapped['extended_data'] = self::parseJsonValue($input['extended_data']);
        }
        if (array_key_exists('individual_memory_enabled', $input)) {
            $enabled = self::toBool($input['individual_memory_enabled']);
            $mapped['individual_memory_enabled'] = $enabled;
            if (array_key_exists('extended_data', $mapped)) {
                if ($enabled) {
                    $mapped['extended_data']['individual_memory_enabled'] = 1;
                } else {
                    unset($mapped['extended_data']['individual_memory_enabled']);
                }
            }
        }
        if (array_key_exists('limbs', $input)) {
            $mapped['limbs'] = self::parseJsonValue($input['limbs']);
        }
        if (array_key_exists('dynamic_profile', $input)) {
            self::applyBooleanMetadataOverride($mapped, 'DYNAMIC_PROFILE_ENABLED', $input['dynamic_profile']);
        }

        $refid = trim((string)($input['refid'] ?? ''));
        if ($refid !== '') {
            $metadata = [];
            if (isset($mapped['metadata']) && is_array($mapped['metadata'])) {
                $metadata = $mapped['metadata'];
            }
            $metadata['storage_id'] = $refid;
            $mapped['metadata'] = $metadata;
        }

        return $mapped;
    }

    private function mapOutgoing(array $row): array
    {
        $metadata = normalizeCoreNpcMetadata($row['metadata'] ?? '{}');
        $storageId = trim((string)($metadata['storage_id'] ?? ''));
        $bountyPayload = $row['bounty_payload'] ?? ($row['bounty'] ?? '{}');
        $bountyAmount = function_exists('stobeBountyAmountFromPayload')
            ? stobeBountyAmountFromPayload($bountyPayload)
            : intval($row['bounty'] ?? 0);
        $legacy = $row;
        $legacy['npc_name'] = strval($row['name'] ?? '');
        $legacy['npc_static_bio'] = strval($row['backstory'] ?? '');
        $legacy['refid'] = $storageId;
        $legacy['bounty'] = $bountyAmount;
        $legacy['npc_favorite'] = self::toBool($row['npc_favorite'] ?? false) ? 1 : 0;
        $legacy['lock_profile'] = self::toBool($row['lock_profile'] ?? false) ? 1 : 0;
        $legacy['is_animal'] = self::toBool($row['is_animal'] ?? false) ? 1 : 0;
        $legacy['is_slave'] = self::toBool($row['is_slave'] ?? false) ? 1 : 0;
        if (function_exists('stobeNormalizeBountyJsonString')) {
            $legacy['bounty_payload'] = stobeNormalizeBountyJsonString($bountyPayload);
        } elseif (function_exists('stobeNormalizeBountyPayload') && function_exists('normalizeJsonString')) {
            $legacy['bounty_payload'] = normalizeJsonString(stobeNormalizeBountyPayload($bountyPayload));
        } else {
            $legacy['bounty_payload'] = is_string($bountyPayload) ? $bountyPayload : '{}';
        }
        $legacy['core'] = $legacy['core'] ?? '';
        $legacy['base'] = $legacy['base'] ?? '';
        $legacy['created'] = $legacy['created'] ?? ($row['created_at'] ?? '');
        $legacy['updated'] = $legacy['updated'] ?? ($row['updated_at'] ?? '');
        return $legacy;
    }

    private function compatSelectSql(): string
    {
        return "SELECT
                    n.id,
                    n.name,
                    n.name AS npc_name,
                    n.original_name,
                    CASE WHEN COALESCE(n.npc_favorite, FALSE) THEN 1 ELSE 0 END AS npc_favorite,
                    CASE WHEN COALESCE(n.lock_profile, FALSE) THEN 1 ELSE 0 END AS lock_profile,
                    n.prompt_head,
                    n.backstory,
                    n.backstory AS npc_static_bio,
                    COALESCE(NULLIF(to_jsonb(n)->>'world_knowledge_tags', ''), '') AS world_knowledge_tags,
                    n.emote_moods,
                    n.personality,
                    n.relationships,
                    n.occupation,
                    n.appearance,
                    n.equipment,
                    n.inventory,
                    n.skills,
                    n.speechstyle,
                    n.goals,
                    n.voiceid,
                    n.metadata,
                    n.extended_data,
                    n.plugin_extended_data,
                    n.gender,
                    n.race,
                    COALESCE(NULLIF(n.metadata->>'storage_id', ''), NULLIF(n.metadata->>'refid', ''), '') AS refid,
                    n.profile_id,
                    CASE
                        WHEN n.metadata ? 'DYNAMIC_PROFILE_ENABLED' THEN
                            CASE
                                WHEN LOWER(COALESCE(n.metadata->>'DYNAMIC_PROFILE_ENABLED', '')) IN ('1', 'true', 'yes', 'on')
                                    THEN 1
                                ELSE 0
                            END
                        ELSE NULL
                    END AS dynamic_profile,
                    n.gamets_last_updated,
                    n.tags,
                    n.md5,
                    CASE
                        WHEN jsonb_typeof(to_jsonb(n)->'bounty') = 'object'
                             AND COALESCE(to_jsonb(n)->'bounty'->>'total', '') ~ '^[0-9]+$'
                        THEN (to_jsonb(n)->'bounty'->>'total')::INT
                        WHEN COALESCE(to_jsonb(n)->>'bounty', '') ~ '^[0-9]+$'
                        THEN (to_jsonb(n)->>'bounty')::INT
                        ELSE 0
                    END AS bounty,
                    to_jsonb(n)->'bounty' AS bounty_payload,
                    n.blood,
                    n.hunger,
                    n.limbs,
                    n.faction,
                    ''::text AS core,
                    ''::text AS base,
                    n.created_at AS created,
                    n.updated_at AS updated
                FROM core_npc n";
    }

    public function countAll(string $where): int
    {
        $safeWhere = trim($where);
        if ($safeWhere === '') {
            $safeWhere = '1=1';
        }
        $row = $this->db->fetchOne("SELECT COUNT(*) AS c FROM (" . $this->compatSelectSql() . ") core_npc_master WHERE {$safeWhere}");
        return intval($row['c'] ?? 0);
    }

    public function getAll(string $whereOrderLimit = ''): array
    {
        $suffix = trim($whereOrderLimit);
        $query = "SELECT * FROM (" . $this->compatSelectSql() . ") core_npc_master";
        if ($suffix !== '') {
            if (preg_match('/^(where|order\\s+by|limit|offset)\\b/i', $suffix) === 1) {
                $query .= " " . $suffix;
            } else {
                $query .= " WHERE " . $suffix;
            }
        }
        $rows = $this->db->fetchAll($query);
        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->mapOutgoing($row);
        }
        return $result;
    }

    public function getById(int $id): array|false
    {
        $row = getNpcById($id);
        if (!$row) {
            return false;
        }
        return $this->mapOutgoing($row);
    }

    public function getByName(string $name): array|false
    {
        $safeName = trim($name);
        if ($safeName === '') {
            return false;
        }
        $row = $this->db->fetchOne(
            "SELECT *
             FROM core_npc
             WHERE LOWER(name) = LOWER($1)
             LIMIT 1",
            [$safeName]
        );
        if (!$row) {
            return false;
        }
        return $this->mapOutgoing($row);
    }

    public function create(array $input): int
    {
        $this->lastError = '';
        $mapped = $this->mapIncoming($input);
        $name = trim((string)($mapped['name'] ?? ''));
        if ($name === '') {
            $this->lastError = 'NPC name is required';
            return 0;
        }
        if (array_key_exists('individual_memory_enabled', $mapped)) {
            $extendedData = $mapped['extended_data'] ?? [];
            if ($mapped['individual_memory_enabled']) {
                $extendedData['individual_memory_enabled'] = 1;
            } else {
                unset($extendedData['individual_memory_enabled']);
            }
            $mapped['extended_data'] = $extendedData;
            unset($mapped['individual_memory_enabled']);
        }

        try {
            storeNpcProfile($name, $mapped, ['history_reason' => 'ui_create']);
            $row = getNpcData($name);
            return intval($row['id'] ?? 0);
        } catch (Throwable $exception) {
            $this->lastError = $exception->getMessage();
            return 0;
        }
    }

    public function update(int $id, array $input): bool
    {
        $this->lastError = '';
        if ($id <= 0) {
            $this->lastError = 'Invalid NPC id';
            return false;
        }

        $mapped = $this->mapIncoming($input);
        try {
            if (isset($mapped['name']) && trim((string)$mapped['name']) !== '') {
                $renamed = $this->db->exec(
                    "UPDATE core_npc SET name = $1, updated_at = NOW() WHERE id = $2",
                    [trim((string)$mapped['name']), $id]
                );
                if ($renamed === false) {
                    $this->lastError = 'NPC rename failed';
                    return false;
                }
                unset($mapped['name']);
            }
            if (count($mapped) > 0) {
                if (!updateNpcById($id, $mapped)) {
                    $this->lastError = 'NPC update failed';
                    return false;
                }
            }
            return true;
        } catch (Throwable $exception) {
            $this->lastError = $exception->getMessage();
            return false;
        }
    }

    public function delete(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        deleteNpc($id);
    }

    public function backupNpcById(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        if (!function_exists('stobeFetchNpcRowForHistoryById') || !function_exists('stobeInsertNpcHistorySnapshotFromRow')) {
            return;
        }
        $row = stobeFetchNpcRowForHistoryById($id);
        if ($row) {
            stobeInsertNpcHistorySnapshotFromRow($row, 'ui_manual_backup');
        }
    }
}
