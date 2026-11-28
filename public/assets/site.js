/*
 * Project: Dabing
 * Repository: klusik/dabing
 * File: public/assets/site.js
 * Module Type: Browser interaction
 * Purpose: Add accessible panels, script helpers and explicit audio capture.
 * Responsibilities: Manage invitations, sample import, microphone lifecycle and clip uploads.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
'use strict';

/**
 * Recording limit policy.
 * Type: Integer
 * Units: Seconds
 * Scope: One in-browser microphone take
 * Consumers: Recording stop timer and user-facing status
 * Rationale: Keep a single take focused and limit accidental long recordings.
 * Value: 180 seconds; long chapters should be split into short lines.
 */
const MAX_RECORDING_SECONDS = 180;

/**
 * MP3 sample-rate policy.
 * Type: Integer
 * Units: Samples per second
 * Scope: Every uploaded take
 * Consumers: OfflineAudioContext resampling and encoder worker
 * Rationale: Keep one predictable mono sample format for MP3 encoding.
 * Value: 44100 samples per second.
 */
const MP3_SAMPLE_RATE = 44100;
const MP3_WORKER_PATH = './public/assets/mp3-worker.js';
let activeCaptureCard = null;
let interruptedCaptureCard = null;
let savedPageNeedsRefresh = false;
const boundPanelTriggers = new WeakSet();
const boundRecorders = new WeakSet();
const playlistStates = new WeakMap();
/** @type {WeakMap<HTMLElement,{url:string,historyMode:'push'|'replace'|'none',force:boolean}>} */
const queuedProjectRefreshes = new WeakMap();

/**
 * Bound revision polling for one visible book workspace.
 * Type: number.
 * Units: milliseconds.
 * Scope: one visible authenticated project view.
 * Consumers: the non-overlapping project revision poller.
 * Rationale: detect shared changes promptly without frequent HTML transfers.
 * Value: 1800 milliseconds between completed revision checks.
 */
const PROJECT_POLL_INTERVAL_MS = 1800;

/**
 * Take-token entropy policy.
 * Type: Integer
 * Units: Random bytes
 * Scope: One new audio capture or selected file
 * Consumers: Stable upload deduplication token generation
 * Rationale: Provide 128 bits of token entropy across explicit retries.
 * Value: 16 bytes, rendered as 32 lowercase hexadecimal characters.
 */
const TAKE_TOKEN_BYTES = 16;

/**
 * Decibel conversion policy.
 * Type: Number
 * Units: Decibels per natural logarithm unit
 * Scope: RMS microphone level display
 * Consumers: dBFS conversion
 * Rationale: Convert amplitude ratios with 20 × log10 to decibels.
 * Value: 20.
 */
const AMPLITUDE_DECIBEL_FACTOR = 20;

/**
 * Meter maximum policy.
 * Type: Number
 * Units: Decibels relative to full scale
 * Scope: Live microphone meter
 * Consumers: dBFS clamping
 * Rationale: Digital full scale is the upper measurement boundary.
 * Value: 0 dBFS.
 */
const METER_MAX_DBFS = 0;

/**
 * Lock microphone and file controls to one line until save or discard.
 * @param {?HTMLElement} card - Active line, or null to release the page lock.
 * @param {?HTMLInputElement} exemptControl - File input opening its picker.
 * @returns {void} Other lines cannot begin overlapping captures or uploads.
 */
function setCaptureOwner(card, exemptControl = null) {
    activeCaptureCard = card;
    document.querySelectorAll('[data-record-start], [data-audio-file]').forEach((control) => {
        control.disabled = card !== null
            && (!card.contains(control) || control !== exemptControl);
    });
}

/**
 * Create one stable deduplication token for a new captured or selected take.
 * @returns {string} A 32-character lowercase hexadecimal token.
 */
function createTakeToken() {
    if (!window.crypto?.getRandomValues) {
        throw new Error('Zabezpečené ukládání není dostupné v tomto prohlížeči.');
    }
    const bytes = new Uint8Array(TAKE_TOKEN_BYTES);
    window.crypto.getRandomValues(bytes);
    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
}

/**
 * Meter floor policy.
 * Type: Number
 * Units: Decibels relative to full scale
 * Scope: One live microphone meter
 * Consumers: RMS meter floor and progress range
 * Rationale: Keep quiet room noise visible without magnifying the display.
 * Value: -60 dBFS.
 */
const METER_MIN_DBFS = -60;

/**
 * Meter analysis window policy.
 * Type: Integer
 * Units: Samples
 * Scope: One live microphone meter
 * Consumers: Analyser FFT window and sample buffer
 * Rationale: Provide a responsive level reading with modest CPU use.
 * Value: 1024 samples.
 */
const METER_FFT_SIZE = 1024;

/**
 * MIME preference for browsers that expose a supported recorder format.
 * Type: Array<string>
 * Units: MIME types
 * Scope: Browser microphone recording
 * Consumers: MediaRecorder construction
 * Rationale: Prefer broadly playable audio while allowing browser-native fallback.
 */
const RECORDING_MIME_TYPES = [
    'audio/webm;codecs=opus',
    'audio/ogg;codecs=opus',
    'audio/webm',
    'audio/mp4'
];

/**
 * Open and close trigger-anchored panels with Escape and outside-click support.
 * @param {HTMLElement} trigger - The button controlling this panel.
 * @param {HTMLElement} panel - The contextual panel to show or hide.
 * @returns {void} This function updates panel visibility and ARIA state.
 */
/**
 * Close one contextual panel and restore its unpositioned anchored style.
 * @param {HTMLElement} panel - The panel to close.
 * @param {?HTMLElement} trigger - Its controlling button, when still present.
 * @returns {void} The panel is hidden and its trigger reports the closed state.
 */
function closePanel(panel, trigger) {
    panel.hidden = true;
    panel.classList.remove('is-viewport-positioned');
    panel.style.left = '';
    panel.style.top = '';
    panel.style.maxHeight = '';
    trigger?.setAttribute('aria-expanded', 'false');
}

/**
 * Position an open contextual panel inside the viewport while retaining its anchor.
 * @param {HTMLElement} trigger - The button controlling the panel.
 * @param {HTMLElement} panel - The panel to position.
 * @returns {void} Oversized panels receive a bounded viewport position.
 */
function positionPanel(trigger, panel) {
    if (panel.hidden || !panel.matches(
        '.cast-popover, .invite-popover, .users-popover, .character-filter-popover'
    )) return;
    const edge = 12;
    panel.style.maxHeight = `${Math.max(120, window.innerHeight - edge * 2)}px`;
    const anchor = trigger.getBoundingClientRect();
    const bounds = panel.getBoundingClientRect();
    const left = Math.max(
        edge,
        Math.min(anchor.right - bounds.width, window.innerWidth - bounds.width - edge)
    );
    const below = anchor.bottom + 8;
    const top = below + bounds.height <= window.innerHeight - edge
        ? below
        : Math.max(edge, anchor.top - bounds.height - 8);
    panel.style.left = `${left}px`;
    panel.style.top = `${top}px`;
    panel.classList.add('is-viewport-positioned');
}

/**
 * Position every currently open panel after viewport movement or resize.
 * @returns {void} Open viewport panels remain aligned with their triggers.
 */
function positionOpenPanels() {
    document.querySelectorAll('[data-panel]:not([hidden])').forEach((panel) => {
        const trigger = Array.from(document.querySelectorAll('[data-panel-trigger]'))
            .find((candidate) => candidate.getAttribute('aria-controls') === panel.id);
        if (trigger) positionPanel(trigger, panel);
    });
}

let panelDocumentHandlersBound = false;

/**
 * Bind one trigger to the shared accessible panel lifecycle.
 * @param {HTMLElement} trigger - The button controlling this panel.
 * @param {HTMLElement} panel - The contextual panel to show or hide.
 * @returns {void} The panel supports outside click, Escape, and viewport positioning.
 */
