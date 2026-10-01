<?php
/**
 * RELATIONSHIP EDITOR - Embeddable UI Component
 *
 * This file provides a relationship editing interface that can be embedded
 * in npc_master.php. It reads/writes to extended_data.relationships (JSONB).
 *
 * Features:
 * - Visual table editor for relationships
 * - "Build with AI" button to infer relationships from recent event history
 * - Manual add/edit/delete
 *
 * INSTALLATION:
 * Add this line in npc_master.php after the relationships textarea (around line 1407):
 *
 *   <?php if (file_exists(__DIR__."/../../ext/relationship_system/relationship_editor.php")) {
 *       include(__DIR__."/../../ext/relationship_system/relationship_editor.php");
 *   } ?>
 */

// This file expects $editItem to be available from the parent npc_master.php
if (!isset($editItem) || !is_array($editItem)) {
    return;
}

$relManagerPath = rtrim((string)($GLOBALS["ENGINE_PATH"] ?? ''), "/\\") . "/lib/relationship_manager.php";
if ($relManagerPath !== '' && file_exists($relManagerPath)) {
    require_once $relManagerPath;
}
if (!class_exists('RelationshipManager')) {
    // Minimal fallback so the editor still renders even if the full manager is unavailable.
    class RelationshipManager {
        public static function getTierLabel($score) {
            if ($score >= 91) return "Bonded";
            if ($score >= 76) return "Devoted";
            if ($score >= 56) return "Fond";
            if ($score >= 31) return "Friendly";
            if ($score >= 6) return "Acquaintance";
            if ($score >= -5) return "Neutral";
            if ($score >= -30) return "Wary";
            if ($score >= -55) return "Cold";
            if ($score >= -75) return "Resentful";
            if ($score >= -90) return "Hateful";
            return "Hostile";
        }
    }
}

// Get existing JSONB relationships
$extendedData = json_decode($editItem['extended_data'] ?? '{}', true) ?: [];
$jsonbRelationships = $extendedData['relationships'] ?? [];
if ((!is_array($jsonbRelationships) || count($jsonbRelationships) === 0) && !empty($editItem['relationships'])) {
    $legacyRelationships = json_decode(strval($editItem['relationships']), true);
    if (is_array($legacyRelationships)) {
        $jsonbRelationships = $legacyRelationships;
    }
}
if (!is_array($jsonbRelationships)) {
    $jsonbRelationships = [];
}
// Round 13: the player's row is shown and editable (the UI save replaces the
// whole map, so hiding it here also deleted it on save).
$filteredRelationships = [];
foreach ($jsonbRelationships as $target => $payload) {
    if (trim(strval($target)) === '') {
        continue;
    }
    $filteredRelationships[$target] = $payload;
}
$jsonbRelationships = $filteredRelationships;

// NPC name for AI analysis
$npcName = $editItem['npc_name'] ?? 'Unknown';

// NOTE: Auto-initialization is handled via the "Build with AI" button
// or during gameplay in postrequest.php - NOT on UI load
// (Loading RelationshipLLM in UI context causes missing class errors)
$autoInitMessage = '';

// Tier labels and colors for display (11 tiers with expanded ranges)
$tierColors = [
    'Bonded'      => '#22c55e',    // Bright green
    'Devoted'     => '#4ade80',    // Green
    'Fond'        => '#86efac',    // Light green
    'Friendly'    => '#a7f3d0',    // Pale green
    'Acquaintance'=> '#d9f99d',    // Yellow-green
    'Neutral'     => '#e5e7eb',    // Gray
    'Wary'        => '#fde68a',    // Yellow
    'Cold'        => '#fed7aa',    // Light orange
    'Resentful'   => '#fca5a5',    // Light red
    'Hateful'     => '#f87171',    // Red
    'Hostile'     => '#ef4444'     // Dark red
];

// Default types with icons (HTML entities to avoid encoding issues)
$defaultTypes = [
    // Classic types
    'romantic'     => '&#x1F49E;',
    'platonic'     => '&#x1F91D;',
    'familial'     => '&#x1F46A;',
    'professional' => '&#x1F4BC;',
    'rival'        => '&#x2694;&#xFE0F;',
    'enemy'        => '&#x1F5E1;&#xFE0F;',
    'neutral'      => '&#x2796;',
    // Extended types
    'nemesis'      => '&#x2620;&#xFE0F;',
    'estranged'    => '&#x1F494;',
    'transactional'=> '&#x1F4B0;',
    'protective'   => '&#x1F6E1;&#xFE0F;',
    'indebted'     => '&#x1FAE0;',
    'fanatical'    => '&#x1F525;',
    'mentor'       => '&#x1F4DA;',
    'student'      => '&#x1F393;',
    'servant'      => '&#x1F9F9;',
    'client'       => '&#x1F91D;',
    'patron'       => '&#x1F4B8;',
    'crush'        => '&#x1F497;',
    'ex'           => '&#x1F494;',
    'betrayed'     => '&#x1F92C;',
    'suspicious'   => '&#x1F928;',
    'admirer'      => '&#x2B50;',
    'jealous'      => '&#x1F4A2;',
    'fearful'      => '&#x1F628;',
    'obsessed'     => '&#x1F300;',
    'awed'         => '&#x1F632;',
    'contempt'     => '&#x1F624;',
    'pitying'      => '&#x1F622;',
    'grateful'     => '&#x1F979;',
    'curious'      => '&#x1F9D0;',
    'dismissive'   => '&#x1F611;'
];

// Collect any custom types from existing relationships
$customTypes = [];
foreach ($jsonbRelationships as $target => $relPayload) {
    $type = $relPayload['type'] ?? 'neutral';
    if (!isset($defaultTypes[$type])) {
        $customTypes[$type] = '&#x1F3F7;&#xFE0F;'; // Default icon for custom types
    }
}

