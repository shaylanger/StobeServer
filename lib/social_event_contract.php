<?php
declare(strict_types=1);

// Native facts and per-observer knowledge remain separate. No bootstrap or DB side effects.
final class SocialEventContract
{
    public const VERSION = 1;
    public const MAX_BYTES = 65536;
    public const MAX_WITNESSES = 32;
    public const KINDS = ['combat', 'combat_start', 'combat_end', 'major_damage', 'knockout',
        'recovered', 'death', 'limb_loss', 'healing', 'slavery', 'imprisonment', 'carry',
        'looting', 'item_pickup', 'trade', 'eat', 'predation', 'dialogue', 'semantic',
        // Structured native facts (facts.source = "structured"), explicit roles: actor did it, target had it done.
        'attack', 'harm', 'item_transfer', 'enslaved', 'freed', 'aid', 'carry_start', 'carry_end', 'placed', 'eat', 'item_gain'];

    public static function token(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $value)) {
            throw new InvalidArgumentException('Invalid ' . $field);
        }
        return $value;
    }

    public static function number(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 0 || $value > 9007199254740991) {
            throw new InvalidArgumentException('Invalid ' . $field);
        }
        return $value;
    }

    public static function entity(mixed $value, string $field): ?array
    {
        if ($value === null) return null;
        if (!is_array($value) || array_is_list($value)) throw new InvalidArgumentException('Invalid ' . $field);
        $key = self::token($value['entity_key'] ?? null, $field . '.entity_key');
        $serial = self::number($value['serial'] ?? null, $field . '.serial');
        $name = $value['name'] ?? '';
        if (!is_string($name) || strlen($name) > 180 || preg_match('/[\x00-\x1f]/', $name)) {
            throw new InvalidArgumentException('Invalid entity name');
        }
        foreach (['in_player_faction', 'in_player_squad', 'conscious'] as $flag) {
            if (isset($value[$flag]) && !is_bool($value[$flag])) throw new InvalidArgumentException('Invalid membership');
        }
        foreach (['storage_id', 'faction'] as $text) {
            if (isset($value[$text]) && (!is_string($value[$text]) || strlen($value[$text]) > 180 || preg_match('/[\x00-\x1f]/', $value[$text]))) {
                throw new InvalidArgumentException('Invalid entity ' . $text);
            }
        }
        // null = unknown, never false/zero by default.
        return ['entity_key'=>$key, 'serial'=>$serial, 'name'=>$name,
            'storage_id'=>$value['storage_id'] ?? null, 'faction'=>$value['faction'] ?? null,
            'in_player_faction'=>$value['in_player_faction'] ?? null,
            'in_player_squad'=>$value['in_player_squad'] ?? null,
            'conscious'=>$value['conscious'] ?? null];
    }

    public static function validate(string|array $input): array
    {
        if (is_string($input)) {
            if (strlen($input) > self::MAX_BYTES) throw new LengthException('Event exceeds byte limit');
            $input = json_decode($input, true, 32, JSON_THROW_ON_ERROR);
        }
        if (!is_array($input) || array_is_list($input)) throw new InvalidArgumentException('Event must be an object');
        if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > self::MAX_BYTES) throw new LengthException('Event exceeds byte limit');
        if (($input['schema_version'] ?? null) !== self::VERSION) throw new InvalidArgumentException('Unsupported social schema');
        foreach (['event_id', 'campaign_id', 'timeline_epoch', 'native_session_id', 'incident_id'] as $key) {
            $input[$key] = self::token($input[$key] ?? null, $key);
        }
        foreach (['sequence', 'game_ts'] as $key) $input[$key] = self::number($input[$key] ?? null, $key);
        if (!in_array($input['event_kind'] ?? '', self::KINDS, true)) throw new InvalidArgumentException('Unknown event kind');
        if (!in_array($input['origin'] ?? '', ['gameplay', 'setup'], true)) throw new InvalidArgumentException('Unknown event origin');
        $input['actor'] = self::entity($input['actor'] ?? null, 'actor');
        $input['target'] = self::entity($input['target'] ?? null, 'target');
        foreach (['state_before', 'state_after', 'facts'] as $key) {
            $value = $input[$key] ?? [];
            if (!is_array($value) || (count($value) && array_is_list($value))) throw new InvalidArgumentException('Invalid ' . $key);
            $input[$key] = $value;
        }
        foreach (['state_before', 'state_after'] as $key) {
            if (array_key_exists('conscious', $input[$key]) && !is_bool($input[$key]['conscious']) && $input[$key]['conscious'] !== null) {
                throw new InvalidArgumentException('Consciousness must be true, false, or unknown');
            }
        }
        $witnesses = $input['witnesses'] ?? [];
        if (!is_array($witnesses) || !array_is_list($witnesses) || count($witnesses) > self::MAX_WITNESSES) {
            throw new InvalidArgumentException('Invalid witness list');
        }
        $seen = [];
        foreach ($witnesses as &$witness) {
            if (!is_array($witness)) throw new InvalidArgumentException('Invalid witness');
            $witness['entity'] = self::entity($witness['entity'] ?? null, 'witness');
            if (!$witness['entity']) throw new InvalidArgumentException('Missing witness entity');
            $key = $witness['entity']['entity_key'];
            if (isset($seen[$key])) throw new InvalidArgumentException('Duplicate witness');
            $seen[$key] = true;
            if (!is_bool($witness['conscious'] ?? null) || !is_bool($witness['perceived'] ?? null)) {
                throw new InvalidArgumentException('Witness requires explicit sensory evidence');
            }
        }
        unset($witness);
        $input['witnesses'] = $witnesses;
        return $input;
    }

    // Canonical hash ignores object key ordering; arrays retain their meaning.
    public static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as &$item) $item = self::canonical($item);
        return $value;
    }

    public static function hash(array $event): string
    {
        return hash('sha256', json_encode(self::canonical($event), JSON_THROW_ON_ERROR));
    }
}