function bindPanel(trigger, panel) {
    if (boundPanelTriggers.has(trigger)) return;
    boundPanelTriggers.add(trigger);
    if (!panelDocumentHandlersBound) {
        panelDocumentHandlersBound = true;
        document.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof Node)) return;
            document.querySelectorAll('[data-panel]:not([hidden])').forEach((openPanel) => {
                const openTrigger = Array.from(
                    document.querySelectorAll('[data-panel-trigger]')
                ).find((candidate) => candidate.getAttribute('aria-controls') === openPanel.id);
                if (openPanel.contains(target) || openTrigger?.contains(target)) return;
                closePanel(openPanel, openTrigger ?? null);
            });
        });
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            const openPanel = document.querySelector('[data-panel]:not([hidden])');
            if (!openPanel) return;
            const openTrigger = Array.from(
                document.querySelectorAll('[data-panel-trigger]')
            ).find((candidate) => candidate.getAttribute('aria-controls') === openPanel.id);
            closePanel(openPanel, openTrigger ?? null);
            openTrigger?.focus();
        });
        window.addEventListener('resize', positionOpenPanels);
        window.addEventListener('scroll', positionOpenPanels, true);
    }
    trigger.addEventListener('click', (event) => {
        event.stopPropagation();
        const willOpen = panel.hidden;
        document.querySelectorAll('[data-panel]:not([hidden])').forEach((openPanel) => {
            const openTrigger = Array.from(
                document.querySelectorAll('[data-panel-trigger]')
            ).find((candidate) => candidate.getAttribute('aria-controls') === openPanel.id);
            closePanel(openPanel, openTrigger ?? null);
        });
        panel.hidden = !willOpen;
        trigger.setAttribute('aria-expanded', String(willOpen));
        if (willOpen) positionPanel(trigger, panel);
    });
    panel.querySelectorAll('[data-panel-close]').forEach((button) => {
        button.addEventListener('click', () => {
            closePanel(panel, trigger);
            trigger.focus();
        });
    });
    if (!panel.hidden) positionPanel(trigger, panel);
}

/**
 * Bind panel triggers in a newly inserted subtree without adding duplicate listeners.
 * @param {ParentNode} root - The document or newly inserted DOM subtree.
 * @returns {void} Every available trigger uses the shared panel lifecycle.
 */
function bindPanelTriggers(root) {
    root.querySelectorAll('[data-panel-trigger]').forEach((trigger) => {
        const panel = document.getElementById(trigger.getAttribute('aria-controls'));
        if (panel) bindPanel(trigger, panel);
    });
}

/**
 * Toggle matrix assignments in place and announce the saved result.
 * @param {HTMLTableElement} matrix - The exact role assignment matrix.
 * @returns {void} Successful changes update one cell without closing the panel.
 */
function bindCastMatrix(matrix) {
    const status = matrix.parentElement?.parentElement?.querySelector(
        '[data-cast-status]'
    );
    const clearAxisHighlights = () => {
        matrix.querySelectorAll('.is-axis-highlight, .is-row-highlight')
            .forEach((element) => {
                element.classList.remove(
                    'is-axis-highlight',
                    'is-row-highlight'
                );
            });
    };
    const highlightTarget = (target) => {
        clearAxisHighlights();
        if (!(target instanceof Element)) return;
        const row = target.closest('[data-cast-role-row]');
        row?.classList.add('is-row-highlight');
        const cell = target.closest('[data-cast-column]');
        const column = cell?.getAttribute('data-cast-column');
        if (column !== null && column !== undefined) {
            matrix.querySelectorAll('[data-cast-column]').forEach((element) => {
                if (element.getAttribute('data-cast-column') === column) {
                    element.classList.add('is-axis-highlight');
                }
            });
        }
    };
    matrix.addEventListener('pointerover', (event) => {
        highlightTarget(event.target);
    });
    matrix.addEventListener('pointerout', (event) => {
        if (event.relatedTarget instanceof Node
            && matrix.contains(event.relatedTarget)) {
            highlightTarget(event.relatedTarget);
        } else {
            clearAxisHighlights();
        }
    });
    matrix.addEventListener('focusin', (event) => {
        highlightTarget(event.target);
    });
    matrix.addEventListener('focusout', (event) => {
        if (event.relatedTarget instanceof Node
            && matrix.contains(event.relatedTarget)) {
            highlightTarget(event.relatedTarget);
        } else {
            clearAxisHighlights();
        }
    });
    matrix.addEventListener('submit', async (event) => {
        const form = event.target.closest('form[data-cast-cell]');
        if (!form || !(form instanceof HTMLFormElement)) return;
        event.preventDefault();
        if (form.dataset.pending === 'true') return;
        const button = form.querySelector('button');
        if (!button) return;
        form.dataset.pending = 'true';
        button.setAttribute('aria-busy', 'true');
        if (status) status.textContent = 'Ukládám obsazení…';
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            let result;
            try {
                result = await response.json();
            } catch {
                throw new Error('Server nevrátil potvrzení změny obsazení.');
            }
            if (!response.ok || result.ok !== true) {
                throw new Error(result.message ?? 'Obsazení se nepodařilo uložit.');
            }
            const assigned = result.assigned === true;
            const actor = button.dataset.actorLabel ?? '';
            const role = button.dataset.roleLabel ?? '';
            const toggle = form.querySelector('[name="assigned"]');
            const glyph = button.querySelector('span');
            button.classList.toggle('is-assigned', assigned);
            button.setAttribute('aria-pressed', String(assigned));
            button.setAttribute(
                'aria-label',
                `${assigned ? 'Odebrat' : 'Přiřadit'} ${actor} jako ${role}`
            );
            button.title = assigned ? 'Odebrat přiřazení' : 'Přiřadit';
            if (glyph) glyph.textContent = assigned ? '✓' : '';
            if (toggle instanceof HTMLInputElement) {
                toggle.value = assigned ? '0' : '1';
            }
            if (status) status.textContent = result.message;
            window.dispatchEvent(new Event('dabing:project-refresh'));
        } catch (error) {
            if (status) {
                status.textContent = error instanceof Error
                    ? error.message
                    : 'Obsazení se nepodařilo uložit.';
            }
        } finally {
            delete form.dataset.pending;
            button.removeAttribute('aria-busy');
        }
    });
}

/**
 * Preserve a failed recording and offer an explicit retry without playback UI.
 * @param {HTMLElement} retryArea - The line's compact failure-action container.
 * @param {HTMLElement} card - The line card containing the recording token.
 * @param {{blob: Blob, token: string, mp3: ?Blob, saved: boolean, discarded: boolean}} pending - Retained take data.
 * @param {HTMLElement} status - Accessible conversion and upload status.
 * @param {() => void} onDiscard - Releases the retained blob and page capture lock.
 * @returns {void} A visible retry action is attached to the line.
 */
function showRetryAction(retryArea, card, pending, status, onDiscard) {
    retryArea.replaceChildren();
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'upload-button';
    retry.title = 'Zkusit uložit znovu';
    retry.setAttribute('aria-label', 'Zkusit uložit nahrávku znovu');
    retry.innerHTML = '<span aria-hidden="true">↻</span><span>Zkusit znovu</span>';
    retry.addEventListener('click', () => uploadRecording(card, pending, status, retry));
    const discard = document.createElement('button');
    discard.type = 'button';
    discard.className = 'upload-discard';
    discard.title = 'Zahodit neuloženou nahrávku';
    discard.setAttribute('aria-label', 'Zahodit neuloženou nahrávku');
    discard.textContent = 'Zahodit';
    discard.addEventListener('click', onDiscard);
    retryArea.append(retry, discard);
    retryArea.hidden = false;
}

/**
 * Start a non-monitoring RMS meter for an already granted microphone stream.
 * @param {MediaStream} stream - The stream already used by MediaRecorder.
 * @param {HTMLElement} live - REC indicator shown during recording.
 * @param {HTMLElement} meter - Accessible dBFS meter element.
 * @param {HTMLElement} fill - Visual meter fill bar.
 * @param {HTMLElement} levelText - Visible numeric level text.
 * @returns {() => void} Stops animation and closes the analyser context.
 */
