<?php
// C49 (m22): chat aimed at a template name ("Dust Bandit Bowman") whose live NPC was just auto-named
// ("Zeth 2 [Dust Bandit Bowman]") goes to that live NPC, not to a stale row of another serial.
// Run: php tests/chat_renamed_target_regression.php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
$fail = 0;
function c49check(string $label, bool $ok): void { global $fail; echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; }
$fn = 'stobeResolveLiveRenamedTarget';
c49check('C49 resolver exists', function_exists($fn));
if (function_exists($fn)) {
    $GLOBALS['CACHE_PEOPLE'] = json_encode(['Shay|971557632', 'Zeth 2 [Dust Bandit Bowman]|-632125440', 'Malzin|-1554488832']);
    c49check('C49 template name -> unique live renamed participant', $fn('Dust Bandit Bowman') === 'Zeth 2 [Dust Bandit Bowman]');
    c49check('C49 case-insensitive', $fn('dust bandit bowman') === 'Zeth 2 [Dust Bandit Bowman]');
    c49check('C49 exact live name is left alone', $fn('Malzin') === '');
    c49check('C49 unknown name is left alone', $fn('Dust Bandit') === '');
    $GLOBALS['CACHE_PEOPLE'] = json_encode(['Shay|971557632', 'Dust Bandit Bowman|12345', 'Zeth 2 [Dust Bandit Bowman]|-632125440']);
    c49check('C49 an exact live match wins over a renamed one', $fn('Dust Bandit Bowman') === '');
    $GLOBALS['CACHE_PEOPLE'] = json_encode(['Zeth 2 [Dust Bandit Bowman]|-632125440', 'Tharnic 2 [Dust Bandit Bowman]|1997924864']);
    c49check('C49 two renamed candidates: ambiguous, left alone', $fn('Dust Bandit Bowman') === '');
    unset($GLOBALS['CACHE_PEOPLE']);
}
$src = strval(file_get_contents(__DIR__ . '/../processor/chat.php'));
c49check('C49 chat processor retargets before the live-serial lookup',
    ($a = strpos($src, 'stobeResolveLiveRenamedTarget(')) !== false && $a < strpos($src, 'stobeResolveLiveParticipantSerial($targetNpc)'));
echo $fail === 0 ? "ALL PASS\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