// Merge default + custom types
$typeIcons = array_merge($defaultTypes, $customTypes);
?>

<div class="form-item span-2" id="relationship-editor-section">
    <div class="metadata-skills-view" style="border:1px solid #4a4a4a; border-radius:8px; padding:8px; background:#262626; margin-top:16px;">
        <div style="font-weight:700; color:#e6b76c; margin-bottom:8px;">Relationship Affinities</div>
        <small class="hint" style="display:block; margin:8px 0; color:#888;">
            Tracked relationships with affinity scores (-100 to +100) and types.
            <br><strong style="color:#fde68a;">Tip:</strong> "Build with AI" analyzes the latest event history for this NPC (up to 200 entries).
            <?php if (!empty($autoInitMessage)): ?>
                <br><strong style="color:#4ade80;">&#x2713; <?= htmlspecialchars($autoInitMessage) ?></strong>
            <?php endif; ?>
            <br><span style="color:#b8860b;">&#x26A0;&#xFE0F; Dynamic relationship data is subject to STOBE Paradox Prevention. Save your game to preserve changes.</span>
        </small>

        <div id="rel-editor-container" style="margin-top:12px; max-height:460px; overflow-y:auto; padding-right:4px;">
            <?php if (empty($jsonbRelationships)): ?>
                <p id="rel-empty-msg" style="color:#666; font-style:italic;">No relationships tracked yet. Use "Build with AI" or add manually below.</p>
            <?php else: ?>
                <table id="rel-table" style="width:100%; border-collapse:collapse; font-size:0.9em;">
                    <thead>
                        <tr style="border-bottom:1px solid #4a4a4a;">
                            <th style="text-align:left; padding:6px; color:#888;">Target</th>
                            <th style="text-align:center; padding:6px; color:#888;">Affinity</th>
                            <th style="text-align:center; padding:6px; color:#888;">Tier</th>
                            <th style="text-align:center; padding:6px; color:#888;">Type</th>
                            <th style="text-align:center; padding:6px; color:#888; width:70px;"></th>
                        </tr>
                    </thead>
                    <tbody id="rel-tbody">
                        <?php foreach ($jsonbRelationships as $target => $relPayload):
                            $aff = $relPayload['aff'] ?? 0;
                            $type = $relPayload['type'] ?? 'neutral';
                            $relation = $relPayload['relation'] ?? '';
                            $note = $relPayload['note'] ?? '';
                            $best = $relPayload['best'] ?? '';
                            $worst = $relPayload['worst'] ?? '';
                            $bestDelta = $relPayload['best_delta'] ?? 0;
                            $worstDelta = $relPayload['worst_delta'] ?? 0;
                            $customInfo = isset($relPayload['custom_info']) && is_scalar($relPayload['custom_info'])
                                ? strval($relPayload['custom_info'])
                                : '';
                            $tier = RelationshipManager::getTierLabel($aff);
                            $tierColor = $tierColors[$tier] ?? '#e5e7eb';
                            $typeIcon = $typeIcons[$type] ?? '&#x2796;';
                            $hasExtended = !empty($relation) || !empty($note) || !empty($best)
                                || !empty($worst) || $customInfo !== '';
                        ?>
                        <tr class="rel-row" data-target="<?= htmlspecialchars($target) ?>"
                            data-relation="<?= htmlspecialchars($relation) ?>"
                            data-note="<?= htmlspecialchars($note) ?>"
                            data-best="<?= htmlspecialchars($best) ?>"
                            data-worst="<?= htmlspecialchars($worst) ?>"
                            data-best-delta="<?= $bestDelta ?>"
                            data-worst-delta="<?= $worstDelta ?>"
                            data-custom-info="<?= htmlspecialchars($customInfo) ?>"
                            style="border-bottom:1px solid #333;">
                            <td style="padding:8px;">
                                <input type="text" class="rel-target" value="<?= htmlspecialchars($target) ?>"
                                       style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:4px 8px; width:180px;">
                            </td>
                            <td style="padding:8px; text-align:center;">
                                <input type="number" class="rel-aff" value="<?= $aff ?>" min="-100" max="100"
                                       style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:4px; width:60px; text-align:center;"
                                       onchange="updateRelTier(this)">
                            </td>
                            <td style="padding:8px; text-align:center;">
                                <span class="rel-tier" style="color:<?= $tierColor ?>; font-weight:500;"><?= $tier ?></span>
                            </td>
                            <td style="padding:8px; text-align:center;">
                                <select class="rel-type" style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:4px;">
                                    <?php foreach ($typeIcons as $t => $icon): ?>
                                        <option value="<?= $t ?>" <?= $t === $type ? 'selected' : '' ?>><?= $icon ?> <?= ucfirst($t) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td style="padding:8px; text-align:center; white-space:nowrap;">
                                <button type="button" class="rel-details" title="Edit details (relation, notes, events)"
                                        style="background:transparent; border:none; color:<?= $hasExtended ? '#fde68a' : '#666' ?>; cursor:pointer; font-size:1em; margin-right:4px;"
                                        onclick="openDetailsModal(this)">&#x270F;&#xFE0F;</button>
                                <button type="button" class="rel-delete" title="Remove relationship"
                                        style="background:transparent; border:none; color:#ef4444; cursor:pointer; font-size:1.2em;"
                                        onclick="removeRelRow(this)">&times;</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <!-- Add new relationship -->
            <div style="margin-top:12px; padding-top:12px; border-top:1px solid #333;">
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <input type="text" id="new-rel-target" placeholder="Target name (e.g., Beep, Kang)"
                           style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:6px 10px; flex:1; min-width:225px;">
                    <input type="number" id="new-rel-aff" value="0" min="-100" max="100" placeholder="Affinity"
                           style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:6px; width:70px; text-align:center;">
                    <select id="new-rel-type" style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:6px;">
                        <?php foreach ($typeIcons as $t => $icon): ?>
                            <option value="<?= $t ?>"><?= $icon ?> <?= ucfirst($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" onclick="addRelRow()"
                            style="background:#2a2a2a; border:1px solid #4a4a4a; border-radius:4px; color:#e6b76c; padding:6px 12px; cursor:pointer; font-weight:500;">
                        + Add
                    </button>
                </div>
            </div>

            <!-- Quick actions -->
            <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
                <button type="button" id="btn-build-ai" onclick="openBuildModal()"
                        style="background:#2a2a2a; border:1px solid #4a4a4a; border-radius:4px; color:#86efac; padding:6px 12px; cursor:pointer; font-size:0.85em;">Build with AI</button>
                <button type="button" onclick="openCustomTypeModal()"
                        style="background:#2a2a2a; border:1px solid #4a4a4a; border-radius:4px; color:#fde68a; padding:6px 12px; cursor:pointer; font-size:0.85em;">Add Custom Type</button>
                <button type="button" onclick="clearAllRelationships()"
                        style="background:#2a2a2a; border:1px solid #4a4a4a; border-radius:4px; color:#ef4444; padding:6px 12px; cursor:pointer; font-size:0.85em;">Clear All</button>
            </div>

            <!-- Build with AI Modal -->
            <div id="build-ai-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:10000; align-items:center; justify-content:center;">
                <div style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:8px; padding:20px; max-width:560px; width:92%;">
                    <h3 style="margin:0 0 12px 0; color:#86efac;">Build Relationships from Events</h3>
                    <p style="color:#888; font-size:0.9em; margin-bottom:12px;">
                        Uses recent event history (up to 200 entries) involving this NPC to infer affinity scores, relationship types, and optional notes.
                    </p>
                    <div style="background:#3a2a0a; border:1px solid #b8860b; border-radius:6px; color:#fde68a; padding:10px 12px; margin-bottom:12px; font-size:0.88em; line-height:1.35;">
                        <strong>Merge warning:</strong> AI results are merged into the current table. Existing entries with the same target name may be overwritten.
                    </div>
                    <div style="margin-bottom:12px;">
                        <label style="color:#ccc; font-size:0.85em;">Optional Direction</label>
                        <textarea id="build-ai-direction" placeholder="Optional guidance (example: prioritize recent betrayals over old alliances)."
                                  style="width:100%; margin-top:4px; background:#262626; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:8px; min-height:80px; resize:vertical;"></textarea>
                    </div>
                    <p style="color:#7a8a9a; font-size:0.8em; margin:0;">
                        This only updates the editor table. Click "Save NPC" to store the changes.
                    </p>
                    <div style="margin-top:16px; display:flex; gap:8px; justify-content:flex-end;">
                        <button type="button" onclick="closeBuildModal()"
                                style="background:#2a2a2a; border:1px solid #4a4a4a; border-radius:4px; color:#888; padding:8px 16px; cursor:pointer;">
                            Cancel
                        </button>
                        <button type="button" id="btn-build-confirm" onclick="buildWithAI()"
                                style="background:#2a2a2a; border:1px solid #4a4a4a; border-radius:4px; color:#86efac; padding:8px 16px; cursor:pointer; font-weight:500;">
                            Build
                        </button>
                    </div>
                </div>
            </div>

            <!-- Custom Type Modal -->
            <div id="custom-type-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:10000; align-items:center; justify-content:center;">
                <div style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:8px; padding:20px; max-width:500px; width:90%;">
                    <h3 style="margin:0 0 12px 0; color:#e6b76c;">Add Custom Relationship Type</h3>
                    <p style="color:#888; font-size:0.9em; margin-bottom:12px;">
                        Create a custom type (e.g., "client", "mentor", "servant"). The AI can use these during future relationship builds.
                    </p>
                    <div style="margin-bottom:12px;">
                        <label style="color:#ccc; font-size:0.85em;">Type Name (one word):</label>
                        <input type="text" id="custom-type-name" placeholder="e.g., client"
                               style="width:100%; margin-top:4px; background:#262626; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:8px;">
                    </div>
                    <div style="margin-bottom:12px;">
                        <label style="color:#ccc; font-size:0.85em;">Emoji:</label>
                        <div id="emoji-picker" style="display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; max-height:120px; overflow-y:auto;">
                            <?php
                            $emojis = ['&#x1F4B0;', '&#x1F4B8;', '&#x1F3AD;', '&#x1F5E1;&#xFE0F;', '&#x1F6E1;&#xFE0F;', '&#x1F4DC;', '&#x1F37A;', '&#x1F480;', '&#x1F525;', '&#x2744;&#xFE0F;', '&#x26A1;', '&#x1F319;', '&#x2600;&#xFE0F;', '&#x1F3F9;', '&#x1F9D9;', '&#x1F478;', '&#x1F924;', '&#x1F9DD;', '&#x1F409;', '&#x1F98A;', '&#x1F43A;', '&#x1F985;', '&#x1F3F0;', '&#x2694;&#xFE0F;', '&#x1F3AA;', '&#x1F3B5;', '&#x1F4BF;', '&#x1F48E;', '&#x1F5DD;&#xFE0F;', '&#x1F3C6;'];
                            foreach ($emojis as $emoji):
                            ?>
                            <button type="button" class="emoji-btn" onclick="selectEmoji('<?= $emoji ?>')"
                                    style="background:#262626; border:1px solid #4a4a4a; border-radius:4px; padding:8px; cursor:pointer; font-size:1.2em;">
                                <?= $emoji ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" id="custom-type-emoji" value="&#x1F3F7;&#xFE0F;">
                        <div style="margin-top:8px; color:#888; font-size:0.85em;">Selected: <span id="selected-emoji">&#x1F3F7;&#xFE0F;</span></div>
                    </div>
                    <div style="margin-top:16px; display:flex; gap:8px; justify-content:flex-end;">
                        <button type="button" onclick="closeCustomTypeModal()"
                                style="background:#2a2a2a; border:1px solid #4a4a4a; border-radius:4px; color:#888; padding:8px 16px; cursor:pointer;">
                            Cancel
                        </button>
                        <button type="button" onclick="addCustomType()"
                                style="background:#2a2a2a; border:1px solid #4a4a4a; border-radius:4px; color:#fde68a; padding:8px 16px; cursor:pointer; font-weight:500;">
                            Add Type
                        </button>
                    </div>
                </div>
            </div>

            <!-- Relationship Details Modal -->
            <div id="rel-details-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:10000; align-items:center; justify-content:center;">
                <div style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:8px; padding:20px; max-width:500px; width:90%;">
                    <h3 style="margin:0 0 8px 0; color:#e6b76c;">Details: <span id="details-target-name"></span></h3>
                    <p style="color:#888; font-size:0.8em; margin-bottom:12px;">
                        &#x26A0;&#xFE0F; These details are injected into AI context. Keep them useful and relevant.
                    </p>

                    <!-- Relationship Detail (specific role) -->
                    <div style="margin-bottom:10px;">
                        <label style="color:#ccc; font-size:0.85em;">Relationship Detail</label>
                        <div style="display:flex; gap:6px; margin-top:4px;">
                            <input type="text" id="details-relation" placeholder="son, ex-wife, employer"
                                   style="flex:1; background:#262626; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:8px;">
                            <button type="button" onclick="showRelationSuggestions()" title="Common suggestions"
                                    style="background:#2a2a2a; border:1px solid #4a4a4a; border-radius:4px; color:#888; padding:4px 8px; cursor:pointer; font-size:0.9em;">
                                +
                            </button>
                        </div>
                        <div id="relation-suggestions" style="display:none; margin-top:6px; flex-wrap:wrap; gap:4px;">
                            <!-- Populated by JS -->
                        </div>
                    </div>

                    <!-- Recent Note -->
                    <div style="margin-bottom:10px;">
                        <label style="color:#ccc; font-size:0.85em;">Recent Interaction</label>
                        <input type="text" id="details-note" placeholder="shared a drink, had argument"
                               style="width:100%; margin-top:4px; background:#262626; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:8px;">
                    </div>

                    <!-- Best Event -->
                    <div style="margin-bottom:10px;">
                        <label style="color:#86efac; font-size:0.85em;">Best Memory</label>
                        <input type="text" id="details-best" placeholder="opened gate for Ulfric, saved life"
                               style="width:100%; margin-top:4px; background:#262626; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:8px;">
                    </div>

                    <!-- Worst Event -->
                    <div style="margin-bottom:10px;">
                        <label style="color:#ef4444; font-size:0.85em;">Worst Memory</label>
                        <input type="text" id="details-worst" placeholder="killed his brother, betrayed trust"
                               style="width:100%; margin-top:4px; background:#262626; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:8px;">
                    </div>

                    <!-- Player-authored note: AI builds never rewrite this -->
                    <div style="margin-bottom:10px;">
                        <label for="details-custom-info" style="color:#93c5fd; font-size:0.85em;">Your note</label>
                        <textarea id="details-custom-info" rows="3" oninput="this.value=clampCustomInfo(this.value)"
                                  aria-describedby="details-custom-info-help"
                                  placeholder="What you want remembered about this relationship"
                                  style="width:100%; margin-top:4px; background:#262626; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:8px; resize:vertical; font-family:inherit; font-size:1em;"></textarea>
                        <p id="details-custom-info-help" style="margin:4px 0 0; color:#888; font-size:0.78em;">
                            AI updates will not change this note. Leave it empty to clear it. Up to 1000 characters.
                        </p>
                    </div>

                    <!-- Hidden field to track which row we're editing -->
                    <input type="hidden" id="details-row-target">

                    <div style="margin-top:14px; display:flex; gap:8px; justify-content:flex-end;">
                        <button type="button" onclick="closeDetailsModal()"
                                style="background:#2a2a2a; border:1px solid #4a4a4a; border-radius:4px; color:#888; padding:8px 16px; cursor:pointer;">
                            Cancel
                        </button>
                        <button type="button" onclick="saveDetails()"
                                style="background:#2a2a2a; border:1px solid #4a4a4a; border-radius:4px; color:#86efac; padding:8px 16px; cursor:pointer; font-weight:500;">
                            Save
                        </button>
                    </div>
                </div>
            </div>

            <!-- Status message -->
            <div id="rel-status" style="margin-top:8px; font-size:0.85em; display:none;"></div>
        </div>

        <!-- Hidden fields -->
        <input type="hidden" name="relationships_jsonb" id="relationships_jsonb" value="<?= htmlspecialchars(json_encode($jsonbRelationships)) ?>">
        <input type="hidden" id="rel-npc-name" value="<?= htmlspecialchars($npcName) ?>">
        <input type="hidden" id="rel-npc-id" value="<?= htmlspecialchars($editItem['id'] ?? '') ?>">
    </div>
</div>

<script>
// Tier calculation (matches PHP RelationshipManager::getTierLabel)
// 11 Tiers with BELL CURVE distribution - extremes are hard to reach
function getTierLabel(score) {
    if (score >= 91) return "Bonded";      // +91 to +100 (10 pts)
    if (score >= 76) return "Devoted";     // +76 to +90  (15 pts)
    if (score >= 56) return "Fond";        // +56 to +75  (20 pts)
    if (score >= 31) return "Friendly";    // +31 to +55  (25 pts)
    if (score >= 6) return "Acquaintance"; // +6 to +30   (25 pts)
    if (score >= -5) return "Neutral";     // -5 to +5    (11 pts)
    if (score >= -30) return "Wary";       // -30 to -6   (25 pts)
    if (score >= -55) return "Cold";       // -55 to -31  (25 pts)
    if (score >= -75) return "Resentful";  // -75 to -56  (20 pts)
    if (score >= -90) return "Hateful";    // -90 to -76  (15 pts)
    return "Hostile";                      // -100 to -91 (10 pts)
}

const tierColors = {
    'Bonded': '#22c55e',
    'Devoted': '#4ade80',
    'Fond': '#86efac',
    'Friendly': '#a7f3d0',
    'Acquaintance': '#d9f99d',
    'Neutral': '#e5e7eb',
    'Wary': '#fde68a',
    'Cold': '#fed7aa',
    'Resentful': '#fca5a5',
    'Hateful': '#f87171',
    'Hostile': '#ef4444'
};

const typeIcons = {
    // Classic types
    'romantic': '&#x1F49E;',
    'platonic': '&#x1F91D;',
    'familial': '&#x1F46A;',
    'professional': '&#x1F4BC;',
    'rival': '&#x2694;&#xFE0F;',
    'enemy': '&#x1F5E1;&#xFE0F;',
    'neutral': '&#x2796;',
    // Extended types
    'nemesis': '&#x2620;&#xFE0F;',
    'estranged': '&#x1F494;',
    'transactional': '&#x1F4B0;',
    'protective': '&#x1F6E1;&#xFE0F;',
    'indebted': '&#x1FAE0;',
    'fanatical': '&#x1F525;',
    'mentor': '&#x1F4DA;',
    'student': '&#x1F393;',
    'servant': '&#x1F9F9;',
    'client': '&#x1F91D;',
    'patron': '&#x1F4B8;',
    'crush': '&#x1F497;',
    'ex': '&#x1F494;',
    'betrayed': '&#x1F92C;',
    'suspicious': '&#x1F928;',
    'admirer': '&#x2B50;',
    'jealous': '&#x1F4A2;',
    'fearful': '&#x1F628;',
    'obsessed': '&#x1F300;',
    'awed': '&#x1F632;',
    'contempt': '&#x1F624;',
    'pitying': '&#x1F622;',
    'grateful': '&#x1F979;',
    'curious': '&#x1F9D0;',
    'dismissive': '&#x1F611;'
};

function showStatus(msg, color = '#888') {
    const status = document.getElementById('rel-status');
    status.textContent = msg;
    status.style.color = color;
    status.style.display = 'block';
}

function hideStatus() {
    document.getElementById('rel-status').style.display = 'none';
}

function updateRelTier(input) {
    const row = input.closest('tr');
    const tierSpan = row.querySelector('.rel-tier');
    const aff = parseInt(input.value) || 0;
    const tier = getTierLabel(aff);
    tierSpan.textContent = tier;
    tierSpan.style.color = tierColors[tier] || '#e5e7eb';
    syncRelationshipsToHidden();
}

function removeRelRow(btn) {
    const row = btn.closest('tr');
    row.remove();
    syncRelationshipsToHidden();
}

function addRelRow() {
    const target = document.getElementById('new-rel-target').value.trim();
    const aff = parseInt(document.getElementById('new-rel-aff').value) || 0;
    const type = document.getElementById('new-rel-type').value;

    if (!target) {
        alert('Please enter a target name');
        return;
    }

    // Check if target already exists
    const existing = document.querySelector(`.rel-row[data-target="${target}"]`);
    if (existing) {
        alert(`Relationship with ${target} already exists. Edit it in the table above.`);
        return;
    }

    const tier = getTierLabel(aff);
    const tierColor = tierColors[tier] || '#e5e7eb';

    // Create table if it doesn't exist
    let tbody = document.getElementById('rel-tbody');
    if (!tbody) {
        const container = document.getElementById('rel-editor-container');
        const emptyMsg = document.getElementById('rel-empty-msg');
        if (emptyMsg) emptyMsg.remove();

        const tableHtml = `
            <table id="rel-table" style="width:100%; border-collapse:collapse; font-size:0.9em;">
                <thead>
                    <tr style="border-bottom:1px solid #4a4a4a;">
                        <th style="text-align:left; padding:6px; color:#888;">Target</th>
                        <th style="text-align:center; padding:6px; color:#888;">Affinity</th>
                        <th style="text-align:center; padding:6px; color:#888;">Tier</th>
                        <th style="text-align:center; padding:6px; color:#888;">Type</th>
                        <th style="text-align:center; padding:6px; color:#888; width:40px;"></th>
                    </tr>
                </thead>
                <tbody id="rel-tbody"></tbody>
            </table>
        `;
        container.insertAdjacentHTML('afterbegin', tableHtml);
        tbody = document.getElementById('rel-tbody');
    }

    const row = document.createElement('tr');
    row.className = 'rel-row';
    row.dataset.target = target;
    row.dataset.relation = '';
    row.dataset.note = '';
    row.dataset.best = '';
    row.dataset.worst = '';
    row.dataset.bestDelta = '0';
    row.dataset.worstDelta = '0';
    row.dataset.customInfo = '';
    row.style.borderBottom = '1px solid #333';
    row.innerHTML = `
        <td style="padding:8px;">
            <input type="text" class="rel-target" value="${escapeHtml(target)}"
                   style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:4px 8px; width:180px;">
        </td>
        <td style="padding:8px; text-align:center;">
            <input type="number" class="rel-aff" value="${aff}" min="-100" max="100"
                   style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:4px; width:60px; text-align:center;"
                   onchange="updateRelTier(this)">
        </td>
        <td style="padding:8px; text-align:center;">
            <span class="rel-tier" style="color:${tierColor}; font-weight:500;">${tier}</span>
        </td>
        <td style="padding:8px; text-align:center;">
            <select class="rel-type" style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:4px;">
                ${Object.entries(typeIcons).map(([t, icon]) =>
                    `<option value="${t}" ${t === type ? 'selected' : ''}>${icon} ${t.charAt(0).toUpperCase() + t.slice(1)}</option>`
                ).join('')}
            </select>
        </td>
        <td style="padding:8px; text-align:center; white-space:nowrap;">
            <button type="button" class="rel-details" title="Edit details (relation, notes, events)"
                    style="background:transparent; border:none; color:#666; cursor:pointer; font-size:1em; margin-right:4px;"
                    onclick="openDetailsModal(this)">&#x270F;&#xFE0F;</button>
            <button type="button" class="rel-delete" title="Remove relationship"
                    style="background:transparent; border:none; color:#ef4444; cursor:pointer; font-size:1.2em;"
                    onclick="removeRelRow(this)">&times;</button>
        </td>
    `;
    tbody.appendChild(row);

    // Clear inputs
    document.getElementById('new-rel-target').value = '';
    document.getElementById('new-rel-aff').value = '0';
    document.getElementById('new-rel-type').value = 'neutral';

    syncRelationshipsToHidden();
}

// The note is player-authored, so cap it by Unicode characters rather than UTF-16 units.
const CUSTOM_INFO_MAX_CHARS = 1000;

function clampCustomInfo(value) {
    const text = String(value === null || value === undefined ? '' : value);
    const characters = Array.from(text);
    return characters.length > CUSTOM_INFO_MAX_CHARS
        ? characters.slice(0, CUSTOM_INFO_MAX_CHARS).join('')
        : text;
}

function readCustomInfo(entry) {
    if (!entry || typeof entry !== 'object' || typeof entry.custom_info !== 'string') {
        return '';
    }
    return entry.custom_info;
}

// An AI build may rewrite any AI-managed field, but never the player's own note.
function mergeAiRelationships(current, incoming) {
    const merged = { ...(current || {}) };
    const existingNotes = {};
    const existingTargets = {};
    Object.entries(current || {}).forEach(([target, data]) => {
        existingTargets[String(target).trim().toLowerCase()] = target;
        const note = readCustomInfo(data);
        if (note) {
            existingNotes[String(target).trim().toLowerCase()] = note;
        }
    });

    Object.entries(incoming || {}).forEach(([target, data]) => {
        const entry = { ...(data && typeof data === 'object' ? data : {}) };
        const note = existingNotes[String(target).trim().toLowerCase()] || '';
        if (note) {
            entry.custom_info = clampCustomInfo(note);
        } else {
            delete entry.custom_info;
        }
        merged[existingTargets[String(target).trim().toLowerCase()] || target] = entry;
    });

    return merged;
}

function syncRelationshipsToHidden() {
    const rows = document.querySelectorAll('.rel-row');
    const relationships = {};

    rows.forEach(row => {
        const target = row.querySelector('.rel-target').value.trim();
        const aff = parseInt(row.querySelector('.rel-aff').value) || 0;
        const type = row.querySelector('.rel-type').value;

        if (target) {
            const rel = { aff: aff, type: type };

            // Include extended fields if they exist
            const relation = row.dataset.relation || '';
            const note = row.dataset.note || '';
            const best = row.dataset.best || '';
            const worst = row.dataset.worst || '';
            const bestDelta = parseInt(row.dataset.bestDelta) || 0;
            const worstDelta = parseInt(row.dataset.worstDelta) || 0;
            const customInfo = clampCustomInfo(row.dataset.customInfo || '');

            if (relation) rel.relation = relation;
            if (note) rel.note = note;
            if (best) {
                rel.best = best;
                if (bestDelta) rel.best_delta = bestDelta;
            }
            if (worst) {
                rel.worst = worst;
                if (worstDelta) rel.worst_delta = worstDelta;
            }
            // Player-authored: an empty value deliberately clears the note.
            if (customInfo) rel.custom_info = customInfo;

            relationships[target] = rel;
        }
    });

    document.getElementById('relationships_jsonb').value = JSON.stringify(relationships);
}

function getCurrentRelationshipsFromHidden() {
    const hidden = document.getElementById('relationships_jsonb');
    if (!hidden || !hidden.value.trim()) {
        return {};
    }

    try {
        const parsed = JSON.parse(hidden.value);
        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch (e) {
        console.warn('Failed to parse current relationships JSON before AI merge:', e);
        return {};
    }
}

// Details Modal Functions
const relationSuggestions = [
    // Family
    'son', 'daughter', 'father', 'mother', 'brother', 'sister', 'spouse',
    'uncle', 'aunt', 'cousin', 'grandparent', 'in-law', 'stepchild',
    // Professional
    'employer', 'employee', 'apprentice', 'partner', 'supplier', 'client',
    // Social
    'ex-wife', 'ex-husband', 'betrothed', 'ward', 'guardian', 'liege', 'vassal'
];

function showRelationSuggestions() {
    const container = document.getElementById('relation-suggestions');
    if (container.style.display === 'flex') {
        container.style.display = 'none';
        return;
    }

    container.innerHTML = relationSuggestions.map(s =>
        `<button type="button" onclick="selectRelationSuggestion('${s}')"
                 style="background:#262626; border:1px solid #4a4a4a; border-radius:4px; color:#ccc; padding:4px 8px; cursor:pointer; font-size:0.8em;">${s}</button>`
    ).join('');
    container.style.display = 'flex';
}

function selectRelationSuggestion(value) {
    document.getElementById('details-relation').value = value;
    document.getElementById('relation-suggestions').style.display = 'none';
}

function openDetailsModal(btn) {
    const row = btn.closest('tr');
    const target = row.querySelector('.rel-target').value;

    // Populate modal with current values from data attributes
    document.getElementById('details-target-name').textContent = target;
    document.getElementById('details-row-target').value = target;
    document.getElementById('details-relation').value = row.dataset.relation || '';
    document.getElementById('details-note').value = row.dataset.note || '';
    document.getElementById('details-best').value = row.dataset.best || '';
    document.getElementById('details-worst').value = row.dataset.worst || '';
    document.getElementById('details-custom-info').value = row.dataset.customInfo || '';

    // Hide suggestions when opening
    document.getElementById('relation-suggestions').style.display = 'none';

    document.getElementById('rel-details-modal').style.display = 'flex';
}

function closeDetailsModal() {
    document.getElementById('rel-details-modal').style.display = 'none';
}

function saveDetails() {
    const targetName = document.getElementById('details-row-target').value;
    const row = document.querySelector(`.rel-row input.rel-target[value="${targetName}"]`)?.closest('tr');

    if (!row) {
        // Try finding by current target input value
        const rows = document.querySelectorAll('.rel-row');
        for (const r of rows) {
            if (r.querySelector('.rel-target').value === targetName) {
                saveDetailsToRow(r);
                return;
            }
        }
        alert('Could not find the relationship row to update');
        return;
    }

    saveDetailsToRow(row);
}

function saveDetailsToRow(row) {
    // Get values from modal
    const relation = document.getElementById('details-relation').value;
    const note = document.getElementById('details-note').value.trim();
    const best = document.getElementById('details-best').value.trim();
    const worst = document.getElementById('details-worst').value.trim();
    const customInfo = clampCustomInfo(document.getElementById('details-custom-info').value.trim());

    // Update row data attributes
    row.dataset.relation = relation;
    row.dataset.note = note;
    row.dataset.best = best;
    row.dataset.worst = worst;
    row.dataset.customInfo = customInfo;

    // Update the details button color to indicate data exists
    const detailsBtn = row.querySelector('.rel-details');
    const hasData = relation || note || best || worst || customInfo;
    detailsBtn.style.color = hasData ? '#fde68a' : '#666';

    // Sync to hidden field
    syncRelationshipsToHidden();

    closeDetailsModal();
    showStatus('Details saved. Click "Save NPC" to store permanently.', '#86efac');
}

// Modal functions
function openBuildModal() {
    const modal = document.getElementById('build-ai-modal');
    if (!modal) {
        return;
    }
    modal.style.display = 'flex';
}

function closeBuildModal() {
    const modal = document.getElementById('build-ai-modal');
    if (modal) {
        modal.style.display = 'none';
    }
    const directionInput = document.getElementById('build-ai-direction');
    if (directionInput) {
        directionInput.value = '';
    }
}

function openCustomTypeModal() {
    document.getElementById('custom-type-modal').style.display = 'flex';
}

function closeCustomTypeModal() {
    document.getElementById('custom-type-modal').style.display = 'none';
    document.getElementById('custom-type-name').value = '';
    document.getElementById('custom-type-emoji').value = '&#x1F3F7;&#xFE0F;';
    document.getElementById('selected-emoji').textContent = '&#x1F3F7;&#xFE0F;';
}

function selectEmoji(emoji) {
    document.getElementById('custom-type-emoji').value = emoji;
    document.getElementById('selected-emoji').textContent = emoji;
    // Highlight selected
    document.querySelectorAll('.emoji-btn').forEach(btn => {
        btn.style.border = btn.textContent.trim() === emoji ? '2px solid #fde68a' : '1px solid #4a4a4a';
    });
}

function addCustomType() {
    const name = document.getElementById('custom-type-name').value.trim().toLowerCase();
    const emoji = document.getElementById('custom-type-emoji').value;

    if (!name) {
        alert('Please enter a type name');
        return;
    }

    // Validate single word
    if (name.includes(' ')) {
        alert('Type name should be a single word (no spaces)');
        return;
    }

    // Check if already exists
    if (typeIcons[name]) {
        alert(`Type "${name}" already exists`);
        return;
    }

    // Add to typeIcons
    typeIcons[name] = emoji;

    // Update all dropdowns on the page
    document.querySelectorAll('.rel-type, #new-rel-type').forEach(select => {
        const option = document.createElement('option');
        option.value = name;
        option.textContent = `${emoji} ${name.charAt(0).toUpperCase() + name.slice(1)}`;
        select.appendChild(option);
    });

    showStatus(`Added custom type: ${emoji} ${name}`, '#fde68a');
    closeCustomTypeModal();
}

async function buildWithAI() {
    const directionInput = document.getElementById('build-ai-direction');
    const direction = directionInput ? directionInput.value.trim() : '';

    const npcName = document.getElementById('rel-npc-name').value;
    const npcId = document.getElementById('rel-npc-id').value;
    const btn = document.getElementById('btn-build-confirm');
    const originalText = btn ? btn.textContent : 'Build';

    closeBuildModal();

    try {
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Building...';
        }
        document.getElementById('btn-build-ai').disabled = true;
        document.getElementById('btn-build-ai').textContent = 'Building...';
        showStatus('Analyzing recent event history...', '#86efac');

        const formData = new FormData();
        formData.append('npc_id', npcId);
        formData.append('npc_name', npcName);
        formData.append('source', 'events');
        formData.append('event_limit', '200');
        if (direction) {
            formData.append('direction', direction);
        }
        // Send custom types so AI knows about them
        const customTypes = Object.keys(typeIcons).filter(t => !['romantic', 'platonic', 'familial', 'professional', 'rival', 'enemy', 'neutral'].includes(t));
        if (customTypes.length > 0) {
            formData.append('custom_types', JSON.stringify(customTypes));
        }

        const response = await fetch('../ext/relationship_system/analyze_relationships.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.ok && result.relationships) {
            const mergedRelationships = mergeAiRelationships(
                getCurrentRelationshipsFromHidden(),
                result.relationships
            );

            // Update the hidden field
            document.getElementById('relationships_jsonb').value = JSON.stringify(mergedRelationships);

            // Rebuild the table
            rebuildRelTable(mergedRelationships);

            // Show success with model info
            let statusMsg = `AI built ${result.count} relationship(s) from ${result.event_count || 0} event(s)`;
            if (result.model) {
                statusMsg += ` using ${result.model}`;
            }
            statusMsg += `. Matching existing targets may have been overwritten. Click "Save NPC" to store.`;
            showStatus(statusMsg, '#86efac');
        } else {
            showStatus(`Error: ${result.error || 'Unknown error'}`, '#ef4444');
            if (result.raw_response) {
                console.log('Raw AI response:', result.raw_response);
            }
        }
    } catch (e) {
        showStatus(`Request failed: ${e.message}`, '#ef4444');
        console.error('AI build error:', e);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = originalText;
        }
        document.getElementById('btn-build-ai').disabled = false;
        document.getElementById('btn-build-ai').textContent = 'Build with AI';
    }
}

