<?php
declare(strict_types=1);
require_once __DIR__ . '/social_perception.php';

final class SocialRules
{
    private array $config;
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? json_decode(file_get_contents(dirname(__DIR__) . '/data/social_relationship_rules.json'), true, 32, JSON_THROW_ON_ERROR);
        if (!is_string($this->config['version'] ?? null)) throw new InvalidArgumentException('Rules version required');
        foreach ($this->config['ranges'] ?? [] as $kind=>$range) {
            if (!is_array($range) || count($range) !== 2 || !is_int($range[0]) || !is_int($range[1]) || $range[0] > $range[1] || $range[0] < -100 || $range[1] > 100) {
                throw new InvalidArgumentException('Invalid range: ' . $kind);
            }
        }
    }
    public function version(): string { return $this->config['version']; }
    public function category(string $component): string
    {
        $category = $this->config['categories'][$component] ?? null;
        if (!is_string($category)) throw new InvalidArgumentException('Unknown semantic component category');
        return $category;
    }
    public function calculate(string $incident, string $observer, string $component, array $belief, array $context = []): array
    {
        $range = $this->config['ranges'][$component] ?? null;
        if (!$range) throw new InvalidArgumentException('Unknown semantic component');
        if (($context['category_enabled'] ?? true) !== true) return ['delta'=>0,'reason'=>'category_disabled'];
        $positive = $range[1] > 0;
        if (!SocialPerception::permits($belief, $positive, ($context['squad_control'] ?? false) === true)) return ['delta'=>0, 'reason'=>'not_known'];
        $seed = hexdec(substr(hash('sha256', implode('|', [$incident, $observer, $component, $this->version()])), 0, 8));
        $base = $range[0] + ($seed % ($range[1] - $range[0] + 1));
        $old = max(-100, min(100, (int)($context['affinity'] ?? 0)));
        $personality = max(.85, min(1.15, (float)($context['personality'] ?? 1)));
        $confidence = ($belief['confidence'] ?? '') === 'strongly_inferred' ? .8 : 1.0;
        $repeat = max(0, (int)($context['repeat_count'] ?? 0));
        $economic = in_array($component, ['favorable_trade','generous_trade','exceptional_trade','gift'], true);
        $life = in_array($component, ['lifesaving','slave_escape'], true);
        $repetition = $positive ? ($life ? 1.0 : ($economic ? pow(.5, min(20,$repeat)) : ($repeat ? 0 : 1))) : 1.0;
        $affinity = $positive && $old < -30 ? ($old <= -76 ? .35 : .6) : 1.0;
        if ($life) $affinity = max(.65, $affinity);
        $raw = (int)round($base * $personality * $confidence * $repetition * $affinity);
        if ($economic) $raw = max(0, min($raw, $this->config['trade_day_cap'] - (int)($context['economic_day_gain'] ?? 0), $this->config['economic_affinity_ceiling'] - $old));
        $new = max(-100, min(100, $old + $raw));
        return ['delta'=>$new-$old, 'base'=>$base, 'new_affinity'=>$new, 'rules_version'=>$this->version(),
            'modifiers'=>compact('personality','confidence','repetition','affinity'), 'reason'=>'known_outcome'];
    }
    public static function recruitment(int $affinity, array $trust, array $grievances, bool $vanilla = false): bool
    {
        if ($vanilla) return true;
        return $affinity >= 76 && count(array_intersect($trust, ['slave_escape','lifesaving','major_aid','companion_history'])) > 0
            && !in_array('severe_unresolved', $grievances, true);
    }
}