function startRecordingMeter(stream, live, meter, fill, levelText) {
    live.hidden = false;
    meter.classList.remove('record-meter-unavailable');
    let context = null;
    let source = null;
    let analyser = null;
    let frameId = 0;
    let closed = false;
    try {
        const AudioContextType = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextType) throw new Error('AudioContext unavailable');
        context = new AudioContextType();
        context.resume().catch(() => {});
        source = context.createMediaStreamSource(stream);
        analyser = context.createAnalyser();
        analyser.fftSize = METER_FFT_SIZE;
        source.connect(analyser);
        const samples = new Float32Array(analyser.fftSize);
        const update = () => {
            if (closed || !analyser) return;
            analyser.getFloatTimeDomainData(samples);
            let squaredSum = 0;
            for (const sample of samples) squaredSum += sample * sample;
            const rms = Math.sqrt(squaredSum / samples.length);
            const dbfs = rms > 0
                ? AMPLITUDE_DECIBEL_FACTOR * Math.log10(rms)
                : METER_MIN_DBFS;
            const clampedDbfs = Math.max(
                METER_MIN_DBFS,
                Math.min(METER_MAX_DBFS, dbfs)
            );
            const fraction = (clampedDbfs - METER_MIN_DBFS) / -METER_MIN_DBFS;
            fill.style.width = `${fraction * 100}%`;
            meter.setAttribute('aria-valuenow', String(Math.round(clampedDbfs)));
            meter.setAttribute('aria-valuetext', String(Math.round(clampedDbfs)) + ' dBFS');
            levelText.textContent = `${Math.round(clampedDbfs)} dBFS`;
            frameId = window.requestAnimationFrame(update);
        };
        frameId = window.requestAnimationFrame(update);
    } catch (error) {
        meter.classList.add('record-meter-unavailable');
        meter.setAttribute('aria-valuenow', String(METER_MIN_DBFS));
        levelText.textContent = '— dBFS';
    }
    return () => {
        if (closed) return;
        closed = true;
        if (frameId) window.cancelAnimationFrame(frameId);
        if (source) source.disconnect();
        if (analyser) analyser.disconnect();
        live.hidden = true;
        if (context && context.state !== 'closed') {
            context.close().catch(() => {});
        }
    };
}

/**
 * Encode mono 44.1 kHz samples with the local MP3 worker.
 * @param {Int16Array} samples - Clamped, signed mono audio samples.
 * @returns {Promise<ArrayBuffer>} Encoded MP3 bytes or a conversion failure.
 */
function encodeMp3WithWorker(samples) {
    return new Promise((resolve, reject) => {
        let worker;
        try {
            worker = new Worker(MP3_WORKER_PATH);
        } catch (error) {
            reject(new Error('MP3 převod se nepodařilo spustit.'));
            return;
        }
        worker.onmessage = (event) => {
            worker.terminate();
            const result = event.data;
            if (result?.ok === true && result.buffer instanceof ArrayBuffer
                && result.buffer.byteLength > 0) {
                resolve(result.buffer);
                return;
            }
            reject(new Error('MP3 převod se nepodařilo dokončit.'));
        };
        worker.onerror = () => {
            worker.terminate();
            reject(new Error('MP3 převod se nepodařilo dokončit.'));
        };
        try {
            worker.postMessage(
                { samples, sampleRate: MP3_SAMPLE_RATE },
                [samples.buffer]
            );
        } catch (error) {
            worker.terminate();
            reject(new Error('MP3 převod se nepodařilo spustit.'));
        }
    });
}

/**
 * Decode any supported source, downmix and resample it, then encode genuine MP3.
 * @param {Blob} source - Captured audio or a selected source audio file.
 * @returns {Promise<Blob>} A mono 44.1 kHz MP3 blob ready for upload.
 */
async function convertToMp3(source) {
    const AudioContextType = window.AudioContext || window.webkitAudioContext;
    const OfflineContextType = window.OfflineAudioContext
        || window.webkitOfflineAudioContext;
    if (!AudioContextType || !OfflineContextType) {
        throw new Error('Tento prohlížeč neumí převést zvuk na MP3.');
    }
    const decoder = new AudioContextType();
    try {
        const decoded = await decoder.decodeAudioData(await source.arrayBuffer());
        if (!Number.isFinite(decoded.duration) || decoded.duration <= 0
            || decoded.duration > MAX_RECORDING_SECONDS) {
            throw new Error('Nahrávka musí trvat nejvýše 180 sekund.');
        }
        const frameCount = Math.ceil(decoded.duration * MP3_SAMPLE_RATE);
        const offline = new OfflineContextType(1, frameCount, MP3_SAMPLE_RATE);
        const input = offline.createBufferSource();
        const mixer = offline.createGain();
        input.buffer = decoded;
        mixer.channelCount = 1;
        mixer.channelCountMode = 'explicit';
        mixer.channelInterpretation = 'speakers';
        input.connect(mixer);
        mixer.connect(offline.destination);
        input.start(0);
        const rendered = await offline.startRendering();
        const mono = rendered.getChannelData(0);
        const samples = new Int16Array(mono.length);
        for (let index = 0; index < mono.length; index += 1) {
            const finite = Number.isFinite(mono[index]) ? mono[index] : 0;
            const clamped = Math.max(-1, Math.min(1, finite));
            samples[index] = clamped < 0
                ? Math.round(clamped * 32768)
                : Math.round(clamped * 32767);
        }
        const encoded = await encodeMp3WithWorker(samples);
        return new Blob([encoded], { type: 'audio/mpeg' });
    } catch (error) {
        if (error instanceof Error && error.message.includes('180 sekund')) {
            throw error;
        }
        throw new Error('Zvuk se nepodařilo převést na MP3. Záznam zůstal v paměti.');
    } finally {
        if (decoder.state !== 'closed') await decoder.close();
    }
}

/**
 * Convert a stopped recording to MP3 and upload it without discarding the source.
 * @param {HTMLElement} card - The line card containing identifiers and CSRF token.
 * @param {{blob: Blob, token: string, mp3: ?Blob, saved: boolean, discarded: boolean}} pending - Take data retained for retry.
 * @param {HTMLElement} status - Accessible conversion and upload status.
 * @param {?HTMLButtonElement} button - Optional retry action disabled during processing.
 * @returns {Promise<void>} Resolves after upload or shows an explicit retry on failure.
 */
async function uploadRecording(card, pending, status, button) {
    const token = card.querySelector('input[name="csrf"]')?.value ?? '';
    const lineId = card.closest('[data-line-card]')?.dataset.lineId
        ?? card.dataset.lineId ?? '';
    const projectId = document.querySelector('[data-project-id]')?.dataset.projectId ?? '';
    if (activeCaptureCard && activeCaptureCard !== card) {
        status.textContent = 'Jiná replika se právě ukládá.';
        return;
    }
    setCaptureOwner(card);
    if (button) {
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.classList.add('is-busy');
    }
    card.querySelectorAll('[data-record-retry-area] button').forEach((action) => {
        action.disabled = true;
    });
    status.textContent = 'Převádím zvuk na MP3…';
    let requestSent = false;
    let responseReceived = false;
    try {
        const mp3 = pending.mp3 ?? await convertToMp3(pending.blob);
        pending.mp3 = mp3;
        const form = new FormData();
        form.append('csrf', token);
        form.append('line_id', lineId);
        pending.token = pending.token || createTakeToken();
        form.append('take_token', pending.token);
        form.append('audio', mp3, 'dabing-take.mp3');
        status.textContent = 'Nahrávám MP3…';
        requestSent = true;
        const response = await fetch(
            `?route=upload&id=${encodeURIComponent(projectId)}`,
            {
                method: 'POST',
                body: form,
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            }
        );
        responseReceived = true;
        let result;
        try {
            result = await response.json();
        } catch (error) {
            throw new Error('Server vrátil neplatnou odpověď.');
        }
        if (!response.ok || !result.ok) {
            throw new Error(result.message || 'Nahrávání se nepodařilo.');
        }
        pending.saved = true;
        pending.blob = new Blob([]);
        pending.mp3 = null;
        savedPageNeedsRefresh = true;
        card.querySelector('[data-record-retry-area]')?.replaceChildren();
        const retryArea = card.querySelector('[data-record-retry-area]');
        if (retryArea) retryArea.hidden = true;
        status.textContent = result.file_name
            ? `Uloženo: ${result.file_name}`
            : (result.message || 'Nahrávka je uložena.');
        const line = card.closest('[data-line-card]');
        if (line?.id) {
            window.history.replaceState(null, '', '#' + encodeURIComponent(line.id));
        }
        setCaptureOwner(null);
        window.dispatchEvent(new Event('dabing:project-refresh'));
    } catch (error) {
        const rawMessage = error instanceof Error
            ? error.message
            : 'Nahrávání se nepodařilo.';
        const message = requestSent && !responseReceived && error instanceof TypeError
            ? 'Připojení se přerušilo.'
            : rawMessage;
        status.textContent = requestSent && !responseReceived
            ? `${message} Záznam zůstal v paměti; můžete bezpečně zkusit znovu.`
            : `${message} Záznam zůstal v paměti.`;
        showRetryAction(
            card.querySelector('[data-record-retry-area]'),
            card,
            pending,
            status,
            () => {
                pending.discarded = true;
                pending.blob = new Blob([]);
                pending.mp3 = null;
                const retryArea = card.querySelector('[data-record-retry-area]');
                if (retryArea) {
                    retryArea.replaceChildren();
                    retryArea.hidden = true;
                }
                status.textContent = 'Nahrávka zahozena.';
                setCaptureOwner(null);
                window.dispatchEvent(new Event('dabing:project-refresh'));
            }
        );
    } finally {
        if (button) {
            button.removeAttribute('aria-busy');
            button.classList.remove('is-busy');
        }
    }
}

