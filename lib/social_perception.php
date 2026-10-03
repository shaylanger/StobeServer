<?php
declare(strict_types=1);

final class SocialPerception
{
    public static function permits(array $belief, bool $positive, bool $squadControl = false): bool
    {
        if ($squadControl && !$positive) return false;
        if (empty($belief['responsible_entity'])) return false;
        $awareness = $belief['awareness'] ?? 'unknown';
        if (!in_array($awareness, ['directly_experienced', 'witnessed', 'inferred', 'verified_aid'], true)) return false;
        if (!in_array($belief['confidence'] ?? 'unknown', ['certain', 'strongly_inferred'], true)) return false;
        if ($positive && $awareness === 'verified_aid') return true;
        // Null awareness/consciousness never becomes knowledge by default.
        return ($belief['conscious'] ?? null) === true;
    }

    public static function onWake(array $pending, array $observed): array
    {
        if (($observed['conscious'] ?? null) !== true) return [];
        $beliefs = [];
        foreach ($pending as $fact) {
            if (($fact['kind'] ?? '') === 'missing_property' && !empty($observed['confirmed_missing_property'])) {
                $culprit = $observed['known_thief'] ?? $fact['remembered_ko_actor'] ?? null;
                if ($culprit) $beliefs[] = ['responsible_entity'=>$culprit, 'awareness'=>'inferred',
                    'confidence'=>isset($observed['known_thief']) ? 'certain' : 'strongly_inferred', 'conscious'=>true,
                    'note'=>'Woke with belongings missing', 'kind'=>'theft'];
            }
            if (($fact['kind'] ?? '') === 'enslavement' && !empty($observed['enslaved']) && !empty($observed['known_enslaver'])) {
                $beliefs[] = ['responsible_entity'=>$observed['known_enslaver'], 'awareness'=>'directly_experienced',
                    'confidence'=>'certain', 'conscious'=>true, 'note'=>'Discovered enslavement', 'kind'=>'enslavement'];
            }
        }
        return $beliefs;
    }

    public static function prompt(array $beliefs): array
    {
        $visible = [];
        foreach ($beliefs as $belief) {
            if (($belief['awareness'] ?? 'unknown') === 'unknown') continue;
            // Allowlist: never copy an objective thief/captor or hidden diagnostic fields.
            $visible[] = array_intersect_key($belief, array_flip(['responsible_entity', 'awareness', 'confidence', 'note', 'kind']));
        }
        return $visible;
    }
}
