/*
 * Project: Dabing
 * Repository: klusik/dabing
 * File: public/assets/audio-profiles/audio-profile-editor.js
 * Module Type: Browser UI controller
 * Purpose: Operate profile controls, effect preview and audio transport.
 * Responsibilities: Edit ordered effects, graph rendered stages and monitor FFT.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
const root = document.querySelector('[data-profile-editor]');
const frequencies = [60, 180, 500, 1500, 5000, 12000];
const defaults = {
    eq: {bands: frequencies.map((frequency) => ({frequency, gain: 0, q: 1, cutoff: false}))},
    compressor: {threshold: -18, ratio: 3, gain: 0},
    normalize: {target: -3},
    reverb: {mix: 0, decay: 1},
};
const titles = {eq: 'Ekvalizér', compressor: 'Kompresor', normalize: 'Normalizace', reverb: 'Reverb'};
/** Type: number; Units: pixels; Scope: pointer reorder; Consumers: effect list.
 * Rationale: Seven pixels filter finger jitter while preserving responsive drag.
 */
const DRAG_THRESHOLD = 7;
/** Type: number; Units: samples; Scope: FFT; Consumers: monitor analyser.
 * Rationale: 1024 bins balance bass resolution and affordable canvas work.
 */
const FFT_SIZE = 1024;
if (root) {
    loadEditorModules().then(initialize).catch((error) => {
        const status = root.querySelector('[data-editor-status]');
        if (status) status.textContent = error.message;
    });
}

/** @typedef {{analysis:typeof import('./preview-analysis.js'),charts:typeof import('./preview-charts.js'),engine:typeof import('./engine.js')}} EditorModules */

/** Load hash-versioned editor dependencies from same-origin view URLs.
 * @returns {Promise<EditorModules>} Loaded analysis, chart and DSP modules.
 */
async function loadEditorModules() {
    const importAsset = (key) => {
        const path = root.dataset[key];
        if (!path) throw new Error('Chybí adresa modulu zvukového náhledu.');
        return import(new URL(path, document.baseURI).href);
    };
    const [analysis, charts, engine] = await Promise.all([
        importAsset('previewAnalysisSrc'),
        importAsset('previewChartsSrc'),
        importAsset('previewEngineSrc'),
    ]);
    return {
        analysis,
        charts,
        engine,
    };
}

/**
 * Initialize profile controls, ordered effects and optional stored sample.
 * @param {EditorModules} modules - Hash-versioned preview and DSP modules.
 * @returns {void} Installs handlers and renders the initial editor.
 */