/**
 * Bind one line's microphone and file-picker lifecycle.
 * @param {HTMLElement} card - A script line element with recorder controls.
 * @returns {void} The line gains serialized capture, automatic upload and retry actions.
 */
function bindRecorder(card) {
    const start = card.querySelector('[data-record-start]');
    const stop = card.querySelector('[data-record-stop]');
    const status = card.querySelector('[data-record-status]');
    const retryArea = card.querySelector('[data-record-retry-area]');
    const fileInput = card.querySelector('[data-audio-file]');
    const live = card.querySelector('[data-record-live]');
    const meter = card.querySelector('[data-record-meter]');
    const meterFill = card.querySelector('[data-record-meter-fill]');
    const meterDb = card.querySelector('[data-record-db]');
    if (!start || !stop || !status || !retryArea || !fileInput) return;

    let recorder = null;
    let stream = null;
    let chunks = [];
    let timer = null;
    let disposed = false;
    let permissionSequence = 0;
    let stopMeter = () => {};
    let discardRecording = false;
    let pendingUpload = null;
    let recordingInterruptedByPagehide = false;

    const releaseStream = () => {
        if (stream) stream.getTracks().forEach((track) => track.stop());
        stream = null;
    };
    const finishRecording = () => {
        stopMeter();
        stopMeter = () => {};
        const recording = recorder && recorder.state !== 'inactive';
        if (recording) {
            recorder.stop();
        } else {
            releaseStream();
        }
        if (timer !== null) window.clearTimeout(timer);
        timer = null;
        start.classList.remove('is-recording');
        stop.hidden = true;
    };
    start.addEventListener('click', async () => {
        if (activeCaptureCard && activeCaptureCard !== card) {
            status.textContent = 'Nejdřív dokončete ukládání jiné repliky.';
            return;
        }
        if (pendingUpload && !pendingUpload.saved && !pendingUpload.discarded) {
            status.textContent = 'Nejdřív uložte čekající nahrávku.';
            retryArea.querySelector('button')?.focus();
            return;
        }
        setCaptureOwner(card);
        pendingUpload = null;
        discardRecording = false;
        if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
            status.textContent = 'Tento prohlížeč neumí nahrávat z mikrofonu. Použijte výběr zvukového souboru.';
            setCaptureOwner(null);
            fileInput.focus();
            return;
        }
        const requestId = ++permissionSequence;
        status.textContent = 'Čekám na povolení mikrofonu…';
        try {
            const grantedStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            if (disposed || requestId !== permissionSequence) {
                grantedStream.getTracks().forEach((track) => track.stop());
                return;
            }
            stream = grantedStream;
            if (live && meter && meterFill && meterDb) {
                stopMeter = startRecordingMeter(
                    grantedStream,
                    live,
                    meter,
                    meterFill,
                    meterDb
                );
            }
            grantedStream.getAudioTracks().forEach((track) => {
                track.addEventListener('ended', () => {
                    if (recorder && recorder.state !== 'inactive') {
                        discardRecording = true;
                        chunks = [];
                        finishRecording();
                        status.textContent = 'Mikrofon se odpojil. Nahrávka nebyla uložena.';
                    }
                }, { once: true });
            });
            const supportedType = RECORDING_MIME_TYPES.find((type) =>
                MediaRecorder.isTypeSupported(type)
            );
            recorder = supportedType
                ? new MediaRecorder(stream, { mimeType: supportedType })
                : new MediaRecorder(stream);
            chunks = [];
            recorder.addEventListener('dataavailable', (event) => {
                if (event.data.size > 0) chunks.push(event.data);
            });
            recorder.addEventListener('error', () => {
                chunks = [];
                discardRecording = true;
                status.textContent = 'Nahrávání bylo přerušeno. Zkontrolujte mikrofon a zkuste to znovu.';
                finishRecording();
            });
            recorder.addEventListener('stop', () => {
                stopMeter();
                stopMeter = () => {};
                if (timer !== null) window.clearTimeout(timer);
                timer = null;
                releaseStream();
                if (disposed) return;
                start.hidden = false;
                if (discardRecording) {
                    chunks = [];
                    discardRecording = false;
                    setCaptureOwner(null);
                    return;
                }
                if (chunks.length === 0) {
                    setCaptureOwner(null);
                    status.textContent = 'Nahrávka neobsahuje zvuk.';
                    return;
                }
                const mimeType = recorder?.mimeType || chunks[0].type || 'audio/webm';
                const blob = new Blob(chunks, { type: mimeType });
                chunks = [];
                pendingUpload = { blob, token: '', mp3: null, saved: false, discarded: false };
                retryArea.hidden = true;
                status.textContent = 'Ukládám nahrávku…';
                uploadRecording(card, pendingUpload, status, null);
            });
            recorder.start();
            start.hidden = true;
            start.classList.add('is-recording');
            stop.hidden = false;
            stop.focus();
            status.textContent = 'Nahrává se.';
            timer = window.setTimeout(() => {
                finishRecording();
                status.textContent = 'Limit 180 sekund. Ukládám nahrávku…';
            }, MAX_RECORDING_SECONDS * 1000);
        } catch (error) {
            discardRecording = true;
            stopMeter();
            stopMeter = () => {};
            releaseStream();
            setCaptureOwner(null);
            if (error instanceof DOMException && error.name === 'NotAllowedError') {
                status.textContent = 'Přístup k mikrofonu není povolen. Povolte jej v prohlížeči nebo vyberte zvukový soubor.';
            } else if (error instanceof DOMException && error.name === 'NotFoundError') {
                status.textContent = 'Mikrofon nebyl nalezen. Připojte mikrofon nebo vyberte zvukový soubor.';
            } else {
                status.textContent = 'Mikrofon se nepodařilo spustit. Zkuste soubor nebo opakujte pokus.';
            }
        }
    });
    stop.addEventListener('click', finishRecording);
    fileInput.addEventListener('click', (event) => {
        if (activeCaptureCard && activeCaptureCard !== card) {
            event.preventDefault();
            status.textContent = 'Nejdřív dokončete ukládání jiné repliky.';
            return;
        }
        setCaptureOwner(card, fileInput);
    });
    fileInput.addEventListener('cancel', () => {
        if (!fileInput.files?.length) setCaptureOwner(null);
    });
    fileInput.addEventListener('change', () => {
        const file = fileInput.files?.[0];
        if (!file) {
            setCaptureOwner(null);
            return;
        }
        if (pendingUpload && !pendingUpload.saved && !pendingUpload.discarded) {
            status.textContent = 'Nejdřív uložte čekající nahrávku.';
            fileInput.value = '';
            retryArea.querySelector('button')?.focus();
            return;
        }
        if (!file.type.startsWith('audio/')) {
            status.textContent = 'Vyberte prosím zvukový soubor.';
            fileInput.value = '';
            setCaptureOwner(null);
            return;
        }
        pendingUpload = { blob: file, token: '', mp3: null, saved: false, discarded: false };
        fileInput.value = '';
        status.textContent = 'Ukládám zvukový soubor…';
        uploadRecording(card, pendingUpload, status, null);
    });
    window.addEventListener('pagehide', () => {
        recordingInterruptedByPagehide = activeCaptureCard === card && !pendingUpload;
        if (recordingInterruptedByPagehide) interruptedCaptureCard = card;
        disposed = true;
        permissionSequence += 1;
        if (recorder && recorder.state !== 'inactive') recorder.stop();
        discardRecording = true;
        chunks = [];
        stopMeter();
        stopMeter = () => {};
        if (timer !== null) window.clearTimeout(timer);
        releaseStream();
    });
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        disposed = false;
        if (savedPageNeedsRefresh) {
            savedPageNeedsRefresh = false;
            window.dispatchEvent(new Event('dabing:project-refresh'));
            return;
        }
        if (!recordingInterruptedByPagehide
            || interruptedCaptureCard !== card || activeCaptureCard !== card) return;
        recordingInterruptedByPagehide = false;
        interruptedCaptureCard = null;
        start.hidden = false;
        start.classList.remove('is-recording');
        stop.hidden = true;
        setCaptureOwner(null);
        status.textContent = 'Nahrávání bylo při opuštění stránky přerušeno.';
    });
}

