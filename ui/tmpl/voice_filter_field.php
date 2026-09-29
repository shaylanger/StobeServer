<?php

/**
 * Shared renderer for the fixed TTS voice-filter preset control.
 *
 * The preset catalog is owned by the server (lib/tts_filter_presets.php); this
 * template only renders whatever that catalog exposes so option definitions are
 * never duplicated in the UI. Users pick a preset; FFmpeg values stay read-only.
 */

$stobeVoiceFilterLib = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'tts_filter_presets.php';
if (is_file($stobeVoiceFilterLib)) {
    require_once($stobeVoiceFilterLib);
}
unset($stobeVoiceFilterLib);

if (!function_exists('stobeUiVoiceFilterPresets')) {
    /**
     * Server-owned, player-selectable presets keyed by preset id.
     */
    function stobeUiVoiceFilterPresets(): array
    {
        if (function_exists('stobeTtsFilterPresetOptions')) {
            $presets = stobeTtsFilterPresetOptions(true);
            if (is_array($presets) && $presets !== []) {
                return $presets;
            }
        }

        // Backend not installed yet: offer the no-op preset only.
        return [
            'none' => [
                'id' => 'none',
                'label' => 'None (default)',
                'description' => 'No additional filter. Speech uses the voice engine output.',
            ],
        ];
    }
}

if (!function_exists('stobeUiNormalizeVoiceFilterPreset')) {
    /**
     * Resolve a stored/posted preset id against the server catalog.
     *
     * When the catalog is unavailable the raw id is kept (shape-checked only) so
     * a saved preset is never silently downgraded to "none".
     */
    function stobeUiNormalizeVoiceFilterPreset(mixed $value): string
    {
        if (function_exists('stobeNormalizeTtsFilterPresetId')) {
            return strval(stobeNormalizeTtsFilterPresetId($value));
        }

        $id = strtolower(trim(is_scalar($value) ? strval($value) : ''));
        if ($id === '' || preg_match('/^[a-z0-9_]{1,64}$/', $id) !== 1) {
            return 'none';
        }
        if (function_exists('stobeTtsFilterPresetOptions')) {
            $presets = stobeUiVoiceFilterPresets();
            return isset($presets[$id]) ? $id : 'none';
        }

        return $id;
    }
}

if (!function_exists('stobeUiVoiceFilterPresetDescription')) {
    function stobeUiVoiceFilterPresetDescription(string $presetId): string
    {
        $presets = stobeUiVoiceFilterPresets();
        $preset = $presets[$presetId] ?? null;
        return is_array($preset) ? strval($preset['description'] ?? '') : '';
    }
}

if (!function_exists('stobeUiVoiceFilterPreviewEndpoint')) {
    function stobeUiVoiceFilterPreviewEndpoint(string $webRoot): string
    {
        return rtrim($webRoot, '/') . '/ui/api/voice_filter_preview.php';
    }
}