function rebuildRelTable(relationships) {
    const container = document.getElementById('rel-editor-container');

    // Remove old table/message
    const oldTable = document.getElementById('rel-table');
    const oldMsg = document.getElementById('rel-empty-msg');
    if (oldTable) oldTable.remove();
    if (oldMsg) oldMsg.remove();

    if (Object.keys(relationships).length === 0) {
        container.insertAdjacentHTML('afterbegin',
            '<p id="rel-empty-msg" style="color:#666; font-style:italic;">No relationships tracked yet.</p>');
        return;
    }

    let html = `
        <table id="rel-table" style="width:100%; border-collapse:collapse; font-size:0.9em;">
            <thead>
                <tr style="border-bottom:1px solid #4a4a4a;">
                    <th style="text-align:left; padding:6px; color:#888;">Target</th>
                    <th style="text-align:center; padding:6px; color:#888;">Affinity</th>
                    <th style="text-align:center; padding:6px; color:#888;">Tier</th>
                    <th style="text-align:center; padding:6px; color:#888;">Type</th>
                    <th style="text-align:center; padding:6px; color:#888; width:70px;"></th>
                </tr>
            </thead>
            <tbody id="rel-tbody">
    `;

    for (const [target, data] of Object.entries(relationships)) {
        const aff = data.aff || 0;
        const type = data.type || 'neutral';
        const relation = data.relation || '';
        const note = data.note || '';
        const best = data.best || '';
        const worst = data.worst || '';
        const bestDelta = data.best_delta || 0;
        const worstDelta = data.worst_delta || 0;
        const customInfo = readCustomInfo(data);
        const tier = getTierLabel(aff);
        const tierColor = tierColors[tier] || '#e5e7eb';
        const hasExtended = relation || note || best || worst || customInfo;
        const detailsColor = hasExtended ? '#fde68a' : '#666';

        html += `
            <tr class="rel-row" data-target="${escapeHtml(target)}"
                data-relation="${escapeHtml(relation)}"
                data-note="${escapeHtml(note)}"
                data-best="${escapeHtml(best)}"
                data-worst="${escapeHtml(worst)}"
                data-best-delta="${bestDelta}"
                data-worst-delta="${worstDelta}"
                data-custom-info="${escapeHtml(customInfo)}"
                style="border-bottom:1px solid #333;">
                <td style="padding:8px;">
                    <input type="text" class="rel-target" value="${escapeHtml(target)}"
                           style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:4px 8px; width:180px;">
                </td>
                <td style="padding:8px; text-align:center;">
                    <input type="number" class="rel-aff" value="${aff}" min="-100" max="100"
                           style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:4px; width:60px; text-align:center;"
                           onchange="updateRelTier(this)">
                </td>
                <td style="padding:8px; text-align:center;">
                    <span class="rel-tier" style="color:${tierColor}; font-weight:500;">${tier}</span>
                </td>
                <td style="padding:8px; text-align:center;">
                    <select class="rel-type" style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:4px;">
                        ${Object.entries(typeIcons).map(([t, icon]) =>
                            `<option value="${t}" ${t === type ? 'selected' : ''}>${icon} ${t.charAt(0).toUpperCase() + t.slice(1)}</option>`
                        ).join('')}
                    </select>
                </td>
                <td style="padding:8px; text-align:center; white-space:nowrap;">
                    <button type="button" class="rel-details" title="Edit details (relation, notes, events)"
                            style="background:transparent; border:none; color:${detailsColor}; cursor:pointer; font-size:1em; margin-right:4px;"
                            onclick="openDetailsModal(this)">&#x270F;&#xFE0F;</button>
                    <button type="button" class="rel-delete" title="Remove relationship"
                            style="background:transparent; border:none; color:#ef4444; cursor:pointer; font-size:1.2em;"
                            onclick="removeRelRow(this)">&times;</button>
                </td>
            </tr>
        `;
    }

    html += '</tbody></table>';
    container.insertAdjacentHTML('afterbegin', html);
}

function clearAllRelationships() {
    if (!confirm('Are you sure you want to clear all relationships? This will take effect when you save the NPC.')) {
        return;
    }

    document.getElementById('relationships_jsonb').value = '{}';
    rebuildRelTable({});
    hideStatus();
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Sync on any change
document.addEventListener('change', function(e) {
    if (e.target.closest('#relationship-editor-section')) {
        syncRelationshipsToHidden();
    }
});

// Initial sync
syncRelationshipsToHidden();
</script>