/**
 * Insert the visible v2 JSON sample into the project import textarea.
 * @param {HTMLButtonElement} button - The action that copies the sample into the form.
 * @returns {void} The form receives the example and moves focus to its editor.
 */
function fillExampleScript(button) {
    const editor = document.querySelector('[data-script-editor]');
    const sample = document.querySelector('[data-script-example]');
    if (!editor || !sample) return;
    const fileInput = document.querySelector('[data-script-file]');
    if (fileInput) fileInput.value = '';
    editor.value = sample.textContent.trim();
    editor.focus();
    editor.dispatchEvent(new Event('input', { bubbles: true }));
    const hint = document.querySelector('[data-script-hint]');
    if (hint) hint.textContent = 'Ukázka vložena.';
    button.blur();
}

/**
 * Download the visible example as UTF-8 JSON.
 * @param {HTMLButtonElement} button - The action that starts the download.
 * @returns {void} The browser downloads the current v2 sample.
 */
function downloadExampleScript(button) {
    const sample = document.querySelector('[data-script-example]');
    if (!sample) return;
    const file = new Blob([sample.textContent.trim() + '\n'], {
        type: 'application/json;charset=utf-8'
    });
    const objectUrl = URL.createObjectURL(file);
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = 'scenar-json-v2.json';
    link.click();
    window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
    button.blur();
}

/**
 * Copy text with clipboard API and a same-page selection fallback.
 * @param {string} text - The exact text to place on the clipboard.
 * @returns {Promise<void>} Resolves when copied or rejects when copying is unavailable.
 */
async function copyText(text) {
    if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);
        return;
    }
    const temporary = document.createElement('textarea');
    temporary.value = text;
    temporary.setAttribute('readonly', '');
    temporary.className = 'clipboard-buffer';
    document.body.append(temporary);
    temporary.select();
    const copied = document.execCommand('copy');
    temporary.remove();
    if (!copied) throw new Error('Kopírování není dostupné.');
}

/**
 * Bind prompt copy feedback and close behavior for the compact JSON help panel.
 * @param {HTMLDetailsElement} disclosure - The native disclosure containing JSON help.
 * @returns {void} Outside click, Escape, and the close icon dismiss the panel.
 */
function bindJsonGuidance(disclosure) {
    const summary = disclosure.querySelector('summary');
    const close = disclosure.querySelector('[data-close-guide]');
    const copyButton = disclosure.querySelector('[data-copy-instructions]');
    const copyStatus = disclosure.querySelector('[data-copy-status]');
    const prompt = disclosure.querySelector('[data-ai-instructions]');
    const panel = disclosure.querySelector('.guidance-panel');
    const placePanel = () => {
        if (!disclosure.open || !panel || !summary) return;
        const triggerRect = summary.getBoundingClientRect();
        panel.style.maxHeight = `${Math.max(180, window.innerHeight - 24)}px`;
        const panelRect = panel.getBoundingClientRect();
        const left = Math.max(
            12,
            Math.min(triggerRect.left, window.innerWidth - panelRect.width - 12)
        );
        const below = triggerRect.bottom + 10;
        const top = below + panelRect.height <= window.innerHeight - 12
            ? below
            : Math.max(12, triggerRect.top - panelRect.height - 10);
        panel.style.left = `${left}px`;
        panel.style.top = `${top}px`;
    };
    disclosure.addEventListener('toggle', () => requestAnimationFrame(placePanel));
    window.addEventListener('resize', placePanel);
    close?.addEventListener('click', () => {
        disclosure.open = false;
        summary?.focus();
    });
    disclosure.addEventListener('click', (event) => event.stopPropagation());
    document.addEventListener('click', (event) => {
        if (disclosure.open && !disclosure.contains(event.target)) {
            disclosure.open = false;
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && disclosure.open) {
            disclosure.open = false;
            summary?.focus();
        }
    });
    copyButton?.addEventListener('click', async () => {
        const rules = Array.from(
            disclosure.querySelectorAll('.guidance-structure li')
        ).map((item) => `- ${item.textContent.trim()}`);
        const sample = disclosure.querySelector('[data-script-example]')
            ?.textContent.trim() ?? '';
        const filenames = disclosure.querySelector('.filename-help')
            ?.textContent.trim() ?? '';
        const instructions = prompt?.textContent.trim() ?? '';
        const content = [
            'FORMÁT A OMEZENÍ JSON V2',
            ...rules,
            '',
            'PŘÍKLAD JSON V2',
            sample,
            '',
            'NÁZVY SOUBORŮ',
            filenames,
            '',
            'POKYNY PRO AI',
            instructions
        ].join('\n');
        try {
            await copyText(content);
            if (copyStatus) copyStatus.textContent = 'Zkopírováno.';
        } catch (error) {
            if (copyStatus) copyStatus.textContent = 'Kopírování není dostupné.';
        }
    });
}
bindPanelTriggers(document);
document.querySelectorAll('[data-cast-matrix]').forEach(bindCastMatrix);
bindRecorderNodes(document);
document.querySelectorAll('[data-fill-example]').forEach((button) => {
    button.addEventListener('click', () => fillExampleScript(button));
});
document.querySelectorAll('[data-download-example]').forEach((button) => {
    button.addEventListener('click', () => downloadExampleScript(button));
});
document.querySelectorAll('[data-json-guide]').forEach(bindJsonGuidance);
document.querySelectorAll('[data-copy-trigger]').forEach((button) => {
    button.addEventListener('click', async () => {
        const value = button.closest('.popover')?.querySelector('[data-copy-value]')?.value ?? '';
        try {
            await navigator.clipboard.writeText(value);
            button.textContent = 'Odkaz zkopírován';
        } catch (error) {
            const input = button.closest('.popover')?.querySelector('[data-copy-value]');
            input?.select();
            button.textContent = 'Odkaz označen — zkopírujte jej';
        }
    });
});
/**
 * Keep JSON file and pasted content as separate import choices and enforce caps.
 * @param {HTMLFormElement} form - The project creation form.
 * @returns {void} Invalid or oversized inputs receive an accessible explanation.
 */
function bindScriptImport(form) {
    const fileInput = form.querySelector('[data-script-file]');
    const editor = form.querySelector('[data-script-editor]');
    const hint = form.querySelector('[data-script-hint]');
    if (!fileInput || !editor || !hint) return;
    const fileCap = Number(fileInput.dataset.maxBytes);
    const pasteCap = Number(editor.dataset.maxBytes);
    fileInput.addEventListener('change', () => {
        const file = fileInput.files?.[0];
        if (!file) return;
        if (Number.isFinite(fileCap) && fileCap > 0 && file.size > fileCap) {
            fileInput.value = '';
            hint.textContent = `Soubor přesahuje limit ${fileInput.dataset.maxLabel}.`;
            return;
        }
        editor.value = '';
        hint.textContent = `Vybráno: ${file.name}`;
    });
    editor.addEventListener('input', () => {
        if (editor.value.trim() && fileInput.files?.length) fileInput.value = '';
        if (editor.value.trim()) hint.textContent = 'Použije se vložený scénář.';
    });
    form.addEventListener('submit', (event) => {
        if (fileInput.files?.length) return;
        if (!editor.value.trim()) {
            event.preventDefault();
            hint.textContent = 'Vyberte JSON soubor, nebo vložte scénář.';
            editor.focus();
            return;
        }
        const pastedBytes = new Blob([editor.value]).size;
        if (Number.isFinite(pasteCap) && pasteCap > 0 && pastedBytes > pasteCap) {
            event.preventDefault();
            hint.textContent = `Vložený JSON přesahuje limit ${editor.dataset.maxLabel}.`;
            editor.focus();
        }
    });
}

/**
 * Play selected takes as one ordered audiobook and support manual next/previous.
 * @returns {void} The selected clips play in script order through one audio element.
 */
let playlistDocumentHandlersBound = false;

/**
 * Play selected takes while retaining the stable player across project refreshes.
 * @returns {void} Delegated playlist controls preserve the current audio element and time.
 */