if (!function_exists('stobeUiVoiceFilterStylesheet')) {
    /**
     * Emit the shared stylesheet once per request.
     */
    function stobeUiVoiceFilterStylesheet(string $webRoot): void
    {
        static $emitted = false;
        if ($emitted) {
            return;
        }
        $emitted = true;

        $href = rtrim($webRoot, '/') . '/ui/css/voice-filter.css';
        $cssPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'voice-filter.css';
        if (is_file($cssPath)) {
            $href .= '?v=' . filemtime($cssPath);
        }
        echo '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }
}

if (!function_exists('stobeUiRenderVoiceFilterField')) {
    /**
     * Render the preset dropdown plus its compact sample-playback control.
     *
     * Recognised config keys:
     *   select_id, select_name  - element id / posted field name (required)
     *   selected                - currently stored preset id
     *   web_root                - web root used for the stylesheet and endpoint
     *   endpoint                - preview endpoint override
     *   label, label_class      - optional rendered label; omit to supply your own
     *   aria_label              - accessible name when no rendered label is associated
     *   hint, hint_tag,
     *   hint_class              - static help copy and the element used for hints
     *   variant                 - '' (Stobe orange) or 'gold' (Global Settings)
     *   play_title              - accessible name for the sample button
     *   profile_select_id       - id of a profile select to preview with
     *   voice_input_id          - id of a Voice ID input to preview with
     *   speaker                 - fixed speaker name to preview instead
     */
    function stobeUiRenderVoiceFilterField(array $config): void
    {
        $selectId = trim(strval($config['select_id'] ?? ''));
        if ($selectId === '') {
            return;
        }
        $selectName = trim(strval($config['select_name'] ?? $selectId));
        $webRoot = strval($config['web_root'] ?? '');
        $endpoint = trim(strval($config['endpoint'] ?? '')) !== ''
            ? strval($config['endpoint'])
            : stobeUiVoiceFilterPreviewEndpoint($webRoot);

        $presets = stobeUiVoiceFilterPresets();
        $selected = stobeUiNormalizeVoiceFilterPreset($config['selected'] ?? 'none');
        if (!isset($presets[$selected])) {
            // Keep an unknown stored preset selectable so saving does not drop it.
            $presets = [$selected => ['id' => $selected, 'label' => $selected, 'description' => '']] + $presets;
        }
        $selectedDesc = stobeUiVoiceFilterPresetDescription($selected);

        $hintTag = in_array(strval($config['hint_tag'] ?? 'span'), ['span', 'small', 'div', 'p'], true)
            ? strval($config['hint_tag'])
            : 'span';
        $hintClass = trim(strval($config['hint_class'] ?? 'hint'));
        $hint = trim(strval($config['hint'] ?? ''));
        $label = array_key_exists('label', $config) ? trim(strval($config['label'])) : '';
        $labelClass = trim(strval($config['label_class'] ?? ''));
        $ariaLabel = trim(strval($config['aria_label'] ?? ''));
        $variant = strtolower(trim(strval($config['variant'] ?? '')));
        $playTitle = trim(strval($config['play_title'] ?? 'Play a sample with this voice filter'));

        $hintId = $selectId . '_hint';
        $descId = $selectId . '_desc';
        $statusId = $selectId . '_status';
        $audioId = $selectId . '_audio';
        $playId = $selectId . '_play';

        $describedBy = trim(($hint !== '' ? $hintId . ' ' : '') . $descId);

        $jsConfig = [
            'endpoint' => $endpoint,
            'selectId' => $selectId,
            'playId' => $playId,
            'descId' => $descId,
            'statusId' => $statusId,
            'audioId' => $audioId,
            'profileSelectId' => trim(strval($config['profile_select_id'] ?? '')),
            'voiceInputId' => trim(strval($config['voice_input_id'] ?? '')),
            'speaker' => trim(strval($config['speaker'] ?? '')),
        ];

        stobeUiVoiceFilterStylesheet($webRoot);

        $fieldClass = 'voice-filter-field' . ($variant === 'gold' ? ' voice-filter-field--gold' : '');
        $hintAttr = htmlspecialchars(($hintClass !== '' ? $hintClass . ' ' : ''), ENT_QUOTES, 'UTF-8');
        $openHint = '<' . $hintTag;
        $closeHint = '</' . $hintTag . '>';
        ?>
        <div class="<?= htmlspecialchars($fieldClass, ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($label !== ''): ?>
                <label for="<?= htmlspecialchars($selectId, ENT_QUOTES, 'UTF-8') ?>"<?= $labelClass !== '' ? ' class="' . htmlspecialchars($labelClass, ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></label>
            <?php endif; ?>
            <div class="voice-filter-row">
                <select
                    id="<?= htmlspecialchars($selectId, ENT_QUOTES, 'UTF-8') ?>"
                    name="<?= htmlspecialchars($selectName, ENT_QUOTES, 'UTF-8') ?>"
                    aria-describedby="<?= htmlspecialchars($describedBy, ENT_QUOTES, 'UTF-8') ?>"
                    <?= $ariaLabel !== '' ? 'aria-label="' . htmlspecialchars($ariaLabel, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
                >
                    <?php foreach ($presets as $presetKey => $presetRow): ?>
                        <?php
                            $presetId = strval(is_array($presetRow) ? ($presetRow['id'] ?? $presetKey) : $presetKey);
                            $presetLabel = trim(strval(is_array($presetRow) ? ($presetRow['label'] ?? '') : ''));
                            if ($presetLabel === '') {
                                $presetLabel = $presetId === 'none' ? 'None (default)' : $presetId;
                            }
                            $presetDesc = strval(is_array($presetRow) ? ($presetRow['description'] ?? '') : '');
                        ?>
                        <option
                            value="<?= htmlspecialchars($presetId, ENT_QUOTES, 'UTF-8') ?>"
                            data-filter-desc="<?= htmlspecialchars($presetDesc, ENT_QUOTES, 'UTF-8') ?>"
                            <?= $presetId === $selected ? 'selected' : '' ?>
                        ><?= htmlspecialchars($presetLabel, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <button
                    type="button"
                    id="<?= htmlspecialchars($playId, ENT_QUOTES, 'UTF-8') ?>"
                    class="voice-filter-play"
                    title="<?= htmlspecialchars($playTitle, ENT_QUOTES, 'UTF-8') ?>"
                    aria-label="<?= htmlspecialchars($playTitle, ENT_QUOTES, 'UTF-8') ?>"
                    aria-describedby="<?= htmlspecialchars($statusId, ENT_QUOTES, 'UTF-8') ?>"
                >
                    <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false">
                        <path d="M8.52 2.18 4.93 5.05H2.32a.8.8 0 0 0-.8.8v4.3c0 .44.36.8.8.8h2.61l3.59 2.87a.6.6 0 0 0 .98-.47V2.65a.6.6 0 0 0-.98-.47z"/>
                        <path d="M11.66 5.36a.7.7 0 0 0-.9 1.07 2.03 2.03 0 0 1 0 3.14.7.7 0 0 0 .9 1.07 3.43 3.43 0 0 0 0-5.28z"/>
                    </svg>
                    <span class="voice-filter-spinner" aria-hidden="true"></span>
                </button>
            </div>
            <audio
                id="<?= htmlspecialchars($audioId, ENT_QUOTES, 'UTF-8') ?>"
                class="voice-filter-audio"
                controls
                preload="none"
                aria-label="Voice filter sample"
            ></audio>
            <?php if ($hint !== ''): ?>
                <?= $openHint ?> class="<?= $hintAttr ?>voice-filter-hint" id="<?= htmlspecialchars($hintId, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') ?><?= $closeHint ?>
            <?php endif; ?>
            <?= $openHint ?> class="<?= $hintAttr ?>voice-filter-desc" id="<?= htmlspecialchars($descId, ENT_QUOTES, 'UTF-8') ?>" role="status" aria-live="polite"><?= htmlspecialchars($selectedDesc, ENT_QUOTES, 'UTF-8') ?><?= $closeHint ?>
            <?= $openHint ?> class="<?= $hintAttr ?>voice-filter-status" id="<?= htmlspecialchars($statusId, ENT_QUOTES, 'UTF-8') ?>" role="status" aria-live="polite"><?= $closeHint ?>
        </div>
        <script>
        (function () {
            var cfg = <?= json_encode($jsConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
            var select = document.getElementById(cfg.selectId);
            var playBtn = document.getElementById(cfg.playId);
            var desc = document.getElementById(cfg.descId);
            var statusLine = document.getElementById(cfg.statusId);
            var audio = document.getElementById(cfg.audioId);
            if (!select || !playBtn || !desc || !statusLine || !audio) return;
            if (select.dataset.voiceFilterBound === '1') return;
            select.dataset.voiceFilterBound = '1';

            var profileSelect = cfg.profileSelectId ? document.getElementById(cfg.profileSelectId) : null;
            var voiceInput = cfg.voiceInputId ? document.getElementById(cfg.voiceInputId) : null;
            var previewKey = '';
            var requestToken = 0;
            var pending = false;

            function syncDescription() {
                var option = select.options[select.selectedIndex];
                desc.textContent = option ? (option.getAttribute('data-filter-desc') || '') : '';
            }

            function currentFields() {
                return {
                    profile_id: profileSelect ? String(profileSelect.value || '') : '',
                    voiceid: voiceInput ? String(voiceInput.value || '').trim() : '',
                    speaker: String(cfg.speaker || ''),
                    tts_filter_preset: String(select.value || '')
                };
            }

            function fieldsKey(fields) {
                return JSON.stringify([fields.profile_id, fields.voiceid, fields.speaker, fields.tts_filter_preset]);
            }

            function setStatus(message, isError) {
                statusLine.textContent = message;
                statusLine.classList.toggle('is-error', !!isError);
            }

            function setBusy(busy) {
                pending = busy;
                playBtn.disabled = busy;
                playBtn.classList.toggle('is-loading', busy);
                playBtn.setAttribute('aria-busy', busy ? 'true' : 'false');
            }

            // A generated sample only matches the values it was generated from.
            function discardPreview() {
                try { audio.pause(); } catch (err) {}
                audio.removeAttribute('src');
                audio.classList.remove('is-ready');
                previewKey = '';
                requestToken++;
                setStatus('', false);
            }

            [profileSelect, voiceInput, select].forEach(function (field) {
                if (!field) return;
                field.addEventListener('change', discardPreview);
                field.addEventListener('input', discardPreview);
            });
            select.addEventListener('change', syncDescription);
            syncDescription();

            function playPreview() {
                audio.classList.add('is-ready');
                try { audio.currentTime = 0; } catch (err) {}
                var started = audio.play();
                if (!started || typeof started.then !== 'function') {
                    setStatus('Playing sample.', false);
                    return;
                }
                started.then(function () {
                    setStatus('Playing sample.', false);
                }).catch(function () {
                    if (audio.error) {
                        setStatus('The sample audio could not be played.', true);
                        return;
                    }
                    // Autoplay blocked: the sample is loaded, so the player below can start it.
                    setStatus('Sample ready. Use the player to listen.', false);
                });
            }

            playBtn.addEventListener('click', function () {
                if (pending) return;
                var fields = currentFields();
                if (profileSelect && fields.profile_id === '') {
                    setStatus('Select a profile before playing a sample.', true);
                    profileSelect.focus();
                    return;
                }
                if (voiceInput && fields.voiceid === '') {
                    setStatus('Enter a Voice ID before playing a sample.', true);
                    voiceInput.focus();
                    return;
                }
                if (previewKey !== '' && previewKey === fieldsKey(fields) && audio.getAttribute('src')) {
                    playPreview();
                    return;
                }

                discardPreview();
                var token = ++requestToken;
                setBusy(true);
                setStatus('Generating sample…', false);

                var body = new FormData();
                body.append('tts_filter_preset', fields.tts_filter_preset);
                if (fields.profile_id !== '') body.append('profile_id', fields.profile_id);
                if (fields.voiceid !== '') body.append('voiceid', fields.voiceid);
                if (fields.speaker !== '') body.append('speaker', fields.speaker);

                fetch(cfg.endpoint, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (response) {
                        return response.json().catch(function () {
                            throw new Error('The sample could not be generated. Try again.');
                        }).then(function (data) {
                            if (!response.ok || !data || data.ok !== true || !data.audio_url) {
                                throw new Error((data && data.error) ? String(data.error) : 'The sample could not be generated. Try again.');
                            }
                            return data;
                        });
                    })
                    .then(function (data) {
                        if (token !== requestToken) return;
                        previewKey = fieldsKey(fields);
                        audio.src = String(data.audio_url);
                        playPreview();
                    })
                    .catch(function (err) {
                        if (token !== requestToken) return;
                        previewKey = '';
                        audio.removeAttribute('src');
                        audio.classList.remove('is-ready');
                        setStatus(err && err.message ? err.message : 'The sample could not be generated. Try again.', true);
                    })
                    .then(function () {
                        setBusy(false);
                    });
            });

            audio.addEventListener('error', function () {
                if (audio.getAttribute('src')) {
                    setStatus('The sample audio could not be played.', true);
                }
            });
        })();
        </script>
        <?php
    }
}
