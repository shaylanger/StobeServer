<?php
/**
 * Drawn-weapon reactions (Shay, 2026-10-06). Stobe.dll (StobeDrawnWeapon.cpp) asks for one line from an NPC who
 * sees a player character with a weapon drawn nearby: a bored-type request with
 *   &react=drawn_weapon &react_kind=friendly|guard|warn &react_detail=<weapon> &react_player=<name> &react_dist=<units>
 * friendly = neutral-or-better (why is the weapon out?), guard = town guard (put it away), warn = below neutral
 * (last warning; the DLL attacks natively if the player keeps coming). The turn is speech only.
 */

/** The reaction asked for by this request, or null (not a reaction turn / unknown kind). */
function stobeReactRequest(array $get): ?array {
    $react = strtolower(trim(strval($get['react'] ?? '')));
    if ($react !== 'drawn_weapon') return null;
    $kind = strtolower(trim(strval($get['react_kind'] ?? '')));
    if (!in_array($kind, ['friendly', 'guard', 'warn'], true)) return null;
    $weapon = trim(preg_replace('/[\x00-\x1f]+/', ' ', strval($get['react_detail'] ?? '')) ?? '');
    if ($weapon === '' || strlen($weapon) > 80) $weapon = 'weapon';
    $player = function_exists('normalizeParticipantNameToken')
        ? normalizeParticipantNameToken(strval($get['react_player'] ?? ''))
        : trim(strval($get['react_player'] ?? ''));
    $dist = intval($get['react_dist'] ?? 0);
    $meters = max(1, (int)round($dist / 10)); // game units: 10 = ~1 m
    return ['react' => $react, 'kind' => $kind, 'weapon' => $weapon, 'player' => $player, 'meters' => $meters];
}

/** The world event the turn is about, stored in the NPC's event history. */
function stobeReactContextEvent(array $r, string $npc, string $player): string {
    $who = $player !== '' ? $player : 'Someone';
    return $who . ' came within about ' . intval($r['meters']) . ' m of ' . $npc . ' with a drawn ' . $r['weapon'] . ' in hand.';
}

/** The instruction for the speaker. */
function stobeReactInstruction(array $r, string $npc, string $player): string {
    $who = $player !== '' ? $player : 'the stranger';
    $w = $r['weapon'];
    $m = intval($r['meters']);
    if ($r['kind'] === 'guard') {
        return "You are on guard duty here. $who is walking around with a drawn $w, about $m m from you. "
            . "Tell $who firmly to put the weapon away (sheathe it) while in town, in character, in one or two short lines. "
            . "Speak only: do not attack and do not start a fight.";
    }
    if ($r['kind'] === 'warn') {
        return "$who is coming toward you with a drawn $w, about $m m away, and you are not on good terms. "
            . "Give one short, clear warning in character: put the weapon away and back off, or you will attack. "
            . "Speak only: do not attack yet and do not choose a fight action (you will fight if $who keeps coming).";
    }
    return "$who has just come up to you with a drawn $w in hand, about $m m away. You are not hostile. "
        . "React in one or two short lines, in character: ask why the weapon is out, or ask $who to put it away, "
        . "as fits your personality and how you feel about $who. Speak only: do not attack and do not start a fight.";
}

/** A reaction turn is speech only: the DLL owns any attack (drops every action the model chose). */
function stobeReactFilterActions(array $actions): array {
    return [];
}
