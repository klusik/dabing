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
        trigger.setAttribute('aria-expanded', 'false');
    };
    trigger.addEventListener('click', (event) => {
        event.stopPropagation();
        const willOpen = panel.hidden;
        document.querySelectorAll('[data-panel]').forEach((openPanel) => {
            openPanel.hidden = true;
            const openTrigger = document.querySelector(
                `[aria-controls="${openPanel.id}"]`
            );
            if (openTrigger) openTrigger.setAttribute('aria-expanded', 'false');
        });
        panel.hidden = !willOpen;
        trigger.setAttribute('aria-expanded', String(willOpen));
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
}

/**
 * Create a playable local preview and explicit upload control for a chosen clip.
 * @param {HTMLElement} preview - The line card's preview container.
 * @param {Blob} blob - Audio data selected by the reader or captured by the browser.
 * @param {string} fileName - A safe filename to send to the upload endpoint.
 * @param {HTMLElement} card - The line card containing the upload controls.
 * @param {HTMLElement} status - The accessible status paragraph for this line.
 * @returns {void} The preview is shown and can be uploaded after user confirmation.
 */
function showRecordingPreview(preview, blob, card, status) {
    preview.querySelectorAll('audio[src^="blob:"]').forEach((player) => URL.revokeObjectURL(player.src));
    preview.replaceChildren();
    const player = document.createElement('audio');
    const objectUrl = URL.createObjectURL(blob);
    player.controls = true;
    player.preload = 'metadata';
    player.src = objectUrl;
    player.setAttribute('aria-label', 'Náhled vybrané nahrávky');
    const upload = document.createElement('button');
    upload.type = 'button';
    upload.className = 'icon-control upload-button';
    upload.title = 'Převést a nahrát jako MP3';
    upload.setAttribute('aria-label', 'Převést a nahrát nahrávku jako MP3');
    upload.textContent = '↑';
    upload.addEventListener('click', () => {
        uploadRecording(card, blob, status, upload);
    });
    preview.append(player, upload);
    preview.hidden = false;
    status.textContent = 'Náhled připraven. Při odeslání se převede na MP3.';
    player.addEventListener('emptied', () => URL.revokeObjectURL(objectUrl), { once: true });
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
 * @param {Blob} source - Original preview recording or selected audio file.
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
        throw new Error('Zvuk se nepodařilo převést na MP3. Původní náhled zůstává k dispozici.');
    } finally {
        if (decoder.state !== 'closed') await decoder.close();
    }
}

/**
 * Convert a confirmed preview to MP3 and upload it without discarding the source.
 * @param {HTMLElement} card - The line card containing identifiers and CSRF token.
 * @param {Blob} blob - Original captured or selected audio, kept for retry.
 * @param {string} fileName - Original filename used only for local preview context.
 * @param {HTMLElement} status - Accessible conversion and upload status.
 * @param {HTMLButtonElement} button - Conversion action disabled during processing.
 * @returns {Promise<void>} Resolves after the upload or reports a retryable failure.
 */
async function uploadRecording(card, blob, status, button) {
    const token = card.querySelector('input[name="csrf"]')?.value ?? '';
    const lineId = card.closest('[data-line-card]')?.dataset.lineId
        ?? card.dataset.lineId ?? '';
    const projectId = document.querySelector('[data-project-id]')?.dataset.projectId ?? '';
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.classList.add('is-busy');
    status.textContent = 'Převádím zvuk na MP3…';
    try {
        const mp3 = await convertToMp3(blob);
        const form = new FormData();
        form.append('csrf', token);
        form.append('line_id', lineId);
        form.append('audio', mp3, 'dabing-take.mp3');
        status.textContent = 'Nahrávám MP3…';
        const response = await fetch(
            `?route=upload&id=${encodeURIComponent(projectId)}`,
            { method: 'POST', body: form, credentials: 'same-origin' }
        );
        const result = await response.json();
        if (!response.ok || !result.ok) {
            throw new Error(result.message || 'Nahrávání se nepodařilo.');
        }
        status.textContent = result.file_name
            ? `Uloženo: ${result.file_name}`
            : (result.message || 'Nahrávka je uložena.');
        window.setTimeout(() => window.location.reload(), 700);
    } catch (error) {
        const message = error instanceof Error
            ? error.message
            : 'Nahrávání se nepodařilo.';
        status.textContent = `${message} Původní náhled zůstává k dispozici; zkuste to znovu.`;
        button.disabled = false;
    } finally {
        button.removeAttribute('aria-busy');
        button.classList.remove('is-busy');
    }
}

