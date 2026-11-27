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
function showRecordingPreview(preview, blob, fileName, card, status) {
    preview.querySelectorAll('audio[src^="blob:"]').forEach((player) => URL.revokeObjectURL(player.src));
    preview.replaceChildren();
    const player = document.createElement('audio');
    const objectUrl = URL.createObjectURL(blob);
    player.controls = true;
    player.preload = 'metadata';
    player.src = objectUrl;
    player.setAttribute('aria-label', 'Náhled nahrávky před odesláním');
    const upload = document.createElement('button');
    upload.type = 'button';
    upload.className = 'upload-button';
    upload.textContent = 'Nahrát nahrávku';
    upload.addEventListener('click', () => {
        uploadRecording(card, blob, fileName, status, upload);
    });
    preview.append(player, upload);
    preview.hidden = false;
    status.textContent = 'Poslechněte si náhled. Nahrávka se odešle až po stisknutí tlačítka.';
    player.addEventListener('emptied', () => URL.revokeObjectURL(objectUrl), { once: true });
}

/**
 * Send one explicitly confirmed audio file for the current script line.
 * @param {HTMLElement} card - The line card whose line identifier is uploaded.
 * @param {Blob} blob - The audio data to submit.
 * @param {string} fileName - The filename supplied in multipart form data.
 * @param {HTMLElement} status - The accessible upload status element.
 * @param {HTMLButtonElement} button - The upload button to lock during submission.
 * @returns {Promise<void>} Resolves after a success message or a readable failure.
 */
async function uploadRecording(card, blob, fileName, status, button) {
    const token = card.querySelector('input[name="csrf"]')?.value ?? '';
    const lineId = card.closest('[data-line-card]')?.dataset.lineId ?? card.dataset.lineId ?? '';
    const form = new FormData();
    form.append('csrf', token);
    form.append('line_id', lineId);
    form.append('audio', blob, fileName);
    button.disabled = true;
    status.textContent = 'Nahrávám repliku…';
    try {
        const response = await fetch(`?route=upload&id=${encodeURIComponent(
            document.querySelector('[data-project-id]')?.dataset.projectId ?? ''
        )}`, { method: 'POST', body: form, credentials: 'same-origin' });
        const result = await response.json();
        if (!response.ok || !result.ok) {
            throw new Error(result.message || 'Nahrávání se nepodařilo.');
        }
        status.textContent = result.message || 'Replika je uložená.';
        button.textContent = 'Nahráno';
        window.setTimeout(() => window.location.reload(), 650);
    } catch (error) {
        status.textContent = error instanceof Error
            ? `${error.message} Zkuste to znovu.`
            : 'Nahrávání se nepodařilo. Zkuste to znovu.';
        button.disabled = false;
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
                showRecordingPreview(preview, blob, currentName, card, status);
            });
            recorder.start();
            start.hidden = true;
            start.classList.add('is-recording');
            stop.hidden = false;
            stop.focus();
            status.textContent = 'Nahrává se. Mluvte přirozeně; tlačítkem záznam ukončíte.';
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
        showRecordingPreview(preview, file, currentName, card, status);
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
 * Fill the script editor with the bundled example JSON for quick onboarding.
 * @param {HTMLButtonElement} button - The button that starts the import.
 * @returns {Promise<void>} Resolves after the example fills the editor or reports failure.
 */
async function fillExampleScript(button) {
    const editor = document.querySelector('[data-script-editor]');
    if (!editor) return;
    button.disabled = true;
    try {
        const response = await fetch(button.dataset.exampleUrl, { credentials: 'same-origin' });
        if (!response.ok) throw new Error('Ukázku se nepodařilo načíst.');
        editor.value = await response.text();
        const fileInput = document.querySelector('[data-script-file]');
        if (fileInput) fileInput.value = '';
        const hint = document.querySelector('[data-script-hint]');
        if (hint) hint.textContent = 'Ukázka je vložená. Před vytvořením můžete upravit názvy kapitol i replik.';
        editor.focus();
        editor.dispatchEvent(new Event('input', { bubbles: true }));
    } catch (error) {
        editor.value = JSON.stringify({ version: 1, title: "Cesta domů", chapters: [{ title: "První kapitola", lines: [{ character: "Vypravěč", text: "Začíná nový příběh." }] }] }, null, 2);
        editor.focus();
    } finally {
        button.disabled = false;
    }
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
    button.dataset.exampleUrl = button.closest('form')?.querySelector('a[download]')?.href ?? '';
    button.addEventListener('click', () => fillExampleScript(button));
});
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
 * Keep JSON file upload and pasted JSON mutually clear and require one source.
 * @param {HTMLFormElement} form - The project creation form.
 * @returns {void} The browser reports missing input before submission.
 */
function bindScriptImport(form) {
    const fileInput = form.querySelector('[data-script-file]');
    const editor = form.querySelector('[data-script-editor]');
    const hint = form.querySelector('[data-script-hint]');
    if (!fileInput || !editor) return;
    fileInput.addEventListener('change', () => {
        if (fileInput.files?.length) {
            editor.value = '';
            if (hint) hint.textContent = 'Soubor je připravený. Název knihy načteme z jeho JSON.';
        }
    });
    editor.addEventListener('input', () => {
        if (editor.value.trim() && fileInput.files?.length) fileInput.value = '';
        if (hint && editor.value.trim()) hint.textContent = 'Použije se vložený scénář.';
    });
    form.addEventListener('submit', (event) => {
        if (fileInput.files?.length || editor.value.trim()) return;
        event.preventDefault();
        if (hint) hint.textContent = 'Nejprve vyberte JSON soubor, nebo vložte scénář.';
        (hint || editor).focus();
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