function bindPlaylist() {
    const player = document.querySelector('[data-story-player]');
    if (!(player instanceof HTMLAudioElement)) return;
    let state = playlistStates.get(player);
    if (!state) {
        state = { currentId: '', currentIndex: -1, initialized: false };
        playlistStates.set(player, state);
    }
    const items = () => Array.from(document.querySelectorAll('[data-play-item]'));
    const status = () => document.querySelector('[data-playlist-status]');
    const playIndex = (index, shouldPlay) => {
        const currentItems = items();
        if (index < 0 || index >= currentItems.length) return;
        const item = currentItems[index];
        state.currentIndex = index;
        state.currentId = item.dataset.recordingId ?? item.dataset.src ?? '';
        player.src = item.dataset.src ?? '';
        player.load();
        const currentStatus = status();
        if (currentStatus) {
            currentStatus.textContent = item.dataset.label ?? 'Vybraná replika';
        }
        currentItems.forEach((button, buttonIndex) => {
            button.setAttribute(
                'aria-current', String(buttonIndex === state.currentIndex)
            );
        });
        if (shouldPlay) {
            player.play().catch(() => {
                const message = status();
                if (message) {
                    message.textContent = 'Přehrávání se nepodařilo spustit. Zkuste tlačítko přehrávání.';
                }
            });
        }
    };
    if (!player.dataset.playlistBound) {
        player.dataset.playlistBound = 'true';
        player.addEventListener('ended', () => {
            const currentItems = items();
            if (state.currentIndex >= 0
                && state.currentIndex + 1 < currentItems.length) {
                playIndex(state.currentIndex + 1, true);
            } else if (status()) {
                status().textContent = 'Přehrávání skončilo.';
            }
        });
    }
    if (!playlistDocumentHandlersBound) {
        playlistDocumentHandlersBound = true;
        document.addEventListener('click', (event) => {
            if (!(event.target instanceof Element)) return;
            const item = event.target.closest('[data-play-item]');
            const activePlayer = document.querySelector('[data-story-player]');
            if (!(activePlayer instanceof HTMLAudioElement)) return;
            const activeState = playlistStates.get(activePlayer);
            if (!activeState) return;
            const currentItems = items();
            if (item) {
                playIndex(currentItems.indexOf(item), true);
                return;
            }
            if (event.target.closest('[data-play-next]')) {
                playIndex((activeState.currentIndex + 1) % currentItems.length, true);
            } else if (event.target.closest('[data-play-previous]')) {
                playIndex(
                    (activeState.currentIndex + currentItems.length - 1)
                        % currentItems.length,
                    true
                );
            }
        });
    }
    const currentItems = items();
    const currentIndex = currentItems.findIndex((item) =>
        (item.dataset.recordingId ?? item.dataset.src ?? '') === state.currentId
    );
    if (state.initialized) {
        state.currentIndex = currentIndex;
        currentItems.forEach((item, index) => {
            item.setAttribute('aria-current', String(index === currentIndex));
        });
    } else if (currentItems.length > 0) {
        state.initialized = true;
        playIndex(0, false);
    }
    document.querySelectorAll('[data-play-next], [data-play-previous]')
        .forEach((button) => {
            button.disabled = currentItems.length === 0;
        });
}

/**
 * Bind recorder controls once, including controls inserted after a live refresh.
 * @param {ParentNode} root - The document or refreshed project subtree.
 * @returns {void} Each recorder retains one capture lifecycle.
 */
function bindRecorderNodes(root) {
    root.querySelectorAll('[data-recorder]').forEach((recorder) => {
        if (boundRecorders.has(recorder)) return;
        boundRecorders.add(recorder);
        bindRecorder(recorder);
    });
}

/**
 * Announce a project action or refresh status in its persistent live region.
 * @param {HTMLElement} root - The project view containing the status target.
 * @param {string} message - Short user-facing status text.
 * @returns {void} The project status region receives the message.
 */
function setProjectStatus(root, message) {
    const status = root.querySelector('[data-project-status]');
    if (status) status.textContent = message;
}

/**
 * Detect active per-take playback that must stay attached during a DOM patch.
 * @returns {boolean} True while a line-level audio player is actively playing.
 */
function hasActiveLinePlayback() {
    return Array.from(document.querySelectorAll('[data-take-audio]'))
        .some((audio) => !audio.paused && !audio.ended);
}

/**
 * Refresh deferred project markup once preserved take playback has stopped.
 * @param {HTMLElement} root - The persistent project root.
 * @param {ParentNode} content - Initial or newly inserted project content.
 * @returns {void} Playing take elements remain intact until a safe refresh point.
 */
function bindDeferredAudioRefresh(root, content) {
    content.querySelectorAll('[data-take-audio]').forEach((audio) => {
        if (audio.dataset.refreshListenerBound === 'true') return;
        audio.dataset.refreshListenerBound = 'true';
        const checkForRefresh = () => {
            if (root.dataset.refreshDeferred === 'true'
                && !activeCaptureCard && !hasActiveLinePlayback()) {
                window.dispatchEvent(new Event('dabing:project-refresh'));
            }
        };
        audio.addEventListener('pause', checkForRefresh);
        audio.addEventListener('ended', checkForRefresh);
    });
}

/**
 * Move stateful controls from existing lines into matching fresh server markup.
 * @param {HTMLElement} current - The currently displayed project content.
 * @param {HTMLElement} next - Parsed server-rendered project content.
 * @returns {void} Recorder, audio and anchored-panel nodes keep their browser state.
 */
function preserveProjectLineControls(current, next) {
    const oldLines = new Map(Array.from(
        current.querySelectorAll('[data-line-card]')
    ).map((line) => [line.dataset.lineId, line]));
    const oldAudios = new Map(Array.from(
        current.querySelectorAll('[data-take-audio]')
    ).map((audio) => [audio.dataset.takeAudio, audio]));
    const oldPanels = new Map(Array.from(
        current.querySelectorAll('[data-panel]')
    ).map((panel) => [panel.id, panel.closest('.anchored-control')]));

    next.querySelectorAll('[data-line-card]').forEach((line) => {
        const oldLine = oldLines.get(line.dataset.lineId);
        if (!oldLine) return;
        const oldRecorder = oldLine.querySelector('[data-recorder]');
        const newRecorder = line.querySelector('[data-recorder]');
        if (oldRecorder && newRecorder) newRecorder.replaceWith(oldRecorder);
        line.querySelectorAll('[data-take-audio]').forEach((audio) => {
            const oldAudio = oldAudios.get(audio.dataset.takeAudio);
            if (oldAudio) {
                oldAudio.setAttribute(
                    'aria-label', audio.getAttribute('aria-label') ?? ''
                );
                audio.replaceWith(oldAudio);
            }
        });
        line.querySelectorAll('[data-panel]').forEach((panel) => {
            const oldControl = oldPanels.get(panel.id);
            const newControl = panel.closest('.anchored-control');
            if (oldControl && newControl) newControl.replaceWith(oldControl);
        });
    });
}

/**
 * Synchronize persistent toolbars and panels from a parsed project page.
 * @param {HTMLElement} current - The live project root.
 * @param {HTMLElement} next - The parsed project root.
 * @returns {void} Static controls reflect server data without losing panel state.
 */