function initialize(modules) {
    const {renderProfilePreview} = modules.analysis;
    const {drawEffectPreview, describeEqBand, formatPreviewNumber} = modules.charts;
    const {renderAudioEffects} = modules.engine;
    const state = {
        chain: readChain(root.dataset.chain), blob: null, sourceUrl: '', renderUrl: '',
        audioContext: null, mediaSource: null, analyser: null, peaks: null,
        smoothing: 8, selectedEffect: 0, selectedEqBand: 1,
        previewMode: 'none', stages: null, stale: false, renderToken: 0,
        processing: false, processedActive: false, stoppedByAction: false,
    };
    const list = root.querySelector('[data-effect-list]');
    const panel = root.querySelector('[data-effect-editor]');
    const graph = root.querySelector('[data-effect-preview]');
    const spectrum = root.querySelector('[data-spectrum]');
    const player = root.querySelector('[data-preview-player]');
    const status = root.querySelector('[data-editor-status]');

    /** Ensure supported effects and six EQ bands exist, retaining saved values.
     * @returns {void} Adds bypassed defaults for missing supported effects.
     */
    function ensureEffects() {
        for (const type of Object.keys(defaults)) {
            if (!state.chain.some((effect) => effect.type === type)) {
                state.chain.push({type, enabled: false, settings: structuredClone(defaults[type])});
            }
        }
        const equalizer = state.chain.find((effect) => effect.type === 'eq');
        if (!Array.isArray(equalizer.settings?.bands) || equalizer.settings.bands.length !== 6) {
            equalizer.settings = structuredClone(defaults.eq);
        }
        state.chain = state.chain.filter((effect, index, chain) => (
            Object.hasOwn(defaults, effect.type)
            && chain.findIndex((candidate) => candidate.type === effect.type) === index
        ));
    }

    /** Return the mutable equalizer effect.
     * @returns {Object} Equalizer effect and its current settings.
     */
    function eq() {
        return state.chain.find((effect) => effect.type === 'eq');
    }

    /** Serialize the current chain into its hidden form field.
     * @returns {void} Synchronizes the profile POST payload.
     */
    function syncForm() {
        root.querySelector('[data-chain-input]').value = JSON.stringify(state.chain);
    }

    /** Prevent a previous rendered trace from appearing current after edits.
     * @returns {void} Marks existing preview traces as stale.
     */
    function markPreviewStale() {
        if (state.previewMode === 'processed') state.stale = true;
    }

    /** Draw the selected effect trace at the current audio position.
     * @returns {void} Updates graph and its status caption.
     */
    function drawSelectedPreview() {
        const effect = state.chain[state.selectedEffect];
        if (!effect) return;
        const original = state.previewMode === 'original';
        const stage = state.stages?.get(effect.type) || null;
        const eqBand = effect.type === 'eq'
            ? describeEqBand(effect.settings, state.selectedEqBand)
            : null;
        const bandSummary = root.querySelector('[data-eq-band-summary]');
        bandSummary.hidden = !eqBand;
        bandSummary.textContent = eqBand?.label || '';
        if (eqBand) graph.setAttribute('aria-describedby', 'eq-band-summary');
        else graph.removeAttribute('aria-describedby');
        drawEffectPreview(graph, {
            effect,
            stage,
            time: player.currentTime || 0,
            original,
            eqBand,
        });
        root.querySelector('[data-preview-caption]').textContent = state.stale
            ? 'Poslední poslech · změny čekají na nové spuštění.'
            : state.processing && stage ? 'Poslední poslech · připravuji nový.'
                : original ? 'Křivka originálu'
                    : stage ? 'Naměřeno při posledním poslechu'
                        : 'Načtěte zvuk a spusťte poslech efektů.';
    }

    /** Select one EQ band for graph highlighting and its accessible label.
     * @param {number} index - Selected zero-based EQ band index.
     * @returns {void} Updates selected-band styling and chart annotation.
     */
    function selectEqBand(index) {
        if (!Number.isInteger(index) || index < 0 || index >= 6) return;
        state.selectedEqBand = index;
        root.querySelectorAll('.eq-band').forEach((item, itemIndex) => {
            item.classList.toggle('is-selected-band', itemIndex === index);
        });
        drawSelectedPreview();
    }

    /** Set the primary audition button to its current processed state.
     * @param {boolean} active - Whether a render or processed audition is active.
     * @returns {void} Updates button text and its accessible action name.
     */
    function setProcessedActive(active) {
        state.processedActive = active;
        const button = root.querySelector('[data-play]');
        button.textContent = active ? '■ Zastavit' : '▶ Poslech efektů';
        button.setAttribute(
            'aria-label',
            active ? 'Zastavit efektovaný poslech' : 'Spustit poslech efektů',
        );
    }

    /** Stop an active processed render/playback and invalidate its async work.
     * @returns {void} Freezes the current playhead and preserves measured traces.
     */
    function stopProcessed() {
        if (!state.processedActive && !state.processing) return;
        state.renderToken += 1;
        state.processing = false;
        setProcessedActive(false);
        state.stoppedByAction = true;
        player.pause();
        status.textContent = 'Poslech zastaven.';
        drawSelectedPreview();
    }

    /** Toggle the primary button between processing and immediate stop.
     * @returns {void} Starts a fresh render or cancels active processed work.
     */
    function toggleProcessedPlayback() {
        if (state.processedActive) {
            stopProcessed();
            return;
        }
        playProcessed();
    }

    /** Rebuild chain buttons with selection, bypass and ordering actions.
     * @returns {void} Updates the ordered effect list.
     */
    function renderChain() {
        list.replaceChildren();
        state.chain.forEach((effect, index) => {
            const row = document.createElement('li');
            row.className = `effect-row${effect.enabled ? '' : ' is-disabled'}`
                + (state.selectedEffect === index ? ' is-selected' : '');
            row.dataset.effectIndex = String(index);
            const title = titles[effect.type] || effect.type;
            row.innerHTML = `<button class="effect-move" type="button" aria-label="Pořadí efektu ${escapeHtml(title)}" title="Táhněte nebo použijte šipky">↕</button><button class="effect-name" type="button" aria-current="${state.selectedEffect === index}">${escapeHtml(title)}</button><button class="effect-toggle" type="button" aria-pressed="${Boolean(effect.enabled)}" aria-label="${effect.enabled ? 'Vypnout' : 'Zapnout'} ${escapeHtml(title)}">${effect.enabled ? '●' : '○'}</button>`;
            row.querySelector('.effect-name').addEventListener('click', () => {
                state.selectedEffect = index;
                renderChain();
                edit(index);
            });
            row.querySelector('.effect-toggle').addEventListener('click', () => {
                effect.enabled = !effect.enabled;
                renderChain();
                markPreviewStale();
                drawSelectedPreview();
                syncForm();
            });
            const handle = row.querySelector('.effect-move');
            handle.addEventListener('keydown', (event) => {
                if (!['ArrowUp', 'ArrowDown'].includes(event.key)) return;
                event.preventDefault();
                const target = clamp(index + (event.key === 'ArrowUp' ? -1 : 1), 0, state.chain.length - 1);
                move(index, target);
                list.querySelectorAll('.effect-move')[target]?.focus();
            });
            handle.addEventListener('pointerdown', (event) => {
                handle.dataset.startY = String(event.clientY);
                handle.dataset.dragged = 'false';
                handle.setPointerCapture(event.pointerId);
            });
            handle.addEventListener('pointermove', (event) => {
                if (handle.hasPointerCapture(event.pointerId)
                    && Math.abs(event.clientY - Number(handle.dataset.startY)) > DRAG_THRESHOLD) {
                    handle.dataset.dragged = 'true';
                }
            });
            handle.addEventListener('pointerup', (event) => {
                if (handle.dataset.dragged !== 'true') return;
                const target = document.elementFromPoint(event.clientX, event.clientY)
                    ?.closest('[data-effect-index]');
                if (target && target !== row) move(index, Number(target.dataset.effectIndex));
            });
            list.append(row);
        });
    }

    /** Move a chain entry and preserve which effect is selected.
     * @param {number} from - Current index of the moved effect.
     * @param {number} to - Destination index in the chain.
     * @returns {void} Reorders and rerenders chain and selected panel.
     */
    function move(from, to) {
        if (from === to || to < 0 || to >= state.chain.length) return;
        const selected = state.chain[state.selectedEffect];
        const [item] = state.chain.splice(from, 1);
        state.chain.splice(to, 0, item);
        state.selectedEffect = state.chain.indexOf(selected);
        markPreviewStale();
        renderChain();
        edit(state.selectedEffect);
        syncForm();
    }

    /** Build selected effect settings and replace the primary preview panel.
     * @param {number} index - Selected effect index.
     * @returns {void} Renders the matching graph heading and controls.
     */
    function edit(index) {
        state.selectedEffect = index;
        const effect = state.chain[index];
        if (!effect) return;
        root.querySelector('[data-effect-title]').textContent = titles[effect.type] || effect.type;
        root.querySelector('[data-curve-legend]').textContent = effect.type === 'eq'
            ? 'Nastavená odezva'
            : effect.type === 'compressor' ? 'Práh / poměr' : 'Nastavená křivka';
        panel.replaceChildren();
        const hint = document.createElement('p');
        hint.className = 'control-hint';
        hint.textContent = 'Táhněte ovladačem, použijte šipky nebo zadejte číslo.';
        panel.append(hint);
        if (effect.type === 'eq') {
            renderEq(panel, effect.settings);
        } else if (effect.type === 'compressor') {
            rotary(panel, effect.settings, 'threshold', 'Práh', -60, 0, false, ' dB');
            rotary(panel, effect.settings, 'ratio', 'Poměr', 1, 20, false, ' : 1');
            slider(panel, effect.settings, 'Make-up', 'gain', 0, 24, 0.5, ' dB');
        } else if (effect.type === 'normalize') {
            slider(panel, effect.settings, 'Cílová úroveň', 'target', -24, 0, 0.5, ' dBFS');
        } else if (effect.type === 'reverb') {
            rotary(panel, effect.settings, 'mix', 'Wet', 0, 100, false, ' %');
            rotary(panel, effect.settings, 'decay', 'Dozvuk', 0.1, 3, true, ' s');
        }
        drawSelectedPreview();
    }

    /** Create a knob, numeric field and accessible output for one setting.
     * @param {HTMLElement} parent - Effect controls container.
     * @param {Object} settings - Mutable effect settings.
     * @param {string} key - Property edited by the control.
     * @param {string} label - Visible control label.
     * @param {number} minimum - Inclusive lower bound.
     * @param {number} maximum - Inclusive upper bound.
     * @param {boolean} logarithmic - Whether pointer movement is logarithmic.
     * @param {string} unit - Value unit shown to the user.
     * @returns {void} Adds pointer, keyboard and direct-entry editing.
     */
    function rotary(parent, settings, key, label, minimum, maximum, logarithmic, unit) {
        const field = document.createElement('div');
        field.className = 'effect-control';
        field.innerHTML = `<span class="control-label">${escapeHtml(label)}</span><span class="effect-control-row"><button type="button" class="eq-knob" aria-label="${escapeHtml(label)}"></button><input class="effect-number" type="text" inputmode="decimal" aria-label="${escapeHtml(label)} číselně"><span class="control-unit">${escapeHtml(unit)}</span></span>`;
        const knob = field.querySelector('.eq-knob');
        const input = field.querySelector('.effect-number');
        const paint = () => {
            const shown = formatPreviewNumber(settings[key]);
            const position = logarithmic
                ? Math.log(settings[key] / minimum) / Math.log(maximum / minimum)
                : (settings[key] - minimum) / (maximum - minimum);
            knob.textContent = '';
            knob.style.setProperty('--angle', `${-135 + 270 * position}deg`);
            knob.setAttribute('role', 'slider');
            knob.setAttribute('aria-valuemin', String(minimum));
            knob.setAttribute('aria-valuemax', String(maximum));
            knob.setAttribute('aria-valuenow', String(settings[key]));
            knob.setAttribute('aria-valuetext', `${shown}${unit}`);
            input.value = shown;
        };
        const commit = (value) => {
            settings[key] = value;
            paint();
            markPreviewStale();
            drawSelectedPreview();
            syncForm();
        };
        knob.tabIndex = 0;
        knob.addEventListener('keydown', (event) => {
            if (event.key === 'Home' || event.key === 'End') {
                event.preventDefault();
                commit(event.key === 'Home' ? minimum : maximum);
                return;
            }
            if (!['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(event.key)) return;
            event.preventDefault();
            const direction = ['ArrowUp', 'ArrowRight'].includes(event.key) ? 1 : -1;
            const amount = logarithmic
                ? settings[key] * (event.shiftKey ? 0.12 : 0.025)
                : (maximum - minimum) * (event.shiftKey ? 0.02 : 0.005);
            commit(clamp(settings[key] + direction * amount, minimum, maximum));
        });
        let startY = 0;
        let initial = 0;
        knob.addEventListener('pointerdown', (event) => {
            startY = event.clientY;
            initial = settings[key];
            knob.setPointerCapture(event.pointerId);
        });
        knob.addEventListener('pointermove', (event) => {
            if (!knob.hasPointerCapture(event.pointerId)) return;
            const delta = (startY - event.clientY) / 120;
            const value = logarithmic
                ? initial * (maximum / minimum) ** delta
                : initial + delta * (maximum - minimum);
            commit(clamp(value, minimum, maximum));
        });
        input.addEventListener('change', () => {
            const parsed = parseNumber(input.value);
            if (parsed === null) {
                paint();
                return;
            }
            commit(clamp(parsed, minimum, maximum));
            knob.focus();
        });
        paint();
        parent.append(field);
    }

    /** Render EQ faders, frequency/Q knobs and edge switches.
     * @param {HTMLElement} parent - Selected effect settings panel.
     * @param {Object} settings - Mutable equalizer settings.
     * @returns {void} Adds editable controls for each EQ band.
     */
    function renderEq(parent, settings) {
        const bands = document.createElement('div');
        bands.className = 'eq-bands';
        settings.bands.forEach((band, index) => {
            const item = document.createElement('div');
            item.className = 'eq-band';
            item.innerHTML = `<span class="eq-band-name">${index === 0 ? 'LOW' : index === 5 ? 'HIGH' : `B${index + 1}`}</span><label class="eq-gain-label">Zisk<input class="eq-gain" type="range" min="-18" max="18" step="0.5" value="${band.gain}" aria-label="Zisk pásma ${index + 1}"><output class="eq-gain-value"></output></label><div class="eq-knob-control"><span>Frekvence</span><span class="eq-knob-row"><button type="button" class="eq-knob" data-knob="frequency" aria-label="Frekvence pásma ${index + 1}"></button></span></div><div class="eq-knob-control"><span>Šířka Q</span><span class="eq-knob-row"><button type="button" class="eq-knob" data-knob="q" aria-label="Šířka Q pásma ${index + 1}"></button></span></div>`;
            item.classList.toggle('is-selected-band', state.selectedEqBand === index);
            item.addEventListener('focusin', () => selectEqBand(index));
            item.addEventListener('pointerdown', () => selectEqBand(index));
            const gain = item.querySelector('.eq-gain');
            const output = item.querySelector('.eq-gain-value');
            output.value = `${formatPreviewNumber(band.gain)} dB`;
            gain.setAttribute('aria-valuetext', output.value);
            gain.addEventListener('input', () => {
                band.gain = Number(gain.value);
                output.value = `${formatPreviewNumber(band.gain)} dB`;
                gain.setAttribute('aria-valuetext', output.value);
                markPreviewStale();
                drawSelectedPreview();
                syncForm();
            });
            bindKnob(item.querySelector('[data-knob="frequency"]'), band, 'frequency', 20, 20000, true, ' Hz');
            bindKnob(item.querySelector('[data-knob="q"]'), band, 'q', 0.2, 8, false, '');
            bands.append(item);
        });
        parent.append(bands);
        const edges = document.createElement('div');
        edges.className = 'eq-edges';
        edges.innerHTML = '<label><input type="checkbox" data-edge="highpass"> High-pass</label><label><input type="checkbox" data-edge="lowpass"> Low-pass</label>';
        edges.querySelector('[data-edge="highpass"]').checked = Boolean(settings.bands[0].cutoff);
        edges.querySelector('[data-edge="lowpass"]').checked = Boolean(settings.bands[5].cutoff);
        edges.querySelectorAll('[data-edge]').forEach((toggle) => toggle.addEventListener('change', () => {
            const bandIndex = toggle.dataset.edge === 'highpass' ? 0 : 5;
            selectEqBand(bandIndex);
            settings.bands[bandIndex].cutoff = toggle.checked;
            markPreviewStale();
            drawSelectedPreview();
            syncForm();
        }));
        parent.append(edges);
    }

    /** Bind an EQ rotary button to pointer, keyboard and numeric entry.
     * @param {HTMLButtonElement} knob - Rotary control.
     * @param {Object} band - Mutable band settings.
     * @param {string} key - Edited setting name.
     * @param {number} minimum - Inclusive lower bound.
     * @param {number} maximum - Inclusive upper bound.
     * @param {boolean} logarithmic - Whether drag movement is logarithmic.
     * @param {string} unit - Visible unit suffix.
     * @returns {void} Updates the band and its accessible display.
     */
    function bindKnob(knob, band, key, minimum, maximum, logarithmic, unit) {
        const label = knob.parentElement;
        const input = document.createElement('input');
        input.className = 'eq-number';
        input.type = 'text';
        input.inputMode = 'decimal';
        input.setAttribute('aria-label', `${knob.getAttribute('aria-label')} číselně`);
        label.append(input);
        let unitLabel = null;
        if (key === 'frequency') {
            unitLabel = document.createElement('span');
            unitLabel.className = 'eq-unit';
            label.append(unitLabel);
        }
        const paint = () => {
            const formatted = formatPreviewNumber(band[key]);
            const largeFrequency = key === 'frequency' && band[key] >= 1000;
            const editableValue = largeFrequency
                ? formatPreviewNumber(band[key] / 1000)
                : formatted;
            const aria = largeFrequency
                ? `${editableValue} kHz`
                : `${formatted}${unit}`;
            knob.textContent = '';
            const position = logarithmic
                ? Math.log(band[key] / minimum) / Math.log(maximum / minimum)
                : (band[key] - minimum) / (maximum - minimum);
            knob.style.setProperty('--angle', `${-135 + 270 * position}deg`);
            input.value = editableValue;
            if (unitLabel) unitLabel.textContent = largeFrequency ? 'kHz' : 'Hz';
            knob.setAttribute('role', 'slider');
            knob.setAttribute('aria-valuemin', String(minimum));
            knob.setAttribute('aria-valuemax', String(maximum));
            knob.setAttribute('aria-valuenow', String(band[key]));
            knob.setAttribute('aria-valuetext', aria);
            knob.title = `${aria} · táhněte nebo použijte šipky`;
        };
        const commit = (value) => {
            band[key] = value;
            paint();
            markPreviewStale();
            drawSelectedPreview();
            syncForm();
        };
        knob.tabIndex = 0;
        knob.addEventListener('keydown', (event) => {
            if (event.key === 'Home' || event.key === 'End') {
                event.preventDefault();
                commit(event.key === 'Home' ? minimum : maximum);
                return;
            }
            if (!['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(event.key)) return;
            event.preventDefault();
            const direction = ['ArrowUp', 'ArrowRight'].includes(event.key) ? 1 : -1;
            const amount = logarithmic
                ? band[key] * (event.shiftKey ? 0.12 : 0.025)
                : (maximum - minimum) * (event.shiftKey ? 0.02 : 0.005);
            commit(clamp(band[key] + direction * amount, minimum, maximum));
        });
        let y = 0;
        let initial = 0;
        knob.addEventListener('pointerdown', (event) => {
            y = event.clientY;
            initial = band[key];
            knob.setPointerCapture(event.pointerId);
        });
        knob.addEventListener('pointermove', (event) => {
            if (!knob.hasPointerCapture(event.pointerId)) return;
            const delta = (y - event.clientY) / 120;
            const value = logarithmic
                ? initial * (maximum / minimum) ** delta
                : initial + delta * (maximum - minimum);
            commit(clamp(value, minimum, maximum));
        });
        input.addEventListener('change', () => {
            const parsed = key === 'frequency'
                ? parseFrequency(input.value, band[key] >= 1000)
                : parseNumber(input.value);
            if (parsed === null) {
                paint();
                return;
            }
            commit(clamp(parsed, minimum, maximum));
            knob.focus();
        });
        paint();
    }

    /** Create a horizontal fader and formatted output for a gain setting.
     * @param {HTMLElement} parent - Effect controls container.
     * @param {Object} settings - Mutable settings object.
     * @param {string} label - Visible control label.
     * @param {string} key - Edited property.
     * @param {number} minimum - Inclusive lower bound.
     * @param {number} maximum - Inclusive upper bound.
     * @param {number} step - Fader increment.
     * @param {string} unit - Output suffix.
     * @returns {void} Adds the fader and keeps serialized settings current.
     */
    function slider(parent, settings, label, key, minimum, maximum, step, unit) {
        const field = document.createElement('label');
        field.className = 'effect-fader';
        field.innerHTML = `<span>${escapeHtml(label)}</span><input type="range" min="${minimum}" max="${maximum}" step="${step}" value="${settings[key]}"><output></output>`;
        const input = field.querySelector('input');
        const output = field.querySelector('output');
        const paint = () => {
            output.value = `${formatPreviewNumber(settings[key])}${unit}`;
            input.setAttribute('aria-valuetext', output.value);
        };
        input.addEventListener('input', () => {
            settings[key] = Number(input.value);
            paint();
            markPreviewStale();
            drawSelectedPreview();
            syncForm();
        });
        paint();
        parent.append(field);
    }

    /** Replace old preview stage after the user requests a processed audition.
     * @returns {Promise<void>} Analyzes and starts processed playback.
     */
    async function playProcessed() {
        if (!state.blob) return;
        const token = ++state.renderToken;
        const chainSnapshot = structuredClone(state.chain);
        const chainJson = JSON.stringify(chainSnapshot);
        state.processing = true;
        state.stoppedByAction = false;
        setProcessedActive(true);
        player.pause();
        status.textContent = 'Zpracovávám ukázku…';
        drawSelectedPreview();
        try {
            const result = await renderProfilePreview(
                state.blob,
                chainSnapshot,
                renderAudioEffects,
            );
            if (token !== state.renderToken) return;
            state.stages = result.stages;
            state.stale = JSON.stringify(state.chain) !== chainJson;
            if (state.renderUrl) URL.revokeObjectURL(state.renderUrl);
            state.renderUrl = URL.createObjectURL(wav(result.audio));
            state.previewMode = 'processed';
            player.src = state.renderUrl;
            player.currentTime = 0;
            drawSelectedPreview();
            const started = await playAndAnalyze(token);
            if (!started || token !== state.renderToken) return;
            state.processing = false;
            drawSelectedPreview();
            status.textContent = 'Přehrává se efektovaný zvuk.';
        } catch (error) {
            if (token === state.renderToken) {
                state.processing = false;
                setProcessedActive(false);
                status.textContent = error.message;
                drawSelectedPreview();
            }
        }
    }

    /** Analyze a bypassed chain and play the original source object URL.
     * @returns {Promise<void>} Resolves when original playback starts.
     */
    async function playOriginal() {
        if (!state.sourceUrl || !state.blob) return;
        const token = ++state.renderToken;
        const chainSnapshot = structuredClone(state.chain);
        state.processing = true;
        state.stoppedByAction = false;
        setProcessedActive(false);
        player.pause();
        status.textContent = 'Připravuji originální průběhy…';
        drawSelectedPreview();
        try {
            const bypassed = chainSnapshot.map((effect) => ({...effect, enabled: false}));
            const result = await renderProfilePreview(
                state.blob,
                bypassed,
                renderAudioEffects,
            );
            if (token !== state.renderToken) return;
            state.stages = result.stages;
            state.previewMode = 'original';
            state.stale = false;
            player.src = state.sourceUrl;
            player.currentTime = 0;
            drawSelectedPreview();
            const started = await playAndAnalyze(token);
            if (!started || token !== state.renderToken) return;
            state.processing = false;
            drawSelectedPreview();
            status.textContent = 'Přehrává se originál.';
        } catch (error) {
            if (token === state.renderToken) {
                state.processing = false;
                status.textContent = error.message;
                drawSelectedPreview();
            }
        }
    }

    /** Connect the analyser and play the selected player source.
     * @param {number} token - Current render transaction token.
     * @returns {Promise<boolean>} Whether this transaction started playback.
     */
    async function playAndAnalyze(token) {
        if (state.spectrumFrame) cancelAnimationFrame(state.spectrumFrame);
        if (!state.audioContext) state.audioContext = new AudioContext();
        if (!state.mediaSource) state.mediaSource = state.audioContext.createMediaElementSource(player);
        if (!state.analyser) {
            state.analyser = state.audioContext.createAnalyser();
            state.analyser.fftSize = FFT_SIZE;
            state.mediaSource.connect(state.analyser);
            state.analyser.connect(state.audioContext.destination);
        }
        await state.audioContext.resume();
        if (token !== state.renderToken) return false;
        const source = player.src;
        await player.play();
        if (token !== state.renderToken || player.src !== source) {
            return false;
        }
        drawSelectedPreview();
        return true;
    }

    /** Encode an AudioBuffer as interleaved 16-bit PCM WAV.
     * @param {AudioBuffer} buffer - Audio buffer to encode.
     * @returns {Blob} WAV blob for browser playback.
     */
    function wav(buffer) {
        const channels = buffer.numberOfChannels;
        const frames = buffer.length;
        const bytes = 2;
        const view = new DataView(new ArrayBuffer(44 + frames * channels * bytes));
        const write = (offset, value) => {
            for (let index = 0; index < value.length; index += 1) {
                view.setUint8(offset + index, value.charCodeAt(index));
            }
        };
        write(0, 'RIFF');
        view.setUint32(4, view.byteLength - 8, true);
        write(8, 'WAVE');
        write(12, 'fmt ');
        view.setUint32(16, 16, true);
        view.setUint16(20, 1, true);
        view.setUint16(22, channels, true);
        view.setUint32(24, buffer.sampleRate, true);
        view.setUint32(28, buffer.sampleRate * channels * bytes, true);
        view.setUint16(32, channels * bytes, true);
        view.setUint16(34, 16, true);
        write(36, 'data');
        view.setUint32(40, frames * channels * bytes, true);
        for (let frame = 0; frame < frames; frame += 1) {
            for (let channel = 0; channel < channels; channel += 1) {
                const sample = clamp(buffer.getChannelData(channel)[frame], -1, 1);
                view.setInt16(
                    44 + (frame * channels + channel) * bytes,
                    sample < 0 ? sample * 32768 : sample * 32767,
                    true,
                );
            }
        }
        return new Blob([view], {type: 'audio/wav'});
    }

    /** Load the authenticated sample into a local object URL.
     * @param {string} url - Controller-approved sample URL.
     * @returns {Promise<void>} Loads the sample or reports visible failure.
     */
    async function loadSample(url) {
        const token = ++state.renderToken;
        status.textContent = 'Načítám ukázku…';
        try {
            const response = await fetch(url, {credentials: 'same-origin'});
            if (!response.ok) throw new Error('Ukázku se nepodařilo načíst.');
            const blob = await response.blob();
            if (token !== state.renderToken) return;
            state.blob = blob;
            useSource(URL.createObjectURL(blob));
            root.querySelector('[data-file-name]').textContent ||= 'Ukázka nahrávky';
            enableMonitor();
            status.textContent = 'Ukázka připravena.';
        } catch (error) {
            if (token === state.renderToken) status.textContent = error.message;
        }
    }

    /** Validate and select a local audio file for private preview.
     * @param {Event} event - File input change event.
     * @returns {void} Selects a file or displays a validation message.
     */
    function loadFile(event) {
        const file = event.currentTarget.files?.[0];
        if (!file) return;
        if (!file.type.startsWith('audio/')) {
            status.textContent = 'Vyberte zvukový soubor.';
            return;
        }
        if (file.size > 20971520) {
            status.textContent = 'Soubor překračuje limit 20 MiB.';
            return;
        }
        ++state.renderToken;
        state.blob = file;
        useSource(URL.createObjectURL(file));
        root.querySelector('[data-file-name]').textContent = file.name;
        enableMonitor();
        status.textContent = 'Místní soubor připraven.';
    }

    /** Replace the source and invalidate previous preview data.
     * @param {string} url - Newly created source object URL.
     * @returns {void} Sets the audio player to the new source.
     */
    function useSource(url) {
        state.processing = false;
        state.stoppedByAction = true;
        setProcessedActive(false);
        player.pause();
        if (state.sourceUrl) URL.revokeObjectURL(state.sourceUrl);
        if (state.renderUrl) URL.revokeObjectURL(state.renderUrl);
        state.sourceUrl = url;
        state.renderUrl = '';
        state.previewMode = 'none';
        state.stages = null;
        state.stale = false;
        player.src = url;
        drawSelectedPreview();
    }

    /** Enable play actions after source selection.
     * @returns {void} Activates both monitor buttons.
     */
    function enableMonitor() {
        root.querySelectorAll('[data-play], [data-play-original]').forEach((button) => {
            button.disabled = false;
        });
    }

    /** Bind monitor smoothing fader and its compact value output.
     * @returns {void} Updates smoothing during playback.
     */
    function bindSmoothing() {
        const input = root.querySelector('[data-smoothing]');
        const output = root.querySelector('[data-smoothing-value]');
        input.addEventListener('input', () => {
            state.smoothing = Number(input.value);
            output.value = formatPreviewNumber(state.smoothing);
        });
    }

    /** Paint live FFT bins and maintain a decaying peak envelope.
     * @returns {void} Updates the spectrum monitor during playback.
     */
    function drawSpectrum() {
        const context = canvasContext(spectrum);
        const analyser = state.analyser;
        if (!context || !analyser) return;
        const bins = new Uint8Array(analyser.frequencyBinCount);
        analyser.getByteFrequencyData(bins);
        if (!state.peaks || state.peaks.length !== bins.length) {
            state.peaks = new Float32Array(bins.length);
        }
        const width = spectrum.clientWidth / bins.length;
        bins.forEach((value, index) => {
            state.peaks[index] = Math.max(value, state.peaks[index] * (1 - 1 / state.smoothing));
            const y = spectrum.clientHeight * (1 - state.peaks[index] / 255);
            context.fillStyle = `hsl(${95 - index / bins.length * 55} 52% 62%)`;
            context.fillRect(index * width, y, Math.max(1, width), spectrum.clientHeight - y);
        });
        if (!player.paused) state.spectrumFrame = requestAnimationFrame(drawSpectrum);
    }

    /** Create a scaled context for a visible canvas.
     * @param {HTMLCanvasElement} canvas - Graph canvas.
     * @returns {CanvasRenderingContext2D|null} Context, or null while hidden.
     */
    function canvasContext(canvas) {
        const rect = canvas.getBoundingClientRect();
        if (!rect.width || !rect.height) return null;
        const scale = Math.min(2, devicePixelRatio || 1);
        const width = Math.round(rect.width * scale);
        const height = Math.round(rect.height * scale);
        if (canvas.width !== width || canvas.height !== height) {
            canvas.width = width;
            canvas.height = height;
        }
        const context = canvas.getContext('2d');
        context.setTransform(scale, 0, 0, scale, 0, 0);
        context.fillStyle = '#253128';
        context.fillRect(0, 0, rect.width, rect.height);
        return context;
    }

    /** Bind page controls, player events and initial editor contents.
     * @returns {void} Completes initial setup and loads an optional sample.
     */
    function bindPage() {
        root.querySelector('[data-new-profile]').addEventListener('click', newProfile);
        root.querySelector('[data-rename-profile]').addEventListener('click', () => {
            const name = root.querySelector('#profile-name');
            name.focus();
            name.select();
        });
        root.querySelector('[data-profile-select]').addEventListener('change', (event) => {
            const url = new URL(location.href);
            if (event.currentTarget.value) url.searchParams.set('id', event.currentTarget.value);
            else url.searchParams.delete('id');
            location.assign(url);
        });
        root.querySelector('[data-local-audio]').addEventListener('change', loadFile);
        root.querySelector('[data-play]').addEventListener('click', toggleProcessedPlayback);
        root.querySelector('[data-play-original]').addEventListener('click', playOriginal);
        root.querySelector('[data-chain-input]').form.addEventListener('submit', syncForm);
        player.addEventListener('timeupdate', drawSelectedPreview);
        player.addEventListener('seeked', drawSelectedPreview);
        player.addEventListener('pause', () => {
            drawSelectedPreview();
            if (player.paused && !player.ended && !state.processing && !state.stoppedByAction
                && state.previewMode !== 'none') {
                if (state.previewMode === 'processed') setProcessedActive(false);
                status.textContent = 'Poslech pozastaven.';
            }
        });
        player.addEventListener('play', () => {
            if (player.paused) return;
            state.stoppedByAction = false;
            if (state.previewMode === 'processed') setProcessedActive(true);
            drawSelectedPreview();
            if (state.previewMode !== 'none') {
                status.textContent = state.previewMode === 'original'
                    ? 'Přehrává se originál.'
                    : 'Přehrává se efektovaný zvuk.';
            }
        });
        player.addEventListener('playing', () => {
            if (player.paused) return;
            if (state.spectrumFrame) cancelAnimationFrame(state.spectrumFrame);
            drawSpectrum();
        });
        player.addEventListener('ended', () => {
            if (state.processing) return;
            state.processing = false;
            state.stoppedByAction = false;
            setProcessedActive(false);
            status.textContent = 'Poslech ukončen.';
            drawSelectedPreview();
        });
        addEventListener('resize', drawSelectedPreview);
        bindSmoothing();
        if (root.dataset.sampleUrl) loadSample(root.dataset.sampleUrl);
    }

    /** Reset editor identity, controls and profile URL for new profile creation.
     * @returns {void} Clears current identity while retaining normal defaults.
     */
    function newProfile() {
        root.querySelector('[data-profile-id]').value = '';
        root.querySelector('#profile-name').value = '';
        root.querySelector('[data-profile-select]').value = '';
        const renameButton = root.querySelector('[data-rename-profile]');
        renameButton.hidden = true;
        renameButton.disabled = true;
        markPreviewStale();
        state.chain = [];
        state.selectedEffect = 0;
        ensureEffects();
        renderChain();
        edit(0);
        syncForm();
        const url = new URL(location.href);
        url.searchParams.delete('id');
        history.replaceState({}, '', url);
    }

    ensureEffects();
    renderChain();
    edit(0);
    syncForm();
    bindPage();
}

/** Parse serialized view data and return an empty list if malformed.
 * @param {string|undefined} value - Serialized chain JSON.
 * @returns {Array<Object>} Effect objects contained in the valid input.
 */
function readChain(value) {
    try {
        const parsed = JSON.parse(value || '[]');
        return Array.isArray(parsed) ? parsed.filter((item) => item && typeof item === 'object') : [];
    } catch {
        return [];
    }
}

/** Clamp a proposed value to finite inclusive limits.
 * @param {number} value - Proposed numeric value.
 * @param {number} minimum - Inclusive minimum.
 * @param {number} maximum - Inclusive maximum.
 * @returns {number} Bounded finite number.
 */
function clamp(value, minimum, maximum) {
    return Math.min(maximum, Math.max(minimum, Number(value) || 0));
}

/** Parse direct-entry numbers with either decimal separator.
 * @param {string} value - User-entered number text.
 * @returns {number|null} Finite number or null for invalid input.
 */
function parseNumber(value) {
    const normalized = value.trim().replace(',', '.');
    if (!normalized || !/^-?(?:\d+\.?\d*|\.\d+)$/.test(normalized)) return null;
    const parsed = Number(normalized);
    return Number.isFinite(parsed) ? parsed : null;
}

/** Parse an EQ frequency, using the visible unit when none is typed.
 * @param {string} value - Number with an optional Hz or kHz suffix.
 * @param {boolean} assumeKilohertz - Whether a suffix-free value uses kHz.
 * @returns {number|null} Frequency in hertz, or null for invalid input.
 */
function parseFrequency(value, assumeKilohertz) {
    const match = value.trim().match(/^(-?(?:\d+[.,]?\d*|[.,]\d+))\s*(khz|hz)?$/i);
    if (!match) return null;
    const number = Number(match[1].replace(',', '.'));
    if (!Number.isFinite(number)) return null;
    const suffix = (match[2] || '').toLowerCase();
    return suffix === 'khz' || (!suffix && assumeKilohertz)
        ? number * 1000
        : number;
}

/** Escape untrusted text before inserting it into editor markup.
 * @param {string} value - Plain text.
 * @returns {string} Safe HTML text.
 */
function escapeHtml(value) {
    return value.replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[char]));
}
