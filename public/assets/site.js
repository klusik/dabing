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
function bindPanel(trigger, panel) {
    const close = () => {
        panel.hidden = true;
        panel.classList.remove('is-viewport-positioned');
        panel.style.left = '';
        panel.style.top = '';
        panel.style.maxHeight = '';
        trigger.setAttribute('aria-expanded', 'false');
    };
    const positionViewportPanel = () => {
        if (panel.hidden || !panel.matches(
            '.cast-popover, .invite-popover, .users-popover'
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
    };
    panel.querySelectorAll('[data-panel-close]').forEach((button) => {
        button.addEventListener('click', () => {
            close();
            trigger.focus();
        });
    });
    trigger.addEventListener('click', (event) => {
        event.stopPropagation();
        const willOpen = panel.hidden;
        document.querySelectorAll('[data-panel]').forEach((openPanel) => {
            openPanel.hidden = true;
            openPanel.classList.remove('is-viewport-positioned');
            openPanel.style.left = '';
            openPanel.style.top = '';
            openPanel.style.maxHeight = '';
            const openTrigger = document.querySelector(
                `[aria-controls="${openPanel.id}"]`
            );
            if (openTrigger) openTrigger.setAttribute('aria-expanded', 'false');
        });
        panel.hidden = !willOpen;
        trigger.setAttribute('aria-expanded', String(willOpen));
        if (willOpen) positionViewportPanel();
    });
    panel.addEventListener('click', (event) => event.stopPropagation());
    document.addEventListener('click', (event) => {
        if (!panel.hidden && !panel.contains(event.target)) close();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.hidden) {
            close();
            trigger.focus();
        }
    });
    window.addEventListener('resize', positionViewportPanel);
    window.addEventListener('scroll', positionViewportPanel, true);
    if (!panel.hidden) positionViewportPanel();
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
            { method: 'POST', body: form, credentials: 'same-origin' }
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
        window.setTimeout(() => window.location.reload(), 500);
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
        if (savedPageNeedsRefresh || !recordingInterruptedByPagehide
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
document.querySelectorAll('[data-panel-trigger]').forEach((trigger) => {
    const panel = document.getElementById(trigger.getAttribute('aria-controls'));
    if (panel) {
        bindPanel(trigger, panel);
        if (trigger.dataset.open === 'true') trigger.click();
    }
});
document.querySelectorAll('[data-cast-matrix]').forEach(bindCastMatrix);
document.querySelectorAll('[data-recorder]').forEach(bindRecorder);
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
function bindPlaylist() {
    const player = document.querySelector('[data-story-player]');
    const items = Array.from(document.querySelectorAll('[data-play-item]'));
    const status = document.querySelector('[data-playlist-status]');
    const next = document.querySelector('[data-play-next]');
    const previous = document.querySelector('[data-play-previous]');
    if (!player || items.length === 0) {
        if (next) next.disabled = true;
        if (previous) previous.disabled = true;
        return;
    }
    let current = 0;
    const playIndex = (index, shouldPlay) => {
        if (index < 0 || index >= items.length) return;
        current = index;
        const item = items[current];
        player.src = item.dataset.src ?? '';
        player.load();
        if (status) status.textContent = item.dataset.label ?? 'Vybraná replika';
        items.forEach((button, buttonIndex) => {
            button.setAttribute('aria-current', buttonIndex === current ? 'true' : 'false');
        });
        if (shouldPlay) {
            player.play().catch(() => {
                if (status) status.textContent = 'Přehrávání se nepodařilo spustit. Zkuste tlačítko přehrávání.';
            });
        }
    };
    items.forEach((item, index) => {
        item.addEventListener('click', () => playIndex(index, true));
    });
    if (next) next.addEventListener('click', () => playIndex((current + 1) % items.length, true));
    if (previous) previous.addEventListener('click', () => playIndex((current + items.length - 1) % items.length, true));
    player.addEventListener('ended', () => {
        if (current + 1 < items.length) playIndex(current + 1, true);
        else if (status) status.textContent = 'Přehrávání skončilo.';
    });
    playIndex(0, false);
}

document.querySelectorAll('[data-script-form]').forEach(bindScriptImport);
bindPlaylist();
window.addEventListener('pageshow', (event) => {
    if (event.persisted && savedPageNeedsRefresh) window.location.reload();
});