function syncProjectShell(current, next) {
    const currentMembers = current.querySelector('[data-project-members]');
    const nextMembers = next.querySelector('[data-project-members]');
    if (currentMembers && nextMembers) currentMembers.innerHTML = nextMembers.innerHTML;

    const currentSummary = current.querySelector('.studio-summary');
    const nextSummary = next.querySelector('.studio-summary');
    if (currentSummary && nextSummary) currentSummary.replaceWith(nextSummary);

    const currentFilters = Array.from(
        current.querySelectorAll('.script-filter a[data-project-nav]')
    );
    const nextFilters = Array.from(
        next.querySelectorAll('.script-filter a[data-project-nav]')
    );
    currentFilters.forEach((link, index) => {
        const fresh = nextFilters[index];
        if (!fresh) return;
        link.href = fresh.href;
        link.className = fresh.className;
        if (fresh.hasAttribute('aria-current')) {
            link.setAttribute('aria-current', fresh.getAttribute('aria-current'));
        } else {
            link.removeAttribute('aria-current');
        }
    });

    const currentCharacters = current.querySelector('.character-filter-list');
    const nextCharacters = next.querySelector('.character-filter-list');
    if (currentCharacters && nextCharacters) {
        const nextChecked = new Map(Array.from(
            nextCharacters.querySelectorAll('[data-character-filter]')
        ).map((input) => [input.value, input.checked]));
        currentCharacters.querySelectorAll('[data-character-filter]')
            .forEach((input) => {
                if (nextChecked.has(input.value)) {
                    input.checked = nextChecked.get(input.value);
                }
            });
    }
    const currentCharacterTrigger = current.querySelector(
        '[aria-controls="character-filter-panel"]'
    );
    const nextCharacterTrigger = next.querySelector(
        '[aria-controls="character-filter-panel"]'
    );
    if (currentCharacterTrigger && nextCharacterTrigger) {
        currentCharacterTrigger.textContent = nextCharacterTrigger.textContent;
        currentCharacterTrigger.className = nextCharacterTrigger.className;
        currentCharacterTrigger.title = nextCharacterTrigger.title;
    }

    const currentPaging = current.querySelector('[data-paging-switch]');
    const nextPaging = next.querySelector('[data-paging-switch]');
    if (currentPaging && nextPaging) currentPaging.checked = nextPaging.checked;

    const currentMatrix = current.querySelector('[data-cast-matrix]');
    const nextMatrix = next.querySelector('[data-cast-matrix]');
    if (currentMatrix && nextMatrix) {
        const matrixScroll = currentMatrix.closest('.cast-matrix-scroll');
        const scrollLeft = matrixScroll?.scrollLeft ?? 0;
        const scrollTop = matrixScroll?.scrollTop ?? 0;
        const focusedCell = document.activeElement instanceof HTMLButtonElement
            ? document.activeElement.closest('[data-cast-cell]')?.querySelector('button')
            : null;
        const actorLabel = focusedCell?.dataset.actorLabel;
        const roleLabel = focusedCell?.dataset.roleLabel;
        currentMatrix.innerHTML = nextMatrix.innerHTML;
        if (matrixScroll) {
            matrixScroll.scrollLeft = scrollLeft;
            matrixScroll.scrollTop = scrollTop;
        }
        if (actorLabel !== undefined && roleLabel !== undefined) {
            Array.from(currentMatrix.querySelectorAll('[data-cast-cell] button'))
                .find((button) => button.dataset.actorLabel === actorLabel
                    && button.dataset.roleLabel === roleLabel)
                ?.focus({ preventScroll: true });
        }
    }

    const currentInviteList = current.querySelector('.invite-email-list');
    const nextInviteList = next.querySelector('.invite-email-list');
    const focusedInviteForm = document.activeElement instanceof Element
        ? document.activeElement.closest('.invite-email-row form') : null;
    const focusedInviteId = focusedInviteForm?.querySelector('[name="invite_id"]')?.value;
    const focusedInviteAction = focusedInviteForm?.querySelector('[name="action"]')?.value;
    if (currentInviteList && nextInviteList) {
        currentInviteList.replaceWith(nextInviteList);
    } else if (currentInviteList) {
        currentInviteList.remove();
    } else if (nextInviteList) {
        current.querySelector('.invite-email-tools')?.after(nextInviteList);
    }
    if (focusedInviteId && focusedInviteAction) {
        const actionForm = Array.from(
            current.querySelectorAll('.invite-email-row form')
        ).find((form) => form.querySelector('[name="invite_id"]')?.value === focusedInviteId
            && form.querySelector('[name="action"]')?.value === focusedInviteAction);
        actionForm?.querySelector('button')?.focus({ preventScroll: true });
    }

    const currentZip = current.querySelector('.zip-export-control');
    const nextZip = next.querySelector('.zip-export-control');
    if (currentZip && nextZip) {
        currentZip.href = nextZip.href;
    } else if (currentZip) {
        currentZip.remove();
    } else if (nextZip) {
        current.querySelector('.studio-toolbar-actions')?.append(nextZip);
    }

    current.dataset.revision = next.dataset.revision ?? '';
    current.dataset.pagingEnabled = next.dataset.pagingEnabled ?? 'off';
}

/**
 * Replace one project view from same-origin HTML while preserving active controls.
 * @param {HTMLElement} root - The persistent project root.
 * @param {string} requestedUrl - Canonical project URL to fetch.
 * @param {'push'|'replace'|'none'} historyMode - URL history behavior after success.
 * @param {boolean} force - Whether to fetch even when revision already matches.
 * @returns {Promise<boolean>} True when a valid project response was applied.
 */