/**
 * Bind one line's microphone and file-picker lifecycle.
 * @param {HTMLElement} card - A script line element with recorder controls.
 * @returns {void} The line gains microphone recording and file upload preview.
 */
function bindRecorder(card) {
    const start = card.querySelector('[data-record-start]');
    const stop = card.querySelector('[data-record-stop]');
    const status = card.querySelector('[data-record-status]');
    const preview = card.querySelector('[data-record-preview]');
    const fileInput = card.querySelector('[data-audio-file]');
    if (!start || !stop || !status || !preview || !fileInput) return;

    let recorder = null;
    let stream = null;
    let chunks = [];
    let timer = null;
    let disposed = false;
    let permissionSequence = 0;
    let currentName = 'dabing-replika.webm';

    const releaseStream = () => {
        if (stream) stream.getTracks().forEach((track) => track.stop());
        stream = null;
    };
    const finishRecording = () => {
        const recording = recorder && recorder.state !== 'inactive';
        if (recording) {
            start.disabled = true;
            recorder.stop();
        } else {
            releaseStream();
            start.disabled = false;
        }
        if (timer !== null) window.clearTimeout(timer);
        timer = null;
        start.classList.remove('is-recording');
        stop.hidden = true;
    };
    start.addEventListener('click', async () => {
        if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
            status.textContent = 'Tento prohlížeč neumí nahrávat z mikrofonu. Použijte výběr zvukového souboru.';
            fileInput.focus();
            return;
        }
        const requestId = ++permissionSequence;
        start.disabled = true;
        status.textContent = 'Čekám na povolení mikrofonu…';
        try {
            const grantedStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            if (disposed || requestId !== permissionSequence) {
                grantedStream.getTracks().forEach((track) => track.stop());
                return;
            }
            stream = grantedStream;
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
                status.textContent = 'Nahrávání bylo přerušeno. Zkontrolujte mikrofon a zkuste to znovu.';
                finishRecording();
            });
            recorder.addEventListener('stop', () => {
            releaseStream();
                if (disposed) return;
                start.hidden = false;
                start.disabled = false;
                if (chunks.length === 0) return;
                const mimeType = recorder?.mimeType || chunks[0].type || 'audio/webm';
                const blob = new Blob(chunks, { type: mimeType });
                currentName = mimeType.includes('ogg')
                    ? 'dabing-replika.ogg'
                    : (mimeType.includes('mp4') ? 'dabing-replika.mp4' : 'dabing-replika.webm');
                showRecordingPreview(preview, blob, card, status);
            });
            recorder.start();
            start.hidden = true;
            start.classList.add('is-recording');
            stop.hidden = false;
            stop.focus();
            status.textContent = 'Nahrává se.';
            timer = window.setTimeout(() => {
                finishRecording();
                status.textContent = 'Limit jedné repliky vypršel. Zkontrolujte náhled a případně nahrajte repliku znovu.';
            }, MAX_RECORDING_SECONDS * 1000);
        } catch (error) {
            releaseStream();
            if (error instanceof DOMException && error.name === 'NotAllowedError') {
                status.textContent = 'Přístup k mikrofonu není povolen. Povolte jej v prohlížeči nebo vyberte zvukový soubor.';
            } else if (error instanceof DOMException && error.name === 'NotFoundError') {
                status.textContent = 'Mikrofon nebyl nalezen. Připojte mikrofon nebo vyberte zvukový soubor.';
            } else {
                status.textContent = 'Mikrofon se nepodařilo spustit. Zkuste soubor nebo opakujte pokus.';
            }
        } finally {
            start.disabled = false;
        }
    });
    stop.addEventListener('click', finishRecording);
    fileInput.addEventListener('change', () => {
        const file = fileInput.files?.[0];
        if (!file) return;
        if (!file.type.startsWith('audio/')) {
            status.textContent = 'Vyberte prosím zvukový soubor.';
            fileInput.value = '';
            return;
        }
        currentName = file.name || 'dabing-replika';
        showRecordingPreview(preview, file, card, status);
    });
    window.addEventListener('pagehide', () => {
        disposed = true;
        permissionSequence += 1;
        if (recorder && recorder.state !== 'inactive') recorder.stop();
            if (timer !== null) window.clearTimeout(timer);
        releaseStream();
        preview.querySelectorAll('audio').forEach((player) => {
            if (player.src.startsWith('blob:')) URL.revokeObjectURL(player.src);
        });
    }, { once: true });
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
