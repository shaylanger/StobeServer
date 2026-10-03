<?php
declare(strict_types=1);

// Maps a native entity (session-local serial + name + storage id) to the NPC profile whose
// relationship map is written. Ambiguity resolves to null (no effect), never to a guess.
final class SocialIdentity
{
    private const GENERIC = ['', 'unknown', 'none', 'someone', 'player'];

    private static function storage(?string $value): string
    {
        $value = trim(strval($value));
        return $value === '' ? '' : (function_exists('normalizeStorageIdToken') ? normalizeStorageIdToken($value) : $value);
    }

    private static function name(string $value): string
    {
        return function_exists('normalizeParticipantNameToken') ? normalizeParticipantNameToken($value) : trim($value);
    }

    public static function generic(string $name): bool
    {
        if (in_array(strtolower(trim($name)), self::GENERIC, true)) return true;
        return function_exists('stobeIsGenericNpcName') && stobeIsGenericNpcName($name);
    }

    /**
     * @return array{id:int,name:string,basis:string}|null
     * observer: needs a stored profile (its map is written). culprit: a stored profile, or a unique
     * non-generic name with no stored profile (the map key is the name; nothing else is written).
     */
    public static function resolve(?array $entity, string $role): ?array
    {
        if (!$entity) return null;
        $name = self::name(strval($entity['name'] ?? ''));
        if ($name === '' || self::generic($name)) return null;
        $storage = self::storage($entity['storage_id'] ?? null);
        $alias = self::storage($entity['storage_alias'] ?? null); // earlier storage id of the same live character
        $rows = $GLOBALS['db']->fetchAll('SELECT id,name,metadata->>\'storage_id\' AS storage_id FROM core_npc WHERE LOWER(name)=LOWER($1) ORDER BY id', [$name]) ?: [];
        $matches = [];
        foreach ($rows as $row) {
            $stored = self::storage($row['storage_id'] ?? null);
            $aliasHit = $stored !== '' && $alias !== '' && strcasecmp($stored, $alias) === 0;
            if ($stored !== '' && $storage !== '' && strcasecmp($stored, $storage) !== 0 && !$aliasHit) continue; // another NPC / reused serial
            $matches[] = ['id'=>(int)$row['id'], 'name'=>strval($row['name']),
                'basis'=>($stored !== '' && $storage !== '') ? ($aliasHit && strcasecmp($stored, $storage) !== 0 ? 'storage_alias+name' : 'storage_id+name') : 'unique_name_no_storage'];
        }
        $exact = array_values(array_filter($matches, fn($m) => $m['basis'] === 'storage_id+name' || $m['basis'] === 'storage_alias+name'));
        if (count($exact) === 1) return $exact[0];
        if (count($matches) === 1) return $matches[0];
        if (count($matches) > 1) return null;
        if ($role === 'culprit' && !$rows) return ['id'=>0, 'name'=>$name, 'basis'=>'name_only_culprit'];
        return null;
    }
}