async function refreshProjectView(root, requestedUrl, historyMode, force) {
    if (activeCaptureCard || hasActiveLinePlayback()) {
        root.dataset.refreshDeferred = 'true';
        return false;
    }
    if (root.dataset.refreshing === 'true') {
        queuedProjectRefreshes.set(root, {
            url: requestedUrl,
            historyMode,
            force,
        });
        return false;
    }
    root.dataset.refreshing = 'true';
    const target = new URL(requestedUrl, window.location.href);
    target.searchParams.delete('view');
    const projectContent = root.querySelector('[data-project-content]');
    const visibleLine = Array.from(root.querySelectorAll('[data-line-card]'))
        .find((line) => line.getBoundingClientRect().bottom > 0);
    const anchorLineId = visibleLine?.dataset.lineId ?? '';
    const anchorOffset = visibleLine?.getBoundingClientRect().top ?? 0;
    const previousScrollY = window.scrollY;
    try {
        const response = await fetch(target.href, {
            headers: { Accept: 'text/html' },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        if (response.status === 401 || response.status === 403) {
            setProjectStatus(root, 'Přístup vypršel. Obnovte stránku a přihlaste se znovu.');
            root.dataset.pollPaused = 'true';
            return false;
        }
        if (response.status === 404) {
            setProjectStatus(root, 'Kniha nebo váš přístup už nejsou dostupné.');
            root.dataset.pollPaused = 'true';
            return false;
        }
        if (!response.ok) throw new Error('Knihu se nepodařilo obnovit.');
        const markup = await response.text();
        const parsed = new DOMParser().parseFromString(markup, 'text/html');
        const nextRoot = parsed.querySelector('[data-project-live]');
        const nextContent = nextRoot?.querySelector('[data-project-content]');
        if (!nextRoot && response.redirected) {
            setProjectStatus(root, 'Přihlášení vypršelo. Přihlaste se znovu.');
            root.dataset.pollPaused = 'true';
            return false;
        }
        if (!nextRoot || !nextContent || !projectContent
            || nextRoot.dataset.projectId !== root.dataset.projectId) {
            throw new Error('Server nevrátil zobrazení této knihy.');
        }
        if (!force && nextRoot.dataset.revision === root.dataset.revision) {
            return false;
        }
        if (activeCaptureCard || hasActiveLinePlayback()) {
            root.dataset.refreshDeferred = 'true';
            return false;
        }

        const oldMaster = projectContent.querySelector('.master-player');
        const newMaster = nextContent.querySelector('.master-player');
        if (oldMaster && newMaster) newMaster.replaceWith(oldMaster);
        const oldPlaylist = projectContent.querySelector('[data-playlist]');
        const newPlaylist = nextContent.querySelector('[data-playlist]');
        if (oldPlaylist && newPlaylist) {
            const listScroll = oldPlaylist.scrollTop;
            const focusedItem = document.activeElement instanceof Element
                ? document.activeElement.closest('[data-play-item]')?.dataset.recordingId
                : null;
            oldPlaylist.replaceChildren(...Array.from(newPlaylist.childNodes));
            oldPlaylist.scrollTop = listScroll;
            newPlaylist.replaceWith(oldPlaylist);
            if (focusedItem) {
                oldPlaylist.querySelector(
                    `[data-recording-id="${CSS.escape(focusedItem)}"]`
                )?.focus({ preventScroll: true });
            }
        }
        preserveProjectLineControls(projectContent, nextContent);
        projectContent.replaceWith(nextContent);
        syncProjectShell(root, nextRoot);
        if (historyMode !== 'none') {
            const visibleUrl = target.pathname + target.search + target.hash;
            window.history[historyMode + 'State'](null, '', visibleUrl);
        }
        if (target.searchParams.has('paging')) {
            try {
                localStorage.setItem(
                    `dabing:paging:${root.dataset.accountId}:${root.dataset.projectId}`,
                    target.searchParams.get('paging') === 'on' ? 'on' : 'off'
                );
            } catch (error) {
                // The URL remains authoritative when browser storage is unavailable.
            }
        }
        bindPanelTriggers(nextContent);
        bindRecorderNodes(nextContent);
        bindDeferredAudioRefresh(root, nextContent);
        bindPlaylist();
        delete root.dataset.refreshDeferred;
        const restoredLine = anchorLineId
            ? root.querySelector(`[data-line-id="${CSS.escape(anchorLineId)}"]`)
            : null;
        if (restoredLine) {
            window.scrollBy(0, restoredLine.getBoundingClientRect().top - anchorOffset);
        } else {
            window.scrollTo(0, previousScrollY);
        }
        root.dataset.lastUrl = target.href;
        return true;
    } catch (error) {
        setProjectStatus(
            root,
            error instanceof Error ? error.message : 'Knihu se nepodařilo obnovit.'
        );
        return false;
    } finally {
        delete root.dataset.refreshing;
        const queued = queuedProjectRefreshes.get(root);
        if (queued && root.dataset.pollPaused !== 'true') {
            queuedProjectRefreshes.delete(root);
            window.setTimeout(() => {
                void refreshProjectView(
                    root,
                    queued.url,
                    queued.historyMode,
                    queued.force
                );
            }, 0);
        }
    }
}

/**
 * Start account-scoped paging persistence, async book actions and revision polling.
 * @param {HTMLElement} root - The authenticated project root.
 * @returns {void} In-book navigation and shared updates use scoped HTML requests.
 */
function bindProjectLive(root) {
    if (root.dataset.liveBound === 'true') return;
    root.dataset.liveBound = 'true';
    const projectId = root.dataset.projectId ?? '';
    const accountId = root.dataset.accountId ?? '';
    const storageKey = `dabing:paging:${accountId}:${projectId}`;
    const currentUrl = new URL(window.location.href);
    const hasPaging = currentUrl.searchParams.has('paging');
    const legacyPagedUrl = !hasPaging
        && /^\d+$/.test(currentUrl.searchParams.get('page') ?? '')
        && Number(currentUrl.searchParams.get('page')) > 0;
    let wantedPaging = currentUrl.searchParams.get('paging') === 'on'
        ? 'on' : 'off';
    if (!hasPaging && !legacyPagedUrl) {
        try {
            wantedPaging = localStorage.getItem(storageKey) === 'on' ? 'on' : 'off';
        } catch (error) {
            wantedPaging = 'off';
        }
    } else if (legacyPagedUrl) {
        wantedPaging = 'on';
    }
    currentUrl.searchParams.set('paging', wantedPaging);
    window.history.replaceState(
        null, '', currentUrl.pathname + currentUrl.search + currentUrl.hash
    );
    root.dataset.lastUrl = currentUrl.href;
    const pagingSwitch = root.querySelector('[data-paging-switch]');
    if (pagingSwitch) pagingSwitch.checked = wantedPaging === 'on';
    if (root.dataset.pagingEnabled !== wantedPaging) {
        void refreshProjectView(root, currentUrl.href, 'none', true);
    } else {
        try {
            localStorage.setItem(storageKey, wantedPaging);
        } catch (error) {
            // Paging remains available in the canonical URL.
        }
    }

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) return;
        const link = event.target.closest('a[data-project-nav]');
        if (!link || !root.contains(link) || event.button !== 0
            || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        if (activeCaptureCard || hasActiveLinePlayback()) {
            setProjectStatus(root, activeCaptureCard
                ? 'Nejdřív dokončete nebo uložte rozpracovanou nahrávku.'
                : 'Po skončení přehrávání můžete změnit zobrazení.');
            return;
        }
        void refreshProjectView(root, link.href, 'push', true);
    });
    document.addEventListener('change', (event) => {
        if (!(event.target instanceof HTMLInputElement) || !root.contains(event.target)) return;
        if (event.target.matches('[data-character-filter]')) {
            const selected = Array.from(
                root.querySelectorAll('[data-character-filter]:checked')
            ).map((input) => input.value);
            const target = new URL(window.location.href);
            target.searchParams.delete('page');
            target.searchParams.delete('characters[]');
            if (selected.length === 0) {
                target.searchParams.set('filter', 'all');
            } else {
                target.searchParams.set('filter', 'characters');
                selected.forEach((character) => {
                    target.searchParams.append('characters[]', character);
                });
            }
            if (activeCaptureCard || hasActiveLinePlayback()) {
                setProjectStatus(root, activeCaptureCard
                    ? 'Nejdřív dokončete nebo uložte rozpracovanou nahrávku.'
                    : 'Po skončení přehrávání můžete změnit zobrazení.');
                event.target.checked = !event.target.checked;
                return;
            }
            void refreshProjectView(root, target.href, 'push', true);
            return;
        }
        if (!event.target.matches('[data-paging-switch]')) return;
        if (activeCaptureCard || hasActiveLinePlayback()) {
            setProjectStatus(root, activeCaptureCard
                ? 'Nejdřív dokončete nebo uložte rozpracovanou nahrávku.'
                : 'Po skončení přehrávání můžete změnit zobrazení.');
            event.target.checked = !event.target.checked;
            return;
        }
        const target = new URL(window.location.href);
        target.searchParams.set('paging', event.target.checked ? 'on' : 'off');
        target.searchParams.delete('page');
        void refreshProjectView(root, target.href, 'push', true);
    });
    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)
            || !form.matches('[data-project-async]') || !root.contains(form)) return;
        event.preventDefault();
        if (form.dataset.pending === 'true') return;
        form.dataset.pending = 'true';
        const submitter = event.submitter instanceof HTMLButtonElement
            ? event.submitter : null;
        submitter?.setAttribute('aria-busy', 'true');
        const formStatus = form.closest('[data-panel]')?.querySelector(
            '[data-panel-status]'
        );
        const say = (message) => {
            if (formStatus) formStatus.textContent = message;
            setProjectStatus(root, message);
        };
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            let result;
            try {
                result = await response.json();
            } catch (error) {
                throw new Error('Server nevrátil potvrzení změny.');
            }
            if (!response.ok || result.ok !== true) {
                throw new Error(result.message ?? 'Změnu se nepodařilo uložit.');
            }
            say(result.message ?? 'Změna byla uložena.');
            window.dispatchEvent(new Event('dabing:project-refresh'));
        } catch (error) {
            say(error instanceof Error
                ? error.message : 'Změnu se nepodařilo uložit.');
        } finally {
            delete form.dataset.pending;
            submitter?.removeAttribute('aria-busy');
        }
    });
    window.addEventListener('popstate', () => {
        if (activeCaptureCard) {
            window.history.pushState(null, '', root.dataset.lastUrl ?? window.location.href);
            setProjectStatus(root, 'Nejdřív dokončete nebo uložte rozpracovanou nahrávku.');
            return;
        }
        void refreshProjectView(root, window.location.href, 'none', true);
    });
    window.addEventListener('dabing:project-refresh', () => {
        if (!activeCaptureCard && !hasActiveLinePlayback()) {
            delete root.dataset.refreshDeferred;
        }
        void refreshProjectView(root, window.location.href, 'none', true);
    });

    let pollTimer = null;
    let polling = false;
    const schedulePoll = () => {
        if (pollTimer !== null) window.clearTimeout(pollTimer);
        if (root.dataset.pollPaused === 'true'
            || document.visibilityState !== 'visible') return;
        pollTimer = window.setTimeout(checkRevision, PROJECT_POLL_INTERVAL_MS);
    };
    const checkRevision = async () => {
        if (polling || document.visibilityState !== 'visible'
            || root.dataset.refreshing === 'true') {
            schedulePoll();
            return;
        }
        polling = true;
        try {
            const probe = new URL(window.location.href);
            probe.searchParams.set('view', 'revision');
            const response = await fetch(probe.href, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            if (response.status === 401 || response.status === 403) {
                setProjectStatus(root, 'Přístup vypršel. Obnovte stránku a přihlaste se znovu.');
                root.dataset.pollPaused = 'true';
                return;
            }
            if (response.status === 404) {
                setProjectStatus(root, 'Kniha nebo váš přístup už nejsou dostupné.');
                root.dataset.pollPaused = 'true';
                return;
            }
            if (!response.headers.get('content-type')?.includes('application/json')) {
                setProjectStatus(root, 'Přihlášení vypršelo. Přihlaste se znovu.');
                root.dataset.pollPaused = 'true';
                return;
            }
            const result = await response.json();
            if (response.ok && result.ok === true
                && typeof result.revision === 'string'
                && result.revision !== root.dataset.revision) {
                if (activeCaptureCard || hasActiveLinePlayback()) {
                    root.dataset.refreshDeferred = 'true';
                } else {
                    await refreshProjectView(root, window.location.href, 'none', true);
                }
            }
        } catch (error) {
            setProjectStatus(root, 'Aktualizace knihy je dočasně nedostupná.');
        } finally {
            polling = false;
            schedulePoll();
        }
    };
    document.addEventListener('visibilitychange', schedulePoll);
    schedulePoll();
}

document.querySelectorAll('[data-script-form]').forEach(bindScriptImport);
bindPlaylist();
document.querySelectorAll('[data-project-live]').forEach((root) => {
    const content = root.querySelector('[data-project-content]');
    if (content) bindDeferredAudioRefresh(root, content);
    bindProjectLive(root);
});
