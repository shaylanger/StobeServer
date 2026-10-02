<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'world_knowledge_aliases.php';

/**
 * Chat helper functions for StobeServer.
 * Builds context, manages conversation state, formats responses.
 */

function loadPromptTemplate(string $templateName): string {
    $normalized = strtolower(trim($templateName));
    if ($normalized === 'prompt_bored.txt' || $normalized === 'prompt_bored') {
        $defaultBoredPrompt = "<bored_prompt_template>\n"
            . "  <task>Generate a brief bored-event conversation between NPCs in Kenshi.</task>\n"
            . "  <npcs>#NPC_LIST#</npcs>\n"
            . "  <location>#LOCATION#</location>\n"
            . "  <world_events>#WORLD_EVENTS#</world_events>\n"
            . "  <requirements>\n"
            . "    <rule>Create a short natural exchange (2-4 lines total).</rule>\n"
            . "    <rule>Possible themes: gossip, faction tensions, survival concerns, world events, trade/work complaints.</rule>\n"
            . "    <rule>Keep it brief and natural like an overheard snippet.</rule>\n"
            . "  </requirements>\n"
            . "</bored_prompt_template>";
        if (function_exists('stobeGetPromptTemplateValue')) {
            return stobeGetPromptTemplateValue('bored_event_template', $defaultBoredPrompt);
        }
        return $defaultBoredPrompt;
    }

    return "<roleplay_instructions>\n"
        . "#ROLEPLAY_INSTRUCTIONS#\n"
        . "</roleplay_instructions>\n\n"
        . "<character>\n"
        . "Roleplay as #NPC_NAME#.\n"
        . "#NPC_NAME# is a #NPC_RACE# aligned with #NPC_FACTION# in the world of Kenshi.\n"
        . "#NPC_CHARACTER_STATE#\n"
        . "<basic_summary>\n"
        . "#NPC_BACKSTORY#\n"
        . "</basic_summary>\n"
        . "<personality>\n"
        . "#NPC_PERSONALITY#\n"
        . "</personality>\n"
        . "<appearance>\n"
        . "#NPC_APPEARANCE#\n"
        . "</appearance>\n"
        . "<relationships>\n"
        . "#NPC_RELATIONSHIPS#\n"
        . "</relationships>\n"
        . "<occupation>\n"
        . "#NPC_OCCUPATION#\n"
        . "</occupation>\n"
        . "#NPC_BOUNTY_BLOCK#\n"
        . "<skills>\n"
        . "#NPC_SKILLS#\n"
        . "</skills>\n"
        . "<speech_style>\n"
        . "#NPC_SPEECHSTYLE#\n"
        . "</speech_style>\n"
        . "<goals>\n"
        . "#NPC_GOALS#\n"
        . "</goals>\n"
        . "#NPC_MIDDLE_TERM_MEMORY#\n"
        . "#NPC_LATEST_DIARY#\n"
        . "</character>\n\n"
        . "<general_instructions>\n"
        . "#GENERAL_INSTRUCTIONS#\n"
        . "</general_instructions>";
}

function stobePromptStripEmptyTagBlock(string $prompt, string $tag): string
{
    $safeTag = trim($tag);
    if ($safeTag === '') {
        return $prompt;
    }

    $pattern = '/(?:\R[ \t]*)?<' . preg_quote($safeTag, '/') . '>\s*<\/' . preg_quote($safeTag, '/') . '>(?:[ \t]*\R)?/isu';
    $updated = preg_replace($pattern, "\n", $prompt);
    return is_string($updated) ? $updated : $prompt;
}

function stobePromptCollapseBlankLines(string $prompt): string
{
    $collapsed = preg_replace('/(?:\R[ \t]*){3,}/u', "\n\n", trim($prompt));
    return is_string($collapsed) ? trim($collapsed) : trim($prompt);
}

function stobePromptCleanupBaseTemplateBlocks(string $prompt): string
{
    foreach (['basic_summary', 'personality', 'appearance', 'relationships', 'occupation', 'skills', 'speech_style', 'goals'] as $tag) {
        $prompt = stobePromptStripEmptyTagBlock($prompt, $tag);
    }

    return stobePromptCollapseBlankLines($prompt);
}

function parseActionAllowlistSetting(string $rawAllowlist): array {
    $trimmed = trim($rawAllowlist);
    if ($trimmed === '') {
        return [];
    }
    $tokens = preg_split('/[\s,|]+/', $trimmed) ?: [];
    $normalized = [];
    foreach ($tokens as $token) {
        $value = strtoupper(trim(strval($token)));
        if ($value === '' || $value === '*' || $value === 'ALL') {
            continue;
        }
        if (!in_array($value, $normalized, true)) {
            $normalized[] = $value;
        }
    }
    return $normalized;
}

function stobeActionCatalogAvailable(): bool {
    static $cached = null;
    if ($cached !== null) {
        return boolval($cached);
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        $cached = false;
        return false;
    }

    $row = $db->fetchOne("SELECT to_regclass('public.combined_core_action') AS rel");
    $cached = is_array($row) && !empty(trim(strval($row['rel'] ?? '')));
    return boolval($cached);
}

function loadCoreActionRows(bool $onlyActivated = true): array {
    if (!stobeActionCatalogAvailable()) {
        return [];
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return [];
    }

    $sql = "SELECT
                command,
                action_name,
                description,
                is_activated
            FROM combined_core_action";
    if ($onlyActivated) {
        $sql .= " WHERE is_activated = TRUE";
    }
    $sql .= " ORDER BY LOWER(action_name) ASC, LOWER(command) ASC";

    $rows = $db->fetchAll($sql);
    $hasTravelLocation = false;
    $hasUseObject = false;
    $hasUseDrugs = false;
    $hasDrinkItem = false;
    $hasKnockout = false;
    $hasKill = false;
    $hasForceDrink = false;
    $hasPickupNpc = false;
    $hasUnequipItem = false;
    foreach ($rows as $row) {
        $command = stobeCanonicalizeActionCommand(strval($row['command'] ?? ''));
        if ($command === 'TRAVEL_LOCATION') {
            $hasTravelLocation = true;
        } elseif ($command === 'USE_OBJECT') {
            $hasUseObject = true;
        } elseif ($command === 'USE_DRUGS') {
            $hasUseDrugs = true;
        } elseif ($command === 'DRINK_ITEM') {
            $hasDrinkItem = true;
        } elseif ($command === 'KNOCKOUT') {
            $hasKnockout = true;
        } elseif ($command === 'KILL') {
            $hasKill = true;
        } elseif ($command === 'FORCE_DRINK') {
            $hasForceDrink = true;
        } elseif ($command === 'PICKUP_NPC') {
            $hasPickupNpc = true;
        } elseif ($command === 'UNEQUIP_ITEM') {
            $hasUnequipItem = true;
        }
    }

    $appendFallbackAction = static function (
        string $command,
        string $actionName,
        string $description
    ) use (&$rows, $db, $onlyActivated): void {
        $shouldAppendFallback = true;
        try {
            $commandSql = preg_replace('/[^A-Z_]/', '', strtoupper($command));
            if (!is_string($commandSql) || trim($commandSql) === '') {
                $commandSql = strtoupper($command);
            }
            $existingRow = $db->fetchOne(
                "SELECT is_activated
                 FROM combined_core_action
                 WHERE UPPER(command) = '" . $commandSql . "'
                 LIMIT 1"
            );
            if (is_array($existingRow)) {
                if ($onlyActivated) {
                    $rawIsActivated = strtolower(trim(strval($existingRow['is_activated'] ?? '')));
                    $isActivated = in_array($rawIsActivated, ['1', 't', 'true', 'yes', 'on'], true);
                    $shouldAppendFallback = $isActivated;
                } else {
                    $shouldAppendFallback = true;
                }
            }
        } catch (Throwable $exception) {
            $shouldAppendFallback = true;
        }

        if ($shouldAppendFallback) {
            $rows[] = [
                'command' => $command,
                'action_name' => $actionName,
                'description' => $description,
                'is_activated' => true,
            ];
        }
    };

    if (!$hasTravelLocation) {
        $appendFallbackAction(
            'TRAVEL_LOCATION',
            'TravelLocation',
            'Travel to a previously visited location by name.'
        );
    }
    if (!$hasUseObject) {
        $appendFallbackAction(
            'USE_OBJECT',
            'UseObject',
            'Use a nearby point of interest that has a free usable slot.'
        );
    }
    if (!$hasUseDrugs) {
        $appendFallbackAction(
            'USE_DRUGS',
            'UseDrugs',
            'Consume Hashish from inventory/equipment.'
        );
    }
    if (!$hasDrinkItem) {
        $appendFallbackAction(
            'DRINK',
            'Drink',
            'Consume Bloodrum, Cactus Rum, Grog, or Sake from inventory/equipment.'
        );
    }
    if (!$hasKnockout) {
        $appendFallbackAction(
            'KNOCKOUT',
            'Knockout',
            'Knock out a target immediately without killing them. Self-targeting is allowed; otherwise the target must already be helpless.'
        );
    }
    if (!$hasKill) {
        $appendFallbackAction(
            'KILL',
            'Kill',
            'Kill a helpless target immediately.'
        );
    }
    if (!$hasForceDrink) {
        $appendFallbackAction(
            'FORCE_DRINK',
            'ForceDrink',
            'Force a helpless target to drink Bloodrum, Cactus Rum, Grog, or Sake from your inventory/equipment.'
        );
    }
    if (!$hasPickupNpc) {
        $appendFallbackAction(
            'PICKUP_NPC',
            'PickupNpc',
            'Pick up a nearby helpless target and carry them.'
        );
    }
    if (!$hasUnequipItem) {
        $appendFallbackAction(
            'UNEQUIP_ITEM',
            'UnequipItem',
            'Remove one currently worn item and move it into your own inventory. Use item for the exact equipped item name when known, or a clear clothing slot such as hat, helmet, facewear, shirt, armor, pants, boots, gloves, neckwear, backpack, belt, or weapon.'
        );
    }

    $bridgeFallbacks = [
        ['EQUIP_ITEM', 'EquipItem', 'Equip one specific item currently carried by this NPC.'],
        ['SHEATHE_WEAPON', 'SheatheWeapon', 'Put the currently drawn weapon away without dropping it.'],
        ['DRAW_WEAPON', 'DrawWeapon', 'Draw or ready a carried weapon.'],
        ['FACE_TARGET', 'FaceTarget', 'Turn to look at and face a nearby named actor.'],
        ['FIRST_AID', 'FirstAid', 'Apply real first aid to an injured nearby named actor.'],
        ['BODYGUARD', 'Bodyguard', 'Guard and protect a nearby named actor.'],
        ['PATROL', 'Patrol', 'Begin a real Kenshi patrol order around the current area.'],
        ['RESCUE', 'Rescue', 'Attempt to rescue a nearby incapacitated named actor.'],
        ['PUT_IN_BED', 'PutInBed', 'Take a nearby incapacitated named actor to an available bed.'],
        ['LOOT_TARGET', 'LootTarget', 'Use Kenshi looting behavior on a valid nearby target.'],
        ['IMPRISON', 'Imprison', 'Take a valid nearby target to an available cage.'],
        ['RELEASE_PRISONER', 'ReleasePrisoner', 'Release a nearby named prisoner using a real Kenshi order.'],
        ['DROP_WEAPON', 'DropWeapon', 'Sheathe and physically drop the currently equipped weapon.'],
        ['SURRENDER', 'Surrender', 'Sheathe and unequip the currently equipped weapon without dropping it.'],
        ['MOVE_TO_TARGET', 'MoveToTarget', 'Move near a nearby named actor without following them indefinitely.'],
        ['HOLD_POSITION', 'HoldPosition', 'Hold the current position using a real Kenshi hold-position order.'],
        ['SIT', 'Sit', 'Sit on a nearby usable chair, table seat, or throne.'],
        ['SLEEP', 'Sleep', 'Use a nearby available bed to rest.'],
        ['OPERATE_OBJECT', 'OperateObject', 'Use a nearby turret, machine, workbench, mine, research station, or similar usable object.'],
        ['REPAIR', 'Repair', 'Repair a nearby named building or structure using a real Kenshi repair order.'],
        ['BUILD', 'Build', 'Work on a nearby named unfinished building or structure using a real Kenshi build order.'],
        ['WORK_GOAL', 'WorkGoal', 'Accept a persistent finite production/resource goal. Put the desired output or resource in item, requested quantity in amount, and optional destination/base/location in target.'],
        ['TASK_GOAL', 'LootArea', 'Loot a specific item or item category from all valid nearby downed/dead targets. target can filter targets such as goats, animals, enemies, raiders, or a name. amount 0 means all matching loot.'],
        ['TASK_GOAL', 'LootStore', 'Loot matching items from valid nearby downed/dead targets, then put them into player storage. Use destination when storage is at a named known base/location.'],
        ['TASK_GOAL', 'StoreItems', 'Put matching carried items into player storage, optionally at a named destination. amount 0 means all matching carried items. item may list several items separated by commas.'],
        ['TASK_GOAL', 'FetchItems', 'Fetch a finite quantity of matching items from player storage, optionally at a named destination. item may list several items separated by commas (amount applies to each).'],
        ['TASK_GOAL', 'DeliverItems', 'Give a finite quantity of carried matching items to a named player-squad member. item may list several items separated by commas.'],
        ['TASK_GOAL', 'RecoverGround', 'Recover matching dropped items from the nearby ground. amount 0 means all matching dropped items.'],
        ['TASK_GOAL', 'MedicalCleanup', 'Treat/rescue injured or downed loaded squad members until no eligible squadmate remains.'],
        ['TASK_GOAL', 'BattleCleanup', 'Perform post-battle cleanup: treat/rescue squadmates first, then loot matching items from nearby downed enemies and optionally store them.'],
        ['TASK_GOAL', 'ImprisonAll', 'Take eligible nearby downed humanoid targets to available cages. target can be enemies/raiders or a more specific filter.'],
        ['TASK_GOAL', 'ReleaseAll', 'Attempt to release nearby prisoners, optionally filtered by name.'],
        ['TASK_GOAL', 'MaintainStock', 'Persistently maintain at least amount units of an item/resource, creating production subgoals when stock falls below the target.'],
        ['TASK_GOAL', 'BuyItems', 'Explicitly buy a finite quantity from a real nearby merchant using real faction Cats. This action itself is purchase authorization; max_cats is an optional hard spending cap.'],
        ['TASK_GOAL', 'SellItems', 'Explicitly sell matching carried items to a real nearby merchant. Never choose this unless the player explicitly asked to sell.'],
        ['TASK_GOAL', 'GuardGoal', 'Persistently guard a named squad member, or the current position if target is blank, until paused/cancelled.'],
        ['TASK_GOAL', 'WaitForGoal', 'Wait at the current position until the named squad member returns nearby.'],
        ['TASK_GOAL', 'PatrolGoal', 'Persistently patrol the current area until paused/cancelled.'],
        ['TASK_GOAL', 'BuildGoal', 'Finish a named nearby construction, recursively sourcing missing producible construction materials first.'],
        ['TASK_GOAL', 'RepairGoal', 'Repair a named nearby player structure, using real Kenshi repair work.'],
        ['TASK_CONTROL', 'PauseGoal', 'Pause the current/referenced persistent goal.'],
        ['TASK_CONTROL', 'ResumeGoal', 'Resume the current/referenced paused goal.'],
        ['TASK_CONTROL', 'CancelGoal', 'Cancel the current/referenced persistent goal.'],
        ['TASK_CONTROL', 'ModifyGoal', 'Change the requested quantity of the current/referenced goal; put the new quantity in amount.'],
        ['TASK_CONTROL', 'RetargetGoal', 'Change the destination of the current/referenced goal; put the new known destination in destination.'],
        ['TASK_CONTROL', 'ApprovePurchase', 'Approve the NPC most recent pending purchase request. Use only after the player clearly says yes/approves spending.'],
        ['TASK_CONTROL', 'DeclinePurchase', 'Decline/cancel the NPC most recent pending purchase request.'],
    ];
    foreach ($bridgeFallbacks as $bridgeFallback) {
        [$bridgeCommand, $bridgeName, $bridgeDescription] = $bridgeFallback;
        $alreadyPresent = false;
        foreach ($rows as $row) {
            if (stobeCanonicalizeActionCommand(strval($row['command'] ?? '')) === $bridgeCommand) {
                $alreadyPresent = true;
                break;
            }
        }
        if (!$alreadyPresent) {
            $appendFallbackAction($bridgeCommand, $bridgeName, $bridgeDescription);
        }
    }

    return $rows;
}

function buildActionGuidanceFromRows(array $rows, array $npcData = [], bool $stableReference = false): string {
    if (count($rows) === 0) {
        return '';
    }

    $lines = [];
    $lines[] = $stableReference ? '<action_reference>' : '<available_actions_list>';
    $lines[] = $stableReference ? '#Action Reference' : '#Available Actions';
    $lines[] = $stableReference
        ? 'Reference definitions only. The later <action_state> block is authoritative for which actions are currently available.'
        : 'Use if your character needs to perform an action:';
    foreach ($rows as $row) {
        $command = stobeCanonicalizeActionCommand(strval($row['command'] ?? ''));
        $actionName = trim(strval($row['action_name'] ?? ''));
        if ($command === 'STOP_CARRYING') {
            $actionName = 'StopCarrying';
        } elseif ($command === 'PICKUP_NPC') {
            $actionName = 'PickupNpc';
        }
        if ($actionName === '') {
            continue;
        }
        if (!$stableReference &&
            in_array($command, ['WORK_GOAL','TASK_GOAL','TASK_CONTROL'], true) &&
            (!is_array($npcData) || !npcIsInPlayerFaction($npcData))) {
            continue;
        }
        $description = trim(strval($row['description'] ?? ''));
        if ($command === 'STOP_CARRYING') {
            $description = 'Put down what you are currently carrying.';
        } elseif ($command === 'PICKUP_NPC') {
            $description = 'Pick up a nearby helpless target and carry them. Not available while already carrying someone.';
        }
        if (!$stableReference && $command === 'FACTION_RELATIONS') {
            $inlineRules = stobeBuildFactionRelationsActionInlineGuidance($npcData);
            if ($inlineRules !== '') {
                if ($description === '') {
                    $description = $inlineRules;
                } else {
                    $description .= ' ' . $inlineRules;
                }
            }
        }
        if ($command === 'MOVE_TO') {
            $description .= ' Put the exact reference in target, or use player for the initiating speaker. Use a known visited location name for a fixed point. Never invent coordinates. Follow is for continued following; UseObject is for interaction.';
            if (!$stableReference) {
                foreach (stobeMoveToReferences($npcData) as $reference) {
                    $lines[] = 'MoveTo target: hand_' . $reference['serial'] . ' = '
                        . stobePromptXmlEscape($reference['name']);
                }
            }
        }
        if ($description !== '') {
            $lines[] = "AVAILABLE ACTION: {$actionName} ({$description})";
        } else {
            $lines[] = "AVAILABLE ACTION: {$actionName}";
        }
    }
    $lines[] = $stableReference ? '</action_reference>' : '</available_actions_list>';

    return implode("\n", $lines);
}

/** Speech that states an equip already happened (it only executes after the reply). */
function stobeSpeechClaimsEquipDone(string $message): bool {
    $done = '/\b(back\s+on|already\s+on|(?:it|they)(?:\'|’)(?:s|re)\s+on|(?:it|they)\s+(?:is|are)\s+on|(?:put|got)\s+(?:it|them|that|those)\s+(?:back\s+)?on)\b/i';
    $intent = '/\b(let me|i(?:\'|’)ll|gonna|going to)\b/i';
    return preg_match($done, $message) === 1 && preg_match($intent, $message) !== 1;
}

function stobeNpcIsInCombat(array|false $npcData): bool {
    if (!is_array($npcData) || count($npcData) === 0) {
        return false;
    }

    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    foreach (['is_in_combat', 'is_attacking'] as $flag) {
        if (stobeParseFlexibleBool($metadata[$flag] ?? ($npcData[$flag] ?? null)) === true) {
            return true;
        }
    }

    $currentAction = strtolower(trim(strval($metadata['current_action'] ?? '')));
    return in_array($currentAction, ['combat', 'attacking', 'fighting'], true);
}

function getActionRuntimeConfig(string $eventType): array {
    $normalizedEventType = strtolower(trim($eventType));
    $actionsEnabled = getSettingBool('ACTIONS_ENABLED', true);
    if ($normalizedEventType === 'bored') {
        $actionsEnabled = $actionsEnabled && getSettingBool('BORED_ALLOW_ACTIONS', false);
    } elseif ($normalizedEventType === 'rechat') {
        $actionsEnabled = $actionsEnabled && getSettingBool('RECHAT_ALLOW_ACTIONS', true);
    }

    $maxActions = getSettingInt('MAX_ACTIONS_PER_RESPONSE', 1);
    if ($maxActions < 1) {
        $maxActions = 1;
    } elseif ($maxActions > 3) {
        $maxActions = 3;
    }

    $allowlist = parseActionAllowlistSetting(getSetting('ACTIONS_ALLOWLIST', ''));
    $activeActionRows = loadCoreActionRows(true);
    $activeCommands = [];
    foreach ($activeActionRows as $row) {
        $command = stobeCanonicalizeActionCommand(strval($row['command'] ?? ''));
        if ($command === '') {
            continue;
        }
        if (!in_array($command, $activeCommands, true)) {
            $activeCommands[] = $command;
        }
    }
    if (count($activeCommands) > 0) {
        if (count($allowlist) === 0) {
            $allowlist = $activeCommands;
        } else {
            $allowlist = array_values(array_intersect($allowlist, $activeCommands));
        }
    }
    $minFaction = intval(getSettingInt('MIN_FACTION_RELATION', -100));
    $maxFaction = intval(getSettingInt('MAX_FACTION_RELATION', 100));
    if ($minFaction < -100) {
        $minFaction = -100;
    }
    if ($maxFaction > 100) {
        $maxFaction = 100;
    }
    if ($minFaction > $maxFaction) {
        $swap = $minFaction;
        $minFaction = $maxFaction;
        $maxFaction = $swap;
    }

    return [
        'enabled' => $actionsEnabled,
        'max_actions' => $maxActions,
        'allowlist' => $allowlist,
        'active_rows' => $activeActionRows,
        'min_faction_relation' => $minFaction,
        'max_faction_relation' => $maxFaction,
        'disallow_follow_for_player_faction' => false,
        'disallow_give_cats' => false,
        'disallow_take_cats' => false,
        'disallow_pickup_npc' => false,
        'disallow_cut_horns' => false,
        'allow_travel_location' => true,
    ];
}

function stobeBuildActionConfigForNpc(string $eventType, array|false $npcData = false): array {
    $config = getActionRuntimeConfig($eventType);
    $config['disallow_stop_attack'] = !stobeNpcIsInCombat($npcData);
    $config['disallow_stop_carrying'] = false;
    $config['disallow_pickup_npc'] = false;
    $config['disallow_remove_limb'] = true;
    $config['disallow_cut_horns'] = true;
    $config['disallow_use_drugs'] = true;
    $config['disallow_drink_item'] = true;
    $config['disallow_force_drink'] = true;
    $config['disallow_give_cats'] = false;
    $config['disallow_take_cats'] = false;
    $config['allow_travel_location'] = true;
    $config['in_player_faction'] = false;
    $config['player_name'] = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    $config['player_affinity'] = 0;
    $config['protected_equipment_text'] = '';
    $config['explicit_barter_offer'] = stobeCurrentRequestLooksLikeExplicitBarter();
    $config['give_equipment_trust_threshold'] = max(0, min(100, intval(getSettingInt('PROPERTY_GIVE_TRUST_THRESHOLD', 60))));
    // Kenshi is harsh: outsiders do not hand the player items or Cats for nothing.
    $config['gift_trust_threshold'] = max(0, min(100, intval(getSettingInt('GIFT_TRUST_THRESHOLD', 56))));
    $config['deal_sanctioned_give'] = false;
    if (is_array($npcData) && count($npcData) > 0) {
        $isPlayerFaction = npcIsInPlayerFaction($npcData);
        $config['in_player_faction'] = $isPlayerFaction;
        $config['npc_name'] = normalizeParticipantNameToken(strval($npcData['name'] ?? ''));
        $config['player_affinity'] = stobeNpcPlayerAffinity($npcData);
        $config['protected_equipment_text'] = stobeNpcProtectedEquipmentText($npcData);
        if ($isPlayerFaction) {
            $config['disallow_follow_for_player_faction'] = true;
        }
    }
    if (is_array($npcData) && count($npcData) > 0 && !stobeNpcIsCarryingTarget($npcData)) {
        $config['disallow_stop_carrying'] = true;
    } else {
        $config['disallow_pickup_npc'] = true;
    }
    if (is_array($npcData) && count($npcData) > 0 && stobeNpcHasHacksaw($npcData)) {
        $config['disallow_remove_limb'] = false;
        $config['disallow_cut_horns'] = false;
    }
    if (is_array($npcData) && count($npcData) > 0 && !stobeNpcIsSkeletonRace($npcData)) {
        if (stobeNpcHasHashish($npcData)) {
            $config['disallow_use_drugs'] = false;
        }
        if (stobeNpcHasDrinkItem($npcData)) {
            $config['disallow_drink_item'] = false;
            $config['disallow_force_drink'] = false;
        }
    }
    return $config;
}

function stobeFilterPartyActionGuidanceByMembership(string $guidance, ?bool $inPlayerFaction): string {
    if (!is_string($guidance) || trim($guidance) === '') {
        return $guidance;
    }
    if ($inPlayerFaction === null) {
        return $guidance;
    }

    if ($inPlayerFaction) {
        $guidance = preg_replace('/^AVAILABLE ACTION:\s*JoinParty\b.*\R?/mi', '', $guidance) ?? $guidance;
        $guidance = preg_replace('/^AVAILABLE ACTION:\s*Follow\b.*\R?/mi', '', $guidance) ?? $guidance;
        $guidance = preg_replace('/^AVAILABLE ACTION:\s*StopFollow\b.*\R?/mi', '', $guidance) ?? $guidance;
    } else {
        $guidance = preg_replace('/^AVAILABLE ACTION:\s*Leave\b.*\R?/mi', '', $guidance) ?? $guidance;
    }

    $stateHint = $inPlayerFaction
        ? 'Action State: This NPC is already in the player faction/squad. Use Leave (not JoinParty). Follow/StopFollow are unavailable. TravelLocation is allowed.'
        : 'Action State: This NPC is not in the player faction/squad. Use JoinParty (not Leave). TravelLocation is allowed.';

    $anchor = "Use if your character needs to perform an action:";
    if (strpos($guidance, $anchor) !== false) {
        $guidance = str_replace($anchor, $anchor . "\n" . $stateHint, $guidance);
    } else {
        $guidance .= "\n" . $stateHint;
    }

    return trim($guidance);
}

function stobeBuildCurrentActionRows(string $eventType, array $npcData = []): array {
    $config = stobeBuildActionConfigForNpc($eventType, $npcData);
    if (!boolval($config['enabled'] ?? false)) {
        return [[], $config];
    }

    $rows = [];
    $allowed = $config['allowlist'] ?? [];
    $activeRows = $config['active_rows'] ?? [];
    if (is_array($activeRows)) {
        foreach ($activeRows as $row) {
            $command = stobeCanonicalizeActionCommand(strval($row['command'] ?? ''));
            if ($command === '' || (count($allowed) > 0 && !in_array($command, $allowed, true))) continue;
            if ($command === 'STOP_ATTACK' && boolval($config['disallow_stop_attack'] ?? true)) continue;
            if ($command === 'GIVE_CATS' && boolval($config['disallow_give_cats'] ?? false)) continue;
            if ($command === 'TAKE_CATS' && boolval($config['disallow_take_cats'] ?? false)) continue;
            if ($command === 'STOP_CARRYING' && boolval($config['disallow_stop_carrying'] ?? false)) continue;
            if ($command === 'PICKUP_NPC' && boolval($config['disallow_pickup_npc'] ?? false)) continue;
            if ($command === 'REMOVE_LIMB' && boolval($config['disallow_remove_limb'] ?? false)) continue;
            if ($command === 'CUT_HORNS' && boolval($config['disallow_cut_horns'] ?? false)) continue;
            if ($command === 'USE_DRUGS' && boolval($config['disallow_use_drugs'] ?? false)) continue;
            if ($command === 'DRINK_ITEM' && boolval($config['disallow_drink_item'] ?? false)) continue;
            if ($command === 'FORCE_DRINK' && boolval($config['disallow_force_drink'] ?? false)) continue;
            if ($command === 'TRAVEL_LOCATION' && !boolval($config['allow_travel_location'] ?? false)) continue;
            $rows[] = $row;
        }
    }
    return [$rows, $config];
}

function appendStableActionReferenceToPrompt(string $prompt, string $eventType): string {
    if (!stobePromptContextOptionEnabled('enabled_sections', 'available_actions_list')) return $prompt;
    $runtime = getActionRuntimeConfig($eventType);
    if (!boolval($runtime['enabled'] ?? false)) return $prompt;
    $rows = is_array($runtime['active_rows'] ?? null) ? $runtime['active_rows'] : [];
    $allowed = $runtime['allowlist'] ?? [];
    if (count($allowed) > 0) {
        $rows = array_values(array_filter($rows, static function ($row) use ($allowed): bool {
            $command = stobeCanonicalizeActionCommand(strval($row['command'] ?? ''));
            return $command !== '' && in_array($command, $allowed, true);
        }));
    }
    $guidance = buildActionGuidanceFromRows($rows, [], true);
    return $guidance === '' ? $prompt : implode("\n\n", [$prompt, $guidance]);
}

function stobeBuildDynamicActionStateBlock(string $eventType, array $npcData = []): string {
    if (!stobePromptContextOptionEnabled('enabled_sections', 'available_actions_list')) return '';
    [$rows, $config] = stobeBuildCurrentActionRows($eventType, $npcData);
    if (!boolval($config['enabled'] ?? false)) return '';

    $names = [];
    foreach ($rows as $row) {
        $command = stobeCanonicalizeActionCommand(strval($row['command'] ?? ''));
        $name = trim(strval($row['action_name'] ?? ''));
        if ($command === 'STOP_CARRYING') $name = 'StopCarrying';
        elseif ($command === 'PICKUP_NPC') $name = 'PickupNpc';
        if ($name !== '') $names[] = $name;
    }
    $inPlayerFaction = count($npcData) > 0 ? npcIsInPlayerFaction($npcData) : false;
    $lines = ['<action_state>'];
    $lines[] = $inPlayerFaction
        ? 'This NPC is already in the player faction/squad. Leave may be used; JoinParty and Follow/StopFollow are unavailable.'
        : 'This NPC is not in the player faction/squad. JoinParty may be used; Leave is unavailable.';
    $lines[] = 'Currently available actions: ' . (count($names) > 0 ? implode(', ', array_values(array_unique($names))) : '(none)') . '.';

    foreach ($rows as $row) {
        $command = stobeCanonicalizeActionCommand(strval($row['command'] ?? ''));
        if ($command === 'FACTION_RELATIONS') {
            $inline = stobeBuildFactionRelationsActionInlineGuidance($npcData);
            if ($inline !== '') $lines[] = 'FactionRelations live context: ' . $inline;
        } elseif ($command === 'MOVE_TO') {
            foreach (stobeMoveToReferences($npcData) as $reference) {
                $lines[] = 'MoveTo target: hand_' . $reference['serial'] . ' = ' . stobePromptXmlEscape($reference['name']);
            }
        }
    }
    $lines[] = '</action_state>';
    return implode("\n", $lines);
}

function appendActionGuidanceToPrompt(string $prompt, string $eventType, array $npcData = []): string {
    if (!stobePromptContextOptionEnabled('enabled_sections', 'available_actions_list')) return $prompt;
    [$rows, $config] = stobeBuildCurrentActionRows($eventType, $npcData);
    if (!boolval($config['enabled'] ?? false)) return $prompt;
    $guidance = buildActionGuidanceFromRows($rows, $npcData);
    if ($guidance === '') return $prompt;
    $inPlayerFaction = count($npcData) > 0 ? npcIsInPlayerFaction($npcData) : null;
    $guidance = stobeFilterPartyActionGuidanceByMembership($guidance, $inPlayerFaction);
    return implode("\n\n", [$prompt, $guidance]);
}

function stobeBuildFactionRelationsActionInlineGuidance(array $npcData): string {
    if (count($npcData) === 0) {
        return '';
    }
    $speakerFactionIdentity = getNpcFactionIdentityFromProfile($npcData);
    $speakerFactionName = trim(strval($speakerFactionIdentity['name'] ?? ''));
    $speakerFactionName = stobeResolvePlayerFactionPromptDisplayName($speakerFactionName, $speakerFactionIdentity);
    if ($speakerFactionName === '') {
        $speakerFactionName = 'Unknown';
    }

    $speakerName = normalizeParticipantNameToken(strval($npcData['name'] ?? ''));
    $speakerKey = strtolower($speakerName);

    $extendedData = normalizeNpcExtendedDataPayload($npcData['extended_data'] ?? []);
    $nearby = stobeExtractSceneArray($extendedData, 'nearby_actors');
    if (count($nearby) === 0) {
        $nearby = stobeExtractSceneArray($extendedData, 'nearby');
    }

    $targets = [];
    $seen = [];
    foreach ($nearby as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $name = normalizeParticipantNameToken(strval($entry['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $nameKey = strtolower($name);
        if ($nameKey === $speakerKey || isset($seen[$nameKey])) {
            continue;
        }
        if (!nearbyEntryIsInPlayerFaction($entry)) {
            continue;
        }
        $seen[$nameKey] = true;
        $targets[] = $name;
        if (count($targets) >= 20) {
            break;
        }
    }

    $targetsText = count($targets) > 0 ? implode(', ', $targets) : '(none detected right now)';
    $text = 'FactionRelations rules: use item value -100 or 100 only. '
        . 'Changes relation between your faction and the faction of the named nearby player-faction person. '
        . 'Your faction: ' . $speakerFactionName . '. '
        . 'Nearby player-faction targets: ' . $targetsText . '.';
    return truncatePromptValue($text, 520);
}

function stobeCanonicalizeActionCommand(string $command): string {
    $upper = strtoupper(trim($command));
    if ($upper === '') {
        return '';
    }
    if (in_array($upper, ['MOVETO', 'MOVE-TO'], true)) {
        return 'MOVE_TO';
    }
    if ($upper === 'STOPATTACK') {
        return 'STOP_ATTACK';
    }
    if (in_array($upper, ['TRAVELLOCATION', 'TRAVEL-LOCATION'], true)) {
        return 'TRAVEL_LOCATION';
    }
    if (in_array($upper, [
        'STOPCARRYING',
        'DROPNPC', 'DROP_NPC', 'DROP-NPC',
        'PUTDOWNNPC', 'PUT_DOWN_NPC', 'PUT-DOWN-NPC',
        'RELEASENPC', 'RELEASE_NPC', 'RELEASE-NPC',
    ], true)) {
        return 'STOP_CARRYING';
    }
    if (in_array($upper, ['PICKUPNPC', 'PICKUP-NPC', 'KIDNAP'], true)) {
        return 'PICKUP_NPC';
    }
    if (in_array($upper, ['UNEQUIPITEM', 'UNEQUIP-ITEM', 'TAKEOFFITEM', 'TAKE_OFF_ITEM', 'TAKE-OFF-ITEM'], true)) {
        return 'UNEQUIP_ITEM';
    }
    if (in_array($upper, ['EQUIPITEM', 'EQUIP-ITEM'], true)) {
        return 'EQUIP_ITEM';
    }
    if (in_array($upper, ['SHEATHEWEAPON', 'SHEATHE-WEAPON', 'HOLSTERWEAPON'], true)) {
        return 'SHEATHE_WEAPON';
    }
    if (in_array($upper, ['DRAWWEAPON', 'DRAW-WEAPON', 'READYWEAPON'], true)) {
        return 'DRAW_WEAPON';
    }
    if (in_array($upper, ['FACETARGET', 'FACE-TARGET', 'LOOKAT', 'LOOK_AT'], true)) {
        return 'FACE_TARGET';
    }
    if (in_array($upper, ['FIRSTAID', 'FIRST-AID', 'HEALTARGET', 'HEAL_TARGET'], true)) {
        return 'FIRST_AID';
    }
    if (in_array($upper, ['GUARDTARGET', 'GUARD_TARGET', 'BODYGUARDTARGET'], true)) {
        return 'BODYGUARD';
    }
    if (in_array($upper, ['PUTINBED', 'PUT-IN-BED'], true)) {
        return 'PUT_IN_BED';
    }
    if (in_array($upper, ['LOOTTARGET', 'LOOT-TARGET'], true)) {
        return 'LOOT_TARGET';
    }
    if (in_array($upper, ['RELEASEPRISONER', 'RELEASE-PRISONER'], true)) {
        return 'RELEASE_PRISONER';
    }
    if (in_array($upper, ['DROPWEAPON', 'DROP-WEAPON'], true)) {
        return 'DROP_WEAPON';
    }
    if (in_array($upper, ['DISARM', 'DISARMSELF', 'DISARM_SELF'], true)) {
        return 'SURRENDER';
    }
    if (in_array($upper, ['MOVETOTARGET', 'MOVE-TO-TARGET', 'GOTOACTOR'], true)) {
        return 'MOVE_TO_TARGET';
    }
    if (in_array($upper, ['SIT', 'TAKEASEAT', 'TAKE_A_SEAT'], true)) {
        return 'SIT';
    }
    if (in_array($upper, ['SLEEP', 'GOTOSLEEP', 'GO_TO_SLEEP'], true)) {
        return 'SLEEP';
    }
    if (in_array($upper, ['OPERATEOBJECT', 'OPERATE-OBJECT', 'WORKOBJECT'], true)) {
        return 'OPERATE_OBJECT';
    }
    if (in_array($upper, ['HOLDPOSITION', 'HOLD-POSITION'], true)) {
        return 'HOLD_POSITION';
    }
    if (in_array($upper, ['WORKGOAL', 'WORK-GOAL', 'PRODUCEGOAL', 'PRODUCE_GOAL', 'CRAFTGOAL', 'CRAFT_GOAL'], true)) {
        return 'WORK_GOAL';
    }
    if (in_array($upper, ['REMOVELIMB'], true)) {
        return 'REMOVE_LIMB';
    }
    if (in_array($upper, ['CUTHORNS', 'CUT-HORNS'], true)) {
        return 'CUT_HORNS';
    }
    if (in_array($upper, ['KO', 'KNOCK_OUT', 'KNOCK-OUT'], true)) {
        return 'KNOCKOUT';
    }
    if (in_array($upper, ['KILLTARGET', 'EXECUTE', 'MURDER'], true)) {
        return 'KILL';
    }
    if (in_array($upper, ['USEOBJECT', 'USE-OBJECT'], true)) {
        return 'USE_OBJECT';
    }
    if (in_array($upper, ['USEDRUGS', 'USE-DRUGS'], true)) {
        return 'USE_DRUGS';
    }
    if (in_array($upper, ['FORCEDRINK', 'FORCE-DRINK'], true)) {
        return 'FORCE_DRINK';
    }
    if (in_array($upper, ['DRINK', 'DRINKITEM', 'DRINK_ITEM', 'DRINK-ITEM'], true)) {
        return 'DRINK_ITEM';
    }
    if (in_array($upper, ['ROLEPLAYACTION', 'ROLEPLAY-ACTION', 'NOTIFY'], true)) {
        return 'ROLEPLAY_ACTION';
    }
    return $upper;
}

function stobeParseFlexibleBool(mixed $value): ?bool {
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
        if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off', 'disabled'], true)) {
            return false;
        }
    }
    return null;
}

function stobeResolveNpcCarryStateFromRecentEvents(string $npcName, int $maxAgeSeconds = 3600): array {
    $resolved = [
        'known' => false,
        'is_carrying' => false,
        'target_name' => '',
    ];

    $safeNpcName = normalizeParticipantNameToken($npcName);
    if ($safeNpcName === '') {
        return $resolved;
    }
    if ($maxAgeSeconds < 0) {
        $maxAgeSeconds = 0;
    }

    static $cache = [];
    $cacheKey = strtolower($safeNpcName) . '|' . strval($maxAgeSeconds);
    if (array_key_exists($cacheKey, $cache) && is_array($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db || !is_object($db) || !method_exists($db, 'fetchAll')) {
        $cache[$cacheKey] = $resolved;
        return $resolved;
    }

    $rows = $db->fetchAll(
        "SELECT type, data, ts, localts
         FROM eventlog
         WHERE type IN ('carry', 'action', 'infoaction')
           AND data ILIKE $1
         ORDER BY gamets DESC, ts DESC, rowid DESC
         LIMIT 180",
        [$safeNpcName . ':%']
    );
    if (!is_array($rows) || count($rows) === 0) {
        $cache[$cacheKey] = $resolved;
        return $resolved;
    }

    $nowTs = time();
    $extractCarryStateFromActionBody = static function (string $body): array {
        $result = [
            'known' => false,
            'is_carrying' => false,
            'target_name' => '',
        ];
        if ($body === '') {
            return $result;
        }

        if (
            preg_match(
                '/\b(PICKUP_NPC|PICKUPNPC|PICKUP-NPC|KIDNAP|STOP_CARRYING|STOPCARRYING|DROPNPC|DROP_NPC|DROP-NPC|PUTDOWNNPC|PUT_DOWN_NPC|PUT-DOWN-NPC|RELEASENPC|RELEASE_NPC|RELEASE-NPC|RELEASE_PLAYER|RELEASE_PRISONER|RELEASEPLAYER)\s*@\s*([^\r\n]*)/iu',
                $body,
                $match
            ) !== 1
        ) {
            return $result;
        }

        $commandRaw = strtoupper(trim(strval($match[1] ?? '')));
        if ($commandRaw === '') {
            return $result;
        }
        $command = stobeCanonicalizeActionCommand($commandRaw);

        $targetRaw = trim(strval($match[2] ?? ''));
        if ($targetRaw !== '') {
            $targetRaw = preg_replace('/\s*\[source:[^\]]+\]\s*$/iu', '', $targetRaw) ?? $targetRaw;
            $targetRaw = preg_replace('/\s*\(source:[^)]+\)\s*$/iu', '', $targetRaw) ?? $targetRaw;
            $targetRaw = preg_replace('/\s*\(talking to:[^)]+\)\s*$/iu', '', $targetRaw) ?? $targetRaw;
            $targetRaw = trim($targetRaw);
        }
        $targetName = normalizeParticipantNameToken($targetRaw);
        if (in_array(strtolower($targetName), ['someone', 'their carried target'], true)) {
            $targetName = '';
        }

        if ($command === 'PICKUP_NPC') {
            return [
                'known' => true,
                'is_carrying' => true,
                'target_name' => $targetName,
            ];
        }
        if ($command === 'STOP_CARRYING') {
            return [
                'known' => true,
                'is_carrying' => false,
                'target_name' => '',
            ];
        }

        return $result;
    };

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $eventTs = intval($row['ts'] ?? 0);
        if ($eventTs <= 0) {
            $eventTs = intval($row['localts'] ?? 0);
        }
        if ($maxAgeSeconds > 0 && $eventTs > 0 && $eventTs < ($nowTs - $maxAgeSeconds)) {
            continue;
        }

        $eventType = strtolower(trim(strval($row['type'] ?? '')));
        $line = trim(strval($row['data'] ?? ''));
        if ($line === '') {
            continue;
        }
        $line = preg_replace('/^\[[^\]]+\]\s*/u', '', $line) ?? $line;

        if (preg_match('/^([^:]+):\s*(.+)$/u', $line, $parts) !== 1) {
            continue;
        }
        $eventActor = normalizeParticipantNameToken(strval($parts[1] ?? ''));
        if ($eventActor === '' || strcasecmp($eventActor, $safeNpcName) !== 0) {
            continue;
        }

        $body = trim(strval($parts[2] ?? ''));
        if ($body === '') {
            continue;
        }

        $listenerTarget = '';
        if (preg_match('/\(\s*talking to:\s*([^)]+)\)\s*$/iu', $body, $listenerMatch) === 1) {
            $listenerTarget = normalizeParticipantNameToken(strval($listenerMatch[1] ?? ''));
        }
        $bodySansListener = trim(preg_replace('/\s*\(\s*talking to:[^)]+\)\s*$/iu', '', $body) ?? $body);
        if ($bodySansListener === '') {
            $bodySansListener = $body;
        }

        $isCarryEvent = ($eventType === 'carry');
        $isCarrying = false;
        $targetName = '';
        $resolvedByAction = false;
        if ($isCarryEvent) {
            if (preg_match('/^picked\s+up(?:\s+(.+))?$/iu', $bodySansListener, $pickupMatch) === 1) {
                $isCarrying = true;
                $targetName = normalizeParticipantNameToken(strval($pickupMatch[1] ?? ''));
            } elseif (
                preg_match('/^(?:put\s+down|set\s+down|dropped|let\s+go)(?:\s+(.+))?$/iu', $bodySansListener, $dropMatch) === 1
            ) {
                $isCarrying = false;
                $targetName = normalizeParticipantNameToken(strval($dropMatch[1] ?? ''));
            } else {
                continue;
            }
        } else {
            $actionState = $extractCarryStateFromActionBody($bodySansListener);
            if (!boolval($actionState['known'] ?? false)) {
                continue;
            }
            $resolvedByAction = true;
            $isCarrying = boolval($actionState['is_carrying'] ?? false);
            $targetName = normalizeParticipantNameToken(strval($actionState['target_name'] ?? ''));
        }

        if (!$isCarryEvent && !$resolvedByAction) {
            continue;
        }

        $genericTargetTokens = ['someone', 'their carried target'];
        if ($targetName === '' && $listenerTarget !== '') {
            $targetName = $listenerTarget;
        }
        if ($targetName !== '') {
            $targetLower = strtolower($targetName);
            if (in_array($targetLower, $genericTargetTokens, true)) {
                $targetName = '';
            }
        }
        if (!$isCarrying) {
            $targetName = '';
        }

        $resolved = [
            'known' => true,
            'is_carrying' => $isCarrying,
            'target_name' => $targetName,
        ];
        $cache[$cacheKey] = $resolved;
        return $resolved;
    }

    $cache[$cacheKey] = $resolved;
    return $resolved;
}

function stobeResolveNpcCarryState(array $npcData): array {
    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    $carryFlag = stobeParseFlexibleBool($npcData['is_carrying'] ?? null);
    if (!is_bool($carryFlag)) {
        $carryFlag = stobeParseFlexibleBool($metadata['is_carrying'] ?? null);
    }

    $carryingTargetName = normalizeParticipantNameToken(strval($npcData['carrying_target_name'] ?? ''));
    if ($carryingTargetName === '') {
        $carryingTargetName = normalizeParticipantNameToken(strval($metadata['carrying_target_name'] ?? ''));
    }

    $isCarrying = false;
    if ($carryFlag === true) {
        $isCarrying = true;
    } elseif ($carryFlag === null && $carryingTargetName !== '') {
        $isCarrying = true;
    }

    if ($carryFlag === false) {
        $carryingTargetName = '';
    }

    return [
        'is_carrying' => $isCarrying,
        'target_name' => $carryingTargetName,
    ];
}

function stobeNpcIsCarryingTarget(array $npcData): bool {
    $state = stobeResolveNpcCarryState($npcData);
    return boolval($state['is_carrying'] ?? false);
}

function stobeNpcHasItemKeyword(array $npcData, array $keywords): bool {
    $normalizedKeywords = [];
    foreach ($keywords as $keywordRaw) {
        $keyword = strtolower(trim(strval($keywordRaw)));
        if ($keyword === '') {
            continue;
        }
        if (!in_array($keyword, $normalizedKeywords, true)) {
            $normalizedKeywords[] = $keyword;
        }
    }
    if (count($normalizedKeywords) === 0) {
        return false;
    }

    $haystacks = [];
    $equipment = trim(strval($npcData['equipment'] ?? ''));
    if ($equipment !== '') {
        $haystacks[] = $equipment;
    }
    $inventory = trim(strval($npcData['inventory'] ?? ''));
    if ($inventory !== '') {
        $haystacks[] = $inventory;
    }

    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    $inventoryItems = $metadata['inventory_items'] ?? [];
    if (is_array($inventoryItems)) {
        foreach ($inventoryItems as $entry) {
            if (is_string($entry) || is_numeric($entry)) {
                $value = trim(strval($entry));
                if ($value !== '') {
                    $haystacks[] = $value;
                }
                continue;
            }
            if (!is_array($entry)) {
                continue;
            }
            foreach (['name', 'item_name', 'display_name', 'item_id', 'id', 'base_id'] as $field) {
                $value = trim(strval($entry[$field] ?? ''));
                if ($value !== '') {
                    $haystacks[] = $value;
                }
            }
        }
    }

    foreach ($haystacks as $haystackRaw) {
        $haystack = strtolower(strval($haystackRaw));
        if ($haystack === '') {
            continue;
        }
        foreach ($normalizedKeywords as $keyword) {
            if (strpos($haystack, $keyword) !== false) {
                return true;
            }
        }
    }
    return false;
}

function stobeNpcHasHacksaw(array $npcData): bool {
    return stobeNpcHasItemKeyword($npcData, ['hacksaw', 'hack saw']);
}

function stobeNpcHasHashish(array $npcData): bool {
    return stobeNpcHasItemKeyword($npcData, ['hashish']);
}

function stobeNpcHasDrinkItem(array $npcData): bool {
    return stobeNpcHasItemKeyword($npcData, [
        'bloodrum',
        'blood rum',
        'cactus rum',
        'cactusrum',
        'grog',
        'sake',
    ]);
}

function stobeNpcIsSkeletonRace(array $npcData): bool {
    $race = strtolower(trim(strval($npcData['race'] ?? '')));
    if ($race !== '' && strpos($race, 'skeleton') !== false) {
        return true;
    }
    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    $metaRace = strtolower(trim(strval($metadata['race'] ?? '')));
    if ($metaRace !== '' && strpos($metaRace, 'skeleton') !== false) {
        return true;
    }
    return false;
}

function isAllowedActionCommand(string $command, array $allowlist): bool {
    $command = stobeCanonicalizeActionCommand($command);
    if (in_array($command, ['RELEASE_PLAYER', 'RELEASEPLAYER'], true)) {
        return false;
    }
    if (count($allowlist) === 0) {
        return true;
    }
    foreach ($allowlist as $allowedCommand) {
        if ($command === stobeCanonicalizeActionCommand(strval($allowedCommand))) {
            return true;
        }
    }
    return false;
}


function stobeNpcPlayerAffinity(array|false $npcData): int {
    if (!is_array($npcData)) {
        return 0;
    }
    $playerName = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    if ($playerName === '') {
        return 0;
    }

    $extended = normalizeNpcExtendedDataPayload($npcData['extended_data'] ?? []);
    $raw = $extended['relationships'] ?? [];
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }
    if (is_array($raw)) {
        foreach ($raw as $target => $entry) {
            if (strcasecmp(normalizeParticipantNameToken(strval($target)), $playerName) !== 0 || !is_array($entry)) {
                continue;
            }
            return max(-100, min(100, intval($entry['aff'] ?? ($entry['affinity'] ?? 0))));
        }
    }

    foreach (stobeGetNpcRelationshipMap($npcData) as $target => $entry) {
        if (strcasecmp(normalizeParticipantNameToken(strval($target)), $playerName) !== 0 || !is_array($entry)) {
            continue;
        }
        return max(-100, min(100, intval($entry['aff'] ?? ($entry['affinity'] ?? 0))));
    }
    return 0;
}

function stobeCurrentRequestLooksLikeExplicitBarter(): bool {
    $message = strtolower(trim(strval($GLOBALS['STOBE_CURRENT_PLAYER_MESSAGE'] ?? '')));
    if ($message === '') {
        return false;
    }
    if (preg_match('/\b(trade|swap|exchange|barter)\b/i', $message) === 1) {
        return true;
    }
    if (preg_match('/\b(i(?:\'|’)ll|i will|give you|offer you)\b.{0,100}\b(for|in return|in exchange)\b/i', $message) === 1) {
        return true;
    }
    return false;
}

function stobeNpcProtectedEquipmentText(array|false $npcData): string {
    if (!is_array($npcData)) {
        return '';
    }
    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    $ctx = buildInventoryContextFromMetadata($metadata);
    $equipment = trim(strval($ctx['equipment'] ?? ''));
    if ($equipment === '') {
        $equipment = trim(strval($npcData['equipment'] ?? ''));
    }
    return strtolower($equipment);
}

function stobeGiveItemNameFromActionArgument(string $argument): string {
    $parts = array_values(array_filter(array_map('trim', explode('@', $argument)), static fn(string $v): bool => $v !== ''));
    if (count($parts) === 0) {
        return '';
    }
    if (count($parts) > 1 && preg_match('/^\d+$/', strval(end($parts))) === 1) {
        array_pop($parts);
    }
    if (count($parts) === 0) {
        return '';
    }
    return trim(strval(end($parts)));
}

function stobeGiveItemTargetFromActionArgument(string $argument): string {
    $parts = array_values(array_filter(array_map('trim', explode('@', $argument)), static fn(string $v): bool => $v !== ''));
    if (count($parts) > 1 && preg_match('/^\d+$/', strval(end($parts))) === 1) {
        array_pop($parts);
    }
    if (count($parts) < 2) {
        return '';
    }
    return normalizeParticipantNameToken(strval($parts[0]));
}

function stobeGiveItemMatchesProtectedEquipment(string $argument, array $config): bool {
    $item = strtolower(stobeGiveItemNameFromActionArgument($argument));
    $equipment = strtolower(trim(strval($config['protected_equipment_text'] ?? '')));
    if ($item === '' || $equipment === '') {
        return false;
    }
    return str_contains($equipment, $item);
}

function stobeProtectedGiveWouldBeBlocked(string $rawTag, array $config): bool {
    $value = trim($rawTag);
    $at = strpos($value, '@');
    if ($at === false) {
        return false;
    }
    $command = strtoupper(trim(substr($value, 0, $at)));
    if (in_array($command, ['GIVEITEM','GIVE-ITEM'], true)) {
        $command = 'GIVE_ITEM';
    }
    if ($command !== 'GIVE_ITEM' || boolval($config['in_player_faction'] ?? false)) {
        return false;
    }
    // Part of an agreed deal: a paid-for item is not a gift, even if it's worn.
    if (boolval($config['deal_sanctioned_give'] ?? false)) {
        return false;
    }
    $dealNpc = strval($config['npc_name'] ?? '');
    if ($dealNpc !== '' && function_exists('stobeNegNpcHasDealContext') && stobeNegNpcHasDealContext($dealNpc)) {
        return false;
    }
    $argument = trim(substr($value, $at + 1));
    $giveTarget = stobeGiveItemTargetFromActionArgument($argument);
    $playerName = normalizeParticipantNameToken(strval($config['player_name'] ?? ''));
    $targetsPlayer = ($giveTarget === '' || $playerName === '' || strcasecmp($giveTarget, $playerName) === 0);
    $affinity = intval($config['player_affinity'] ?? 0);
    $threshold = max(0, min(100, intval($config['give_equipment_trust_threshold'] ?? 60)));
    $barter = boolval($config['explicit_barter_offer'] ?? false);
    return $targetsPlayer && $affinity < $threshold && !$barter
        && stobeGiveItemMatchesProtectedEquipment($argument, $config);
}

/**
 * An outsider giving the player an item or Cats with nothing in return. Allowed
 * only at high trust (GIFT_TRUST_THRESHOLD, default 56 = Fond) or when the
 * hand-over is part of an agreed deal (the negotiation ledger sets
 * deal_sanctioned_give; deal settlements are attached after this filter).
 */
function stobeUnpaidGiftWouldBeBlocked(string $command, string $argument, array $config): bool {
    if (!in_array($command, ['GIVE_ITEM','GIVE_CATS'], true)) {
        return false;
    }
    if (boolval($config['in_player_faction'] ?? false) || boolval($config['deal_sanctioned_give'] ?? false)) {
        return false;
    }
    // Checked against the deal ledger itself: every code path that streams or re-validates
    // actions builds its own config, so a flag alone is not enough.
    $npcName = strval($config['npc_name'] ?? '');
    if ($npcName !== '' && function_exists('stobeNegNpcHasDealContext') && stobeNegNpcHasDealContext($npcName)) {
        return false;
    }
    // Bug 122: handing back a refund is not a gift.
    if ($npcName !== '' && $command === 'GIVE_CATS' && isset($GLOBALS['db'])) {
        $refund = $GLOBALS['db']->fetchOne(
            "SELECT 1 AS hit FROM stobe_negotiation_directive WHERE kind='refund' AND LOWER(npc_name)=LOWER($1) AND created_unix >= $2 LIMIT 1",
            [$npcName, time() - 600]
        );
        if (is_array($refund)) return false;
    }
    $playerName = normalizeParticipantNameToken(strval($config['player_name'] ?? ''));
    $target = $command === 'GIVE_ITEM'
        ? stobeGiveItemTargetFromActionArgument($argument)
        : normalizeParticipantNameToken(strval(explode('@', $argument)[0] ?? ''));
    $targetsPlayer = $target === '' || $playerName === '' || strcasecmp($target, $playerName) === 0;
    if (!$targetsPlayer) {
        return false;
    }
    return intval($config['player_affinity'] ?? 0) < intval($config['gift_trust_threshold'] ?? 56);
}

function stobeCurrentRequestClaimsKeepingProperty(): bool {
    $message = strtolower(trim(strval($GLOBALS['STOBE_CURRENT_PLAYER_MESSAGE'] ?? '')));
    if ($message === '') {
        return false;
    }
    return preg_match('/\b(i(?:\'|’)m|i am|i(?:\'|’)ll|i will|gonna|going to)\b.{0,45}\b(keep|keeping|take|taking|steal|stealing)\b/i', $message) === 1
        || preg_match('/\b(not|never)\b.{0,30}\b(return|give)\b.{0,20}\b(back|it)\b/i', $message) === 1
        || preg_match('/\b(it(?:\'|’)s|it is)\s+mine\b/i', $message) === 1;
}

function normalizeActionTagToken(string $rawTag, array $config = []): string {
    $value = trim($rawTag);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    if ($value === '') {
        return '';
    }

    $firstAt = strpos($value, '@');
    if ($firstAt === false) {
        return '';
    }

    $command = strtoupper(trim(substr($value, 0, $firstAt)));
    $argument = trim(substr($value, $firstAt + 1));
    if ($command === '') {
        return '';
    }

    $commandAliases = [
        'STOPATTACK' => 'STOP_ATTACK',
        'STOPFOLLOW' => 'STOP_FOLLOW',
        'JOINPARTY' => 'JOIN_PARTY',
        'KILLSELF' => 'SUICIDE',
        'SELFDESTRUCT' => 'SUICIDE',
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
        'GIVECATS' => 'GIVE_CATS',
        'TAKECATS' => 'TAKE_CATS',
        'TAKEITEM' => 'TAKE_ITEM',
        'GIVEITEM' => 'GIVE_ITEM',
        'DROPITEM' => 'DROP_ITEM',
        'UNEQUIPITEM' => 'UNEQUIP_ITEM',
        'UNEQUIP-ITEM' => 'UNEQUIP_ITEM',
        'TAKEOFFITEM' => 'UNEQUIP_ITEM',
        'TAKE_OFF_ITEM' => 'UNEQUIP_ITEM',
        'TAKE-OFF-ITEM' => 'UNEQUIP_ITEM',
        'EQUIPITEM' => 'EQUIP_ITEM',
        'EQUIP-ITEM' => 'EQUIP_ITEM',
        'SHEATHEWEAPON' => 'SHEATHE_WEAPON',
        'SHEATHE-WEAPON' => 'SHEATHE_WEAPON',
        'HOLSTERWEAPON' => 'SHEATHE_WEAPON',
        'DRAWWEAPON' => 'DRAW_WEAPON',
        'DRAW-WEAPON' => 'DRAW_WEAPON',
        'READYWEAPON' => 'DRAW_WEAPON',
        'FACETARGET' => 'FACE_TARGET',
        'FACE-TARGET' => 'FACE_TARGET',
        'LOOKAT' => 'FACE_TARGET',
        'LOOK_AT' => 'FACE_TARGET',
        'FIRSTAID' => 'FIRST_AID',
        'FIRST-AID' => 'FIRST_AID',
        'HEALTARGET' => 'FIRST_AID',
        'HEAL_TARGET' => 'FIRST_AID',
        'GUARDTARGET' => 'BODYGUARD',
        'GUARD_TARGET' => 'BODYGUARD',
        'BODYGUARDTARGET' => 'BODYGUARD',
        'PUTINBED' => 'PUT_IN_BED',
        'PUT-IN-BED' => 'PUT_IN_BED',
        'LOOTTARGET' => 'LOOT_TARGET',
        'LOOT-TARGET' => 'LOOT_TARGET',
        'RELEASEPRISONER' => 'RELEASE_PRISONER',
        'RELEASE-PRISONER' => 'RELEASE_PRISONER',
        'DROPWEAPON' => 'DROP_WEAPON',
        'DROP-WEAPON' => 'DROP_WEAPON',
        'DISARM' => 'SURRENDER',
        'DISARMSELF' => 'SURRENDER',
        'DISARM_SELF' => 'SURRENDER',
        'MOVETOTARGET' => 'MOVE_TO_TARGET',
        'MOVE-TO-TARGET' => 'MOVE_TO_TARGET',
        'GOTOACTOR' => 'MOVE_TO_TARGET',
        'HOLDPOSITION' => 'HOLD_POSITION',
        'HOLD-POSITION' => 'HOLD_POSITION',
        'TAKEASEAT' => 'SIT',
        'TAKE_A_SEAT' => 'SIT',
        'GOTOSLEEP' => 'SLEEP',
        'GO_TO_SLEEP' => 'SLEEP',
        'OPERATEOBJECT' => 'OPERATE_OBJECT',
        'OPERATE-OBJECT' => 'OPERATE_OBJECT',
        'WORKOBJECT' => 'OPERATE_OBJECT',
        'WORKGOAL' => 'WORK_GOAL',
        'TASKGOAL' => 'TASK_GOAL',
        'TASKCONTROL' => 'TASK_CONTROL',
        'WORK-GOAL' => 'WORK_GOAL',
        'PRODUCEGOAL' => 'WORK_GOAL',
        'PRODUCE_GOAL' => 'WORK_GOAL',
        'CRAFTGOAL' => 'WORK_GOAL',
        'CRAFT_GOAL' => 'WORK_GOAL',
        'FACTIONRELATIONS' => 'FACTION_RELATIONS',
        'SETBLOCK' => 'SET_BLOCK',
        'SETHOLD' => 'SET_HOLD',
        'SETPASSIVE' => 'SET_PASSIVE',
        'SETJOBS' => 'SET_JOBS',
        'SETRANGED' => 'SET_RANGED',
        'SETTAUNT' => 'SET_TAUNT',
        'SETSNEAK' => 'SET_SNEAK',
        'SETRESOURCE' => 'SET_RESOURCE',
        'SETMEDIC' => 'SET_MEDIC',
        'REMOVELIMB' => 'REMOVE_LIMB',
        'CUTHORNS' => 'CUT_HORNS',
        'KO' => 'KNOCKOUT',
        'KNOCK_OUT' => 'KNOCKOUT',
        'KNOCK-OUT' => 'KNOCKOUT',
        'KILLTARGET' => 'KILL',
        'EXECUTE' => 'KILL',
        'MURDER' => 'KILL',
        'USEOBJECT' => 'USE_OBJECT',
        'USE-OBJECT' => 'USE_OBJECT',
        'USEDRUGS' => 'USE_DRUGS',
        'USE-DRUGS' => 'USE_DRUGS',
        'FORCEDRINK' => 'FORCE_DRINK',
        'FORCE-DRINK' => 'FORCE_DRINK',
        'DRINK' => 'DRINK_ITEM',
        'DRINKITEM' => 'DRINK_ITEM',
        'DRINK_ITEM' => 'DRINK_ITEM',
        'DRINK-ITEM' => 'DRINK_ITEM',
        'TRAVELLOCATION' => 'TRAVEL_LOCATION',
        'ROLEPLAYACTION' => 'ROLEPLAY_ACTION',
        'ROLEPLAY-ACTION' => 'ROLEPLAY_ACTION',
        'NOTIFY' => 'ROLEPLAY_ACTION',
    ];
    if (isset($commandAliases[$command])) {
        $command = $commandAliases[$command];
    }
    $command = stobeCanonicalizeActionCommand($command);

    if (!isAllowedActionCommand($command, $config['allowlist'] ?? [])) {
        return '';
    }

    if ($command === 'GIVE_ITEM' && stobeProtectedGiveWouldBeBlocked($value, $config)) {
        $giveTarget = stobeGiveItemTargetFromActionArgument($argument);
        $playerName = normalizeParticipantNameToken(strval($config['player_name'] ?? ''));
        stobeLogWarn('Blocked low-trust outsider from giving equipped gear to player', [
            'item' => stobeGiveItemNameFromActionArgument($argument),
            'target' => $giveTarget !== '' ? $giveTarget : $playerName,
            'affinity' => intval($config['player_affinity'] ?? 0),
            'required_affinity' => intval($config['give_equipment_trust_threshold'] ?? 60),
        ]);
        return '';
    }
    if (stobeUnpaidGiftWouldBeBlocked($command, $argument, $config)) {
        stobeLogWarn('Blocked unpaid gift to player (not enough trust, no agreed deal)', [
            'npc' => strval($config['npc_name'] ?? ''),
            'in_player_faction' => boolval($config['in_player_faction'] ?? false),
            'trace' => implode(' < ', array_map(static fn($f) => strval($f['function'] ?? ''), array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6), 1, 5))),
            'command' => $command,
            'argument' => $argument,
            'affinity' => intval($config['player_affinity'] ?? 0),
            'required_affinity' => intval($config['gift_trust_threshold'] ?? 56),
        ]);
        $GLOBALS['STOBE_BLOCKED_UNPAID_GIFT'] = true;
        return '';
    }
    if (boolval($config['disallow_follow_for_player_faction'] ?? false) &&
        ($command === 'FOLLOW' || $command === 'STOP_FOLLOW')) {
        return '';
    }
    if (boolval($config['disallow_give_cats'] ?? false) &&
        $command === 'GIVE_CATS') {
        return '';
    }
    if (boolval($config['disallow_take_cats'] ?? false) &&
        $command === 'TAKE_CATS') {
        return '';
    }
    if (boolval($config['disallow_stop_carrying'] ?? false) &&
        $command === 'STOP_CARRYING') {
        return '';
    }
    if (boolval($config['disallow_pickup_npc'] ?? false) &&
        $command === 'PICKUP_NPC') {
        return '';
    }
    if (boolval($config['disallow_remove_limb'] ?? false) &&
        $command === 'REMOVE_LIMB') {
        return '';
    }
    if (boolval($config['disallow_cut_horns'] ?? false) &&
        $command === 'CUT_HORNS') {
        return '';
    }
    if (boolval($config['disallow_use_drugs'] ?? false) &&
        $command === 'USE_DRUGS') {
        return '';
    }
    if (boolval($config['disallow_drink_item'] ?? false) &&
        $command === 'DRINK_ITEM') {
        return '';
    }
    if (boolval($config['disallow_force_drink'] ?? false) &&
        $command === 'FORCE_DRINK') {
        return '';
    }
    if (boolval($config['disallow_stop_attack'] ?? false) &&
        $command === 'STOP_ATTACK' &&
        !boolval($config['deal_sanctioned_give'] ?? false)) { // an accepted deal's ceasefire always goes (bug 88)
        return '';
    }
    if ($command === 'TRAVEL_LOCATION' && !boolval($config['allow_travel_location'] ?? false)) {
        return '';
    }

    $simpleNoArg = [
        'JOIN_PARTY', 'LEAVE', 'IDLE',
        'STOP_FOLLOW', 'STOP_CARRYING', 'SUICIDE',
        'SHEATHE_WEAPON', 'DRAW_WEAPON', 'PATROL', 'HOLD_POSITION',
        'DROP_WEAPON', 'SURRENDER',
    ];
    if (in_array($command, $simpleNoArg, true)) {
        return $command . '@';
    }

    $toggleCommands = [
        'SET_BLOCK',
        'SET_HOLD',
        'SET_PASSIVE',
        'SET_JOBS',
        'SET_RANGED',
        'SET_TAUNT',
        'SET_SNEAK',
        'SET_RESOURCE',
        'SET_MEDIC',
    ];
    if (in_array($command, $toggleCommands, true)) {
        $toggle = strtoupper(trim($argument));
        if (in_array($toggle, ['ON', 'TRUE', '1', 'ENABLE', 'ENABLED'], true)) {
            return $command . '@ON';
        }
        if (in_array($toggle, ['OFF', 'FALSE', '0', 'DISABLE', 'DISABLED'], true)) {
            return $command . '@OFF';
        }
        return '';
    }

    $sanitizeInlineText = static function (string $text, int $maxLen = 220): string {
        $clean = trim(preg_replace('/[\r\n\t]+/', ' ', $text) ?? '');
        $clean = trim(str_replace(['[', ']', '@'], '', $clean));
        if ($clean === '') {
            return '';
        }
        if (strlen($clean) > $maxLen) {
            $clean = substr($clean, 0, $maxLen);
        }
        return trim($clean);
    };
    $splitActionSegments = static function (string $raw): array {
        $segments = [];
        foreach (explode('@', $raw) as $segment) {
            $segment = trim(strval($segment));
            if ($segment === '') {
                continue;
            }
            $segments[] = $segment;
        }
        return $segments;
    };
    $parsePositiveAmount = static function (string $raw, int $max = 1000000): int {
        if (preg_match('/-?\d+/', trim($raw), $m) !== 1) {
            return 0;
        }
        $amount = abs(intval($m[0]));
        if ($amount < 1) {
            return 0;
        }
        if ($amount > $max) {
            $amount = $max;
        }
        return $amount;
    };

    if ($command === 'TASK_GOAL') {
        $parts=explode('@',$argument);
        while(count($parts)<6)$parts[]='';
        $kind=strtoupper($sanitizeInlineText(strval($parts[0]),40));
        $targetName=$sanitizeInlineText(strval($parts[1]),140);
        $itemName=$sanitizeInlineText(strval($parts[2]),140);
        $qty=max(0,min(1000,intval($parts[3])));
        $dest=$sanitizeInlineText(strval($parts[4]),160);
        $maxCats=max(0,min(10000000,intval($parts[5])));
        if($kind==='')return '';
        return 'TASK_GOAL@'.$kind.'@'.$targetName.'@'.$itemName.'@'.$qty.'@'.$dest.'@'.$maxCats;
    }
    if ($command === 'TASK_CONTROL') {
        $parts=explode('@',$argument);
        while(count($parts)<4)$parts[]='';
        $cmd=strtoupper($sanitizeInlineText(strval($parts[0]),24));
        if(!in_array($cmd,['PAUSE','RESUME','CANCEL','QUANTITY','DESTINATION','APPROVE','DECLINE'],true))return '';
        $selector=$sanitizeInlineText(strval($parts[1]),160);
        $qty=max(0,min(1000,intval($parts[2])));
        $dest=$sanitizeInlineText(strval($parts[3]),160);
        return 'TASK_CONTROL@'.$cmd.'@'.$selector.'@'.$qty.'@'.$dest;
    }

    if ($command === 'WORK_GOAL') {
        $rawSegments = array_map('trim', explode('@', $argument));
        $rawSegments = array_values(array_filter($rawSegments, static fn(string $v): bool => $v !== ''));
        if (count($rawSegments) < 2) {
            return '';
        }
        $amount = $parsePositiveAmount($rawSegments[count($rawSegments) - 1], 1000);
        if ($amount < 1) {
            return '';
        }
        array_pop($rawSegments);
        $destination = '';
        if (count($rawSegments) >= 2) {
            $destination = $sanitizeInlineText(array_shift($rawSegments), 160);
        }
        $itemName = $sanitizeInlineText(implode(' ', $rawSegments), 160);
        if ($itemName === '') {
            return '';
        }
        return $destination !== ''
            ? 'WORK_GOAL@' . $destination . '@' . $itemName . '@' . strval($amount)
            : 'WORK_GOAL@' . $itemName . '@' . strval($amount);
    }

    if ($command === 'GIVE_CATS' || $command === 'TAKE_CATS') {
        $segments = $splitActionSegments($argument);
        if (count($segments) === 0) {
            return '';
        }
        $targetName = '';
        $amount = 0;
        if (count($segments) === 1) {
            $amount = $parsePositiveAmount($segments[0]);
        } else {
            $tailAmount = $parsePositiveAmount($segments[count($segments) - 1]);
            if ($tailAmount > 0) {
                $amount = $tailAmount;
                array_pop($segments);
                $targetName = $sanitizeInlineText(implode(' ', $segments), 160);
            } else {
                $headAmount = $parsePositiveAmount($segments[0]);
                if ($headAmount > 0) {
                    $amount = $headAmount;
                    array_shift($segments);
                    $targetName = $sanitizeInlineText(implode(' ', $segments), 160);
                }
            }
        }
        if ($amount < 1) {
            return '';
        }
        if ($targetName !== '') {
            return $command . '@' . $targetName . '@' . strval($amount);
        }
        return $command . '@' . strval($amount);
    }

    if ($command === 'TAKE_ITEM' || $command === 'GIVE_ITEM') {
        $segments = $splitActionSegments($argument);
        if (count($segments) === 0) {
            return '';
        }
        $amount = 0;
        if (count($segments) >= 2) {
            $tailAmount = $parsePositiveAmount($segments[count($segments) - 1], 100);
            if ($tailAmount > 0) {
                $amount = $tailAmount;
                array_pop($segments);
            }
        }
        $targetName = '';
        if (count($segments) >= 2) {
            $targetName = $sanitizeInlineText(array_shift($segments), 160);
        }
        $itemName = $sanitizeInlineText(implode(' ', $segments), 140);
        if ($itemName === '') {
            return '';
        }
        $normalized = $command . '@';
        if ($targetName !== '') {
            $normalized .= $targetName . '@';
        }
        $normalized .= $itemName;
        if ($amount > 0) {
            $normalized .= '@' . strval($amount);
        }
        return $normalized;
    }

    if ($command === 'DROP_ITEM' || $command === 'UNEQUIP_ITEM' || $command === 'EQUIP_ITEM') {
        $itemName = $sanitizeInlineText($argument, 140);
        if ($itemName === '') {
            return '';
        }
        return $command . '@' . $itemName;
    }
    if (in_array($command, ['FACE_TARGET', 'FIRST_AID', 'BODYGUARD', 'RESCUE', 'PUT_IN_BED', 'LOOT_TARGET', 'IMPRISON', 'RELEASE_PRISONER', 'MOVE_TO_TARGET'], true)) {
        $targetName = $sanitizeInlineText($argument, 140);
        if ($targetName === '') {
            return '';
        }
        return $command . '@' . $targetName;
    }
    if ($command === 'REPAIR' || $command === 'BUILD') {
        $objectName = $sanitizeInlineText($argument, 160);
        if ($objectName === '') {
            return '';
        }
        return $command . '@' . $objectName;
    }
    if ($command === 'SIT' || $command === 'SLEEP' || $command === 'OPERATE_OBJECT') {
        $objectName = $sanitizeInlineText($argument, 160);
        if ($objectName === '') {
            $objectName = $command === 'SIT' ? 'chair' : ($command === 'SLEEP' ? 'bed' : '');
        }
        return 'USE_OBJECT@' . $objectName;
    }
    if ($command === 'REMOVE_LIMB') {
        $payload = trim($argument);
        if ($payload === '') {
            return '';
        }
        $payloadParts = explode('@', $payload, 2);
        if (count($payloadParts) !== 2) {
            return '';
        }
        $targetName = $sanitizeInlineText(strval($payloadParts[0]), 120);
        $limbToken = strtoupper(trim(strval($payloadParts[1])));
        if ($targetName === '' || $limbToken === '') {
            return '';
        }
        $limbAliases = [
            'LEFT_ARM' => 'LEFT_ARM',
            'RIGHT_ARM' => 'RIGHT_ARM',
            'LEFT_LEG' => 'LEFT_LEG',
            'RIGHT_LEG' => 'RIGHT_LEG',
            'LEFTARM' => 'LEFT_ARM',
            'RIGHTARM' => 'RIGHT_ARM',
            'LEFTLEG' => 'LEFT_LEG',
            'RIGHTLEG' => 'RIGHT_LEG',
            'L_ARM' => 'LEFT_ARM',
            'R_ARM' => 'RIGHT_ARM',
            'L_LEG' => 'LEFT_LEG',
            'R_LEG' => 'RIGHT_LEG',
            'LARM' => 'LEFT_ARM',
            'RARM' => 'RIGHT_ARM',
            'LLEG' => 'LEFT_LEG',
            'RLEG' => 'RIGHT_LEG',
        ];
        if (!isset($limbAliases[$limbToken])) {
            return '';
        }
        return 'REMOVE_LIMB@' . $targetName . '@' . strval($limbAliases[$limbToken]);
    }
    if ($command === 'CUT_HORNS') {
        $targetName = $sanitizeInlineText($argument, 120);
        if ($targetName === '') {
            return '';
        }
        return 'CUT_HORNS@' . $targetName;
    }
    if ($command === 'KNOCKOUT') {
        $targetName = $sanitizeInlineText($argument, 120);
        if ($targetName === '') {
            return '';
        }
        return 'KNOCKOUT@' . $targetName;
    }
    if ($command === 'KILL') {
        $targetName = $sanitizeInlineText($argument, 120);
        if ($targetName === '') {
            return '';
        }
        return 'KILL@' . $targetName;
    }
    if ($command === 'PICKUP_NPC') {
        $targetName = $sanitizeInlineText($argument, 120);
        if ($targetName === '') {
            return '';
        }
        return 'PICKUP_NPC@' . $targetName;
    }
    if ($command === 'MOVE_TO') {
        $targetToken = $sanitizeInlineText($argument, 120);
        return $targetToken !== '' ? 'MOVE_TO@' . $targetToken : '';
    }
    if ($command === 'USE_OBJECT') {
        $objectToken = $sanitizeInlineText($argument, 160);
        if ($objectToken === '') {
            return 'USE_OBJECT@';
        }
        return 'USE_OBJECT@' . $objectToken;
    }
    if ($command === 'USE_DRUGS') {
        $drugName = $sanitizeInlineText($argument, 80);
        if ($drugName === '') {
            return 'USE_DRUGS@Hashish';
        }
        return 'USE_DRUGS@' . $drugName;
    }
    if ($command === 'DRINK_ITEM') {
        $drinkName = $sanitizeInlineText($argument, 80);
        if ($drinkName === '') {
            return 'DRINK_ITEM@Cactus Rum';
        }
        return 'DRINK_ITEM@' . $drinkName;
    }
    if ($command === 'FORCE_DRINK') {
        $payload = trim($argument);
        if ($payload === '') {
            return '';
        }
        $targetName = '';
        $drinkName = '';
        $payloadParts = explode('@', $payload, 2);
        if (count($payloadParts) === 2) {
            $targetName = $sanitizeInlineText(strval($payloadParts[0]), 120);
            $drinkName = $sanitizeInlineText(strval($payloadParts[1]), 80);
        } else {
            $targetName = $sanitizeInlineText($payload, 120);
        }
        if ($targetName === '') {
            return '';
        }
        if ($drinkName === '') {
            $drinkName = 'Cactus Rum';
        }
        return 'FORCE_DRINK@' . $targetName . '@' . $drinkName;
    }

    if ($command === 'ATTACK') {
        // Bug 39: keep the "@help" flag (she called her faction-mates in); the sanitizer strips '@'.
        $helpFlag = '';
        if (preg_match('/^(.*)@help\s*$/i', $argument, $helpMatch)) {
            $argument = $helpMatch[1];
            $helpFlag = '@help';
        }
        $targetName = $sanitizeInlineText($argument, 120);
        if ($targetName === '') {
            return 'ATTACK@';
        }
        return 'ATTACK@' . $targetName . $helpFlag;
    }
    if ($command === 'STOP_ATTACK') {
        $targetName = $sanitizeInlineText($argument, 120);
        if ($targetName === '') {
            return '';
        }
        return 'STOP_ATTACK@' . $targetName;
    }
    if ($command === 'FOLLOW') {
        $targetName = $sanitizeInlineText($argument, 120);
        if ($targetName === '') {
            return '';
        }
        return 'FOLLOW@' . $targetName;
    }
    if ($command === 'TRAVEL_LOCATION') {
        $locationName = $sanitizeInlineText($argument, 120);
        if ($locationName === '') {
            return '';
        }
        return 'TRAVEL_LOCATION@' . $locationName;
    }

    if ($command === 'ROLEPLAY_ACTION') {
        $notice = $sanitizeInlineText($argument, 220);
        if ($notice === '') {
            return '';
        }
        return 'ROLEPLAY_ACTION@' . $notice;
    }

    if ($command === 'FACTION_RELATIONS') {
        if (preg_match('/^\s*([^@:]+?)\s*[@:]\s*(-?\d{1,4})\s*$/', $argument, $m) !== 1) {
            return '';
        }
        $targetName = $sanitizeInlineText(strval($m[1]), 80);
        if ($targetName === '') {
            return '';
        }
        $relationDelta = intval($m[2]);
        if ($relationDelta === 0) {
            return '';
        }
        $relationDelta = $relationDelta < 0 ? -100 : 100;
        return 'FACTION_RELATIONS@' . $targetName . '@' . strval($relationDelta);
    }

    if ($command === 'TASK') {
        $taskName = strtoupper($sanitizeInlineText($argument, 64));
        if ($taskName === '') {
            return '';
        }
        return 'TASK@' . $taskName;
    }

    return '';
}

function stobeSanitizeTravelLocationToken(string $value, int $maxLen = 120): string {
    $normalized = trim(preg_replace('/[\r\n\t]+/', ' ', $value) ?? '');
    if ($normalized === '') {
        return '';
    }
    $normalized = trim(str_replace(['[', ']', '@', '|', ';'], '', $normalized));
    $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    $normalized = trim($normalized, " \t\n\r\0\x0B\"'.,!?:;");
    if (function_exists('stobeNormalizeZoneLocationToken')) {
        $zone = stobeNormalizeZoneLocationToken($normalized);
        if ($zone !== '') {
            $normalized = $zone;
        }
    }
    if (strlen($normalized) > $maxLen) {
        $normalized = substr($normalized, 0, $maxLen);
    }
    return trim($normalized);
}

function stobeFormatTravelCoordinateToken(float $value): string {
    $formatted = rtrim(rtrim(sprintf('%.3f', $value), '0'), '.');
    if ($formatted === '' || $formatted === '-0') {
        return '0';
    }
    return $formatted;
}

function stobeResolveTravelLocationFromVisitedZones(string $requestedLocation): array|false {
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return false;
    }

    $normalizedRequest = stobeSanitizeTravelLocationToken($requestedLocation);
    if ($normalizedRequest === '') {
        return false;
    }

    $candidates = [$normalizedRequest];
    if (strpos($normalizedRequest, ',') !== false) {
        $parts = preg_split('/\s*,\s*/', $normalizedRequest) ?: [];
        foreach ($parts as $part) {
            $candidate = stobeSanitizeTravelLocationToken(strval($part));
            if ($candidate === '' || in_array($candidate, $candidates, true)) {
                continue;
            }
            $candidates[] = $candidate;
        }
    }

    $match = false;
    $matchedCandidate = $normalizedRequest;
    foreach ($candidates as $candidate) {
        try {
            $match = $db->fetchOne(
                "SELECT zone_name, city_name, x, y, z
                 FROM location_zones
                 WHERE (LOWER(zone_name) = LOWER($1)
                    OR LOWER(city_name) = LOWER($1))
                   AND metadata->>'knowledge_only' IS DISTINCT FROM 'true'
                 ORDER BY
                    CASE
                        WHEN LOWER(zone_name) = LOWER($1) THEN 0
                        ELSE 1
                    END,
                    last_seen_ts DESC,
                    id DESC
                 LIMIT 1",
                [$candidate]
            );
        } catch (Throwable $exception) {
            $match = false;
        }
        if ($match) {
            $matchedCandidate = $candidate;
            break;
        }
    }

    if (!$match) {
        $likeNeedle = '%' . strtolower($normalizedRequest) . '%';
        try {
            $match = $db->fetchOne(
                "SELECT zone_name, city_name, x, y, z
                 FROM location_zones
                 WHERE (LOWER(zone_name) LIKE $1
                    OR LOWER(city_name) LIKE $1)
                   AND metadata->>'knowledge_only' IS DISTINCT FROM 'true'
                 ORDER BY
                    CASE
                        WHEN LOWER(zone_name) LIKE $1 THEN 0
                        ELSE 1
                    END,
                    last_seen_ts DESC,
                    id DESC
                 LIMIT 1",
                [$likeNeedle]
            );
        } catch (Throwable $exception) {
            $match = false;
        }
    }

    if (!$match) {
        return false;
    }

    if (!is_numeric($match['x'] ?? null) || !is_numeric($match['y'] ?? null) || !is_numeric($match['z'] ?? null)) {
        return false;
    }

    $zoneLabel = stobeSanitizeTravelLocationToken(strval($match['zone_name'] ?? ''));
    $cityLabel = stobeSanitizeTravelLocationToken(strval($match['city_name'] ?? ''));

    // If the request matched a city token, keep the city as display label.
    $label = $zoneLabel;
    if ($cityLabel !== '' && strcasecmp($cityLabel, $matchedCandidate) === 0) {
        $label = $cityLabel;
    } elseif ($label === '') {
        $label = $cityLabel;
    }
    if ($label === '') {
        $label = $normalizedRequest;
    }

    return [
        'x' => floatval($match['x']),
        'y' => floatval($match['y']),
        'z' => floatval($match['z']),
        'label' => stobeSanitizeTravelLocationToken($label),
    ];
}

function stobeBuildTravelLocationFailureAction(string $requestedLocation): string {
    $destination = stobeSanitizeTravelLocationToken($requestedLocation);
    if ($destination === '') {
        $destination = 'that location';
    }
    return 'ROLEPLAY_ACTION@Can not travel to ' . $destination . ' as you have not visited it yet';
}

// Keep identity when similarly named characters, objects, and landmarks share a scene.
function stobeMoveToReferences(array|false $npcData): array {
    $extended = normalizeNpcExtendedDataPayload($npcData['extended_data'] ?? []);
    $references = [];
    foreach (['nearby_actors', 'nearby_items', 'points_of_interest', 'nearby_use_objects'] as $section) {
        foreach (stobeExtractSceneArray($extended, $section) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = trim(strval($entry['name'] ?? ''));
            $ref = trim(strval($entry['refid'] ?? ($entry['serial'] ?? '')));
            if ($name === '' || !preg_match('/^(?:hand_)?([1-9][0-9]{0,9})$/D', $ref, $match)
                || (float)$match[1] > 4294967295) {
                continue;
            }
            $references[$match[1]] = ['name' => $name, 'serial' => $match[1]];
            if (count($references) >= 96) {
                return $references;
            }
        }
    }
    return $references;
}

// Resolve dialogue targets against game context; only visited destinations supply coordinates.
function stobeResolveMoveToDispatch(string $target, array|false $npcData): string {
    $fail = 'ROLEPLAY_ACTION@Could not identify that destination.';
    $target = trim($target);
    if (in_array(strtolower($target), ['player', 'me', 'you', 'the player'], true)) {
        $serial = trim(strval($_GET['initiator_sid'] ?? ''));
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $serial) || (float)$serial > 4294967295) {
            return $fail;
        }
        return 'MOVE_TO@ref;' . $serial . ';the speaker';
    }
    $matches = [];
    foreach (stobeMoveToReferences($npcData) as $reference) {
        if ($target === 'hand_' . $reference['serial'] || $target === strval($reference['serial'])
            || strcasecmp($target, $reference['name']) === 0) {
            $matches[] = $reference;
        }
    }
    if (count($matches) > 1) {
        return 'ROLEPLAY_ACTION@More than one destination has that name. Specify which one.';
    }
    if (count($matches) === 1) {
        $reference = $matches[0];
        return 'MOVE_TO@ref;' . $reference['serial'] . ';'
            . stobeSanitizeTravelLocationToken($reference['name']);
    }
    // A model-supplied reference or wire payload must not become a location lookup.
    if ($target === '' || preg_match('/^(?:hand_|ref;|point;)|[;@|]/i', $target)) {
        return $fail;
    }
    $db = $GLOBALS['db'] ?? null;
    $points = $db ? $db->fetchAll(
        "SELECT zone_name, city_name, x, y, z FROM location_zones
         WHERE (LOWER(zone_name) = LOWER($1) OR LOWER(city_name) = LOWER($1))
           AND metadata->>'knowledge_only' IS DISTINCT FROM 'true'
         LIMIT 2",
        [$target]
    ) : [];
    if (count($points) !== 1) {
        return $fail;
    }
    $point = $points[0];
    foreach (['x', 'y', 'z'] as $axis) {
        if (!is_numeric($point[$axis] ?? null) || !is_finite(floatval($point[$axis]))
            || abs(floatval($point[$axis])) >= 1000000) {
            return $fail;
        }
    }
    return 'MOVE_TO@point;' . stobeFormatTravelCoordinateToken(floatval($point['x'])) . ';'
        . stobeFormatTravelCoordinateToken(floatval($point['y'])) . ';'
        . stobeFormatTravelCoordinateToken(floatval($point['z'])) . ';'
        . stobeSanitizeTravelLocationToken($target);
}

function stobeTransformActionForDispatch(string $normalizedAction, array|false $npcData = false): string {
    $value = trim($normalizedAction);
    if ($value === '') {
        return '';
    }

    $atPos = strpos($value, '@');
    if ($atPos === false) {
        return $value;
    }

    $command = stobeCanonicalizeActionCommand(substr($value, 0, $atPos));
    $argument = trim(substr($value, $atPos + 1));
    if ($command === 'MOVE_TO') {
        return stobeResolveMoveToDispatch($argument, $npcData);
    }
    if ($command !== 'TRAVEL_LOCATION') {
        return $value;
    }

    if ($argument === '') {
        return stobeBuildTravelLocationFailureAction('that location');
    }

    $resolved = stobeResolveTravelLocationFromVisitedZones($argument);
    if (!$resolved) {
        return stobeBuildTravelLocationFailureAction($argument);
    }

    $xToken = stobeFormatTravelCoordinateToken(floatval($resolved['x'] ?? 0.0));
    $yToken = stobeFormatTravelCoordinateToken(floatval($resolved['y'] ?? 0.0));
    $zToken = stobeFormatTravelCoordinateToken(floatval($resolved['z'] ?? 0.0));
    $label = stobeSanitizeTravelLocationToken(strval($resolved['label'] ?? $argument));
    if ($label === '') {
        $label = stobeSanitizeTravelLocationToken($argument);
    }
    if ($label === '') {
        $label = 'destination';
    }

    // Use ';' separators because '|' is the wire protocol field delimiter.
    return 'TRAVEL_LOCATION@' . $xToken . ';' . $yToken . ';' . $zToken . ';' . $label;
}

function extractAndNormalizeActionTags(string $rawResponse, string $eventType, ?array $configOverride = null): array {
    $text = trim($rawResponse);
    $config = is_array($configOverride) ? $configOverride : getActionRuntimeConfig($eventType);

    $commandNames = [
        'ATTACK', 'STOP_ATTACK', 'FOLLOW', 'STOP_FOLLOW', 'JOIN_PARTY',
        'LEAVE', 'IDLE', 'STOP_CARRYING', 'PICKUP_NPC', 'RELEASE_PLAYER', 'RELEASE_PRISONER', 'SUICIDE',
        'GIVE_CATS', 'TAKE_CATS', 'TAKE_ITEM', 'GIVE_ITEM', 'DROP_ITEM', 'UNEQUIP_ITEM', 'EQUIP_ITEM', 'SHEATHE_WEAPON', 'DRAW_WEAPON', 'FACE_TARGET', 'FIRST_AID', 'BODYGUARD', 'PATROL', 'RESCUE', 'PUT_IN_BED', 'LOOT_TARGET', 'IMPRISON', 'RELEASE_PRISONER', 'DROP_WEAPON', 'SURRENDER', 'MOVE_TO_TARGET', 'HOLD_POSITION', 'REPAIR', 'BUILD', 'WORK_GOAL', 'TASK_GOAL', 'TASK_CONTROL', 'SIT', 'SLEEP', 'OPERATE_OBJECT', 'REMOVE_LIMB', 'KNOCKOUT', 'KILL', 'USE_OBJECT', 'USE_DRUGS', 'DRINK_ITEM', 'DRINK', 'FORCE_DRINK', 'TRAVEL_LOCATION', 'MOVE_TO', 'MOVETO',
        'ROLEPLAY_ACTION', 'NOTIFY', 'FACTION_RELATIONS', 'TASK', 'TALK',
        'SET_BLOCK', 'SET_HOLD', 'SET_PASSIVE', 'SET_JOBS', 'SET_RANGED',
        'SET_TAUNT', 'SET_SNEAK', 'SET_RESOURCE', 'SET_MEDIC',
        // Common alias forms emitted by models without underscores.
        'STOPATTACK', 'STOPFOLLOW', 'JOINPARTY', 'STOPCARRYING', 'DROPNPC', 'DROP_NPC', 'DROP-NPC', 'PUTDOWNNPC', 'PUT_DOWN_NPC', 'PUT-DOWN-NPC', 'RELEASENPC', 'RELEASE_NPC', 'RELEASE-NPC', 'PICKUPNPC', 'PICKUP-NPC', 'KIDNAP', 'RELEASEPLAYER', 'GIVECATS', 'TAKECATS',
        'TAKEITEM', 'GIVEITEM', 'DROPITEM', 'UNEQUIPITEM', 'UNEQUIP-ITEM', 'TAKEOFFITEM', 'TAKE_OFF_ITEM', 'TAKE-OFF-ITEM', 'WORKGOAL', 'WORK-GOAL', 'PRODUCEGOAL', 'PRODUCE_GOAL', 'CRAFTGOAL', 'CRAFT_GOAL', 'EQUIPITEM', 'EQUIP-ITEM', 'SHEATHEWEAPON', 'SHEATHE-WEAPON', 'HOLSTERWEAPON', 'DRAWWEAPON', 'DRAW-WEAPON', 'READYWEAPON', 'FACETARGET', 'FACE-TARGET', 'LOOKAT', 'LOOK_AT', 'FIRSTAID', 'FIRST-AID', 'HEALTARGET', 'HEAL_TARGET', 'GUARDTARGET', 'GUARD_TARGET', 'BODYGUARDTARGET', 'PUTINBED', 'PUT-IN-BED', 'LOOTTARGET', 'LOOT-TARGET', 'RELEASEPRISONER', 'RELEASE-PRISONER', 'DROPWEAPON', 'DROP-WEAPON', 'DISARM', 'DISARMSELF', 'DISARM_SELF', 'MOVETOTARGET', 'MOVE-TO-TARGET', 'GOTOACTOR', 'HOLDPOSITION', 'HOLD-POSITION', 'TAKEASEAT', 'TAKE_A_SEAT', 'GOTOSLEEP', 'GO_TO_SLEEP', 'OPERATEOBJECT', 'OPERATE-OBJECT', 'WORKOBJECT', 'REMOVELIMB', 'KO', 'KNOCK_OUT', 'KNOCK-OUT', 'KILLTARGET', 'EXECUTE', 'MURDER', 'USEOBJECT', 'USE-OBJECT', 'USEDRUGS', 'USE-DRUGS', 'DRINKITEM', 'DRINK-ITEM', 'FORCEDRINK', 'FORCE-DRINK', 'FACTIONRELATIONS', 'TRAVELLOCATION',
        'ROLEPLAYACTION', 'ROLEPLAY-ACTION',
        'SETBLOCK', 'SETHOLD', 'SETPASSIVE', 'SETJOBS', 'SETRANGED',
        'SETTAUNT', 'SETSNEAK', 'SETRESOURCE', 'SETMEDIC',
    ];
    $commandAlternation = implode('|', $commandNames);

    $commandPattern = '/(?:^|[\s>])((?:' . $commandAlternation . ')\s*@[^\r\n<]*)/im';

    $rawTags = [];
    $commandMatches = [];
    preg_match_all($commandPattern, $text, $commandMatches);
    if (is_array($commandMatches[1] ?? null)) {
        foreach ($commandMatches[1] as $match) {
            $candidate = trim(strval($match));
            if ($candidate !== '') {
                $rawTags[] = $candidate;
            }
        }
    }

    $cleanText = trim(preg_replace($commandPattern, ' ', $text) ?? $text);
    $cleanText = trim(preg_replace('/[ \t]{2,}/', ' ', $cleanText) ?? $cleanText);

    if (!boolval($config['enabled'] ?? false)) {
        return [
            'text' => $cleanText,
            'actions' => [],
            'raw_actions' => $rawTags,
            'actions_enabled' => false,
        ];
    }

    $normalized = [];
    $seen = [];
    $maxActions = intval($config['max_actions'] ?? 1);
    if ($maxActions < 1) {
        $maxActions = 1;
    }
    foreach ($rawTags as $rawTag) {
        if (count($normalized) >= $maxActions) {
            break;
        }
        $parsed = normalizeActionTagToken(strval($rawTag), $config);
        if ($parsed === '') {
            continue;
        }
        $key = strtolower($parsed);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $normalized[] = $parsed;
    }

    return [
        'text' => $cleanText,
        'actions' => $normalized,
        'raw_actions' => $rawTags,
        'actions_enabled' => true,
    ];
}

function parseNpcKnowledgeTags(array $npcData, string $npcName = ''): array {
    $rawTags = trim(strval($npcData['world_knowledge_tags'] ?? ''));
    if ($rawTags === '') {
        $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
        $rawTags = trim(strval($metadata['world_knowledge_tags'] ?? ''));
    }
    $tokens = [];
    if ($rawTags !== '') {
        $tokens = explode(',', strtolower($rawTags));
    }
    $normalized = [];
    foreach ($tokens as $token) {
        $tag = trim($token);
        if ($tag === '') {
            continue;
        }
        if (!in_array($tag, $normalized, true)) {
            $normalized[] = $tag;
        }
    }

    // Mirror Herika awareness behavior: NPC identity can satisfy class-matching
    // without implicitly granting full knowall access.
    $resolvedNpcName = normalizeParticipantNameToken($npcName);
    $npcTagCandidates = [
        strtolower(trim($resolvedNpcName)),
        strtolower(trim(baseNameWithoutBracketSuffix($resolvedNpcName))),
    ];
    foreach ($npcTagCandidates as $candidate) {
        if ($candidate === '') {
            continue;
        }
        if (!in_array($candidate, $normalized, true)) {
            $normalized[] = $candidate;
        }
    }

    return $normalized;
}

function stobeWorldKnowledgeHasQueryableTerms(string $value): bool {
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        return false;
    }

    $tokens = preg_split('/[^a-z0-9_]+/i', $normalized) ?: [];
    foreach ($tokens as $token) {
        $word = trim(strval($token));
        if (strlen($word) >= 3) {
            return true;
        }
    }
    return false;
}

function stobeWorldKnowledgeUniqueTerms(array $terms, int $max = 10): array {
    $unique = [];
    foreach ($terms as $term) {
        $clean = trim(strval($term));
        if ($clean === '') {
            continue;
        }
        $key = strtolower($clean);
        if (isset($unique[$key])) {
            continue;
        }
        $unique[$key] = $clean;
        if (count($unique) >= $max) {
            break;
        }
    }
    return array_values($unique);
}

function stobeWorldKnowledgeRaceInsertEnabled(): bool {
    return getSettingBool('ALWAYS_INSERT_RACE', true);
}

function stobeWorldKnowledgeLocationInsertEnabled(): bool {
    return getSettingBool('ALWAYS_INSERT_LOCATION', true);
}

function stobeWorldKnowledgePeopleInsertEnabled(): bool {
    return getSettingBool('ALWAYS_INSERT_PEOPLE', true);
}

function stobeWorldKnowledgeAddRaceSignal(array &$signals, mixed $rawRace): void {
    $race = stobeWorldKnowledgeNormalizeLookupLabel(strval($rawRace));
    if ($race === '') {
        return;
    }
    if (in_array($race, ['unknown', 'none', 'n/a', 'na'], true)) {
        return;
    }
    $signals[$race] = $race;
}

function stobeWorldKnowledgeCollectForcedRaceSignals(array $npcData, string $speakerName = ''): array {
    if (!stobeWorldKnowledgeRaceInsertEnabled()) {
        return [];
    }

    $signals = [];
    stobeWorldKnowledgeAddRaceSignal($signals, $npcData['race'] ?? '');

    $safeSpeakerName = normalizeParticipantNameToken($speakerName);
    if ($safeSpeakerName !== '' && function_exists('getNpcData')) {
        $speakerData = getNpcData($safeSpeakerName);
        if (is_array($speakerData) && count($speakerData) > 0) {
            stobeWorldKnowledgeAddRaceSignal($signals, $speakerData['race'] ?? '');
        }
    }

    $extendedData = normalizeNpcExtendedDataPayload($npcData['extended_data'] ?? []);
    $actors = stobeExtractSceneArray($extendedData, 'nearby_actors');
    if (count($actors) === 0) {
        $actors = stobeExtractSceneArray($extendedData, 'nearby');
    }

    foreach ($actors as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if (stobeParseFlexibleBool($entry['is_animal'] ?? null) === true) {
            continue;
        }
        $entry = stobeEnrichNearbyEntryFromNpcProfile($entry);
        stobeWorldKnowledgeAddRaceSignal($signals, $entry['race'] ?? '');
    }

    return array_values($signals);
}

function stobeWorldKnowledgeAddPersonSignal(array &$signals, mixed $rawName): void {
    $name = normalizeParticipantNameToken(strval($rawName));
    if ($name === '') {
        return;
    }

    foreach ([$name, baseNameWithoutBracketSuffix($name)] as $candidate) {
        $label = stobeWorldKnowledgeNormalizeLookupLabel($candidate);
        $key = stobeWorldKnowledgeComparableLabel($candidate);
        if ($label === '' || $key === '' || isset($signals[$key])) {
            continue;
        }
        $signals[$key] = $label;
    }
}

function stobeWorldKnowledgeNpcDataIsAnimal(array $npcData): bool {
    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    return stobeParseFlexibleBool($npcData['is_animal'] ?? ($metadata['is_animal'] ?? null)) === true;
}

function stobeWorldKnowledgeCollectForcedPeopleSignals(
    string $npcName,
    array $npcData,
    string $speakerName = ''
): array {
    if (!stobeWorldKnowledgePeopleInsertEnabled()) {
        return [];
    }

    $signals = [];
    if (!stobeWorldKnowledgeNpcDataIsAnimal($npcData)) {
        stobeWorldKnowledgeAddPersonSignal($signals, $npcName);
    }

    $safeSpeakerName = normalizeParticipantNameToken($speakerName);
    if ($safeSpeakerName !== '') {
        $speakerData = function_exists('getNpcData') ? getNpcData($safeSpeakerName) : false;
        if (!is_array($speakerData) || count($speakerData) === 0 || !stobeWorldKnowledgeNpcDataIsAnimal($speakerData)) {
            stobeWorldKnowledgeAddPersonSignal($signals, $safeSpeakerName);
        }
    }

    if (stobePromptContextOptionEnabled('enabled_sections', 'nearby_actors')) {
        $extendedData = normalizeNpcExtendedDataPayload($npcData['extended_data'] ?? []);
        $actors = stobeExtractSceneArray($extendedData, 'nearby_actors');
        if (count($actors) === 0) {
            $actors = stobeExtractSceneArray($extendedData, 'nearby');
        }
        if (count($actors) === 0) {
            $peopleRaw = trim(strval($GLOBALS['CACHE_PEOPLE'] ?? ($_GET['people'] ?? '')));
            $actors = stobeBuildNearbyActorsFromPeopleScope($peopleRaw);
        }

        $added = 0;
        $seenActors = [];
        $targetKey = strtolower(normalizeParticipantNameToken($npcName));
        foreach ($actors as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entry = stobeEnrichNearbyEntryFromNpcProfile($entry);
            if (stobeParseFlexibleBool($entry['is_animal'] ?? null) === true) {
                continue;
            }
            $actorName = normalizeParticipantNameToken(strval($entry['name'] ?? ''));
            $actorKey = strtolower($actorName);
            if ($actorName === '' || $actorKey === $targetKey || isset($seenActors[$actorKey])) {
                continue;
            }
            $seenActors[$actorKey] = true;
            stobeWorldKnowledgeAddPersonSignal($signals, $actorName);
            $added++;
            if ($added >= 32) {
                break;
            }
        }
    }

    return array_values($signals);
}

function stobeWorldKnowledgeAddLocationSignal(array &$signals, mixed $rawLocation): void {
    $location = stobeNormalizeWorldPromptToken($rawLocation);
    if ($location === '') {
        return;
    }

    foreach (array_merge([$location], preg_split('/\s*,\s*/u', $location) ?: []) as $candidate) {
        $label = stobeWorldKnowledgeNormalizeLookupLabel(strval($candidate));
        $key = stobeWorldKnowledgeComparableLabel($candidate);
        if ($label === '' || $key === '' || isset($signals[$key])) {
            continue;
        }
        $signals[$key] = $label;
    }
}

function stobeWorldKnowledgeCollectForcedLocationSignals(array $npcData): array {
    if (!stobeWorldKnowledgeLocationInsertEnabled()) {
        return [];
    }

    $signals = [];
    if (stobePromptContextOptionEnabled('enabled_sections', 'world')) {
        $world = stobeResolveWorldPromptContext($npcData);
        stobeWorldKnowledgeAddLocationSignal($signals, $world['location'] ?? '');
    }

    if (stobePromptContextOptionEnabled('enabled_sections', 'points_of_interest')) {
        $extendedData = normalizeNpcExtendedDataPayload($npcData['extended_data'] ?? []);
        $points = stobeExtractSceneArray($extendedData, 'points_of_interest');
        $added = 0;
        foreach ($points as $entry) {
            $beforeCount = count($signals);
            if (is_string($entry)) {
                stobeWorldKnowledgeAddLocationSignal($signals, $entry);
            } elseif (is_array($entry)) {
                stobeWorldKnowledgeAddLocationSignal($signals, $entry['name'] ?? ($entry['location'] ?? ''));
            }
            if (count($signals) === $beforeCount) {
                continue;
            }
            $added++;
            if ($added >= 24) {
                break;
            }
        }
    }

    return array_values($signals);
}

function stobeWorldKnowledgeLookupTopicRowByLabel(string $label): ?array {
    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        return null;
    }

    $comparable = stobeWorldKnowledgeComparableLabel($label);
    if ($comparable === '') {
        return null;
    }

    try {
        $rows = $db->fetchAll(
            "SELECT
                id,
                topic,
                topic_desc,
                COALESCE(topic_desc_basic, '') AS topic_desc_basic,
                COALESCE(knowledge_class, '') AS knowledge_class,
                COALESCE(knowledge_class_basic, '') AS knowledge_class_basic,
                COALESCE(tags, '') AS tags,
                regexp_replace(lower(COALESCE(topic, '')), '[^a-z0-9]+', '', 'g') = $1 AS canonical_match
             FROM world_knowledge
             WHERE regexp_replace(lower(COALESCE(topic, '')), '[^a-z0-9]+', '', 'g') = $1
                OR EXISTS (
                    SELECT 1
                    FROM regexp_split_to_table(COALESCE(aliases, ''), ',') AS alias_value(alias_token)
                    WHERE regexp_replace(lower(BTRIM(alias_token)), '[^a-z0-9]+', '', 'g') = $1
                )
             ORDER BY canonical_match DESC, id DESC",
            [$comparable]
        );
    } catch (Throwable $exception) {
        return null;
    }

    if (!is_array($rows) || count($rows) === 0) {
        return null;
    }
    $canonicalMatch = strtolower(trim(strval($rows[0]['canonical_match'] ?? '')));
    if (in_array($canonicalMatch, ['1', 't', 'true', 'yes', 'on'], true)) {
        return $rows[0];
    }
    return count($rows) === 1 ? $rows[0] : null;
}

function stobeWorldKnowledgeRowHasAnyTag(array $row, array $requiredTags): bool {
    if (count($requiredTags) === 0) {
        return true;
    }

    $rowTags = preg_split('/\s*,\s*/u', strtolower(trim(strval($row['tags'] ?? '')))) ?: [];
    foreach ($requiredTags as $requiredTag) {
        if (in_array(strtolower(trim(strval($requiredTag))), $rowTags, true)) {
            return true;
        }
    }
    return false;
}

function stobeWorldKnowledgeBuildHintLine(string $topic, string $description): string
{
    $safeTopic = trim($topic);
    $safeDescription = trim(preg_replace('/\s+/u', ' ', $description) ?? $description);
    if ($safeTopic === '' || $safeDescription === '') {
        return '';
    }

    $worldStateAddenda = stobeWorldStatePromptAddendaForTopic($safeTopic, 4);
    if (count($worldStateAddenda) > 0) {
        $safeDescription .= ' ' . implode(' ', $worldStateAddenda);
    }

    return $safeTopic . ': ' . trim(preg_replace('/\s+/u', ' ', $safeDescription) ?? $safeDescription);
}

/**
 * Adds prompt hints while suppressing identical lore carried by different topics.
 */
function stobeWorldKnowledgeAppendUniqueHints(array &$target, array $hints, array &$seenPayloads): void
{
    foreach ($hints as $hint) {
        $line = trim(strval($hint));
        if ($line === '') {
            continue;
        }

        $separator = strpos($line, ': ');
        $description = $separator === false ? $line : substr($line, $separator + 2);
        $normalized = trim(preg_replace('/\s+/u', ' ', $description) ?? $description);
        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower($normalized, 'UTF-8')
            : strtolower($normalized);
        $fingerprint = $normalized === '' ? '' : hash('sha256', $normalized);
        if ($fingerprint !== '' && isset($seenPayloads[$fingerprint])) {
            continue;
        }
        if ($fingerprint !== '') {
            $seenPayloads[$fingerprint] = true;
        }
        $target[] = $line;
    }
}

function stobeWorldKnowledgeResolveForcedSignalHints(
    string $npcName,
    array $npcData,
    array $signals,
    array $requiredTags = [],
    string $eventType = 'chat'
): array {
    if (!stobeWorldKnowledgeRetrieverEnabled()) {
        return [];
    }

    $normalizedEventType = strtolower(trim($eventType));
    if ($normalizedEventType === '') {
        $normalizedEventType = 'chat';
    }
    if (!stobeWorldKnowledgeEventAllowed($normalizedEventType)) {
        return [];
    }

    if (!is_array($npcData) || count($npcData) === 0) {
        $npcData = getNpcData($npcName);
    }
    if (!is_array($npcData) || count($npcData) === 0) {
        return [];
    }

    if (count($signals) === 0) {
        return [];
    }

    $knowledgeTags = parseNpcKnowledgeTags($npcData, $npcName);
    $isKnowAll = in_array('knowall', $knowledgeTags, true);
    $hints = [];
    $seenTopics = [];
    $seenPayloads = [];

    foreach ($signals as $signal) {
        $row = stobeWorldKnowledgeLookupTopicRowByLabel(strval($signal));
        if (!is_array($row) || count($row) === 0) {
            continue;
        }
        if (!stobeWorldKnowledgeRowHasAnyTag($row, $requiredTags)) {
            continue;
        }

        $payload = stobeWorldKnowledgeSelectKnowledgePayload($row, $knowledgeTags, $isKnowAll);
        if (!boolval($payload['allowed'] ?? false)) {
            continue;
        }

        $topic = trim(strval($payload['topic'] ?? ''));
        $line = stobeWorldKnowledgeBuildHintLine($topic, strval($payload['desc'] ?? ''));
        if ($topic === '' || $line === '') {
            continue;
        }

        $topicKey = stobeWorldKnowledgeNormalizeLookupLabel($topic);
        if ($topicKey === '' || isset($seenTopics[$topicKey])) {
            continue;
        }
        $seenTopics[$topicKey] = true;
        stobeWorldKnowledgeAppendUniqueHints($hints, [$line], $seenPayloads);
    }

    return $hints;
}

function stobeWorldKnowledgeResolveForcedRaceHints(
    string $npcName,
    array $npcData,
    string $speakerName = '',
    string $eventType = 'chat'
): array {
    if (count($npcData) === 0 && function_exists('getNpcData')) {
        $resolvedNpcData = getNpcData($npcName);
        if (is_array($resolvedNpcData)) {
            $npcData = $resolvedNpcData;
        }
    }
    return stobeWorldKnowledgeResolveForcedSignalHints(
        $npcName,
        $npcData,
        stobeWorldKnowledgeCollectForcedRaceSignals($npcData, $speakerName),
        [],
        $eventType
    );
}

function stobeWorldKnowledgeResolveForcedPeopleHints(
    string $npcName,
    array $npcData,
    string $speakerName = '',
    string $eventType = 'chat'
): array {
    if (count($npcData) === 0 && function_exists('getNpcData')) {
        $resolvedNpcData = getNpcData($npcName);
        if (is_array($resolvedNpcData)) {
            $npcData = $resolvedNpcData;
        }
    }
    return stobeWorldKnowledgeResolveForcedSignalHints(
        $npcName,
        $npcData,
        stobeWorldKnowledgeCollectForcedPeopleSignals($npcName, $npcData, $speakerName),
        ['Characters'],
        $eventType
    );
}

function stobeWorldKnowledgeResolveForcedLocationHints(
    string $npcName,
    array $npcData,
    string $eventType = 'chat'
): array {
    if (count($npcData) === 0 && function_exists('getNpcData')) {
        $resolvedNpcData = getNpcData($npcName);
        if (is_array($resolvedNpcData)) {
            $npcData = $resolvedNpcData;
        }
    }
    return stobeWorldKnowledgeResolveForcedSignalHints(
        $npcName,
        $npcData,
        stobeWorldKnowledgeCollectForcedLocationSignals($npcData),
        ['Locations', 'Zones', 'Buildings'],
        $eventType
    );
}

function stobeWorldKnowledgeBuildNpcKey(string $npcName, array $npcData): string {
    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    $storageId = normalizeStorageIdToken(strval($metadata['storage_id'] ?? ''));
    if ($storageId !== '') {
        return strtolower($storageId);
    }
    $normalizedName = normalizeParticipantNameToken($npcName);
    if ($normalizedName !== '') {
        return strtolower($normalizedName);
    }
    return strtolower(trim($npcName));
}

function stobeWorldKnowledgeBuildCurrentTopicConfId(string $npcKey): string {
    $normalized = strtolower(trim($npcKey));
    $normalized = preg_replace('/[^a-z0-9_:-]+/i', '_', $normalized) ?? '';
    $normalized = trim($normalized, '_:');
    if ($normalized === '') {
        $normalized = 'global';
    }
    if (strlen($normalized) > 96) {
        $normalized = substr($normalized, 0, 96);
    }
    return 'current_world_knowledge_topic:' . $normalized;
}

function stobeWorldKnowledgeGetCurrentTopic(string $npcKey): string {
    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        return '';
    }

    $confId = stobeWorldKnowledgeBuildCurrentTopicConfId($npcKey);
    try {
        $row = $db->fetchOne(
            "SELECT value
             FROM conf_opts
             WHERE id = $1
             LIMIT 1",
            [$confId]
        );
    } catch (Throwable $exception) {
        return '';
    }

    return trim(strval($row['value'] ?? ''));
}

function stobeWorldKnowledgeSetCurrentTopic(string $npcKey, string $npcName, string $topic, string $eventType = '', int $gamets = 0): void {
    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        return;
    }

    $normalizedTopic = trim($topic);
    if ($normalizedTopic === '') {
        return;
    }

    $confId = stobeWorldKnowledgeBuildCurrentTopicConfId($npcKey);
    try {
        $db->exec(
            "INSERT INTO conf_opts (id, value, updated_at)
             VALUES ($1, $2, NOW())
             ON CONFLICT (id) DO UPDATE
             SET value = EXCLUDED.value,
                 updated_at = NOW()",
            [$confId, $normalizedTopic]
        );
    } catch (Throwable $exception) {
        return;
    }
}

function stobeWorldKnowledgeEventAllowed(string $eventType): bool {
    $normalized = strtolower(trim($eventType));
    if ($normalized === '') {
        $normalized = 'chat';
    }
    return in_array(
        $normalized,
        ['chat', 'rechat', 'continue', 'instruction', 'suggestion', 'inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s'],
        true
    );
}

function stobeWorldKnowledgeRetrieverEnabled(): bool {
    return getSettingBool('WORLD_KNOWLEDGE_ENABLED', true);
}

function stobeWorldKnowledgeSplitKnowledgeClassSpec(string $rawSpec): array {
    $positive = [];
    $negative = [];
    $tokens = explode(',', strtolower(trim($rawSpec)));
    foreach ($tokens as $token) {
        $value = trim($token);
        if ($value === '') {
            continue;
        }
        if (str_starts_with($value, '!')) {
            $tag = trim(substr($value, 1));
            if ($tag !== '' && !in_array($tag, $negative, true)) {
                $negative[] = $tag;
            }
            continue;
        }
        if (!in_array($value, $positive, true)) {
            $positive[] = $value;
        }
    }
    return [
        'positive' => $positive,
        'negative' => $negative,
    ];
}

function stobeWorldKnowledgeIsKnowledgeAllowed(string $requiredClasses, array $npcKnowledgeTags, bool $isKnowAll): bool {
    if ($isKnowAll) {
        return true;
    }

    $spec = stobeWorldKnowledgeSplitKnowledgeClassSpec($requiredClasses);
    $positive = $spec['positive'];
    $negative = $spec['negative'];

    if (count($negative) > 0) {
        foreach ($negative as $blockedTag) {
            if (in_array($blockedTag, $npcKnowledgeTags, true)) {
                return false;
            }
        }
    }

    if (count($positive) === 0) {
        return true;
    }
    foreach ($positive as $requiredTag) {
        if (in_array($requiredTag, $npcKnowledgeTags, true)) {
            return true;
        }
    }
    return false;
}

function stobeWorldKnowledgeSelectKnowledgePayload(array $row, array $npcKnowledgeTags, bool $isKnowAll): array {
    $topic = trim(strval($row['topic'] ?? ''));

    $advancedDesc = trim(strval($row['topic_desc'] ?? ''));
    $advancedClass = trim(strval($row['knowledge_class'] ?? ''));
    if ($advancedDesc !== '' && stobeWorldKnowledgeIsKnowledgeAllowed($advancedClass, $npcKnowledgeTags, $isKnowAll)) {
        return [
            'allowed' => true,
            'mode' => 'advanced',
            'topic' => $topic,
            'desc' => $advancedDesc,
            'class_used' => $advancedClass,
        ];
    }

    $basicDesc = trim(strval($row['topic_desc_basic'] ?? ''));
    $basicClass = trim(strval($row['knowledge_class_basic'] ?? ''));
    if ($basicDesc !== '' && stobeWorldKnowledgeIsKnowledgeAllowed($basicClass, $npcKnowledgeTags, $isKnowAll)) {
        return [
            'allowed' => true,
            'mode' => 'basic',
            'topic' => $topic,
            'desc' => $basicDesc,
            'class_used' => $basicClass,
        ];
    }

    return [
        'allowed' => false,
        'mode' => 'blocked',
        'topic' => $topic,
        'desc' => '',
        'class_used' => '',
    ];
}

function stobeWorldKnowledgeResolveLocationContext(string $npcName): string {
    $parts = [];
    $seen = [];
    $push = static function (string $value) use (&$parts, &$seen): void {
        $token = trim($value);
        if ($token === '') {
            return;
        }
        $key = strtolower($token);
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $parts[] = $token;
    };

    $npcGeo = getEventGeoFromNpcName($npcName);
    foreach (['location', 'city', 'region'] as $field) {
        $push(strval($npcGeo[$field] ?? ''));
    }

    if (count($parts) === 0) {
        $playerGeo = getEventGeoFromPlayerSnapshot();
        foreach (['location', 'city', 'region'] as $field) {
            $push(strval($playerGeo[$field] ?? ''));
        }
    }

    return implode(' ', $parts);
}

function stobeWorldKnowledgeExtractContextKeywords(string $npcName, int $historyRows = 16, int $maxKeywords = 8): string {
    $rows = DataEventLog(max(6, min(60, $historyRows)), $npcName);
    $rows = stobeFilterNarratorRowsForContext($rows, $npcName, 'world_knowledge');
    if (count($rows) === 0) {
        return '';
    }

    $stopwords = [
        'the', 'and', 'for', 'with', 'that', 'this', 'from', 'have', 'just',
        'about', 'into', 'your', 'youre', 'they', 'them', 'then', 'than',
        'will', 'would', 'could', 'should', 'there', 'their', 'talking',
        'response', 'rechat', 'chat', 'inputtext', 'bored', 'pending',
        'speaker', 'target', 'line', 'said', 'says', 'what', 'when', 'where',
        'been', 'were', 'while', 'keep', 'make', 'made', 'very', 'more',
        'some', 'only', 'same', 'over', 'under', 'does', 'dont', 'cant',
        'im', 'ive', 'its', 'our', 'out', 'off', 'not', 'all',
    ];
    $stopLookup = array_fill_keys($stopwords, true);

    $weights = [];
    foreach ($rows as $row) {
        $line = trim(strval($row['data'] ?? ''));
        if ($line === '') {
            continue;
        }
        $line = preg_replace('/\(talking to:\s*[^\)]+\)/i', ' ', $line) ?? $line;
        $line = preg_replace('/^\s*[^:]+:\s*/', '', $line) ?? $line;
        $line = strtolower($line);
        $tokens = preg_split('/[^a-z0-9_]+/i', $line) ?: [];
        foreach ($tokens as $token) {
            $word = trim($token);
            if (strlen($word) < 4) {
                continue;
            }
            if (isset($stopLookup[$word])) {
                continue;
            }
            if (preg_match('/^[0-9]+$/', $word) === 1) {
                continue;
            }
            if (!isset($weights[$word])) {
                $weights[$word] = 0;
            }
            $weights[$word] += 1;
        }
    }

    if (count($weights) === 0) {
        return '';
    }

    arsort($weights);
    $keywords = array_slice(array_keys($weights), 0, max(1, min(20, $maxKeywords)));
    return implode(' ', $keywords);
}

function stobeWorldKnowledgeExtractTopicsHeuristic(string $message, string $contextKeywords = '', int $maxTopics = 2): array {
    $safeMaxTopics = max(1, min(5, $maxTopics));
    $topics = [];

    $normalizedMessage = trim($message);
    if ($normalizedMessage !== '') {
        $topics[] = truncatePromptValue($normalizedMessage, 140);
    }

    $text = strtolower($normalizedMessage . ' ' . $contextKeywords);
    $tokens = preg_split('/[^a-z0-9_]+/i', $text) ?: [];
    $freq = [];
    $stopwords = [
        'the', 'and', 'for', 'with', 'that', 'this', 'from', 'have', 'just',
        'about', 'into', 'your', 'youre', 'they', 'them', 'then', 'than',
        'will', 'would', 'could', 'should', 'there', 'their', 'talking',
        'response', 'rechat', 'chat', 'inputtext', 'bored', 'speaker', 'target',
        'line', 'said', 'says', 'what', 'when', 'where', 'been', 'were', 'while',
        'keep', 'make', 'made', 'very', 'more', 'some', 'only', 'same', 'over',
        'under', 'does', 'dont', 'cant', 'im', 'ive', 'its', 'our', 'out', 'off',
        'not', 'all',
    ];
    $stopLookup = array_fill_keys($stopwords, true);

    foreach ($tokens as $token) {
        $word = trim($token);
        if (strlen($word) < 4) {
            continue;
        }
        if (isset($stopLookup[$word])) {
            continue;
        }
        if (preg_match('/^[0-9]+$/', $word) === 1) {
            continue;
        }
        if (!isset($freq[$word])) {
            $freq[$word] = 0;
        }
        $freq[$word] += 1;
    }

    if (count($freq) > 0) {
        arsort($freq);
        foreach (array_keys($freq) as $word) {
            $topics[] = $word;
            if (count($topics) >= ($safeMaxTopics * 3)) {
                break;
            }
        }
    }

    $topics = stobeWorldKnowledgeUniqueTerms($topics, $safeMaxTopics);
    if (count($topics) === 0 && trim($message) !== '') {
        $topics[] = truncatePromptValue($message, 80);
    }
    return $topics;
}

function stobeWorldKnowledgeMinimeServiceAvailable(): bool
{
    static $available = null;
    if ($available !== null) {
        return boolval($available);
    }

    $serviceSocket = stobeMiniMeServiceSocket();
    $socket = @fsockopen($serviceSocket['socket_host'], $serviceSocket['port'], $errno, $errstr, 0.1);
    if ($socket) {
        fclose($socket);
        $available = true;
    } else {
        $available = false;
        stobeLogWarn('World knowledge Minime service unavailable; using heuristic fallback', [
            'host' => $serviceSocket['host'],
            'port' => $serviceSocket['port'],
        ]);
    }

    return boolval($available);
}

function stobeWorldKnowledgeMinimeTopicRequest(string $text): ?string
{
    $payload = trim($text);
    if ($payload === '') {
        return null;
    }
    if (!stobeWorldKnowledgeMinimeServiceAvailable()) {
        return null;
    }

    $timeout = intval($GLOBALS['HTTP_TIMEOUT'] ?? 15);
    if ($timeout < 2) {
        $timeout = 2;
    } elseif ($timeout > 20) {
        $timeout = 20;
    }

    $url = stobeMiniMeServiceEndpoint('topic', ['text' => $payload]);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    return $raw;
}

function stobeWorldKnowledgeNormalizeTopicForDedup(string $topic): string
{
    $normalized = strtolower(trim($topic));
    if ($normalized === '') {
        return '';
    }
    $normalized = str_replace('_', ' ', $normalized);
    $normalized = preg_replace('/[^a-z0-9\s]+/i', ' ', $normalized) ?? $normalized;
    $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    return trim($normalized);
}

function stobeWorldKnowledgeExtractTopicsMinime(string $message, int $maxTopics = 2): array
{
    $safeMaxTopics = max(1, min(5, $maxTopics));
    $remainingText = trim($message);
    if ($remainingText === '') {
        return [];
    }

    $topics = [];
    $seen = [];
    for ($i = 0; $i < $safeMaxTopics; $i++) {
        $topicPayload = stobeWorldKnowledgeMinimeTopicRequest($remainingText);
        if ($topicPayload === null) {
            break;
        }

        $decoded = json_decode($topicPayload, true);
        if (!is_array($decoded)) {
            break;
        }

        $generated = $decoded['generated_tags'] ?? '';
        $candidates = [];
        if (is_array($generated)) {
            foreach ($generated as $item) {
                if (!is_scalar($item) || $item === null) {
                    continue;
                }
                $candidates[] = strval($item);
            }
        } else {
            $candidates = preg_split('/[,;\r\n]+/', strval($generated)) ?: [];
        }

        $selected = '';
        foreach ($candidates as $candidate) {
            $topic = trim(strval($candidate));
            if ($topic === '') {
                continue;
            }
            $dedupe = stobeWorldKnowledgeNormalizeTopicForDedup($topic);
            if ($dedupe === '' || isset($seen[$dedupe])) {
                continue;
            }
            $selected = $topic;
            $seen[$dedupe] = true;
            break;
        }

        if ($selected === '') {
            break;
        }

        $topics[] = $selected;
        $remainingText = preg_replace('/\b' . preg_quote($selected, '/') . '\b/iu', ' ', $remainingText) ?? $remainingText;
        $remainingText = trim(preg_replace('/\s+/', ' ', $remainingText) ?? $remainingText);
        if ($remainingText === '') {
            break;
        }
    }

    return stobeWorldKnowledgeUniqueTerms($topics, $safeMaxTopics);
}

function stobeWorldKnowledgeExtractTopics(string $message, string $contextKeywords = '', int $maxTopics = 2): array
{
    $topics = stobeWorldKnowledgeExtractTopicsMinime($message, $maxTopics);
    if (count($topics) > 0) {
        return $topics;
    }
    return stobeWorldKnowledgeExtractTopicsHeuristic($message, $contextKeywords, $maxTopics);
}

function stobeWorldKnowledgeWriteAuditRow(array $payload): void {
    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        return;
    }

    $input = truncatePromptValue(strval($payload['input_text'] ?? ''), 3000);
    $selectedTopic = trim(strval($payload['selected_topic'] ?? ''));
    $selectedMode = trim(strval($payload['selected_mode'] ?? ''));
    $selectedEntryId = intval($payload['selected_entry_id'] ?? 0);
    $locationContext = truncatePromptValue(strval($payload['location_context'] ?? ''), 400);
    $contextKeywords = truncatePromptValue(strval($payload['context_keywords'] ?? ''), 400);
    $currentTopicBefore = truncatePromptValue(strval($payload['current_topic_before'] ?? ''), 180);
    $currentTopicAfter = truncatePromptValue(strval($payload['current_topic_after'] ?? ''), 180);
    $knowledgeTags = truncatePromptValue(strval($payload['knowledge_tags'] ?? ''), 240);
    $notes = truncatePromptValue(strval($payload['notes'] ?? ''), 800);
    $rank = floatval($payload['selected_rank'] ?? 0.0);

    $topicText = '';
    $rawTopics = $payload['extracted_topics'] ?? [];
    if (is_array($rawTopics)) {
        $topicParts = [];
        foreach ($rawTopics as $topic) {
            $clean = trim(strval($topic));
            if ($clean === '') {
                continue;
            }
            $topicParts[] = $clean;
            if (count($topicParts) >= 10) {
                break;
            }
        }
        $topicText = implode(', ', $topicParts);
    } elseif (!is_bool($rawTopics) && $rawTopics !== null) {
        $topicText = trim(strval($rawTopics));
    }
    $topicText = truncatePromptValue($topicText, 800);

    $keywordParts = [];
    if ($topicText !== '') {
        $keywordParts[] = 'topics=' . $topicText;
    }
    if ($notes !== '') {
        $keywordParts[] = 'notes=' . $notes;
    }
    $keywords = trim(implode(' | ', $keywordParts));
    if ($keywords === '') {
        $keywords = 'world_knowledge';
    }

    $memoryParts = [];
    if ($selectedTopic !== '') {
        $memoryParts[] = 'selected=' . $selectedTopic;
    }
    if ($selectedMode !== '') {
        $memoryParts[] = 'mode=' . $selectedMode;
    }
    if ($selectedEntryId > 0) {
        $memoryParts[] = 'entry_id=' . strval($selectedEntryId);
    }
    if ($locationContext !== '') {
        $memoryParts[] = 'location=' . $locationContext;
    }
    if ($contextKeywords !== '') {
        $memoryParts[] = 'context=' . $contextKeywords;
    }
    if ($currentTopicBefore !== '') {
        $memoryParts[] = 'before=' . $currentTopicBefore;
    }
    if ($currentTopicAfter !== '') {
        $memoryParts[] = 'after=' . $currentTopicAfter;
    }
    if ($knowledgeTags !== '') {
        $memoryParts[] = 'tags=' . $knowledgeTags;
    }
    $memory = truncatePromptValue(implode(' / ', $memoryParts), 8000);

    $elapsedSeconds = floatval($payload['elapsed_seconds'] ?? 0.0);
    if ($elapsedSeconds < 0.0) {
        $elapsedSeconds = 0.0;
    }
    $elapsedText = number_format($elapsedSeconds, 4, '.', '') . ' secs';

    try {
        $result = $db->exec(
            'INSERT INTO audit_memory (input, keywords, rank_any, rank_all, memory, "time")
             VALUES ($1, $2, $3, $4, $5, $6)',
            [$input, $keywords, $rank, $rank, $memory, $elapsedText]
        );
        if ($result === false) {
            $error = '';
            if (is_object($db) && method_exists($db, 'GetLastError')) {
                $error = strval($db->GetLastError());
            }
            stobeLogWarn('Failed to persist audit_memory row', [
                'error' => $error,
            ]);
        }
    } catch (Throwable $exception) {
        stobeLogWarn('Failed to persist audit_memory row', [
            'error' => $exception->getMessage(),
        ]);
    }
}

function truncatePromptValue(string $value, int $maxLength = 220): string {
    $clean = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($clean === '') {
        return '';
    }
    if (mb_strlen($clean, 'UTF-8') <= $maxLength) {
        return $clean;
    }
    return rtrim(mb_substr($clean, 0, $maxLength - 3, 'UTF-8')) . '...';
}

function stobePromptXmlEscape(mixed $value): string {
    $text = strval($value);
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    // Keep apostrophes readable in text nodes while still escaping XML control chars.
    return htmlspecialchars($text, ENT_COMPAT | ENT_XML1, 'UTF-8');
}

function stobeBuildLatestDiaryContextBlock(string $npcName, array $npcData): string
{
    $safeNpcName = normalizeParticipantNameToken($npcName);
    if ($safeNpcName === '') {
        return '';
    }

    $profileMetadata = getCoreProfileMetadataForNpc($npcData);
    if (!coerceBoolean($profileMetadata['LATEST_DIARY_CONTEXT_ENABLED'] ?? false)) {
        return '';
    }

    try {
        $entry = $GLOBALS['db']->fetchOne(
            "SELECT topic, content
             FROM diarylog
             WHERE lower(trim(people)) = lower($1)
             ORDER BY gamets DESC, localts DESC, rowid DESC
             LIMIT 1",
            [$safeNpcName]
        );
    } catch (Throwable $e) {
        stobeLogWarn('Unable to load latest diary context', [
            'npc_name' => $safeNpcName,
            'error' => $e->getMessage(),
        ]);
        return '';
    }

    $content = trim(strval($entry['content'] ?? ''));
    if ($content === '') {
        return '';
    }

    $topic = trim(strval($entry['topic'] ?? ''));
    $diaryText = $topic !== '' ? "Date: {$topic}\n{$content}" : $content;
    return "<latest_diary_entry>\n"
        . stobePromptXmlEscape($diaryText)
        . "\n</latest_diary_entry>";
}

function stobeBuildPlayerInputPromptContent(string $speaker, string $targetNpc, string $message): string
{
    $safeSpeaker = trim($speaker);
    $safeTarget = trim($targetNpc);
    $safeMessage = trim($message);

    if ($safeSpeaker === '') {
        return ($safeTarget !== '' && $safeMessage !== '')
            ? $safeMessage . ' (talking to: ' . $safeTarget . ')'
            : $safeMessage;
    }

    $content = $safeSpeaker . ': ' . $safeMessage;
    if ($safeTarget !== '') {
        $content .= ' (talking to: ' . $safeTarget . ')';
    }

    return trim($content);
}

function stobeBuildRechatPromptContent(string $previousSpeaker, string $previousTarget, string $previousMessage): string
{
    $safeMessage = trim($previousMessage);
    if ($safeMessage === '') {
        return 'Continue the conversation naturally.';
    }

    return stobeBuildPlayerInputPromptContent($previousSpeaker, $previousTarget, $safeMessage);
}

function stobeNormalizeWorldEnvironmentPayload(mixed $rawEnvironment): array {
    if (is_array($rawEnvironment)) {
        return $rawEnvironment;
    }
    if (is_string($rawEnvironment)) {
        $trimmed = trim($rawEnvironment);
        if ($trimmed !== '') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }
    return [];
}

function stobeNormalizeWorldPromptToken(mixed $value): string {
    if (is_array($value) || is_object($value) || $value === null) {
        return '';
    }

    $text = trim(strval($value));
    if ($text === '') {
        return '';
    }

    $lower = strtolower($text);
    if (in_array($lower, ['unknown', 'none', 'n/a', 'null', 'unset'], true)) {
        return '';
    }

    return preg_replace('/\s+/u', ' ', $text) ?? $text;
}

function stobeNormalizeWorldFloorToken(mixed $value): string {
    if (is_int($value) || is_float($value)) {
        $normalized = intval(round(floatval($value)));
        if ($normalized === 0) {
            return 'ground floor';
        }
        return strval($normalized);
    }
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        if (preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $trimmed) === 1) {
            $normalized = intval(round(floatval($trimmed)));
            if ($normalized === 0) {
                return 'ground floor';
            }
            return strval($normalized);
        }
        $token = stobeNormalizeWorldPromptToken($trimmed);
        if ($token === '') {
            return '';
        }
        $lowerToken = strtolower($token);
        if (in_array($lowerToken, ['ground', 'ground floor', 'outdoors'], true)) {
            return 'ground floor';
        }
        return truncatePromptValue($token, 64);
    }
    return '';
}

function stobeResolveWorldWeatherLabel(array $environment, array $metadata = []): string {
    $parseWeatherCode = static function (mixed $value): ?int {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return intval(round($value));
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $trimmed) === 1) {
                return intval(round(floatval($trimmed)));
            }
        }
        return null;
    };

    foreach (['weather_name', 'weather_state', 'weather_type', 'weather'] as $key) {
        if (!array_key_exists($key, $environment)) {
            continue;
        }
        $candidate = stobeNormalizeWorldPromptToken($environment[$key]);
        if ($candidate === '') {
            continue;
        }
        $lowerCandidate = strtolower($candidate);
        if (preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $lowerCandidate) === 1) {
            continue;
        }
        if (in_array($lowerCandidate, ['none', 'no weather', 'normal', 'clear'], true)) {
            return 'Clear';
        }
        $normalized = preg_replace('/\s+/u', ' ', $candidate) ?? $candidate;
        return truncatePromptValue(ucwords(strtolower($normalized)), 120);
    }

    $weatherCode = $parseWeatherCode($environment['weather'] ?? ($metadata['weather'] ?? null));
    if ($weatherCode !== null) {
        $weatherLabelMap = [
            0 => 'Clear',
            1 => 'Duststorm',
            2 => 'Acid Rain',
            3 => 'Burning',
            4 => 'Gas',
            5 => 'Rain',
        ];
        if (array_key_exists($weatherCode, $weatherLabelMap)) {
            return $weatherLabelMap[$weatherCode];
        }
        return 'Weather ' . strval($weatherCode);
    }

    $indoorsFlag = stobeParseFlexibleBool($environment['indoors'] ?? ($metadata['indoors'] ?? null));
    $outdoorsFlag = stobeParseFlexibleBool($environment['outdoors'] ?? ($metadata['outdoors'] ?? null));
    if ($indoorsFlag === true) {
        return 'None (indoors)';
    }
    if ($outdoorsFlag === true) {
        return 'Clear';
    }

    return '';
}

function stobeShouldUseBuildingInWorldLocation(array $environment, array $metadata = []): bool {
    $indoorsFlag = stobeParseFlexibleBool($environment['indoors'] ?? ($metadata['indoors'] ?? null));
    $outdoorsFlag = stobeParseFlexibleBool($environment['outdoors'] ?? ($metadata['outdoors'] ?? null));
    if ($outdoorsFlag === true) {
        return false;
    }
    if ($indoorsFlag === false) {
        return false;
    }
    return true;
}

function stobeBuildWorldPromptContextFromNpcData(array $npcData): array {
    $resolved = [
        'location' => '',
        'weather' => '',
        'floor' => '',
    ];

    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    $extendedData = normalizeNpcExtendedDataPayload($npcData['extended_data'] ?? []);

    $environment = [];
    $environmentCandidates = [
        $extendedData['environment'] ?? null,
        $metadata['environment'] ?? null,
    ];
    foreach (['nearby_snapshot', 'entry', 'context'] as $nestedKey) {
        $nestedExtended = $extendedData[$nestedKey] ?? null;
        if (is_array($nestedExtended)) {
            $environmentCandidates[] = $nestedExtended['environment'] ?? $nestedExtended;
        }
        $nestedMetadata = $metadata[$nestedKey] ?? null;
        if (is_array($nestedMetadata)) {
            $environmentCandidates[] = $nestedMetadata['environment'] ?? $nestedMetadata;
        }
    }
    foreach ($environmentCandidates as $candidate) {
        $normalizedEnvironment = stobeNormalizeWorldEnvironmentPayload($candidate);
        if (count($normalizedEnvironment) > 0) {
            $environment = $normalizedEnvironment;
            break;
        }
    }

    $pickToken = static function (array $source, array $keys): string {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $token = stobeNormalizeWorldPromptToken($source[$key]);
            if ($token !== '') {
                return $token;
            }
        }
        return '';
    };

    $useBuildingToken = stobeShouldUseBuildingInWorldLocation($environment, $metadata);
    $locationCandidates = [];
    if ($useBuildingToken) {
        $locationCandidates[] = $pickToken($environment, ['building_name', 'indoors_name', 'location', 'location_name', 'cell', 'area_name', 'area']);
    } else {
        $locationCandidates[] = $pickToken($environment, ['location', 'location_name', 'cell', 'area_name', 'area']);
    }
    $locationCandidates[] = $pickToken($environment, ['town_name', 'town', 'city', 'settlement']);
    $locationCandidates[] = $pickToken($environment, ['zone_name', 'zone']);
    $locationCandidates[] = $pickToken($environment, ['region', 'region_name']);
    $locationCandidates[] = $pickToken($extendedData, ['location', 'location_name', 'town', 'town_name', 'zone', 'zone_name', 'region']);
    if ($useBuildingToken) {
        $locationCandidates[] = $pickToken($metadata, ['location', 'location_name', 'town', 'town_name', 'zone', 'zone_name', 'region', 'region_name', 'building_name', 'cell']);
    } else {
        $locationCandidates[] = $pickToken($metadata, ['location', 'location_name', 'town', 'town_name', 'zone', 'zone_name', 'region', 'region_name', 'cell']);
    }
    $locationCandidates[] = stobeNormalizeWorldPromptToken($npcData['town'] ?? '');
    $locationCandidates[] = stobeNormalizeWorldPromptToken($npcData['zone'] ?? '');
    $locationCandidates[] = stobeNormalizeWorldPromptToken($npcData['region'] ?? '');

    $locationParts = [];
    $seenLocationParts = [];
    foreach ($locationCandidates as $candidate) {
        if ($candidate === '') {
            continue;
        }
        $dedupeKey = strtolower($candidate);
        if (isset($seenLocationParts[$dedupeKey])) {
            continue;
        }
        $seenLocationParts[$dedupeKey] = true;
        $locationParts[] = $candidate;
    }
    if (count($locationParts) > 0) {
        $resolved['location'] = truncatePromptValue(implode(', ', $locationParts), 260);
    }

    $floorSources = [$environment, $extendedData, $metadata, $npcData];
    foreach (['environment', 'nearby_snapshot', 'entry', 'context'] as $nestedKey) {
        if (isset($extendedData[$nestedKey]) && is_array($extendedData[$nestedKey])) {
            $nested = $extendedData[$nestedKey];
            $floorSources[] = $nested;
            if (isset($nested['environment']) && is_array($nested['environment'])) {
                $floorSources[] = $nested['environment'];
            }
        }
        if (isset($metadata[$nestedKey]) && is_array($metadata[$nestedKey])) {
            $nested = $metadata[$nestedKey];
            $floorSources[] = $nested;
            if (isset($nested['environment']) && is_array($nested['environment'])) {
                $floorSources[] = $nested['environment'];
            }
        }
    }
    foreach ($floorSources as $source) {
        if (!is_array($source)) {
            continue;
        }
        foreach (['floor', 'current_floor', 'floor_num', 'level', 'story', 'story_level', 'story_num'] as $floorKey) {
            if (!array_key_exists($floorKey, $source)) {
                continue;
            }
            $floorToken = stobeNormalizeWorldFloorToken($source[$floorKey]);
            if ($floorToken !== '') {
                $resolved['floor'] = $floorToken;
                break 2;
            }
        }
    }

    $weatherEnvironment = $environment;
    foreach (['weather', 'weather_name', 'weather_state', 'weather_type', 'weather_strength', 'weather_affect_strength', 'wind_speed', 'wind_direction', 'wetness', 'active_environmental_effects', 'indoors', 'outdoors'] as $weatherKey) {
        if (!array_key_exists($weatherKey, $weatherEnvironment) && array_key_exists($weatherKey, $extendedData)) {
            $weatherEnvironment[$weatherKey] = $extendedData[$weatherKey];
        }
        if (!array_key_exists($weatherKey, $weatherEnvironment) && array_key_exists($weatherKey, $metadata)) {
            $weatherEnvironment[$weatherKey] = $metadata[$weatherKey];
        }
    }
    $resolved['weather'] = stobeResolveWorldWeatherLabel($weatherEnvironment, $metadata);
    if ($resolved['floor'] === '') {
        $indoorsFlag = stobeParseFlexibleBool($weatherEnvironment['indoors'] ?? ($metadata['indoors'] ?? null));
        $outdoorsFlag = stobeParseFlexibleBool($weatherEnvironment['outdoors'] ?? ($metadata['outdoors'] ?? null));
        if ($indoorsFlag === true || $outdoorsFlag === true) {
            $resolved['floor'] = 'ground floor';
        }
    }

    if ($resolved['location'] === '') {
        $npcName = normalizeParticipantNameToken(strval($npcData['name'] ?? ''));
        if ($npcName !== '') {
            $resolvedGeo = getEventGeoFromNpcName($npcName);
            $resolved['location'] = truncatePromptValue(composeEventLocationText($resolvedGeo), 260);
        }
    }

    return $resolved;
}

function stobeMergeWorldPromptContext(array &$resolved, array $candidate): void {
    foreach (['location', 'weather', 'floor'] as $key) {
        $current = $key === 'floor'
            ? stobeNormalizeWorldFloorToken($resolved[$key] ?? '')
            : stobeNormalizeWorldPromptToken($resolved[$key] ?? '');
        if ($current !== '') {
            $resolved[$key] = $current;
            continue;
        }
        $incoming = $key === 'floor'
            ? stobeNormalizeWorldFloorToken($candidate[$key] ?? '')
            : stobeNormalizeWorldPromptToken($candidate[$key] ?? '');
        if ($incoming !== '') {
            $resolved[$key] = $incoming;
        }
    }
}

function stobeCollectWorldContextCandidateNames(mixed $context = null): array {
    $names = [];
    $seen = [];
    $addName = static function (mixed $value) use (&$names, &$seen): void {
        $normalized = normalizeParticipantNameToken(strval($value));
        if ($normalized === '') {
            return;
        }
        $key = strtolower($normalized);
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $names[] = $normalized;
    };

    if (is_string($context)) {
        $addName($context);
    } elseif (is_array($context)) {
        foreach ([
            'name',
            'speaker_name',
            'speaker',
            'target_name',
            'target',
            'profile',
            'npc',
            'npc_name',
            'responding_npc',
            'previous_speaker',
            'previous_target',
        ] as $field) {
            if (array_key_exists($field, $context)) {
                $addName($context[$field]);
            }
        }
        $contextPeople = $context['people'] ?? null;
        if (is_array($contextPeople)) {
            foreach ($contextPeople as $person) {
                $addName($person);
            }
        }
    }

    $peopleRaw = trim(strval($GLOBALS['CACHE_PEOPLE'] ?? ($_GET['people'] ?? '')));
    if ($peopleRaw !== '') {
        $decodedPeople = json_decode($peopleRaw, true);
        if (is_array($decodedPeople)) {
            foreach ($decodedPeople as $personToken) {
                $addName($personToken);
            }
        }
    }

    $addName(strval($_GET['profile'] ?? ''));

    return $names;
}

function stobeResolveWorldContextFromRecentNpcRows(string $npcName, int $limit = 8): array {
    $empty = [
        'location' => '',
        'weather' => '',
        'floor' => '',
    ];

    $safeName = normalizeParticipantNameToken($npcName);
    if ($safeName === '') {
        return $empty;
    }

    static $cache = [];
    $safeLimit = max(1, min(20, $limit));
    $cacheKey = strtolower($safeName) . '|' . strval($safeLimit);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db || !is_object($db) || !method_exists($db, 'fetchAll')) {
        $cache[$cacheKey] = $empty;
        return $cache[$cacheKey];
    }

    $rows = $db->fetchAll(
        "SELECT *
         FROM core_npc
         WHERE LOWER(name) = LOWER($1)
         ORDER BY gamets_last_updated DESC, updated_at DESC
         LIMIT " . strval($safeLimit),
        [$safeName]
    );
    if (!is_array($rows) || count($rows) === 0) {
        $rows = $db->fetchAll(
            "SELECT *
             FROM core_npc
             WHERE LOWER(COALESCE(original_name, '')) = LOWER($1)
             ORDER BY
                CASE WHEN COALESCE(metadata->>'storage_id', '') <> '' THEN 0 ELSE 1 END,
                gamets_last_updated DESC,
                updated_at DESC
             LIMIT " . strval($safeLimit),
            [$safeName]
        );
    }

    $resolved = $empty;
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) === 0) {
                continue;
            }
            stobeMergeWorldPromptContext($resolved, stobeBuildWorldPromptContextFromNpcData($row));
            if ($resolved['location'] !== '' && $resolved['weather'] !== '' && $resolved['floor'] !== '') {
                break;
            }
        }
    }

    $cache[$cacheKey] = $resolved;
    return $cache[$cacheKey];
}

function stobeResolveWorldContextFromRecentEventData(int $windowSeconds = 1800, int $limit = 64): array {
    $empty = [
        'location' => '',
        'weather' => '',
        'floor' => '',
    ];

    static $cache = [];
    $safeWindow = max(60, min(86400, $windowSeconds));
    $safeLimit = max(1, min(256, $limit));
    $cacheKey = strval($safeWindow) . '|' . strval($safeLimit);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db || !is_object($db) || !method_exists($db, 'fetchAll')) {
        $cache[$cacheKey] = $empty;
        return $cache[$cacheKey];
    }

    $hasGeoColumn = function_exists('stobeEnsureEventlogGeoColumn') && stobeEnsureEventlogGeoColumn();
    $sql = $hasGeoColumn
        ? "SELECT type, data, location, geo
         FROM eventlog
         WHERE localts > $1
         ORDER BY localts DESC, ts DESC, rowid DESC
         LIMIT " . strval($safeLimit)
        : "SELECT type, data, location
         FROM eventlog
         WHERE localts > $1
         ORDER BY localts DESC, ts DESC, rowid DESC
         LIMIT " . strval($safeLimit);
    $rows = $db->fetchAll($sql, [time() - $safeWindow]);
    if (!is_array($rows) || count($rows) === 0) {
        $cache[$cacheKey] = $empty;
        return $cache[$cacheKey];
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $geoRaw = $row['geo'] ?? null;
        $fromGeoColumn = '';
        if (is_array($geoRaw)) {
            $fromGeoColumn = composeEventLocationText(stobeNormalizeGeoContext($geoRaw));
        } elseif (is_string($geoRaw) && trim($geoRaw) !== '') {
            $decodedGeo = json_decode($geoRaw, true);
            if (is_array($decodedGeo)) {
                $fromGeoColumn = composeEventLocationText(stobeNormalizeGeoContext($decodedGeo));
            }
        }
        if ($fromGeoColumn !== '') {
            $cache[$cacheKey] = [
                'location' => truncatePromptValue($fromGeoColumn, 260),
                'weather' => '',
                'floor' => '',
            ];
            return $cache[$cacheKey];
        }

        $locationToken = trim(strval($row['location'] ?? ''));
        if ($locationToken !== '') {
            $fromLocationColumn = composeEventLocationText(stobeNormalizeGeoContext([
                'location' => $locationToken,
                'building' => '',
                'zone' => '',
                'region' => '',
            ]));
            if ($fromLocationColumn !== '') {
                $cache[$cacheKey] = [
                    'location' => truncatePromptValue($fromLocationColumn, 260),
                    'weather' => '',
                    'floor' => '',
                ];
                return $cache[$cacheKey];
            }
        }

        $eventType = strtolower(trim(strval($row['type'] ?? '')));
        $eventData = trim(strval($row['data'] ?? ''));
        if ($eventData === '') {
            continue;
        }

        $geo = ['location' => '', 'building' => '', 'zone' => '', 'city' => '', 'region' => ''];
        if (in_array($eventType, ['location', 'infoloc'], true)) {
            $geo = extractEventGeoFromLocationUpdateMessage($eventData);
            if (
                trim(strval($geo['location'] ?? '')) === '' &&
                trim(strval($geo['zone'] ?? ($geo['city'] ?? ''))) === '' &&
                trim(strval($geo['region'] ?? '')) === ''
            ) {
                $geo = extractEventGeoFromString($eventData);
            }
        }

        $fromEventData = composeEventLocationText($geo);
        if ($fromEventData !== '') {
            $cache[$cacheKey] = [
                'location' => truncatePromptValue($fromEventData, 260),
                'weather' => '',
                'floor' => '',
            ];
            return $cache[$cacheKey];
        }
    }

    $cache[$cacheKey] = $empty;
    return $cache[$cacheKey];
}

function stobeResolveWorldPromptContext(mixed $context = null): array {
    $resolved = [
        'location' => '',
        'weather' => '',
        'floor' => '',
    ];

    if (is_array($context)) {
        if (
            array_key_exists('metadata', $context)
            || array_key_exists('extended_data', $context)
            || array_key_exists('name', $context)
        ) {
            stobeMergeWorldPromptContext($resolved, stobeBuildWorldPromptContextFromNpcData($context));
        }

        foreach (['speaker_data', 'target_data', 'npc_data', 'context_data'] as $nestedDataKey) {
            $nested = $context[$nestedDataKey] ?? null;
            if (is_array($nested) && count($nested) > 0) {
                stobeMergeWorldPromptContext($resolved, stobeBuildWorldPromptContextFromNpcData($nested));
            }
        }

        if (array_key_exists('location', $context)) {
            $contextLocation = truncatePromptValue(stobeNormalizeWorldPromptToken($context['location']), 260);
            if ($contextLocation !== '') {
                $resolved['location'] = $contextLocation;
            }
        }
        if ($resolved['weather'] === '') {
            $resolved['weather'] = stobeResolveWorldWeatherLabel($context, $context);
        }
        if ($resolved['floor'] === '') {
            foreach (['floor', 'current_floor', 'floor_num', 'level', 'story', 'story_level', 'story_num'] as $floorKey) {
                if (!array_key_exists($floorKey, $context)) {
                    continue;
                }
                $floorToken = stobeNormalizeWorldFloorToken($context[$floorKey]);
                if ($floorToken !== '') {
                    $resolved['floor'] = $floorToken;
                    break;
                }
            }
        }
    }

    $candidateNames = stobeCollectWorldContextCandidateNames($context);
    foreach ($candidateNames as $candidateName) {
        $candidateNpcData = getNpcData($candidateName);
        if (is_array($candidateNpcData) && count($candidateNpcData) > 0) {
            stobeMergeWorldPromptContext($resolved, stobeBuildWorldPromptContextFromNpcData($candidateNpcData));
        }
        if ($resolved['location'] === '' || $resolved['weather'] === '' || $resolved['floor'] === '') {
            stobeMergeWorldPromptContext($resolved, stobeResolveWorldContextFromRecentNpcRows($candidateName, 10));
        }
        if ($resolved['location'] === '') {
            $candidateGeo = getEventGeoFromNpcName($candidateName);
            $candidateLocation = truncatePromptValue(composeEventLocationText($candidateGeo), 260);
            if ($candidateLocation !== '') {
                $resolved['location'] = $candidateLocation;
            }
        }
        if ($resolved['location'] !== '' && $resolved['weather'] !== '' && $resolved['floor'] !== '') {
            break;
        }
    }

    $queryLocation = trim(strval($_GET['location'] ?? ''));
    $queryCity = trim(strval($_GET['city'] ?? ''));
    $queryBuilding = trim(strval($_GET['loc_building'] ?? ''));
    $queryRegion = trim(strval($_GET['region'] ?? ''));
    $queryZone = trim(strval($_GET['loc_zone'] ?? ''));
    $queryLocRegion = trim(strval($_GET['loc_region'] ?? ''));
    $queryIndoorsRaw = strtolower(trim(strval($_GET['loc_indoors'] ?? '')));
    $queryOutdoors = in_array($queryIndoorsRaw, ['0', 'false', 'no', 'off'], true);
    if ($queryZone === '' && $queryCity !== '') {
        $queryZone = $queryCity;
    }
    if ($queryRegion === '' && $queryLocRegion !== '') {
        $queryRegion = $queryLocRegion;
    }
    if ($queryRegion === '') {
        $queryTownLike = $queryZone !== '' ? $queryZone : $queryLocation;
        $queryTownLike = trim($queryTownLike);
        if ($queryTownLike !== '' && function_exists('stobeResolveRegionFromAnyToken')) {
            $mappedRegion = stobeResolveRegionFromAnyToken($queryTownLike);
            if ($mappedRegion !== '') {
                $queryRegion = $mappedRegion;
            }
        } elseif ($queryTownLike !== '' && function_exists('stobeResolveKenshiZoneFromTown')) {
            $mappedRegion = stobeResolveKenshiZoneFromTown($queryTownLike);
            if ($mappedRegion !== '') {
                $queryRegion = $mappedRegion;
            }
        }
    }
    stobeLogDebug('GEO_DEBUG_WORLD_QUERY', [
        'query' => [
            'location' => $queryLocation,
            'city' => $queryCity,
            'region' => $queryRegion,
            'loc_building' => $queryBuilding,
            'loc_zone' => $queryZone,
            'loc_region' => $queryLocRegion,
            'loc_indoors' => $queryIndoorsRaw,
        ],
    ]);
    if ($queryOutdoors) {
        if ($queryRegion === '') {
            $queryTownLike = $queryZone !== '' ? $queryZone : $queryLocation;
            $queryTownLike = trim($queryTownLike);
            if ($queryTownLike !== '' && function_exists('stobeResolveRegionFromAnyToken')) {
                $mappedRegion = stobeResolveRegionFromAnyToken($queryTownLike);
                if ($mappedRegion !== '') {
                    $queryRegion = $mappedRegion;
                }
            } elseif ($queryTownLike !== '' && function_exists('stobeResolveKenshiZoneFromTown')) {
                $mappedRegion = stobeResolveKenshiZoneFromTown($queryTownLike);
                if ($mappedRegion !== '') {
                    $queryRegion = $mappedRegion;
                }
            }
        }
        $queryBuilding = '';
        if ($queryZone !== '') {
            $queryLocation = $queryZone;
        } elseif ($queryRegion !== '') {
            $queryLocation = $queryRegion;
        } else {
            $derivedRegion = trim(strval($GLOBALS['CACHE_REGION'] ?? ''));
            if ($derivedRegion === '' && function_exists('getRecentEventGeoFallback')) {
                $fallbackGeo = getRecentEventGeoFallback('', 86400);
                $derivedRegion = trim(strval($fallbackGeo['region'] ?? ''));
            }
            if ($derivedRegion !== '') {
                $queryRegion = $derivedRegion;
                $queryLocation = $derivedRegion;
                stobeLogDebug('GEO_DEBUG_WORLD_OUTDOOR_REGION_FALLBACK', [
                    'region' => $derivedRegion,
                ]);
            }
        }
    }
    $queryLocationText = '';
    $queryLocationToken = stobeNormalizeWorldPromptToken($queryLocation);
    if ($queryLocationToken !== '') {
        $queryLocationText = composeEventLocationText([
            'location' => $queryLocationToken,
            'building' => $queryBuilding,
            'zone' => $queryZone,
            'city' => $queryZone,
            'region' => $queryRegion,
            'allow_unknown_zone' => true,
        ]);
    } elseif ($queryBuilding !== '' || $queryZone !== '' || $queryRegion !== '') {
        $queryLocationText = composeEventLocationText([
            'location' => '',
            'building' => $queryBuilding,
            'zone' => $queryZone,
            'city' => $queryZone,
            'region' => $queryRegion,
            'allow_unknown_zone' => true,
        ]);
    }
    // Current request geo is authoritative for the current prompt turn.
    if ($queryLocationText !== '') {
        $resolved['location'] = truncatePromptValue($queryLocationText, 260);
    }

    if ($resolved['location'] === '') {
        $cachedLocation = trim(strval($GLOBALS['CACHE_LOCATION'] ?? ''));
        if ($cachedLocation !== '') {
            $resolved['location'] = truncatePromptValue($cachedLocation, 260);
        }
    }

    if ($resolved['location'] === '') {
        $recentGeo = getRecentEventGeoFallback('', 86400);
        $resolved['location'] = truncatePromptValue(composeEventLocationText($recentGeo), 260);
    }

    if ($resolved['location'] === '') {
        stobeMergeWorldPromptContext($resolved, stobeResolveWorldContextFromRecentEventData(3600, 96));
    }

    stobeLogDebug('GEO_DEBUG_WORLD_RESULT', [
        'location' => strval($resolved['location'] ?? ''),
        'weather' => strval($resolved['weather'] ?? ''),
        'floor' => strval($resolved['floor'] ?? ''),
    ]);
    return $resolved;
}

function stobeBuildGameTimePromptBlock(mixed $gamets, mixed $worldContext = null): string {
    if (!stobePromptContextOptionEnabled('enabled_sections', 'world')) {
        return '';
    }

    $safeGamets = stobeGametsNormalize($gamets);
    $dateLabel = stobeGametsDateLabel($safeGamets);
    $world = stobeResolveWorldPromptContext($worldContext);
    $location = stobeNormalizeWorldPromptToken($world['location'] ?? '');
    $weather = stobeNormalizeWorldPromptToken($world['weather'] ?? '');
    $floor = stobeNormalizeWorldFloorToken($world['floor'] ?? '');

    if ($location === '') {
        $location = 'Unknown';
    }
    if ($weather === '') {
        $weather = 'Unknown';
    }
    if ($floor === '') {
        $floor = 'Unknown';
    }

    $xml = [
        "<world>",
        "  <date_label>" . stobePromptXmlEscape($dateLabel) . "</date_label>",
        "  <location>" . stobePromptXmlEscape($location) . "</location>",
        "  <weather>" . stobePromptXmlEscape($weather) . "</weather>",
        "  <floor>" . stobePromptXmlEscape($floor) . "</floor>",
    ];
    $xml[] = "</world>";

    return "\n" . implode("\n", $xml);
}

function stobeBuildRecentContextPromptBlock(string $historyText, string $tag = 'recent_context'): string {
    $normalizedTag = strtolower(trim($tag));
    if ($normalizedTag === '') {
        $normalizedTag = 'recent_context';
    }
    $normalizedTag = preg_replace('/[^a-z0-9_]+/i', '_', $normalizedTag) ?? 'recent_context';
    if ($normalizedTag === '') {
        $normalizedTag = 'recent_context';
    }

    $lines = preg_split('/\R+/', trim($historyText)) ?: [];
    $xml = ["<{$normalizedTag}>"];
    $added = 0;
    foreach ($lines as $line) {
        $clean = trim(strval($line));
        if ($clean === '') {
            continue;
        }
        $xml[] = '  <line>' . stobePromptXmlEscape($clean) . '</line>';
        $added++;
    }
    if ($added === 0) {
        $xml[] = '  <line>(none)</line>';
    }
    $xml[] = "</{$normalizedTag}>";

    return implode("\n", $xml);
}

function stobeSanitizePromptContextLine(string $line): string {
    $clean = sanitizeForKenshi(trim(strval($line)));
    if ($clean === '') {
        return '';
    }

    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $clean) ?? $clean;
    $clean = trim($clean);
    if ($clean === '') {
        return '';
    }

    $parsed = parseDialogueEventData($clean);
    $speaker = normalizeParticipantNameToken(strval($parsed['speaker'] ?? ''));
    $target = stobeNormalizeDialogueTargetToken(strval($parsed['target'] ?? ''));
    $message = trim(strval($parsed['message'] ?? ''));

    if ($message !== '' && function_exists('stobeSanitizeDialogueMessageForLog')) {
        $message = stobeSanitizeDialogueMessageForLog($message);
    }

    if ($speaker !== '' && $message !== '') {
        $normalizedLine = $speaker . ': ' . $message;
        if ($target !== '') {
            $normalizedLine .= ' (talking to: ' . $target . ')';
        }
        return trim($normalizedLine);
    }

    if ($message !== '') {
        $clean = $message;
    }

    $clean = preg_replace(
        '/(\(talking to:\s*[^\)]+\))\s*(?:\?+|[0-9]{1,3})\s*$/iu',
        '$1',
        $clean
    ) ?? $clean;
    $clean = preg_replace('/\s+\?{2,}\s*$/u', '', $clean) ?? $clean;
    $clean = preg_replace('/(?<=\D)7+\s*$/u', '', $clean) ?? $clean;
    $clean = preg_replace('/([\.!\?\)\]])\d{1,3}\s*$/u', '$1', $clean) ?? $clean;
    $clean = preg_replace('/\s{2,}/u', ' ', $clean) ?? $clean;

    return trim($clean);
}

function stobeNormalizeContextHistoryDataLine(string $historyData): string {
    $raw = trim($historyData);
    if ($raw === '') {
        return '';
    }

    // Collapse multiline payloads into one line to avoid fragmented context
    // entries when old event rows contain raw JSON.
    $singleLine = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);

    if (preg_match('/^\s*([^:]{1,120}):\s*(\{.*)$/s', $raw, $matches) === 1) {
        $speaker = normalizeParticipantNameToken(strval($matches[1] ?? ''));
        $jsonPayload = trim(strval($matches[2] ?? ''));
        $structured = stobeParseStructuredDialogueResponse($jsonPayload, 'chat');
        $message = trim(strval($structured['message'] ?? ''));
        if ($message !== '' && function_exists('stobeSanitizeDialogueMessageForLog')) {
            $message = stobeSanitizeDialogueMessageForLog($message);
        }
        if ($message !== '') {
            $line = $speaker !== '' ? ($speaker . ': ' . $message) : $message;
            $listener = stobeNormalizeDialogueTargetToken(strval($structured['listener'] ?? ''));
            if ($listener !== '') {
                $line .= ' (talking to: ' . $listener . ')';
            }
            return stobeSanitizePromptContextLine($line);
        }
    }

    if (str_starts_with(ltrim($raw), '{')) {
        $structured = stobeParseStructuredDialogueResponse($raw, 'chat');
        $message = trim(strval($structured['message'] ?? ''));
        if ($message !== '' && function_exists('stobeSanitizeDialogueMessageForLog')) {
            $message = stobeSanitizeDialogueMessageForLog($message);
        }
        if ($message !== '') {
            $character = normalizeParticipantNameToken(strval($structured['character'] ?? ''));
            $line = $character !== '' ? ($character . ': ' . $message) : $message;
            $listener = stobeNormalizeDialogueTargetToken(strval($structured['listener'] ?? ''));
            if ($listener !== '') {
                $line .= ' (talking to: ' . $listener . ')';
            }
            return stobeSanitizePromptContextLine($line);
        }
    }

    return stobeSanitizePromptContextLine($singleLine);
}

function stobeNormalizeDialogueTargetToken(string $rawTarget): string {
    $target = normalizeParticipantNameToken($rawTarget);
    if ($target === '') {
        return '';
    }

    $lower = strtolower(trim($target));
    if (in_array($lower, ['unknown', 'none', 'n/a', 'null', 'unset', 'neutral'], true)) {
        return '';
    }

    return $target;
}

function stobeIsMergeableRecentContextType(string $historyType): bool {
    return in_array($historyType, ['infonpc', 'infonpc_close', 'infoloc', 'location', 'infoitems'], true);
}

function stobeRecentContextSingletonTypeKey(string $historyType): string {
    $normalizedType = strtolower(trim($historyType));
    if ($normalizedType === 'infonpc') {
        return 'infonpc';
    }

    return '';
}

function stobeRemoveRecentContextMessageAtIndex(
    array &$messages,
    array &$messageTypes,
    array &$messageKeys,
    array &$messageDialogueMeta,
    array &$messageTransferTradeMeta,
    int $index
): void {
    if ($index < 0 || $index >= count($messages)) {
        return;
    }

    array_splice($messages, $index, 1);
    if ($index < count($messageTypes)) {
        array_splice($messageTypes, $index, 1);
    }
    if ($index < count($messageKeys)) {
        array_splice($messageKeys, $index, 1);
    }
    if ($index < count($messageDialogueMeta)) {
        array_splice($messageDialogueMeta, $index, 1);
    }
    if ($index < count($messageTransferTradeMeta)) {
        array_splice($messageTransferTradeMeta, $index, 1);
    }
}

function stobeBuildRecentContextDedupeKey(string $historyType, string $historyData): string {
    $normalizedType = strtolower(trim($historyType));
    $normalizedData = strtolower(trim(preg_replace('/\s+/u', ' ', $historyData) ?? $historyData));
    return $normalizedType . '|' . $normalizedData;
}

function stobeIsRecentContextSpeechType(string $historyType): bool {
    return in_array($historyType, ['inputtext', 'inputtext_s', 'chat', 'rechat', 'bored'], true);
}

function stobeBuildRecentContextDialogueMeta(string $historyType, string $historyData): array {
    if (!stobeIsRecentContextSpeechType($historyType)) {
        return [];
    }

    $parsed = parseDialogueEventData($historyData);
    $speakerDisplay = normalizeParticipantNameToken(strval($parsed['speaker'] ?? ''));
    $messageDisplay = trim(strval($parsed['message'] ?? ''));
    if ($messageDisplay !== '' && function_exists('stobeSanitizeDialogueMessageForLog')) {
        $messageDisplay = stobeSanitizeDialogueMessageForLog($messageDisplay);
    }
    $messageDisplay = trim(preg_replace('/\s+/u', ' ', $messageDisplay) ?? $messageDisplay);
    if ($speakerDisplay === '' || $messageDisplay === '') {
        return [];
    }

    $speaker = strtolower($speakerDisplay);
    $message = strtolower($messageDisplay);
    $targetDisplay = stobeNormalizeDialogueTargetToken(strval($parsed['target'] ?? ''));
    $target = strtolower($targetDisplay);
    return [
        'speaker' => $speaker,
        'message' => $message,
        'target' => $target,
        'has_target' => $targetDisplay !== '',
        'speaker_display' => $speakerDisplay,
        'message_display' => $messageDisplay,
        'target_display' => $targetDisplay,
        'history_type' => strtolower(trim($historyType)),
    ];
}

function stobeBuildRecentContextDialogueLine(array $dialogueMeta): string {
    $speaker = normalizeParticipantNameToken(strval($dialogueMeta['speaker_display'] ?? ($dialogueMeta['speaker'] ?? '')));
    $message = trim(strval($dialogueMeta['message_display'] ?? ($dialogueMeta['message'] ?? '')));
    $target = stobeNormalizeDialogueTargetToken(strval($dialogueMeta['target_display'] ?? ($dialogueMeta['target'] ?? '')));
    if ($speaker === '' || $message === '') {
        return '';
    }

    $line = $speaker . ': ' . $message;
    if ($target !== '') {
        $line .= ' (talking to: ' . $target . ')';
    }

    return stobeSanitizePromptContextLine($line);
}

function stobeRecentContextSpeakerUsesAssistantRole(array $dialogueMeta, string $assistantPerspectiveNpc): bool {
    $perspectiveNpc = normalizeParticipantNameToken($assistantPerspectiveNpc);
    if ($perspectiveNpc === '' || count($dialogueMeta) === 0) {
        return false;
    }

    $speaker = normalizeParticipantNameToken(
        strval($dialogueMeta['speaker_display'] ?? ($dialogueMeta['speaker'] ?? ''))
    );
    if ($speaker === '' || (function_exists('stobeIsNarratorName') && stobeIsNarratorName($speaker))) {
        return false;
    }

    return strcasecmp($speaker, $perspectiveNpc) === 0;
}

function stobeBuildRecentContextAssistantContent(array $dialogueMeta): string {
    $character = normalizeParticipantNameToken(
        strval($dialogueMeta['speaker_display'] ?? ($dialogueMeta['speaker'] ?? ''))
    );
    $message = trim(strval($dialogueMeta['message_display'] ?? ($dialogueMeta['message'] ?? '')));
    $listener = stobeNormalizeDialogueTargetToken(
        strval($dialogueMeta['target_display'] ?? ($dialogueMeta['target'] ?? ''))
    );
    if ($message !== '' && function_exists('stobeSanitizeDialogueMessageForLog')) {
        $message = stobeSanitizeDialogueMessageForLog($message);
    }
    $message = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);
    if ($character === '' || $message === '') {
        return '';
    }

    $payload = [
        'character' => $character,
        'listener' => $listener,
        'mood' => '',
        'action' => 'Talk',
        'target' => '',
        'message' => $message,
    ];
    return strval(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function stobeBuildRecentContextMessagePayload(
    string $historyData,
    array $dialogueMeta = [],
    string $assistantPerspectiveNpc = ''
): array {
    if (stobeRecentContextSpeakerUsesAssistantRole($dialogueMeta, $assistantPerspectiveNpc)) {
        $assistantContent = stobeBuildRecentContextAssistantContent($dialogueMeta);
        if ($assistantContent !== '') {
            return [
                'role' => 'assistant',
                'content' => $assistantContent,
            ];
        }
    }

    return [
        'role' => 'user',
        'content' => " (...\n" . $historyData . "\n...)",
    ];
}

function stobeMergeRecentContextDialogueMeta(array $previousMeta, array $currentMeta): array {
    if (count($previousMeta) === 0 || count($currentMeta) === 0) {
        return [];
    }

    if (strval($previousMeta['speaker'] ?? '') !== strval($currentMeta['speaker'] ?? '')) {
        return [];
    }

    $previousMessage = trim(strval($previousMeta['message_display'] ?? ''));
    $currentMessage = trim(strval($currentMeta['message_display'] ?? ''));
    if ($previousMessage === '' || $currentMessage === '') {
        return [];
    }

    $previousTarget = strval($previousMeta['target'] ?? '');
    $currentTarget = strval($currentMeta['target'] ?? '');
    if ($previousTarget !== '' && $currentTarget !== '' && $previousTarget !== $currentTarget) {
        return [];
    }

    $mergedMessage = trim($previousMessage . ' ' . $currentMessage);
    $mergedMessage = preg_replace('/\s+/u', ' ', $mergedMessage) ?? $mergedMessage;
    if ($mergedMessage === '') {
        return [];
    }

    $mergedTargetDisplay = stobeNormalizeDialogueTargetToken(
        strval($currentMeta['target_display'] ?? '')
    );
    if ($mergedTargetDisplay === '') {
        $mergedTargetDisplay = stobeNormalizeDialogueTargetToken(
            strval($previousMeta['target_display'] ?? '')
        );
    }

    return [
        'speaker' => strval($previousMeta['speaker'] ?? ''),
        'message' => strtolower($mergedMessage),
        'target' => strtolower($mergedTargetDisplay),
        'has_target' => $mergedTargetDisplay !== '',
        'speaker_display' => normalizeParticipantNameToken(
            strval($previousMeta['speaker_display'] ?? ($currentMeta['speaker_display'] ?? ''))
        ),
        'message_display' => $mergedMessage,
        'target_display' => $mergedTargetDisplay,
        'history_type' => strval($currentMeta['history_type'] ?? ($previousMeta['history_type'] ?? 'chat')),
    ];
}

function stobeResolveRecentContextDialogueVariantDecision(array $previousMeta, array $currentMeta): string {
    if (count($previousMeta) === 0 || count($currentMeta) === 0) {
        return '';
    }

    if (
        strval($previousMeta['speaker'] ?? '') !== strval($currentMeta['speaker'] ?? '')
        || strval($previousMeta['message'] ?? '') !== strval($currentMeta['message'] ?? '')
    ) {
        return '';
    }

    $previousTarget = strval($previousMeta['target'] ?? '');
    $currentTarget = strval($currentMeta['target'] ?? '');
    if ($previousTarget !== '' && $currentTarget !== '' && $previousTarget !== $currentTarget) {
        return '';
    }

    if ($previousTarget === '' && $currentTarget !== '') {
        return 'replace_prior';
    }
    return 'skip_current';
}

function stobeNormalizeRecentContextTradeItemKey(string $value): string {
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        return '';
    }
    $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    return trim($normalized);
}

function stobeParseRecentContextTransferTradeMeta(string $historyType, string $historyData): array {
    if (strtolower(trim($historyType)) !== 'trade') {
        return [];
    }

    $parsed = parseDialogueEventData($historyData);
    $speaker = normalizeParticipantNameToken(strval($parsed['speaker'] ?? ''));
    if ($speaker === '') {
        return [];
    }
    $target = normalizeParticipantNameToken(strval($parsed['target'] ?? ''));
    $message = trim(strval($parsed['message'] ?? ''));
    if ($message === '') {
        return [];
    }

    $matches = [];
    if (preg_match('/^transferred\s+(.+)$/iu', $message, $matches) !== 1) {
        return [];
    }
    $itemsBlob = trim(strval($matches[1] ?? ''));
    if ($itemsBlob === '') {
        return [];
    }

    $rawParts = preg_split('/\s*,\s*/u', $itemsBlob) ?: [];
    $items = [];
    foreach ($rawParts as $rawPart) {
        $part = trim(strval($rawPart));
        if ($part === '') {
            continue;
        }
        $partMatches = [];
        if (preg_match('/^(\d+)\s*x\s+(.+)$/iu', $part, $partMatches) !== 1
            && preg_match('/^(\d+)x\s+(.+)$/iu', $part, $partMatches) !== 1) {
            continue;
        }
        $qty = intval($partMatches[1] ?? 0);
        $name = trim(strval($partMatches[2] ?? ''));
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        if ($qty <= 0 || $name === '') {
            continue;
        }
        $key = stobeNormalizeRecentContextTradeItemKey($name);
        if ($key === '') {
            continue;
        }
        $items[] = [
            'key' => $key,
            'name' => $name,
            'qty' => $qty,
        ];
    }
    if (count($items) === 0) {
        return [];
    }

    return [
        'speaker' => $speaker,
        'target' => $target,
        'speaker_display' => $speaker,
        'target_display' => $target,
        'items' => $items,
    ];
}

function stobeMergeRecentContextTransferTradeMeta(array $previousMeta, array $currentMeta): array {
    if (count($previousMeta) === 0 || count($currentMeta) === 0) {
        return [];
    }

    $prevSpeaker = strtolower(trim(strval($previousMeta['speaker'] ?? '')));
    $currSpeaker = strtolower(trim(strval($currentMeta['speaker'] ?? '')));
    $prevTarget = strtolower(trim(strval($previousMeta['target'] ?? '')));
    $currTarget = strtolower(trim(strval($currentMeta['target'] ?? '')));
    if ($prevSpeaker === '' || $currSpeaker === '' || $prevSpeaker !== $currSpeaker) {
        return [];
    }
    if ($prevTarget !== $currTarget) {
        return [];
    }

    $merged = $previousMeta;
    $mergedItems = is_array($merged['items'] ?? null) ? $merged['items'] : [];
    $indexByKey = [];
    foreach ($mergedItems as $idx => $item) {
        if (!is_array($item)) {
            continue;
        }
        $key = stobeNormalizeRecentContextTradeItemKey(strval($item['key'] ?? ''));
        if ($key === '') {
            $key = stobeNormalizeRecentContextTradeItemKey(strval($item['name'] ?? ''));
        }
        if ($key !== '') {
            $indexByKey[$key] = $idx;
        }
    }

    $currentItems = is_array($currentMeta['items'] ?? null) ? $currentMeta['items'] : [];
    foreach ($currentItems as $item) {
        if (!is_array($item)) {
            continue;
        }
        $key = stobeNormalizeRecentContextTradeItemKey(strval($item['key'] ?? ''));
        if ($key === '') {
            $key = stobeNormalizeRecentContextTradeItemKey(strval($item['name'] ?? ''));
        }
        $qty = intval($item['qty'] ?? 0);
        $name = trim(strval($item['name'] ?? ''));
        if ($key === '' || $qty <= 0 || $name === '') {
            continue;
        }

        if (array_key_exists($key, $indexByKey)) {
            $idx = intval($indexByKey[$key]);
            $existingQty = intval($mergedItems[$idx]['qty'] ?? 0);
            $mergedItems[$idx]['qty'] = $existingQty + $qty;
            if (trim(strval($mergedItems[$idx]['name'] ?? '')) === '') {
                $mergedItems[$idx]['name'] = $name;
            }
        } else {
            $indexByKey[$key] = count($mergedItems);
            $mergedItems[] = [
                'key' => $key,
                'name' => $name,
                'qty' => $qty,
            ];
        }
    }

    $merged['items'] = $mergedItems;
    return $merged;
}

function stobeBuildRecentContextTransferTradeLine(array $meta): string {
    $items = is_array($meta['items'] ?? null) ? $meta['items'] : [];
    if (count($items) === 0) {
        return '';
    }

    $speaker = trim(strval($meta['speaker_display'] ?? $meta['speaker'] ?? ''));
    if ($speaker === '') {
        $speaker = 'Unknown';
    }
    $target = trim(strval($meta['target_display'] ?? $meta['target'] ?? ''));

    $parts = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $qty = intval($item['qty'] ?? 0);
        $name = trim(strval($item['name'] ?? ''));
        if ($qty <= 0 || $name === '') {
            continue;
        }
        $parts[] = strval($qty) . 'x ' . $name;
    }
    if (count($parts) === 0) {
        return '';
    }

    $line = $speaker . ': transferred ' . implode(', ', $parts);
    if ($target !== '' && strcasecmp($target, $speaker) !== 0) {
        $line .= ' (talking to: ' . $target . ')';
    }
    return stobeSanitizePromptContextLine($line);
}

function stobeBuildRecentContextTextMessagePayload(string $historyLine, string $assistantPerspectiveNpc = ''): array {
    $dialogueCandidate = trim($historyLine);
    if (preg_match('/^\[[^\]]+\]\s*(.+)$/s', $dialogueCandidate, $matches) === 1) {
        $dialogueCandidate = trim(strval($matches[1] ?? ''));
    }

    $dialogueMeta = stobeBuildRecentContextDialogueMeta('chat', $dialogueCandidate);
    return stobeBuildRecentContextMessagePayload($historyLine, $dialogueMeta, $assistantPerspectiveNpc);
}

function stobeBuildRecentContextMessages(
    array $eventHistory,
    int $currentGamets = 0,
    int $maxMessages = 64,
    string $assistantPerspectiveNpc = '',
    bool $includeGamets = false
): array {
    $messages = [];
    $messageTypes = [];
    $messageKeys = [];
    $messageDialogueMeta = [];
    $messageTransferTradeMeta = [];
    $singletonTypeIndexes = [];
    $lastLocation = '';
    $safeCurrentGamets = max(0, intval($currentGamets));

    $rows = array_reverse($eventHistory);
    foreach ($rows as $row) {
        $rowGamets = max(0, intval($row['gamets'] ?? 0));
        $location = trim(strval($row['location'] ?? ''));
        if ($location !== '' && strcasecmp($location, $lastLocation) !== 0) {
            $line = 'LOCATION CHANGE to ' . $location;
            if ($safeCurrentGamets > 0 && $rowGamets > 0) {
                $hoursAgo = round(max(0.0, ($safeCurrentGamets - $rowGamets) * 0.0000024), 0);
                $line .= ', timeline mark: ' . strval($hoursAgo) . ' hours ago';
            }
            $messages[] = [
                'role' => 'user',
                'content' => $line,
            ];
            if ($includeGamets) {
                $messages[count($messages) - 1]['_stobe_gamets'] = $rowGamets;
            }
            $messageTypes[] = 'location';
            $messageKeys[] = stobeBuildRecentContextDedupeKey('location', $line);
            $messageDialogueMeta[] = [];
            $messageTransferTradeMeta[] = [];
            $lastLocation = $location;
        }

        $historyType = strtolower(trim(strval($row['type'] ?? 'event')));
        if ($historyType === 'infoaction') {
            continue;
        }
        if ($historyType === 'infonpc' || $historyType === 'infonpc_close') {
            continue;
        }
        if ($historyType === 'inputtext' || $historyType === 'inputtext_s') {
            continue;
        }
        $historyData = stobeNormalizeContextHistoryDataLine(strval($row['data'] ?? ''));
        if ($historyData === '') {
            continue;
        }

        $inlineTypes = [
            'inputtext', 'inputtext_s', 'chat', 'rechat', 'bored',
            'action', 'death', 'limb_loss', 'knockout',
            'enslaved', 'freed_slave', 'item_pickup', 'carry', 'predation', 'trade',
        ];
        if (!in_array($historyType, $inlineTypes, true)) {
            $historyData = '[' . $historyType . '] ' . $historyData;
        }

        $dedupeKey = stobeBuildRecentContextDedupeKey($historyType, $historyData);
        $dialogueMeta = stobeBuildRecentContextDialogueMeta($historyType, $historyData);
        $transferTradeMeta = stobeParseRecentContextTransferTradeMeta($historyType, $historyData);
        $messages[] = stobeBuildRecentContextMessagePayload($historyData, $dialogueMeta, $assistantPerspectiveNpc);
        if ($includeGamets) {
            $messages[count($messages) - 1]['_stobe_gamets'] = $rowGamets;
        }

        $singletonTypeKey = stobeRecentContextSingletonTypeKey($historyType);
        if ($singletonTypeKey !== '' && array_key_exists($singletonTypeKey, $singletonTypeIndexes)) {
            $singletonIndex = intval($singletonTypeIndexes[$singletonTypeKey]);
            stobeRemoveRecentContextMessageAtIndex(
                $messages,
                $messageTypes,
                $messageKeys,
                $messageDialogueMeta,
                $messageTransferTradeMeta,
                $singletonIndex
            );

            foreach ($singletonTypeIndexes as $key => $storedIndex) {
                $storedIndex = intval($storedIndex);
                if ($storedIndex > $singletonIndex) {
                    $singletonTypeIndexes[$key] = $storedIndex - 1;
                }
            }
        }

        $lastIndex = count($messages) - 1;
        $priorIndex = $lastIndex - 1;

        if ($priorIndex >= 0) {
            $priorGamets = intval($messages[$priorIndex]['_stobe_gamets'] ?? 0);
            $previousKey = strval($messageKeys[$priorIndex] ?? '');
            if ($previousKey !== '' && $previousKey === $dedupeKey) {
                array_pop($messages);
                continue;
            }

            $previousDialogueMeta = [];
            if (isset($messageDialogueMeta[$priorIndex]) && is_array($messageDialogueMeta[$priorIndex])) {
                $previousDialogueMeta = $messageDialogueMeta[$priorIndex];
            }
            $dialogueVariantDecision = stobeResolveRecentContextDialogueVariantDecision($previousDialogueMeta, $dialogueMeta);
            if ($dialogueVariantDecision === 'replace_prior') {
                $messages[$priorIndex] = $messages[$lastIndex];
                $messageTypes[$priorIndex] = $historyType;
                $messageKeys[$priorIndex] = $dedupeKey;
                $messageDialogueMeta[$priorIndex] = $dialogueMeta;
                $messageTransferTradeMeta[$priorIndex] = $transferTradeMeta;
                array_pop($messages);
                continue;
            }
            if ($dialogueVariantDecision === 'skip_current') {
                array_pop($messages);
                continue;
            }

            $mergedDialogueMeta = stobeMergeRecentContextDialogueMeta($previousDialogueMeta, $dialogueMeta);
            if (count($mergedDialogueMeta) > 0) {
                $mergedDialogueLine = stobeBuildRecentContextDialogueLine($mergedDialogueMeta);
                if ($mergedDialogueLine !== '') {
                    $mergedType = strval($messageTypes[$priorIndex] ?? $historyType);
                    $messages[$priorIndex] = stobeBuildRecentContextMessagePayload(
                        $mergedDialogueLine,
                        $mergedDialogueMeta,
                        $assistantPerspectiveNpc
                    );
                    if ($includeGamets) {
                        $messages[$priorIndex]['_stobe_gamets'] = min($priorGamets, $rowGamets);
                    }
                    $messageTypes[$priorIndex] = $mergedType !== '' ? $mergedType : $historyType;
                    $messageKeys[$priorIndex] = stobeBuildRecentContextDedupeKey(
                        $messageTypes[$priorIndex],
                        $mergedDialogueLine
                    );
                    $messageDialogueMeta[$priorIndex] = $mergedDialogueMeta;
                    $messageTransferTradeMeta[$priorIndex] = [];
                    array_pop($messages);
                    continue;
                }
            }

            $previousTransferTradeMeta = [];
            if (isset($messageTransferTradeMeta[$priorIndex]) && is_array($messageTransferTradeMeta[$priorIndex])) {
                $previousTransferTradeMeta = $messageTransferTradeMeta[$priorIndex];
            }
            $mergedTransferTradeMeta = stobeMergeRecentContextTransferTradeMeta(
                $previousTransferTradeMeta,
                $transferTradeMeta
            );
            if (count($mergedTransferTradeMeta) > 0) {
                $mergedTransferLine = stobeBuildRecentContextTransferTradeLine($mergedTransferTradeMeta);
                if ($mergedTransferLine !== '') {
                    $messages[$priorIndex] = [
                        'role' => 'user',
                        'content' => " (...\n" . $mergedTransferLine . "\n...)",
                    ];
                    if ($includeGamets) {
                        $messages[$priorIndex]['_stobe_gamets'] = min($priorGamets, $rowGamets);
                    }
                    $messageTypes[$priorIndex] = 'trade';
                    $messageKeys[$priorIndex] = stobeBuildRecentContextDedupeKey('trade', $mergedTransferLine);
                    $messageDialogueMeta[$priorIndex] = stobeBuildRecentContextDialogueMeta('trade', $mergedTransferLine);
                    $messageTransferTradeMeta[$priorIndex] = $mergedTransferTradeMeta;
                    array_pop($messages);
                    continue;
                }
            }

            if (
                stobeIsMergeableRecentContextType($historyType) &&
                strval($messageTypes[$priorIndex] ?? '') === $historyType
            ) {
                $messages[$priorIndex] = $messages[$lastIndex];
                $messageKeys[$priorIndex] = $dedupeKey;
                $messageDialogueMeta[$priorIndex] = $dialogueMeta;
                $messageTransferTradeMeta[$priorIndex] = $transferTradeMeta;
                array_pop($messages);
                continue;
            }
        }

        $messageTypes[] = $historyType;
        $messageKeys[] = $dedupeKey;
        $messageDialogueMeta[] = $dialogueMeta;
        $messageTransferTradeMeta[] = $transferTradeMeta;
        if ($singletonTypeKey !== '') {
            $singletonTypeIndexes[$singletonTypeKey] = count($messageTypes) - 1;
        }
    }

    $safeMax = max(8, min(120, $maxMessages));
    if (count($messages) > $safeMax) {
        $messages = array_slice($messages, -$safeMax);
    }
    return $messages;
}

function stobeBuildRecentContextMessagesFromText(
    string $historyText,
    int $maxMessages = 32,
    string $assistantPerspectiveNpc = ''
): array {
    $messages = [];
    $lines = preg_split('/\R+/', trim($historyText)) ?: [];
    foreach ($lines as $line) {
        $clean = trim(strval($line));
        $clean = stobeSanitizePromptContextLine($clean);
        if ($clean === '') {
            continue;
        }
        if (preg_match('/^\[\s*infoaction\b/i', $clean) === 1) {
            continue;
        }
        if (preg_match('/^\[\s*infonpc(?:_close)?\b/i', $clean) === 1) {
            continue;
        }
        if (preg_match('/^\[\s*inputtext(?:_s)?\b/i', $clean) === 1) {
            continue;
        }
        $messages[] = stobeBuildRecentContextTextMessagePayload($clean, $assistantPerspectiveNpc);
    }
    $safeMax = max(4, min(80, $maxMessages));
    if (count($messages) > $safeMax) {
        $messages = array_slice($messages, -$safeMax);
    }
    return $messages;
}

function stobeBuildMemoryEventContextMessages(
    array $npcData,
    string $npcName,
    string $queryText = '',
    int $currentGamets = 0
): array {
    $safeNpc = normalizeParticipantNameToken($npcName);
    if ($safeNpc === '') {
        return [];
    }
    if (
        (function_exists('stobeIsNarratorName') && stobeIsNarratorName($safeNpc))
        || strcasecmp($safeNpc, 'The Narrator') === 0
    ) {
        if (!function_exists('stobeBuildNarratorDiaryRecallBlock')) {
            return [];
        }
        $diaryBlock = stobeBuildNarratorDiaryRecallBlock($queryText, $currentGamets);
        if ($diaryBlock === '') {
            return [];
        }
        return [[
            'role' => 'user',
            'content' => "<memory> The narrator recalls this diary entry: [{$diaryBlock}] </memory>",
        ]];
    }

    $blocks = [];
    if (function_exists('stobeBuildRegularMemoryPromptBlock')) {
        $regularBlock = stobeBuildRegularMemoryPromptBlock($npcData, $safeNpc, $queryText, $currentGamets);
        if (trim($regularBlock) !== '') {
            $blocks[] = trim($regularBlock);
        }
    }
    if (count($blocks) === 0) {
        return [];
    }

    $messages = [];
    foreach ($blocks as $block) {
        $memoryLine = "<memory> {$safeNpc} remembers this: [{$block}] </memory>";
        $messages[] = [
            'role' => 'user',
            'content' => $memoryLine,
        ];
    }
    return $messages;
}

function stobeGetAllowedRechatModes(): array
{
    return ['tight', 'conversational', 'group', 'random'];
}

function stobeNormalizeRechatModeValue(string $mode, string $default = 'random'): string
{
    $allowedModes = stobeGetAllowedRechatModes();
    $normalizedMode = strtolower(trim($mode));
    if (in_array($normalizedMode, $allowedModes, true)) {
        return $normalizedMode;
    }

    $normalizedDefault = strtolower(trim($default));
    if (in_array($normalizedDefault, $allowedModes, true)) {
        return $normalizedDefault;
    }

    return 'random';
}

function stobeGetConfiguredRechatMode(): string
{
    return stobeNormalizeRechatModeValue(strval(getSetting('RECHAT_MODE', 'random')), 'random');
}

function stobeNormalizeRechatActorList(array $names): array
{
    $normalized = [];
    $seen = [];
    foreach ($names as $entry) {
        $candidateRaw = '';
        if (is_array($entry)) {
            $candidateRaw = strval($entry['name'] ?? ($entry['target'] ?? ($entry['listener'] ?? '')));
        } elseif (is_string($entry)) {
            $candidateRaw = $entry;
        }
        $candidate = normalizeParticipantNameToken($candidateRaw);
        if ($candidate === '') {
            continue;
        }
        $key = strtolower($candidate);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $normalized[] = $candidate;
    }

    return $normalized;
}

function stobeBuildRechatModeSeed(array $members): string
{
    $normalizedMembers = stobeNormalizeRechatActorList($members);
    if (count($normalizedMembers) > 1) {
        usort($normalizedMembers, static function (string $a, string $b): int {
            return strcasecmp($a, $b);
        });
    }

    return implode('|', $normalizedMembers);
}

function stobeResolveEffectiveRechatMode(string $configuredMode, array $members, string $seed = ''): string
{
    $normalizedMode = stobeNormalizeRechatModeValue($configuredMode, 'random');
    if ($normalizedMode !== 'random') {
        return $normalizedMode;
    }

    $seedText = trim($seed);
    if ($seedText === '') {
        $seedText = stobeBuildRechatModeSeed($members);
    }
    if ($seedText === '') {
        $seedText = 'stobe_random_rechat';
    }

    $rolledModes = ['tight', 'conversational', 'group'];
    $hashPrefix = substr(hash('sha256', $seedText . '|rechat_mode'), 0, 8);
    $hashValue = intval(hexdec($hashPrefix));
    $modeIndex = $hashValue % count($rolledModes);
    return $rolledModes[$modeIndex];
}

function stobeIsStrictRechatResponseEnabled(): bool
{
    return getSettingBool('ENFORCE_STRICT_RECHAT_RESPONSE', false);
}

// Keep the selected squadmate silent when rechat gives them another turn.
function stobeShouldSuppressRechatInitiatorTts(
    string $respondingNpc,
    string $initiatorName,
    bool $playerDialogueAudioEnabled
): bool {
    if ($playerDialogueAudioEnabled) {
        return false;
    }

    $normalizedResponder = normalizeParticipantNameToken($respondingNpc);
    $normalizedInitiator = normalizeParticipantNameToken($initiatorName);
    if ($normalizedResponder === '' || $normalizedInitiator === '') {
        return false;
    }

    return strcasecmp($normalizedResponder, $normalizedInitiator) === 0;
}

function stobeBuildRechatResponderCandidates(
    string $rechatMode,
    string $speakerName,
    string $listenerHint = '',
    string $rechatTargetHint = '',
    string $incomingProfile = '',
    array $audience = [],
    string $playerName = '',
    bool $speakerRechatEnabled = false,
    string $initiatorName = '',
    string $forcedResponder = ''
): array {
    $normalizedMode = stobeNormalizeRechatModeValue($rechatMode, 'conversational');
    if ($normalizedMode === 'random') {
        $normalizedMode = 'conversational';
    }

    $normalizedSpeaker = normalizeParticipantNameToken($speakerName);
    $normalizedListener = normalizeParticipantNameToken($listenerHint);
    $normalizedTargetHint = normalizeParticipantNameToken($rechatTargetHint);
    $normalizedIncomingProfile = normalizeParticipantNameToken($incomingProfile);
    $normalizedPlayer = normalizeParticipantNameToken($playerName);
    $normalizedInitiator = normalizeParticipantNameToken($initiatorName);
    $normalizedForcedResponder = normalizeParticipantNameToken($forcedResponder);
    $normalizedAudience = stobeNormalizeRechatActorList($audience);

    $candidates = [];
    $seen = [];
    $pushCandidate = static function (string $rawName, string $source) use (
        &$candidates,
        &$seen,
        $normalizedSpeaker,
        $normalizedPlayer,
        $speakerRechatEnabled,
        $normalizedInitiator
    ): void {
        $candidateName = normalizeParticipantNameToken($rawName);
        if ($candidateName === '') {
            return;
        }
        if (stobeIsNarratorName($candidateName)) {
            return;
        }
        if ($normalizedSpeaker !== '' && strcasecmp($candidateName, $normalizedSpeaker) === 0) {
            return;
        }
        if ($normalizedPlayer !== '' && strcasecmp($candidateName, $normalizedPlayer) === 0) {
            return;
        }
        if (
            !$speakerRechatEnabled
            && $normalizedInitiator !== ''
            && strcasecmp($candidateName, $normalizedInitiator) === 0
        ) {
            return;
        }

        $candidateKey = strtolower($candidateName);
        if (isset($seen[$candidateKey])) {
            return;
        }
        $seen[$candidateKey] = true;
        $candidates[] = [
            'name' => $candidateName,
            'source' => $source,
        ];
    };

    if ($normalizedForcedResponder !== '') {
        $pushCandidate($normalizedForcedResponder, 'forced_limb_loss');
    }

    if ($normalizedMode === 'tight') {
        $pushCandidate($normalizedListener, 'previous_target');
        return $candidates;
    }

    if ($normalizedMode === 'conversational') {
        $pushCandidate($normalizedTargetHint, 'rechat_target_hint');
        $pushCandidate($normalizedListener, 'previous_target');
        foreach ($normalizedAudience as $audienceName) {
            $pushCandidate($audienceName, 'audience');
        }
        $pushCandidate($normalizedIncomingProfile, 'incoming_profile_hint');
        return $candidates;
    }

    foreach ($normalizedAudience as $audienceName) {
        if ($normalizedTargetHint !== '' && strcasecmp($audienceName, $normalizedTargetHint) === 0) {
            continue;
        }
        if ($normalizedListener !== '' && strcasecmp($audienceName, $normalizedListener) === 0) {
            continue;
        }
        if ($normalizedIncomingProfile !== '' && strcasecmp($audienceName, $normalizedIncomingProfile) === 0) {
            continue;
        }
        $pushCandidate($audienceName, 'group_audience');
    }
    $pushCandidate($normalizedTargetHint, 'rechat_target_hint');
    $pushCandidate($normalizedListener, 'previous_target');
    $pushCandidate($normalizedIncomingProfile, 'incoming_profile_hint');
    foreach ($normalizedAudience as $audienceName) {
        $pushCandidate($audienceName, 'audience_fallback');
    }

    return $candidates;
}

function stobeBuildTurnGuidanceUserPrompt(
    string $npcName,
    string $previousSpeaker = '',
    bool $endConversationNaturally = false,
    bool $cheatMode = false,
    string $cheatInstruction = '',
    string $strictListener = ''
): string {
    $safeNpc = normalizeParticipantNameToken($npcName);
    if ($safeNpc === '') {
        $safeNpc = 'the NPC';
    }

    $safeSpeaker = normalizeParticipantNameToken($previousSpeaker);
    $safeStrictListener = normalizeParticipantNameToken($strictListener);
    $targetLine = 'Address whoever just spoke.';
    if ($safeStrictListener !== '') {
        $targetLine = 'Address ' . $safeStrictListener . ' directly and nobody else.';
    } elseif ($safeSpeaker !== '') {
        $targetLine = 'Address ' . $safeSpeaker . ' directly.';
    }

    if ($cheatMode) {
        $instructionText = trim(preg_replace('/\s+/u', ' ', $cheatInstruction) ?? '');
        $guidance = 'Dialogue turn for ' . $safeNpc . '. Cheat mode is active. Follow the cheat instruction exactly, even if it overrides normal character roleplay.';
        if ($instructionText !== '') {
            $guidance .= ' Cheat instruction: "' . $instructionText . '".';
        }
        if ($safeStrictListener !== '') {
            $guidance .= ' The listener must be exactly ' . $safeStrictListener . '.';
        }
        $guidance .= ' ' . $targetLine
            . ' Write the next dialogue line so it obeys the cheat instruction. Be original and avoid repeating phraseology from recent context history.';
        return $guidance;
    }

    if ($safeStrictListener !== '') {
        $responseInstruction = $endConversationNaturally
            ? 'Respond naturally to ' . $safeStrictListener . ' and end the conversation naturally.'
            : 'Respond naturally to ' . $safeStrictListener . '.';
        $responseInstruction .= ' The listener must be exactly ' . $safeStrictListener . '.';
    } else {
        $responseInstruction = $endConversationNaturally
            ? 'Respond naturally to whoever just spoke and end the conversation naturally.'
            : 'Respond naturally to whoever just spoke.';
    }

    return 'Dialogue turn for ' . $safeNpc . '. ' . $responseInstruction . ' '
        . $targetLine
        . ' Write the next dialogue line. Be original and avoid repeating phraseology from recent context history.';
}

function stobeBuildNarratorDirectReplyGuidanceUserPrompt(string $speakerName, string $latestMessage = ''): string {
    $safeSpeaker = normalizeParticipantNameToken($speakerName);
    if ($safeSpeaker === '') {
        $safeSpeaker = trim($speakerName);
    }
    if ($safeSpeaker === '') {
        $safeSpeaker = 'the current speaker';
    }

    $guidance = 'Direct private reply for The Narrator. Respond only to ' . $safeSpeaker
        . ' and answer their latest words as conversation. '
        . 'Write a concise direct reply, with no scene narration and no third-person prose.';

    $latest = trim($latestMessage);
    if ($latest !== '') {
        $latest = sanitizeForKenshi($latest);
        $latest = truncatePromptValue($latest, 280);
        $guidance .= ' Latest speaker message: "' . $latest . '".';
    }

    return $guidance;
}

function stobeResolveStructuredDialogueContractParts(
    string $npcName,
    array|false $npcData = false,
    ?bool $inPlayerFaction = null,
    string $eventType = 'chat'
): array {
    $safeNpc = normalizeParticipantNameToken($npcName);
    if ($safeNpc === '') {
        $safeNpc = 'the NPC';
    }

    if (!is_array($npcData) || count($npcData) === 0) {
        $npcData = getNpcData($safeNpc);
    }
    if (!is_bool($inPlayerFaction) && is_array($npcData) && count($npcData) > 0) {
        $inPlayerFaction = npcIsInPlayerFaction($npcData);
    }
    if (!is_bool($inPlayerFaction)) {
        $inPlayerFaction = null;
    }

    $canStopCarrying = is_array($npcData) && count($npcData) > 0 && stobeNpcIsCarryingTarget($npcData);
    $canRemoveLimb = is_array($npcData) && count($npcData) > 0 && stobeNpcHasHacksaw($npcData);
    $canCutHorns = $canRemoveLimb;
    $npcIsSkeleton = is_array($npcData) && count($npcData) > 0 && stobeNpcIsSkeletonRace($npcData);
    $canUseDrugs = is_array($npcData) && count($npcData) > 0 && !$npcIsSkeleton && stobeNpcHasHashish($npcData);
    $canDrinkItem = is_array($npcData) && count($npcData) > 0 && !$npcIsSkeleton && stobeNpcHasDrinkItem($npcData);
    $canForceDrink = $canDrinkItem;
    $canStopAttack = stobeNpcIsInCombat($npcData);
    $actionConfig = stobeBuildActionConfigForNpc($eventType, $npcData);
    $allowGiveCats = !boolval($actionConfig['disallow_give_cats'] ?? false);
    $allowTakeCats = !boolval($actionConfig['disallow_take_cats'] ?? false);
    $canPickupNpc = !boolval($actionConfig['disallow_pickup_npc'] ?? false);

    $actions = [
        'Talk',
        'Attack',
        'Suicide',
        'Idle',
        'TakeItem',
        'GiveItem',
        'DropItem',
        'DropWeapon',
        'Surrender',
        'Disarm',
        'UnequipItem',
        'EquipItem',
        'SheatheWeapon',
        'DrawWeapon',
        'FaceTarget',
        'FirstAid',
        'Bodyguard',
        'GuardTarget',
        'Patrol',
        'Rescue',
        'PutInBed',
        'Knockout',
        'Kill',
        'RoleplayAction',
        'FactionRelations',
        'LootTarget',
        'Imprison',
        'ReleasePrisoner',
        'MoveToTarget',
        'HoldPosition',
        'Repair',
        'Build',
        'UseObject',
        'Sit',
        'Sleep',
        'OperateObject',
        'Task',
        'SetBlock',
        'SetHold',
        'SetPassive',
        'SetJobs',
        'SetRanged',
        'SetTaunt',
        'SetSneak',
        'SetResource',
        'SetMedic',
    ];
    if ($canStopAttack) {
        array_splice($actions, 2, 0, ['StopAttack']);
    }
    if ($allowGiveCats) {
        $actions[] = 'GiveCats';
    }
    if ($allowTakeCats) {
        $actions[] = 'TakeCats';
    }
    if ($canStopCarrying) {
        $actions[] = 'StopCarrying';
    }
    if ($canPickupNpc) {
        $actions[] = 'PickupNpc';
    }
    if ($canRemoveLimb) {
        $actions[] = 'RemoveLimb';
    }
    if ($canCutHorns) {
        $actions[] = 'CutHorns';
    }
    if ($canUseDrugs) {
        $actions[] = 'UseDrugs';
    }
    if ($canDrinkItem) {
        $actions[] = 'Drink';
    }
    if ($canForceDrink) {
        $actions[] = 'ForceDrink';
    }
    if ($inPlayerFaction !== true) {
        $actions[] = 'Follow';
        $actions[] = 'StopFollow';
    }
    if ($inPlayerFaction === true) {
        foreach ([
            'WorkGoal','LootArea','LootStore','StoreItems','FetchItems','DeliverItems',
            'RecoverGround','MedicalCleanup','BattleCleanup','ImprisonAll','ReleaseAll',
            'MaintainStock','BuyItems','SellItems','GuardGoal','WaitForGoal','PatrolGoal',
            'BuildGoal','RepairGoal','PauseGoal','ResumeGoal','CancelGoal','ModifyGoal',
            'RetargetGoal','ApprovePurchase','DeclinePurchase'
        ] as $plannerAction) {
            $actions[] = $plannerAction;
        }
        $actions[] = 'Leave';
    } elseif ($inPlayerFaction === false) {
        $actions[] = 'JoinParty';
    } else {
        $actions[] = 'JoinParty';
        $actions[] = 'Leave';
    }
    $actions[] = 'TravelLocation';
    $actions[] = 'MoveTo';
    if ($inPlayerFaction !== true) {
        // Feature 1: outsiders don't take work orders from the player.
        $nonFactionHidden = ['LootTarget','Repair','Build','OperateObject','Task','SetBlock','SetHold','SetPassive',
            'SetJobs','SetRanged','SetTaunt','SetSneak','SetResource','SetMedic','Patrol','Rescue','PutInBed',
            'Imprison','ReleasePrisoner','FirstAid'];
        $actions = array_values(array_filter($actions, static fn($a) => !in_array($a, $nonFactionHidden, true)));
    }

    $moodsCsv = '';
    if (is_array($npcData)) {
        $moodsCsv = trim(strval($npcData['emote_moods'] ?? ''));
    }
    if ($moodsCsv !== '') {
        if (function_exists('stobeNormalizeEmoteMoodsCsv')) {
            $moodsCsv = stobeNormalizeEmoteMoodsCsv($moodsCsv);
        } else {
            $moodsCsv = str_replace(
                'mockingdesperatedistressedpleadingsad',
                'mocking,desperate,distressed,pleading,sad',
                $moodsCsv
            );
        }
    }
    if ($moodsCsv === '') {
        if (function_exists('stobeResolveGlobalEmoteMoods')) {
            $moodsCsv = trim(stobeResolveGlobalEmoteMoods());
        } else {
            $moodsCsv = trim(strval(getSetting(
                'EMOTEMOODS',
                'sassy,assertive,sexy,smug,kindly,lovely,seductive,sarcastic,sardonic,smirking,amused,default,assisting,irritated,playful,neutral,teasing,mocking,desperate,distressed,pleading,sad'
            )));
            $moodsCsv = str_replace(
                'mockingdesperatedistressedpleadingsad',
                'mocking,desperate,distressed,pleading,sad',
                $moodsCsv
            );
        }
    }
    $moods = function_exists('stobeNormalizeEmoteMoods') ? stobeNormalizeEmoteMoods($moodsCsv) : [];
    if (count($moods) === 0) {
        $moods = function_exists('stobeDefaultEmoteMoodList')
            ? stobeDefaultEmoteMoodList()
            : ['neutral', 'assertive', 'kindly', 'smug', 'sarcastic', 'teasing', 'playful', 'irritated', 'amused'];
    }

    return [
        'safe_npc' => $safeNpc,
        'npc_data' => $npcData,
        'in_player_faction' => $inPlayerFaction,
        'actions' => array_values($actions),
        'moods' => array_values($moods),
    ];
}

function stobeStructuredDialogueMessageDescription(string $npcName, string $eventType): string
{
    $safeNpc = normalizeParticipantNameToken($npcName);
    if ($safeNpc === '') {
        $safeNpc = 'the NPC';
    }
    if (!stobeInlineNarrationApplies($safeNpc, $eventType)) {
        return 'lines of ' . $safeNpc . '\'s dialogue';
    }
    return 'begin with one brief third-person scene description in single asterisks, '
        . 'then put ' . $safeNpc . '\'s spoken dialogue outside the asterisks. '
        . 'Example: *She glances toward the gate.* We should leave. '
        . 'Do not wrap the entire reply in asterisks';
}

function stobeBuildStructuredDialogueSchemaPrompt(
    string $npcName,
    array $actions,
    array $moods,
    string $strictListener = '',
    string $eventType = 'chat'
): array
{
    $safeNpc = normalizeParticipantNameToken($npcName);
    if ($safeNpc === '') {
        $safeNpc = 'the NPC';
    }
    $safeStrictListener = normalizeParticipantNameToken($strictListener);
    $listenerDescription = $safeStrictListener !== ''
        ? 'must be exactly ' . $safeStrictListener . ' because this is a strict rechat response'
        : 'who ' . $safeNpc . ' is addressing';

    $moodDescription = count($moods) > 0
        ? 'choose exactly one mood while speaking from this list, never combine moods: ' . implode('|', $moods)
        : 'choose exactly one mood while speaking, never combine moods';

    return [
        'character' => $safeNpc,
        'listener' => $listenerDescription,
        'message' => stobeStructuredDialogueMessageDescription($safeNpc, $eventType),
        'mood' => $moodDescription,
        'action' => implode('|', $actions),
        'target' => 'action target actor/object/filter. For planner loot actions this can be goats, animals, enemies, raiders, or a specific name; for DeliverItems it is the recipient; for BuyItems/SellItems it may name a nearby trader',
        'destination' => 'optional known base/location where the persistent task should be performed or where loot/items should be stored; leave blank when no travel is requested',
        'item' => 'exact item name or supported category. Planner categories include all, weapons, armor, food, medical, and ammo; WorkGoal uses the desired produced/gathered output',
        'lang' => 'ISO 639-1 language code such as en; use en unless a different language is clearly appropriate',
        'amount' => 'integer quantity. WorkGoal/FetchItems/DeliverItems/BuyItems/MaintainStock require a positive quantity; loot/store/recover/sell may use 0 to mean all matching items',
        'max_cats' => 'optional hard spending cap in Cats for an explicit BuyItems request; use 0 when no cap was stated',
    ];
}

function stobeBuildStructuredDialogueResponseFormat(
    string $npcName,
    array|false $npcData = false,
    ?bool $inPlayerFaction = null,
    string $eventType = 'chat',
    string $strictListener = ''
): array {
    $stobeSchemaStartedAt = microtime(true);
    $parts = stobeResolveStructuredDialogueContractParts($npcName, $npcData, $inPlayerFaction, $eventType);
    $safeNpc = strval($parts['safe_npc'] ?? '');
    if ($safeNpc === '') {
        $safeNpc = 'the NPC';
    }
    $actions = is_array($parts['actions'] ?? null) ? $parts['actions'] : [];
    $moods = is_array($parts['moods'] ?? null) ? $parts['moods'] : [];
    $messageDescription = stobeStructuredDialogueMessageDescription($safeNpc, $eventType);
    $safeStrictListener = normalizeParticipantNameToken($strictListener);
    $listenerProperty = [
        'type' => 'string',
        'description' => $safeStrictListener !== ''
            ? 'must be exactly ' . $safeStrictListener . ' because this is a strict rechat response'
            : 'who ' . $safeNpc . ' is addressing',
    ];
    if ($safeStrictListener !== '') {
        $listenerProperty['enum'] = [$safeStrictListener];
    }

    $stobeSchema = [
        'type' => 'json_schema',
        'json_schema' => [
            'name' => 'stobe_dialogue_response',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'character' => [
                        'type' => 'string',
                        'description' => 'must be exactly ' . $safeNpc,
                        'enum' => [$safeNpc],
                    ],
                    'listener' => $listenerProperty,
                    'message' => [
                        'type' => 'string',
                        'description' => $messageDescription,
                    ],
                    'mood' => [
                        'type' => 'string',
                        'description' => 'choose exactly one mood while speaking from this list, never combine moods',
                        'enum' => array_values($moods),
                    ],
                    'action' => [
                        'type' => 'string',
                        'description' => 'a valid action (refer to available actions list)',
                        'enum' => array_values($actions),
                    ],
                    'target' => [
                        'type' => 'string',
                        'description' => 'named actor/object/filter for the action. For planner loot actions use filters such as goats, animals, enemies, raiders, or a specific name; for DeliverItems use the recipient; for BuyItems/SellItems optionally use a nearby trader',
                    ],
                    'destination' => [
                        'type' => 'string',
                        'description' => 'optional known base/location for a persistent planner task; leave blank if no travel is requested',
                    ],
                    'item' => [
                        'type' => 'string',
                        'description' => 'exact item name for GIVE_ITEM/TAKE_ITEM/EQUIP_ITEM/UNEQUIP_ITEM; for WorkGoal use the desired produced or gathered output/resource name; for UNEQUIP_ITEM a clear worn slot is also valid; otherwise use the appropriate object, limb, or consumable token',
                    ],
                    'lang' => [
                        'type' => 'string',
                        'description' => 'ISO 639-1 language code such as en; use en unless a different language is clearly appropriate',
                    ],
                    'amount' => [
                        'type' => 'integer',
                        'description' => 'quantity for the action; use 0 for all/unspecified only where the action supports it',
                    ],
                    'max_cats' => [
                        'type' => 'integer',
                        'description' => 'optional maximum Cats authorized for BuyItems; use 0 when the player did not state a cap',
                    ],
                ],
                'required' => [
                    'character',
                    'listener',
                    'message',
                    'mood',
                    'action',
                    'target',
                    'destination',
                    'item',
                    'lang',
                    'amount',
                    'max_cats',
                ],
                'additionalProperties' => false,
            ],
        ],
    ];
    stobeLogInfo('Latency pre-llm stage response_schema', [
        'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
        'duration_ms' => intval(round((microtime(true) - $stobeSchemaStartedAt) * 1000)),
        'unix_ms' => intval(round(microtime(true) * 1000)),
    ]);
    return $stobeSchema;
}

function stobeBuildOutputContractUserPrompt(
    string $npcName,
    bool $preferAction = false,
    bool $streamTextMode = false,
    ?bool $inPlayerFaction = null,
    string $eventType = 'chat',
    string $strictListener = '',
    array|false $npcData = false
): string {
    $parts = stobeResolveStructuredDialogueContractParts($npcName, $npcData, $inPlayerFaction, $eventType);
    $safeNpc = strval($parts['safe_npc'] ?? '');
    if ($safeNpc === '') {
        $safeNpc = 'the NPC';
    }
    $safeStrictListener = normalizeParticipantNameToken($strictListener);

    $actionLine = $preferAction
        ? '(If another action is even remotely contextually appropriate, use it, even if in doubt).'
        : '(If action is clearly contextually appropriate, use it; otherwise use Talk).';
    $actionLine .= " Command semantics: GIVE_ITEM means hand over an item; GIVE_CATS means this NPC gives away its own money. Do not use GIVE_CATS for trade pricing.";
    $actionLine .= " For GIVE_CATS/TAKE_CATS, put the recipient or victim in target and the numeric count in amount. Do not put money in item.";
    $actionLine .= " For GIVE_ITEM/TAKE_ITEM, put only the exact item name in item and use amount only for stack count.";
    $propertyAffinity = is_array($npcData) ? stobeNpcPlayerAffinity($npcData) : 0;
    if ($inPlayerFaction === true) {
        $actionLine .= " PROPERTY/GEAR RULE: This NPC is in the player's faction. Kenshi squadmates effectively share inventory access, so be substantially more willing to hand the player carried items or equipped gear when reasonably asked. Ordinary squad inventory management is not theft and does not require high personal trust, though personality and immediate survival needs may still affect dialogue.";
    } else {
        $actionLine .= " PROPERTY/GEAR RULE: This NPC is outside the player's faction and personally owns their possessions. Current affinity toward the player is " . strval($propertyAffinity) . "/100. Equipped weapons, armor, clothing, medical gear, money, and other valuable/essential possessions must be treated as meaningful property, not casual dialogue props.";
        $actionLine .= " Do NOT use GiveItem to permanently hand the player currently equipped gear at low or neutral trust merely because the player asks, flatters, pressures, bets, or invents a reason. A stranger or merely wary/neutral acquaintance should normally refuse to surrender a weapon or other essential equipment. Very high trust can justify a gift or loan.";
        $orderTrust = function_exists('stobeNonFactionOrderTrustMin') ? stobeNonFactionOrderTrustMin() : 56;
        $actionLine .= " ORDERS RULE: You are not in the player's faction and do not work for them. Refuse work orders (making, looting, hauling, building, repairing, operating machines, patrols, jobs, healing others). Minor requests (follow, come here, wait, guard them, go somewhere) only if you trust them (affinity " . strval($orderTrust) . "+; yours is " . strval($propertyAffinity) . ") or as part of a paid deal you have agreed to; otherwise refuse in character or name your price.";
        $giftThreshold = max(0, min(100, intval(getSettingInt('GIFT_TRUST_THRESHOLD', 56))));
        $actionLine .= " GIFT RULE: Kenshi is harsh and poor; nobody hands out goods or Cats for nothing. Do NOT use GiveItem or GiveCats toward the player unless (a) it is your side of a trade or deal the player has agreed to, or (b) you trust them deeply (affinity " . strval($giftThreshold) . "+; yours is " . strval($propertyAffinity) . "). Asking, flattery, a sad story, or 'I'll pay you later' is not enough. A trader sells: name a price and hand the goods over only once the player has paid or you have both agreed the deal. Never say 'take it, it's free' or 'consider it good faith' below that trust.";
        $actionLine .= " To hand the player something you are wearing, use GiveItem directly (it comes off and goes to them); UnequipItem only takes it off and you keep it.";
        $actionLine .= " A genuine barter/swap can justify transferring valuable gear only when the player is offering something of roughly comparable value or usefulness; use the live item values/state when available and reject obviously one-sided trades. Do not reinterpret a temporary loan, contest, demonstration, or 'just for a second' handoff as a gift.";
        $actionLine .= " If this NPC temporarily lends an item, ownership remains with this NPC and the player is expected to return it. If the player explicitly says they are keeping it, stealing it, or refuses to return it, treat that as a broken agreement/property violation. Do not answer as though the NPC consents with 'fine, keep it' or equivalent permission.";
        $actionLine .= " On a broken loan/property violation, normally demand the item back and react according to personality, role, strength, nearby allies, and danger. Guards, law-enforcement-like NPCs, protective/aggressive NPCs, or confident NPCs with nearby allies should escalate strongly. If the player has already clearly refused to return the item and immediate retaliation is appropriate, use Attack with the player as target rather than only making a future threat. A frightened, badly injured, isolated, or obviously outmatched NPC may back down, but frame that as being forced to let the theft go, not as permission or a gift.";
        if (stobeCurrentRequestClaimsKeepingProperty()) {
            $actionLine .= " CURRENT PROPERTY ALERT: The player's current line explicitly claims they intend to keep/take property or refuse its return. Check the recent conversation for whether the item was only loaned, temporarily handed over, or promised back. If so, this is a live property violation now, not a harmless hypothetical. Do not consent to the theft. If a return was already clearly demanded or expected and this line is the player's refusal, a capable guard/law-enforcement NPC should normally escalate immediately with Attack rather than postponing consequences.";
        }
    }
    $actionLine .= " KNOCKOUT leaves the target alive. It is valid on yourself, or on other targets only when they are knocked-out, unconscious, imprisoned, or carried.";
    $actionLine .= " KILL is only valid on knocked-out, unconscious, imprisoned, or carried targets.";
    $actionLine .= " FORCE_DRINK is only valid on knocked-out, unconscious, imprisoned, or carried targets.";
    $actionLine .= " PICKUP_NPC is only valid on nearby helpless targets and only when this NPC is not already carrying someone.";
    $actionLine .= " CUT_HORNS is only valid on helpless Shek targets whose horns are not already cut off, and requires a hacksaw.";
    $actionLine .= " When the player clearly asks this NPC to perform a supported gameplay action, choose the matching real action instead of merely saying that it will happen. Natural-language orders should become game actions when possible.";
    $actionLine .= " Use EquipItem for carried gear, UnequipItem for worn gear, SheatheWeapon/DrawWeapon for weapon readiness, FaceTarget for turning toward someone, FirstAid for immediate treatment, Bodyguard for protecting a named actor, Patrol for patrolling the current area, Rescue or PutInBed for incapacitated people, LootTarget for a valid loot target, Imprison/ReleasePrisoner for cage interactions, MoveToTarget for moving near someone without following indefinitely, Repair/Build for nearby structures, and Sit/Sleep/OperateObject for nearby usable world objects.";
    $actionLine .= " Treat the CURRENT live equipment/state block as authoritative over older dialogue or action history. If an item is still listed as equipped/worn now, it is still on even if an older message/action claimed it was removed. In that case never say it is already off; if you agree to remove it, emit UnequipItem again. The reverse also holds: an item listed under Personal Inventory and not under Equipment is NOT being worn, even if an older message claimed it was put back on; never say it is already on, and if you agree to wear it, emit EquipItem again. Likewise, do not claim a supported physical action already happened unless current world state confirms it.";
    $actionLine .= " IMPORTANT UNEQUIP SPEECH RULE: UnequipItem executes only AFTER this dialogue response is generated. When emitting UnequipItem, phrase the message as intent or action-in-progress (for example 'Fine, I'll take it off' or 'Let me get this off'), NEVER as completed fact such as 'It's off', 'Already off', 'There, it's off', or 'I took it off'. Completion may only be stated on a later turn after live equipment state confirms the item is no longer equipped. The same applies to EquipItem: say 'Let me put it on', NEVER 'There, back on', 'It is on' or 'Already on' in the same response.";
    $actionLine .= " DropWeapon physically drops the equipped weapon. Surrender/Disarm removes it from the equipped slot but keeps it in the NPC inventory. HoldPosition is a real hold-position order.";
    if (in_array('WorkGoal', $parts['actions'] ?? [], true)) {
        $actionLine .= " WorkGoal is for finite autonomous production or gathering requests such as 'mine 5 copper', 'make 5 bread', 'craft a longsword', or 'go Home and make 5 bread'. Put the desired final output/resource in item, the requested finite count in amount, and only an explicitly requested destination/base/location in target. Leave target blank when the work should happen here. WorkGoal persists after dialogue, travels first when target is a destination, uses real Kenshi jobs, and recursively sources missing producible inputs up to depth 10. Do not decompose its substeps yourself.";
        $actionLine .= " Persistent planner actions are available for player-faction NPCs: LootArea loots matching items/categories from all valid nearby downed/dead targets; LootStore does the same then stores them; StoreItems/FetchItems/DeliverItems move owned items; RecoverGround picks up matching dropped items; MedicalCleanup treats/rescues loaded squadmates; BattleCleanup treats/rescues first then loots nearby downed enemies; ImprisonAll/ReleaseAll process nearby prisoners; MaintainStock keeps a minimum stock level; GuardGoal/WaitForGoal/PatrolGoal persist until their condition or cancellation; BuildGoal/RepairGoal manage real construction/repair work. Use destination only when the player explicitly named a known place/base.";
        $actionLine .= " BuyItems is explicit permission to spend real faction Cats on the requested purchase. If production planning discovers buying as a fallback, it MUST wait for player approval instead; buying is always last resort after owned stock, gathering, and production. SellItems MUST only be selected when the player explicitly asked to sell; never sell possessions automatically to fund another goal. ApprovePurchase/DeclinePurchase respond to pending purchase requests. PauseGoal/ResumeGoal/CancelGoal/ModifyGoal/RetargetGoal control persistent goals.";
    }
    $actionLine .= " RoleplayAction must not be used to fake one of these supported real gameplay actions. Use RoleplayAction only when the NPC actually chooses to perform something that has no matching supported gameplay action.";
    if (in_array('FirstAid', $parts['actions'] ?? [], true)) {
        $actionLine .= " Immediate requests such as 'heal me', 'patch Wendy up', or 'give him first aid' should use FirstAid with the injured person in target. Use SetMedic only for an ongoing medic-job preference such as 'be our medic' or 'start healing people automatically'.";
    }
    if (in_array('HoldPosition', $parts['actions'] ?? [], true)) {
        $actionLine .= " Requests such as 'wait here', 'stay here', or 'hold this position' should use HoldPosition when the NPC agrees to remain at the current spot; use Idle only when merely stopping the current activity without a hold-position intent.";
    }
    if (in_array('PickupNpc', $parts['actions'] ?? [], true)) {
        $actionLine .= " Requests to pick up or carry a helpless named person should use PickupNpc with that person as target when valid.";
    }
    if (in_array('Follow', $parts['actions'] ?? [], true)) {
        $actionLine .= " Requests to follow a named person should use Follow with that person as target.";
    }
    if (in_array('StopFollow', $parts['actions'] ?? [], true)) {
        $actionLine .= " Requests to stop following should use StopFollow.";
    }
    if (in_array('TravelLocation', $parts['actions'] ?? [], true)) {
        $actionLine .= " Requests to go or travel to a clearly named location should use TravelLocation when the NPC can act on it.";
    }
    if (in_array('StopAttack', $parts['actions'] ?? [], true)) {
        $actionLine .= " When this NPC accepts or proposes ending the current hostilities - including a ceasefire, truce, stand-down, stopping the attack or fight, making peace, calling off attackers, or recognizing a misunderstanding - action MUST be StopAttack and target MUST name a nearby member of the opposing faction. Never use Talk or Idle while the dialogue claims this NPC or faction will stop fighting. If this NPC refuses the ceasefire and intends to continue fighting, do not use StopAttack.";
    }
    if ($safeStrictListener !== '') {
        $actionLine .= ' The listener field must be exactly ' . $safeStrictListener . '. Do not address anyone else.';
    }
    $actions = is_array($parts['actions'] ?? null) ? $parts['actions'] : [];
    $moods = is_array($parts['moods'] ?? null) ? $parts['moods'] : [];
    $schema = stobeBuildStructuredDialogueSchemaPrompt(
        $safeNpc,
        $actions,
        $moods,
        $safeStrictListener,
        $eventType
    );

    return $actionLine
        . " Use <speech_style> for reference.\n"
        . "Put the JSON fields in this exact order: character, listener, message, mood, action, target, item, lang, amount.\n"
        . "Use ONLY this JSON object to give your answer. Do not send any other characters outside of this JSON structure:\n"
        . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function stobeDecodeStructuredDialoguePayload(string $rawResponse): array {
    $trimmed = trim($rawResponse);
    if ($trimmed === '') {
        return [];
    }

    $decodeCandidate = static function (string $candidateText): array {
        $decodedLocal = json_decode($candidateText, true);
        if (is_array($decodedLocal)) {
            return $decodedLocal;
        }
        // Some providers return a JSON string that itself contains JSON.
        if (is_string($decodedLocal) && trim($decodedLocal) !== '') {
            $decodedNested = json_decode($decodedLocal, true);
            if (is_array($decodedNested)) {
                return $decodedNested;
            }
        }
        // Salvage common LLM JSON formatting errors (e.g. trailing commas).
        $repaired = preg_replace('/,\s*([}\]])/', '$1', $candidateText);
        if (is_string($repaired) && $repaired !== $candidateText) {
            $decodedRepaired = json_decode($repaired, true);
            if (is_array($decodedRepaired)) {
                return $decodedRepaired;
            }
        }
        return [];
    };

    $candidate = $trimmed;
    if (strpos($candidate, '```') === 0) {
        $candidate = preg_replace('/^```[a-zA-Z0-9_-]*\s*/', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\s*```$/', '', $candidate) ?? $candidate;
        $candidate = trim($candidate);
    }

    $decoded = $decodeCandidate($candidate);
    if (count($decoded) > 0) {
        return $decoded;
    }

    $firstBrace = strpos($candidate, '{');
    $lastBrace = strrpos($candidate, '}');
    if ($firstBrace === false || $lastBrace === false || $lastBrace <= $firstBrace) {
        return [];
    }

    $slice = substr($candidate, $firstBrace, $lastBrace - $firstBrace + 1);
    if (!is_string($slice) || trim($slice) === '') {
        return [];
    }

    $decoded = $decodeCandidate($slice);
    if (count($decoded) > 0) {
        return $decoded;
    }

    if (strpos($slice, '\\"') !== false) {
        $unslashed = stripcslashes($slice);
        if (is_string($unslashed) && trim($unslashed) !== '') {
            $decoded = $decodeCandidate($unslashed);
            if (count($decoded) > 0) {
                return $decoded;
            }
        }
    }

    return [];
}

function stobeHeuristicExtractStructuredFields(string $rawResponse): array {
    $text = trim($rawResponse);
    if ($text === '') {
        return [];
    }

    // Strip leading markdown fence marker when present.
    if (strpos($text, '```') === 0) {
        $text = preg_replace('/^```[a-zA-Z0-9_-]*\s*/', '', $text) ?? $text;
        $text = trim($text);
    }

    $extractQuoted = static function (string $key) use ($text): string {
        $quotedKey = preg_quote($key, '/');
        $patterns = [
            '/"' . $quotedKey . '"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/is',
            '/"' . $quotedKey . '"\s*:\s*\'((?:\\\\.|[^\'\\\\])*)\'/is',
            '/\'' . $quotedKey . '\'\s*:\s*"((?:\\\\.|[^"\\\\])*)"/is',
            '/\'' . $quotedKey . '\'\s*:\s*\'((?:\\\\.|[^\'\\\\])*)\'/is',
            '/(?:^|[,{]\s*)' . $quotedKey . '\s*:\s*"((?:\\\\.|[^"\\\\])*)"/is',
            '/(?:^|[,{]\s*)' . $quotedKey . '\s*:\s*\'((?:\\\\.|[^\'\\\\])*)\'/is',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                return trim(stripcslashes(strval($m[1])));
            }
        }
        return '';
    };
    $extractScalar = static function (string $key) use ($text): string {
        $quotedKey = preg_quote($key, '/');
        $patterns = [
            '/"' . $quotedKey . '"\s*:\s*(-?\d+(?:\.\d+)?)/is',
            '/\'' . $quotedKey . '\'\s*:\s*(-?\d+(?:\.\d+)?)/is',
            '/(?:^|[,{]\s*)' . $quotedKey . '\s*:\s*(-?\d+(?:\.\d+)?)/is',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                return trim(strval($m[1]));
            }
        }
        return '';
    };

    $extractMaybeUnclosedMessage = static function (string $key) use ($text): string {
        $quotedKey = preg_quote($key, '/');
        $closedPatterns = [
            '/"' . $quotedKey . '"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/is',
            '/"' . $quotedKey . '"\s*:\s*\'((?:\\\\.|[^\'\\\\])*)\'/is',
            '/\'' . $quotedKey . '\'\s*:\s*"((?:\\\\.|[^"\\\\])*)"/is',
            '/\'' . $quotedKey . '\'\s*:\s*\'((?:\\\\.|[^\'\\\\])*)\'/is',
            '/(?:^|[,{]\s*)' . $quotedKey . '\s*:\s*"((?:\\\\.|[^"\\\\])*)"/is',
            '/(?:^|[,{]\s*)' . $quotedKey . '\s*:\s*\'((?:\\\\.|[^\'\\\\])*)\'/is',
        ];
        foreach ($closedPatterns as $closedPattern) {
            if (preg_match($closedPattern, $text, $m) === 1) {
                return trim(stripcslashes(strval($m[1])));
            }
        }

        // Handle truncated JSON where the final quote/object close is missing.
        $openPatterns = [
            '/"' . $quotedKey . '"\s*:\s*"([\s\S]*)$/is',
            '/"' . $quotedKey . '"\s*:\s*\'([\s\S]*)$/is',
            '/\'' . $quotedKey . '\'\s*:\s*"([\s\S]*)$/is',
            '/\'' . $quotedKey . '\'\s*:\s*\'([\s\S]*)$/is',
            '/(?:^|[,{]\s*)' . $quotedKey . '\s*:\s*"([\s\S]*)$/is',
            '/(?:^|[,{]\s*)' . $quotedKey . '\s*:\s*\'([\s\S]*)$/is',
        ];
        foreach ($openPatterns as $openPattern) {
            if (preg_match($openPattern, $text, $m) === 1) {
                $value = strval($m[1]);
                $value = preg_replace('/\s*```$/', '', $value) ?? $value;
                $value = rtrim($value, " \t\r\n,}");
                return trim(stripcslashes($value));
            }
        }
        return '';
    };

    $message = $extractMaybeUnclosedMessage('message');
    if ($message === '') {
        $message = $extractMaybeUnclosedMessage('text');
    }
    if ($message === '') {
        $message = $extractMaybeUnclosedMessage('content');
    }

    $item = $extractQuoted('item');
    if ($item === '') {
        if (preg_match('/"item"\s*:\s*(null|NULL)\b/', $text) === 1) {
            $item = '';
        }
    }

    $fields = [
        'character' => $extractQuoted('character'),
        'message' => $message,
        'action' => $extractQuoted('action'),
        'target' => $extractQuoted('target'),
        'item' => $item,
        'listener' => $extractQuoted('listener'),
        'mood' => $extractQuoted('mood'),
        'lang' => $extractQuoted('lang'),
        'amount' => $extractScalar('amount'),
    ];

    foreach ($fields as $value) {
        if (trim(strval($value)) !== '') {
            return $fields;
        }
    }
    return [];
}

function stobeParseStructuredPositiveAmount(string $rawValue, int $max = 1000000): int {
    if (preg_match('/-?\d+/', trim($rawValue), $m) !== 1) {
        return 0;
    }
    $amount = abs(intval($m[0]));
    if ($amount < 1) {
        return 0;
    }
    if ($amount > $max) {
        $amount = $max;
    }
    return $amount;
}

function stobeStructuredItemLooksLikeCatsTransfer(string $rawValue): bool {
    $value = strtolower(trim(preg_replace('/[\s_\-]+/', ' ', $rawValue) ?? ''));
    if ($value === '') {
        return false;
    }
    return preg_match('/\b(cats?|money|coins?)\b/', $value) === 1;
}

function stobeBuildActionTagFromStructuredPayload(
    string $action,
    string $target,
    string $item,
    string $message,
    string $listener = '',
    string $amount = '',
    string $destination = '',
    string $maxCats = ''
): string {
    $actionUpper = strtoupper(trim($action));
    $target = trim($target);
    $item = trim($item);
    $listener = trim($listener);
    $amount = trim($amount);
    $destination = trim($destination);
    $maxCats = trim($maxCats);

    if ($actionUpper === '' || $actionUpper === 'TALK') {
        return '';
    }

    $synonyms = [
        'STOPATTACK' => 'STOP_ATTACK',
        'STOPFOLLOW' => 'STOP_FOLLOW',
        'UNFOLLOW' => 'STOP_FOLLOW',
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
        'KILLSELF' => 'SUICIDE',
        'SELFDESTRUCT' => 'SUICIDE',
        'JOINPARTY' => 'JOIN_PARTY',
        'JOIN_TO_RANGROOSQUAD' => 'JOIN_PARTY',
        'GIVECATS' => 'GIVE_CATS',
        'TAKECATS' => 'TAKE_CATS',
        'TAKEITEM' => 'TAKE_ITEM',
        'GIVEITEM' => 'GIVE_ITEM',
        'DROPITEM' => 'DROP_ITEM',
        'UNEQUIPITEM' => 'UNEQUIP_ITEM',
        'UNEQUIP-ITEM' => 'UNEQUIP_ITEM',
        'TAKEOFFITEM' => 'UNEQUIP_ITEM',
        'TAKE_OFF_ITEM' => 'UNEQUIP_ITEM',
        'TAKE-OFF-ITEM' => 'UNEQUIP_ITEM',
        'EQUIPITEM' => 'EQUIP_ITEM',
        'EQUIP-ITEM' => 'EQUIP_ITEM',
        'SHEATHEWEAPON' => 'SHEATHE_WEAPON',
        'SHEATHE-WEAPON' => 'SHEATHE_WEAPON',
        'HOLSTERWEAPON' => 'SHEATHE_WEAPON',
        'DRAWWEAPON' => 'DRAW_WEAPON',
        'DRAW-WEAPON' => 'DRAW_WEAPON',
        'READYWEAPON' => 'DRAW_WEAPON',
        'FACETARGET' => 'FACE_TARGET',
        'FACE-TARGET' => 'FACE_TARGET',
        'LOOKAT' => 'FACE_TARGET',
        'LOOK_AT' => 'FACE_TARGET',
        'FIRSTAID' => 'FIRST_AID',
        'FIRST-AID' => 'FIRST_AID',
        'HEALTARGET' => 'FIRST_AID',
        'HEAL_TARGET' => 'FIRST_AID',
        'GUARDTARGET' => 'BODYGUARD',
        'GUARD_TARGET' => 'BODYGUARD',
        'BODYGUARDTARGET' => 'BODYGUARD',
        'PUTINBED' => 'PUT_IN_BED',
        'PUT-IN-BED' => 'PUT_IN_BED',
        'LOOTTARGET' => 'LOOT_TARGET',
        'LOOT-TARGET' => 'LOOT_TARGET',
        'RELEASEPRISONER' => 'RELEASE_PRISONER',
        'RELEASE-PRISONER' => 'RELEASE_PRISONER',
        'DROPWEAPON' => 'DROP_WEAPON',
        'DROP-WEAPON' => 'DROP_WEAPON',
        'DISARM' => 'SURRENDER',
        'DISARMSELF' => 'SURRENDER',
        'DISARM_SELF' => 'SURRENDER',
        'MOVETOTARGET' => 'MOVE_TO_TARGET',
        'MOVE-TO-TARGET' => 'MOVE_TO_TARGET',
        'GOTOACTOR' => 'MOVE_TO_TARGET',
        'HOLDPOSITION' => 'HOLD_POSITION',
        'HOLD-POSITION' => 'HOLD_POSITION',
        'TAKEASEAT' => 'SIT',
        'TAKE_A_SEAT' => 'SIT',
        'GOTOSLEEP' => 'SLEEP',
        'GO_TO_SLEEP' => 'SLEEP',
        'OPERATEOBJECT' => 'OPERATE_OBJECT',
        'OPERATE-OBJECT' => 'OPERATE_OBJECT',
        'WORKOBJECT' => 'OPERATE_OBJECT',
        'WORKGOAL' => 'WORK_GOAL',
        'TASKGOAL' => 'TASK_GOAL',
        'TASKCONTROL' => 'TASK_CONTROL',
        'WORK-GOAL' => 'WORK_GOAL',
        'PRODUCEGOAL' => 'WORK_GOAL',
        'PRODUCE_GOAL' => 'WORK_GOAL',
        'CRAFTGOAL' => 'WORK_GOAL',
        'CRAFT_GOAL' => 'WORK_GOAL',
        'FACTIONRELATIONS' => 'FACTION_RELATIONS',
        'SETBLOCK' => 'SET_BLOCK',
        'SETHOLD' => 'SET_HOLD',
        'SETPASSIVE' => 'SET_PASSIVE',
        'SETJOBS' => 'SET_JOBS',
        'SETRANGED' => 'SET_RANGED',
        'SETTAUNT' => 'SET_TAUNT',
        'SETSNEAK' => 'SET_SNEAK',
        'SETRESOURCE' => 'SET_RESOURCE',
        'SETMEDIC' => 'SET_MEDIC',
        'REMOVELIMB' => 'REMOVE_LIMB',
        'KO' => 'KNOCKOUT',
        'KNOCK_OUT' => 'KNOCKOUT',
        'KNOCK-OUT' => 'KNOCKOUT',
        'KILLTARGET' => 'KILL',
        'EXECUTE' => 'KILL',
        'MURDER' => 'KILL',
        'USEOBJECT' => 'USE_OBJECT',
        'USE-OBJECT' => 'USE_OBJECT',
        'USEDRUGS' => 'USE_DRUGS',
        'USE-DRUGS' => 'USE_DRUGS',
        'FORCEDRINK' => 'FORCE_DRINK',
        'FORCE-DRINK' => 'FORCE_DRINK',
        'DRINK' => 'DRINK_ITEM',
        'DRINKITEM' => 'DRINK_ITEM',
        'DRINK_ITEM' => 'DRINK_ITEM',
        'DRINK-ITEM' => 'DRINK_ITEM',
        'TRAVELLOCATION' => 'TRAVEL_LOCATION',
        'ROLEPLAYACTION' => 'ROLEPLAY_ACTION',
        'ROLEPLAY-ACTION' => 'ROLEPLAY_ACTION',
        'NOTIFY' => 'ROLEPLAY_ACTION',
        'WAITHERE' => 'IDLE',
        'FIGHT' => 'ATTACK',
        'HUNT' => 'ATTACK',
        'TAKEMONEYFROMRANGROO' => 'TAKE_CATS',
        'GIVEGOLDTO' => 'GIVE_CATS',
        'MOVE_TO' => 'MOVE_TO',
        'MOVETO' => 'MOVE_TO',
    ];
    if (isset($synonyms[$actionUpper])) {
        $actionUpper = $synonyms[$actionUpper];
    }
    $actionUpper = stobeCanonicalizeActionCommand($actionUpper);
    $explicitAmount = stobeParseStructuredPositiveAmount($amount);

    // "Put those back in storage": the model sometimes picks GiveItem -> player.
    if ($actionUpper === 'GIVE_ITEM' && $item !== '') {
        $playerLine = strtolower(strval($GLOBALS['STOBE_CURRENT_PLAYER_MESSAGE'] ?? ''));
        if ($playerLine !== ''
            && preg_match('/\b(storage|chests?|stash|stockpile|put\s+(?:\w+\s+){0,3}(?:away|back\s+in)|store\s+(?:it|them|those|these|that))\b/', $playerLine) === 1
            && preg_match('/\b(give|hand|pass|bring)\s+(?:\w+\s+){0,3}(?:me|to\s+me)\b/', $playerLine) !== 1) {
            stobeLogInfo('GiveItem rewritten to StoreItems (player asked for storage)', ['item' => $item, 'target' => $target]);
            $actionUpper = 'STORE_ITEMS';
            $target = '';
        }
    }

    $plannerKinds = [
        'LOOTAREA'=>'LOOT_AREA','LOOTSTORE'=>'LOOT_STORE','STOREITEMS'=>'STORE',
        'FETCHITEMS'=>'FETCH','DELIVERITEMS'=>'DELIVER','RECOVERGROUND'=>'RECOVER_GROUND',
        'MEDICALCLEANUP'=>'MEDICAL_CLEANUP','BATTLECLEANUP'=>'BATTLE_CLEANUP',
        'IMPRISONALL'=>'IMPRISON_ALL','RELEASEALL'=>'RELEASE_ALL','MAINTAINSTOCK'=>'STOCK',
        'BUYITEMS'=>'BUY','SELLITEMS'=>'SELL','GUARDGOAL'=>'GUARD','WAITFORGOAL'=>'WAIT_FOR',
        'PATROLGOAL'=>'PATROL','BUILDGOAL'=>'BUILD_GOAL','REPAIRGOAL'=>'REPAIR_GOAL',
    ];
    $plannerKey = str_replace(['_','-',' '], '', $actionUpper);
    if (isset($plannerKinds[$plannerKey])) {
        $kind = $plannerKinds[$plannerKey];
        $goalItem = trim($item);
        $goalTarget = trim($target);
        $goalDestination = trim($destination);
        $goalAmount = max(0, min(1000, intval($amount)));
        $goalMaxCats = max(0, min(10000000, intval($maxCats)));
        if (in_array($kind, ['LOOT_AREA','LOOT_STORE','STORE','RECOVER_GROUND','SELL'], true) && $goalItem === '') {
            $goalItem = 'all';
        }
        if ($kind === 'BATTLE_CLEANUP' && $goalItem === '') {
            $goalItem = 'weapons';
        }
        if ($kind === 'IMPRISON_ALL' && $goalTarget === '') {
            $goalTarget = 'enemies';
        }
        if (in_array($kind, ['FETCH','DELIVER','BUY','STOCK'], true) && ($goalItem === '' || $goalAmount < 1)) {
            return '';
        }
        if ($kind === 'DELIVER' && $goalTarget === '') {
            return '';
        }
        if (in_array($kind, ['BUILD_GOAL','REPAIR_GOAL'], true) && $goalTarget === '') {
            $goalTarget = trim($item);
        }
        return 'TASK_GOAL@' . $kind . '@' . $goalTarget . '@' . $goalItem . '@'
            . strval($goalAmount) . '@' . $goalDestination . '@' . strval($goalMaxCats);
    }

    $controlMap = [
        'PAUSEGOAL'=>'PAUSE','RESUMEGOAL'=>'RESUME','CANCELGOAL'=>'CANCEL',
        'MODIFYGOAL'=>'QUANTITY','RETARGETGOAL'=>'DESTINATION',
        'APPROVEPURCHASE'=>'APPROVE','DECLINEPURCHASE'=>'DECLINE',
    ];
    if (isset($controlMap[$plannerKey])) {
        $cmd = $controlMap[$plannerKey];
        $selector = trim($target !== '' ? $target : $item);
        $argAmount = max(0, min(1000, intval($amount)));
        return 'TASK_CONTROL@' . $cmd . '@' . $selector . '@' . strval($argAmount) . '@' . trim($destination);
    }

    if ($actionUpper === 'ATTACK') {
        return 'ATTACK@' . $target;
    }
    if ($actionUpper === 'STOP_ATTACK') {
        if ($target === '') {
            return '';
        }
        return 'STOP_ATTACK@' . $target;
    }
    if ($actionUpper === 'MOVE_TO') {
        return $target !== '' ? 'MOVE_TO@' . $target : '';
    }
    if ($actionUpper === 'FOLLOW') {
        $followTarget = trim($target !== '' ? $target : $item);
        if ($followTarget === '') {
            // Default to player-follow when model selects FOLLOW but omits target.
            return 'FOLLOW@player';
        }
        return 'FOLLOW@' . $followTarget;
    }
    if ($actionUpper === 'TRAVEL_LOCATION') {
        $destination = trim($target !== '' ? $target : ($item !== '' ? $item : $message));
        if ($destination === '') {
            return '';
        }
        return 'TRAVEL_LOCATION@' . $destination;
    }
    if ($actionUpper === 'WORK_GOAL') {
        $goalItem = trim($item !== '' ? $item : $message);
        if ($goalItem === '' || $explicitAmount < 1) {
            return '';
        }
        $goalDestination = trim($destination !== '' ? $destination : $target);
        return $goalDestination !== ''
            ? 'WORK_GOAL@' . $goalDestination . '@' . $goalItem . '@' . strval(min($explicitAmount, 1000))
            : 'WORK_GOAL@' . $goalItem . '@' . strval(min($explicitAmount, 1000));
    }
    if (in_array($actionUpper, ['USE_OBJECT', 'SIT', 'SLEEP', 'OPERATE_OBJECT'], true)) {
        $objectToken = trim($target !== '' ? $target : $item);
        if ($objectToken === '') {
            if ($actionUpper === 'SIT') {
                $objectToken = 'chair';
            } elseif ($actionUpper === 'SLEEP') {
                $objectToken = 'bed';
            }
        }
        return 'USE_OBJECT@' . $objectToken;
    }
    if ($actionUpper === 'USE_DRUGS') {
        $drugName = trim($item !== '' ? $item : ($target !== '' ? $target : $message));
        if ($drugName === '') {
            return 'USE_DRUGS@Hashish';
        }
        return 'USE_DRUGS@' . $drugName;
    }
    if ($actionUpper === 'DRINK_ITEM') {
        $drinkName = trim($item !== '' ? $item : ($target !== '' ? $target : $message));
        if ($drinkName === '') {
            return 'DRINK_ITEM@Cactus Rum';
        }
        return 'DRINK_ITEM@' . $drinkName;
    }
    if ($actionUpper === 'FORCE_DRINK') {
        $forcedTarget = trim($target);
        if ($forcedTarget === '') {
            return '';
        }
        $drinkName = trim($item !== '' ? $item : $message);
        if ($drinkName === '') {
            $drinkName = 'Cactus Rum';
        }
        return 'FORCE_DRINK@' . $forcedTarget . '@' . $drinkName;
    }
    if (in_array($actionUpper, ['STOP_FOLLOW', 'STOP_CARRYING', 'JOIN_PARTY', 'LEAVE', 'IDLE', 'SUICIDE', 'SHEATHE_WEAPON', 'DRAW_WEAPON', 'PATROL', 'HOLD_POSITION', 'DROP_WEAPON', 'SURRENDER'], true)) {
        return $actionUpper . '@';
    }
    if (in_array($actionUpper, ['SET_BLOCK', 'SET_HOLD', 'SET_PASSIVE', 'SET_JOBS', 'SET_RANGED', 'SET_TAUNT', 'SET_SNEAK', 'SET_RESOURCE', 'SET_MEDIC'], true)) {
        $toggleRaw = strtoupper(trim($item !== '' ? $item : $target));
        if ($toggleRaw === '') {
            return '';
        }
        if (in_array($toggleRaw, ['TRUE', '1', 'YES', 'ON', 'ENABLE', 'ENABLED'], true)) {
            return $actionUpper . '@ON';
        }
        if (in_array($toggleRaw, ['FALSE', '0', 'NO', 'OFF', 'DISABLE', 'DISABLED'], true)) {
            return $actionUpper . '@OFF';
        }
        return '';
    }
    if (in_array($actionUpper, ['GIVE_CATS', 'TAKE_CATS'], true)) {
        $transferTarget = $target;
        if ($transferTarget === '' && $actionUpper === 'GIVE_CATS' && $listener !== '') {
            $transferTarget = $listener;
        }

        $amountValue = $explicitAmount;
        if ($amountValue <= 0 && $item !== '') {
            $amountValue = stobeParseStructuredPositiveAmount($item);
        }
        if ($amountValue <= 0 && $target !== '' && preg_match('/^\s*-?\d+\s*$/', $target) === 1) {
            $amountValue = stobeParseStructuredPositiveAmount($target);
            $transferTarget = '';
        }
        if ($amountValue <= 0) {
            return '';
        }
        if ($transferTarget !== '') {
            return $actionUpper . '@' . $transferTarget . '@' . strval($amountValue);
        }
        return $actionUpper . '@' . strval($amountValue);
    }
    if (in_array($actionUpper, ['TAKE_ITEM', 'GIVE_ITEM'], true)) {
        $itemName = trim($item !== '' ? $item : $target);
        if ($itemName === '') {
            return '';
        }
        $explicitTarget = '';
        if ($item !== '') {
            $explicitTarget = trim($target);
        }
        if ($actionUpper === 'GIVE_ITEM' && $explicitTarget === '' && $listener !== '') {
            $explicitTarget = $listener;
        }

        if (stobeStructuredItemLooksLikeCatsTransfer($itemName)) {
            $catsCommand = $actionUpper === 'GIVE_ITEM' ? 'GIVE_CATS' : 'TAKE_CATS';
            $catsTarget = $explicitTarget;
            if ($catsTarget === '' && $catsCommand === 'GIVE_CATS' && $listener !== '') {
                $catsTarget = $listener;
            }
            $catsAmount = $explicitAmount > 0
                ? $explicitAmount
                : stobeParseStructuredPositiveAmount($itemName);
            if ($catsAmount <= 0) {
                return '';
            }
            if ($catsTarget !== '') {
                return $catsCommand . '@' . $catsTarget . '@' . strval($catsAmount);
            }
            return $catsCommand . '@' . strval($catsAmount);
        }

        $normalized = $actionUpper . '@';
        if ($explicitTarget !== '') {
            $normalized .= $explicitTarget . '@';
        }
        $normalized .= $itemName;
        if ($explicitAmount > 0) {
            $normalized .= '@' . strval(min($explicitAmount, 100));
        }
        return $normalized;
    }
    if ($actionUpper === 'DROP_ITEM' || $actionUpper === 'UNEQUIP_ITEM' || $actionUpper === 'EQUIP_ITEM') {
        $itemName = trim($item !== '' ? $item : $target);
        if ($itemName === '') {
            $itemName = trim($message);
        }
        if ($itemName === '') {
            return '';
        }
        return $actionUpper . '@' . $itemName;
    }
    if (in_array($actionUpper, ['FACE_TARGET', 'FIRST_AID', 'BODYGUARD', 'RESCUE', 'PUT_IN_BED', 'LOOT_TARGET', 'IMPRISON', 'RELEASE_PRISONER', 'MOVE_TO_TARGET'], true)) {
        $targetName = trim($target !== '' ? $target : $item);
        if ($targetName === '') {
            return '';
        }
        return $actionUpper . '@' . $targetName;
    }
    if ($actionUpper === 'REPAIR' || $actionUpper === 'BUILD') {
        $objectName = trim($target !== '' ? $target : ($item !== '' ? $item : $message));
        if ($objectName === '') {
            return '';
        }
        return $actionUpper . '@' . $objectName;
    }
    if ($actionUpper === 'REMOVE_LIMB') {
        $targetName = trim($target);
        $limbToken = trim($item);
        if ($limbToken === '' && strpos($targetName, '@') !== false) {
            $parts = explode('@', $targetName, 2);
            if (count($parts) === 2) {
                $targetName = trim(strval($parts[0]));
                $limbToken = trim(strval($parts[1]));
            }
        }
        if ($targetName === '' || $limbToken === '') {
            return '';
        }
        return 'REMOVE_LIMB@' . $targetName . '@' . $limbToken;
    }
    if ($actionUpper === 'CUT_HORNS') {
        $targetName = trim($target !== '' ? $target : $item);
        if ($targetName === '') {
            return '';
        }
        return 'CUT_HORNS@' . $targetName;
    }
    if ($actionUpper === 'KNOCKOUT') {
        $targetName = trim($target !== '' ? $target : $item);
        if ($targetName === '') {
            return '';
        }
        return 'KNOCKOUT@' . $targetName;
    }
    if ($actionUpper === 'KILL') {
        $targetName = trim($target !== '' ? $target : $item);
        if ($targetName === '') {
            return '';
        }
        return 'KILL@' . $targetName;
    }
    if ($actionUpper === 'PICKUP_NPC') {
        $pickupTarget = trim($target !== '' ? $target : $item);
        if ($pickupTarget === '') {
            return '';
        }
        return 'PICKUP_NPC@' . $pickupTarget;
    }
    if ($actionUpper === 'ROLEPLAY_ACTION') {
        $notice = trim($target !== '' ? $target : $message);
        if ($notice === '') {
            return '';
        }
        return 'ROLEPLAY_ACTION@' . $notice;
    }
    if ($actionUpper === 'FACTION_RELATIONS') {
        $targetName = trim($target);
        $deltaSource = trim($item);

        if ($targetName !== '' && strpos($targetName, '@') !== false && $deltaSource === '') {
            $parts = explode('@', $targetName, 2);
            if (count($parts) === 2) {
                $targetName = trim(strval($parts[0]));
                $deltaSource = trim(strval($parts[1]));
            }
        }
        if ($targetName === '') {
            return '';
        }
        if ($deltaSource === '') {
            $deltaSource = trim($message);
        }

        $delta = 0;
        if (preg_match('/-?\d+/', $deltaSource, $m) === 1) {
            $delta = intval($m[0]);
        } else {
            $low = strtolower($deltaSource);
            if (preg_match('/\b(hostile|enemy|hate|hateful|worse|decrease|lower|negative)\b/', $low) === 1) {
                $delta = -100;
            } elseif (preg_match('/\b(friendly|friend|allied|ally|improve|increase|raise|positive)\b/', $low) === 1) {
                $delta = 100;
            }
        }
        if ($delta === 0) {
            return '';
        }
        $delta = $delta < 0 ? -100 : 100;
        return 'FACTION_RELATIONS@' . $targetName . '@' . strval($delta);
    }
    if ($actionUpper === 'TASK') {
        $task = trim($target !== '' ? $target : $item);
        if ($task === '') {
            return '';
        }
        return 'TASK@' . $task;
    }

    return '';
}

function stobeParseStructuredDialogueResponse(string $rawResponse, string $eventType = 'chat'): array {
    $fallbackMessage = trim($rawResponse);
    $fallbackActionTag = '';
    $decoded = stobeDecodeStructuredDialoguePayload($rawResponse);
    if (is_array($decoded) && count($decoded) > 0) {
        // Unwrap one response object, including provider arrays, without choosing from multiple replies.
        $unwrapKeys = ['response', 'data', 'output', 'result', 'payload'];
        for ($depth = 0; $depth < 4; $depth++) {
            if (count($decoded) === 1 && isset($decoded[0]) && is_array($decoded[0])) {
                $decoded = $decoded[0];
                continue;
            }
            $nested = [];
            foreach ($unwrapKeys as $unwrapKey) {
                if (!array_key_exists($unwrapKey, $decoded)) {
                    continue;
                }
                $nestedRaw = $decoded[$unwrapKey];
                if (is_array($nestedRaw)) {
                    $nested = $nestedRaw;
                } elseif (is_string($nestedRaw) && trim($nestedRaw) !== '') {
                    $nested = stobeDecodeStructuredDialoguePayload($nestedRaw);
                }
                if (count($nested) > 0) {
                    break;
                }
            }
            if (count($nested) === 0) {
                break;
            }
            $decoded = $nested;
        }
    }
    if (!is_array($decoded) || count($decoded) === 0) {
        $heuristic = stobeHeuristicExtractStructuredFields($rawResponse);
        if (count($heuristic) > 0) {
            $heuristicMessage = trim(strval($heuristic['message'] ?? ''));
            $heuristicAction = trim(strval($heuristic['action'] ?? ''));
            $heuristicTarget = trim(strval($heuristic['target'] ?? ''));
            $heuristicItem = trim(strval($heuristic['item'] ?? ''));
            $heuristicListener = trim(strval($heuristic['listener'] ?? ''));
            $fallbackActionTag = stobeBuildActionTagFromStructuredPayload(
                $heuristicAction,
                $heuristicTarget,
                $heuristicItem,
                $heuristicMessage,
                $heuristicListener,
                trim(strval($heuristic['amount'] ?? '')),
                trim(strval($heuristic['destination'] ?? '')),
                trim(strval($heuristic['max_cats'] ?? ''))
            );
            if ($fallbackActionTag !== '') {
                $heuristicCharacter = trim(strval($heuristic['character'] ?? ''));
                $heuristicNpcData = $heuristicCharacter !== ''
                    ? getNpcData($heuristicCharacter)
                    : false;
                // Bug 120: the speaker's own config (squadmate, trust), not the generic one.
                $heuristicConfig = is_array($heuristicNpcData)
                    ? stobeBuildActionConfigForNpc($eventType, $heuristicNpcData)
                    : getActionRuntimeConfig($eventType);
                $heuristicConfig['disallow_stop_attack'] = !stobeNpcIsInCombat($heuristicNpcData);
                $fallbackActionTag = normalizeActionTagToken(
                    $fallbackActionTag,
                    $heuristicConfig
                );
            }
            return [
                'is_structured' => true,
                'character' => trim(strval($heuristic['character'] ?? '')),
                'message' => $heuristicMessage,
                'action_tag' => $fallbackActionTag,
                'listener' => trim(strval($heuristic['listener'] ?? '')),
                'mood' => function_exists('stobeExtractFirstEmoteMood')
                    ? stobeExtractFirstEmoteMood($heuristic['mood'] ?? '')
                    : trim(strval($heuristic['mood'] ?? '')),
                'lang' => trim(strval($heuristic['lang'] ?? '')),
            ];
        }

        return [
            'is_structured' => false,
            'character' => '',
            'message' => $fallbackMessage,
            'action_tag' => $fallbackActionTag,
            'listener' => '',
            'mood' => '',
            'lang' => '',
        ];
    }

    $character = trim(strval($decoded['character'] ?? ''));
    $message = trim(strval($decoded['message'] ?? ''));
    if ($message === '') {
        $message = trim(strval($decoded['text'] ?? ''));
    }
    if ($message === '') {
        $message = trim(strval($decoded['content'] ?? ''));
    }
    $action = trim(strval($decoded['action'] ?? 'Talk'));
    $target = trim(strval($decoded['target'] ?? ''));
    $item = trim(strval($decoded['item'] ?? ''));
    $listener = trim(strval($decoded['listener'] ?? ''));
    $mood = function_exists('stobeExtractFirstEmoteMood')
        ? stobeExtractFirstEmoteMood($decoded['mood'] ?? '')
        : trim(strval($decoded['mood'] ?? ''));
    $lang = trim(strval($decoded['lang'] ?? ''));
    $amount = trim(strval($decoded['amount'] ?? ''));
    $destination = trim(strval($decoded['destination'] ?? ''));
    $maxCats = trim(strval($decoded['max_cats'] ?? ''));

    $rawActionTag = stobeBuildActionTagFromStructuredPayload(
        $action,
        $target,
        $item,
        $message,
        $listener,
        $amount,
        $destination,
        $maxCats
    );
    $actionTag = '';
    if ($rawActionTag !== '') {
        $speakerNpcData = $character !== '' ? getNpcData($character) : false;
        $responseConfig = is_array($speakerNpcData) // bug 120
            ? stobeBuildActionConfigForNpc($eventType, $speakerNpcData)
            : getActionRuntimeConfig($eventType);
        $responseConfig['disallow_stop_attack'] = !stobeNpcIsInCombat($speakerNpcData);
        $actionTag = normalizeActionTagToken($rawActionTag, $responseConfig);
    }

    return [
        'is_structured' => true,
        'character' => $character,
        'message' => trim($message),
        'action_tag' => $actionTag,
        'listener' => $listener,
        'mood' => $mood,
        'lang' => $lang,
    ];
}

function queryWorldKnowledgeForNpc(
    string $npcName,
    string $playerMessage,
    int $limit = 3,
    array|false $npcData = false,
    string $eventType = 'chat',
    array $excludedPayloads = []
): array {
    $queryStartedAt = microtime(true);

    $normalizedEventType = strtolower(trim($eventType));
    if ($normalizedEventType === '') {
        $normalizedEventType = 'chat';
    }

    if (!stobeWorldKnowledgeEventAllowed($normalizedEventType)) {
        return [];
    }

    if (!stobeWorldKnowledgeRetrieverEnabled()) {
        return [];
    }

    $message = trim($playerMessage);
    if ($message === '') {
        return [];
    }

    if (!is_array($npcData)) {
        $npcData = getNpcData($npcName);
    }
    if (!is_array($npcData)) {
        return [];
    }

    $knowledgeTags = parseNpcKnowledgeTags($npcData, $npcName);
    $isKnowAll = in_array('knowall', $knowledgeTags, true);
    $knowledgeSummary = implode(',', $knowledgeTags);
    $npcKey = stobeWorldKnowledgeBuildNpcKey($npcName, $npcData);
    $currentTopicBefore = stobeWorldKnowledgeGetCurrentTopic($npcKey);

    // Round 12: only topics[0] is used for retrieval, so one MiniMe call is enough.
    $topicCount = 1;
    $keywordWindow = max(6, min(60, getSettingInt('WORLD_KNOWLEDGE_CONTEXT_HISTORY', 16)));
    $keywordLimit = max(3, min(24, getSettingInt('WORLD_KNOWLEDGE_CONTEXT_KEYWORDS', 8)));
    $minRank = max(0.0, min(100.0, getSettingFloat('WORLD_KNOWLEDGE_MIN_RANK', 3.30)));

    $locationContext = stobeWorldKnowledgeResolveLocationContext($npcName);
    $contextKeywords = stobeWorldKnowledgeExtractContextKeywords($npcName, $keywordWindow, $keywordLimit);
    $topics = stobeWorldKnowledgeExtractTopics($message, $contextKeywords, $topicCount);
    $primaryTopic = count($topics) > 0 ? strval($topics[0]) : $message;

    $db = $GLOBALS["db"];
    $vectorExpr = "COALESCE(wk.native_vector, to_tsvector('english', COALESCE(wk.topic, '') || ' ' || COALESCE(wk.aliases, '') || ' ' || COALESCE(wk.topic_desc, '') || ' ' || COALESCE(wk.topic_desc_basic, '')))";
    $aliasExpr = "to_tsvector('simple', regexp_replace(replace(lower(COALESCE(wk.topic, '') || ' ' || COALESCE(wk.aliases, '')), '_', ' '), ',', ' ', 'g'))";
    $scoreParts = ['0'];
    $whereParts = [];
    $params = [];
    $notes = [];

    $addSignal = static function (string $signalText, float $weight, string $label) use (&$params, &$scoreParts, &$whereParts, $vectorExpr, &$notes): void {
        $text = trim($signalText);
        if ($text === '') {
            return;
        }
        if (!stobeWorldKnowledgeHasQueryableTerms($text)) {
            return;
        }

        $params[] = $text;
        $paramIdx = count($params);
        $scoreParts[] = "(CASE WHEN {$vectorExpr} @@ websearch_to_tsquery('english', $" . $paramIdx . ") THEN " . strval($weight * 3.0) . " ELSE 0 END)";
        $scoreParts[] = "(ts_rank_cd({$vectorExpr}, websearch_to_tsquery('english', $" . $paramIdx . ")) * " . strval($weight) . ")";
        $whereParts[] = "{$vectorExpr} @@ websearch_to_tsquery('english', $" . $paramIdx . ")";
        $notes[] = $label;
    };

    $addSignal($primaryTopic, 10.0, 'topic');
    $addSignal($currentTopicBefore, 5.0, 'continuity');
    $addSignal($locationContext, 2.0, 'location');
    $addSignal($contextKeywords, 1.0, 'context');
    $addSignal($message, 1.0, 'message');

    $exactTopicKeys = [];
    $primaryTopicKey = stobeWorldKnowledgeComparableLabel($primaryTopic);
    if (strlen($primaryTopicKey) >= 2) {
        $exactTopicKeys[$primaryTopicKey] = true;
    }
    foreach (stobeWorldKnowledgeFindUniqueAliasKeysInText($db, $message) as $messageAliasKey) {
        $exactTopicKeys[$messageAliasKey] = true;
    }

    foreach (array_keys($exactTopicKeys) as $exactTopicKey) {
        $params[] = $exactTopicKey;
        $exactIdx = count($params);
        $canonicalExactExpr = "regexp_replace(lower(COALESCE(wk.topic, '')), '[^a-z0-9]+', '', 'g') = $" . $exactIdx;
        $aliasExactExpr = "EXISTS (
            SELECT 1
            FROM regexp_split_to_table(COALESCE(wk.aliases, ''), ',') AS exact_alias(alias_token)
            WHERE regexp_replace(lower(BTRIM(exact_alias.alias_token)), '[^a-z0-9]+', '', 'g') = $" . $exactIdx . "
        )";
        $uniqueAliasExpr = $aliasExactExpr . " AND (
            SELECT COUNT(*)
            FROM world_knowledge alias_owner
            WHERE EXISTS (
                SELECT 1
                FROM regexp_split_to_table(COALESCE(alias_owner.aliases, ''), ',') AS owner_alias(alias_token)
                WHERE regexp_replace(lower(BTRIM(owner_alias.alias_token)), '[^a-z0-9]+', '', 'g') = $" . $exactIdx . "
            )
        ) = 1";
        $scoreParts[] = "(CASE WHEN {$canonicalExactExpr} THEN 120 ELSE 0 END)";
        $scoreParts[] = "(CASE WHEN ({$uniqueAliasExpr}) THEN 100 ELSE 0 END)";
        $whereParts[] = $canonicalExactExpr;
        $whereParts[] = "({$uniqueAliasExpr})";
        $notes[] = 'exact topic/alias:' . $exactTopicKey;
    }

    if (stobeWorldKnowledgeHasQueryableTerms($primaryTopic)) {
        $params[] = $primaryTopic;
        $aliasIdx = count($params);
        $scoreParts[] = "(CASE WHEN {$aliasExpr} @@ websearch_to_tsquery('simple', $" . $aliasIdx . ") THEN 20 ELSE 0 END)";
        $whereParts[] = "{$aliasExpr} @@ websearch_to_tsquery('simple', $" . $aliasIdx . ")";
        $notes[] = 'alias';
    }

    if (count($whereParts) === 0) {
        stobeWorldKnowledgeWriteAuditRow([
            'npc_name' => $npcName,
            'npc_key' => $npcKey,
            'event_type' => $normalizedEventType,
            'input_text' => $message,
            'extracted_topics' => $topics,
            'selected_topic' => '',
            'selected_entry_id' => 0,
            'selected_mode' => 'none',
            'selected_rank' => 0.0,
            'location_context' => $locationContext,
            'context_keywords' => $contextKeywords,
            'current_topic_before' => $currentTopicBefore,
            'current_topic_after' => $currentTopicBefore,
            'knowledge_tags' => $knowledgeSummary,
            'notes' => 'No queryable world_knowledge signals',
            'elapsed_seconds' => max(0.0, microtime(true) - $queryStartedAt),
        ]);
        return [];
    }

    $safeLimit = max(1, min(6, $limit));
    $candidateLimit = max(8, min(40, $safeLimit * 6));
    $query = "SELECT
                wk.id,
                wk.topic,
                wk.topic_desc,
                COALESCE(wk.topic_desc_basic, '') AS topic_desc_basic,
                COALESCE(wk.knowledge_class, '') AS knowledge_class,
                COALESCE(wk.knowledge_class_basic, '') AS knowledge_class_basic,
                COALESCE(wk.tags, '') AS tags,
                (" . implode(' + ', $scoreParts) . ") AS combined_rank
              FROM world_knowledge wk
              WHERE (" . implode(' OR ', $whereParts) . ")
              ORDER BY combined_rank DESC, id DESC
              LIMIT " . intval($candidateLimit);

    $rows = $db->fetchAll($query, $params);
    $hints = [];
    $seenHints = $excludedPayloads;
    $selectedTopic = '';
    $selectedMode = '';
    $selectedRank = 0.0;
    $selectedEntryId = 0;

    foreach ($rows as $row) {
        $rank = floatval($row['combined_rank'] ?? 0.0);
        if ($rank < $minRank) {
            continue;
        }

        $payload = stobeWorldKnowledgeSelectKnowledgePayload($row, $knowledgeTags, $isKnowAll);
        if (!boolval($payload['allowed'] ?? false)) {
            continue;
        }

        $topic = trim(strval($payload['topic'] ?? ''));
        $line = stobeWorldKnowledgeBuildHintLine($topic, strval($payload['desc'] ?? ''));
        $hintCountBefore = count($hints);
        stobeWorldKnowledgeAppendUniqueHints($hints, [$line], $seenHints);
        if (count($hints) === $hintCountBefore) {
            continue;
        }

        if ($selectedTopic === '') {
            $selectedTopic = $topic;
            $selectedMode = strval($payload['mode'] ?? '');
            $selectedRank = $rank;
            $selectedEntryId = intval($row['id'] ?? 0);
        }

        if (count($hints) >= $safeLimit) {
            break;
        }
    }

    $currentTopicAfter = $currentTopicBefore;
    if ($selectedTopic !== '') {
        $safeGamets = isset($GLOBALS['gameRequest']) && is_array($GLOBALS['gameRequest'])
            ? intval($GLOBALS['gameRequest'][2] ?? 0)
            : 0;
        stobeWorldKnowledgeSetCurrentTopic($npcKey, $npcName, $selectedTopic, $normalizedEventType, $safeGamets);
        $currentTopicAfter = $selectedTopic;
    }

    stobeWorldKnowledgeWriteAuditRow([
        'npc_name' => $npcName,
        'npc_key' => $npcKey,
        'event_type' => $normalizedEventType,
        'input_text' => $message,
        'extracted_topics' => $topics,
        'selected_topic' => $selectedTopic,
        'selected_entry_id' => $selectedEntryId,
        'selected_mode' => $selectedMode,
        'selected_rank' => $selectedRank,
        'location_context' => $locationContext,
        'context_keywords' => $contextKeywords,
        'current_topic_before' => $currentTopicBefore,
        'current_topic_after' => $currentTopicAfter,
        'knowledge_tags' => $knowledgeSummary,
        'notes' => implode(',', $notes),
        'elapsed_seconds' => max(0.0, microtime(true) - $queryStartedAt),
    ]);

    return $hints;
}

function getItemDescriptionFromCombinedTable(string $itemName, string $itemId = ''): string {
    static $cacheById = [];
    static $cacheByName = [];

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        return '';
    }

    $normalizedId = trim($itemId);
    if ($normalizedId !== '') {
        $idKey = strtolower($normalizedId);
        if (array_key_exists($idKey, $cacheById)) {
            return strval($cacheById[$idKey]);
        }
        try {
            $row = $db->fetchOne(
                "SELECT description
                 FROM combined_descriptions
                 WHERE LOWER(stringid) = LOWER($1)
                 LIMIT 1",
                [$normalizedId]
            );
            $description = trim(strval($row['description'] ?? ''));
            $cacheById[$idKey] = $description;
            if ($description !== '') {
                return $description;
            }
        } catch (Throwable $exception) {
            $cacheById[$idKey] = '';
            return '';
        }
    }

    $normalizedName = trim($itemName);
    if ($normalizedName === '') {
        return '';
    }

    $nameKey = strtolower($normalizedName);
    if (array_key_exists($nameKey, $cacheByName)) {
        return strval($cacheByName[$nameKey]);
    }

    try {
        $row = $db->fetchOne(
            "SELECT description
             FROM combined_descriptions
             WHERE LOWER(name) = LOWER($1)
             LIMIT 1",
            [$normalizedName]
        );
        $description = trim(strval($row['description'] ?? ''));
        $cacheByName[$nameKey] = $description;
        return $description;
    } catch (Throwable $exception) {
        $cacheByName[$nameKey] = '';
        return '';
    }
}

function stobeResolveArmorQualityLabelFromLevel(int $level): string {
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
}

function stobeResolveItemQualityFromEntry(array $entry): array {
    $label = trim(strval(
        $entry['quality']
            ?? ($entry['quality_label']
            ?? ($entry['quality_name'] ?? ''))
    ));
    if ($label !== '') {
        $label = preg_replace('/\s+/u', ' ', $label) ?? $label;
        $label = trim($label);
    }

    $level = -1;
    foreach (['quality_level', 'qualityLevel', 'level', 'gear_level'] as $field) {
        if (!array_key_exists($field, $entry)) {
            continue;
        }
        $rawLevel = $entry[$field];
        if (is_int($rawLevel) || is_float($rawLevel)) {
            $level = intval(round(floatval($rawLevel)));
            break;
        }
        if (is_string($rawLevel)) {
            $text = trim($rawLevel);
            if ($text === '' || preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $text) !== 1) {
                continue;
            }
            $level = intval(round(floatval($text)));
            break;
        }
    }
    if ($level >= 0) {
        if ($level > 100) {
            $level = 100;
        }
        if ($label === '') {
            $label = stobeResolveArmorQualityLabelFromLevel($level);
        }
    } else {
        $level = -1;
    }

    return [
        'label' => $label,
        'level' => $level,
    ];
}

function stobeResolveWeaponModelFromEntry(array $entry): string {
    $model = trim(strval(
        $entry['weapon_model']
            ?? ($entry['weaponModel']
            ?? ($entry['model'] ?? ''))
    ));
    if ($model === '') {
        return '';
    }

    $model = preg_replace('/\s+/u', ' ', $model) ?? $model;
    $model = trim($model);
    if ($model === '') {
        return '';
    }
    if (preg_match('/^\[(.*)\]$/u', $model, $matches) === 1) {
        $model = trim(strval($matches[1] ?? ''));
    }
    if ($model === '') {
        return '';
    }
    return $model;
}

function stobeFormatInventoryItemNameWithQuality(string $itemName, array $entry): string {
    $name = trim($itemName);
    if ($name === '') {
        return '';
    }
    $tags = [];

    $quality = stobeResolveItemQualityFromEntry($entry);
    $qualityLabel = trim(strval($quality['label'] ?? ''));
    if ($qualityLabel !== '') {
        $tags[] = $qualityLabel;
    }

    $weaponModel = stobeResolveWeaponModelFromEntry($entry);
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
        return $name;
    }

    $displayName = $name;
    foreach ($tags as $tag) {
        if ($tag === '') {
            continue;
        }
        if (stripos($displayName, '[' . $tag . ']') !== false) {
            continue;
        }
        $displayName .= ' [' . $tag . ']';
    }
    return $displayName;
}

function buildInventoryContextFromMetadata(array $metadata): array {
    $rawEntries = $metadata['inventory_items'] ?? [];
    if (is_string($rawEntries) && trim($rawEntries) !== '') {
        $decoded = json_decode($rawEntries, true);
        if (is_array($decoded)) {
            $rawEntries = $decoded;
        }
    }

    $rawTraderEntries = $metadata['trader_inventory_items'] ?? [];
    if (is_string($rawTraderEntries) && trim($rawTraderEntries) !== '') {
        $decodedTrader = json_decode($rawTraderEntries, true);
        if (is_array($decodedTrader)) {
            $rawTraderEntries = $decodedTrader;
        }
    }

    if (!is_array($rawEntries)) {
        $rawEntries = [];
    }
    if (!is_array($rawTraderEntries)) {
        $rawTraderEntries = [];
    }
    if (count($rawEntries) === 0 && count($rawTraderEntries) === 0) {
        return [
            'equipment' => '',
            'inventory' => '',
            'trader_inventory' => '',
            'merchant_inventory' => '',
            'has_items' => false,
            'has_trader_items' => false,
        ];
    }

    $parseFlag = static function (mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return intval($value) !== 0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true);
        }
        return false;
    };

    $equipmentCounts = [];
    $inventoryCounts = [];
    $traderInventoryCounts = [];
    foreach ($rawEntries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $itemName = trim(strval($entry['name'] ?? ''));
        if ($itemName === '') {
            continue;
        }

        $itemCount = intval($entry['count'] ?? 1);
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
        $itemDescription = trim(strval($entry['description'] ?? ''));
        if ($itemDescription !== '') {
            $itemDescription = preg_replace('/\s+/u', ' ', $itemDescription) ?? $itemDescription;
            $itemDescription = trim($itemDescription);
        }
        $itemQuality = stobeResolveItemQualityFromEntry($entry);
        $itemQualityLabel = trim(strval($itemQuality['label'] ?? ''));
        $itemQualityLevel = intval($itemQuality['level'] ?? -1);
        $itemWeaponModel = stobeResolveWeaponModelFromEntry($entry);
        $itemModel = '';
        $itemManufacturer = '';
        $itemManufacturerId = '';
        $itemValueEach = intval($entry['value_each'] ?? ($entry['value_single'] ?? 0));
        if ($itemValueEach < 0) {
            $itemValueEach = 0;
        }
        $isEquipped = $parseFlag($entry['equipped'] ?? ($entry['is_equipped'] ?? false));

        $qualityKey = $itemQualityLevel >= 0
            ? ('q:' . strval($itemQualityLevel))
            : ('q:' . strtolower($itemQualityLabel !== '' ? $itemQualityLabel : 'none'));
        $weaponModelKey = 'wm:' . strtolower($itemWeaponModel !== '' ? $itemWeaponModel : 'none');
        $entryKey = strtolower($itemId !== '' ? ('id:' . $itemId) : ('name:' . $itemName)) . '|' . $qualityKey . '|' . $weaponModelKey;
        if ($isEquipped) {
            if (!array_key_exists($entryKey, $equipmentCounts)) {
                $equipmentCounts[$entryKey] = [
                    'name' => $itemName,
                    'item_id' => $itemId,
                    'description' => $itemDescription,
                    'quality' => $itemQualityLabel,
                    'quality_level' => $itemQualityLevel,
                    'weapon_model' => $itemWeaponModel,
                    'value_each' => $itemValueEach,
                    'count' => 0,
                ];
            }
            $equipmentCounts[$entryKey]['count'] += $itemCount;
            if (trim(strval($equipmentCounts[$entryKey]['item_id'] ?? '')) === '' && $itemId !== '') {
                $equipmentCounts[$entryKey]['item_id'] = $itemId;
            }
            if (trim(strval($equipmentCounts[$entryKey]['description'] ?? '')) === '' && $itemDescription !== '') {
                $equipmentCounts[$entryKey]['description'] = $itemDescription;
            }
            if (trim(strval($equipmentCounts[$entryKey]['quality'] ?? '')) === '' && $itemQualityLabel !== '') {
                $equipmentCounts[$entryKey]['quality'] = $itemQualityLabel;
            }
            if (intval($equipmentCounts[$entryKey]['quality_level'] ?? -1) < 0 && $itemQualityLevel >= 0) {
                $equipmentCounts[$entryKey]['quality_level'] = $itemQualityLevel;
            }
            if (trim(strval($equipmentCounts[$entryKey]['weapon_model'] ?? '')) === '' && $itemWeaponModel !== '') {
                $equipmentCounts[$entryKey]['weapon_model'] = $itemWeaponModel;
            }
            if (intval($equipmentCounts[$entryKey]['value_each'] ?? 0) <= 0 && $itemValueEach > 0) {
                $equipmentCounts[$entryKey]['value_each'] = $itemValueEach;
            }
            continue;
        }

        if (!array_key_exists($entryKey, $inventoryCounts)) {
            $inventoryCounts[$entryKey] = [
                'name' => $itemName,
                'item_id' => $itemId,
                'description' => $itemDescription,
                'quality' => $itemQualityLabel,
                'quality_level' => $itemQualityLevel,
                'weapon_model' => $itemWeaponModel,
                'value_each' => $itemValueEach,
                'count' => 0,
            ];
        }
        $inventoryCounts[$entryKey]['count'] += $itemCount;
        if (trim(strval($inventoryCounts[$entryKey]['item_id'] ?? '')) === '' && $itemId !== '') {
            $inventoryCounts[$entryKey]['item_id'] = $itemId;
        }
        if (trim(strval($inventoryCounts[$entryKey]['description'] ?? '')) === '' && $itemDescription !== '') {
            $inventoryCounts[$entryKey]['description'] = $itemDescription;
        }
        if (trim(strval($inventoryCounts[$entryKey]['quality'] ?? '')) === '' && $itemQualityLabel !== '') {
            $inventoryCounts[$entryKey]['quality'] = $itemQualityLabel;
        }
        if (intval($inventoryCounts[$entryKey]['quality_level'] ?? -1) < 0 && $itemQualityLevel >= 0) {
            $inventoryCounts[$entryKey]['quality_level'] = $itemQualityLevel;
        }
        if (trim(strval($inventoryCounts[$entryKey]['weapon_model'] ?? '')) === '' && $itemWeaponModel !== '') {
            $inventoryCounts[$entryKey]['weapon_model'] = $itemWeaponModel;
        }
        if (intval($inventoryCounts[$entryKey]['value_each'] ?? 0) <= 0 && $itemValueEach > 0) {
            $inventoryCounts[$entryKey]['value_each'] = $itemValueEach;
        }
    }

    foreach ($rawTraderEntries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $itemName = trim(strval($entry['name'] ?? ''));
        if ($itemName === '') {
            continue;
        }
        $itemCount = intval($entry['count'] ?? ($entry['quantity'] ?? 1));
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
        $itemDescription = trim(strval($entry['description'] ?? ''));
        if ($itemDescription !== '') {
            $itemDescription = preg_replace('/\s+/u', ' ', $itemDescription) ?? $itemDescription;
            $itemDescription = trim($itemDescription);
        }
        $itemQuality = stobeResolveItemQualityFromEntry($entry);
        $itemQualityLabel = trim(strval($itemQuality['label'] ?? ''));
        $itemQualityLevel = intval($itemQuality['level'] ?? -1);
        $itemWeaponModel = stobeResolveWeaponModelFromEntry($entry);
        $itemValueEach = intval($entry['value_each'] ?? ($entry['value_single'] ?? 0));
        if ($itemValueEach < 0) {
            $itemValueEach = 0;
        }

        $qualityKey = $itemQualityLevel >= 0
            ? ('q:' . strval($itemQualityLevel))
            : ('q:' . strtolower($itemQualityLabel !== '' ? $itemQualityLabel : 'none'));
        $weaponModelKey = 'wm:' . strtolower($itemWeaponModel !== '' ? $itemWeaponModel : 'none');
        $entryKey = strtolower($itemId !== '' ? ('id:' . $itemId) : ('name:' . $itemName)) . '|' . $qualityKey . '|' . $weaponModelKey;
        if (!array_key_exists($entryKey, $traderInventoryCounts)) {
            $traderInventoryCounts[$entryKey] = [
                'name' => $itemName,
                'item_id' => $itemId,
                'description' => $itemDescription,
                'quality' => $itemQualityLabel,
                'quality_level' => $itemQualityLevel,
                'weapon_model' => $itemWeaponModel,
                'value_each' => $itemValueEach,
                'count' => 0,
            ];
        }
        $traderInventoryCounts[$entryKey]['count'] += $itemCount;
        if (trim(strval($traderInventoryCounts[$entryKey]['item_id'] ?? '')) === '' && $itemId !== '') {
            $traderInventoryCounts[$entryKey]['item_id'] = $itemId;
        }
        if (
            trim(strval($traderInventoryCounts[$entryKey]['description'] ?? '')) === '' &&
            $itemDescription !== ''
        ) {
            $traderInventoryCounts[$entryKey]['description'] = $itemDescription;
        }
        if (
            trim(strval($traderInventoryCounts[$entryKey]['quality'] ?? '')) === '' &&
            $itemQualityLabel !== ''
        ) {
            $traderInventoryCounts[$entryKey]['quality'] = $itemQualityLabel;
        }
        if (intval($traderInventoryCounts[$entryKey]['quality_level'] ?? -1) < 0 && $itemQualityLevel >= 0) {
            $traderInventoryCounts[$entryKey]['quality_level'] = $itemQualityLevel;
        }
        if (
            trim(strval($traderInventoryCounts[$entryKey]['weapon_model'] ?? '')) === '' &&
            $itemWeaponModel !== ''
        ) {
            $traderInventoryCounts[$entryKey]['weapon_model'] = $itemWeaponModel;
        }
        if (intval($traderInventoryCounts[$entryKey]['value_each'] ?? 0) <= 0 && $itemValueEach > 0) {
            $traderInventoryCounts[$entryKey]['value_each'] = $itemValueEach;
        }
    }

    $describedTokens = [];
    $resolveDescription = static function (array $entry, bool $isInventory) use (&$describedTokens): string {
        $entryName = trim(strval($entry['name'] ?? ''));
        if ($entryName === '') {
            return '';
        }
        $entryId = trim(strval($entry['item_id'] ?? ''));
        $entryCount = intval($entry['count'] ?? 1);
        if ($isInventory && $entryCount > 5) {
            return '';
        }

        $dedupeToken = strtolower($entryId !== '' ? ('id:' . $entryId) : ('name:' . $entryName));
        if (isset($describedTokens[$dedupeToken])) {
            return '';
        }

        $inlineDescription = trim(strval($entry['description'] ?? ''));
        if ($inlineDescription !== '') {
            $inlineDescription = preg_replace('/\s+/u', ' ', $inlineDescription) ?? $inlineDescription;
            $inlineDescription = trim($inlineDescription);
            if ($inlineDescription !== '') {
                $describedTokens[$dedupeToken] = true;
                return $inlineDescription;
            }
        }

        $description = getItemDescriptionFromCombinedTable($entryName, $entryId);
        if ($description !== '') {
            $describedTokens[$dedupeToken] = true;
        }
        return $description;
    };

    $equipmentParts = [];
    foreach ($equipmentCounts as $entry) {
        $entryName = trim(strval($entry['name'] ?? ''));
        if ($entryName === '') {
            continue;
        }
        $entryDisplayName = stobeFormatInventoryItemNameWithQuality($entryName, $entry);
        if ($entryDisplayName === '') {
            $entryDisplayName = $entryName;
        }
        $entryCount = intval($entry['count'] ?? 1);
        if ($entryCount <= 0) {
            $entryCount = 1;
        }
        $entryText = $entryDisplayName . ' x' . strval($entryCount);
        $entryValueEach = intval($entry['value_each'] ?? 0);
        if ($entryValueEach > 0) {
            $entryText .= ' value ' . strval($entryValueEach);
        }
        $description = $resolveDescription($entry, false);
        if ($description !== '') {
            $entryText .= ' (' . $description . ')';
        }
        $equipmentParts[] = $entryText;
        if (count($equipmentParts) >= 30) {
            break;
        }
    }

    $inventoryParts = [];
    foreach ($inventoryCounts as $entry) {
        $entryName = trim(strval($entry['name'] ?? ''));
        if ($entryName === '') {
            continue;
        }
        $entryDisplayName = stobeFormatInventoryItemNameWithQuality($entryName, $entry);
        if ($entryDisplayName === '') {
            $entryDisplayName = $entryName;
        }
        $entryCount = intval($entry['count'] ?? 1);
        if ($entryCount <= 0) {
            $entryCount = 1;
        }
        $description = $resolveDescription($entry, true);
        $entryText = $entryDisplayName . ' x' . strval($entryCount);
        $entryValueEach = intval($entry['value_each'] ?? 0);
        if ($entryValueEach > 0) {
            $entryText .= ' value ' . strval($entryValueEach);
        }
        if ($description !== '') {
            $entryText .= ' (' . $description . ')';
        }
        $inventoryParts[] = $entryText;
        if (count($inventoryParts) >= 60) {
            break;
        }
    }

    $traderInventoryParts = [];
    foreach ($traderInventoryCounts as $entry) {
        $entryName = trim(strval($entry['name'] ?? ''));
        if ($entryName === '') {
            continue;
        }
        $entryDisplayName = stobeFormatInventoryItemNameWithQuality($entryName, $entry);
        if ($entryDisplayName === '') {
            $entryDisplayName = $entryName;
        }
        $entryCount = intval($entry['count'] ?? 1);
        if ($entryCount <= 0) {
            $entryCount = 1;
        }
        $description = $resolveDescription($entry, true);
        $entryText = $entryDisplayName . ' x' . strval($entryCount);
        $entryValueEach = intval($entry['value_each'] ?? 0);
        if ($entryValueEach > 0) {
            $entryText .= ' value ' . strval($entryValueEach);
        }
        if ($description !== '') {
            $entryText .= ' (' . $description . ')';
        }
        $traderInventoryParts[] = $entryText;
        if (count($traderInventoryParts) >= 80) {
            break;
        }
    }

    if (count($inventoryParts) === 0 && count($traderInventoryParts) > 0) {
        $inventoryParts = $traderInventoryParts;
    }

    $hasTraderItems = count($traderInventoryCounts) > 0;
    $hasItems = (count($equipmentCounts) + count($inventoryCounts) + count($traderInventoryCounts)) > 0;

    return [
        'equipment' => implode(', ', $equipmentParts),
        'inventory' => implode(', ', $inventoryParts),
        'trader_inventory' => implode(', ', $traderInventoryParts),
        'merchant_inventory' => implode(', ', $traderInventoryParts),
        'has_items' => $hasItems,
        'has_trader_items' => $hasTraderItems,
    ];
}

function stobeNormalizeItemNameKey(string $name): string {
    $normalized = trim(strtolower($name));
    if ($normalized === '') {
        return '';
    }
    $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    return trim($normalized);
}

function stobeLookupItemIdentityFromKnownMetadata(string $itemName): array {
    static $cache = [];

    $normalizedName = trim($itemName);
    if ($normalizedName === '') {
        return ['model' => '', 'manufacturer' => ''];
    }
    $cacheKey = strtolower($normalizedName);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        $cache[$cacheKey] = ['model' => '', 'manufacturer' => ''];
        return $cache[$cacheKey];
    }

    try {
        $row = $db->fetchOne(
            "SELECT
                COALESCE(
                    NULLIF(TRIM(item_entry->>'model'), ''),
                    NULLIF(TRIM(item_entry->>'item_id'), ''),
                    NULLIF(TRIM(item_entry->>'itemId'), ''),
                    ''
                ) AS model,
                COALESCE(
                    NULLIF(TRIM(item_entry->>'manufacturer'), ''),
                    NULLIF(TRIM(item_entry->>'manufacturer_id'), ''),
                    NULLIF(TRIM(item_entry->>'manufacturerId'), ''),
                    ''
                ) AS manufacturer
             FROM core_npc_master n
             CROSS JOIN LATERAL jsonb_array_elements(
                CASE
                    WHEN jsonb_typeof(n.metadata->'inventory_items') = 'array'
                        THEN n.metadata->'inventory_items'
                    ELSE '[]'::jsonb
                END
             ) AS item_entry
             WHERE LOWER(TRIM(item_entry->>'name')) = LOWER(TRIM($1))
               AND (
                    NULLIF(TRIM(item_entry->>'model'), '') IS NOT NULL
                    OR NULLIF(TRIM(item_entry->>'item_id'), '') IS NOT NULL
                    OR NULLIF(TRIM(item_entry->>'itemId'), '') IS NOT NULL
                    OR NULLIF(TRIM(item_entry->>'manufacturer'), '') IS NOT NULL
                    OR NULLIF(TRIM(item_entry->>'manufacturer_id'), '') IS NOT NULL
                    OR NULLIF(TRIM(item_entry->>'manufacturerId'), '') IS NOT NULL
               )
             ORDER BY n.updated_at DESC
             LIMIT 1",
            [$normalizedName]
        );

        $identity = [
            'model' => trim(strval($row['model'] ?? '')),
            'manufacturer' => trim(strval($row['manufacturer'] ?? '')),
        ];
        $cache[$cacheKey] = $identity;
        return $identity;
    } catch (Throwable $exception) {
        $cache[$cacheKey] = ['model' => '', 'manufacturer' => ''];
        return $cache[$cacheKey];
    }
}

function stobeExtractBaseItemNameFromAggregateToken(string $token): string {
    $work = trim($token);
    if ($work === '') {
        return '';
    }

    $cutPatterns = [
        '/\s+x\d+\b/iu',
        '/\s+value\s+\d+\b/iu',
        '/\s+model\s+.+$/iu',
        '/\s+manufacturer\s+.+$/iu',
    ];
    $cutAt = null;
    foreach ($cutPatterns as $pattern) {
        if (preg_match($pattern, $work, $match, PREG_OFFSET_CAPTURE) === 1) {
            $offset = intval($match[0][1] ?? -1);
            if ($offset >= 0 && ($cutAt === null || $offset < $cutAt)) {
                $cutAt = $offset;
            }
        }
    }
    if ($cutAt !== null) {
        $work = substr($work, 0, $cutAt);
    }
    return trim($work);
}

function stobeEnrichAggregateItemTokenWithDescription(string $token, int $maxDescriptionLength = 120): string {
    $text = trim($token);
    if ($text === '') {
        return '';
    }

    $baseName = stobeExtractBaseItemNameFromAggregateToken($text);
    if ($baseName === '') {
        return $text;
    }

    $description = getItemDescriptionFromCombinedTable($baseName, '');
    $description = trim(strval($description));
    if ($description === '') {
        return $text;
    }
    $description = preg_replace('/\s+/u', ' ', $description) ?? $description;
    $description = trim($description);
    if ($description === '') {
        return $text;
    }
    if ($maxDescriptionLength > 0 && strlen($description) > $maxDescriptionLength) {
        $description = substr($description, 0, $maxDescriptionLength);
        $description = rtrim($description, " \t\n\r\0\x0B.,;:");
    }
    if ($description === '') {
        return $text;
    }
    if (stripos($text, $description) !== false) {
        return $text;
    }
    return $text . ' (' . $description . ')';
}

function stobeEnrichItemCsvWithDescriptions(
    string $csv,
    int $maxItems = 12,
    int $maxDescriptionLength = 120,
    int $maxTotalLength = 1800
): string {
    $raw = trim($csv);
    if ($raw === '') {
        return '';
    }

    $tokens = preg_split('/\s*,\s*/u', $raw) ?: [];
    $out = [];
    $describedByBaseName = [];
    foreach ($tokens as $token) {
        $cleanToken = trim(strval($token));
        if ($cleanToken === '') {
            continue;
        }

        $baseName = stobeExtractBaseItemNameFromAggregateToken($cleanToken);
        $baseKey = stobeNormalizeItemNameKey($baseName);

        $enrichedToken = $cleanToken;
        if ($baseKey !== '' && isset($describedByBaseName[$baseKey])) {
            // Already described once in this list; keep later duplicates concise.
            $enrichedToken = preg_replace('/\s*\([^)]{8,}\)\s*$/u', '', $cleanToken) ?? $cleanToken;
            $enrichedToken = trim(strval($enrichedToken));
            if ($enrichedToken === '') {
                $enrichedToken = $cleanToken;
            }
        } else {
            $enrichedToken = stobeEnrichAggregateItemTokenWithDescription($cleanToken, $maxDescriptionLength);
            if ($baseKey !== '' && $enrichedToken !== $cleanToken) {
                $describedByBaseName[$baseKey] = true;
            }
        }

        $out[] = $enrichedToken;
        if ($maxItems > 0 && count($out) >= $maxItems) {
            break;
        }
    }

    $result = implode(', ', $out);
    if ($maxTotalLength > 0 && strlen($result) > $maxTotalLength) {
        $result = substr($result, 0, $maxTotalLength);
        $result = rtrim($result, " \t\n\r\0\x0B,;:");
    }
    return trim($result);
}

function stobeParseCurrentMaxRatio(mixed $raw): array {
    $text = trim(strval($raw));
    if ($text === '' || preg_match('/^\s*(-?[0-9]+)\s*\/\s*(-?[0-9]+)\s*$/', $text, $m) !== 1) {
        return ['valid' => false, 'current' => 0, 'max' => 0, 'pct' => 0.0];
    }

    $current = max(0, intval($m[1]));
    $max = max(0, intval($m[2]));
    if ($max <= 0) {
        return ['valid' => false, 'current' => $current, 'max' => 0, 'pct' => 0.0];
    }
    if ($current > $max) {
        $current = $max;
    }
    return [
        'valid' => true,
        'current' => $current,
        'max' => $max,
        'pct' => ($current / $max) * 100.0,
    ];
}

function stobeDescribeBloodStatus(mixed $bloodRaw): string {
    $ratio = stobeParseCurrentMaxRatio($bloodRaw);
    if (!boolval($ratio['valid'] ?? false)) {
        return '';
    }
    $pct = floatval($ratio['pct'] ?? 0.0);
    if ($pct >= 95.0) {
        return 'In good health';
    }
    if ($pct >= 75.0) {
        return 'Lightly wounded';
    }
    if ($pct >= 50.0) {
        return 'Wounded';
    }
    if ($pct >= 25.0) {
        return 'Badly wounded';
    }
    if ($pct > 0.0) {
        return 'Near death';
    }
    return "On death's door";
}

function stobeDescribeHungerStatus(mixed $hungerRaw): string {
    $ratio = stobeParseCurrentMaxRatio($hungerRaw);
    if (!boolval($ratio['valid'] ?? false)) {
        return '';
    }
    $pct = floatval($ratio['pct'] ?? 0.0);
    if ($pct >= 90.0) {
        return 'Well fed';
    }
    if ($pct >= 70.0) {
        return 'Sated';
    }
    if ($pct >= 45.0) {
        return 'Peckish';
    }
    if ($pct >= 25.0) {
        return 'Hungry';
    }
    if ($pct >= 10.0) {
        return 'Very hungry';
    }
    if ($pct > 0.0) {
        return 'Starving';
    }
    return 'Near starvation';
}

function stobeNormalizeLimbToken(string $rawLimb): string {
    $normalized = strtolower(trim($rawLimb));
    if ($normalized === '') {
        return '';
    }
    $normalized = str_replace(['-', ' '], '_', $normalized);
    $normalized = preg_replace('/_+/', '_', $normalized) ?? $normalized;
    $normalized = trim($normalized, '_');
    if ($normalized === '') {
        return '';
    }
    $compact = str_replace('_', '', $normalized);
    if ($compact === 'leftarm' || $compact === 'larm') {
        return 'left_arm';
    }
    if ($compact === 'rightarm' || $compact === 'rarm') {
        return 'right_arm';
    }
    if ($compact === 'leftleg' || $compact === 'lleg') {
        return 'left_leg';
    }
    if ($compact === 'rightleg' || $compact === 'rleg') {
        return 'right_leg';
    }
    return $normalized;
}

function stobeBaseLimbToken(string $rawLimb): string {
    $normalized = stobeNormalizeLimbToken($rawLimb);
    if ($normalized === '') {
        return '';
    }
    if (strpos($normalized, 'left_arm') === 0) {
        return 'left_arm';
    }
    if (strpos($normalized, 'right_arm') === 0) {
        return 'right_arm';
    }
    if (strpos($normalized, 'left_leg') === 0) {
        return 'left_leg';
    }
    if (strpos($normalized, 'right_leg') === 0) {
        return 'right_leg';
    }
    if (strpos($normalized, 'head') === 0) {
        return 'head';
    }
    if ($normalized === 'stomach' || strpos($normalized, 'torso') === 0) {
        return 'stomach';
    }
    return $normalized;
}

function stobeParseLimbPayload(mixed $limbsRaw): array {
    if (is_array($limbsRaw)) {
        return $limbsRaw;
    }
    $text = trim(strval($limbsRaw));
    if ($text === '') {
        return [];
    }
    $decoded = json_decode($text, true);
    return is_array($decoded) ? $decoded : [];
}

function stobeParseRoboticLimbList(mixed $roboticLimbRaw): array {
    $roboticLimbList = [];
    if (is_array($roboticLimbRaw)) {
        $roboticLimbList = $roboticLimbRaw;
    } elseif (is_string($roboticLimbRaw) && trim($roboticLimbRaw) !== '') {
        $decodedRobotLimbs = json_decode($roboticLimbRaw, true);
        if (is_array($decodedRobotLimbs)) {
            $roboticLimbList = $decodedRobotLimbs;
        }
    }

    $normalizedList = [];
    $seen = [];
    foreach ($roboticLimbList as $limbNameRaw) {
        $baseToken = stobeBaseLimbToken(strval($limbNameRaw));
        if ($baseToken === '' || isset($seen[$baseToken])) {
            continue;
        }
        $seen[$baseToken] = true;
        $normalizedList[] = $baseToken;
        if (count($normalizedList) >= 8) {
            break;
        }
    }
    return $normalizedList;
}

function stobeFormatRoboticLimbLabels(array $roboticLimbList, int $maxLabels = 6): array {
    $labels = [];
    foreach ($roboticLimbList as $limbNameRaw) {
        $normalized = stobeBaseLimbToken(strval($limbNameRaw));
        if ($normalized === '') {
            continue;
        }
        if ($normalized === 'left_arm') {
            $labels[] = 'left arm prosthetic';
        } elseif ($normalized === 'right_arm') {
            $labels[] = 'right arm prosthetic';
        } elseif ($normalized === 'left_leg') {
            $labels[] = 'left leg prosthetic';
        } elseif ($normalized === 'right_leg') {
            $labels[] = 'right leg prosthetic';
        } elseif ($normalized === 'head') {
            $labels[] = 'robotic head';
        } elseif ($normalized === 'stomach') {
            $labels[] = 'robotic torso';
        } else {
            $labels[] = str_replace('_', ' ', $normalized);
        }
        if (count($labels) >= max(1, $maxLabels)) {
            break;
        }
    }
    return $labels;
}

function stobeDescribeLimbStatus(mixed $limbsRaw, mixed $roboticLimbRaw = null): string {
    $limbs = stobeParseLimbPayload($limbsRaw);
    $roboticLimbList = stobeParseRoboticLimbList($roboticLimbRaw);
    $roboticLabels = stobeFormatRoboticLimbLabels($roboticLimbList, 6);
    $roboticLookup = [];
    foreach ($roboticLimbList as $roboticLimb) {
        $roboticLookup[$roboticLimb] = true;
    }

    $readNumeric = static function (mixed $value): ?float {
        if (is_int($value) || is_float($value)) {
            return floatval($value);
        }
        if (is_string($value) && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', trim($value)) === 1) {
            return floatval($value);
        }
        return null;
    };
    $readFlag = static function (mixed $value): ?bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return floatval($value) > 0.0;
        }
        if (!is_string($value)) {
            return null;
        }
        $trimmed = strtolower(trim($value));
        if ($trimmed === '') {
            return null;
        }
        if (in_array($trimmed, ['1', 'true', 'yes', 'y', 'on'], true)) {
            return true;
        }
        if (in_array($trimmed, ['0', 'false', 'no', 'n', 'off'], true)) {
            return false;
        }
        if (preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $trimmed) === 1) {
            return floatval($trimmed) > 0.0;
        }
        return null;
    };

    $tracked = ['head', 'stomach', 'left_arm', 'right_arm', 'left_leg', 'right_leg'];
    $missingTracked = ['left_arm', 'right_arm', 'left_leg', 'right_leg'];
    $missingLabelMap = [
        'head' => 'head',
        'stomach' => 'stomach',
        'left_arm' => 'left arm',
        'right_arm' => 'right arm',
        'left_leg' => 'left leg',
        'right_leg' => 'right leg',
    ];
    $missingLabels = [];
    $missingSeen = [];
    $brokenLabels = [];
    $damagedLabels = [];

    foreach ($tracked as $base) {
        $current = null;
        $max = null;
        $rawMax = null;
        if (array_key_exists($base . '_current', $limbs)) {
            $current = $readNumeric($limbs[$base . '_current']);
        }
        if ($current === null && array_key_exists($base, $limbs)) {
            $current = $readNumeric($limbs[$base]);
        }
        if (array_key_exists($base . '_max', $limbs)) {
            $max = $readNumeric($limbs[$base . '_max']);
        }
        $rawMax = $max;
        if ($current === null) {
            continue;
        }
        if ($max === null || $max <= 0.0) {
            $max = 100.0;
        }

        $current = max(0.0, min($current, $max));
        $pct = ($current / $max) * 100.0;
        $baseToken = stobeBaseLimbToken($base);
        $isRobotic = ($baseToken !== '' && isset($roboticLookup[$baseToken]));
        $displayLabel = $missingLabelMap[$baseToken] ?? str_replace('_', ' ', $baseToken);
        $explicitMissing = null;
        $missingFlagKeys = [$base . '_missing', $base . '_lost', $base . '_gone', 'missing_' . $base];
        foreach ($missingFlagKeys as $flagKey) {
            if (!array_key_exists($flagKey, $limbs)) {
                continue;
            }
            $parsedFlag = $readFlag($limbs[$flagKey]);
            if ($parsedFlag === null) {
                continue;
            }
            $explicitMissing = $parsedFlag;
            break;
        }
        $isMissing = ($explicitMissing === true);
        if (!$isMissing && $explicitMissing === null && $rawMax !== null && $rawMax <= 0.0 && $current <= 0.0) {
            $isMissing = true;
        }
        if ($isMissing && !$isRobotic && in_array($baseToken, $missingTracked, true) && !isset($missingSeen[$baseToken])) {
            $missingSeen[$baseToken] = true;
            $missingLabels[] = $displayLabel;
            continue;
        }

        if ($pct <= 0.0) {
            $brokenLabels[] = $displayLabel;
            continue;
        }
        if ($pct < 95.0) {
            $damagedLabels[] = $displayLabel;
        }
    }

    $statusParts = [];
    if (count($missingLabels) > 0) {
        $statusParts[] = 'Missing: ' . implode(', ', $missingLabels);
    }
    if (count($brokenLabels) > 0) {
        $statusParts[] = 'Broken: ' . implode(', ', $brokenLabels);
    }
    if (count($damagedLabels) > 0) {
        $statusParts[] = 'Damaged: ' . implode(', ', $damagedLabels);
    }
    if (count($roboticLabels) > 0) {
        $statusParts[] = 'Robotic: ' . implode(', ', $roboticLabels);
    }
    if (count($missingLabels) === 0) {
        if (count($statusParts) === 0) {
            $statusParts[] = 'All limbs intact';
        } else {
            $statusParts[count($statusParts) - 1] .= ', all limbs intact';
        }
    }

    return implode('; ', $statusParts);
}

function buildWorldStateBlock(array $npcData): string {
    $fields = [];
    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    $extendedData = normalizeNpcExtendedDataPayload($npcData['extended_data'] ?? []);
    $includeWorld = stobePromptContextOptionEnabled('enabled_sections', 'world');
    $includeCurrentCondition = stobePromptContextOptionEnabled('enabled_state_subsections', 'current_condition');
    $includeActivityState = stobePromptContextOptionEnabled('enabled_state_subsections', 'activity_state');
    $includeEquipment = stobePromptContextOptionEnabled('enabled_state_subsections', 'equipment');
    $includePersonalInventory = stobePromptContextOptionEnabled('enabled_state_subsections', 'personal_inventory');
    $includeMerchantInventory = stobePromptContextOptionEnabled('enabled_state_subsections', 'merchant_inventory');
    $includeBounty = stobePromptContextOptionEnabled('enabled_character_subsections', 'bounty');
    $parseFlag = static function (mixed $value): ?bool {
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
            if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off', 'disabled'], true)) {
                return false;
            }
        }
        return null;
    };
    $rawEnvironment = $extendedData['environment'] ?? ($metadata['environment'] ?? []);
    $environment = [];
    if (is_array($rawEnvironment)) {
        $environment = $rawEnvironment;
    } elseif (is_string($rawEnvironment) && trim($rawEnvironment) !== '') {
        $decodedEnvironment = json_decode($rawEnvironment, true);
        if (is_array($decodedEnvironment)) {
            $environment = $decodedEnvironment;
        }
    }
    $normalizeEnvironmentToken = static function (mixed $value): string {
        if (is_array($value) || is_object($value) || $value === null) {
            return '';
        }
        $text = trim(strval($value));
        if ($text === '') {
            return '';
        }
        $lower = strtolower($text);
        if (in_array($lower, ['unknown', 'none', 'n/a', 'null', 'unset'], true)) {
            return '';
        }
        return $text;
    };
    $pickEnvironmentToken = static function (array $source, array $keys) use ($normalizeEnvironmentToken): string {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $token = $normalizeEnvironmentToken($source[$key]);
            if ($token !== '') {
                return $token;
            }
        }
        return '';
    };
    $useBuildingToken = stobeShouldUseBuildingInWorldLocation($environment, $metadata);
    $locationCandidates = [];
    if ($useBuildingToken) {
        $locationCandidates[] = $pickEnvironmentToken($environment, ['building_name', 'indoors_name']);
    }
    $locationCandidates[] = $pickEnvironmentToken($environment, ['town_name', 'town', 'city', 'settlement']);
    $locationCandidates[] = $pickEnvironmentToken($environment, ['zone_name', 'zone']);
    $locationCandidates[] = $pickEnvironmentToken($environment, ['region', 'region_name']);
    if (count(array_filter($locationCandidates, static function (string $value): bool {
        return $value !== '';
    })) === 0) {
        $locationCandidates = [
            $normalizeEnvironmentToken($npcData['town'] ?? ''),
            $normalizeEnvironmentToken($npcData['zone'] ?? ''),
            $normalizeEnvironmentToken($npcData['region'] ?? ''),
        ];
    }
    $locationParts = [];
    $seenLocationParts = [];
    foreach ($locationCandidates as $candidate) {
        if ($candidate === '') {
            continue;
        }
        $dedupeKey = strtolower($candidate);
        if (isset($seenLocationParts[$dedupeKey])) {
            continue;
        }
        $seenLocationParts[$dedupeKey] = true;
        $locationParts[] = $candidate;
    }
    if ($includeWorld && count($locationParts) > 0) {
        $locationText = implode(', ', $locationParts);
        $locationFlags = [];
        $indoorsFlag = $parseFlag($environment['indoors'] ?? null);
        $outdoorsFlag = $parseFlag($environment['outdoors'] ?? null);
        $inTownFlag = $parseFlag($environment['in_town'] ?? null);
        if ($indoorsFlag === true) {
            $locationFlags[] = 'indoors';
        } elseif ($outdoorsFlag === true) {
            $locationFlags[] = 'outdoors';
        }
        if ($inTownFlag === true) {
            $locationFlags[] = 'in town';
        }
        if (count($locationFlags) > 0) {
            $locationText .= ' (' . implode(', ', $locationFlags) . ')';
        }
        $fields['location'] = truncatePromptValue($locationText, 260);
    }
    $parseWeatherCode = static function (mixed $value): ?int {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return intval(round($value));
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $trimmed) === 1) {
                return intval(round(floatval($trimmed)));
            }
        }
        return null;
    };
    $weatherCode = $parseWeatherCode($environment['weather'] ?? ($metadata['weather'] ?? null));
    if ($includeWorld && $weatherCode !== null) {
        $weatherLabelMap = [
            0 => 'Clear',
            1 => 'Duststorm',
            2 => 'Acid',
            3 => 'Burning',
            4 => 'Gas',
            5 => 'Rain',
        ];
        $fields['weather'] = $weatherLabelMap[$weatherCode] ?? 'Unknown';
    } elseif ($includeWorld) {
        $weatherText = $pickEnvironmentToken(
            $environment,
            ['weather_name', 'weather_state', 'weather_type']
        );
        if ($weatherText !== '') {
            $normalizedWeatherText = preg_replace('/\s+/', ' ', trim($weatherText)) ?? trim($weatherText);
            $fields['weather'] = truncatePromptValue(ucfirst(strtolower($normalizedWeatherText)), 120);
        }
    }

    if ($includeCurrentCondition) {
        $bloodState = stobeDescribeBloodStatus($npcData['blood'] ?? '');
        if ($bloodState !== '') {
            $fields['blood'] = $bloodState;
        }

        $hungerState = stobeDescribeHungerStatus($npcData['hunger'] ?? '');
        if ($hungerState !== '') {
            $fields['hunger'] = $hungerState;
        }
    }

    $characterState = trim(strval($metadata['character_state'] ?? ''));
    if ($includeActivityState && $characterState !== '' && strtolower($characterState) !== 'normal') {
        $fields['state'] = $characterState;
    }

    $activityFlags = [];
    $activitySignals = [];
    $activityMap = [
        'is_in_combat' => 'in combat',
        'is_attacking' => 'attacking',
        'is_sneaking' => 'sneaking',
        'is_running' => 'running',
        'is_moving' => 'moving',
    ];
    foreach ($activityMap as $key => $label) {
        $parsed = $parseFlag($metadata[$key] ?? null);
        if ($parsed === true) {
            $activityFlags[] = $label;
            $activitySignals[$key] = true;
        }
    }

    if ($includeActivityState) {
        $currentAction = trim(strval($metadata['current_action'] ?? ''));
        if ($currentAction === '') {
            if (($activitySignals['is_attacking'] ?? false) === true) {
                $currentAction = 'attacking';
            } elseif (($activitySignals['is_in_combat'] ?? false) === true) {
                $currentAction = 'combat';
            } elseif (($activitySignals['is_sneaking'] ?? false) === true) {
                $currentAction = 'sneaking';
            } elseif (($activitySignals['is_running'] ?? false) === true) {
                $currentAction = 'running';
            } elseif (($activitySignals['is_moving'] ?? false) === true) {
                $currentAction = 'moving';
            } elseif ($characterState !== '' && strtolower($characterState) !== 'normal') {
                $currentAction = strtolower($characterState);
            } else {
                $currentAction = 'idle';
            }
        }
        $fields['current_action'] = $currentAction;

        if (count($activityFlags) > 0) {
            $fields['action_flags'] = implode(', ', $activityFlags);
        }

        $attackTarget = trim(strval($metadata['attack_target'] ?? ''));
        if ($attackTarget !== '') {
            $fields['attack_target'] = $attackTarget;
        }
    }

    $bounty = function_exists('stobeBountyAmountFromPayload')
        ? stobeBountyAmountFromPayload($npcData['bounty'] ?? [])
        : intval($npcData['bounty'] ?? 0);
    if ($includeBounty && $bounty > 0) {
        $fields['bounty'] = number_format($bounty) . ' Cats';
    }

    $inventoryContext = buildInventoryContextFromMetadata($metadata);
    $hasInventoryContext = boolval($inventoryContext['has_items'] ?? false);

    $equipmentRaw = trim(strval($inventoryContext['equipment'] ?? ''));
    $usedEquipmentFallback = false;
    if ($equipmentRaw === '') {
        $equipmentRaw = strval($npcData['equipment'] ?? '');
        $usedEquipmentFallback = true;
    }
    if ($usedEquipmentFallback) {
        $equipmentRaw = stobeEnrichItemCsvWithDescriptions($equipmentRaw, 14, 0, 4000);
    }
    $equipment = truncatePromptValue($equipmentRaw, 3200);
    if ($includeEquipment && $equipment !== '') {
        $fields['equipment'] = $equipment;
    }

    $inventoryRaw = trim(strval($inventoryContext['inventory'] ?? ''));
    $usedInventoryFallback = false;
    if ($inventoryRaw === '' && !$hasInventoryContext) {
        $inventoryRaw = strval($npcData['inventory'] ?? '');
        $usedInventoryFallback = true;
    }
    if ($usedInventoryFallback) {
        $inventoryRaw = stobeEnrichItemCsvWithDescriptions($inventoryRaw, 16, 0, 4500);
    }
    $inventory = truncatePromptValue($inventoryRaw, 3600);
    if ($includePersonalInventory && $inventory !== '') {
        $fields['personal_inventory'] = $inventory;
    }

    $merchantInventoryRaw = trim(strval(
        $inventoryContext['merchant_inventory']
            ?? ($inventoryContext['trader_inventory'] ?? '')
    ));
    $merchantInventory = truncatePromptValue($merchantInventoryRaw, 3600);
    if ($includeMerchantInventory && $merchantInventory !== '') {
        $fields['merchant_inventory_rule'] = "This is the character's live stock currently offered for sale. It overrides conflicting biography, occupation, goals, and earlier dialogue. When asked about goods or prices, acknowledge that the character is trading and answer from this inventory.";
        $fields['merchant_inventory'] = $merchantInventory;
    }

    $roboticLimbList = stobeParseRoboticLimbList($metadata['robotic_limbs'] ?? []);
    if ($includeCurrentCondition) {
        $limbState = stobeDescribeLimbStatus($npcData['limbs'] ?? '', $roboticLimbList);
        if ($limbState !== '') {
            $fields['limb_status'] = $limbState;
        }

        $roboticLabels = stobeFormatRoboticLimbLabels($roboticLimbList, 6);
        if (count($roboticLabels) > 0) {
            $fields['robotic_limbs'] = implode(', ', $roboticLabels);
        } else {
            $hasRobotics = $parseFlag($metadata['has_robotic_limbs'] ?? null);
            if ($hasRobotics === true) {
                $fields['robotic_limbs'] = 'Robotics detected';
            }
        }
    }

    if (count($fields) === 0) {
        return '';
    }

    $xml = ['<character_state>'];
    foreach ($fields as $tag => $value) {
        $xml[] = '  <' . $tag . '>' . stobePromptXmlEscape($value) . '</' . $tag . '>';
    }
    $xml[] = '</character_state>';
    return implode("\n", $xml);
}

function buildPlayerBaseStateBlock(array $npcData): string
{
    if (!stobePromptContextOptionEnabled('enabled_sections', 'player_base')) {
        return '';
    }

    $extended = $npcData['extended_data'] ?? [];
    if (is_string($extended)) {
        $decoded = json_decode($extended, true);
        $extended = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($extended)) {
        return '';
    }

    $base = function_exists('stobeNormalizePlayerBaseSnapshot')
        ? stobeNormalizePlayerBaseSnapshot($extended['player_base'] ?? [], false)
        : [];
    $baseFromPresence = false;
    $ownFresh = boolval($base['inside'] ?? false)
        && !(intval($base['server_observed_at'] ?? 0) > 0 && (time() - intval($base['server_observed_at'])) > 90);
    if (!$ownFresh) {
        // The DLL does not fill in per-NPC base presence; a faction member knows the
        // player's base when the player is inside it (server-side presence snapshot).
        $inFaction = npcIsInPlayerFaction($npcData);
        $row = ($inFaction && function_exists('stobeGetCurrentPlayerBaseState'))
            ? stobeGetCurrentPlayerBaseState(90)
            : [];
        if ($inFaction && (!is_array($row) || trim(strval($row['base_id'] ?? '')) === '')) {
            // Presence follows the selected character, who may be out at the base's own
            // mine or well (outside the town radius). Use the nearest known base instead.
            $qx = is_numeric($_GET['loc_x'] ?? null) ? floatval($_GET['loc_x']) : null;
            $qz = is_numeric($_GET['loc_z'] ?? null) ? floatval($_GET['loc_z']) : null;
            if ($qx !== null && $qz !== null) {
                try {
                    $near = $GLOBALS['db']->fetchOne(
                        "SELECT b.* FROM player_base_locations l
                           JOIN player_bases b ON b.base_id = l.base_id
                          WHERE (l.x - $1) * (l.x - $1) + (l.z - $2) * (l.z - $2) <= 1500.0 * 1500.0
                            AND b.last_seen_at >= NOW() - INTERVAL '3 hours'
                          ORDER BY (l.x - $1) * (l.x - $1) + (l.z - $2) * (l.z - $2), b.last_seen_at DESC
                          LIMIT 1",
                        [$qx, $qz]
                    );
                    if (is_array($near)) {
                        $row = $near;
                    }
                } catch (Throwable $e) {
                    $row = [];
                }
            }
        }
        if (!is_array($row) || trim(strval($row['base_id'] ?? '')) === '') {
            return '';
        }
        $pgBool = static fn($v): bool => $v === true || in_array(strtolower(strval($v)), ['t', 'true', '1'], true);
        $details = json_decode(strval($row['details'] ?? ''), true);
        $base = [
            'inside' => true,
            'name' => strval($row['name'] ?? 'Player Base'),
            'power_generated' => $row['power_generated'] ?? 0,
            'power_required' => $row['power_required'] ?? 0,
            'has_spare_power' => $pgBool($row['has_spare_power'] ?? false),
            'battery_charge' => $row['battery_charge'] ?? 0,
            'battery_capacity' => $row['battery_capacity'] ?? 0,
            'battery_drain' => $row['battery_drain'] ?? 0,
            'battery_charging' => $row['battery_charging'] ?? 0,
            'battery_mode' => $pgBool($row['battery_mode'] ?? false),
            'members_inside' => intval($row['members_inside'] ?? 0),
            'has_gates' => $pgBool($row['has_gates'] ?? false),
            'gates_closed' => $pgBool($row['gates_closed'] ?? false),
            'details' => is_array($details) ? $details : [],
        ];
        $baseFromPresence = true;
    }

    $lines = ['<player_base>'];
    $lines[] = '  <name>' . stobePromptXmlEscape(strval($base['name'] ?? 'Player Base')) . '</name>';
    $lines[] = '  <power_generated>' . stobePromptXmlEscape(strval($base['power_generated'] ?? 0)) . '</power_generated>';
    $lines[] = '  <power_required>' . stobePromptXmlEscape(strval($base['power_required'] ?? 0)) . '</power_required>';
    $lines[] = '  <has_spare_power>' . (!empty($base['has_spare_power']) ? 'true' : 'false') . '</has_spare_power>';
    $lines[] = '  <battery_charge>' . stobePromptXmlEscape(strval($base['battery_charge'] ?? 0)) . '</battery_charge>';
    $lines[] = '  <battery_capacity>' . stobePromptXmlEscape(strval($base['battery_capacity'] ?? 0)) . '</battery_capacity>';
    $lines[] = '  <battery_drain>' . stobePromptXmlEscape(strval($base['battery_drain'] ?? 0)) . '</battery_drain>';
    $lines[] = '  <battery_charging>' . stobePromptXmlEscape(strval($base['battery_charging'] ?? 0)) . '</battery_charging>';
    $lines[] = '  <battery_mode>' . (!empty($base['battery_mode']) ? 'true' : 'false') . '</battery_mode>';
    $lines[] = '  <squad_members_inside>' . intval($base['members_inside'] ?? 0) . '</squad_members_inside>';
    if (!empty($base['has_gates'])) {
        $lines[] = '  <gates>' . (!empty($base['gates_closed']) ? 'closed' : 'open') . '</gates>';
    }
    $details = is_array($base['details'] ?? null) ? $base['details'] : [];
    if (coerceBoolean($details['available'] ?? false)) {
        $appendFields = static function (
            array &$output,
            string $groupName,
            array $group,
            array $fieldNames
        ): void {
            $output[] = '  <' . $groupName . '>';
            foreach ($fieldNames as $fieldName) {
                $output[] = '    <' . $fieldName . '>'
                    . stobePromptXmlEscape(strval($group[$fieldName] ?? 0))
                    . '</' . $fieldName . '>';
            }
            $output[] = '  </' . $groupName . '>';
        };
        $appendGroups = static function (
            array &$output,
            string $containerName,
            array $groups,
            array $fieldNames
        ): void {
            if (count($groups) === 0) {
                return;
            }
            $output[] = '    <' . $containerName . '>';
            foreach (array_slice($groups, 0, 12) as $group) {
                if (!is_array($group)) {
                    continue;
                }
                $output[] = '      <group>';
                $output[] = '        <name>'
                    . stobePromptXmlEscape(strval($group['name'] ?? 'Unknown'))
                    . '</name>';
                foreach ($fieldNames as $fieldName) {
                    $output[] = '        <' . $fieldName . '>'
                        . stobePromptXmlEscape(strval($group[$fieldName] ?? 0))
                        . '</' . $fieldName . '>';
                }
                $output[] = '      </group>';
            }
            $output[] = '    </' . $containerName . '>';
        };

        $appendFields($lines, 'security', $details['security'] ?? [], [
            'alarm_state', 'hostiles_inside', 'gates_total', 'damaged_defenses',
            'destroyed_defenses', 'turrets_total', 'turrets_manned', 'turrets_unpowered',
        ]);

        $infrastructure = is_array($details['infrastructure'] ?? null)
            ? $details['infrastructure']
            : [];
        $infrastructureProblemCount = intval($infrastructure['damaged'] ?? 0)
            + intval($infrastructure['destroyed'] ?? 0)
            + intval($infrastructure['broken'] ?? 0)
            + intval($infrastructure['unpowered'] ?? 0);
        if ($infrastructureProblemCount > 0) {
            $lines[] = '  <infrastructure_problems>';
            foreach (['damaged', 'destroyed', 'broken', 'unpowered'] as $fieldName) {
                $lines[] = '    <' . $fieldName . '>'
                    . intval($infrastructure[$fieldName] ?? 0)
                    . '</' . $fieldName . '>';
            }
            $appendGroups($lines, 'affected_buildings', $infrastructure['issues'] ?? [], [
                'count', 'damaged', 'destroyed', 'broken', 'unpowered',
            ]);
            $lines[] = '  </infrastructure_problems>';
        }

        $construction = is_array($details['construction'] ?? null) ? $details['construction'] : [];
        if (intval($construction['total'] ?? 0) > 0) {
            $lines[] = '  <construction>';
            foreach (['total', 'paused', 'missing_materials', 'average_progress'] as $fieldName) {
                $lines[] = '    <' . $fieldName . '>'
                    . stobePromptXmlEscape(strval($construction[$fieldName] ?? 0))
                    . '</' . $fieldName . '>';
            }
            $appendGroups($lines, 'building_groups', $construction['groups'] ?? [], [
                'count', 'paused', 'missing_materials', 'average_progress',
            ]);
            $lines[] = '  </construction>';
        }

        $appendFields($lines, 'power_resilience', $details['power'] ?? [], [
            'consumers', 'unpowered', 'switched_off', 'generators_total', 'generators_active',
        ]);
        $appendFields($lines, 'supplies', $details['supplies'] ?? [], [
            'food', 'medicine', 'building_materials', 'iron_plates', 'fuel', 'water', 'ammunition',
        ]);

        foreach ([
            'storage' => [
                ['total', 'empty', 'full', 'item_units'],
                ['total', 'empty', 'full', 'item_units'],
            ],
            'production' => [
                ['total', 'active', 'input_blocked', 'output_blocked', 'unpowered', 'staffed', 'average_efficiency'],
                ['total', 'active', 'input_blocked', 'output_blocked', 'unpowered', 'staffed', 'average_efficiency'],
            ],
            'farms' => [
                ['total', 'active', 'needs_water', 'output_full', 'unpowered', 'staffed', 'hydroponic', 'average_yield'],
                ['total', 'active', 'needs_water', 'output_full', 'unpowered', 'staffed', 'hydroponic', 'average_yield'],
            ],
        ] as $groupName => [$fieldNames, $groupFieldNames]) {
            $group = is_array($details[$groupName] ?? null) ? $details[$groupName] : [];
            $lines[] = '  <' . $groupName . '>';
            foreach ($fieldNames as $fieldName) {
                $lines[] = '    <' . $fieldName . '>'
                    . stobePromptXmlEscape(strval($group[$fieldName] ?? 0))
                    . '</' . $fieldName . '>';
            }
            $appendGroups($lines, 'building_groups', $group['groups'] ?? [], $groupFieldNames);
            $lines[] = '  </' . $groupName . '>';
        }
        if (coerceBoolean($details['scan_truncated'] ?? false)) {
            $lines[] = '  <scan_truncated>true</scan_truncated>';
        }
    }
    $lines[] = $baseFromPresence
        ? '  <context>This is your faction\'s own base; the player is inside it now. Its production buildings, farms and storage listed here are yours to work (WORK_GOAL / TASK_GOAL).</context>'
        : '  <context>This character is currently inside this player-owned base perimeter.</context>';
    $lines[] = '</player_base>';
    return implode("\n", $lines);
}

function parseFactionIdentityToken(string $rawFaction): array {
    $raw = trim($rawFaction);
    if ($raw === '') {
        return ['name' => '', 'id' => ''];
    }

    $name = $raw;
    $id = '';
    if (preg_match('/^(.*?)\s*\[\[([^\]]+)\]\]\s*$/u', $raw, $matches) === 1) {
        $parsedName = trim(strval($matches[1] ?? ''));
        $parsedId = trim(strval($matches[2] ?? ''));
        if ($parsedId !== '') {
            $id = $parsedId;
            $name = $parsedName !== '' ? $parsedName : $raw;
        }
    } elseif (preg_match('/^(.*?)\s*\[([^\]]+)\]\s*$/u', $raw, $matches) === 1) {
        $parsedName = trim(strval($matches[1] ?? ''));
        $parsedId = trim(strval($matches[2] ?? ''));
        if ($parsedId !== '') {
            $id = $parsedId;
            $name = $parsedName !== '' ? $parsedName : $raw;
        }
    }
    $name = trim(strval(preg_replace('/\s*\[\[[^\]]+\]\]\s*$/u', '', $name)));
    $name = trim(strval(preg_replace('/\s*\[[^\]]+\]\s*$/u', '', $name)));

    $nameLower = strtolower($name);
    if (in_array($nameLower, ['unknown', 'neutral', 'none', 'n/a'], true)) {
        $name = '';
    }

    return ['name' => $name, 'id' => $id];
}

function stobeFactionDisplayName(string $rawFaction): string {
    $identity = parseFactionIdentityToken($rawFaction);
    $name = trim(strval($identity['name'] ?? ''));
    if ($name === '') {
        return '';
    }
    return $name;
}

function stobeFactionRelationStateTableAvailable(): bool {
    static $available = null;
    if ($available !== null) {
        return boolval($available);
    }

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        $available = false;
        return false;
    }

    try {
        $row = $db->fetchOne("SELECT to_regclass('public.faction_relation_state') AS rel");
    } catch (Throwable $exception) {
        $available = false;
        return false;
    }

    $available = is_array($row) && trim(strval($row['rel'] ?? '')) !== '';
    return boolval($available);
}

// Read the selected NPC faction's synced standings without copying them into its profile.
function stobeGetNpcFactionStandings(array $npcData): array {
    $identity = getNpcFactionIdentityFromProfile($npcData);
    $name = trim(strval($identity['name'] ?? ''));
    $factionId = trim(strval($identity['id'] ?? ''));
    $result = ['faction_name' => $name, 'status' => 'unknown_faction', 'relations' => [], 'truncated' => false];
    if ($name === '' && $factionId === '') {
        return $result;
    }
    $result['faction_name'] = $name !== '' ? $name : $factionId;
    $result['status'] = 'unavailable';
    if (!stobeFactionRelationStateTableAvailable()) {
        return $result;
    }

    // Prefer stable identity; a known but unmatched ID must not borrow a namesake's standings.
    if ($factionId !== '') {
        $where = 'LOWER(source_string_id) = LOWER($1)';
        $params = [$factionId];
        if (ctype_digit($factionId) && intval($factionId) > 0) {
            $where .= ' OR source_numeric_id = $2';
            $params[] = intval($factionId);
        }
    } else {
        $where = 'LOWER(source_name) = LOWER($1)';
        $params = [$name];
    }

    try {
        $queryResult = $GLOBALS['db']->exec(
            "SELECT * FROM (
                SELECT DISTINCT ON (target_key) * FROM (
                    SELECT source_name, source_string_id, target_name, target_string_id, target_numeric_id,
                           relation, alliance, war, coexists, game_ts, updated_at, id,
                           CASE WHEN target_string_id <> '' THEN 'sid:' || LOWER(target_string_id)
                                WHEN target_numeric_id > 0 THEN 'id:' || target_numeric_id::text
                                ELSE 'name:' || LOWER(target_name) END AS target_key
                    FROM faction_relation_state WHERE " . $where . "
                ) matches
                ORDER BY target_key, game_ts DESC, updated_at DESC, id DESC
            ) latest ORDER BY LOWER(target_name), target_key LIMIT 202",
            $params
        );
        if ($queryResult === false) {
            stobeLogWarn('NPC faction standings query failed');
            return $result;
        }
        $rows = pg_fetch_all($queryResult) ?: [];
        foreach ($rows as $row) {
            $targetIdentity = [
                'name' => trim(strval($row['target_name'] ?? '')),
                'id' => trim(strval($row['target_string_id'] ?? '')),
            ];
            $sourceIdentity = ['name' => strval($row['source_name'] ?? $name), 'id' => strval($row['source_string_id'] ?? $factionId)];
            if (stobeFactionIdentityMatches($sourceIdentity, $targetIdentity)) {
                continue;
            }
            $result['faction_name'] = stobeResolvePlayerFactionPromptDisplayName($sourceIdentity['name'], $sourceIdentity);
            $targetName = stobeResolvePlayerFactionPromptDisplayName($targetIdentity['name'], $targetIdentity);
            if ($targetName === '') {
                $targetName = $targetIdentity['id'] !== '' ? $targetIdentity['id'] : 'Unknown faction';
            }
            $value = $row['relation'] ?? null;
            $result['relations'][] = [
                'name' => $targetName,
                'relation' => is_numeric($value) && is_finite(floatval($value)) ? floatval($value) : null,
                'alliance' => coerceBoolean($row['alliance'] ?? false),
                'war' => coerceBoolean($row['war'] ?? false),
                'coexists' => coerceBoolean($row['coexists'] ?? false),
            ];
        }
        usort($result['relations'], static fn(array $left, array $right): int => strcasecmp($left['name'], $right['name']));
        $result['truncated'] = count($result['relations']) > 200;
        $result['relations'] = array_slice($result['relations'], 0, 200);
        $result['status'] = count($result['relations']) > 0 ? 'ok' : 'empty';
    } catch (Throwable $exception) {
        stobeLogException($exception, 'Failed to read NPC faction standings');
        $result['relations'] = [];
        $result['status'] = 'unavailable';
    }
    return $result;
}

function stobeFactionIdentityMatches(array $leftIdentity, array $rightIdentity): bool {
    $leftId = trim(strval($leftIdentity['id'] ?? ''));
    $rightId = trim(strval($rightIdentity['id'] ?? ''));
    if ($leftId !== '' && $rightId !== '') {
        return strcasecmp($leftId, $rightId) === 0;
    }

    $leftName = trim(strval($leftIdentity['name'] ?? ''));
    $rightName = trim(strval($rightIdentity['name'] ?? ''));
    if ($leftName !== '' && $rightName !== '') {
        return strcasecmp($leftName, $rightName) === 0;
    }

    return false;
}

function stobeBuildFactionRelationTokens(array $identity): array {
    $tokens = [];
    $id = trim(strval($identity['id'] ?? ''));
    if ($id !== '') {
        $tokens[] = 'sid:' . strtolower($id);
        if (preg_match('/^-?[0-9]+$/', $id) === 1) {
            $numericId = intval($id);
            if ($numericId > 0) {
                $tokens[] = 'id:' . strval($numericId);
            }
        }
    }

    $name = trim(strval($identity['name'] ?? ''));
    if ($name !== '') {
        $tokens[] = 'name:' . strtolower($name);
    }

    $deduped = [];
    $seen = [];
    foreach ($tokens as $token) {
        if ($token === '' || isset($seen[$token])) {
            continue;
        }
        $seen[$token] = true;
        $deduped[] = $token;
    }
    return $deduped;
}

function stobeLookupDirectedFactionRelationValue(array $sourceIdentity, array $targetIdentity): ?float {
    if (stobeFactionIdentityMatches($sourceIdentity, $targetIdentity)) {
        return 100.0;
    }
    if (!stobeFactionRelationStateTableAvailable()) {
        return null;
    }

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        return null;
    }

    $sourceTokens = stobeBuildFactionRelationTokens($sourceIdentity);
    $targetTokens = stobeBuildFactionRelationTokens($targetIdentity);
    $mergeKeys = [];
    foreach ($sourceTokens as $sourceToken) {
        foreach ($targetTokens as $targetToken) {
            $mergeKey = trim($sourceToken) . '->' . trim($targetToken);
            if ($mergeKey !== '' && !isset($mergeKeys[$mergeKey])) {
                $mergeKeys[$mergeKey] = true;
            }
        }
    }

    try {
        if (count($mergeKeys) > 0) {
            $where = [];
            $params = [];
            $idx = 1;
            foreach (array_keys($mergeKeys) as $mergeKey) {
                $where[] = 'merge_key = $' . strval($idx);
                $params[] = $mergeKey;
                $idx++;
            }

            $row = $db->fetchOne(
                "SELECT relation
                 FROM faction_relation_state
                 WHERE " . implode(' OR ', $where) . "
                 ORDER BY game_ts DESC, updated_at DESC, id DESC
                 LIMIT 1",
                $params
            );
            if (is_array($row) && array_key_exists('relation', $row)) {
                return floatval($row['relation']);
            }
        }

        $sourceName = trim(strval($sourceIdentity['name'] ?? ''));
        $targetName = trim(strval($targetIdentity['name'] ?? ''));
        if ($sourceName !== '' && $targetName !== '') {
            $row = $db->fetchOne(
                "SELECT relation
                 FROM faction_relation_state
                 WHERE LOWER(source_name) = LOWER($1)
                   AND LOWER(target_name) = LOWER($2)
                 ORDER BY game_ts DESC, updated_at DESC, id DESC
                 LIMIT 1",
                [$sourceName, $targetName]
            );
            if (is_array($row) && array_key_exists('relation', $row)) {
                return floatval($row['relation']);
            }
        }
    } catch (Throwable $exception) {
        return null;
    }

    return null;
}

function stobeFactionRelationPromptLabel(?float $relationValue): string {
    $relation = is_numeric($relationValue) ? floatval($relationValue) : 0.0;
    if ($relation > 0.0) {
        return 'Friendly';
    }
    if ($relation < 0.0) {
        return 'Hostile';
    }
    return 'Neutral';
}

function stobeFormatFactionWithRelationPromptLabel(
    string $factionName,
    array $speakerFactionIdentity,
    array $targetFactionIdentity
): string {
    $name = trim($factionName);
    if ($name === '') {
        return '';
    }

    if (stobeFactionIdentityMatches($speakerFactionIdentity, $targetFactionIdentity)) {
        return $name . ' [Same Faction]';
    }

    $relationValue = stobeLookupDirectedFactionRelationValue($speakerFactionIdentity, $targetFactionIdentity);
    $relationLabel = stobeFactionRelationPromptLabel($relationValue);
    return $name . ' [' . $relationLabel . ']';
}

function stobeGetPlayerFactionCustomNameSetting(): string {
    static $cached = null;
    if ($cached !== null) {
        return strval($cached);
    }

    $cached = trim(strval(getSetting('PLAYER_FACTION_CUSTOM_NAME', '')));
    return strval($cached);
}

function stobeGetPlayerFactionPromptSetting(): string {
    static $cached = null;
    if ($cached !== null) {
        return strval($cached);
    }

    $cached = trim(strval(getSetting('PLAYER_FACTION_PROMPT', '')));
    return strval($cached);
}

function stobeResolvePlayerFactionPromptDisplayName(string $factionName, array $factionIdentity = []): string {
    $name = trim($factionName);
    if ($name === '') {
        return '';
    }

    $customName = stobeGetPlayerFactionCustomNameSetting();
    if ($customName === '') {
        return $name;
    }

    $playerFaction = getCurrentPlayerFactionIdentity();
    if (stobeFactionIdentityMatches($playerFaction, $factionIdentity)) {
        return $customName;
    }

    if (strcasecmp($name, 'Nameless') !== 0) {
        return $name;
    }

    $playerFactionName = trim(strval($playerFaction['name'] ?? ''));
    if ($playerFactionName === '' || strcasecmp($playerFactionName, 'Nameless') === 0) {
        return $customName;
    }

    return $name;
}

function stobeAliasPlayerFactionNameInText(string $text, array $factionIdentity = [], bool $forceAlias = false): string {
    $customName = stobeGetPlayerFactionCustomNameSetting();
    if ($customName === '') {
        return $text;
    }

    $playerFaction = getCurrentPlayerFactionIdentity();
    $shouldAlias = $forceAlias || stobeFactionIdentityMatches($playerFaction, $factionIdentity);
    if (!$shouldAlias) {
        return $text;
    }

    $aliased = $text;
    $candidateNames = [];
    $playerFactionName = trim(strval($playerFaction['name'] ?? ''));
    if ($playerFactionName !== '') {
        $candidateNames[] = $playerFactionName;
    }
    $candidateNames[] = 'Nameless';
    $seen = [];

    foreach ($candidateNames as $candidate) {
        $name = trim(strval($candidate));
        if ($name === '') {
            continue;
        }
        $key = strtolower($name);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        if (strcasecmp($name, $customName) === 0) {
            continue;
        }
        $pattern = '/\b' . preg_quote($name, '/') . '\b/iu';
        $aliased = preg_replace($pattern, $customName, $aliased) ?? $aliased;
    }

    return $aliased;
}

function stobePromptHasPlayerFactionContext(array $npcData): bool {
    if (npcIsInPlayerFaction($npcData)) {
        return true;
    }

    $customName = trim(stobeGetPlayerFactionCustomNameSetting());
    $hasPlayerFactionNearby = static function (array $entries) use ($customName): bool {
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entry = stobeEnrichNearbyEntryFromNpcProfile($entry);
            if (nearbyEntryIsInPlayerFaction($entry)) {
                return true;
            }
            if ($customName !== '') {
                $factionRaw = trim(strval($entry['faction'] ?? ''));
                if ($factionRaw !== '' && stripos($factionRaw, $customName) !== false) {
                    return true;
                }
            }
        }
        return false;
    };

    $extendedData = normalizeNpcExtendedDataPayload($npcData['extended_data'] ?? []);
    $nearby = stobeExtractSceneArray($extendedData, 'nearby_actors');
    if (count($nearby) === 0) {
        $nearby = stobeExtractSceneArray($extendedData, 'nearby');
    }
    if ($hasPlayerFactionNearby($nearby)) {
        return true;
    }

    // Keep this in sync with scene prompt fallback logic so player-faction
    // prompt inclusion matches what is actually rendered in <nearby_actors>.
    $peopleRaw = trim(strval($GLOBALS['CACHE_PEOPLE'] ?? ($_GET['people'] ?? '')));
    if ($peopleRaw !== '') {
        $fallbackNearby = stobeBuildNearbyActorsFromPeopleScope($peopleRaw);
        if ($hasPlayerFactionNearby($fallbackNearby)) {
            return true;
        }
    }

    return false;
}

function stobeBuildPlayerFactionPromptBlock(array $npcData): string {
    if (
        !stobePromptContextOptionEnabled('enabled_sections', 'knowledge')
        || !stobePromptContextOptionEnabled('enabled_knowledge_subsections', 'player_faction_prompt')
    ) {
        return '';
    }

    $customPrompt = stobeGetPlayerFactionPromptSetting();
    if (trim($customPrompt) === '') {
        return '';
    }

    $playerFactionIdentity = getCurrentPlayerFactionIdentity();
    $baseName = trim(strval($playerFactionIdentity['name'] ?? ''));
    if ($baseName === '') {
        $baseName = 'Nameless';
    }
    $displayName = stobeResolvePlayerFactionPromptDisplayName($baseName, $playerFactionIdentity);
    if ($displayName === '') {
        $displayName = 'Nameless';
    }

    $title = trim($displayName !== '' ? $displayName : 'Nameless');
    $content = trim($customPrompt);
    $entryText = $title . ': ' . $content;
    return '<entry>' . stobePromptXmlEscape($entryText) . '</entry>';
}

function normalizeNpcMetadataPayload(mixed $metadata): array {
    if (is_array($metadata)) {
        return $metadata;
    }
    if (is_string($metadata) && trim($metadata) !== '') {
        $decoded = json_decode($metadata, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return [];
}

function normalizeNpcExtendedDataPayload(mixed $extendedData): array {
    if (is_array($extendedData)) {
        return $extendedData;
    }
    if (is_string($extendedData) && trim($extendedData) !== '') {
        $decoded = json_decode($extendedData, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return [];
}

function stobeExtractSceneArray(array $payload, string $key): array {
    if (!array_key_exists($key, $payload)) {
        return [];
    }
    $raw = $payload[$key];
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

function stobeDescribeDistanceBand(mixed $rawDistance): string {
    $text = trim(strval($rawDistance));
    if ($text === '' || preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $text) !== 1) {
        return '';
    }

    $distance = floatval($text);
    if ($distance < 0.0) {
        return '';
    }

    if ($distance <= 60.0) {
        return 'Close';
    }
    if ($distance <= 180.0) {
        return 'Nearby';
    }
    return 'Far away';
}

function stobeDescribeNearbyEntryHunger(array $entry): string {
    $direct = trim(strval($entry['hunger'] ?? ''));
    if ($direct !== '') {
        $status = stobeDescribeHungerStatus($direct);
        if ($status !== '') {
            return $status;
        }
    }

    $medical = $entry['medical'] ?? null;
    if (is_array($medical)) {
        $current = trim(strval($medical['hunger'] ?? ''));
        $max = trim(strval($medical['hunger_max'] ?? ($medical['max_hunger'] ?? '')));
        if ($current !== '' && $max !== '') {
            $status = stobeDescribeHungerStatus($current . '/' . $max);
            if ($status !== '') {
                return $status;
            }
        }
    } elseif (is_string($medical) && trim($medical) !== '') {
        $decoded = json_decode($medical, true);
        if (is_array($decoded)) {
            $current = trim(strval($decoded['hunger'] ?? ''));
            $max = trim(strval($decoded['hunger_max'] ?? ($decoded['max_hunger'] ?? '')));
            if ($current !== '' && $max !== '') {
                $status = stobeDescribeHungerStatus($current . '/' . $max);
                if ($status !== '') {
                    return $status;
                }
            }
        }
    }

    return '';
}

function stobeCoerceTruthyPromptFlag(mixed $value): bool {
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return floatval($value) != 0.0;
    }
    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        return in_array($normalized, ['1', 'true', 't', 'yes', 'y', 'on', 'enabled'], true);
    }
    return false;
}

function stobeDescribeDrunkPromptState(mixed $rawLevel, mixed $rawIsDrunk, mixed $rawStatus): string {
    $status = strtolower(trim(strval($rawStatus)));
    if ($status !== '') {
        if (in_array($status, ['passed_out', 'passed out', 'blackout', 'knocked_out', 'knocked out'], true)) {
            return 'Passed out';
        }
        if (in_array($status, ['very_drunk', 'very drunk', 'verydrunk'], true)) {
            return 'Very drunk';
        }
        if (in_array($status, ['drunk', 'tipsy'], true)) {
            return 'Drunk';
        }
        if ($status === 'sober') {
            return '';
        }
    }

    $level = 0;
    if (is_int($rawLevel) || is_float($rawLevel)) {
        $level = intval($rawLevel);
    } elseif (is_string($rawLevel) && preg_match('/^-?[0-9]+$/', trim($rawLevel)) === 1) {
        $level = intval($rawLevel);
    }

    if ($level >= 3) {
        return 'Passed out';
    }
    if ($level >= 2) {
        return 'Very drunk';
    }
    if ($level >= 1 || stobeCoerceTruthyPromptFlag($rawIsDrunk)) {
        return 'Drunk';
    }

    return '';
}

function stobeDescribeHighPromptState(
    mixed $rawIsHigh,
    mixed $rawStatus,
    mixed $rawSecondsRemaining,
    mixed $rawHungerMultiplier
): string {
    $status = strtolower(trim(strval($rawStatus)));
    $isHigh = stobeCoerceTruthyPromptFlag($rawIsHigh);
    if (!$isHigh && $status !== '') {
        $isHigh = in_array($status, ['high', 'stoned', 'drugged'], true);
    }
    if (!$isHigh) {
        return '';
    }

    $hungerMultiplier = 1.0;
    if (is_int($rawHungerMultiplier) || is_float($rawHungerMultiplier)) {
        $hungerMultiplier = floatval($rawHungerMultiplier);
    } elseif (is_string($rawHungerMultiplier) && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', trim($rawHungerMultiplier)) === 1) {
        $hungerMultiplier = floatval($rawHungerMultiplier);
    }
    if ($hungerMultiplier < 1.0) {
        $hungerMultiplier = 1.0;
    }

    $secondsRemaining = 0;
    if (is_int($rawSecondsRemaining) || is_float($rawSecondsRemaining)) {
        $secondsRemaining = max(0, intval($rawSecondsRemaining));
    } elseif (is_string($rawSecondsRemaining) && preg_match('/^-?[0-9]+$/', trim($rawSecondsRemaining)) === 1) {
        $secondsRemaining = max(0, intval($rawSecondsRemaining));
    }

    $parts = ['High'];
    if ($hungerMultiplier > 1.0) {
        $parts[] = 'hunger x' . rtrim(rtrim(sprintf('%.2f', $hungerMultiplier), '0'), '.');
    }
    if ($secondsRemaining > 0) {
        $minutes = intval(ceil($secondsRemaining / 60.0));
        if ($minutes < 1) {
            $minutes = 1;
        }
        $parts[] = strval($minutes) . 'm remaining';
    }

    return implode(' | ', $parts);
}

function stobeDescribeNearbyEntryAppearance(array $entry): string {
    $appearance = trim(strval($entry['appearance'] ?? ($entry['looks'] ?? '')));
    if ($appearance !== '') {
        return trim(preg_replace('/\s+/u', ' ', $appearance) ?? '');
    }

    $race = trim(strval($entry['race'] ?? ''));
    $gender = trim(strval($entry['gender'] ?? ''));
    $parts = [];
    if ($gender !== '' && !in_array(strtolower($gender), ['unknown', 'none', 'n/a'], true)) {
        $parts[] = ucfirst(strtolower($gender));
    }
    if ($race !== '' && !in_array(strtolower($race), ['unknown', 'none', 'n/a'], true)) {
        $parts[] = $race;
    }
    if (count($parts) > 0) {
        return implode(' ', $parts);
    }

    return '';
}

// Resolves detailed profile appearances for the nearby actor list in one database query.
function stobeLoadNearbyNpcAppearanceMap(array $actors): array {
    if (!stobePromptContextOptionEnabled('enabled_character_subsections', 'appearance')) {
        return [];
    }

    $requestedNames = [];
    foreach ($actors as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $existingAppearance = trim(strval($entry['appearance'] ?? ($entry['looks'] ?? '')));
        if ($existingAppearance !== '' || stobeParseFlexibleBool($entry['is_animal'] ?? null) === true) {
            continue;
        }
        $name = normalizeParticipantNameToken(strval($entry['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $requestedNames[strtolower($name)] = true;
        if (count($requestedNames) >= 32) {
            break;
        }
    }
    if (count($requestedNames) === 0) {
        return [];
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db || !is_object($db) || !method_exists($db, 'fetchAll')) {
        return [];
    }

    $namesJson = json_encode(array_keys($requestedNames), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($namesJson) || $namesJson === '') {
        return [];
    }

    $rows = $db->fetchAll(
        "WITH requested AS (
            SELECT value AS lookup_name
            FROM jsonb_array_elements_text($1::jsonb)
         )
         SELECT requested.lookup_name, matched.appearance
         FROM requested
         JOIN LATERAL (
            SELECT n.appearance
            FROM core_npc n
            WHERE (
                    LOWER(n.name) = requested.lookup_name
                    OR LOWER(COALESCE(n.original_name, '')) = requested.lookup_name
                  )
              AND BTRIM(COALESCE(n.appearance, '')) <> ''
            ORDER BY
                CASE WHEN LOWER(n.name) = requested.lookup_name THEN 0 ELSE 1 END,
                CASE WHEN COALESCE(n.metadata->>'storage_id', '') <> '' THEN 0 ELSE 1 END,
                n.gamets_last_updated DESC,
                n.updated_at DESC
            LIMIT 1
         ) AS matched ON TRUE",
        [$namesJson]
    );

    $appearanceByName = [];
    foreach ($rows as $row) {
        $lookupName = strtolower(trim(strval($row['lookup_name'] ?? '')));
        $appearance = trim(strval($row['appearance'] ?? ''));
        if ($lookupName !== '' && $appearance !== '') {
            $appearanceByName[$lookupName] = $appearance;
        }
    }
    return $appearanceByName;
}

function stobeGetActivatedNpcNameLookup(): array {
    static $lookup = null;
    if (is_array($lookup)) {
        return $lookup;
    }

    $lookup = [];
    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        return $lookup;
    }

    $rows = $db->fetchAll("SELECT name FROM core_npc");
    foreach ($rows as $row) {
        $name = normalizeParticipantNameToken(strval($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $lookup[strtolower($name)] = $name;
    }
    return $lookup;
}

function stobeResolveBountyPromptText(mixed $rawBounty, mixed $rawBountyInfo = null, int $maxLength = 420): string {
    $amount = 0;
    if (function_exists('stobeBountyAmountFromPayload')) {
        $amount = intval(stobeBountyAmountFromPayload($rawBounty, $rawBountyInfo));
    } elseif (is_int($rawBounty) || is_float($rawBounty) || (is_string($rawBounty) && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', trim($rawBounty)) === 1)) {
        $amount = intval(floatval($rawBounty));
    }
    if ($amount <= 0) {
        return '';
    }

    $summary = '';
    if (function_exists('stobeNormalizeBountyPayload') && function_exists('stobeBuildBountyText')) {
        $payload = stobeNormalizeBountyPayload($rawBounty, $rawBountyInfo);
        if (is_array($payload) && count($payload) > 0) {
            $summary = trim(strval(stobeBuildBountyText($payload)));
        }
    }

    if ($summary === '') {
        $summary = 'Bounty: ' . number_format($amount) . ' cats';
    } else {
        $summary = str_replace(' | ', '; ', $summary);
        $summary = preg_replace('/\s+/', ' ', $summary) ?? $summary;
        if (stripos($summary, 'Bounty:') !== 0) {
            $summary = 'Bounty: ' . $summary;
        }
    }

    return truncatePromptValue(trim($summary), max(80, $maxLength));
}

function stobeBuildNpcBountyPromptBlock(array $npcData): string {
    if (!stobePromptContextOptionEnabled('enabled_character_subsections', 'bounty')) {
        return '';
    }

    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    $rawBounty = $npcData['bounty'] ?? ($metadata['bounty'] ?? null);
    $rawBountyInfo = $npcData['bounty_info']
        ?? ($npcData['bounty_details']
        ?? ($metadata['bounty_info'] ?? ($metadata['bounty_details'] ?? null)));

    $text = stobeResolveBountyPromptText($rawBounty, $rawBountyInfo, 520);
    if ($text === '') {
        return '';
    }

    return "<bounty>\n" . stobePromptXmlEscape($text) . "\n</bounty>";
}

function stobeBuildNearbyEntryBountyText(array $entry): string {
    $metadata = normalizeNpcMetadataPayload($entry['metadata'] ?? []);
    $rawBounty = $entry['bounty'] ?? ($entry['bounty_payload'] ?? ($metadata['bounty'] ?? null));
    $rawBountyInfo = $entry['bounty_info']
        ?? ($entry['bounty_details']
        ?? ($entry['bounty_payload'] ?? ($metadata['bounty_info'] ?? ($metadata['bounty_details'] ?? null))));
    return stobeResolveBountyPromptText($rawBounty, $rawBountyInfo, 380);
}

function stobeBuildNearbyActorsPromptBlock(array $npcData, string $speakerName = ''): string {
    if (!stobePromptContextOptionEnabled('enabled_sections', 'nearby_actors')) {
        return '';
    }

    $extendedData = normalizeNpcExtendedDataPayload($npcData['extended_data'] ?? []);
    $actors = stobeExtractSceneArray($extendedData, 'nearby_actors');
    if (count($actors) === 0) {
        $actors = stobeExtractSceneArray($extendedData, 'nearby');
    }
    if (count($actors) === 0) {
        return '';
    }

    $speakerKey = strtolower(normalizeParticipantNameToken($speakerName));
    $profileAppearances = stobeLoadNearbyNpcAppearanceMap($actors);
    $speakerFactionIdentity = getNpcFactionIdentityFromProfile($npcData);
    $seen = [];
    $seenFactionLabels = [];
    $animalGroups = [];
    $orderedEntries = [];
    $lines = [
        '<nearby_actors>',
        '# NEARBY ACTORS/NPC IN THE SCENE',
    ];
    $added = 0;

    foreach ($actors as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $name = normalizeParticipantNameToken(strval($entry['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $nameKey = strtolower($name);
        $entryAppearance = trim(strval($entry['appearance'] ?? ($entry['looks'] ?? '')));
        if ($entryAppearance === '' && isset($profileAppearances[$nameKey])) {
            $entry['appearance'] = $profileAppearances[$nameKey];
        }
        $entry = stobeEnrichNearbyEntryFromNpcProfile($entry);
        if ($nameKey === $speakerKey) {
            continue;
        }

        $action = trim(strval($entry['current_action'] ?? ''));
        $actionLower = strtolower(trim(preg_replace('/\s+/', ' ', $action)));
        $isDeadFlag = stobeParseFlexibleBool($entry['is_dead'] ?? null) === true;
        $isKnockedOutFlag = stobeParseFlexibleBool($entry['is_knocked_out'] ?? ($entry['is_knockedout'] ?? null)) === true;
        $isUnconsciousFlag = stobeParseFlexibleBool($entry['is_unconscious'] ?? null) === true;
        $isDeadOrKnockedOut = $isDeadFlag
            || $isKnockedOutFlag
            || $isUnconsciousFlag
            || (preg_match('/\b(dead|unconscious|knocked[ _-]?out|passed[ _-]?out)\b/iu', $actionLower) === 1);

        $isAnimal = stobeParseFlexibleBool($entry['is_animal'] ?? null) === true;

        if ($isAnimal) {
            if (!isset($animalGroups[$nameKey])) {
                $animalGroups[$nameKey] = [
                    'name' => $name,
                    'count' => 0,
                    'action' => '',
                    'distance' => '',
                ];
                $orderedEntries[] = ['type' => 'animal', 'key' => $nameKey];
            }
            $animalGroups[$nameKey]['count']++;
            $distanceBand = stobeDescribeDistanceBand($entry['dist'] ?? '');
            if ($distanceBand !== '' && $animalGroups[$nameKey]['distance'] === '') {
                $animalGroups[$nameKey]['distance'] = $distanceBand;
            }
            if (
                $action !== ''
                && !in_array(strtolower($action), ['unknown', 'none', 'n/a'], true)
                && $animalGroups[$nameKey]['action'] === ''
            ) {
                $animalGroups[$nameKey]['action'] = $action;
            }
            continue;
        }

        if (isset($seen[$nameKey])) {
            continue;
        }
        $seen[$nameKey] = true;

        $race = trim(strval($entry['race'] ?? ''));
        $gender = trim(strval($entry['gender'] ?? ''));
        $descriptorParts = [];
        if ($gender !== '' && !in_array(strtolower($gender), ['unknown', 'none', 'n/a'], true)) {
            $descriptorParts[] = ucfirst(strtolower($gender));
        }
        if ($race !== '' && !in_array(strtolower($race), ['unknown', 'none', 'n/a'], true)) {
            $descriptorParts[] = $race;
        }
        $descriptor = count($descriptorParts) > 0 ? (' (' . implode(' ', $descriptorParts) . ')') : '';

        $detailParts = [];
        $entryFactionIdentity = getFactionIdentityFromNearbyEntry($entry);
        $faction = trim(strval($entryFactionIdentity['name'] ?? ''));
        if ($faction === '') {
            $faction = stobeFactionDisplayName(strval($entry['faction'] ?? ''));
        }
        $faction = stobeResolvePlayerFactionPromptDisplayName($faction, $entryFactionIdentity);
        if ($faction !== '' && !in_array(strtolower($faction), ['unknown', 'none', 'n/a'], true)) {
            $formattedFaction = stobeFormatFactionWithRelationPromptLabel(
                $faction,
                $speakerFactionIdentity,
                $entryFactionIdentity
            );
            $factionKey = strtolower(trim($formattedFaction));
            if ($formattedFaction !== '' && !isset($seenFactionLabels[$factionKey])) {
                $detailParts[] = 'Faction: ' . $formattedFaction;
                $seenFactionLabels[$factionKey] = true;
            }
        }
        $bountyText = stobeBuildNearbyEntryBountyText($entry);
        if ($bountyText !== '') {
            $detailParts[] = $bountyText;
        }
        $displayAction = $action;
        if ($displayAction === '' || in_array(strtolower($displayAction), ['unknown', 'none', 'n/a'], true)) {
            if ($isDeadFlag) {
                $displayAction = 'dead';
            } elseif ($isKnockedOutFlag) {
                $displayAction = 'knocked out';
            } elseif ($isUnconsciousFlag) {
                $displayAction = 'unconscious';
            }
        }
        if ($displayAction !== '' && !in_array(strtolower($displayAction), ['unknown', 'none', 'n/a'], true)) {
            $detailParts[] = 'Action: ' . $displayAction;
        }
        $isCarrying = stobeParseFlexibleBool($entry['is_carrying'] ?? null);
        $carryingTargetName = trim(strval($entry['carrying_target_name'] ?? ''));
        if (!is_bool($isCarrying) && $carryingTargetName !== '') {
            $isCarrying = true;
        }
        if ($isCarrying === true) {
            $detailParts[] = 'Carrying: ' . ($carryingTargetName !== '' ? $carryingTargetName : 'someone');
        }
        $isBeingCarried = stobeParseFlexibleBool($entry['is_being_carried'] ?? null);
        $carriedByName = trim(strval($entry['carried_by_name'] ?? ''));
        if (!is_bool($isBeingCarried) && $carriedByName !== '') {
            $isBeingCarried = true;
        }
        if ($isBeingCarried === true) {
            $detailParts[] = 'Carried by: ' . ($carriedByName !== '' ? $carriedByName : 'someone');
        }
        $hungerState = stobeDescribeNearbyEntryHunger($entry);
        if ($hungerState !== '') {
            $detailParts[] = 'Hunger: ' . $hungerState;
        }
        $drunkState = stobeDescribeDrunkPromptState(
            $entry['drunk_level'] ?? null,
            $entry['is_drunk'] ?? null,
            $entry['drunk_status'] ?? null
        );
        if ($drunkState !== '') {
            $detailParts[] = 'Drunk state: ' . $drunkState;
        }
        $highState = stobeDescribeHighPromptState(
            $entry['is_high'] ?? null,
            $entry['high_status'] ?? null,
            $entry['high_seconds_remaining'] ?? null,
            $entry['high_hunger_rate_multiplier'] ?? null
        );
        if ($highState !== '') {
            $detailParts[] = 'Drug state: ' . $highState;
        }
        $appearance = stobeDescribeNearbyEntryAppearance($entry);
        if ($appearance !== '') {
            $detailParts[] = 'Appearance: ' . $appearance;
        }
        if (!$isDeadOrKnockedOut) {
            $equipmentRaw = trim(strval($entry['equipment'] ?? ''));
            $equipment = truncatePromptValue(
                stobeEnrichItemCsvWithDescriptions($equipmentRaw, 8, 70, 760),
                760
            );
            if ($equipment !== '') {
                $detailParts[] = 'Equipment: ' . $equipment;
            }
        }
        $distanceBand = stobeDescribeDistanceBand($entry['dist'] ?? '');
        if ($distanceBand !== '') {
            $detailParts[] = 'Distance: ' . $distanceBand;
        }

        $line = '## ' . stobePromptXmlEscape($name . $descriptor);
        if (count($detailParts) > 0) {
            $line .= ': ' . stobePromptXmlEscape(implode(' | ', $detailParts));
        }
        $orderedEntries[] = ['type' => 'line', 'line' => $line];
    }

    foreach ($orderedEntries as $entry) {
        if ($added >= 24) {
            break;
        }
        if (($entry['type'] ?? '') === 'line') {
            $line = strval($entry['line'] ?? '');
            if ($line !== '') {
                $lines[] = $line;
                $added++;
            }
            continue;
        }
        if (($entry['type'] ?? '') !== 'animal') {
            continue;
        }

        $animalKey = strval($entry['key'] ?? '');
        $group = $animalGroups[$animalKey] ?? null;
        if (!is_array($group)) {
            continue;
        }
        $count = intval($group['count'] ?? 0);
        if ($count <= 0) {
            continue;
        }
        $animalName = trim(strval($group['name'] ?? ''));
        if ($animalName === '') {
            continue;
        }

        if ($count > 1) {
            $line = '## ' . stobePromptXmlEscape($count . 'x ' . $animalName);
            $lines[] = $line;
            $added++;
            continue;
        }

        $detailParts = [];
        $animalAction = trim(strval($group['action'] ?? ''));
        if ($animalAction !== '' && !in_array(strtolower($animalAction), ['unknown', 'none', 'n/a'], true)) {
            $detailParts[] = 'Action: ' . $animalAction;
        }
        $animalDistance = trim(strval($group['distance'] ?? ''));
        if ($animalDistance !== '') {
            $detailParts[] = 'Distance: ' . $animalDistance;
        }

        $line = '## ' . stobePromptXmlEscape($animalName);
        if (count($detailParts) > 0) {
            $line .= ': ' . stobePromptXmlEscape(implode(' | ', $detailParts));
        }
        $lines[] = $line;
        $added++;
    }

    if ($added === 0) {
        return '';
    }

    $lines[] = '</nearby_actors>';
    return implode("\n", $lines);
}

function stobeEnrichNearbyEntryFromNpcProfile(array $entry): array {
    $name = normalizeParticipantNameToken(strval($entry['name'] ?? ''));
    if ($name === '') {
        return $entry;
    }

    $hasRace = trim(strval($entry['race'] ?? '')) !== '';
    $hasGender = trim(strval($entry['gender'] ?? '')) !== '';
    $hasFaction = trim(strval($entry['faction'] ?? '')) !== '';
    $hasAction = trim(strval($entry['current_action'] ?? '')) !== '';
    $hasEquipment = trim(strval($entry['equipment'] ?? '')) !== '';
    $hasAnimalFlag = array_key_exists('is_animal', $entry);
    $hasDeadFlag = array_key_exists('is_dead', $entry);
    $hasKnockoutFlag = array_key_exists('is_knocked_out', $entry) || array_key_exists('is_knockedout', $entry);
    $hasUnconsciousFlag = array_key_exists('is_unconscious', $entry);

    if (
        $hasRace && $hasGender && $hasFaction && $hasAction && $hasEquipment
        && $hasAnimalFlag && $hasDeadFlag && $hasKnockoutFlag && $hasUnconsciousFlag
    ) {
        return $entry;
    }

    if (!function_exists('getNpcData')) {
        return $entry;
    }

    $npcData = getNpcData($name);
    if (!is_array($npcData) || count($npcData) === 0) {
        return $entry;
    }

    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);

    if (!$hasRace) {
        $race = trim(strval($npcData['race'] ?? ''));
        if ($race !== '') {
            $entry['race'] = $race;
        }
    }

    if (!$hasGender) {
        $gender = trim(strval($npcData['gender'] ?? ''));
        if ($gender !== '') {
            $entry['gender'] = $gender;
        }
    }

    if (!$hasFaction) {
        $faction = trim(strval($npcData['faction'] ?? ''));
        if ($faction !== '') {
            $entry['faction'] = $faction;
        }
    }

    if (trim(strval($entry['faction_id'] ?? ($entry['factionID'] ?? ''))) === '') {
        $factionId = trim(strval($metadata['faction_id'] ?? ($metadata['factionID'] ?? '')));
        if ($factionId !== '') {
            $entry['faction_id'] = $factionId;
        }
    }

    if (!$hasAction) {
        $currentAction = trim(strval($metadata['current_action'] ?? ''));
        if ($currentAction === '') {
            if (stobeParseFlexibleBool($metadata['is_attacking'] ?? null) === true) {
                $currentAction = 'attacking';
            } elseif (stobeParseFlexibleBool($metadata['is_in_combat'] ?? null) === true) {
                $currentAction = 'combat';
            } elseif (stobeParseFlexibleBool($metadata['is_moving'] ?? null) === true) {
                $currentAction = 'moving';
            }
        }
        if ($currentAction === '') {
            $state = strtolower(trim(strval($metadata['character_state'] ?? '')));
            if ($state !== '' && $state !== 'normal') {
                $currentAction = $state;
            }
        }
        if ($currentAction !== '') {
            $entry['current_action'] = $currentAction;
        }
    }

    if (!$hasEquipment) {
        $equipment = trim(strval($npcData['equipment'] ?? ''));
        if ($equipment !== '') {
            $entry['equipment'] = $equipment;
        }
    }

    if (!$hasAnimalFlag) {
        $entry['is_animal'] = coerceBoolean($npcData['is_animal'] ?? ($metadata['is_animal'] ?? false));
    }

    if (!$hasDeadFlag) {
        $entry['is_dead'] = coerceBoolean($metadata['is_dead'] ?? false);
    }
    if (!$hasKnockoutFlag) {
        $isKnockedOut = coerceBoolean($metadata['is_knocked_out'] ?? ($metadata['is_knockedout'] ?? false));
        if ($isKnockedOut) {
            $entry['is_knocked_out'] = true;
        }
    }
    if (!$hasUnconsciousFlag) {
        $isUnconscious = coerceBoolean($metadata['is_unconscious'] ?? false);
        if ($isUnconscious) {
            $entry['is_unconscious'] = true;
        }
    }

    if (trim(strval($entry['dist'] ?? '')) === '') {
        $dist = trim(strval($metadata['dist'] ?? ($metadata['distance'] ?? '')));
        if ($dist !== '' && is_numeric($dist)) {
            $entry['dist'] = $dist;
        }
    }

    if (trim(strval($entry['storage_id'] ?? '')) === '') {
        $storageId = normalizeStorageIdToken($metadata['storage_id'] ?? '');
        if ($storageId !== '') {
            $entry['storage_id'] = $storageId;
        }
    }

    return $entry;
}

function stobeBuildNearbyActorsFromPeopleScope(string $peopleRaw): array {
    $peopleText = trim($peopleRaw);
    if ($peopleText === '') {
        return [];
    }

    $identities = [];
    if (function_exists('extractParticipantIdentities')) {
        $identities = extractParticipantIdentities(['people' => $peopleText]);
    }

    if (!is_array($identities) || count($identities) === 0) {
        $decoded = json_decode($peopleText, true);
        if (is_array($decoded)) {
            foreach ($decoded as $token) {
                if (!is_string($token)) {
                    continue;
                }
                $parsed = function_exists('extractParticipantIdentityToken')
                    ? extractParticipantIdentityToken($token)
                    : ['name' => normalizeParticipantNameToken($token), 'storage_id' => ''];
                $name = normalizeParticipantNameToken(strval($parsed['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $identities[] = [
                    'name' => $name,
                    'storage_id' => normalizeStorageIdToken(strval($parsed['storage_id'] ?? '')),
                ];
            }
        }
    }

    if (!is_array($identities) || count($identities) === 0) {
        return [];
    }

    $actors = [];
    $seen = [];
    foreach ($identities as $identity) {
        if (!is_array($identity)) {
            continue;
        }
        $name = normalizeParticipantNameToken(strval($identity['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $nameKey = strtolower($name);
        if (isset($seen[$nameKey])) {
            continue;
        }
        $seen[$nameKey] = true;

        $entry = ['name' => $name];
        $storageId = normalizeStorageIdToken(strval($identity['storage_id'] ?? ''));
        if ($storageId !== '') {
            $entry['storage_id'] = $storageId;
        }
        $entry = stobeEnrichNearbyEntryFromNpcProfile($entry);
        $actors[] = $entry;
        if (count($actors) >= 32) {
            break;
        }
    }

    return $actors;
}

function stobeBuildNearbyItemsPromptBlock(array $npcData): string {
    if (!stobePromptContextOptionEnabled('enabled_sections', 'nearby_items')) {
        return '';
    }

    $extendedData = normalizeNpcExtendedDataPayload($npcData['extended_data'] ?? []);
    $items = stobeExtractSceneArray($extendedData, 'nearby_items');
    if (count($items) === 0) {
        return '';
    }

    $seen = [];
    $lines = [
        '<nearby_items>',
        '# NEARBY ITEMS (format: RefID:ItemName (Description))',
    ];
    $added = 0;

    foreach ($items as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $name = trim(strval($entry['name'] ?? ($entry['item'] ?? '')));
        if ($name === '') {
            continue;
        }
        $refId = trim(strval($entry['refid'] ?? ($entry['id'] ?? '')));
        $baseName = stobeExtractBaseItemNameFromAggregateToken($name);
        if ($baseName === '') {
            $baseName = $name;
        }
        $dedupeKey = stobeNormalizeItemNameKey($baseName);
        if ($dedupeKey === '') {
            $dedupeKey = strtolower(($refId !== '' ? $refId : $name));
        }
        if ($dedupeKey === '' || isset($seen[$dedupeKey])) {
            continue;
        }
        $seen[$dedupeKey] = true;

        $displayName = stobeEnrichAggregateItemTokenWithDescription($name, 120);
        $display = $refId !== '' ? ($refId . ':' . $displayName) : $displayName;
        $distanceBand = stobeDescribeDistanceBand($entry['dist'] ?? '');
        if ($distanceBand !== '') {
            $display .= ' (' . $distanceBand . ')';
        }

        $lines[] = '## ' . stobePromptXmlEscape($display);
        $added++;
        if ($added >= 32) {
            break;
        }
    }

    if ($added === 0) {
        return '';
    }

    $lines[] = '</nearby_items>';
    return implode("\n", $lines);
}

function stobeBuildPointsOfInterestPromptBlock(array $npcData): string {
    if (!stobePromptContextOptionEnabled('enabled_sections', 'points_of_interest')) {
        return '';
    }

    $extendedData = normalizeNpcExtendedDataPayload($npcData['extended_data'] ?? []);
    $points = stobeExtractSceneArray($extendedData, 'points_of_interest');
    if (count($points) === 0) {
        return '';
    }

    $seen = [];
    $lines = [
        '<points_of_interest>',
        '# POIs - Points of Interest nearby',
    ];
    $added = 0;

    foreach ($points as $entry) {
        $name = '';
        $type = '';
        $distRaw = '';

        if (is_string($entry)) {
            $name = trim($entry);
        } elseif (is_array($entry)) {
            $name = trim(strval($entry['name'] ?? ($entry['location'] ?? '')));
            $type = trim(strval($entry['type'] ?? ($entry['kind'] ?? '')));
            $distRaw = trim(strval($entry['dist'] ?? ''));
        }

        if ($name === '') {
            continue;
        }
        $nameKey = strtolower($name);
        if (isset($seen[$nameKey])) {
            continue;
        }
        $seen[$nameKey] = true;

        $display = $name;
        if ($type !== '') {
            $display .= ' (' . $type . ')';
        }
        $distanceBand = stobeDescribeDistanceBand($distRaw);
        if ($distanceBand !== '') {
            $display .= ' - ' . $distanceBand;
        }

        $lines[] = '## ' . stobePromptXmlEscape($display);
        $added++;
        if ($added >= 24) {
            break;
        }
    }

    if ($added === 0) {
        return '';
    }

    $lines[] = '</points_of_interest>';
    return implode("\n", $lines);
}

function stobeBuildScenePromptBlock(array $npcData, string $speakerName = ''): string {
    $blocks = [];

    $actors = stobeBuildNearbyActorsPromptBlock($npcData, $speakerName);
    if ($actors === '') {
        $peopleRaw = trim(strval($GLOBALS['CACHE_PEOPLE'] ?? ($_GET['people'] ?? '')));
        $fallbackActors = stobeBuildNearbyActorsFromPeopleScope($peopleRaw);
        if (count($fallbackActors) > 0) {
            $fallbackNpcData = $npcData;
            $fallbackExtended = normalizeNpcExtendedDataPayload($fallbackNpcData['extended_data'] ?? []);
            $fallbackExtended['nearby_actors'] = $fallbackActors;
            $fallbackExtended['nearby'] = $fallbackActors;
            $fallbackNpcData['extended_data'] = $fallbackExtended;
            $actors = stobeBuildNearbyActorsPromptBlock($fallbackNpcData, $speakerName);
            if ($actors !== '') {
                stobeLogDebug('Nearby actors prompt hydrated from request people scope', [
                    'speaker' => $speakerName,
                    'count' => count($fallbackActors),
                ]);
            }
        }
    }
    if ($actors !== '') {
        $blocks[] = $actors;
    }

    $items = stobeBuildNearbyItemsPromptBlock($npcData);
    if ($items !== '') {
        $blocks[] = $items;
    }

    $points = stobeBuildPointsOfInterestPromptBlock($npcData);
    if ($points !== '') {
        $blocks[] = $points;
    }

    if (count($blocks) === 0) {
        return '';
    }

    return implode("\n\n", $blocks);
}

function stobeBuildRecentCombatPromptEvents(
    array $eventHistory,
    int $currentGamets = 0,
    int $limit = 5
): array {
    $allowedTypes = ['major_damage', 'knockout', 'limb_loss', 'death', 'combat_start'];
    $events = [];
    $seen = [];
    $now = time();
    $safeCurrentGamets = max(0, $currentGamets);

    foreach ($eventHistory as $row) {
        if (!is_array($row)) {
            continue;
        }
        $type = strtolower(trim(strval($row['type'] ?? '')));
        if (!in_array($type, $allowedTypes, true)) {
            continue;
        }

        $rowGamets = max(0, intval($row['gamets'] ?? 0));
        $localts = max(0, intval($row['localts'] ?? 0));
        $recentByGameTime = $safeCurrentGamets > 0
            && $rowGamets > 0
            && $rowGamets <= $safeCurrentGamets
            && ($safeCurrentGamets - $rowGamets) <= 1200;
        $recentByClock = $localts > 0 && ($now - $localts) >= 0 && ($now - $localts) <= 180;
        if (!$recentByGameTime && !$recentByClock) {
            continue;
        }

        $line = stobeSanitizePromptContextLine(strval($row['data'] ?? ''));
        $dedupeKey = $type . '|' . strtolower($line);
        if ($line === '' || isset($seen[$dedupeKey])) {
            continue;
        }
        $seen[$dedupeKey] = true;
        $events[] = ['type' => $type, 'line' => truncatePromptValue($line, 280)];
        if (count($events) >= max(1, min(8, $limit))) {
            break;
        }
    }

    return array_reverse($events);
}

function stobeAttachRecentCombatPromptEvents(
    array $npcData,
    array $eventHistory,
    int $currentGamets = 0
): array {
    $events = stobeBuildRecentCombatPromptEvents($eventHistory, $currentGamets);
    if (count($events) === 0) {
        return $npcData;
    }

    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    $metadata['recent_combat_events'] = $events;
    $npcData['metadata'] = $metadata;
    return $npcData;
}

function stobeBuildCombatPriorityPromptBlock(array $npcData, string $speakerName = ''): string {
    if (!stobePromptContextOptionEnabled('enabled_sections', 'combat_priority')) {
        return '';
    }

    if (count($npcData) === 0) {
        return '';
    }

    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    $isInCombat = stobeCoerceTruthyPromptFlag($metadata['is_in_combat'] ?? false);
    $isAttacking = stobeCoerceTruthyPromptFlag($metadata['is_attacking'] ?? false);
    $currentAction = strtolower(trim(strval($metadata['current_action'] ?? '')));
    $actionFlagsText = strtolower(trim(strval($metadata['action_flags'] ?? '')));
    $recentCombatEvents = [];
    if (isset($metadata['recent_combat_events']) && is_array($metadata['recent_combat_events'])) {
        foreach ($metadata['recent_combat_events'] as $event) {
            if (!is_array($event)) {
                continue;
            }
            $line = trim(strval($event['line'] ?? ''));
            if ($line === '') {
                continue;
            }
            $recentCombatEvents[] = [
                'type' => strtolower(trim(strval($event['type'] ?? 'combat'))),
                'line' => $line,
            ];
        }
    }

    $currentActionSignalsCombat = (
        $currentAction === 'combat' ||
        $currentAction === 'attacking' ||
        strpos($currentAction, 'combat') !== false ||
        strpos($currentAction, 'attack') !== false
    );
    $actionFlagsSignalCombat = (
        strpos($actionFlagsText, 'in combat') !== false ||
        strpos($actionFlagsText, 'attacking') !== false
    );
    $hasLiveCombatSignal = $isInCombat
        || $isAttacking
        || $currentActionSignalsCombat
        || $actionFlagsSignalCombat;

    if (
        !$hasLiveCombatSignal
        && count($recentCombatEvents) === 0
    ) {
        return '';
    }

    $speaker = normalizeParticipantNameToken($speakerName);
    if ($speaker === '') {
        $speaker = normalizeParticipantNameToken(strval($npcData['name'] ?? ''));
    }
    if ($speaker === '') {
        $speaker = 'This NPC';
    }

    $attackTarget = normalizeParticipantNameToken(strval($metadata['attack_target'] ?? ''));
    $priorityInstruction = $hasLiveCombatSignal
        ? "PRIORITY INSTRUCTION - {$speaker} is in active combat and fighting for survival right now. Prioritize combat-relevant responses over casual conversation."
        : "PRIORITY INSTRUCTION - {$speaker} has just suffered a serious combat event and may still be under immediate threat. Prioritize the violent situation over casual conversation.";

    $lines = [];
    $lines[] = '<combat_priority>';
    $lines[] = '  <priority_instruction>' . stobePromptXmlEscape($priorityInstruction) . '</priority_instruction>';
    $lines[] = '  <rule>Focus this turn on immediate threats, survival, and battlefield intent.</rule>';
    $lines[] = '  <rule>Keep speech urgent, concise, and grounded in the active fight.</rule>';
    $lines[] = '  <rule>Avoid casual, off-topic, or long-form chatter while combat is active.</rule>';
    $lines[] = '  <rule>React to wounds, fallen allies, and surrender demands as immediate physical stakes, not as an abstract discussion.</rule>';
    $lines[] = '  <rule>Let personality shape whether the response is frightened, desperate, furious, defiant, pleading, or tactical, but never detached or analytical.</rule>';
    $lines[] = '  <rule>If badly hurt or visibly losing, show credible desperation or self-preservation instead of effortless confidence.</rule>';
    if ($attackTarget !== '') {
        $lines[] = '  <attack_target>' . stobePromptXmlEscape($attackTarget) . '</attack_target>';
    }
    foreach ($recentCombatEvents as $event) {
        $eventType = trim(strval($event['type'] ?? 'combat'));
        $eventLine = trim(strval($event['line'] ?? ''));
        $lines[] = '  <recent_event type="' . stobePromptXmlEscape($eventType) . '">'
            . stobePromptXmlEscape($eventLine) . '</recent_event>';
    }
    $lines[] = '</combat_priority>';

    return implode("\n", $lines);
}

function getCurrentPlayerFactionIdentity(): array {
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $playerName = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    $db = $GLOBALS["db"];
    $row = $db->fetchOne(
        "SELECT faction, metadata
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
        // Kenshi default player faction is Nameless; use it as a stable fallback
        // when no explicit player-character row is available.
        $cached = ['name' => 'Nameless', 'id' => ''];
        return $cached;
    }

    $identity = parseFactionIdentityToken(strval($row['faction'] ?? ''));
    $metadata = normalizeNpcMetadataPayload($row['metadata'] ?? []);
    $metaFactionId = trim(strval($metadata['faction_id'] ?? ($metadata['factionID'] ?? '')));
    if ($identity['id'] === '' && $metaFactionId !== '') {
        $identity['id'] = $metaFactionId;
    }

    if ($identity['name'] === '') {
        $metaFaction = trim(strval($metadata['faction'] ?? ''));
        if ($metaFaction !== '') {
            $metaIdentity = parseFactionIdentityToken($metaFaction);
            if ($metaIdentity['name'] !== '') {
                $identity['name'] = $metaIdentity['name'];
            }
            if ($identity['id'] === '' && $metaIdentity['id'] !== '') {
                $identity['id'] = $metaIdentity['id'];
            }
        }
    }

    if ($identity['name'] === '') {
        $identity['name'] = 'Nameless';
    }

    $cached = $identity;
    return $cached;
}

function getNpcFactionIdentityFromProfile(array $npcData): array {
    $identity = parseFactionIdentityToken(strval($npcData['faction'] ?? ''));
    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    $metaFactionId = trim(strval($metadata['faction_id'] ?? ($metadata['factionID'] ?? '')));
    if ($identity['id'] === '' && $metaFactionId !== '') {
        $identity['id'] = $metaFactionId;
    }
    return $identity;
}

function npcIsInPlayerFaction(array $npcData): bool {
    $playerFaction = getCurrentPlayerFactionIdentity();
    $npcFaction = getNpcFactionIdentityFromProfile($npcData);

    $playerFactionId = trim(strval($playerFaction['id'] ?? ''));
    $npcFactionId = trim(strval($npcFaction['id'] ?? ''));
    if ($playerFactionId !== '' && $npcFactionId !== '') {
        return strcasecmp($playerFactionId, $npcFactionId) === 0;
    }

    $playerFactionName = trim(strval($playerFaction['name'] ?? ''));
    $npcFactionName = trim(strval($npcFaction['name'] ?? ''));
    if ($playerFactionName !== '' && $npcFactionName !== '') {
        return strcasecmp($playerFactionName, $npcFactionName) === 0;
    }

    return false;
}

function decodeConfOptJsonList(string $confId): array {
    $raw = trim(getConfOpt($confId, ''));
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    return $decoded;
}

function getPlayerSquadMembershipSnapshot(): array {
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $snapshot = [
        'squad_members' => [],
        'member_to_squads' => [],
    ];

    $squadNamesRaw = decodeConfOptJsonList('PLAYER_SQUADS');
    foreach ($squadNamesRaw as $entry) {
        if (!is_string($entry)) {
            continue;
        }
        $squadName = trim($entry);
        if ($squadName === '') {
            continue;
        }

        $membersRaw = decodeConfOptJsonList($squadName);
        $memberMap = [];
        foreach ($membersRaw as $memberEntry) {
            if (!is_string($memberEntry)) {
                continue;
            }
            $memberName = normalizeParticipantNameToken($memberEntry);
            if ($memberName === '') {
                continue;
            }
            $memberKey = strtolower($memberName);
            if (!isset($memberMap[$memberKey])) {
                $memberMap[$memberKey] = $memberName;
            }
            if (!isset($snapshot['member_to_squads'][$memberKey])) {
                $snapshot['member_to_squads'][$memberKey] = [];
            }
            if (!in_array($squadName, $snapshot['member_to_squads'][$memberKey], true)) {
                $snapshot['member_to_squads'][$memberKey][] = $squadName;
            }
        }
        if (count($memberMap) > 0) {
            $snapshot['squad_members'][$squadName] = $memberMap;
        }
    }

    $cached = $snapshot;
    return $cached;
}

function getSpeakerSquadMemberSet(string $speakerName): array {
    $speaker = normalizeParticipantNameToken($speakerName);
    if ($speaker === '') {
        return [];
    }

    $membership = getPlayerSquadMembershipSnapshot();
    $speakerKey = strtolower($speaker);
    $speakerSquads = is_array($membership['member_to_squads'][$speakerKey] ?? null)
        ? $membership['member_to_squads'][$speakerKey]
        : [];
    if (count($speakerSquads) === 0) {
        return [];
    }

    $memberSet = [];
    foreach ($speakerSquads as $squadName) {
        $members = is_array($membership['squad_members'][$squadName] ?? null)
            ? $membership['squad_members'][$squadName]
            : [];
        foreach ($members as $memberKey => $memberName) {
            $memberSet[$memberKey] = $memberName;
        }
    }
    unset($memberSet[$speakerKey]);

    return $memberSet;
}

function getFactionIdentityFromNearbyEntry(array $entry): array {
    $identity = parseFactionIdentityToken(strval($entry['faction'] ?? ''));
    $factionId = trim(strval($entry['faction_id'] ?? ($entry['factionID'] ?? '')));
    if ($identity['id'] === '' && $factionId !== '') {
        $identity['id'] = $factionId;
    }
    return $identity;
}

function nearbyEntryIsInPlayerFaction(array $entry): bool {
    $playerFaction = getCurrentPlayerFactionIdentity();
    $nearFaction = getFactionIdentityFromNearbyEntry($entry);

    $playerFactionId = trim(strval($playerFaction['id'] ?? ''));
    $nearFactionId = trim(strval($nearFaction['id'] ?? ''));
    if ($playerFactionId !== '' && $nearFactionId !== '') {
        return strcasecmp($playerFactionId, $nearFactionId) === 0;
    }

    $playerFactionName = trim(strval($playerFaction['name'] ?? ''));
    $nearFactionName = trim(strval($nearFaction['name'] ?? ''));
    if ($playerFactionName !== '' && $nearFactionName !== '') {
        return strcasecmp($playerFactionName, $nearFactionName) === 0;
    }

    $customName = stobeGetPlayerFactionCustomNameSetting();
    if ($customName !== '' && $nearFactionName !== '' && strcasecmp($nearFactionName, $customName) === 0) {
        return true;
    }

    return false;
}

function buildNearbyPlayerAlliesPrompt(array $nearby, string $speakerName): string {
    if (!stobePromptContextOptionEnabled('enabled_sections', 'nearby_player_allies')) {
        return '';
    }

    if (count($nearby) === 0) {
        return '';
    }

    $speakerSquadMembers = getSpeakerSquadMemberSet($speakerName);
    $sameSquad = [];
    $sameFaction = [];
    $seen = [];

    foreach ($nearby as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $name = normalizeParticipantNameToken(strval($entry['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $nameKey = strtolower($name);
        if (isset($seen[$nameKey])) {
            continue;
        }

        if (isset($speakerSquadMembers[$nameKey])) {
            $sameSquad[] = $speakerSquadMembers[$nameKey];
            $seen[$nameKey] = true;
            continue;
        }

        if (nearbyEntryIsInPlayerFaction($entry)) {
            $sameFaction[] = $name;
            $seen[$nameKey] = true;
        }
    }

    if (count($sameSquad) === 0 && count($sameFaction) === 0) {
        return '';
    }

    $lines = [
        '<nearby_player_allies>',
        '  <summary>Nearby people aligned with the player side.</summary>',
    ];
    if (count($sameSquad) > 0) {
        $lines[] = '  <same_squad>' . stobePromptXmlEscape(implode(', ', $sameSquad)) . '</same_squad>';
    }
    if (count($sameFaction) > 0) {
        $lines[] = '  <same_player_faction>' . stobePromptXmlEscape(implode(', ', $sameFaction)) . '</same_player_faction>';
    }
    $lines[] = '  <usage_note>Use this only as social context. Do not mention squad or faction names explicitly.</usage_note>';
    $lines[] = '</nearby_player_allies>';

    return implode("\n", $lines);
}

function stobeBuildNearbyPlayerFactionPartyPrompt(array|false $npcData, string $speakerName): string {
    if (!is_array($npcData) || count($npcData) === 0) {
        return '';
    }
    if (!npcIsInPlayerFaction($npcData)) {
        return '';
    }

    $extendedData = normalizeNpcExtendedDataPayload($npcData['extended_data'] ?? []);
    $nearby = stobeExtractSceneArray($extendedData, 'nearby_actors');
    if (count($nearby) === 0) {
        $nearby = stobeExtractSceneArray($extendedData, 'nearby');
    }
    if (count($nearby) === 0) {
        return '';
    }

    return buildNearbyPlayerAlliesPrompt($nearby, $speakerName);
}

function stobeBuildRoleplayInstructionsText(string $npcName, string $playerName, array|false $npcData = false): string {
    $custom = '';
    if (function_exists('stobeGetNpcLayeredStringSetting')) {
        $custom = trim(stobeGetNpcLayeredStringSetting(
            $npcData,
            ['ROLEPLAY_INSTRUCTIONS'],
            'ROLEPLAY_INSTRUCTIONS',
            ''
        ));
    } else {
        $custom = trim(strval(getSetting('ROLEPLAY_INSTRUCTIONS', '')));
    }
    if ($custom !== '') {
        return $custom;
    }

    $safeNpc = trim($npcName);
    if ($safeNpc === '') {
        $safeNpc = 'this character';
    }
    $safePlayer = trim($playerName);
    if ($safePlayer === '') {
        $safePlayer = 'the conversation target';
    }

    return 'Roleplay as ' . $safeNpc . '. Treat Kenshi as your lived reality and respond directly to '
        . $safePlayer . ' in grounded in-world terms.';
}

function stobeBuildGeneralInstructionsText(array|false $npcData = false): string {
    $custom = '';
    if (function_exists('stobeGetNpcLayeredStringSetting')) {
        $custom = trim(stobeGetNpcLayeredStringSetting(
            $npcData,
            ['GENERAL_INSTRUCTIONS'],
            'GENERAL_INSTRUCTIONS',
            ''
        ));
    } else {
        $custom = trim(strval(getSetting('GENERAL_INSTRUCTIONS', '')));
    }
    if ($custom !== '') {
        return $custom;
    }

    // Match HerikaServer behavior by preferring COMMAND_PROMPT when available.
    $herikaCommandPrompt = '';
    if (function_exists('stobeGetNpcLayeredStringSetting')) {
        $herikaCommandPrompt = trim(stobeGetNpcLayeredStringSetting(
            $npcData,
            ['COMMAND_PROMPT'],
            'COMMAND_PROMPT',
            ''
        ));
    } else {
        $herikaCommandPrompt = trim(strval(getSetting('COMMAND_PROMPT', '')));
    }
    if ($herikaCommandPrompt === '' && function_exists('getConfOpt')) {
        $herikaCommandPrompt = trim(strval(getConfOpt('COMMAND_PROMPT', '')));
    }
    if ($herikaCommandPrompt !== '') {
        return $herikaCommandPrompt;
    }

    $defaults = [
        'Stay in character at all times.',
        'Keep responses concise unless the moment needs detail.',
        'Use Kenshi world logic, factions, and survival tone.',
    ];
    return implode("\n", $defaults);
}

function stobeResolveNpcPromptOverrides(array $npcData, array $metadata = []): array {
    $profileId = intval($npcData['profile_id'] ?? 0);
    if ($profileId <= 0 && function_exists('getDefaultNpcProfileId')) {
        $profileId = intval(getDefaultNpcProfileId());
    }

    $profilePromptHead = '';
    $profilePrompt = '';
    if ($profileId > 0 && function_exists('getCoreProfileById')) {
        static $profileCache = [];
        if (!array_key_exists($profileId, $profileCache)) {
            $profileRow = getCoreProfileById($profileId);
            $profileCache[$profileId] = is_array($profileRow) ? $profileRow : false;
        }
        $resolvedProfile = $profileCache[$profileId];
        if (is_array($resolvedProfile)) {
            $profilePromptHead = trim(strval($resolvedProfile['prompt_head'] ?? ''));
            $profilePrompt = trim(strval($resolvedProfile['profile_prompt'] ?? ''));
        }
    }

    $npcPromptHead = trim(strval($npcData['prompt_head'] ?? ($metadata['prompt_head'] ?? '')));
    $npcProfilePrompt = trim(strval($npcData['profile_prompt'] ?? ($metadata['profile_prompt'] ?? '')));

    if (function_exists('stobeGetNpcRuntimeOverrideMap')) {
        $runtimeOverrides = stobeGetNpcRuntimeOverrideMap($npcData);
        if ($npcPromptHead === '') {
            foreach (['PROMPT_HEAD', 'NPC_PROMPT_HEAD'] as $overrideKey) {
                if (!array_key_exists($overrideKey, $runtimeOverrides)) {
                    continue;
                }
                $npcPromptHead = trim(strval($runtimeOverrides[$overrideKey]));
                if ($npcPromptHead !== '') {
                    break;
                }
            }
        }
        if ($npcProfilePrompt === '') {
            foreach (['PROFILE_PROMPT'] as $overrideKey) {
                if (!array_key_exists($overrideKey, $runtimeOverrides)) {
                    continue;
                }
                $npcProfilePrompt = trim(strval($runtimeOverrides[$overrideKey]));
                if ($npcProfilePrompt !== '') {
                    break;
                }
            }
        }
    }

    $resolvedPromptHead = $npcPromptHead !== '' ? $npcPromptHead : $profilePromptHead;
    if ($resolvedPromptHead === '' && function_exists('getSetting')) {
        $resolvedPromptHead = trim(strval(getSetting('PROMPT_HEAD', '')));
    }
    $resolvedProfilePrompt = $npcProfilePrompt !== '' ? $npcProfilePrompt : $profilePrompt;
    // Bug 107: fill the name placeholders (only the diary code did this).
    $placeholderName = trim(strval($npcData['name'] ?? ($metadata['name'] ?? '')));
    if ($placeholderName !== '') {
        $placeholders = ['#HERIKA_NAME#', '{HERIKA_NAME}', '#NPC_NAME#'];
        $resolvedPromptHead = str_replace($placeholders, $placeholderName, $resolvedPromptHead);
        $resolvedProfilePrompt = str_replace($placeholders, $placeholderName, $resolvedProfilePrompt);
    }

    return [
        'prompt_head' => $resolvedPromptHead,
        'profile_prompt' => $resolvedProfilePrompt,
    ];
}

function stobeBuildNpcAppearanceText(array $npcData): string {
    if (!stobePromptContextOptionEnabled('enabled_character_subsections', 'appearance')) {
        return '';
    }

    $appearance = trim(strval($npcData['appearance'] ?? ''));
    if ($appearance !== '') {
        return truncatePromptValue($appearance, 280);
    }

    $race = trim(strval($npcData['race'] ?? ''));
    if ($race === '') {
        $race = 'Unknown';
    }

    return 'No detailed appearance record. Known race: ' . $race . '.';
}

function stobeBuildNpcConditionText(array $npcData, array $metadata): string {
    if (!stobePromptContextOptionEnabled('enabled_state_subsections', 'current_condition')) {
        return '';
    }

    $parts = [];

    $health = trim(strval($npcData['health'] ?? ($metadata['health'] ?? '')));
    if ($health !== '') {
        $parts[] = 'Health: ' . $health;
    }

    $bloodState = stobeDescribeBloodStatus($npcData['blood'] ?? '');
    if ($bloodState !== '') {
        $parts[] = 'Blood: ' . $bloodState;
    }

    $hungerState = stobeDescribeHungerStatus($npcData['hunger'] ?? '');
    if ($hungerState !== '') {
        $parts[] = 'Hunger: ' . $hungerState;
    }

    $limbState = stobeDescribeLimbStatus(
        $npcData['limbs'] ?? '',
        $metadata['robotic_limbs'] ?? []
    );
    if ($limbState !== '') {
        $parts[] = 'Limbs: ' . $limbState;
    }

    $drunkState = stobeDescribeDrunkPromptState(
        $npcData['drunk_level'] ?? ($metadata['drunk_level'] ?? null),
        $npcData['is_drunk'] ?? ($metadata['is_drunk'] ?? null),
        $npcData['drunk_status'] ?? ($metadata['drunk_status'] ?? null)
    );
    if ($drunkState !== '') {
        $parts[] = 'Drunk state: ' . $drunkState;
    }
    $highState = stobeDescribeHighPromptState(
        $npcData['is_high'] ?? ($metadata['is_high'] ?? null),
        $npcData['high_status'] ?? ($metadata['high_status'] ?? null),
        $npcData['high_seconds_remaining'] ?? ($metadata['high_seconds_remaining'] ?? null),
        $npcData['high_hunger_rate_multiplier'] ?? ($metadata['high_hunger_rate_multiplier'] ?? null)
    );
    if ($highState !== '') {
        $parts[] = 'Drug state: ' . $highState;
    }

    $characterState = trim(strval($metadata['character_state'] ?? ''));
    if (
        stobePromptContextOptionEnabled('enabled_state_subsections', 'activity_state')
        && $characterState !== ''
        && strtolower($characterState) !== 'normal'
    ) {
        $parts[] = 'State: ' . $characterState;
    }

    if (count($parts) === 0) {
        return 'Condition appears stable.';
    }

    return implode("\n", array_map(static function (string $line): string {
        return '- ' . $line;
    }, $parts));
}

function stobeSkillGroupMaps(): array {
    return [
        'attributes' => [
            'strength' => 'Strength',
            'dexterity' => 'Dexterity',
            'toughness' => 'Toughness',
            'perception' => 'Perception',
        ],
        'combat' => [
            'melee_attack' => 'Melee Attack',
            'melee_defence' => 'Melee Defence',
            'dodge' => 'Dodge',
            'martial_arts' => 'Martial Arts',
            'katanas' => 'Katanas',
            'sabres' => 'Sabres',
            'hackers' => 'Hackers',
            'heavy_weapons' => 'Heavy Weapons',
            'blunt' => 'Blunt',
            'polearms' => 'Polearms',
            'crossbows' => 'Crossbows',
            'turrets' => 'Turrets',
            'athletics' => 'Athletics',
            'stealth' => 'Stealth',
            'assassination' => 'Assassination',
            'swimming' => 'Swimming',
            'survival' => 'Survival',
        ],
        'core' => [
            'labouring' => 'Labouring',
            'thieving' => 'Thieving',
            'lockpicking' => 'Lockpicking',
            'medic' => 'Medic',
            'science' => 'Science',
            'engineering' => 'Engineering',
            'robotics' => 'Robotics',
            'farming' => 'Farming',
            'cooking' => 'Cooking',
            'weapon_smithing' => 'Weapon Smithing',
            'armour_smithing' => 'Armour Smithing',
            'bow_smithing' => 'Bow Smithing',
            'hive_medic' => 'Hive Medic',
            'vet' => 'Veterinary',
        ],
    ];
}

function stobeSkillAliases(): array {
    return [
        'melee_defense' => 'melee_defence',
        'martialarts' => 'martial_arts',
        'heavyweapons' => 'heavy_weapons',
        'weapon_smith' => 'weapon_smithing',
        'armor_smithing' => 'armour_smithing',
        'armour_smith' => 'armour_smithing',
        'bow_smith' => 'bow_smithing',
        'hivemedic' => 'hive_medic',
    ];
}

function stobeNormalizeSkillToken(string $token): string {
    $lower = strtolower(trim($token));
    if ($lower === '') {
        return '';
    }
    return preg_replace('/[^a-z0-9]+/', '', $lower) ?? '';
}

function stobeSkillLookupTable(): array {
    static $lookup = null;
    if (is_array($lookup)) {
        return $lookup;
    }

    $lookup = [];
    $maps = stobeSkillGroupMaps();
    foreach ($maps as $groupMap) {
        foreach ($groupMap as $canonical => $label) {
            $lookup[stobeNormalizeSkillToken($canonical)] = $canonical;
            $lookup[stobeNormalizeSkillToken($label)] = $canonical;
        }
    }

    $shortLabels = [
        'str' => 'strength',
        'dex' => 'dexterity',
        'tgh' => 'toughness',
        'per' => 'perception',
        'matk' => 'melee_attack',
        'mdef' => 'melee_defence',
        'ma' => 'martial_arts',
        'katana' => 'katanas',
        'sabre' => 'sabres',
        'hacker' => 'hackers',
        'heavy' => 'heavy_weapons',
        'blunt' => 'blunt',
        'polearm' => 'polearms',
        'crossbow' => 'crossbows',
        'turret' => 'turrets',
        'assassin' => 'assassination',
        'swim' => 'swimming',
        'labour' => 'labouring',
        'lockpick' => 'lockpicking',
        'engineer' => 'engineering',
        'wpnsmith' => 'weapon_smithing',
        'armsmith' => 'armour_smithing',
        'bowsmith' => 'bow_smithing',
        'hivemedic' => 'hive_medic',
        'vet' => 'vet',
    ];
    foreach ($shortLabels as $token => $canonical) {
        $lookup[stobeNormalizeSkillToken($token)] = $canonical;
    }

    foreach (stobeSkillAliases() as $alias => $canonical) {
        $lookup[stobeNormalizeSkillToken($alias)] = $canonical;
    }

    return $lookup;
}

function stobeParseSkillInt(mixed $value): ?int {
    if (is_int($value)) {
        return $value;
    }
    if (is_float($value)) {
        return intval($value);
    }
    if (is_string($value) && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', trim($value)) === 1) {
        return intval(floatval($value));
    }
    return null;
}

function stobeSkillTierLabel(?int $value): string {
    if ($value === null || $value <= 0) {
        return 'Untrained';
    }
    if ($value < 15) {
        return 'Novice';
    }
    if ($value < 30) {
        return 'Apprentice';
    }
    if ($value < 45) {
        return 'Competent';
    }
    if ($value < 60) {
        return 'Skilled';
    }
    if ($value < 75) {
        return 'Expert';
    }
    if ($value < 90) {
        return 'Veteran';
    }
    return 'Elite';
}

function stobeExtractSkillValues(array $npcData): array {
    $values = [];
    $lookup = stobeSkillLookupTable();
    $aliases = stobeSkillAliases();
    $addSkill = static function (string $rawKey, mixed $rawValue) use (&$values, $lookup, $aliases): void {
        $normalized = trim(strtolower($rawKey));
        if ($normalized === '') {
            return;
        }
        if (isset($aliases[$normalized])) {
            $normalized = $aliases[$normalized];
        }
        $token = stobeNormalizeSkillToken($normalized);
        if ($token === '' || !isset($lookup[$token])) {
            return;
        }
        $score = stobeParseSkillInt($rawValue);
        if ($score === null) {
            return;
        }
        $values[$lookup[$token]] = $score;
    };

    $rawSkills = $npcData['skills'] ?? '';
    if (is_array($rawSkills)) {
        foreach ($rawSkills as $key => $value) {
            $addSkill(strval($key), $value);
        }
    } else {
        $skillsText = trim(strval($rawSkills));
        if ($skillsText !== '') {
            $decoded = json_decode($skillsText, true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    $addSkill(strval($key), $value);
                }
            } else {
                if (preg_match_all('/([A-Za-z][A-Za-z0-9_ ]{1,32})\s+(-?[0-9]+)/', $skillsText, $matches, PREG_SET_ORDER) > 0) {
                    foreach ($matches as $match) {
                        $addSkill(strval($match[1] ?? ''), strval($match[2] ?? ''));
                    }
                }
            }
        }
    }

    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    $stats = $metadata['stats'] ?? null;
    if (is_array($stats)) {
        foreach ($stats as $key => $value) {
            $addSkill(strval($key), $value);
        }
    }

    return $values;
}

function stobeBuildNpcSkillsText(array $npcData): string {
    if (!stobePromptContextOptionEnabled('enabled_character_subsections', 'skills')) {
        return '';
    }

    $maps = stobeSkillGroupMaps();
    $values = stobeExtractSkillValues($npcData);

    $lines = [];
    foreach ($maps as $groupName => $groupMap) {
        $lines[] = '<group name="' . stobePromptXmlEscape($groupName) . '">';
        foreach ($groupMap as $canonical => $label) {
            $tier = stobeSkillTierLabel($values[$canonical] ?? null);
            $lines[] = '  <skill name="' . stobePromptXmlEscape($label) . '">' . stobePromptXmlEscape($tier) . '</skill>';
        }
        $lines[] = '</group>';
    }

    return implode("\n", $lines);
}

function stobeRelationshipTierLabel(int $score): string {
    if ($score >= 91) return 'Bonded';
    if ($score >= 76) return 'Devoted';
    if ($score >= 56) return 'Fond';
    if ($score >= 31) return 'Friendly';
    if ($score >= 6) return 'Acquaintance';
    if ($score >= -5) return 'Neutral';
    if ($score >= -30) return 'Wary';
    if ($score >= -55) return 'Cold';
    if ($score >= -75) return 'Resentful';
    if ($score >= -90) return 'Hateful';
    return 'Hostile';
}

function stobeNormalizeRelationshipTypeToken(string $rawType): string {
    $type = strtolower(trim($rawType));
    if ($type === '') {
        return 'neutral';
    }
    if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $type) !== 1) {
        return 'neutral';
    }
    return $type;
}

function stobeIsIgnoredRelationshipTarget(string $rawTarget): bool {
    $rawLower = strtolower(trim($rawTarget));
    if (in_array($rawLower, ['player', 'the player', '#player_name#', 'dragonborn', 'the dragonborn'], true)) {
        return true;
    }

    $target = normalizeParticipantNameToken($rawTarget);
    if ($target === '') {
        return true;
    }

    $targetLower = strtolower($target);
    if (in_array($targetLower, ['player', 'the player', '#player_name#', 'dragonborn', 'the dragonborn'], true)) {
        return true;
    }

    /* A real configured player name is a valid relationship target. Only
     * generic placeholder labels above are ignored. Filtering PLAYER_NAME here
     * prevented affinity with the actual player from evolving after the name
     * was configured correctly. */
    return false;
}

function stobeNormalizeRelationshipMap(mixed $rawMap): array {
    $source = [];
    if (is_array($rawMap)) {
        $source = $rawMap;
    } elseif (is_string($rawMap) && trim($rawMap) !== '') {
        $trimmedRaw = trim($rawMap);
        $decoded = json_decode($trimmedRaw, true);
        if (is_array($decoded)) {
            $source = $decoded;
        } else {
            // Legacy compatibility: parse summary text rows like:
            // "Name: Friendly (ally, aff 35)" or "* Name - +12 (Friendly, ally)"
            $parsed = [];
            $lines = preg_split('/\r\n|\r|\n/', $trimmedRaw) ?: [];
            foreach ($lines as $rawLine) {
                $line = trim(strval($rawLine));
                if ($line === '') {
                    continue;
                }
                $line = ltrim($line, " \t-*");
                if ($line === '') {
                    continue;
                }

                $target = '';
                $aff = 0;
                $type = 'neutral';
                $note = '';

                if (preg_match('/^(.+?)\s*:\s*[A-Za-z][A-Za-z ]{0,31}\s*\(\s*([a-zA-Z][a-zA-Z0-9_-]{0,31})\s*,\s*aff\s*([+-]?[0-9]{1,3})\s*\)(?:\s*\|\s*(.+))?$/', $line, $m) === 1) {
                    $target = trim(strval($m[1] ?? ''));
                    $type = strval($m[2] ?? 'neutral');
                    $aff = intval($m[3] ?? 0);
                    $note = trim(strval($m[4] ?? ''));
                } elseif (preg_match('/^(.+?)\s*-\s*([+-]?[0-9]{1,3})\s*\(\s*[A-Za-z][A-Za-z ]{0,31}\s*,\s*([a-zA-Z][a-zA-Z0-9_-]{0,31})\s*\)(?:\s*\|\s*(.+))?$/', $line, $m) === 1) {
                    $target = trim(strval($m[1] ?? ''));
                    $aff = intval($m[2] ?? 0);
                    $type = strval($m[3] ?? 'neutral');
                    $note = trim(strval($m[4] ?? ''));
                } elseif (preg_match('/^(.+?)\s*:\s*([+-]?[0-9]{1,3})\s*$/', $line, $m) === 1) {
                    $target = trim(strval($m[1] ?? ''));
                    $aff = intval($m[2] ?? 0);
                }

                if ($target === '') {
                    continue;
                }
                $parsed[$target] = [
                    'aff' => $aff,
                    'type' => $type,
                    'note' => $note,
                ];
            }
            if (count($parsed) > 0) {
                $source = $parsed;
            }
        }
    }
    if (count($source) === 0) {
        return [];
    }

    $normalized = [];
    $canonicalNames = [];
    $append = static function (string $targetName, mixed $value) use (&$normalized, &$canonicalNames): void {
        $target = normalizeParticipantNameToken($targetName);
        if ($target === '') {
            if (is_array($value)) {
                $target = normalizeParticipantNameToken(strval($value['target'] ?? ($value['name'] ?? '')));
            }
        }
        if ($target === '') {
            return;
        }
        if (stobeIsIgnoredRelationshipTarget($target)) {
            return;
        }

        $affRaw = 0;
        $typeRaw = 'neutral';
        $noteRaw = '';
        if (is_array($value)) {
            $affRaw = $value['aff'] ?? ($value['affinity'] ?? 0);
            $typeRaw = strval($value['type'] ?? ($value['relationship_type'] ?? 'neutral'));
            $noteRaw = strval($value['note'] ?? ($value['reason'] ?? ''));
        } elseif (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^[+-]?[0-9]+$/', trim($value)) === 1)) {
            $affRaw = $value;
        }

        $affinity = intval($affRaw);
        if ($affinity > 100) {
            $affinity = 100;
        } elseif ($affinity < -100) {
            $affinity = -100;
        }

        $type = stobeNormalizeRelationshipTypeToken($typeRaw);
        $note = trim($noteRaw);
        if (strlen($note) > 180) {
            $note = substr($note, 0, 180);
        }

        $key = strtolower($target);
        if (!isset($canonicalNames[$key])) {
            $canonicalNames[$key] = $target;
        }
        $canonical = $canonicalNames[$key];
        $existingCustomInfo = $normalized[$canonical]['custom_info'] ?? '';
        $normalized[$canonical] = [
            'aff' => $affinity,
            'type' => $type,
            'tier' => stobeRelationshipTierLabel($affinity),
            'note' => $note,
            'updated_at' => intval(time()),
        ];
        if ($existingCustomInfo !== '') {
            $normalized[$canonical]['custom_info'] = $existingCustomInfo;
        } elseif (is_array($value) && is_string($value['custom_info'] ?? null) && $value['custom_info'] !== '') {
            $normalized[$canonical]['custom_info'] = $value['custom_info'];
        }
    };

    $isList = array_keys($source) === range(0, count($source) - 1);
    if ($isList) {
        foreach ($source as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $target = strval($entry['target'] ?? ($entry['name'] ?? ''));
            $append($target, $entry);
        }
    } else {
        foreach ($source as $target => $entry) {
            $append(strval($target), $entry);
        }
    }

    return $normalized;
}

function stobeFindRelationshipEntryKey(array $relationshipMap, string $targetName): string {
    $target = normalizeParticipantNameToken($targetName);
    if ($target === '') {
        return '';
    }
    $targetLower = strtolower($target);
    if (array_key_exists($target, $relationshipMap)) {
        return $target;
    }
    foreach ($relationshipMap as $existingName => $_entry) {
        if (strtolower(strval($existingName)) === $targetLower) {
            return strval($existingName);
        }
    }

    return '';
}

function stobeCollectRelationshipContextTargets(array|false $npcData, string $speakerName, string $conversationTarget): array {
    $targets = [];
    $seen = [];
    $add = static function (string $rawName) use (&$targets, &$seen, $speakerName): void {
        $name = normalizeParticipantNameToken($rawName);
        if ($name === '') {
            return;
        }
        if (stobeIsIgnoredRelationshipTarget($name)) {
            return;
        }
        if (strcasecmp($name, $speakerName) === 0) {
            return;
        }
        $key = strtolower($name);
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $targets[] = $name;
    };

    $add($conversationTarget);

    $participants = extractParticipantNames([
        'people' => strval($GLOBALS["CACHE_PEOPLE"] ?? ''),
        'profile' => $conversationTarget,
        'speaker' => $speakerName,
    ]);
    foreach ($participants as $participantName) {
        $add(strval($participantName));
    }

    if (is_array($npcData)) {
        $extended = normalizeNpcExtendedDataPayload($npcData['extended_data'] ?? []);
        $nearbyActors = stobeExtractSceneArray($extended, 'nearby_actors');
        if (count($nearbyActors) === 0) {
            $nearbyActors = stobeExtractSceneArray($extended, 'nearby');
        }
        foreach ($nearbyActors as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $add(strval($entry['name'] ?? ''));
        }
    }

    if (count($targets) > 16) {
        $targets = array_slice($targets, 0, 16);
    }
    return $targets;
}

function stobeShouldUseTierOnlyRelationshipContext(array|false $npcData): bool {
    $connector = getProfileLlmConnectorForNpcByPurpose($npcData, 'relationship');
    if (!is_array($connector) || intval($connector['id'] ?? 0) <= 0) {
        return false;
    }
    $apiKey = trim(strval($connector['api_badge_key'] ?? ($connector['api_key'] ?? '')));
    return $apiKey !== '';
}

function stobeGetNpcRelationshipMap(array|false $npcData): array {
    if (!is_array($npcData)) {
        return [];
    }

    $columnMap = stobeNormalizeRelationshipMap(strval($npcData['relationships'] ?? ''));

    $extended = normalizeNpcExtendedDataPayload($npcData['extended_data'] ?? []);
    $extendedRaw = $extended['relationships'] ?? [];
    $extendedMap = stobeNormalizeRelationshipMap($extendedRaw);

    if (count($extendedMap) === 0) {
        return $columnMap;
    }
    if (count($columnMap) === 0) {
        return $extendedMap;
    }

    $merged = $columnMap;
    foreach ($extendedMap as $targetName => $entry) {
        $key = stobeFindRelationshipEntryKey($merged, strval($targetName));
        if ($key === '') {
            $key = strval($targetName);
        }
        $merged[$key] = $entry;
    }

    return $merged;
}

function stobeBuildNpcRelationshipsText(string $speakerName, string $conversationTarget, array|false $npcData = false): string {
    if (!stobePromptContextOptionEnabled('enabled_character_subsections', 'relationships')) {
        return '';
    }

    $speaker = normalizeParticipantNameToken($speakerName);
    if (!is_array($npcData)) {
        $npcData = getNpcData($speaker);
    }

    $relationshipMap = stobeGetNpcRelationshipMap($npcData);

    /* The generic relationship normalizer intentionally filters player aliases,
     * but that also hid a real named-player relationship from dialogue. Preserve
     * the configured player's explicit stored relationship for conversational
     * behavior without changing the stricter relationship-update parser. */
    $conversationTargetNormalized = normalizeParticipantNameToken($conversationTarget);
    $configuredPlayerName = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    if ($conversationTargetNormalized !== '' && $configuredPlayerName !== ''
        && strcasecmp($conversationTargetNormalized, $configuredPlayerName) === 0) {
        $extendedForPlayer = normalizeNpcExtendedDataPayload($npcData['extended_data'] ?? []);
        $rawRelationships = $extendedForPlayer['relationships'] ?? [];
        if (is_string($rawRelationships)) {
            $decodedRelationships = json_decode($rawRelationships, true);
            $rawRelationships = is_array($decodedRelationships) ? $decodedRelationships : [];
        }
        if (is_array($rawRelationships)) {
            foreach ($rawRelationships as $rawTarget => $rawEntry) {
                if (strcasecmp(normalizeParticipantNameToken(strval($rawTarget)), $configuredPlayerName) !== 0
                    || !is_array($rawEntry)) {
                    continue;
                }
                $aff = max(-100, min(100, intval($rawEntry['aff'] ?? ($rawEntry['affinity'] ?? 0))));
                $relationshipMap[$configuredPlayerName] = [
                    'aff' => $aff,
                    'type' => stobeNormalizeRelationshipTypeToken(strval($rawEntry['type'] ?? 'neutral')),
                    'tier' => stobeRelationshipTierLabel($aff),
                    'note' => trim(strval($rawEntry['note'] ?? '')),
                    'custom_info' => trim(strval($rawEntry['custom_info'] ?? '')),
                ];
                break;
            }
        }
    }

    $targetNames = stobeCollectRelationshipContextTargets($npcData, $speaker, $conversationTarget);
    if ($conversationTargetNormalized !== '' && $configuredPlayerName !== ''
        && strcasecmp($conversationTargetNormalized, $configuredPlayerName) === 0) {
        $hasPlayerTarget = false;
        foreach ($targetNames as $existingTargetName) {
            if (strcasecmp($existingTargetName, $configuredPlayerName) === 0) {
                $hasPlayerTarget = true;
                break;
            }
        }
        if (!$hasPlayerTarget) {
            array_unshift($targetNames, $configuredPlayerName);
        }
    }

    foreach (array_keys($relationshipMap) as $mapTarget) {
        if (count($targetNames) >= 16) {
            break;
        }
        $normalized = normalizeParticipantNameToken(strval($mapTarget));
        if ($normalized === '') {
            continue;
        }
        if (strcasecmp($normalized, $speaker) === 0) {
            continue;
        }
        $alreadyIncluded = false;
        foreach ($targetNames as $existing) {
            if (strcasecmp($existing, $normalized) === 0) {
                $alreadyIncluded = true;
                break;
            }
        }
        if (!$alreadyIncluded) {
            $targetNames[] = $normalized;
        }
    }

    if (count($targetNames) === 0) {
        return 'No explicit relationship map recorded.';
    }

    $tierOnly = stobeShouldUseTierOnlyRelationshipContext($npcData);
    $lines = [];
    foreach ($targetNames as $targetName) {
        $existingKey = stobeFindRelationshipEntryKey($relationshipMap, $targetName);
        $entry = $existingKey !== '' ? $relationshipMap[$existingKey] : [
            'aff' => 0,
            'type' => 'neutral',
            'tier' => stobeRelationshipTierLabel(0),
            'note' => '',
        ];

        $aff = intval($entry['aff'] ?? 0);
        if ($aff > 100) {
            $aff = 100;
        } elseif ($aff < -100) {
            $aff = -100;
        }
        $type = stobeNormalizeRelationshipTypeToken(strval($entry['type'] ?? 'neutral'));
        $tier = stobeRelationshipTierLabel($aff);
        $note = trim(strval($entry['note'] ?? ''));
        if (strlen($note) > 120) {
            $note = substr($note, 0, 120);
        }

        if ($tierOnly) {
            $line = '* ' . $targetName . ' - ' . $tier . ' (' . $type . ')';
        } else {
            $signedAff = $aff > 0 ? ('+' . strval($aff)) : strval($aff);
            $line = '* ' . $targetName . ' - ' . $signedAff . ' (' . $tier . ', ' . $type . ')';
        }
        if ($note !== '') {
            $line .= ' | ' . $note;
        }
        if (is_string($entry['custom_info'] ?? null) && $entry['custom_info'] !== '') {
            $customInfo = preg_replace('/[\p{C}\s]+/u', ' ', $entry['custom_info']) ?? '';
            $line .= ' | Player note: ' . mb_substr(trim($customInfo), 0, 1000, 'UTF-8');
        }
        $lines[] = $line;
        if (count($lines) >= 16) {
            break;
        }
    }

    if (count($lines) === 0) {
        return 'No explicit relationship map recorded.';
    }
    return implode("\n", $lines);
}

function stobeStripRelationshipCommandTags(string $text): string {
    $cleaned = preg_replace('/#(?:REL|TYPE)\s*:[^#]+#/i', '', $text);
    if (!is_string($cleaned)) {
        $cleaned = $text;
    }
    $cleaned = preg_replace('/<relationships>[\s\S]*?<\/relationships>/i', '', $cleaned) ?? $cleaned;
    $cleaned = preg_replace('/[ \t]{2,}/', ' ', $cleaned) ?? $cleaned;
    $cleaned = preg_replace('/\n{3,}/', "\n\n", $cleaned) ?? $cleaned;
    return trim($cleaned);
}

function stobeParseRelationshipCommandTags(string $text): array {
    $updatesByKey = [];

    $normalizeTarget = static function (string $rawTarget): string {
        if (stobeIsIgnoredRelationshipTarget($rawTarget)) {
            return '';
        }
        $target = normalizeParticipantNameToken($rawTarget);
        if ($target === '') {
            return '';
        }
        return $target;
    };

    if (preg_match_all('/#REL\s*:\s*([^=#]+?)\s*=\s*([+-]?[0-9]{1,3})\s*#/i', $text, $matches)) {
        foreach ($matches[1] as $idx => $rawTarget) {
            $target = $normalizeTarget(strval($rawTarget));
            if ($target === '') {
                continue;
            }
            $key = strtolower($target);
            if (!isset($updatesByKey[$key])) {
                $updatesByKey[$key] = [
                    'target' => $target,
                    'aff_delta' => 0,
                    'type' => '',
                    'note' => '',
                ];
            }
            $updatesByKey[$key]['aff_delta'] += intval($matches[2][$idx] ?? 0);
        }
    }

    if (preg_match_all('/#TYPE\s*:\s*([^=#]+?)\s*=\s*([a-zA-Z][a-zA-Z0-9_-]{0,31})\s*#/i', $text, $matches)) {
        foreach ($matches[1] as $idx => $rawTarget) {
            $target = $normalizeTarget(strval($rawTarget));
            if ($target === '') {
                continue;
            }
            $key = strtolower($target);
            if (!isset($updatesByKey[$key])) {
                $updatesByKey[$key] = [
                    'target' => $target,
                    'aff_delta' => 0,
                    'type' => '',
                    'note' => '',
                ];
            }
            $updatesByKey[$key]['type'] = stobeNormalizeRelationshipTypeToken(strval($matches[2][$idx] ?? 'neutral'));
        }
    }

    return [
        'updates' => array_values($updatesByKey),
        'clean_response' => stobeStripRelationshipCommandTags($text),
    ];
}

function stobeRelationshipTierRepresentativeAffinity(string $tier): ?int {
    $normalized = strtolower(trim($tier));
    return match ($normalized) {
        'bonded' => 95,
        'devoted' => 83,
        'fond' => 66,
        'friendly' => 43,
        'acquaintance' => 12,
        'neutral' => 0,
        'wary' => -18,
        'cold' => -42,
        'resentful' => -65,
        'hateful' => -83,
        'hostile' => -96,
        default => null,
    };
}

function stobeParseRelationshipBulletBlockUpdates(string $rawResponse): array {
    $source = trim($rawResponse);
    if ($source === '') {
        return [];
    }

    if (preg_match('/<relationships\b[^>]*>([\s\S]*?)<\/relationships>/i', $source, $m) === 1) {
        $source = trim(strval($m[1] ?? ''));
    }
    if ($source === '') {
        return [];
    }

    $lines = preg_split('/\r\n|\r|\n/', $source) ?: [];
    $updates = [];

    foreach ($lines as $rawLine) {
        $line = trim(strval($rawLine));
        if ($line === '') {
            continue;
        }

        $line = ltrim($line, " \t*-");
        if ($line === '' || preg_match('/^<\/?[a-z0-9_:-]+/i', $line) === 1) {
            continue;
        }

        $target = '';
        $delta = 0;
        $affSet = null;
        $type = '';
        $note = '';

        if (preg_match('/^(.+?)\s*:\s*[A-Za-z][A-Za-z ]{0,31}\s*\(\s*([a-zA-Z][a-zA-Z0-9_-]{0,31})\s*,\s*aff\s*([+-]?[0-9]{1,3})\s*\)(?:\s*\|\s*(.+))?$/', $line, $m) === 1) {
            $target = strval($m[1] ?? '');
            $type = strval($m[2] ?? '');
            $affSet = intval($m[3] ?? 0);
            $note = trim(strval($m[4] ?? ''));
        } elseif (preg_match('/^(.+?)\s*-\s*([+-]?[0-9]{1,3})\s*\(\s*[A-Za-z][A-Za-z ]{0,31}\s*,\s*([a-zA-Z][a-zA-Z0-9_-]{0,31})\s*\)(?:\s*\|\s*(.+))?$/', $line, $m) === 1) {
            $target = strval($m[1] ?? '');
            $affSet = intval($m[2] ?? 0);
            $type = strval($m[3] ?? '');
            $note = trim(strval($m[4] ?? ''));
        } elseif (preg_match('/^(.+?)\s*-\s*([A-Za-z][A-Za-z ]{0,31})\s*\(\s*([a-zA-Z][a-zA-Z0-9_-]{0,31})\s*\)(?:\s*\|\s*(.+))?$/', $line, $m) === 1) {
            $target = strval($m[1] ?? '');
            $type = strval($m[3] ?? '');
            $affSet = stobeRelationshipTierRepresentativeAffinity(strval($m[2] ?? ''));
            $note = trim(strval($m[4] ?? ''));
        } elseif (preg_match('/^(.+?)\s*-\s*([A-Za-z][A-Za-z ]{0,31})\s*\(([^)]*)\)(?:\s*\|\s*(.+))?$/', $line, $m) === 1) {
            $target = strval($m[1] ?? '');
            $affSet = stobeRelationshipTierRepresentativeAffinity(strval($m[2] ?? ''));
            $inside = strval($m[3] ?? '');
            $tokens = preg_split('/\s*,\s*/', $inside) ?: [];
            foreach ($tokens as $token) {
                $candidate = trim(strval($token));
                if (preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,31}$/', $candidate) === 1) {
                    $type = $candidate;
                    break;
                }
            }
            $note = trim(strval($m[4] ?? ''));
        } elseif (preg_match('/^\s*([^:]+)\s*:\s*([+-]?[0-9]{1,3})(?:\s*:\s*([a-zA-Z][a-zA-Z0-9_-]{0,31}))?\s*$/', $line, $m) === 1) {
            $target = strval($m[1] ?? '');
            $delta = intval($m[2] ?? 0);
            $type = strval($m[3] ?? '');
        }

        $target = normalizeParticipantNameToken($target);
        if ($target === '') {
            continue;
        }
        if (stobeIsIgnoredRelationshipTarget($target)) {
            continue;
        }

        $entry = [
            'target' => $target,
            'aff_delta' => intval($delta),
            'type' => strval($type),
            'note' => $note,
        ];
        if (is_int($affSet)) {
            $entry['aff_set'] = $affSet;
        }
        $updates[] = $entry;

        if (count($updates) >= 24) {
            break;
        }
    }

    return $updates;
}

function stobeParseRelationshipEvalUpdates(string $rawResponse): array {
    $decoded = stobeDecodeStructuredDialoguePayload($rawResponse);
    if (!is_array($decoded) || count($decoded) === 0) {
        return stobeParseRelationshipBulletBlockUpdates($rawResponse);
    }

    $rawUpdates = [];
    if (isset($decoded['updates'])) {
        $rawUpdates = $decoded['updates'];
    } elseif (isset($decoded['relationships'])) {
        $rawUpdates = $decoded['relationships'];
    } elseif (isset($decoded['changes'])) {
        $rawUpdates = $decoded['changes'];
    }

    if (!is_array($rawUpdates)) {
        return stobeParseRelationshipBulletBlockUpdates($rawResponse);
    }

    $isList = array_keys($rawUpdates) === range(0, count($rawUpdates) - 1);
    if (!$isList) {
        $mapped = [];
        foreach ($rawUpdates as $target => $payload) {
            if (!is_array($payload)) {
                continue;
            }
            $payload['target'] = strval($payload['target'] ?? $target);
            $mapped[] = $payload;
        }
        $rawUpdates = $mapped;
    }

    $updates = [];
    foreach ($rawUpdates as $entry) {
        if (is_string($entry)) {
            $target = '';
            $delta = 0;
            $type = '';
            if (preg_match('/^\s*([^:]+)\s*:\s*([+-]?[0-9]{1,3})(?:\s*:\s*([a-zA-Z][a-zA-Z0-9_-]{0,31}))?\s*$/', $entry, $m) === 1) {
                $target = strval($m[1] ?? '');
                $delta = intval($m[2] ?? 0);
                $type = strval($m[3] ?? '');
            }
            if (trim($target) !== '') {
                $updates[] = [
                    'target' => $target,
                    'aff_delta' => $delta,
                    'type' => $type,
                    'note' => '',
                ];
            }
            continue;
        }
        if (!is_array($entry)) {
            continue;
        }

        $target = strval($entry['target'] ?? ($entry['name'] ?? ($entry['listener'] ?? ($entry['npc'] ?? ''))));
        if (trim($target) === '') {
            continue;
        }

        $deltaRaw = $entry['aff_delta'] ?? ($entry['delta'] ?? ($entry['change'] ?? ($entry['affinity_delta'] ?? 0)));
        if (is_string($deltaRaw) && preg_match('/[+-]?[0-9]{1,3}/', $deltaRaw, $m) === 1) {
            $deltaRaw = intval($m[0]);
        }
        $delta = intval($deltaRaw);

        $affSet = null;
        $affSetRaw = $entry['aff_set'] ?? ($entry['affinity'] ?? ($entry['aff_score'] ?? null));
        if (is_int($affSetRaw) || is_float($affSetRaw)) {
            $affSet = intval($affSetRaw);
        } elseif (is_string($affSetRaw) && preg_match('/[+-]?[0-9]{1,3}/', $affSetRaw, $m) === 1) {
            $affSet = intval($m[0]);
        }

        $parsed = [
            'target' => $target,
            'aff_delta' => $delta,
            'type' => strval($entry['type'] ?? ($entry['relationship_type'] ?? ($entry['relation_type'] ?? ''))),
            'note' => strval($entry['note'] ?? ($entry['reason'] ?? ($entry['summary'] ?? ''))),
        ];
        if (is_int($affSet)) {
            $parsed['aff_set'] = $affSet;
        }
        $updates[] = $parsed;
    }

    if (count($updates) === 0) {
        return stobeParseRelationshipBulletBlockUpdates($rawResponse);
    }

    return $updates;
}

function stobeApplyRelationshipUpdatesMap(array $relationshipMap, array $updates, array $allowedTargets = []): array {
    $allowedLookup = [];
    foreach ($allowedTargets as $name) {
        $normalized = normalizeParticipantNameToken(strval($name));
        if ($normalized === '') {
            continue;
        }
        if (stobeIsIgnoredRelationshipTarget($normalized)) {
            continue;
        }
        $allowedLookup[strtolower($normalized)] = true;
    }

    $applied = [];
    foreach ($updates as $update) {
        if (!is_array($update)) {
            continue;
        }
        $targetRaw = strval($update['target'] ?? '');
        if (stobeIsIgnoredRelationshipTarget($targetRaw)) {
            continue;
        }
        $target = normalizeParticipantNameToken($targetRaw);
        if ($target === '') {
            continue;
        }
        $targetLower = strtolower($target);
        if (count($allowedLookup) > 0 && !isset($allowedLookup[$targetLower])) {
            continue;
        }

        $typeCandidate = trim(strval($update['type'] ?? ''));
        $noteCandidate = trim(strval($update['note'] ?? ''));
        if (strlen($noteCandidate) > 180) {
            $noteCandidate = substr($noteCandidate, 0, 180);
        }

        $existingKey = stobeFindRelationshipEntryKey($relationshipMap, $target);
        if ($existingKey === '') {
            $existingKey = $target;
        }
        $existing = is_array($relationshipMap[$existingKey] ?? null) ? $relationshipMap[$existingKey] : [];
        $oldAff = intval($existing['aff'] ?? 0);

        $hasAffSet = false;
        $affSet = 0;
        $affSetRaw = $update['aff_set'] ?? null;
        if (is_int($affSetRaw) || is_float($affSetRaw)) {
            $hasAffSet = true;
            $affSet = intval($affSetRaw);
        } elseif (is_string($affSetRaw) && preg_match('/[+-]?[0-9]{1,3}/', $affSetRaw, $m) === 1) {
            $hasAffSet = true;
            $affSet = intval($m[0]);
        }

        $delta = intval($update['aff_delta'] ?? 0);
        if ($delta > 80) {
            $delta = 80;
        } elseif ($delta < -80) {
            $delta = -80;
        }

        if ($hasAffSet) {
            $newAff = $affSet;
        } else {
            $newAff = $oldAff + $delta;
        }
        if ($newAff > 100) {
            $newAff = 100;
        } elseif ($newAff < -100) {
            $newAff = -100;
        }

        $oldType = stobeNormalizeRelationshipTypeToken(strval($existing['type'] ?? 'neutral'));
        $newType = $oldType;
        if ($typeCandidate !== '') {
            $newType = stobeNormalizeRelationshipTypeToken($typeCandidate);
        }

        $oldNote = trim(strval($existing['note'] ?? ''));
        $newNote = $noteCandidate !== '' ? $noteCandidate : $oldNote;

        $changed = ($newAff !== $oldAff) || ($newType !== $oldType) || ($newNote !== $oldNote);
        if (!$changed) {
            continue;
        }

        $relationshipMap[$existingKey] = [
            'aff' => $newAff,
            'type' => $newType,
            'tier' => stobeRelationshipTierLabel($newAff),
            'note' => $newNote,
            'updated_at' => intval(time()),
        ];
        if (is_string($existing['custom_info'] ?? null) && $existing['custom_info'] !== '') {
            $relationshipMap[$existingKey]['custom_info'] = $existing['custom_info'];
        }

        $applied[] = [
            'target' => $existingKey,
            'delta' => ($newAff - $oldAff),
            'old_aff' => $oldAff,
            'new_aff' => $newAff,
            'type' => $newType,
        ];
    }

    return [
        'map' => $relationshipMap,
        'applied' => $applied,
        'updated' => count($applied),
    ];
}

// AI may change relationship state, but only the player can replace or remove their own notes.
function stobePreserveRelationshipCustomInfo(array $existing, array $incoming): array {
    foreach ($incoming as &$relationship) {
        if (is_array($relationship)) {
            unset($relationship['custom_info']);
        }
    }
    unset($relationship);
    foreach ($existing as $target => $relationship) {
        if (!is_array($relationship) || !is_string($relationship['custom_info'] ?? null) || $relationship['custom_info'] === '') {
            continue;
        }
        $key = stobeFindRelationshipEntryKey($incoming, strval($target));
        if ($key === '') {
            $incoming[$target] = $relationship;
        } elseif (!is_array($incoming[$key])) {
            $incoming[$key] = $relationship;
        } else {
            $incoming[$key]['custom_info'] = $relationship['custom_info'];
        }
    }
    return $incoming;
}

function stobePersistNpcRelationshipMap(string $speakerName, array $relationshipMap, array|false $npcData = false): bool {
    $normalizedSpeaker = normalizeParticipantNameToken($speakerName);
    if ($normalizedSpeaker === '') {
        return false;
    }
    if (!is_array($npcData)) {
        $npcData = getNpcData($normalizedSpeaker);
    }
    if (!is_array($npcData)) {
        return false;
    }

    $npcId = intval($npcData['id'] ?? 0);
    if ($npcId <= 0) {
        return false;
    }

    $normalizedMap = stobeNormalizeRelationshipMap($relationshipMap);
    $db = $GLOBALS["db"];
    $updated = stobeRunWithRelationshipExtendedDataWrite(
        static function () use ($db, $normalizedMap, $npcId) {
            $current = getNpcById($npcId);
            if (!$current) {
                return false;
            }
            $map = stobePreserveRelationshipCustomInfo(stobeGetNpcRelationshipMap($current), $normalizedMap);
            $serializedMap = count($map) > 0 ? normalizeJsonString($map) : '';
            $serializedJsonbMap = count($map) > 0 ? normalizeJsonString($map) : '{}';
            $result = $db->exec(
                "UPDATE core_npc
                 SET relationships = $1,
                     extended_data = jsonb_set(
                         COALESCE(extended_data, '{}'::jsonb),
                         '{relationships}',
                         $2::jsonb,
                         true
                     ),
                     updated_at = NOW()
                 WHERE id = $3",
                [$serializedMap, $serializedJsonbMap, $npcId]
            );
            if ($result !== false) {
                stobeRelationshipTimelineStamp($npcId);
            }
            return $result;
        },
        $npcId
    );
    if ($updated === false) {
        return false;
    }

    return true;
}

// Keep the random boundary deterministic for regression probes without changing runtime behavior.
function stobeShouldRunAutomaticRelationshipEvaluation(int $chance, ?int $roll = null): bool {
    $chance = max(0, min(100, $chance));
    if ($chance === 0) {
        return false;
    }
    if ($chance === 100) {
        return true;
    }

    $roll = $roll === null ? random_int(1, 100) : max(1, min(100, $roll));
    return $roll <= $chance;
}

function stobeRelationshipTurnNeedsConnectorEvaluation(
    string $incomingLine,
    string $responseText,
    string $eventType,
    int $configuredChance
): bool {
    $chance = max(0, min(100, $configuredChance));
    if ($chance <= 0) {
        return false;
    }
    $type = strtolower(trim($eventType));
    $salientTypes = [
        'trade', 'healing', 'death', 'recruit', 'join', 'leave',
        'combat_end', 'major_damage', 'limb_loss', 'knockout',
        'slavery', 'enslaved', 'freed_slave', 'imprisonment',
    ];
    if (in_array($type, $salientTypes, true)) {
        return true;
    }
    $text = strtolower(trim($incomingLine . ' ' . $responseText));
    if ($text !== '' && preg_match(
        '/\b(thank|thanks|appreciat|sorry|apolog|forgiv|trust|love|hate|friend|enemy|betray|promise|owe|debt|deal|agree|saved|rescue|helped me|protect|threat|kill you|hurt you|idiot|bastard|liar|jealous|marry|kiss|date|romance|leave me|stay with me)\b/i',
        $text
    ) === 1) {
        return true;
    }
    $backgroundChance = max(1, intval(round($chance / 4)));
    return stobeShouldRunAutomaticRelationshipEvaluation($backgroundChance);
}

function stobeEvaluateRelationshipsForTurn(
    string $speakerName,
    string $listenerName,
    string $incomingLine,
    string $responseText,
    array|false $speakerNpcData = false,
    string $eventType = 'chat'
): array {
    $result = [
        'clean_response' => stobeStripRelationshipCommandTags($responseText),
        'method' => 'none',
        'updated' => 0,
        'applied' => [],
        'error' => '',
    ];

    $speaker = normalizeParticipantNameToken($speakerName);
    if ($speaker === '' || strcasecmp($speaker, 'The Narrator') === 0) {
        $result['error'] = 'invalid_speaker';
        return $result;
    }
    $hasInlineCommands = (preg_match('/#(?:REL|TYPE)\s*:/i', $responseText) === 1)
        || (preg_match('/<relationships\b[^>]*>[\s\S]*<\/relationships>/i', $responseText) === 1);
    if (trim($result['clean_response']) === '' && !$hasInlineCommands) {
        $result['error'] = 'empty_response';
        return $result;
    }

    if (!is_array($speakerNpcData)) {
        $speakerNpcData = getNpcData($speaker);
    }
    if (!is_array($speakerNpcData)) {
        $result['error'] = 'missing_speaker_profile';
        return $result;
    }

    if (function_exists('stobeIsRelationshipSystemEnabled') && !stobeIsRelationshipSystemEnabled()) {
        $result['error'] = 'relationship_system_disabled';
        return $result;
    }

    $enabled = getNpcProfileBoolSetting(
        $speakerNpcData,
        ['RELATIONSHIP_SYSTEM', 'RELATIONSHIP_SYSTEM_ENABLED'],
        'RELATIONSHIP_SYSTEM',
        true
    );
    if (!$enabled) {
        $result['error'] = 'relationship_system_disabled';
        return $result;
    }

    $listener = normalizeParticipantNameToken($listenerName);
    if ($listener === '') {
        $listener = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    }

    $relationshipMap = stobeGetNpcRelationshipMap($speakerNpcData);
    $contextTargets = stobeCollectRelationshipContextTargets($speakerNpcData, $speaker, $listener);
    foreach (array_keys($relationshipMap) as $mapTarget) {
        $contextTargets[] = $mapTarget;
    }
    $contextTargets = array_values(array_unique(array_filter(array_map(
        static fn($value): string => normalizeParticipantNameToken(strval($value)),
        $contextTargets
    ))));

    $updates = [];
    $method = '';
    $relationshipUpdateChance = getNpcProfileIntegerSetting(
        $speakerNpcData,
        ['RELATIONSHIP_UPDATE_CHANCE'],
        '',
        50,
        0,
        100
    );
    $shouldRunConnectorEvaluation = stobeRelationshipTurnNeedsConnectorEvaluation(
        $incomingLine,
        $result['clean_response'],
        $eventType,
        $relationshipUpdateChance
    );
    $connector = false;
    $connectorApiKey = '';
    if ($shouldRunConnectorEvaluation) {
        $connector = getProfileLlmConnectorForNpcByPurpose($speakerNpcData, 'relationship');
        $connectorApiKey = trim(strval($connector['api_badge_key'] ?? ($connector['api_key'] ?? '')));
    }
    if ($shouldRunConnectorEvaluation && is_array($connector) && intval($connector['id'] ?? 0) > 0 && $connectorApiKey !== '' && function_exists('stobeCallLLM')) {
        $currentRelationships = [];
        foreach ($contextTargets as $targetName) {
            $key = stobeFindRelationshipEntryKey($relationshipMap, $targetName);
            $entry = $key !== '' ? ($relationshipMap[$key] ?? []) : [];
            $aff = intval($entry['aff'] ?? 0);
            $type = stobeNormalizeRelationshipTypeToken(strval($entry['type'] ?? 'neutral'));
            $currentRelationships[] = $targetName . ': ' . strval($aff) . ' (' . stobeRelationshipTierLabel($aff) . ', ' . $type . ')';
            if (count($currentRelationships) >= 16) {
                break;
            }
        }
        if (count($currentRelationships) === 0) {
            $currentRelationships[] = '(none)';
        }

        $systemPrompt = "<relationship_evaluator>\n"
            . "  <rule>You update only the speaker NPC relationship map for this single turn.</rule>\n"
            . "  <rule>Return strict JSON only: {\"updates\":[{\"target\":\"Name\",\"aff_delta\":-10,\"type\":\"rival\",\"note\":\"short optional note\"}]}</rule>\n"
            . "  <rule>Use at most 3 updates.</rule>\n"
            . "  <rule>Use conservative deltas for normal chat (typically -8..+8). Reserve larger deltas for major events.</rule>\n"
            . "  <rule>Use lowercase one-word types. If type should not change, omit type or leave it empty.</rule>\n"
            . "  <rule>If nothing changed, return {\"updates\":[]}.</rule>\n"
            . "</relationship_evaluator>";

        $userPrompt = "<relationship_turn>\n"
            . "  <speaker>" . stobePromptXmlEscape($speaker) . "</speaker>\n"
            . "  <listener>" . stobePromptXmlEscape($listener) . "</listener>\n"
            . "  <event_type>" . stobePromptXmlEscape($eventType) . "</event_type>\n"
            . "  <allowed_targets>" . stobePromptXmlEscape(implode(', ', $contextTargets)) . "</allowed_targets>\n"
            . "  <incoming_line>" . stobePromptXmlEscape($incomingLine) . "</incoming_line>\n"
            . "  <speaker_response>" . stobePromptXmlEscape($result['clean_response']) . "</speaker_response>\n"
            . "  <current_relationships>" . stobePromptXmlEscape(implode(' | ', $currentRelationships)) . "</current_relationships>\n"
            . "</relationship_turn>";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];
        $relConfig = getLlmConfigForNpcPurpose($speakerNpcData, 'relationship');
        $rawEval = stobeCallLLM($messages, $relConfig, [
            'npc_name' => $speaker,
            'event_type' => 'relationship_eval',
            'response_format' => ['type' => 'json_object'],
        ]);
        if ($rawEval !== false && trim(strval($rawEval)) !== '') {
            $updates = stobeParseRelationshipEvalUpdates(strval($rawEval));
            if (count($updates) > 0) {
                $method = 'connector';
            } else {
                $result['error'] = 'connector_empty_updates';
            }
        } else {
            $result['error'] = 'connector_eval_failed';
        }
    } elseif ($shouldRunConnectorEvaluation && is_array($connector) && intval($connector['id'] ?? 0) > 0 && $connectorApiKey !== '' && !function_exists('stobeCallLLM')) {
        $result['error'] = 'callllm_unavailable';
    }

    if (count($updates) === 0) {
        $parsedTags = stobeParseRelationshipCommandTags($responseText);
        $updates = is_array($parsedTags['updates'] ?? null) ? $parsedTags['updates'] : [];
        if (count($updates) > 0) {
            $method = 'command_tags';
        }
    }

    if (count($updates) === 0) {
        $updates = stobeParseRelationshipBulletBlockUpdates($responseText);
        if (count($updates) > 0) {
            $method = 'response_relationship_block';
        }
    }

    if (count($updates) === 0) {
        if ($method === '') {
            $method = 'none';
        }
        $result['method'] = $method;
        stobeLogRelationshipDebug('Relationship evaluation skipped/no changes', [
            'speaker' => $speaker,
            'listener' => $listener,
            'event_type' => $eventType,
            'method' => $method,
            'error' => $result['error'],
        ]);
        return $result;
    }

    $applied = stobeApplyRelationshipUpdatesMap($relationshipMap, $updates, $contextTargets);
    $updatedCount = intval($applied['updated'] ?? 0);
    $appliedRows = is_array($applied['applied'] ?? null) ? $applied['applied'] : [];
    if ($updatedCount <= 0) {
        $result['method'] = $method !== '' ? $method : 'none';
        $result['updated'] = 0;
        $result['applied'] = [];
        return $result;
    }

    $persisted = stobePersistNpcRelationshipMap($speaker, is_array($applied['map'] ?? null) ? $applied['map'] : [], $speakerNpcData);
    if (!$persisted) {
        stobeLogRelationshipWarn('Relationship updates computed but not persisted', [
            'speaker' => $speaker,
            'listener' => $listener,
            'event_type' => $eventType,
            'method' => $method,
            'updated' => $updatedCount,
        ]);
        $result['error'] = 'persist_failed';
    }

    $result['method'] = $method !== '' ? $method : 'unknown';
    $result['updated'] = $updatedCount;
    $result['applied'] = $appliedRows;

    stobeLogRelationshipInfo('Relationship updates applied', [
        'speaker' => $speaker,
        'listener' => $listener,
        'event_type' => $eventType,
        'method' => $result['method'],
        'updated' => $updatedCount,
        'applied' => $appliedRows,
    ]);

    return $result;
}

function stobeBuildNpcEquipmentInventoryText(array $npcData, array $metadata): array {
    $inventoryContext = buildInventoryContextFromMetadata($metadata);

    $equipment = trim(strval($inventoryContext['equipment'] ?? ''));
    $usedEquipmentFallback = false;
    if ($equipment === '') {
        $equipment = trim(strval($npcData['equipment'] ?? ''));
        $usedEquipmentFallback = true;
    }
    if ($usedEquipmentFallback) {
        $equipment = stobeEnrichItemCsvWithDescriptions($equipment, 14, 0, 4000);
    }
    if ($equipment === '') {
        $equipment = 'No notable equipment recorded.';
    }

    $inventory = trim(strval($inventoryContext['inventory'] ?? ''));
    $usedInventoryFallback = false;
    if ($inventory === '' && !boolval($inventoryContext['has_items'] ?? false)) {
        $inventory = trim(strval($npcData['inventory'] ?? ''));
        $usedInventoryFallback = true;
    }
    if ($usedInventoryFallback) {
        $inventory = stobeEnrichItemCsvWithDescriptions($inventory, 16, 0, 4500);
    }
    if ($inventory === '') {
        $inventory = 'No notable inventory recorded.';
    }

    return [
        'equipment' => truncatePromptValue($equipment, 1800),
        'inventory' => truncatePromptValue($inventory, 1800),
    ];
}

function stobeExtractMiddleTermMemoryEntriesFromExtendedData(array $npcData, int $maxEntries): array {
    if ($maxEntries < 1) {
        $maxEntries = 1;
    } elseif ($maxEntries > 8) {
        $maxEntries = 8;
    }

    $extended = normalizeCoreNpcExtendedData($npcData['extended_data'] ?? []);
    $rawEntries = $extended['middle_term_memory'] ?? [];
    if (!is_array($rawEntries) || count($rawEntries) === 0) {
        return [];
    }

    $orderedEntries = [];
    $isSequential = array_keys($rawEntries) === range(0, count($rawEntries) - 1);
    if ($isSequential) {
        $orderedEntries = array_values($rawEntries);
    } else {
        $numeric = [];
        $other = [];
        foreach ($rawEntries as $key => $entry) {
            if (preg_match('/^-?\d+$/', strval($key)) === 1) {
                $numeric[intval($key)] = $entry;
            } else {
                $other[] = $entry;
            }
        }
        if (count($numeric) > 0) {
            ksort($numeric, SORT_NUMERIC);
            foreach ($numeric as $entry) {
                $orderedEntries[] = $entry;
            }
        }
        foreach ($other as $entry) {
            $orderedEntries[] = $entry;
        }
    }

    $entries = [];
    foreach ($orderedEntries as $entry) {
        if (!is_scalar($entry) || $entry === null) {
            continue;
        }
        $text = trim(sanitizeForKenshi(strval($entry)));
        if ($text === '') {
            continue;
        }
        $entries[] = truncatePromptValue($text, 1400);
    }

    if (count($entries) === 0) {
        return [];
    }

    if (count($entries) > $maxEntries) {
        $entries = array_slice($entries, -$maxEntries);
    }

    return array_values($entries);
}

function stobeMemorySummaryTableAvailable(): bool {
    static $available = null;
    if ($available !== null) {
        return boolval($available);
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        $available = false;
        return false;
    }

    $row = $db->fetchOne("SELECT to_regclass('public.memory_summary') AS rel");
    $available = is_array($row) && trim(strval($row['rel'] ?? '')) !== '';
    return boolval($available);
}

function stobeFetchMiddleTermMemorySummaryEntries(string $npcName, array $npcData, int $maxEntries): array {
    if ($maxEntries < 1) {
        $maxEntries = 1;
    } elseif ($maxEntries > 8) {
        $maxEntries = 8;
    }

    $safeNpcName = normalizeParticipantNameToken($npcName);
    if ($safeNpcName === '') {
        return [];
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return [];
    }
    if (!stobeMemorySummaryTableAvailable()) {
        return [];
    }

    $quotedNpc = strtolower(json_encode($safeNpcName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    if ($quotedNpc === '') {
        $quotedNpc = '"' . strtolower($safeNpcName) . '"';
    }

    $rows = $db->fetchAll(
        "SELECT summary
         FROM memory_summary
         WHERE (
                LOWER(people) = LOWER($1)
                OR POSITION($2 IN LOWER(COALESCE(people, ''))) > 0
               )
           AND summary IS NOT NULL
           AND BTRIM(summary) <> ''
         ORDER BY COALESCE(period_end, created_at) DESC, created_at DESC
         LIMIT " . intval($maxEntries),
        [$safeNpcName, $quotedNpc]
    );

    if (!is_array($rows) || count($rows) === 0) {
        return [];
    }

    $entries = [];
    foreach ($rows as $row) {
        $summary = trim(sanitizeForKenshi(strval($row['summary'] ?? '')));
        if ($summary === '') {
            continue;
        }
        $entries[] = truncatePromptValue($summary, 1400);
    }

    if (count($entries) === 0) {
        return [];
    }

    // DB query is newest-first; keep prompt entries oldest->newest for readability.
    return array_values(array_reverse($entries));
}

function stobeBuildMiddleTermMemoryPromptBlock(array $npcData, string $npcName): string {
    $safeNpcName = normalizeParticipantNameToken($npcName);
    if ($safeNpcName === '' || strcasecmp($safeNpcName, 'The Narrator') === 0) {
        return '';
    }

    // Herika-style prompt injection: only latest middle-term memory entry.
    $entries = stobeExtractMiddleTermMemoryEntriesFromExtendedData($npcData, 1);
    if (count($entries) === 0) {
        return '';
    }

    $latest = trim(strval($entries[count($entries) - 1] ?? ''));
    if ($latest === '') {
        return '';
    }

    $lines = [];
    $lines[] = '<middle_term_memory>';
    $lines[] = '#Past events';
    $lines[] = $latest;
    $lines[] = '</middle_term_memory>';
    return implode("\n", $lines);
}

function stobeExtractSimpleXmlTagValue(string $xml, string $tag): string {
    $safeTag = strtolower(trim($tag));
    if ($safeTag === '' || preg_match('/^[a-z0-9_]+$/', $safeTag) !== 1) {
        return '';
    }
    if (preg_match('/<' . preg_quote($safeTag, '/') . '>(.*?)<\/' . preg_quote($safeTag, '/') . '>/isu', $xml, $matches) !== 1) {
        return '';
    }
    $raw = trim(strval($matches[1] ?? ''));
    if ($raw === '') {
        return '';
    }
    return trim(html_entity_decode($raw, ENT_QUOTES | ENT_XML1, 'UTF-8'));
}

function stobeIndentPromptBlock(string $block, int $spaces = 2): string {
    $trimmed = trim($block);
    if ($trimmed === '') {
        return '';
    }
    $prefix = str_repeat(' ', max(0, $spaces));
    $lines = preg_split('/\R/', $trimmed) ?: [];
    $indented = [];
    foreach ($lines as $line) {
        $lineText = strval($line);
        if (trim($lineText) === '') {
            continue;
        }
        $indented[] = $prefix . $lineText;
    }
    return implode("\n", $indented);
}

function stobeBuildNarratorNearbyActorsContextBlock(array $speakerData, string $speakerName = ''): string {
    return stobeBuildNearbyActorsPromptBlock($speakerData, $speakerName);
}

function stobeBuildNarratorNearbyItemsContextBlock(array $speakerData): string {
    return stobeBuildNearbyItemsPromptBlock($speakerData);
}

function stobeBuildNarratorPointsOfInterestContextBlock(array $speakerData): string {
    return stobeBuildPointsOfInterestPromptBlock($speakerData);
}

function stobeBuildNarratorSpeakerContextBlock(string $speakerName): string {
    $safeSpeaker = normalizeParticipantNameToken($speakerName);
    if ($safeSpeaker === '') {
        $safeSpeaker = trim($speakerName);
    }
    if ($safeSpeaker === '') {
        $safeSpeaker = 'Speaker';
    }

    $speakerData = getNpcData($safeSpeaker);
    $speakerRace = '';
    $speakerFaction = '';
    $speakerGender = '';
    $speakerOccupation = '';
    $speakerSummary = '';
    $speakerPersonality = '';
    $speakerSpeechStyle = '';
    $speakerGoals = '';
    $speakerAppearance = '';
    $speakerCondition = '';
    $speakerEquipment = '';
    $speakerInventory = '';
    $speakerRelationships = '';
    $speakerSkillsBlock = '';
    $speakerBountyBlock = '';
    $speakerWorldStateBlock = '';
    $speakerPlayerBaseBlock = '';
    $nearbyActorsBlock = '';
    $nearbyItemsBlock = '';
    $pointsOfInterestBlock = '';

    if (is_array($speakerData) && count($speakerData) > 0) {
        $metadata = normalizeNpcMetadataPayload($speakerData['metadata'] ?? []);
        $speakerRace = trim(strval($speakerData['race'] ?? ''));
        $speakerFactionIdentity = getNpcFactionIdentityFromProfile($speakerData);
        $speakerFaction = trim(strval($speakerFactionIdentity['name'] ?? ''));
        if ($speakerFaction === '') {
            $speakerFaction = stobeFactionDisplayName(strval($speakerData['faction'] ?? ''));
        }
        $speakerFaction = stobeResolvePlayerFactionPromptDisplayName($speakerFaction, $speakerFactionIdentity);
        $speakerGender = trim(strval($speakerData['gender'] ?? ''));
        $speakerOccupation = trim(strval($speakerData['occupation'] ?? ''));
        if ($speakerOccupation !== '') {
            $speakerOccupation = preg_replace('/\s*\[default[^\]]*factionsid\]\s*/iu', ' ', $speakerOccupation) ?? $speakerOccupation;
            $speakerOccupation = trim(preg_replace('/\s+/u', ' ', $speakerOccupation) ?? $speakerOccupation);
            if (npcIsInPlayerFaction($speakerData)) {
                $speakerOccupation = stobeAliasPlayerFactionNameInText($speakerOccupation, $speakerFactionIdentity, true);
            }
        }
        $speakerSummary = trim(strval($speakerData['backstory'] ?? ''));
        if ($speakerSummary === '') {
            $speakerSummary = trim(strval($speakerData['core'] ?? ''));
        }
        $speakerPersonality = trim(strval($speakerData['personality'] ?? ''));
        $speakerSpeechStyle = trim(strval($speakerData['speechstyle'] ?? ''));
        $speakerGoals = trim(strval($speakerData['goals'] ?? ''));
        $speakerAppearance = stobeBuildNpcAppearanceText($speakerData);
        $speakerCondition = stobeBuildNpcConditionText($speakerData, $metadata);
        $equipmentInventory = stobeBuildNpcEquipmentInventoryText($speakerData, $metadata);
        $speakerEquipment = trim(strval($equipmentInventory['equipment'] ?? ''));
        $speakerInventory = trim(strval($equipmentInventory['inventory'] ?? ''));
        $narratorName = function_exists('stobeNarratorName') ? stobeNarratorName() : 'The Narrator';
        $speakerRelationships = stobeBuildNpcRelationshipsText($safeSpeaker, $narratorName, $speakerData);
        $speakerSkillsBlock = stobeBuildNpcSkillsText($speakerData);
        $speakerBountyBlock = stobeBuildNpcBountyPromptBlock($speakerData);
        $speakerWorldStateBlock = buildWorldStateBlock($speakerData);
        $speakerPlayerBaseBlock = buildPlayerBaseStateBlock($speakerData);
        $nearbyActorsBlock = stobeBuildNarratorNearbyActorsContextBlock($speakerData, $safeSpeaker);
        $nearbyItemsBlock = stobeBuildNarratorNearbyItemsContextBlock($speakerData);
        $pointsOfInterestBlock = stobeBuildNarratorPointsOfInterestContextBlock($speakerData);
    }

    $geo = getEventGeoFromNpcName($safeSpeaker);
    $hasGeo = trim(strval($geo['location'] ?? '')) !== ''
        || trim(strval($geo['city'] ?? '')) !== ''
        || trim(strval($geo['region'] ?? '')) !== '';
    if (!$hasGeo) {
        $geo = getEventGeoFromPlayerSnapshot();
    }

    $includeBasicSummary = stobePromptContextOptionEnabled('enabled_character_subsections', 'basic_summary');
    $includePersonality = stobePromptContextOptionEnabled('enabled_character_subsections', 'personality');
    $includeOccupation = stobePromptContextOptionEnabled('enabled_character_subsections', 'occupation');
    $includeSpeechStyle = stobePromptContextOptionEnabled('enabled_character_subsections', 'speech_style');
    $includeGoals = stobePromptContextOptionEnabled('enabled_character_subsections', 'goals');
    $includeAppearance = stobePromptContextOptionEnabled('enabled_character_subsections', 'appearance');
    $includeCurrentCondition = stobePromptContextOptionEnabled('enabled_state_subsections', 'current_condition');
    $includeEquipment = stobePromptContextOptionEnabled('enabled_state_subsections', 'equipment');
    $includePersonalInventory = stobePromptContextOptionEnabled('enabled_state_subsections', 'personal_inventory');

    $lines = ['<speaker_context>'];
    $lines[] = '  <name>' . stobePromptXmlEscape($safeSpeaker) . '</name>';
    if ($speakerRace !== '') {
        $lines[] = '  <race>' . stobePromptXmlEscape($speakerRace) . '</race>';
    }
    if ($speakerFaction !== '') {
        $lines[] = '  <faction>' . stobePromptXmlEscape($speakerFaction) . '</faction>';
    }
    if ($speakerGender !== '') {
        $lines[] = '  <gender>' . stobePromptXmlEscape($speakerGender) . '</gender>';
    }
    if ($speakerOccupation !== '' && $includeOccupation) {
        $lines[] = '  <occupation>' . stobePromptXmlEscape($speakerOccupation) . '</occupation>';
    }
    if ($speakerSummary !== '' && $includeBasicSummary) {
        $lines[] = '  <summary>' . stobePromptXmlEscape($speakerSummary) . '</summary>';
    }
    if ($speakerPersonality !== '' && $includePersonality) {
        $lines[] = '  <personality>' . stobePromptXmlEscape($speakerPersonality) . '</personality>';
    }
    if ($speakerSpeechStyle !== '' && $includeSpeechStyle) {
        $lines[] = '  <speech_style>' . stobePromptXmlEscape($speakerSpeechStyle) . '</speech_style>';
    }
    if ($speakerGoals !== '' && $includeGoals) {
        $lines[] = '  <goals>' . stobePromptXmlEscape($speakerGoals) . '</goals>';
    }
    if ($speakerAppearance !== '' && $includeAppearance) {
        $lines[] = '  <appearance>' . stobePromptXmlEscape($speakerAppearance) . '</appearance>';
    }
    if ($speakerCondition !== '' && $includeCurrentCondition) {
        $lines[] = '  <current_condition>' . stobePromptXmlEscape($speakerCondition) . '</current_condition>';
    }
    if ($speakerEquipment !== '' && $includeEquipment) {
        $lines[] = '  <equipment>' . stobePromptXmlEscape($speakerEquipment) . '</equipment>';
    }
    if ($speakerInventory !== '' && $includePersonalInventory) {
        $lines[] = '  <inventory>' . stobePromptXmlEscape($speakerInventory) . '</inventory>';
    }
    if ($speakerRelationships !== '') {
        $lines[] = '  <relationships>' . stobePromptXmlEscape($speakerRelationships) . '</relationships>';
    }
    if ($speakerSkillsBlock !== '') {
        $lines[] = '  <skills>';
        $skillsIndented = stobeIndentPromptBlock($speakerSkillsBlock, 4);
        if ($skillsIndented !== '') {
            $lines[] = $skillsIndented;
        }
        $lines[] = '  </skills>';
    }
    if ($speakerBountyBlock !== '') {
        $bountyIndented = stobeIndentPromptBlock($speakerBountyBlock, 2);
        if ($bountyIndented !== '') {
            $lines[] = $bountyIndented;
        }
    }
    if ($speakerWorldStateBlock !== '') {
        $worldStateIndented = stobeIndentPromptBlock($speakerWorldStateBlock, 2);
        if ($worldStateIndented !== '') {
            $lines[] = $worldStateIndented;
        }
    }
    if ($speakerPlayerBaseBlock !== '') {
        $playerBaseIndented = stobeIndentPromptBlock($speakerPlayerBaseBlock, 2);
        if ($playerBaseIndented !== '') {
            $lines[] = $playerBaseIndented;
        }
    }

    if ($nearbyActorsBlock !== '') {
        $nearbyActorsIndented = stobeIndentPromptBlock($nearbyActorsBlock, 2);
        if ($nearbyActorsIndented !== '') {
            $lines[] = $nearbyActorsIndented;
        }
    }
    if ($nearbyItemsBlock !== '') {
        $nearbyItemsIndented = stobeIndentPromptBlock($nearbyItemsBlock, 2);
        if ($nearbyItemsIndented !== '') {
            $lines[] = $nearbyItemsIndented;
        }
    }
    if ($pointsOfInterestBlock !== '') {
        $pointsIndented = stobeIndentPromptBlock($pointsOfInterestBlock, 2);
        if ($pointsIndented !== '') {
            $lines[] = $pointsIndented;
        }
    }

    $lines[] = '</speaker_context>';
    return implode("\n", $lines);
}

function stobeBuildNarratorSystemPrompt(
    array $narratorData,
    string $speakerName,
    string $playerMessage = '',
    int $currentGamets = 0,
    string $eventType = 'chat'
): string {
    $narratorName = function_exists('stobeNarratorRoleplayName')
        ? stobeNarratorRoleplayName()
        : 'The Narrator';
    $metadata = normalizeNpcMetadataPayload($narratorData['metadata'] ?? []);
    $safeSpeaker = normalizeParticipantNameToken($speakerName);
    $normalizedEventType = strtolower(trim($eventType));
    if ($normalizedEventType === '') {
        $normalizedEventType = 'chat';
    }
    $isNarrationEvent = in_array($normalizedEventType, ['narration', 'narrator_welcome'], true);
    $isRandomNarrationEvent = ($normalizedEventType === 'narration');
    if ($safeSpeaker === '') {
        $safeSpeaker = trim($speakerName);
    }
    if ($safeSpeaker === '') {
        $safeSpeaker = 'Speaker';
    }

    $narratorSummary = trim(strval($narratorData['backstory'] ?? ''));
    if ($narratorSummary === '') {
        $narratorSummary = trim(strval($narratorData['core'] ?? ''));
    }
    if ($narratorSummary === '') {
        $narratorSummary = "A guiding voice that describes the world, events, and transitions. This voice exists only in the speaker's mind.";
    }

    $narratorPersonality = trim(strval($narratorData['personality'] ?? ''));
    if ($narratorPersonality === '') {
        $narratorPersonality = 'Laid-back, observant, and friendly; describes scenes with calm confidence.';
    }
    $narratorSpeechStyle = trim(strval($narratorData['speechstyle'] ?? ''));
    if ($isRandomNarrationEvent) {
        if ($narratorSpeechStyle === '') {
            $narratorSpeechStyle = 'Vivid third-person scene framing in one or two concise sentences.';
        }
    } elseif ($isNarrationEvent) {
        if ($narratorSpeechStyle === '') {
            $narratorSpeechStyle = 'Relaxed and descriptive, with vivid scene framing in one or two concise sentences.';
        }
    } else {
        $narratorSpeechStyle = 'Relaxed and conversational, focused on direct one-on-one replies. Never switch into scene narration.';
    }
    $narratorGoals = trim(strval($narratorData['goals'] ?? ''));

    $roleplayInstructions = stobeBuildRoleplayInstructionsText($narratorName, $safeSpeaker, $narratorData);
    $generalInstructions = stobeBuildGeneralInstructionsText($narratorData);
    $promptOverrides = stobeResolveNpcPromptOverrides($narratorData, $metadata);
    $promptHeadOverride = trim(strval($promptOverrides['prompt_head'] ?? ''));
    $profilePromptOverride = trim(strval($promptOverrides['profile_prompt'] ?? ''));
    if ($promptHeadOverride !== '') {
        $roleplayInstructions = trim($promptHeadOverride . "\n\n" . $roleplayInstructions);
    }
    if ($profilePromptOverride !== '') {
        $generalInstructions = trim($profilePromptOverride . "\n" . $generalInstructions);
    }

    $speakerContextBlock = stobeBuildNarratorSpeakerContextBlock($safeSpeaker);

    $lines = [];
    $lines[] = '<roleplay_instructions>';
    $lines[] = stobePromptXmlEscape($roleplayInstructions);
    $lines[] = '</roleplay_instructions>';
    $lines[] = '';
    $lines[] = '<narrator_profile>';
    $lines[] = '  <name>' . stobePromptXmlEscape($narratorName) . '</name>';
    $lines[] = '  <role>Private inner narrator voice for the current speaker.</role>';
    $lines[] = '  <summary>' . stobePromptXmlEscape($narratorSummary) . '</summary>';
    $lines[] = '  <personality>' . stobePromptXmlEscape($narratorPersonality) . '</personality>';
    if ($narratorSpeechStyle !== '') {
        $lines[] = '  <speech_style>' . stobePromptXmlEscape($narratorSpeechStyle) . '</speech_style>';
    }
    if ($narratorGoals !== '') {
        $lines[] = '  <goals>' . stobePromptXmlEscape($narratorGoals) . '</goals>';
    }
    $lines[] = '</narrator_profile>';
    if ($speakerContextBlock !== '') {
        $lines[] = '';
        $lines[] = $speakerContextBlock;
    }
    $lines[] = '';
    $lines[] = '<general_instructions>';
    $lines[] = stobePromptXmlEscape($generalInstructions);
    $lines[] = '</general_instructions>';
    $lines[] = '';
    if ($isRandomNarrationEvent) {
        $lines[] = '<narration_rules>';
        $lines[] = '  <rule>Focus on concise scene narration in third-person storytelling style.</rule>';
        $lines[] = '  <rule>Treat the current speaker as the focal subject when relevant, but do not address them directly.</rule>';
        $lines[] = '  <rule>Never use second-person pronouns like "you" or "your".</rule>';
        $lines[] = '  <rule>Prefer explicit character names and clear third-person pronouns.</rule>';
        $lines[] = '  <rule>Keep the narration tightly grounded in recent context and avoid invented events.</rule>';
        $lines[] = '  <rule>Keep output to one or two concise sentences.</rule>';
        $lines[] = '  <rule>Do not output action tags or command syntax.</rule>';
        $lines[] = '</narration_rules>';
    } elseif ($isNarrationEvent) {
        $lines[] = '<narration_rules>';
        $lines[] = '  <rule>Only the narrator and the current speaker are in this conversation.</rule>';
        $lines[] = '  <rule>Address only the current speaker directly.</rule>';
        $lines[] = '  <rule>Focus on concise scene narration unless explicitly asked for something else.</rule>';
        $lines[] = '  <rule>Do not output action tags or command syntax.</rule>';
        $lines[] = '</narration_rules>';
    } else {
        $lines[] = '<conversation_rules>';
        $lines[] = '  <rule>Only the narrator and the current speaker are in this conversation.</rule>';
        $lines[] = '  <rule>Address only the current speaker directly.</rule>';
        $lines[] = '  <rule>Respond to the speaker&apos;s latest words as direct conversation.</rule>';
        $lines[] = '  <rule>Never output scene narration, atmospheric description, or third-person prose in this mode.</rule>';
        $lines[] = '  <rule>Ignore narration-style profile tendencies when they conflict with direct conversation.</rule>';
        $lines[] = '  <rule>Do not output action tags or command syntax.</rule>';
        $lines[] = '</conversation_rules>';
    }

    return implode("\n", $lines);
}

function buildSystemPrompt(
    string $npcName,
    array $npcData,
    string $playerName,
    string $playerMessage = '',
    bool $includeActionGuidance = true,
    string $eventType = 'chat',
    int $currentGamets = 0
): string {
    $narratorTarget = function_exists('stobeIsNarratorName')
        ? stobeIsNarratorName($npcName)
        : (strcasecmp(trim($npcName), 'The Narrator') === 0);
    if ($narratorTarget) {
        return stobeBuildNarratorSystemPrompt($npcData, $playerName, $playerMessage, $currentGamets, $eventType);
    }

    $template = loadPromptTemplate('prompt_chat.txt');
    $metadata = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
    $npcRace = trim(strval($npcData['race'] ?? ''));
    if ($npcRace === '') {
        $npcRace = 'Unknown';
    }
    $npcFactionIdentity = getNpcFactionIdentityFromProfile($npcData);
    $npcFaction = trim(strval($npcFactionIdentity['name'] ?? ''));
    if ($npcFaction === '') {
        $npcFaction = stobeFactionDisplayName(strval($npcData['faction'] ?? ''));
    }
    $npcFaction = stobeResolvePlayerFactionPromptDisplayName($npcFaction, $npcFactionIdentity);
    if ($npcFaction === '') {
        $npcFaction = 'Unknown';
    }
    $npcPersonality = trim(strval($npcData['personality'] ?? ''));
    if ($npcPersonality === '') {
        $npcPersonality = 'Pragmatic wasteland survivor.';
    }
    $npcBackstory = trim(strval($npcData['backstory'] ?? ''));
    if ($npcBackstory === '') {
        $npcBackstory = 'A drifter surviving the harsh world of Kenshi.';
    }
    $npcSpeechStyle = trim(strval($npcData['speechstyle'] ?? ''));
    if ($npcSpeechStyle === '') {
        $npcSpeechStyle = 'Direct and practical.';
    }
    $npcGoals = trim(strval($npcData['goals'] ?? ''));
    if ($npcGoals === '') {
        $npcGoals = 'Survive and adapt to the wasteland.';
    }
    $playerCatsRaw = trim(getConfOpt('PLAYER_CATS', '0'));
    if ($playerCatsRaw === '' || preg_match('/^-?\d+(?:\.\d+)?$/', $playerCatsRaw) !== 1) {
        $playerCatsRaw = '0';
    }
    $playerCats = strval(max(0, intval(floatval($playerCatsRaw))));
    $inPlayerFaction = npcIsInPlayerFaction($npcData);
    $npcEquipmentInventory = stobeBuildNpcEquipmentInventoryText($npcData, $metadata);
    $npcOccupation = trim(strval($npcData['occupation'] ?? ''));
    if ($npcOccupation !== '') {
        $npcOccupation = preg_replace('/\s*\[default[^\]]*factionsid\]\s*/iu', ' ', $npcOccupation) ?? $npcOccupation;
        $npcOccupation = trim(preg_replace('/\s+/u', ' ', $npcOccupation) ?? $npcOccupation);
        if ($inPlayerFaction) {
            $npcOccupation = stobeAliasPlayerFactionNameInText($npcOccupation, $npcFactionIdentity, true);
        }
    }
    if ($npcOccupation === '') {
        $npcOccupation = 'Wasteland drifter.';
    }
    if (!stobePromptContextOptionEnabled('enabled_character_subsections', 'basic_summary')) {
        $npcBackstory = '';
    }
    if (!stobePromptContextOptionEnabled('enabled_character_subsections', 'personality')) {
        $npcPersonality = '';
    }
    if (!stobePromptContextOptionEnabled('enabled_character_subsections', 'occupation')) {
        $npcOccupation = '';
    }
    if (!stobePromptContextOptionEnabled('enabled_character_subsections', 'speech_style')) {
        $npcSpeechStyle = '';
    }
    if (!stobePromptContextOptionEnabled('enabled_character_subsections', 'goals')) {
        $npcGoals = '';
    }
    $npcRelationships = stobeBuildNpcRelationshipsText($npcName, $playerName, $npcData);
    $npcAppearance = stobeBuildNpcAppearanceText($npcData);
    $npcBountyBlock = stobeBuildNpcBountyPromptBlock($npcData);
    $npcSkills = stobeBuildNpcSkillsText($npcData);
    $npcCondition = stobeBuildNpcConditionText($npcData, $metadata);
    $npcLatestDiary = stobeBuildLatestDiaryContextBlock($npcName, $npcData);
    $promptOverrides = stobeResolveNpcPromptOverrides($npcData, $metadata);
    $promptHeadOverride = trim(strval($promptOverrides['prompt_head'] ?? ''));
    $profilePromptOverride = trim(strval($promptOverrides['profile_prompt'] ?? ''));

    $roleplayInstructions = stobeBuildRoleplayInstructionsText($npcName, $playerName, $npcData);
    if ($promptHeadOverride !== '') {
        $roleplayInstructions = trim($promptHeadOverride . "\n\n" . $roleplayInstructions);
    }

    $generalInstructions = stobeBuildGeneralInstructionsText($npcData);
    if ($profilePromptOverride !== '') {
        $generalInstructions = trim($profilePromptOverride . "\n" . $generalInstructions);
    }

    $replacements = [
        '#ROLEPLAY_INSTRUCTIONS#' => stobePromptXmlEscape($roleplayInstructions),
        '#NPC_NAME#' => stobePromptXmlEscape($npcName),
        '#NPC_RACE#' => stobePromptXmlEscape($npcRace),
        '#NPC_FACTION#' => stobePromptXmlEscape($npcFaction),
        '#NPC_PERSONALITY#' => stobePromptXmlEscape($npcPersonality),
        '#NPC_BACKSTORY#' => stobePromptXmlEscape($npcBackstory),
        '#NPC_APPEARANCE#' => stobePromptXmlEscape($npcAppearance),
        '#NPC_EQUIPMENT#' => stobePromptXmlEscape(strval($npcEquipmentInventory['equipment'] ?? '')),
        '#NPC_INVENTORY#' => stobePromptXmlEscape(strval($npcEquipmentInventory['inventory'] ?? '')),
        '#NPC_CURRENT_CONDITION#' => stobePromptXmlEscape($npcCondition),
        '#NPC_RELATIONSHIPS#' => stobePromptXmlEscape($npcRelationships),
        '#NPC_OCCUPATION#' => stobePromptXmlEscape($npcOccupation),
        '#NPC_BOUNTY_BLOCK#' => $npcBountyBlock,
        '#NPC_SKILLS#' => $npcSkills,
        '#NPC_SPEECHSTYLE#' => stobePromptXmlEscape($npcSpeechStyle),
        '#NPC_GOALS#' => stobePromptXmlEscape($npcGoals),
        '#NPC_MIDDLE_TERM_MEMORY#' => stobePromptContextOptionEnabled('enabled_character_subsections', 'middle_term_memory')
            ? stobeBuildMiddleTermMemoryPromptBlock($npcData, $npcName)
            : '',
        '#NPC_LATEST_DIARY#' => $npcLatestDiary,
        '#PLAYER_NAME#' => stobePromptXmlEscape($playerName),
        '#PLAYER_CATS#' => stobePromptXmlEscape($playerCats),
        '#GENERAL_INSTRUCTIONS#' => stobePromptXmlEscape($generalInstructions),
    ];

    $prompt = str_replace(array_keys($replacements), array_values($replacements), $template);
    $worldStateBlock = buildWorldStateBlock($npcData);
    // Round 13: stable-first chat prompt so DeepInfra's prefix cache can reuse the head.
    // Character State and Relationships change every turn; they move (unchanged) into
    // <current_situation> after Available Actions instead of sitting inside <character>.
    $stableFirst = strtolower($eventType) === 'chat'
        && (!function_exists('getSettingBool') || getSettingBool('PROMPT_CACHE_STABLE_FIRST', true));
    if ($stableFirst) {
        $prompt = str_replace('#NPC_CHARACTER_STATE#', '', $prompt);
        $prompt = str_replace($npcSkills, '', $prompt);
        $liveSkills = trim($npcSkills);
        $liveDiary = trim($npcLatestDiary);
        if ($liveDiary !== '') {
            $prompt = str_replace($npcLatestDiary, '', $prompt);
        }
        $liveRelationships = '';
        if (preg_match('/<relationships>.*?<\/relationships>/s', $prompt, $relMatch, PREG_OFFSET_CAPTURE) === 1) {
            $liveRelationships = $relMatch[0][0];
            $prompt = substr_replace($prompt, '', $relMatch[0][1], strlen($liveRelationships));
        }
        if ($includeActionGuidance) {
            $prompt = appendStableActionReferenceToPrompt($prompt, $eventType);
        }
        $liveBlock = trim(trim($worldStateBlock) . "\n" . $liveSkills . "\n" . $liveRelationships);
        if ($liveBlock !== '') {
            $prompt .= "\n\n<current_situation>\n" . $liveBlock . "\n</current_situation>";
        }
        if ($includeActionGuidance) {
            $dynamicActionState = stobeBuildDynamicActionStateBlock($eventType, $npcData);
            if ($dynamicActionState !== '') {
                $prompt .= "\n\n" . $dynamicActionState;
            }
        }
        if ($liveDiary !== '') {
            $prompt .= "\n\n" . $liveDiary;
        }
    } elseif (strpos($prompt, '#NPC_CHARACTER_STATE#') !== false) {
        $prompt = str_replace('#NPC_CHARACTER_STATE#', $worldStateBlock, $prompt);
    } elseif ($worldStateBlock !== '') {
        $prompt .= "\n\n" . $worldStateBlock;
    }
    $playerBaseBlock = buildPlayerBaseStateBlock($npcData);
    if ($playerBaseBlock !== '') {
        $prompt .= "\n\n" . $playerBaseBlock;
    }
    $workGoalBlock = function_exists('stobeBuildWorkGoalStateBlock')
        ? stobeBuildWorkGoalStateBlock($npcName)
        : '';
    if ($workGoalBlock !== '') {
        $prompt .= "\n\n" . $workGoalBlock;
    }
    $taskGoalBlock = function_exists('stobeBuildTaskGoalStateBlock')
        ? stobeBuildTaskGoalStateBlock($npcName)
        : '';
    if ($taskGoalBlock !== '') {
        $prompt .= "\n\n" . $taskGoalBlock;
    }
    $prompt = stobePromptCleanupBaseTemplateBlocks($prompt);

    if ($inPlayerFaction && stobePromptContextOptionEnabled('enabled_sections', 'player_faction_funds')) {
        $prompt .= "\n\n<player_faction_funds>\n"
            . "  <cats>" . stobePromptXmlEscape($playerCats) . "</cats>\n"
            . "  <note>cats is the currently available shared funds for this character's player-side squad/faction.</note>\n"
            . "</player_faction_funds>";
    }

    $playerFactionPromptBlock = stobeBuildPlayerFactionPromptBlock($npcData);
    $knowledgeHints = [];
    $knowledgeEnabled = stobePromptContextOptionEnabled('enabled_sections', 'knowledge');
    if ($knowledgeEnabled && stobePromptContextOptionEnabled('enabled_knowledge_subsections', 'world_knowledge')) {
        $knowledgeLimit = max(1, min(6, getSettingInt('WORLD_KNOWLEDGE_AMOUNT', 2)));
        $seenKnowledgePayloads = [];
        stobeWorldKnowledgeAppendUniqueHints(
            $knowledgeHints,
            stobeWorldKnowledgeResolveForcedRaceHints($npcName, $npcData, $playerName, $eventType),
            $seenKnowledgePayloads
        );
        stobeWorldKnowledgeAppendUniqueHints(
            $knowledgeHints,
            stobeWorldKnowledgeResolveForcedLocationHints($npcName, $npcData, $eventType),
            $seenKnowledgePayloads
        );
        stobeWorldKnowledgeAppendUniqueHints(
            $knowledgeHints,
            stobeWorldKnowledgeResolveForcedPeopleHints($npcName, $npcData, $playerName, $eventType),
            $seenKnowledgePayloads
        );
        stobeWorldKnowledgeAppendUniqueHints(
            $knowledgeHints,
            queryWorldKnowledgeForNpc(
                $npcName,
                $playerMessage,
                $knowledgeLimit,
                $npcData,
                $eventType,
                $seenKnowledgePayloads
            ),
            $seenKnowledgePayloads
        );
    }

    if ($knowledgeEnabled && (count($knowledgeHints) > 0 || $playerFactionPromptBlock !== '')) {
        $prompt .= "\n\n<knowledge>";
        foreach ($knowledgeHints as $hint) {
            $prompt .= "\n  <entry>" . stobePromptXmlEscape($hint) . "</entry>";
        }
        if ($playerFactionPromptBlock !== '') {
            $prompt .= "\n  " . trim($playerFactionPromptBlock);
        }
        $prompt .= "\n</knowledge>";
    }

    if ($includeActionGuidance && !$stableFirst) {
        $prompt = appendActionGuidanceToPrompt($prompt, $eventType, $npcData);
    }

    $scenePromptBlock = stobeBuildScenePromptBlock($npcData, $npcName);
    if ($scenePromptBlock !== '') {
        $prompt .= "\n\n" . $scenePromptBlock;
    }

    $combatPriorityBlock = stobeBuildCombatPriorityPromptBlock($npcData, $npcName);
    if ($combatPriorityBlock !== '') {
        $prompt .= "\n\n" . $combatPriorityBlock;
    }
    if (strtolower($eventType) === 'chat' && stobeDealShouldNegotiate($npcName, $npcData, $playerMessage)) {
        $prompt .= "\n\n" . stobeDealPromptBlock($npcName, $npcData, $playerMessage);
    }
    if (strtolower($eventType) === 'chat' && function_exists('stobeRemovedClothingPromptBlock') && is_array($npcData)) {
        $removedClothing = stobeRemovedClothingPromptBlock($npcName, $npcData);
        if ($removedClothing !== '') {
            $prompt .= "\n\n" . $removedClothing;
        }
    }

    // Put volatile lifelike continuity context at the END so the large stable
    // character/system prefix remains cache-friendly for OpenRouter.
    if (function_exists('stobeBuildLifelikeNpcContextBlock')) {
        $lifelikeBlock = stobeBuildLifelikeNpcContextBlock(
            $npcName,
            $npcData,
            $playerName,
            $playerMessage,
            $currentGamets,
            $eventType
        );
        if ($lifelikeBlock !== '') {
            $prompt .= "\n\n" . $lifelikeBlock;
        }
    }

    return stobePromptCollapseBlankLines($prompt);
}

function buildAutochatRewriteSpeakerPrompt(
    string $speakerName,
    string $targetNpc,
    array $targetNpcData,
    string $sourceMessage
): string {
    $speakerNpcData = getNpcData($speakerName);
    if (is_array($speakerNpcData)) {
        return buildSystemPrompt($speakerName, $speakerNpcData, $targetNpc, $sourceMessage, false, 'chat');
    }

    $effectiveSpeaker = trim($speakerName);
    if ($effectiveSpeaker === '') {
        $effectiveSpeaker = getSetting('PLAYER_NAME', 'Drifter');
    }

    $playerBio = trim(strval(getSetting('PLAYER_BIOS', 'I am #PLAYER_NAME#, a wanderer in the wastelands of Kenshi.')));
    if ($playerBio !== '') {
        $playerBio = str_replace('#PLAYER_NAME#', $effectiveSpeaker, $playerBio);
    }

    $targetPrompt = buildSystemPrompt($targetNpc, $targetNpcData, $effectiveSpeaker, $sourceMessage, false, 'chat');

    $lines = [
        '<autochat_rewrite_context>',
        '  <speaker>' . stobePromptXmlEscape($effectiveSpeaker) . '</speaker>',
        '  <target>' . stobePromptXmlEscape($targetNpc) . '</target>',
    ];
    if ($playerBio !== '') {
        $lines[] = '  <speaker_background>' . stobePromptXmlEscape($playerBio) . '</speaker_background>';
    }
    $lines[] = '</autochat_rewrite_context>';
    $lines[] = '<target_context>';
    $lines[] = $targetPrompt;
    $lines[] = '</target_context>';

    return implode("\n", $lines);
}

function rewriteSpeakerMessageForAutochat(
    string $speakerName,
    string $targetNpc,
    array $targetNpcData,
    string $sourceMessage,
    string $historyText = ''
): array {
    $original = trim($sourceMessage);
    if (function_exists('stobeSanitizeDialogueMessageForLog')) {
        $original = stobeSanitizeDialogueMessageForLog($original);
    }
    if ($original === '') {
        return [
            'message' => $sourceMessage,
            'rewritten' => false,
            'error' => 'empty_source',
            'model' => '',
        ];
    }

    $llmConfig = getLlmConfigForNpcPurpose($targetNpcData, 'autochat');
    if (trim(strval($llmConfig['api_key'] ?? '')) === '') {
        return [
            'message' => $sourceMessage,
            'rewritten' => false,
            'error' => 'missing_api_key',
            'model' => strval($llmConfig['model'] ?? ''),
        ];
    }

    $speakerPrompt = buildAutochatRewriteSpeakerPrompt(
        $speakerName,
        $targetNpc,
        $targetNpcData,
        $original
    );

    $systemPrompt = $speakerPrompt
        . "\n\n<autochat_rewrite_mode>\n"
        . "  <rule>Rewrite the source line into an in-character Kenshi dialogue reply for the speaker.</rule>\n"
        . "  <rule>Preserve the intent and core meaning of the source line.</rule>\n"
        . "  <rule>Keep it concise (1-2 sentences).</rule>\n"
        . "  <rule>Do not add stage directions or action tags.</rule>\n"
        . "  <rule>Do not include a speaker prefix like \"Name:\".</rule>\n"
        . "  <rule>Output dialogue text only.</rule>\n"
        . "</autochat_rewrite_mode>";

    $messages = [
        [
            'role' => 'system',
            'content' => $systemPrompt,
        ],
    ];
    if (trim($historyText) !== '') {
        $historyMessages = stobeBuildRecentContextMessagesFromText($historyText, 24, $speakerName);
        foreach ($historyMessages as $historyMessage) {
            $messages[] = $historyMessage;
        }
    }
    $messages[] = [
        'role' => 'user',
        'content' => "<source_line>\n"
            . "  <speaker>" . stobePromptXmlEscape($speakerName) . "</speaker>\n"
            . "  <text>" . stobePromptXmlEscape($original) . "</text>\n"
            . "</source_line>",
    ];
    $messages[] = [
        'role' => 'user',
        'content' => '<output_format><requirement>Return only the rewritten dialogue line.</requirement></output_format>',
    ];

    $raw = stobeCallLLM($messages, $llmConfig, [
        'npc_name' => $targetNpc,
        'event_type' => 'autochat_rewrite',
        'speaker' => $speakerName,
    ]);
    if ($raw === false || trim(strval($raw)) === '') {
        return [
            'message' => $sourceMessage,
            'rewritten' => false,
            'error' => 'empty_rewrite_response',
            'model' => strval($llmConfig['model'] ?? ''),
        ];
    }

    $rewritten = sanitizeForKenshi(trim(strval($raw)));
    $trimmedSpeaker = trim($speakerName);
    if ($trimmedSpeaker !== '') {
        $prefixPattern = '/^\s*' . preg_quote($trimmedSpeaker, '/') . '\s*:\s*/iu';
        $rewritten = preg_replace($prefixPattern, '', $rewritten) ?? $rewritten;
    }
    $rewritten = trim($rewritten, " \t\n\r\0\x0B\"'");
    if (function_exists('stobeSanitizeDialogueMessageForLog')) {
        $rewritten = stobeSanitizeDialogueMessageForLog($rewritten);
    }

    if ($rewritten === '') {
        return [
            'message' => $sourceMessage,
            'rewritten' => false,
            'error' => 'rewrite_empty_after_sanitize',
            'model' => strval($llmConfig['model'] ?? ''),
        ];
    }

    return [
        'message' => $rewritten,
        'rewritten' => strcasecmp($rewritten, $original) !== 0,
        'error' => '',
        'model' => strval($llmConfig['model'] ?? ''),
    ];
}

function extractDialogueTarget(string $source): array {
    $text = trim($source);
    if ($text === '') {
        return ['target' => '', 'cleaned' => ''];
    }

    if (preg_match('/\(talking to:\s*([^\)]+)\)/i', $text, $matches) === 1) {
        $target = trim(strval($matches[1] ?? ''));
        $cleaned = trim(preg_replace('/\s*\(talking to:\s*[^\)]+\)\s*/i', ' ', $text) ?? '');
        return [
            'target' => stobeNormalizeDialogueTargetToken($target),
            'cleaned' => $cleaned,
        ];
    }

    return ['target' => '', 'cleaned' => $text];
}

function parseDialogueEventData(string $eventData): array {
    $targetExtract = extractDialogueTarget($eventData);
    $cleaned = trim(strval($targetExtract['cleaned'] ?? ''));
    $target = stobeNormalizeDialogueTargetToken(strval($targetExtract['target'] ?? ''));

    $speaker = '';
    $message = $cleaned;
    $parts = explode(': ', $cleaned, 2);
    if (count($parts) === 2) {
        $speaker = normalizeParticipantNameToken(strval($parts[0] ?? ''));
        $message = trim(strval($parts[1] ?? ''));
    }

    if ($speaker === '') {
        $colonPos = strpos($cleaned, ':');
        if ($colonPos !== false && $colonPos > 0) {
            $speaker = normalizeParticipantNameToken(substr($cleaned, 0, $colonPos));
            $message = trim(substr($cleaned, $colonPos + 1));
        }
    }

    return [
        'speaker' => $speaker,
        'message' => trim($message),
        'target' => $target,
        'cleaned' => $cleaned,
    ];
}

function buildActionEventData(string $actor, string $actionToken, string $target = '', string $source = ''): string {
    $safeActor = normalizeParticipantNameToken($actor);
    if ($safeActor === '') {
        $safeActor = 'Unknown';
    }

    $safeAction = trim(strval($actionToken));
    return $safeActor . ': ' . $safeAction;
}

function stobeActionRequiresVerifiedWorldOutcome(string $actionToken): bool {
    $token = trim($actionToken);
    if ($token === '') {
        return false;
    }
    $at = strpos($token, '@');
    $command = $at === false ? $token : substr($token, 0, $at);
    $command = stobeCanonicalizeActionCommand($command);
    return in_array($command, [
        'UNEQUIP_ITEM', 'EQUIP_ITEM', 'SHEATHE_WEAPON', 'DRAW_WEAPON',
        'FACE_TARGET', 'FIRST_AID', 'BODYGUARD', 'PATROL', 'RESCUE',
        'PUT_IN_BED', 'LOOT_TARGET', 'IMPRISON', 'RELEASE_PRISONER',
        'DROP_WEAPON', 'SURRENDER', 'MOVE_TO_TARGET', 'HOLD_POSITION',
        'REPAIR', 'BUILD',
    ], true);
}

function storeActionEvents(
    string $actor,
    array $actions,
    int $gamets,
    string $target = '',
    string $sourceEventType = 'chat'
): int {
    if (count($actions) === 0) {
        return 0;
    }

    $source = strtolower(trim($sourceEventType));
    if ($source === '') {
        $source = 'chat';
    }
    $actorData = getNpcData($actor);
    $config = stobeBuildActionConfigForNpc($source, $actorData);

    $stored = 0;
    $seen = [];
    foreach ($actions as $rawAction) {
        $normalized = normalizeActionTagToken(strval($rawAction), $config);
        if ($normalized === '') {
            continue;
        }
        $dispatchAction = stobeTransformActionForDispatch($normalized, $actorData);
        if ($dispatchAction === '') {
            continue;
        }
        $key = strtolower($dispatchAction);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        if (stobeActionRequiresVerifiedWorldOutcome($dispatchAction)) {
            stobeLogInfo('Deferred action-history write until world outcome is verified', [
                'actor' => $actor,
                'action' => $dispatchAction,
                'target' => $target,
                'source_event_type' => $source,
            ]);
            continue;
        }

        $actionEventData = buildActionEventData($actor, $dispatchAction, $target, $source);
        storeEvent('action', time(), $gamets, $actionEventData);
        $stored++;
    }

    if ($stored > 0) {
        stobeLogInfo('Action events stored', [
            'actor' => $actor,
            'target' => $target,
            'source_event_type' => $source,
            'stored' => $stored,
        ]);
    }

    return $stored;
}

function buildRechatSystemPrompt(
    string $npcName,
    array $npcData,
    string $previousSpeaker,
    string $previousMessage,
    string $previousTarget = '',
    int $currentGamets = 0,
    array $specialContext = [],
    string $strictListener = ''
): string {
    $speakerForBasePrompt = $previousSpeaker !== '' ? $previousSpeaker : getSetting('PLAYER_NAME', 'Drifter');
    $prompt = buildSystemPrompt($npcName, $npcData, $speakerForBasePrompt, $previousMessage, true, 'rechat', $currentGamets);
    $xml = [];

    $specialMode = strtolower(trim(strval($specialContext['mode'] ?? '')));
    if ($specialMode === 'limb_loss_reaction') {
        $victim = normalizeParticipantNameToken(strval($specialContext['victim'] ?? $npcName));
        if ($victim === '') {
            $victim = $npcName;
        }
        $limb = stobeNormalizeLimbLossLabel(strval($specialContext['limb'] ?? ''));
        if ($limb === '') {
            $limb = 'a limb';
        }
        $attacker = normalizeParticipantNameToken(strval($specialContext['attacker'] ?? ''));
        $hacksawContext = boolval($specialContext['hacksaw'] ?? false);

        $injuryRule = $hacksawContext
            ? "State clearly that {$limb} is cut off by a hacksaw."
            : "State clearly that {$limb} has just been severed.";

        $xml[] = '<limb_loss_reaction>';
        $xml[] = '  <rule>This is an immediate one-turn trauma reaction after limb loss.</rule>';
        $xml[] = '  <rule>You are the injured victim and must react before normal dialogue resumes.</rule>';
        $xml[] = '  <rule>Speak in screams, broken words, and gargled pain sounds.</rule>';
        $xml[] = '  <rule>' . stobePromptXmlEscape($injuryRule) . '</rule>';
        if ($attacker !== '') {
            $xml[] = '  <rule>Mention that ' . stobePromptXmlEscape($attacker) . ' caused it.</rule>';
        }
        $xml[] = '  <rule>Keep it concise and fully in-character for Kenshi.</rule>';
        $xml[] = '</limb_loss_reaction>';
        $xml[] = '<limb_loss_meta>';
        $xml[] = '  <victim>' . stobePromptXmlEscape($victim) . '</victim>';
        $xml[] = '  <limb>' . stobePromptXmlEscape($limb) . '</limb>';
        $xml[] = '  <hacksaw>' . ($hacksawContext ? 'true' : 'false') . '</hacksaw>';
        $xml[] = '</limb_loss_meta>';
    }

    if (count($xml) === 0) {
        return $prompt;
    }

    return $prompt . "\n\n" . implode("\n", $xml);
}

function stobeGenerateUtteranceId(): string {
    $prefix = 'utt_';
    try {
        return $prefix . bin2hex(random_bytes(12));
    } catch (Throwable $exception) {
        return $prefix . md5(uniqid('stobe', true) . '|' . microtime(true) . '|' . mt_rand());
    }
}

function stobeRegisterGeneratedSpeechChunk(
    string $actor,
    string $action,
    string $message,
    string $utteranceId,
    string $eventType = '',
    string $listener = ''
): void {
    if (strcasecmp(trim($action), 'ScriptQueue') !== 0) {
        return;
    }
    $safeActor = trim($actor);
    $safeMessage = trim($message);
    $safeUtteranceId = trim($utteranceId);
    if ($safeActor === '' || $safeMessage === '' || $safeUtteranceId === '') {
        return;
    }
    $safeEventType = strtolower(trim($eventType));
    $safeListener = normalizeParticipantNameToken($listener);
    if ($safeListener === '') {
        $safeListener = trim($listener);
    }

    if (!isset($GLOBALS['__stobe_generated_speech_chunks']) || !is_array($GLOBALS['__stobe_generated_speech_chunks'])) {
        $GLOBALS['__stobe_generated_speech_chunks'] = [];
    }
    $GLOBALS['__stobe_generated_speech_chunks'][] = [
        'actor' => $safeActor,
        'action' => $action,
        'message' => $safeMessage,
        'utterance_id' => $safeUtteranceId,
        'event_type' => $safeEventType,
        'listener' => $safeListener,
    ];
}

function stobeMarkGeneratedSpeechChunkCursor(): int {
    $chunks = $GLOBALS['__stobe_generated_speech_chunks'] ?? [];
    if (!is_array($chunks)) {
        $chunks = [];
        $GLOBALS['__stobe_generated_speech_chunks'] = $chunks;
    }
    return count($chunks);
}

function stobeTakeGeneratedSpeechChunksSince(int $cursor): array {
    $chunks = $GLOBALS['__stobe_generated_speech_chunks'] ?? [];
    if (!is_array($chunks)) {
        $chunks = [];
    }

    $safeCursor = max(0, intval($cursor));
    if ($safeCursor >= count($chunks)) {
        return [];
    }

    $taken = array_slice($chunks, $safeCursor);
    $GLOBALS['__stobe_generated_speech_chunks'] = array_slice($chunks, 0, $safeCursor);
    return array_values($taken);
}

function stobeTakeGeneratedSpeechChunks(): array {
    return stobeTakeGeneratedSpeechChunksSince(0);
}

function formatResponse(
    string $actor,
    string $action,
    string $message,
    string $ttsHash = '',
    int $ttsDurationMs = 0,
    string $utteranceId = ''
): string {
    stobeInteractionRequire();
    $metadata = [];
    if ($utteranceId !== '') {
        $metadata[] = 'uid=' . $utteranceId;
    }
    if ($ttsHash !== '') {
        $metadata[] = 'tts=' . $ttsHash;
    }
    if ($ttsDurationMs > 0) {
        $metadata[] = 'ttsd=' . $ttsDurationMs;
    }
    if (count($metadata) > 0) {
        return "{$actor}|{$action}|{$message}|" . implode('|', $metadata) . "\r\n";
    }
    return "{$actor}|{$action}|{$message}\r\n";
}

function stobeGetOutputToPluginLogPath(): string {
    if (function_exists('stobeGetLogPath')) {
        return stobeGetLogPath('output_to_plugin.log');
    }
    $enginePath = $GLOBALS['ENGINE_PATH'] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
    $logDir = rtrim($enginePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR;
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    return $logDir . 'output_to_plugin.log';
}

function stobeLogOutputToPlugin(
    string $actor,
    string $action,
    string $message,
    string $wirePayload,
    string $ttsHash = '',
    int $ttsDurationMs = 0,
    string $utteranceId = ''
): void {
    $requestElapsedMs = isset($GLOBALS['__stobe_request_start']) && is_float($GLOBALS['__stobe_request_start'])
        ? intval(round((microtime(true) - $GLOBALS['__stobe_request_start']) * 1000))
        : 0;
    $entry = [
        'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
        'request_elapsed_ms' => $requestElapsedMs,
        'unix_ms' => intval(round(microtime(true) * 1000)),
        'stage' => 'output_queued_to_plugin',
        'actor' => $actor,
        'action' => $action,
        'message' => $message,
        'utterance_id' => $utteranceId,
        'tts_hash' => $ttsHash,
        'tts_duration_ms' => $ttsDurationMs,
        'shadow_enabled' => function_exists('stobeShadowEnabled') && stobeShadowEnabled(),
        'wire_payload' => trim($wirePayload),
    ];
    $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if (!is_string($json) || $json === '') {
        return;
    }
    $line = '[' . gmdate('Y-m-d H:i:s') . '] [INFO] output_to_plugin | ' . $json . PHP_EOL;
    @file_put_contents(stobeGetOutputToPluginLogPath(), $line, FILE_APPEND | LOCK_EX);
    if ($ttsDurationMs > 0 && function_exists('stobeTrainingCapture')) {
        stobeTrainingCapture('tts_output', $entry);
    }
}

function stobeIsTtsEnabledForCurrentRequest(): bool {
    static $resolved = null;
    if ($resolved !== null) {
        return boolval($resolved);
    }

    $requestRaw = $_GET['tts_enabled'] ?? $_POST['tts_enabled'] ?? null;
    if ($requestRaw === null) {
        $resolved = true;
        return boolval($resolved);
    }

    $normalized = strtolower(trim(strval($requestRaw)));
    if ($normalized === '') {
        $resolved = true;
        return boolval($resolved);
    }

    $requestEnabled = !in_array($normalized, ['0', 'false', 'off', 'no', 'disabled'], true);
    $resolved = $requestEnabled;
    return boolval($resolved);
}

function stobeGetStreamSentenceMaxSize(): int {
    if (defined('MAXIMUM_SENTENCE_SIZE')) {
        return max(32, intval(MAXIMUM_SENTENCE_SIZE));
    }
    return 125;
}

function stobeGetStreamSentenceMinSize(): int {
    if (defined('MINIMUM_SENTENCE_SIZE')) {
        return max(20, intval(MINIMUM_SENTENCE_SIZE));
    }
    return 75;
}

function stobeStreamSentenceSplitRegex(): string {
    // Includes Latin and CJK sentence punctuation.
    return '/(?<=[.?!。？！])(?<!\.\.)(?<!\.\.\.)\s+/u';
}

function stobeFindFastSentencePosition(string $text): int|false {
    if ($text === '') {
        return false;
    }
    // A completed sentence can be synthesized before the model emits the next space.
    // Keep decimal points and ellipses inside the buffer.
    if (preg_match('/(?<![.\d])([.?!。？！])(?=\s|$)/u', $text, $matches, PREG_OFFSET_CAPTURE) === 1) {
        return intval($matches[1][1] ?? -1);
    }
    // If the NPC starts with a long sentence, release a natural clause before
    // waiting for the final period. Avoid tiny or mid-word speech fragments.
    if (strlen($text) >= 100 && preg_match_all('/[,;:]\s+/u', substr($text, 0, 105), $cuts, PREG_OFFSET_CAPTURE)) {
        foreach (array_reverse($cuts[0]) as $cut) {
            if ($cut[1] >= 55) return intval($cut[1]);
        }
    }
    if (strlen($text) >= 125) {
        $space = strrpos(substr($text, 0, 105), ' ');
        if ($space !== false && $space >= 75) return $space - 1;
    }
    return false;
}

function stobeSplitLongStreamChunk(string $text, int $maxSize): array {
    $remaining = trim(strval($text));
    if ($remaining === '') {
        return [];
    }
    if ($maxSize < 32) {
        $maxSize = 32;
    }
    if (strlen($remaining) <= $maxSize) {
        return [$remaining];
    }

    $chunks = [];
    while (strlen($remaining) > $maxSize) {
        $window = substr($remaining, 0, $maxSize + 1);
        $bestCut = false;

        $cutTokens = ['. ', '? ', '! ', '; ', ', ', ': ', ' '];
        foreach ($cutTokens as $token) {
            $tokenPos = strrpos($window, $token);
            if ($tokenPos === false) {
                continue;
            }
            $candidateCut = $tokenPos + ($token === ' ' ? 0 : 1);
            if ($candidateCut <= 0) {
                continue;
            }
            if ($bestCut === false || $candidateCut > $bestCut) {
                $bestCut = $candidateCut;
            }
        }

        if ($bestCut === false || $bestCut < intval($maxSize * 0.5)) {
            $bestCut = $maxSize;
        }
        if ($bestCut <= 0) {
            $bestCut = $maxSize;
        }

        $chunk = trim(substr($remaining, 0, $bestCut));
        if ($chunk === '') {
            $chunk = trim(substr($remaining, 0, $maxSize));
            $bestCut = strlen($chunk);
        }
        if ($chunk === '') {
            break;
        }

        $chunks[] = $chunk;
        $remaining = ltrim(substr($remaining, $bestCut));
    }

    if ($remaining !== '') {
        $chunks[] = trim($remaining);
    }

    return $chunks;
}

function stobeSplitSentencesStream(string $paragraph): array {
    $text = trim(strval($paragraph));
    if ($text === '') {
        return [];
    }

    $maxSize = stobeGetStreamSentenceMaxSize();
    $minSize = stobeGetStreamSentenceMinSize();
    if (strlen($text) <= $maxSize) {
        return [$text];
    }

    $sentences = preg_split(stobeStreamSentenceSplitRegex(), $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($sentences) || count($sentences) === 0) {
        return stobeSplitLongStreamChunk($text, $maxSize);
    }

    $chunks = [];
    $current = '';
    foreach ($sentences as $sentenceRaw) {
        $sentence = trim(strval($sentenceRaw));
        if ($sentence === '') {
            continue;
        }
        if ($current === '') {
            $current = $sentence;
            continue;
        }
        $combined = $current . ' ' . $sentence;
        if (strlen($combined) > $maxSize) {
            $chunks[] = $current;
            $current = $sentence;
            continue;
        }
        $current = $combined;
        if (strlen($current) >= $minSize) {
            $chunks[] = $current;
            $current = '';
        }
    }

    if ($current !== '') {
        $chunks[] = $current;
    }

    if (count($chunks) === 0) {
        return stobeSplitLongStreamChunk($text, $maxSize);
    }

    $normalizedChunks = [];
    foreach ($chunks as $chunkRaw) {
        $subChunks = stobeSplitLongStreamChunk(strval($chunkRaw), $maxSize);
        if (count($subChunks) === 0) {
            continue;
        }
        foreach ($subChunks as $subChunk) {
            $subChunkText = trim(strval($subChunk));
            if ($subChunkText !== '') {
                $normalizedChunks[] = $subChunkText;
            }
        }
    }

    return count($normalizedChunks) > 0 ? $normalizedChunks : [$text];
}

function stobeStripParentheticalDialogueText(string $text): string {
    $clean = sanitizeForKenshi(trim(strval($text)));
    if ($clean === '') {
        return '';
    }

    // Remove parenthetical stage directions, including repeated nested groups.
    $previous = null;
    while ($previous !== $clean) {
        $previous = $clean;
        $clean = preg_replace('/\([^()]*\)/u', ' ', $clean) ?? $clean;
    }

    // Remove XML/HTML-like metadata tags that should never be spoken.
    $previous = null;
    while ($previous !== $clean) {
        $previous = $clean;
        $clean = preg_replace('/<([a-z][a-z0-9:_-]*)(?:\s[^<>]*)?>[\s\S]*?<\/\1>/iu', ' ', $clean) ?? $clean;
        $clean = preg_replace('/<\/?[a-z][^>]*>/iu', ' ', $clean) ?? $clean;
        $clean = preg_replace('/<[^>\r\n]{1,240}>/u', ' ', $clean) ?? $clean;
    }

    // Remove leaked inline action tags (e.g. FACTION_RELATIONS@Target@100).
    $commandNames = [
        'ATTACK', 'STOP_ATTACK', 'FOLLOW', 'STOP_FOLLOW', 'JOIN_PARTY',
        'LEAVE', 'IDLE', 'STOP_CARRYING', 'PICKUP_NPC', 'RELEASE_PLAYER', 'RELEASE_PRISONER', 'SUICIDE',
        'GIVE_CATS', 'TAKE_CATS', 'TAKE_ITEM', 'GIVE_ITEM', 'DROP_ITEM', 'UNEQUIP_ITEM', 'EQUIP_ITEM', 'SHEATHE_WEAPON', 'DRAW_WEAPON', 'FACE_TARGET', 'FIRST_AID', 'BODYGUARD', 'PATROL', 'RESCUE', 'PUT_IN_BED', 'LOOT_TARGET', 'IMPRISON', 'RELEASE_PRISONER', 'DROP_WEAPON', 'SURRENDER', 'MOVE_TO_TARGET', 'HOLD_POSITION', 'REPAIR', 'BUILD', 'WORK_GOAL', 'TASK_GOAL', 'TASK_CONTROL', 'SIT', 'SLEEP', 'OPERATE_OBJECT', 'REMOVE_LIMB', 'KNOCKOUT', 'KILL', 'USE_OBJECT', 'USE_DRUGS', 'DRINK_ITEM', 'DRINK', 'FORCE_DRINK', 'TRAVEL_LOCATION', 'MOVE_TO', 'MOVETO',
        'ROLEPLAY_ACTION', 'NOTIFY', 'FACTION_RELATIONS', 'TASK', 'TALK',
        'SET_BLOCK', 'SET_HOLD', 'SET_PASSIVE', 'SET_JOBS', 'SET_RANGED',
        'SET_TAUNT', 'SET_SNEAK', 'SET_RESOURCE', 'SET_MEDIC',
        'STOPATTACK', 'STOPFOLLOW', 'JOINPARTY', 'STOPCARRYING', 'DROPNPC', 'DROP_NPC', 'DROP-NPC', 'PUTDOWNNPC', 'PUT_DOWN_NPC', 'PUT-DOWN-NPC', 'RELEASENPC', 'RELEASE_NPC', 'RELEASE-NPC', 'PICKUPNPC', 'PICKUP-NPC', 'KIDNAP', 'RELEASEPLAYER', 'GIVECATS', 'TAKECATS',
        'TAKEITEM', 'GIVEITEM', 'DROPITEM', 'UNEQUIPITEM', 'UNEQUIP-ITEM', 'TAKEOFFITEM', 'TAKE_OFF_ITEM', 'TAKE-OFF-ITEM', 'WORKGOAL', 'WORK-GOAL', 'PRODUCEGOAL', 'PRODUCE_GOAL', 'CRAFTGOAL', 'CRAFT_GOAL', 'EQUIPITEM', 'EQUIP-ITEM', 'SHEATHEWEAPON', 'SHEATHE-WEAPON', 'HOLSTERWEAPON', 'DRAWWEAPON', 'DRAW-WEAPON', 'READYWEAPON', 'FACETARGET', 'FACE-TARGET', 'LOOKAT', 'LOOK_AT', 'FIRSTAID', 'FIRST-AID', 'HEALTARGET', 'HEAL_TARGET', 'GUARDTARGET', 'GUARD_TARGET', 'BODYGUARDTARGET', 'PUTINBED', 'PUT-IN-BED', 'LOOTTARGET', 'LOOT-TARGET', 'RELEASEPRISONER', 'RELEASE-PRISONER', 'DROPWEAPON', 'DROP-WEAPON', 'DISARM', 'DISARMSELF', 'DISARM_SELF', 'MOVETOTARGET', 'MOVE-TO-TARGET', 'GOTOACTOR', 'HOLDPOSITION', 'HOLD-POSITION', 'TAKEASEAT', 'TAKE_A_SEAT', 'GOTOSLEEP', 'GO_TO_SLEEP', 'OPERATEOBJECT', 'OPERATE-OBJECT', 'WORKOBJECT', 'REMOVELIMB', 'KO', 'KNOCK_OUT', 'KNOCK-OUT', 'KILLTARGET', 'EXECUTE', 'MURDER', 'USEOBJECT', 'USE-OBJECT', 'USEDRUGS', 'USE-DRUGS', 'DRINKITEM', 'DRINK-ITEM', 'FORCEDRINK', 'FORCE-DRINK', 'FACTIONRELATIONS', 'TRAVELLOCATION',
        'ROLEPLAYACTION', 'ROLEPLAY-ACTION',
        'SETBLOCK', 'SETHOLD', 'SETPASSIVE', 'SETJOBS', 'SETRANGED',
        'SETTAUNT', 'SETSNEAK', 'SETRESOURCE', 'SETMEDIC',
    ];
    $commandAlternation = implode('|', $commandNames);
    $clean = preg_replace('/(?:^|[\s>])(?:' . $commandAlternation . ')\s*@[^\r\n<]*/iu', ' ', $clean) ?? $clean;

    $clean = preg_replace('/\s{2,}/u', ' ', $clean) ?? $clean;
    $clean = preg_replace('/\s+([,.;:!?])/u', '$1', $clean) ?? $clean;
    return trim($clean);
}

function stobeDedupeActionList(array $actions, string $eventType, ?array $configOverride = null): array {
    $config = is_array($configOverride) ? $configOverride : getActionRuntimeConfig($eventType);
    $normalized = [];
    $seen = [];
    foreach ($actions as $rawAction) {
        $parsed = normalizeActionTagToken(strval($rawAction), $config);
        if ($parsed === '') {
            continue;
        }
        $key = strtolower($parsed);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $normalized[] = $parsed;
    }
    return $normalized;
}

if (!function_exists('stobeResolveDialogueListenerTarget')) {
function stobeResolveDialogueListenerTarget(string $listener, array $allowedNames, string $fallback = ''): string
{
        $listenerName = normalizeParticipantNameToken($listener);
        $fallbackName = normalizeParticipantNameToken($fallback);

        $allowedByName = [];
        $allowedByBaseName = [];
        foreach ($allowedNames as $entry) {
            $candidateRaw = '';
            if (is_array($entry)) {
                $candidateRaw = strval($entry['name'] ?? ($entry['target'] ?? ($entry['listener'] ?? '')));
            } elseif (is_string($entry)) {
                $candidateRaw = $entry;
            }
            $candidate = normalizeParticipantNameToken($candidateRaw);
            if ($candidate === '') {
                continue;
            }

            $nameKey = strtolower($candidate);
            if (!isset($allowedByName[$nameKey])) {
                $allowedByName[$nameKey] = $candidate;
            }

            $baseName = strtolower(baseNameWithoutBracketSuffix($candidate));
            if ($baseName !== '' && !isset($allowedByBaseName[$baseName])) {
                $allowedByBaseName[$baseName] = $candidate;
            }
        }

        if ($listenerName !== '') {
            $listenerKey = strtolower($listenerName);
            if (isset($allowedByName[$listenerKey])) {
                return $allowedByName[$listenerKey];
            }

            $listenerBase = strtolower(baseNameWithoutBracketSuffix($listenerName));
            if ($listenerBase !== '' && isset($allowedByBaseName[$listenerBase])) {
                return $allowedByBaseName[$listenerBase];
            }
        }

        if ($fallbackName !== '') {
            $fallbackKey = strtolower($fallbackName);
            if (isset($allowedByName[$fallbackKey])) {
                return $allowedByName[$fallbackKey];
            }

            $fallbackBase = strtolower(baseNameWithoutBracketSuffix($fallbackName));
            if ($fallbackBase !== '' && isset($allowedByBaseName[$fallbackBase])) {
                return $allowedByBaseName[$fallbackBase];
            }

            return $fallbackName;
        }

        return '';
    }
}

function stobeResolveRechatReplyTarget(
    string $responseListener,
    array $allowedNames,
    string $defaultReplyTarget = '',
    string $strictReplyTarget = '',
    string $speakerName = ''
): string {
    $safeStrictReplyTarget = normalizeParticipantNameToken($strictReplyTarget);
    if ($safeStrictReplyTarget !== '') {
        $resolvedStrictTarget = stobeResolveDialogueListenerTarget(
            $safeStrictReplyTarget,
            $allowedNames,
            $safeStrictReplyTarget
        );
        return $resolvedStrictTarget !== '' ? $resolvedStrictTarget : $safeStrictReplyTarget;
    }

    $safeDefaultReplyTarget = normalizeParticipantNameToken($defaultReplyTarget);
    $replyTarget = stobeResolveDialogueListenerTarget(
        $responseListener,
        $allowedNames,
        $safeDefaultReplyTarget
    );
    if ($replyTarget === '') {
        $replyTarget = $safeDefaultReplyTarget;
    }

    $safeSpeakerName = normalizeParticipantNameToken($speakerName);
    if (
        $replyTarget !== ''
        && $safeSpeakerName !== ''
        && strcasecmp($replyTarget, $safeSpeakerName) === 0
        && $safeDefaultReplyTarget !== ''
        && strcasecmp($safeDefaultReplyTarget, $safeSpeakerName) !== 0
    ) {
        $replyTarget = stobeResolveDialogueListenerTarget(
            $safeDefaultReplyTarget,
            $allowedNames,
            $safeDefaultReplyTarget
        );
    }

    return $replyTarget;
}

function stobeComputeStructuredStreamMessageDelta(string $previousMessage, string $currentMessage): string {
    if ($currentMessage === '' || $currentMessage === $previousMessage) {
        return '';
    }
    if ($previousMessage === '') {
        return $currentMessage;
    }
    if (strpos($currentMessage, $previousMessage) === 0) {
        return substr($currentMessage, strlen($previousMessage));
    }

    $maxPrefix = min(strlen($previousMessage), strlen($currentMessage));
    $commonPrefixLength = 0;
    while (
        $commonPrefixLength < $maxPrefix
        && $previousMessage[$commonPrefixLength] === $currentMessage[$commonPrefixLength]
    ) {
        $commonPrefixLength++;
    }

    if ($commonPrefixLength <= 0 || $commonPrefixLength < intval(strlen($previousMessage) / 2)) {
        return '';
    }

    return substr($currentMessage, $commonPrefixLength);
}

function stobeGetInlineNarrationMode(): string
{
    $mode = '';
    try {
        $mode = strtolower(trim(strval((new Narrator())->get('inline_narration_mode') ?? 'disabled')));
    } catch (Throwable) {
        $mode = strtolower(trim(strval($GLOBALS['INLINE_NARRATION_MODE'] ?? 'disabled')));
    }
    return in_array($mode, ['disabled', 'narrator', 'npc', 'text_only'], true)
        ? $mode
        : 'disabled';
}

function stobeInlineNarrationApplies(string $actor, string $eventType = ''): bool
{
    if (stobeGetInlineNarrationMode() === 'disabled') {
        return false;
    }
    if (function_exists('stobeIsNarratorName') && stobeIsNarratorName($actor)) {
        return false;
    }
    return !in_array(
        strtolower(trim($eventType)),
        ['narration', 'narrator_welcome', 'inline_narration', 'diary', 'diary_narrator'],
        true
    );
}

/**
 * Split one or more leading *narration* blocks from the spoken dialogue.
 */
function stobeExtractInlineNarrationParts(string $text): array
{
    $remaining = trim(sanitizeForKenshi($text));
    $narrations = [];
    while ($remaining !== '' && preg_match('/^\*([^*]+)\*\s*(.*)$/su', $remaining, $match) === 1) {
        $narration = trim(strval($match[1] ?? ''));
        if ($narration !== '') {
            $narrations[] = $narration;
        }
        $next = trim(strval($match[2] ?? ''));
        if ($next === $remaining) {
            break;
        }
        $remaining = $next;
    }

    // Recover the common malformed form "*scene sentence. Spoken dialogue*".
    if (count($narrations) === 1 && $remaining === '' && str_starts_with(trim($text), '*')) {
        $wrapped = $narrations[0];
        if (preg_match('/^(.+?[.!?])\s+(.+)$/su', $wrapped, $match) === 1) {
            $dialogueLead = trim(strval($match[2] ?? ''));
            if (preg_match('/^(?:I|We|You|Yes|No|Indeed|Maybe|Perhaps|Come|Let|Do|Don\'t|Can|Could|Should|Would|This|That|There)\b/iu', $dialogueLead) === 1) {
                $narrations = [trim(strval($match[1] ?? ''))];
                $remaining = $dialogueLead;
            }
        }
    }

    return [
        'narrations' => $narrations,
        'dialogue' => $remaining,
        'has_narration' => count($narrations) > 0,
    ];
}

function stobeInlineNarrationPromptMessages(
    array $messages,
    string $actor,
    string $eventType
): array {
    if (!stobeInlineNarrationApplies($actor, $eventType)) {
        return $messages;
    }
    $fallback = 'Begin each reply with one brief third-person scene description in single asterisks, followed by spoken dialogue outside the asterisks. Example: *She glances toward the gate.* We should leave. Never wrap the entire reply in asterisks.';
    $instruction = function_exists('stobeGetPromptTemplateValue')
        ? stobeGetPromptTemplateValue('inline_narration_prompt', $fallback)
        : $fallback;
    $messages[] = [
        'role' => 'system',
        'content' => "<inline_narration_format>\n  <instruction>"
            . stobePromptXmlEscape($instruction)
            . "</instruction>\n</inline_narration_format>",
    ];
    return $messages;
}

/**
 * Emit inline narration with the configured actor, subtitle, and TTS routing.
 */
function stobeStreamDialogueResponse(
    string $actor,
    array|false $actorData,
    string $message,
    array $actions = [],
    string $eventType = 'chat',
    string $listener = '',
    int $gamets = 0,
    array $options = []
): void {
    $mode = stobeGetInlineNarrationMode();
    $applies = stobeInlineNarrationApplies($actor, $eventType);
    $parts = $applies ? stobeExtractInlineNarrationParts($message) : [
        'narrations' => [],
        'dialogue' => $message,
        'has_narration' => false,
    ];

    if (count($actions) > 0) {
        streamResponse($actor, 'ScriptQueue', '', $actorData, $actions, $eventType, $listener, $gamets);
    }

    if (!$applies || empty($parts['has_narration'])) {
        $clean = stobeStripParentheticalDialogueText($message);
        streamResponse($actor, 'ScriptQueue', $clean, $actorData, [], $eventType, $listener, $gamets, $options);
        return;
    }

    $narrations = is_array($parts['narrations'] ?? null) ? $parts['narrations'] : [];
    $dialogue = stobeStripParentheticalDialogueText(strval($parts['dialogue'] ?? ''));
    $narrationDisplay = [];
    foreach ($narrations as $narrationRaw) {
        $narration = trim(strval($narrationRaw));
        if ($narration !== '') {
            $narrationDisplay[] = '*' . trim($narration, '* ') . '*';
        }
    }

    if ($mode === 'narrator') {
        $narratorData = function_exists('stobeBuildNarratorNpcData')
            ? stobeBuildNarratorNpcData()
            : false;
        foreach ($narrations as $index => $narrationRaw) {
            $narration = trim(strval($narrationRaw));
            if ($narration === '') {
                continue;
            }
            streamResponse(
                function_exists('stobeNarratorName') ? stobeNarratorName() : 'The Narrator',
                'ScriptQueue',
                $narrationDisplay[$index] ?? ('*' . trim($narration, '* ') . '*'),
                $narratorData,
                [],
                'inline_narration',
                $listener,
                $gamets,
                ['tts_text' => $narration, 'single_segment' => true]
            );
        }
        if ($dialogue !== '') {
            streamResponse($actor, 'ScriptQueue', $dialogue, $actorData, [], $eventType, $listener, $gamets, $options);
        }
        return;
    }

    $displayText = trim(implode(' ', $narrationDisplay) . ' ' . $dialogue);
    if ($mode === 'text_only') {
        $textOnlyOptions = array_merge($options, [
            'tts_text' => $dialogue,
            'suppress_tts' => boolval($options['suppress_tts'] ?? false) || $dialogue === '',
            'single_segment' => true,
        ]);
        streamResponse(
            $actor,
            'ScriptQueue',
            $displayText,
            $actorData,
            [],
            $eventType,
            $listener,
            $gamets,
            $textOnlyOptions
        );
        return;
    }

    $npcSpeech = trim(implode(' ', array_map(
        static fn($value): string => trim(strval($value), '* '),
        $narrations
    )) . ' ' . $dialogue);
    $npcSpeechOptions = array_merge($options, [
        'tts_text' => $npcSpeech,
        'single_segment' => true,
    ]);
    streamResponse(
        $actor,
        'ScriptQueue',
        $displayText,
        $actorData,
        [],
        $eventType,
        $listener,
        $gamets,
        $npcSpeechOptions
    );
}

function stobeStructuredStreamResponseIsUsable(string $rawResponse, bool $isStructured, string $message): bool {
    if (trim($message) === '') {
        return false;
    }

    $looksLikeStructuredPayload = preg_match('/^\s*[\{\[]/', $rawResponse) === 1;
    return !$looksLikeStructuredPayload || $isStructured;
}

function stobeStreamDialogueViaLlm(
    string $actor,
    array|false $actorData,
    array $messages,
    array $llmConfig,
    string $eventType = 'chat',
    array $meta = []
): array {
    $messages = stobeInlineNarrationPromptMessages($messages, $actor, $eventType);
    $result = [
        'ok' => false,
        'used_streaming' => false,
        'raw_response' => '',
        'response_text' => '',
        'actions' => [],
        'actions_streamed' => false,
        'structured_json' => false,
        'listener' => '',
        'chunks_emitted' => 0,
    ];

    if (!function_exists('stobeCallLLMStream')) {
        return $result;
    }

    $actionConfig = is_array($meta['action_config'] ?? null)
        ? $meta['action_config']
        : stobeBuildActionConfigForNpc($eventType, $actorData);

    $streamMeta = $meta;
    $structuredResponseFormat = is_array($streamMeta['response_format'] ?? null)
        ? $streamMeta['response_format']
        : null;
    $streamEventType = strtolower(trim(strval($meta['stream_event_type'] ?? $eventType)));
    if ($streamEventType === '') {
        $streamEventType = strtolower(trim($eventType));
    }
    $streamListener = normalizeParticipantNameToken(strval($meta['stream_listener'] ?? ''));
    $streamGamets = max(0, intval($meta['stream_gamets'] ?? 0));
    $streamOptions = [];
    if (boolval($meta['suppress_tts'] ?? false)) {
        $streamOptions['suppress_tts'] = true;
    }
    unset($streamMeta['suppress_tts'], $streamMeta['defer_structured_stream'], $streamMeta['hold_stream_on_money']);
    // Underway deal: speak normally, but hold everything from the first sentence about money.
    $holdOnMoney = !empty($meta['hold_stream_on_money']) && function_exists('stobeDealSpeechMentionsMoney');
    if ($streamListener === '') {
        $streamListener = trim(strval($meta['stream_listener'] ?? ''));
    }

    if (stobeInlineNarrationApplies($actor, $eventType)) {
        $rawResponse = '';
        $streamed = stobeCallLLMStream(
            $messages,
            $llmConfig,
            static function (string $delta) use (&$rawResponse): void {
                $rawResponse .= $delta;
            },
            $streamMeta
        );
        if ($streamed === false) {
            return $result;
        }
        if (is_string($streamed) && trim($streamed) !== '') {
            $rawResponse = $streamed;
        }

        $parsed = stobeParseStructuredDialogueResponse($rawResponse, $eventType);
        $isStructured = boolval($parsed['is_structured'] ?? false);
        $responseText = $isStructured
            ? sanitizeForKenshi(trim(strval($parsed['message'] ?? '')))
            : sanitizeForKenshi(trim($rawResponse));
        $rawActions = [];
        $parsedAction = trim(strval($parsed['action_tag'] ?? ''));
        if ($parsedAction !== '') {
            $rawActions[] = $parsedAction;
        }
        $extraction = extractAndNormalizeActionTags($responseText, $eventType, $actionConfig);
        $responseText = sanitizeForKenshi(trim(strval($extraction['text'] ?? $responseText)));
        if (is_array($extraction['actions'] ?? null)) {
            $rawActions = array_merge($rawActions, $extraction['actions']);
        }

        $result['ok'] = true;
        $result['used_streaming'] = true;
        $result['raw_response'] = trim($rawResponse);
        $result['response_text'] = $responseText;
        $result['actions'] = stobeDedupeActionList($rawActions, $eventType, $actionConfig);
        $result['actions_streamed'] = false;
        $result['structured_json'] = $isStructured;
        $result['listener'] = normalizeParticipantNameToken(strval($parsed['listener'] ?? ''));
        $result['chunks_emitted'] = 0;
        return $result;
    }

    if (is_array($structuredResponseFormat)) {
        $rawResponse = '';
        $chunksEmitted = 0;
        $heldBack = false;
        $spokenText = '';
        $heldFrom = '';
        $messageStreamBuffer = '';
        $lastStructuredMessage = '';
        $structuredListener = '';
        $structuredParsed = false;
        require_once __DIR__ . '/stream_tts_queue.php';
        $ttsQueue = null;
        if (stobeIsTtsEnabledForCurrentRequest() && getSettingInt('ASYNC_STREAM_TTS', 1) !== 0) {
            $ttsQueue = new StobeStreamTtsQueue(
                $actor, $actorData,
                static function (string $text, ?array $prepared) use ($actor, $actorData, $streamEventType, $streamListener, $streamGamets, $streamOptions): void {
                    streamResponse($actor, 'ScriptQueue', $text, $actorData, [], $streamEventType, $streamListener, $streamGamets,
                        array_merge($streamOptions, ['single_segment' => true, 'prepared_tts' => $prepared]));
                }
            );
        }

        $emitStructuredDialogue = function (string $deltaText = '', bool $flushRemainder = false) use (
            &$messageStreamBuffer,
            &$chunksEmitted,
            &$heldBack,
            &$spokenText,
            &$heldFrom,
            $holdOnMoney,
            $actor,
            $actorData,
            $streamEventType,
            $streamListener,
            $streamGamets,
            $streamOptions,
            $ttsQueue
        ): void {
            if ($deltaText !== '') {
                $messageStreamBuffer .= $deltaText;
            }

            while (true) {
                $position = stobeFindFastSentencePosition($messageStreamBuffer);
                if ($position === false || $position < 0) {
                    break;
                }

                $sentence = trim(substr($messageStreamBuffer, 0, $position + 1));
                $remaining = substr($messageStreamBuffer, $position + 1);
                $messageStreamBuffer = ltrim(strval($remaining));
                if ($sentence === '') {
                    continue;
                }

                $sentenceChunks = stobeSplitSentencesStream($sentence);
                foreach ($sentenceChunks as $sentenceChunkRaw) {
                    $sentenceChunk = stobeStripParentheticalDialogueText(
                        sanitizeForKenshi(trim(strval($sentenceChunkRaw)))
                    );
                    if ($sentenceChunk === '') {
                        continue;
                    }
                    if ($holdOnMoney && !$heldBack && stobeDealSpeechMentionsMoney($sentenceChunk)) {
                        $heldBack = true;
                        $heldFrom = $sentenceChunk;
                    }
                    if ($heldBack) {
                        continue;
                    }
                    $spokenText .= ($spokenText === '' ? '' : ' ') . $sentenceChunk;
                    if ($ttsQueue) {
                        $ttsQueue->enqueue($sentenceChunk);
                    } else {
                        streamResponse($actor, 'ScriptQueue', $sentenceChunk, $actorData, [], $streamEventType, $streamListener, $streamGamets, $streamOptions);
                    }
                    $chunksEmitted++;
                }
            }

            if (!$flushRemainder) {
                return;
            }

            $remainingText = stobeStripParentheticalDialogueText(
                sanitizeForKenshi(trim($messageStreamBuffer))
            );
            $messageStreamBuffer = '';
            if ($remainingText === '') {
                return;
            }

            $remainingChunks = stobeSplitSentencesStream($remainingText);
            foreach ($remainingChunks as $remainingChunkRaw) {
                $remainingChunk = stobeStripParentheticalDialogueText(
                    sanitizeForKenshi(trim(strval($remainingChunkRaw)))
                );
                if ($remainingChunk === '') {
                    continue;
                }
                if ($holdOnMoney && !$heldBack && stobeDealSpeechMentionsMoney($remainingChunk)) {
                    $heldBack = true;
                    $heldFrom = $remainingChunk;
                }
                if ($heldBack) {
                    continue;
                }
                $spokenText .= ($spokenText === '' ? '' : ' ') . $remainingChunk;
                if ($ttsQueue) {
                    $ttsQueue->enqueue($remainingChunk);
                } else {
                    streamResponse($actor, 'ScriptQueue', $remainingChunk, $actorData, [], $streamEventType, $streamListener, $streamGamets, $streamOptions);
                }
                $chunksEmitted++;
            }
        };

        $processStructuredSnapshot = function (bool $flushRemainder = false) use (
            &$rawResponse,
            &$lastStructuredMessage,
            &$structuredListener,
            &$structuredParsed,
            $eventType,
            $emitStructuredDialogue
        ): array {
            $parsedSnapshot = stobeParseStructuredDialogueResponse($rawResponse, $eventType);
            $isStructuredSnapshot = boolval($parsedSnapshot['is_structured'] ?? false);
            $parsedMessage = '';
            if ($isStructuredSnapshot) {
                $parsedMessage = stobeStripParentheticalDialogueText(
                    sanitizeForKenshi(trim(strval($parsedSnapshot['message'] ?? '')))
                );
            }
            if ($parsedMessage !== '') {
                $deltaText = stobeComputeStructuredStreamMessageDelta(
                    $lastStructuredMessage,
                    $parsedMessage
                );
                if (strlen($parsedMessage) >= strlen($lastStructuredMessage)) {
                    $lastStructuredMessage = $parsedMessage;
                }
                if ($deltaText !== '') {
                    $emitStructuredDialogue($deltaText, false);
                }
            }

            $parsedListener = normalizeParticipantNameToken(
                strval($parsedSnapshot['listener'] ?? '')
            );
            if ($parsedListener !== '') {
                $structuredListener = $parsedListener;
            }
            if ($isStructuredSnapshot || $parsedListener !== '') {
                $structuredParsed = true;
            }

            if ($flushRemainder) {
                $emitStructuredDialogue('', true);
            }

            return $parsedSnapshot;
        };

        $deferStructuredStream = !empty($meta['defer_structured_stream']);
        $streamed = stobeCallLLMStream(
            $messages,
            $llmConfig,
            function (string $delta) use (&$rawResponse, $processStructuredSnapshot, $ttsQueue, $deferStructuredStream): void {
                if ($delta === '') {
                    return;
                }
                $rawResponse .= $delta;
                if (!$deferStructuredStream) $processStructuredSnapshot(false);
                if ($ttsQueue && !$deferStructuredStream) $ttsQueue->pump();
            },
            $streamMeta
        );

        if ($streamed === false) {
            if ($ttsQueue) $ttsQueue->finish();
            return $result;
        }

        if (is_string($streamed) && trim($streamed) !== '') {
            $rawResponse = $streamed;
        }

        $finalSnapshot = $deferStructuredStream
            ? stobeParseStructuredDialogueResponse($rawResponse, $eventType)
            : $processStructuredSnapshot(true);
        if ($ttsQueue) $ttsQueue->finish();
        $finalIsStructured = boolval($finalSnapshot['is_structured'] ?? false) || $structuredParsed;
        $finalMessage = stobeStripParentheticalDialogueText(
            sanitizeForKenshi(trim(strval($finalSnapshot['message'] ?? '')))
        );
        if ($finalMessage === '' && $lastStructuredMessage !== '') {
            $finalMessage = $lastStructuredMessage;
        }
        if (!stobeStructuredStreamResponseIsUsable($rawResponse, $finalIsStructured, $finalMessage)) {
            stobeLogWarn('LLM structured stream did not contain usable dialogue', [
                'npc_name' => $actor,
                'event_type' => $eventType,
                'response_preview' => substr(trim($rawResponse), 0, 500),
            ]);
            return $result;
        }

        $finalActions = [];
        $finalAction = trim(strval($finalSnapshot['action_tag'] ?? ''));
        if ($finalAction !== '') {
            $finalActions[] = $finalAction;
        }
        $finalActions = stobeDedupeActionList($finalActions, $eventType, $actionConfig);

        $result['ok'] = true;
        $result['used_streaming'] = true;
        $result['raw_response'] = trim($rawResponse);
        $result['response_text'] = $finalMessage;
        $result['actions'] = $finalActions;
        $result['actions_streamed'] = false;
        $result['structured_json'] = $finalIsStructured;
        $result['listener'] = $structuredListener !== ''
            ? $structuredListener
            : normalizeParticipantNameToken(strval($finalSnapshot['listener'] ?? ''));
        $result['chunks_emitted'] = $chunksEmitted;
        $result['held_back'] = $heldBack;
        $result['spoken_text'] = $spokenText;
        $result['held_from'] = $heldFrom;
        return $result;
    }

    $rawResponse = '';
    $streamBuffer = '';
    $chunksEmitted = 0;
    $streamActionSeen = [];
    $rawActions = [];

    unset($streamMeta['response_format']);

    $streamed = stobeCallLLMStream(
        $messages,
        $llmConfig,
        function (string $delta) use (&$rawResponse, &$streamBuffer, &$chunksEmitted, $actor, $actorData, $eventType, $actionConfig, &$streamActionSeen, &$rawActions, $streamEventType, $streamListener, $streamGamets, $streamOptions): void {
            if ($delta === '') {
                return;
            }
            $rawResponse .= $delta;
            $streamBuffer .= $delta;

            while (true) {
                $position = stobeFindFastSentencePosition($streamBuffer);
                if ($position === false || $position < 0) {
                    break;
                }

                $extracted = trim(substr($streamBuffer, 0, $position + 1));
                $remaining = substr($streamBuffer, $position + 1);
                $streamBuffer = ltrim(strval($remaining));
                if ($extracted === '') {
                    continue;
                }

                $chunks = stobeSplitSentencesStream($extracted);
                foreach ($chunks as $chunkRaw) {
                    $chunk = sanitizeForKenshi(trim(strval($chunkRaw)));
                    if ($chunk === '') {
                        continue;
                    }
                    $chunkExtraction = extractAndNormalizeActionTags($chunk, $eventType, $actionConfig);
                    $chunkActions = is_array($chunkExtraction['actions'] ?? null)
                        ? $chunkExtraction['actions']
                        : [];
                    foreach ($chunkActions as $chunkAction) {
                        $normalizedChunkAction = trim(strval($chunkAction));
                        if ($normalizedChunkAction === '') {
                            continue;
                        }
                        $rawActions[] = $normalizedChunkAction;
                        $seenKey = strtolower($normalizedChunkAction);
                        if (!isset($streamActionSeen[$seenKey])) {
                            $streamActionSeen[$seenKey] = true;
                            streamResponse($actor, 'ScriptQueue', '', $actorData, [$normalizedChunkAction], $streamEventType, $streamListener, $streamGamets, $streamOptions);
                        }
                    }
                    $chunkText = sanitizeForKenshi(trim(strval($chunkExtraction['text'] ?? $chunk)));
                    $chunkText = stobeStripParentheticalDialogueText($chunkText);
                    if ($chunkText !== '') {
                        streamResponse($actor, 'ScriptQueue', $chunkText, $actorData, [], $streamEventType, $streamListener, $streamGamets, $streamOptions);
                        $chunksEmitted++;
                    }
                }
            }
        },
        $streamMeta
    );

    if ($streamed === false) {
        return $result;
    }

    if (is_string($streamed) && trim($streamed) !== '') {
        $rawResponse = $streamed;
    }

    $structured = stobeParseStructuredDialogueResponse($rawResponse, $eventType);
    $isStructured = boolval($structured['is_structured'] ?? false);
    $listener = normalizeParticipantNameToken(strval($structured['listener'] ?? ''));

    $responseText = '';
    if ($isStructured) {
        $responseText = sanitizeForKenshi(trim(strval($structured['message'] ?? '')));
        $structuredAction = trim(strval($structured['action_tag'] ?? ''));
        if ($structuredAction !== '') {
            $rawActions[] = $structuredAction;
        }
        $inlineExtraction = extractAndNormalizeActionTags($responseText, $eventType, $actionConfig);
        $responseText = sanitizeForKenshi(trim(strval($inlineExtraction['text'] ?? $responseText)));
        if (is_array($inlineExtraction['actions'] ?? null)) {
            foreach ($inlineExtraction['actions'] as $inlineAction) {
                $rawActions[] = strval($inlineAction);
            }
        }
    } else {
        $fullExtraction = extractAndNormalizeActionTags($rawResponse, $eventType, $actionConfig);
        $responseText = sanitizeForKenshi(trim(strval($fullExtraction['text'] ?? $rawResponse)));
        if (is_array($fullExtraction['actions'] ?? null)) {
            foreach ($fullExtraction['actions'] as $inlineAction) {
                $rawActions[] = strval($inlineAction);
            }
        }
    }

    $responseText = stobeStripParentheticalDialogueText($responseText);

    $remaining = trim($streamBuffer);
    if ($remaining !== '') {
        $remainingExtraction = extractAndNormalizeActionTags($remaining, $eventType, $actionConfig);
        $remainingActions = is_array($remainingExtraction['actions'] ?? null)
            ? $remainingExtraction['actions']
            : [];
        foreach ($remainingActions as $remainingAction) {
            $normalizedRemainingAction = trim(strval($remainingAction));
            if ($normalizedRemainingAction === '') {
                continue;
            }
            $rawActions[] = $normalizedRemainingAction;
            $seenKey = strtolower($normalizedRemainingAction);
            if (!isset($streamActionSeen[$seenKey])) {
                $streamActionSeen[$seenKey] = true;
                streamResponse($actor, 'ScriptQueue', '', $actorData, [$normalizedRemainingAction], $streamEventType, $streamListener, $streamGamets, $streamOptions);
            }
        }
        $remainingText = sanitizeForKenshi(trim(strval($remainingExtraction['text'] ?? '')));
        $remainingText = stobeStripParentheticalDialogueText($remainingText);
        if ($remainingText !== '') {
            streamResponse($actor, 'ScriptQueue', $remainingText, $actorData, [], $streamEventType, $streamListener, $streamGamets, $streamOptions);
            $chunksEmitted++;
        }
    }

    $dedupedActions = stobeDedupeActionList($rawActions, $eventType, $actionConfig);
    foreach ($dedupedActions as $dedupedAction) {
        $normalizedAction = trim(strval($dedupedAction));
        if ($normalizedAction === '') {
            continue;
        }
        $seenKey = strtolower($normalizedAction);
        if (!isset($streamActionSeen[$seenKey])) {
            $streamActionSeen[$seenKey] = true;
            streamResponse($actor, 'ScriptQueue', '', $actorData, [$normalizedAction], $streamEventType, $streamListener, $streamGamets, $streamOptions);
        }
    }

    $result['ok'] = true;
    $result['used_streaming'] = true;
    $result['raw_response'] = trim($rawResponse);
    $result['response_text'] = $responseText;
    $result['actions'] = $dedupedActions;
    $result['actions_streamed'] = count($streamActionSeen) > 0;
    $result['structured_json'] = $isStructured;
    $result['listener'] = $listener;
    $result['chunks_emitted'] = $chunksEmitted;
    return $result;
}

function stobeParseLiveStorageSerial(string $storageId): int {
    $candidate = trim($storageId);
    if ($candidate === '') {
        return 0;
    }

    $numeric = '';
    if (preg_match('/^-?\d+$/', $candidate) === 1) {
        $numeric = $candidate;
    } elseif (preg_match('/(?:^|_)(-?\d+)$/', $candidate, $matches) === 1) {
        $numeric = strval($matches[1] ?? '');
    }
    if ($numeric === '' || preg_match('/^-?\d+$/', $numeric) !== 1) {
        return 0;
    }

    $serial = intval($numeric);
    if ($serial < 0) {
        $serial += 4294967296;
    }
    if ($serial <= 0 || $serial > 4294967295) {
        return 0;
    }
    return $serial;
}

function stobeQueueUnequipItemRequest(string $actor, string $itemQuery): bool {
    $safeActor = normalizeParticipantNameToken($actor);
    $safeItem = trim(preg_replace('/[\r\n\t]+/', ' ', $itemQuery) ?? '');
    $safeItem = trim(str_replace(['|'], ' ', $safeItem));
    if ($safeActor === '' || $safeItem === '') {
        return false;
    }
    if (strlen($safeItem) > 160) {
        $safeItem = substr($safeItem, 0, 160);
    }

    $peopleRaw = strval($GLOBALS['CACHE_PEOPLE'] ?? ($_GET['people'] ?? ($_POST['people'] ?? '')));
    $identities = extractParticipantIdentities([
        'people' => $peopleRaw,
        'profile' => $safeActor,
    ]);
    $serial = 0;
    foreach ($identities as $identity) {
        if (!is_array($identity)) {
            continue;
        }
        if (strcasecmp(normalizeParticipantNameToken(strval($identity['name'] ?? '')), $safeActor) !== 0) {
            continue;
        }
        $serial = stobeParseLiveStorageSerial(strval($identity['storage_id'] ?? ''));
        if ($serial > 0) {
            break;
        }
    }
    if ($serial <= 0) {
        stobeLogWarn('Unequip request skipped because actor serial was unavailable', [
            'actor' => $safeActor,
            'item' => $safeItem,
        ]);
        return false;
    }

    $requestPath = '/mnt/d/Steam/steamapps/common/Kenshi/RE_Kenshi/mods/Stobe/unequip_item.request';
    $tempPath = $requestPath . '.tmp.' . strval(getmypid());
    // One-slot mailbox (KenshiFP takes one request every 50 ms): never overwrite a pending one.
    for ($slotWait = 0; $slotWait < 40 && file_exists($requestPath); $slotWait++) usleep(50000);
    $payload = strval($serial) . "\t" . $safeItem . "\n";
    if (@file_put_contents($tempPath, $payload, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tempPath, $requestPath)) {
        @unlink($tempPath);
        return false;
    }

    stobeLogInfo('Queued KenshiFP unequip request', [
        'actor' => $safeActor,
        'serial' => $serial,
        'item' => $safeItem,
    ]);
    return true;
}

/**
 * Items for FETCH/STORE/DELIVER: the model's item field split on "," "&" "and";
 * if that gives one item, the player's own line may list more (bug 60:
 * "fetch the vodka and the mead" came back as item=Vodka).
 */
function stobeTaskItemListFromTurn(string $item): array {
    $split = static fn(string $v): array => array_values(array_filter(array_map('trim',
        preg_split('/\s*(?:,|&|\band\b)\s*/i', $v) ?: []), static fn($x) => $x !== ''));
    $list = $split($item);
    if (count($list) !== 1) return $list;
    $line = trim(strval($GLOBALS['STOBE_CURRENT_PLAYER_MESSAGE'] ?? ''));
    if ($line === '' || !preg_match('/\b(?:fetch|get|grab|bring|take|put|store|stash|stow|give|hand)\b\s+(?:me\s+|us\s+)?(.+?)(?:\s+\b(?:from|in|into|to|back|at|for|out)\b|[.?!;]|$)/i', $line, $m)) {
        return $list;
    }
    $words = [];
    foreach ($split($m[1]) as $part) {
        $part = trim(preg_replace('/^(?:the|a|an|some|my|our|your|those|these|that|this|all|\d+)\s+/i', '', $part) ?? '');
        $part = trim(preg_replace('/^(?:the|a|an|some)\s+/i', '', $part) ?? '');
        if ($part !== '' && strlen($part) <= 40) $words[] = $part;
    }
    if (count($words) < 2) return $list;
    $one = strtolower($list[0]);
    $hit = false;
    foreach ($words as $w) {
        $lw = strtolower($w);
        if (str_contains($one, $lw) || str_contains($lw, $one)) { $hit = true; break; }
    }
    if (!$hit) return $list;
    $out = [$list[0]];
    foreach ($words as $w) {
        $lw = strtolower($w);
        if (str_contains($one, $lw) || str_contains($lw, $one)) continue;
        $out[] = $w;
    }
    return array_slice($out, 0, 6);
}

/**
 * Bug 77: "put them back" after a fetch -> every item of the actor's latest
 * FETCH batch (goals queued within 10 s of each other, last 60 min).
 */
function stobeTaskStorePronounItems(string $actor, string $item): array {
    $line = strtolower(trim(strval($GLOBALS['STOBE_CURRENT_PLAYER_MESSAGE'] ?? '')));
    if ($actor === '' || $line === ''
        || !preg_match('/\b(them|those|these|both|everything|all\s+of\s+(?:it|them))\b/', $line)) return [];
    try {
        $rows = $GLOBALS['db']->fetchAll(
            "SELECT item_name, EXTRACT(EPOCH FROM created_at) AS ts FROM stobe_task_goal_runtime
             WHERE LOWER(actor_name)=LOWER($1) AND kind='FETCH' AND created_at > NOW() - INTERVAL '60 minutes'
             ORDER BY created_at DESC LIMIT 6", [$actor]);
    } catch (Throwable $e) {
        return [];
    }
    if (!is_array($rows) || count($rows) < 2) return [];
    $newest = floatval($rows[0]['ts'] ?? 0);
    $items = [];
    foreach ($rows as $row) {
        if ($newest - floatval($row['ts'] ?? 0) > 10.0) break;
        $name = trim(strval($row['item_name'] ?? ''));
        if ($name !== '' && !in_array(strtolower($name), array_map('strtolower', $items), true)) $items[] = $name;
    }
    if (count($items) < 2) return [];
    $one = strtolower(trim($item));
    if ($one !== '' && $one !== 'all') {
        $hit = false;
        foreach ($items as $i) {
            $li = strtolower($i);
            if (str_contains($li, $one) || str_contains($one, $li)) { $hit = true; break; }
        }
        if (!$hit) return [];
    }
    return array_reverse($items);
}

/**
 * Bug 76: a plain production order to a squad member ("Make 3 steel bars.")
 * that the model answered without a goal action -> WORK_GOAL@Item@N.
 * The planner reports BLOCKED itself when nothing can make it.
 */
function stobeInferWorkGoalFromOrder(string $playerLine, array|false $npcData, array $actions): string {
    if (!is_array($npcData) || !function_exists('npcIsInPlayerFaction') || !npcIsInPlayerFaction($npcData)) return '';
    foreach ($actions as $a) {
        if (preg_match('/^(WORK_GOAL|TASK_GOAL|TASK_CONTROL)@/i', strval($a))) return '';
    }
    $line = trim($playerLine);
    if ($line === '' || str_contains($line, '?')) return '';
    if (preg_match("/\b(don'?t|do\s+not|stop|never|no\s+need|cancel)\b/i", $line)) return '';
    $words = ['a'=>1,'an'=>1,'one'=>1,'two'=>2,'three'=>3,'four'=>4,'five'=>5,'six'=>6,'seven'=>7,
        'eight'=>8,'nine'=>9,'ten'=>10,'twelve'=>12,'twenty'=>20];
    if (!preg_match("/(?:^|[.!,;]\s*|\b(?:just|please|now|then|and)\s+)(?:make|craft|produce|smelt|cook|bake|brew)\s+(?:me\s+|us\s+)?(\d{1,4}|a|an|one|two|three|four|five|six|seven|eight|nine|ten|twelve|twenty)\s+([a-z][a-z' -]{1,40}?)(?=\s*(?:\band\b|\bfor\b|\bat\b|\bfrom\b|\bthen\b|[,.!;]|$))/i", $line, $m)) return '';
    $qty = ctype_digit($m[1]) ? intval($m[1]) : ($words[strtolower($m[1])] ?? 0);
    if ($qty < 1 || $qty > 1000) return '';
    $item = trim(preg_replace('/\s+/', ' ', $m[2]) ?? '');
    $parts = explode(' ', $item);
    $last = array_pop($parts);
    if (strlen($last) > 3 && preg_match('/[^s]s$/i', $last)) $last = substr($last, 0, -1);
    $parts[] = $last;
    $item = ucwords(strtolower(implode(' ', $parts)));
    if ($item === '' || preg_match('/^(it|them|that|this|some|more|sure)$/i', $item)) return '';
    return 'WORK_GOAL@' . $item . '@' . $qty;
}

/**
 * Bug 87: "follow me" / "guard me" to a squad member answered without a
 * movement action -> BODYGUARD@<player>.
 */
function stobeInferFollowFromOrder(string $playerLine, array|false $npcData, array $actions, string $playerName): string {
    if ($playerName === '' || !is_array($npcData) || !function_exists('npcIsInPlayerFaction') || !npcIsInPlayerFaction($npcData)) return '';
    foreach ($actions as $a) {
        if (preg_match('/^(FOLLOW|BODYGUARD|GUARD_TARGET|MOVE_TO|MOVE_TO_TARGET|HOLD_POSITION|PATROL|ATTACK|STOP_ATTACK|TRAVEL_LOCATION|WORK_GOAL|TASK_GOAL)@/i', strval($a))) return '';
    }
    $line = trim($playerLine);
    if ($line === '' || preg_match("/\b(don'?t|do\s+not|stop|quit|no\s+longer|never)\b/i", $line)) return '';
    if (!preg_match('/\b(follow\s+me|guard\s+me|protect\s+me|cover\s+me|watch\s+my\s+back|come\s+with\s+me|stay\s+(?:close|with\s+me|near\s+me)|stick\s+(?:close|with\s+me)|keep\s+up\s+with\s+me)\b/i', $line)) return '';
    return 'BODYGUARD@' . $playerName;
}

/** Work orders a non-faction NPC never takes from the player (feature 1). */
function stobeNonFactionWorkOrderCommands(): array {
    return ['WORK_GOAL','TASK_GOAL','TASK_CONTROL','LOOT_TARGET','REPAIR','BUILD','OPERATE_OBJECT','TASK',
        'SET_BLOCK','SET_HOLD','SET_PASSIVE','SET_JOBS','SET_RANGED','SET_TAUNT','SET_SNEAK','SET_RESOURCE','SET_MEDIC',
        'PATROL','RESCUE','PUT_IN_BED','IMPRISON','RELEASE_PRISONER','FIRST_AID'];
}

/** Minor requests a non-faction NPC takes only with trust or an agreed deal. */
function stobeNonFactionMinorOrderCommands(): array {
    return ['FOLLOW','MOVE_TO_TARGET','HOLD_POSITION','BODYGUARD','GUARD_TARGET','TRAVEL_LOCATION','MOVE_TO'];
}

function stobeNonFactionOrderTrustMin(): int {
    $gift = intval(getSettingInt('GIFT_TRUST_THRESHOLD', 56));
    return max(0, min(100, intval(getSettingInt('MINOR_ORDER_TRUST_MIN', $gift))));
}

/**
 * '' when the NPC may carry out this action for the player; otherwise an in-character
 * refusal line. Faction members and non-order actions always pass.
 */
function stobePlayerOrderGate(string $actor, array|false $npcData, string $normalizedAction): string {
    if (!is_array($npcData) || count($npcData) === 0) return '';
    if (function_exists('npcIsInPlayerFaction') && npcIsInPlayerFaction($npcData)) return '';
    $at = strpos($normalizedAction, '@');
    $cmd = strtoupper(trim($at === false ? $normalizedAction : substr($normalizedAction, 0, $at)));
    if (in_array($cmd, stobeNonFactionWorkOrderCommands(), true)) {
        return "I don't take orders like that from you - I'm not one of yours.";
    }
    if (!in_array($cmd, stobeNonFactionMinorOrderCommands(), true)) return '';
    $trust = function_exists('stobeNpcPlayerAffinity') ? intval(stobeNpcPlayerAffinity($npcData)) : 0;
    if ($trust >= stobeNonFactionOrderTrustMin()) return '';
    try {
        if (function_exists('stobeDealOpenForNpc') && stobeDealOpenForNpc($actor) !== null) return '';
    } catch (Throwable $e) {
    }
    return "Why would I do that for you? Make it worth my while first.";
}

function stobeResolveLiveParticipantSerial(string $name, bool $allowStoredFallback = false): int {
    $safeName = normalizeParticipantNameToken($name);
    if ($safeName === '') {
        return 0;
    }

    $peopleRaw = strval($GLOBALS['CACHE_PEOPLE'] ?? ($_GET['people'] ?? ($_POST['people'] ?? '')));
    $identities = extractParticipantIdentities([
        'people' => $peopleRaw,
        'profile' => normalizeParticipantNameToken(strval($_GET['profile'] ?? '')),
        'speaker' => normalizeParticipantNameToken(strval($_GET['speaker'] ?? ($_POST['speaker'] ?? ''))),
    ]);
    foreach ($identities as $identity) {
        if (!is_array($identity)) {
            continue;
        }
        if (strcasecmp(normalizeParticipantNameToken(strval($identity['name'] ?? '')), $safeName) !== 0) {
            continue;
        }
        $serial = stobeParseLiveStorageSerial(strval($identity['storage_id'] ?? ''));
        if ($serial > 0) {
            return $serial;
        }
    }
    if ($allowStoredFallback && isset($GLOBALS['db'])) {
        // Not in this request's people list (e.g. working far off at the base):
        // use the stored serial, but only when the name is unambiguous.
        $rows = $GLOBALS['db']->fetchAll(
            "SELECT metadata->>'storage_id' AS sid FROM core_npc_master WHERE LOWER(name)=LOWER($1) AND metadata ? 'storage_id' LIMIT 2",
            [$safeName]
        );
        if (is_array($rows) && count($rows) === 1) {
            $serial = stobeParseLiveStorageSerial(strval($rows[0]['sid'] ?? ''));
            if ($serial > 0) {
                return $serial;
            }
        }
    }
    return 0;
}

function stobeQueueKenshiFpActionRequest(
    string $actor,
    string $command,
    string $targetName = '',
    string $argument = ''
): bool {
    $safeActor = normalizeParticipantNameToken($actor);
    $safeCommand = stobeCanonicalizeActionCommand($command);
    $safeTarget = normalizeParticipantNameToken($targetName);
    $safeArgument = trim(preg_replace('/[\r\n\t]+/', ' ', $argument) ?? '');
    $safeArgument = trim(str_replace('|', ' ', $safeArgument));

    $allowed = [
        'EQUIP_ITEM', 'SHEATHE_WEAPON', 'DRAW_WEAPON', 'FACE_TARGET',
        'FIRST_AID', 'BODYGUARD', 'PATROL', 'RESCUE', 'PUT_IN_BED',
        'LOOT_TARGET', 'IMPRISON', 'RELEASE_PRISONER', 'DROP_WEAPON',
        'SURRENDER', 'MOVE_TO_TARGET', 'HOLD_POSITION', 'REPAIR', 'BUILD',
    ];
    if ($safeActor === '' || !in_array($safeCommand, $allowed, true)) {
        return false;
    }
    if (strlen($safeArgument) > 180) {
        $safeArgument = substr($safeArgument, 0, 180);
    }

    $actorSerial = stobeResolveLiveParticipantSerial($safeActor, true);
    if ($actorSerial <= 0) {
        stobeLogWarn('KenshiFP action request skipped because actor serial was unavailable', [
            'actor' => $safeActor,
            'command' => $safeCommand,
        ]);
        return false;
    }

    $targetCommands = [
        'FACE_TARGET', 'FIRST_AID', 'BODYGUARD', 'RESCUE', 'PUT_IN_BED',
        'LOOT_TARGET', 'IMPRISON', 'RELEASE_PRISONER', 'MOVE_TO_TARGET',
    ];
    $targetSerial = 0;
    if (in_array($safeCommand, $targetCommands, true)) {
        if ($safeTarget === '') {
            return false;
        }
        $targetSerial = stobeResolveLiveParticipantSerial($safeTarget);
        if ($targetSerial <= 0) {
            stobeLogWarn('KenshiFP action request skipped because target serial was unavailable', [
                'actor' => $safeActor,
                'command' => $safeCommand,
                'target' => $safeTarget,
            ]);
            return false;
        }
    }

    $requestPath = '/mnt/d/Steam/steamapps/common/Kenshi/RE_Kenshi/mods/Stobe/stobe_action.request';
    $tempPath = $requestPath . '.tmp.' . strval(getmypid());
    // One-slot mailbox (KenshiFP takes one request every 50 ms): never overwrite a pending one.
    for ($slotWait = 0; $slotWait < 40 && file_exists($requestPath); $slotWait++) usleep(50000);
    $payload = strval($actorSerial) . "\t" . $safeCommand . "\t"
        . strval($targetSerial) . "\t" . $safeArgument . "\n";
    if (@file_put_contents($tempPath, $payload, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tempPath, $requestPath)) {
        @unlink($tempPath);
        return false;
    }

    stobeLogInfo('Queued KenshiFP gameplay action request', [
        'actor' => $safeActor,
        'actor_serial' => $actorSerial,
        'command' => $safeCommand,
        'target' => $safeTarget,
        'target_serial' => $targetSerial,
        'argument' => $safeArgument,
    ]);
    return true;
}

function streamResponse(
    string $actor,
    string $action,
    string $message,
    array|false $actorData = false,
    array $actions = [],
    string $deliveryEventType = '',
    string $deliveryListener = '',
    int $deliveryGamets = 0,
    array $options = []
): void {
    stobeInteractionRequire();
    // Normalize accidental raw JSON payloads (including truncated JSON) before
    // splitting into streamed lines.
    $structuredFromMessage = stobeParseStructuredDialogueResponse($message, 'chat');
    if (boolval($structuredFromMessage['is_structured'] ?? false)) {
        $normalizedMessage = trim(strval($structuredFromMessage['message'] ?? ''));
        $message = $normalizedMessage;
        $structuredAction = trim(strval($structuredFromMessage['action_tag'] ?? ''));
        if ($structuredAction !== '' && !in_array($structuredAction, $actions, true)) {
            array_unshift($actions, $structuredAction);
        }
    }
    if (count($actions) > 0 && function_exists('stobeNegCeasefireBlocksAttack')) { // bug 116
        $actions = array_values(array_filter($actions, static fn($a) => !stobeNegCeasefireBlocksAttack($actor, strval($a))));
    }

    $queuedActions = 0;
    $effectiveDeliveryGamets = $deliveryGamets > 0
        ? $deliveryGamets
        : intval($_POST['gamets'] ?? $_GET['gamets'] ?? 0);
    // Bug 120: some callers pass no NPC data; without it a squadmate looked like a
    // low-trust outsider and her gift to the player was blocked.
    if (!is_array($actorData) && trim($actor) !== '' && function_exists('getNpcData')) {
        $actorData = getNpcData($actor);
    }
    $actionConfig = stobeBuildActionConfigForNpc(
        'chat',
        is_array($actorData) ? $actorData : false
    );
    foreach ($actions as $rawAction) {
        $rawActionText = strval($rawAction);
        $blockedProtectedGive = stobeProtectedGiveWouldBeBlocked($rawActionText, $actionConfig);
        $normalizedAction = normalizeActionTagToken($rawActionText, $actionConfig);
        if ($normalizedAction === '') {
            if ($blockedProtectedGive) {
                $alreadyRefusal = preg_match('/\b(no|not|won(?:\'|’)t|wouldn(?:\'|’)t|refuse|can(?:\'|’)t|cannot|mine|keeping)\b/i', $message) === 1;
                if (!$alreadyRefusal) {
                    $message = "No. I'm not handing over my equipped gear.";
                }
            }
            continue;
        }
        $orderRefusal = function_exists('stobePlayerOrderGate')
            ? stobePlayerOrderGate($actor, is_array($actorData) ? $actorData : false, $normalizedAction)
            : '';
        if ($orderRefusal !== '') {
            stobeLogInfo('Order refused: NPC is not in the player faction', ['actor' => $actor, 'action' => $normalizedAction]);
            if (preg_match('/\b(no|not|won(?:\'|’)t|refuse|can(?:\'|’)t|cannot|why would|price|pay)\b/i', $message) !== 1) {
                $message = trim($message . ' ' . $orderRefusal);
            }
            continue;
        }
        if (str_starts_with($normalizedAction, 'WORK_GOAL@')) {
            $goalParts = explode('@', $normalizedAction);
            array_shift($goalParts);
            $goalQuantity = count($goalParts) > 0 ? intval(array_pop($goalParts)) : 0;
            $goalDestination = '';
            $goalItem = '';
            if (count($goalParts) >= 2) {
                $goalDestination = trim(strval(array_shift($goalParts)));
                $goalItem = trim(implode(' ', $goalParts));
            } elseif (count($goalParts) === 1) {
                $goalItem = trim(strval($goalParts[0]));
            }
            $goalResult = null;
            $stockLine = strtolower(strval($GLOBALS['STOBE_CURRENT_PLAYER_MESSAGE'] ?? ''));
            if ($goalItem !== '' && $goalQuantity > 0 && function_exists('stobeTaskGoalQueue')
                && preg_match('/\b(stocked|in\s+stock|on\s+hand|always\s+have|never\s+run\s+(?:out|low)|maintain|keep\s+(?:\w+\s+){0,4}(?:topped|stocked|in\s+stock|on\s+hand|around|supplied))\b/', $stockLine) === 1) {
                $stockResult = stobeTaskGoalQueue(
                    $actor, 'STOCK', $goalItem, '', $goalDestination, $goalQuantity,
                    false, $goalQuantity, 0, false, $effectiveDeliveryGamets
                );
                stobeLogInfo('WORK_GOAL turned into STOCK goal (player asked to keep a stock)', ['actor' => $actor, 'item' => $goalItem, 'minimum' => $goalQuantity, 'ok' => boolval($stockResult['ok'] ?? false)]);
                $goalResult = $stockResult;
            }
            $resumeLine = $goalResult === null ? strtolower(strval($GLOBALS['STOBE_CURRENT_PLAYER_MESSAGE'] ?? '')) : '';
            if ($goalItem !== '' && $resumeLine !== '' && function_exists('stobeAnyGoalControl')
                && preg_match('/\b(resume|continue|carry\s+on|keep\s+going|pick\s+(?:it\s+)?(?:back\s+)?up|try\s+(?:\w+\s+){0,3}again|start\s+(?:\w+\s+){0,3}again|unpause)\b/', $resumeLine) === 1) {
                try {
                    if (function_exists('stobeWorkGoalSyncStatusFile')) stobeWorkGoalSyncStatusFile();
                    $paused = $GLOBALS['db']->fetchOne(
                        "SELECT goal_id FROM stobe_work_goal WHERE LOWER(actor_name)=LOWER($1)
                           AND status IN ('PAUSED','BLOCKED') AND LOWER(item_name)=LOWER($2)
                         ORDER BY updated_at DESC LIMIT 1",
                        [normalizeParticipantNameToken($actor), $goalItem]
                    );
                    if (is_array($paused)) {
                        $resumeResult = stobeAnyGoalControl($actor, 'RESUME', $goalItem);
                        if (boolval($resumeResult['ok'] ?? false)) {
                            stobeLogInfo('WORK_GOAL turned into RESUME of the existing goal', ['actor' => $actor, 'item' => $goalItem, 'goal_id' => strval($resumeResult['goal_id'] ?? '')]);
                            $goalResult = ['ok' => true, 'goal_id' => strval($resumeResult['goal_id'] ?? ''), 'resumed' => true];
                        }
                    }
                } catch (Throwable $e) {
                    stobeLogWarn('Resume lookup failed', ['error' => $e->getMessage()]);
                }
            }
            if ($goalResult === null) {
                $goalResult = stobeQueueWorkGoalRequest(
                    $actor,
                    $goalItem,
                    $goalQuantity,
                    $goalDestination,
                    $effectiveDeliveryGamets
                );
            }
            if (boolval($goalResult['ok'] ?? false)) {
                $queuedActions++;
            } else {
                $goalError = trim(strval($goalResult['error'] ?? 'work_goal_not_started'));
                stobeLogWarn('Persistent work goal could not be queued', [
                    'actor' => $actor,
                    'item' => $goalItem,
                    'quantity' => $goalQuantity,
                    'destination' => $goalDestination,
                    'error' => $goalError,
                ]);
                if ($goalError === 'destination_not_known') {
                    $message = trim($message . ' I cannot start that job because I do not know how to reach ' . $goalDestination . '.');
                } else {
                    $message = trim($message . ' I cannot start that work order right now.');
                }
            }
            continue;
        }
        if (str_starts_with($normalizedAction, 'TASK_GOAL@')) {
            $taskParts = explode('@', $normalizedAction);
            array_shift($taskParts);
            while (count($taskParts) < 6) $taskParts[] = '';
            [$taskKind,$taskTarget,$taskItem,$taskAmountRaw,$taskDestination,$taskMaxCatsRaw] = array_slice($taskParts,0,6);
            $taskKind = strtoupper(trim(strval($taskKind)));
            $taskAmount = max(0, min(1000, intval($taskAmountRaw)));
            $taskMaxCats = max(0, min(10000000, intval($taskMaxCatsRaw)));
            $explicitTrade = in_array($taskKind, ['BUY','SELL'], true);
            $queueOne = static function(string $kind, string $item, string $target, string $destination, int $amount, bool $storeAfter, int $minimumStock, int $maxCats, bool $explicitTrade) use ($actor, $effectiveDeliveryGamets): array {
                return stobeTaskGoalQueue(
                    $actor,$kind,$item,$target,$destination,$amount,$storeAfter,
                    $minimumStock,$maxCats,$explicitTrade,$effectiveDeliveryGamets
                );
            };

            $taskResults = [];
            if ($taskKind === 'STORE' && count(stobeTaskItemListFromTurn($taskItem)) <= 1
                && count($pronounItems = stobeTaskStorePronounItems($actor, $taskItem)) > 1) {
                stobeLogInfo('STORE expanded to the last fetched items (bug 77)', ['actor'=>$actor,'item'=>$taskItem,'items'=>$pronounItems]);
                $taskItem = implode(', ', $pronounItems);
            }
            if ($taskKind === 'BATTLE_CLEANUP') {
                $taskResults[] = $queueOne('MEDICAL_CLEANUP','','','',0,false,0,0,false);
                $taskResults[] = $queueOne(
                    'LOOT_STORE',
                    trim($taskItem) !== '' ? $taskItem : 'weapons',
                    trim($taskTarget) !== '' ? $taskTarget : 'enemies',
                    $taskDestination,
                    $taskAmount,
                    true,0,0,false
                );
            } elseif (in_array($taskKind, ['FETCH','STORE','DELIVER'], true)
                && count($taskItemList = stobeTaskItemListFromTurn($taskItem)) > 1) {
                foreach (array_slice($taskItemList, 0, 6) as $oneItem) {
                    $taskResults[] = $queueOne(
                        $taskKind, $oneItem, $taskTarget, $taskDestination,
                        $taskKind === 'STORE' ? $taskAmount : max(1, $taskAmount),
                        false, 0, $taskMaxCats, false
                    );
                }
            } else {
                $taskResults[] = $queueOne(
                    $taskKind,$taskItem,$taskTarget,$taskDestination,$taskAmount,
                    $taskKind === 'LOOT_STORE',
                    $taskKind === 'STOCK' ? $taskAmount : 0,
                    $taskMaxCats,$explicitTrade
                );
            }

            $allOk = count($taskResults) > 0;
            foreach ($taskResults as $taskResult) {
                if (!boolval($taskResult['ok'] ?? false)) {
                    $allOk = false;
                    stobeLogWarn('Persistent task goal could not be queued', [
                        'actor'=>$actor,'kind'=>$taskKind,'item'=>$taskItem,
                        'target'=>$taskTarget,'destination'=>$taskDestination,
                        'error'=>strval($taskResult['error'] ?? 'task_goal_not_started'),
                    ]);
                } else {
                    $queuedActions++;
                }
            }
            if (!$allOk) {
                $message = trim($message . ' I could not start all of that task right now.');
            }
            continue;
        }
        if (str_starts_with($normalizedAction, 'TASK_CONTROL@')) {
            $controlParts = explode('@', $normalizedAction);
            array_shift($controlParts);
            while (count($controlParts) < 4) $controlParts[] = '';
            [$controlCommand,$controlSelector,$controlAmountRaw,$controlDestination] = array_slice($controlParts,0,4);
            $controlCommand = strtoupper(trim(strval($controlCommand)));
            $controlResult = stobeAnyGoalControl(
                $actor,$controlCommand,trim($controlSelector),
                max(0,min(1000,intval($controlAmountRaw))),
                trim($controlDestination)
            );
            if (boolval($controlResult['ok'] ?? false)) {
                $queuedActions++;
            } else {
                stobeLogWarn('Persistent goal control failed', [
                    'actor'=>$actor,'command'=>$controlCommand,
                    'selector'=>$controlSelector,
                    'error'=>strval($controlResult['error'] ?? 'goal_control_failed'),
                ]);
                $message = trim($message . ' I could not find a matching goal to change.');
            }
            continue;
        }
        if (str_starts_with($normalizedAction, 'EQUIP_ITEM@')) {
            $equipQuery = trim(substr($normalizedAction, strlen('EQUIP_ITEM@')));
            if (stobeSpeechClaimsEquipDone($message)) {
                $message = "Alright. Let me put " . ($equipQuery !== '' ? $equipQuery : 'it') . " on.";
                stobeLogInfo('Rewrote premature equip completion claim', [
                    'actor' => $actor,
                    'item' => $equipQuery,
                ]);
            }
        }
        if (str_starts_with($normalizedAction, 'UNEQUIP_ITEM@')) {
            $itemQuery = trim(substr($normalizedAction, strlen('UNEQUIP_ITEM@')));
            if (preg_match('/\b(already\s+off|it(?:\'|’)s\s+off|it\s+is\s+off|took\s+(?:it|that)\s+off|taken\s+(?:it|that)\s+off)\b/i', $message) === 1) {
                $message = "Alright. Let me take " . ($itemQuery !== '' ? $itemQuery : 'it') . " off.";
                stobeLogInfo('Rewrote premature unequip completion claim', [
                    'actor' => $actor,
                    'item' => $itemQuery,
                ]);
            }
            if (stobeQueueUnequipItemRequest($actor, $itemQuery)) {
                $queuedActions++;
            } else {
                stobeLogWarn('Unequip action could not be queued', [
                    'actor' => $actor,
                    'item' => $itemQuery,
                ]);
                $message = trim($message . " I couldn't actually remove that item.");
            }
            continue;
        }

        $bridgeAt = strpos($normalizedAction, '@');
        $bridgeCommand = $bridgeAt === false
            ? stobeCanonicalizeActionCommand($normalizedAction)
            : stobeCanonicalizeActionCommand(substr($normalizedAction, 0, $bridgeAt));
        $bridgeArgument = $bridgeAt === false ? '' : trim(substr($normalizedAction, $bridgeAt + 1));
        $bridgeCommands = [
            'EQUIP_ITEM', 'SHEATHE_WEAPON', 'DRAW_WEAPON', 'FACE_TARGET',
            'FIRST_AID', 'BODYGUARD', 'PATROL', 'RESCUE', 'PUT_IN_BED',
            'LOOT_TARGET', 'IMPRISON', 'RELEASE_PRISONER', 'DROP_WEAPON',
            'SURRENDER', 'MOVE_TO_TARGET', 'HOLD_POSITION', 'REPAIR', 'BUILD',
        ];
        if ($bridgeCommand === 'PATROL' && function_exists('stobeTaskGoalQueue')) {
            // Kenshi's PATROL order without waypoints wanders the region (bug 62).
            $patrolResult = stobeTaskGoalQueue($actor, 'PATROL', '', '', '', 0, false, 0, 0, false, $effectiveDeliveryGamets);
            if (boolval($patrolResult['ok'] ?? false)) {
                $queuedActions++;
            } else {
                stobeLogWarn('Patrol goal could not be queued', ['actor' => $actor, 'error' => strval($patrolResult['error'] ?? '')]);
                $message = trim($message . " I couldn't actually carry that action out.");
            }
            continue;
        }
        if ($bridgeCommand === 'LOOT_TARGET' && function_exists('stobeTaskGoalQueue')) {
            $lootLine = strtolower(strval($GLOBALS['STOBE_CURRENT_PLAYER_MESSAGE'] ?? ''));
            $lootCategory = '';
            foreach ([
                'weapons' => '/\bweapons?\b/',
                'armour' => '/\b(armou?r|clothing|clothes|gear)\b/',
                'food' => '/\b(food|meat|rations?)\b/',
                'medical' => '/\b(medkits?|medical|first\s+aid|bandages?)\b/',
                'ammo' => '/\b(ammo|ammunition|bolts)\b/',
            ] as $lootCat => $lootRe) {
                if (preg_match($lootRe, $lootLine) === 1) { $lootCategory = $lootCat; break; }
            }
            $lootTarget = trim($bridgeArgument);
            $lootTargetLive = $lootTarget !== '' && stobeResolveLiveParticipantSerial($lootTarget) > 0;
            if ($lootCategory !== '' || !$lootTargetLive) {
                // Category loot, or a body outside the people list (bug 64): a LOOT_AREA goal.
                $lootResult = stobeTaskGoalQueue($actor, 'LOOT_AREA', $lootCategory !== '' ? $lootCategory : 'all',
                    $lootTarget, '', 0, false, 0, 0, false, $effectiveDeliveryGamets);
                stobeLogInfo('LOOT_TARGET turned into LOOT_AREA goal', ['actor' => $actor, 'target' => $lootTarget, 'category' => $lootCategory, 'ok' => boolval($lootResult['ok'] ?? false)]);
                if (boolval($lootResult['ok'] ?? false)) {
                    $queuedActions++;
                } else {
                    $message = trim($message . " I couldn't actually carry that action out.");
                }
                continue;
            }
        }
        if (in_array($bridgeCommand, $bridgeCommands, true)) {
            $bridgeTargetCommands = [
                'FACE_TARGET', 'FIRST_AID', 'BODYGUARD', 'RESCUE', 'PUT_IN_BED',
                'LOOT_TARGET', 'IMPRISON', 'RELEASE_PRISONER', 'MOVE_TO_TARGET',
            ];
            $bridgeTarget = in_array($bridgeCommand, $bridgeTargetCommands, true)
                ? $bridgeArgument
                : '';
            $bridgePayload = in_array($bridgeCommand, ['EQUIP_ITEM', 'REPAIR', 'BUILD'], true)
                ? $bridgeArgument
                : '';
            if (stobeQueueKenshiFpActionRequest($actor, $bridgeCommand, $bridgeTarget, $bridgePayload)) {
                $queuedActions++;
            } else {
                stobeLogWarn('KenshiFP gameplay action could not be queued', [
                    'actor' => $actor,
                    'command' => $bridgeCommand,
                    'target' => $bridgeTarget,
                    'argument' => $bridgePayload,
                ]);
                $message = trim($message . " I couldn't actually carry that action out.");
            }
            continue;
        }

        $dispatchAction = stobeTransformActionForDispatch($normalizedAction, is_array($actorData) ? $actorData : false);
        if ($dispatchAction === '') {
            continue;
        }
        $wirePayload = formatResponse($actor, 'ActionQueue', $dispatchAction);
        echo $wirePayload;
        stobeLogOutputToPlugin($actor, 'ActionQueue', $dispatchAction, $wirePayload);
        if (ob_get_length()) {
            ob_flush();
        }
        flush();
        $queuedActions++;
    }

    $rawLines = preg_split('/\r\n|\r|\n/', $message);
    if (!is_array($rawLines) || count($rawLines) === 0) {
        $rawLines = [$message];
    }

    $sentAny = false;
    $stopStreaming = false;
    $ttsEnabled = stobeIsTtsEnabledForCurrentRequest();
    if (strcasecmp($action, 'ScriptQueue') === 0 && !$ttsEnabled) {
        stobeLogInfo('TTS skipped for stream response', [
            'actor' => $actor,
            'action' => $action,
            'reason' => 'client_or_server_tts_disabled',
            'request_tts_enabled' => strval($_GET['tts_enabled'] ?? $_POST['tts_enabled'] ?? ''),
        ]);
    }
    foreach ($rawLines as $rawLine) {
        if ($stopStreaming) {
            break;
        }
        $line = rtrim(strval($rawLine), "\r");
        if (strcasecmp($action, 'ScriptQueue') === 0 && empty($options['single_segment'])) {
            $line = stobeStripParentheticalDialogueText($line);
        }
        if ($line === '') {
            continue;
        }
        $lineChunks = [$line];
        if (strcasecmp($action, 'ScriptQueue') === 0 && empty($options['single_segment'])) {
            $splitChunks = stobeSplitSentencesStream($line);
            if (is_array($splitChunks) && count($splitChunks) > 0) {
                $lineChunks = $splitChunks;
            }
        }

        foreach ($lineChunks as $chunkRaw) {
            if ($stopStreaming) {
                break;
            }
            $chunk = trim(strval($chunkRaw));
            if ($chunk === '') {
                continue;
            }

            if (function_exists('connection_aborted') && connection_aborted()) {
                stobeLogInfo('Stream response stopped after client disconnect', [
                    'actor' => $actor,
                    'action' => $action,
                    'queued_actions' => $queuedActions,
                ]);
                $stopStreaming = true;
                break;
            }

            $ttsHash = '';
            $ttsDurationMs = 0;
            $ttsText = array_key_exists('tts_text', $options)
                ? trim(strval($options['tts_text']))
                : $chunk;
            $suppressTts = boolval($options['suppress_tts'] ?? false) || $ttsText === '';
            if ($ttsEnabled && !$suppressTts && strcasecmp($action, 'ScriptQueue') === 0) {
                stobeInteractionRequire();
                // An async stream queue may have prepared this sentence already.
                $preparedTts = $options['prepared_tts'] ?? null;
                $ttsResult = is_array($preparedTts) && trim(strval($preparedTts['hash'] ?? '')) !== ''
                    ? $preparedTts
                    : stobeSynthesizePocketTtsLine($actor, $ttsText, $actorData);
                $ttsHash = trim(strval($ttsResult['hash'] ?? ''));
                $ttsDurationMs = intval($ttsResult['duration_ms'] ?? 0);
            }

            $utteranceId = '';
            if (strcasecmp($action, 'ScriptQueue') === 0) {
                $utteranceId = stobeGenerateUtteranceId();
                if ($effectiveDeliveryGamets > 0) {
                    stobePersistGeneratedSpeechChunk(
                        [
                            'actor' => $actor,
                            'action' => $action,
                            'message' => $chunk,
                            'utterance_id' => $utteranceId,
                            'event_type' => $deliveryEventType,
                            'listener' => $deliveryListener,
                        ],
                        $effectiveDeliveryGamets,
                        $deliveryEventType,
                        $deliveryListener
                    );
                }
            }

            $wirePayload = formatResponse($actor, $action, $chunk, $ttsHash, $ttsDurationMs, $utteranceId);
            echo $wirePayload;
            stobeLogOutputToPlugin($actor, $action, $chunk, $wirePayload, $ttsHash, $ttsDurationMs, $utteranceId);
            if (ob_get_length()) {
                ob_flush();
            }
            flush();
            $sentAny = true;
        }
    }

    if (!$sentAny && $queuedActions === 0) {
        $utteranceId = '';
        if (strcasecmp($action, 'ScriptQueue') === 0) {
            $utteranceId = stobeGenerateUtteranceId();
            if ($effectiveDeliveryGamets > 0) {
                stobePersistGeneratedSpeechChunk(
                    [
                        'actor' => $actor,
                        'action' => $action,
                        'message' => '...',
                        'utterance_id' => $utteranceId,
                        'event_type' => $deliveryEventType,
                        'listener' => $deliveryListener,
                    ],
                    $effectiveDeliveryGamets,
                    $deliveryEventType,
                    $deliveryListener
                );
            }
        }
        $wirePayload = formatResponse($actor, $action, '...', '', 0, $utteranceId);
        echo $wirePayload;
        stobeLogOutputToPlugin($actor, $action, '...', $wirePayload, '', 0, $utteranceId);
        if (ob_get_length()) {
            ob_flush();
        }
        flush();
    }
}

if (!function_exists('mb_scrub')) {
    function mb_scrub(string $str): string {
        return mb_convert_encoding($str, 'UTF-8', 'UTF-8');
    }
}
