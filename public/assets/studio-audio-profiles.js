/**
 * Project: Dabing
 * Repository: klusik/dabing
 * File: public/assets/studio-audio-profiles.js
 * Module Type: Browser module
 * Purpose: Preview and download takes with their assigned audio profile.
 * Responsibilities: Render profile effects, encode MP3 copies, and bundle originals.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
import { hasAudioEffects, renderAudioEffects } from './audio-profiles/engine.js';

/** Maximum individual ZIP payload retained in memory by this browser module.
 * Type: number; Units: bytes; Scope: one take download; Consumers: ZIP builder.
 * Rationale: Keep two-copy downloads bounded on browser memory; 64 MiB per take.
 */
const MAX_TAKE_DOWNLOAD_BYTES = 64 * 1024 * 1024;
/** Upper bound for originals and processed copies retained for one book ZIP.
 * Type: number; Units: bytes; Scope: one full-book ZIP; Consumers: book export.
 * Rationale: Limit browser memory use while permitting normal short-form books.
 */
const MAX_BOOK_DOWNLOAD_BYTES = 256 * 1024 * 1024;
/** Output sample rate used by the existing MP3 encoder worker.
 * Type: number; Units: samples per second; Scope: effect MP3 encoding.
 * Consumers: OfflineAudioContext and mp3-worker.js.
 * Rationale: Match the established upload encoder's 44.1 kHz mono format.
 */
const MP3_SAMPLE_RATE = 44100;
/** Deduplicated processed playback URLs and their in-flight render promises.
 * @type {Map<string, {url:string, promise?:Promise<string>}>}
 */
const processedPlayback = new Map();
/** Identifies pause events raised while effect rendering pauses an audio element.
 * @type {WeakSet<HTMLAudioElement>} Elements whose next pause event is internal.
 */
const processingPauses = new WeakSet();

/** Read profile definitions embedded in the current project root.
 * @returns {Map<string, {id: number, name: string, chain: object}>} Profiles by ID.
 */
function readProfiles() {
    const raw = document.querySelector('[data-project-live]')?.dataset.audioProfiles;
    let rows;
    try { rows = JSON.parse(raw ?? '[]'); } catch (error) { rows = []; }
    return new Map((Array.isArray(rows) ? rows : []).map((row) => [
        String(row.id), row,
    ]));
}

/** Encode mono PCM samples as MP3 with the existing local worker.
 * @param {Int16Array} samples - Signed 16-bit mono samples at 44.1 kHz.
 * @returns {Promise<Blob>} MP3 data or a rejected conversion promise.
 */
function encodeMp3(samples) {
    return new Promise((resolve, reject) => {
        let worker;
        try { worker = new Worker('./public/assets/mp3-worker.js'); }
        catch (error) { reject(new Error('MP3 enkodér není dostupný.')); return; }
        worker.onmessage = (event) => {
            worker.terminate();
            if (event.data?.ok && event.data.buffer instanceof ArrayBuffer) {
                resolve(new Blob([event.data.buffer], { type: 'audio/mpeg' }));
            } else reject(new Error('MP3 se nepodařilo vytvořit.'));
        };
        worker.onerror = () => { worker.terminate(); reject(new Error('MP3 se nepodařilo vytvořit.')); };
        worker.postMessage({ samples, sampleRate: MP3_SAMPLE_RATE }, [samples.buffer]);
    });
}

/** Render an assigned effect chain and encode the resulting audio as MP3.
 * @param {Blob} source - Immutable original recording bytes.
 * @param {object} chain - Saved audio effect chain from the profile.
 * @returns {Promise<Blob>} A processed MP3 copy.
 */
async function makeEffectMp3(source, chain) {
    const buffer = await renderAudioEffects(source, chain);
    const Offline = window.OfflineAudioContext || window.webkitOfflineAudioContext;
    if (!Offline) throw new Error('OfflineAudioContext není dostupný.');
    const frames = Math.ceil(buffer.duration * MP3_SAMPLE_RATE);
    const offline = new Offline(1, frames, MP3_SAMPLE_RATE);
    const input = offline.createBufferSource();
    const gain = offline.createGain();
    input.buffer = buffer;
    gain.channelCount = 1;
    gain.channelCountMode = 'explicit';
    gain.channelInterpretation = 'speakers';
    input.connect(gain);
    gain.connect(offline.destination);
    input.start(0);
    const rendered = await offline.startRendering();
    const pcm = rendered.getChannelData(0);
    const samples = new Int16Array(pcm.length);
    for (let index = 0; index < pcm.length; index += 1) {
        const value = Math.max(-1, Math.min(1, Number.isFinite(pcm[index]) ? pcm[index] : 0));
        samples[index] = value < 0 ? Math.round(value * 32768) : Math.round(value * 32767);
    }
    return encodeMp3(samples);
}

/** Compute CRC-32 for a ZIP entry using the ZIP/IEEE polynomial.
 * @param {Uint8Array} bytes - Entry bytes.
 * @returns {number} Unsigned CRC-32 value.
 */
function crc32(bytes) {
    let crc = 0xffffffff;
    for (const byte of bytes) {
        crc ^= byte;
        for (let bit = 0; bit < 8; bit += 1) {
            crc = (crc >>> 1) ^ ((crc & 1) ? 0xedb88320 : 0);
        }
    }
    return (crc ^ 0xffffffff) >>> 0;
}

/** Build a small uncompressed ZIP archive with UTF-8 filenames.
 * @param {{name: string, blob: Blob}[]} entries - Files to include.
 * @returns {Promise<Blob>} Complete ZIP file.
 */
async function makeZip(entries) {
    const encoder = new TextEncoder();
    const local = [];
    const central = [];
    let offset = 0;
    for (const entry of entries) {
        const name = encoder.encode(entry.name);
        const body = new Uint8Array(await entry.blob.arrayBuffer());
        const crc = crc32(body);
        const header = new Uint8Array(30 + name.length);
        const view = new DataView(header.buffer);
        view.setUint32(0, 0x04034b50, true); view.setUint16(4, 20, true);
        view.setUint16(6, 0x0800, true); view.setUint32(14, crc, true);
        view.setUint32(18, body.length, true); view.setUint32(22, body.length, true);
        view.setUint16(26, name.length, true); header.set(name, 30);
        local.push(header, body);
        const directory = new Uint8Array(46 + name.length);
        const dir = new DataView(directory.buffer);
        dir.setUint32(0, 0x02014b50, true); dir.setUint16(4, 20, true);
        dir.setUint16(6, 20, true); dir.setUint16(8, 0x0800, true);
        dir.setUint32(16, crc, true); dir.setUint32(20, body.length, true);
        dir.setUint32(24, body.length, true); dir.setUint16(28, name.length, true);
        dir.setUint32(42, offset, true); directory.set(name, 46);
        central.push(directory);
        offset += header.length + body.length;
    }
    const centralSize = central.reduce((sum, part) => sum + part.length, 0);
    const end = new Uint8Array(22);
    const view = new DataView(end.buffer);
    view.setUint32(0, 0x06054b50, true);
    view.setUint16(8, entries.length, true); view.setUint16(10, entries.length, true);
    view.setUint32(12, centralSize, true); view.setUint32(16, offset, true);
    return new Blob([...local, ...central, end], { type: 'application/zip' });
}

/** Trigger one browser download using a temporary object URL.
 * @param {Blob} blob - File content.
 * @param {string} name - Suggested filename.
 * @returns {void} The browser receives a download link.
 */
function saveBlob(blob, name) {
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url; anchor.download = name; anchor.click();
    window.setTimeout(() => URL.revokeObjectURL(url), 30000);
}

/** Pause media while marking the ensuing pause event as an internal transition.
 * @param {HTMLAudioElement} audio - Player paused before swapping its source.
 * @returns {void} A later pause event will not cancel the render request.
 */
function pauseForProcessing(audio) {
    processingPauses.add(audio);
    audio.pause();
}

/** Resolve the processed playback URL for a playlist button when needed.
 * @param {HTMLElement} item - Playlist button containing the recording metadata.
 * @returns {Promise<string>} Original or temporary processed media URL.
 */
async function playbackUrl(item) {
    const profileId = item.dataset.audioProfileId ?? '';
    const profile = readProfiles().get(profileId);
    if (!profile || !hasAudioEffects(profile.chain)) return item.dataset.src ?? '';
    const signature = JSON.stringify(profile.chain);
    const key = `${item.dataset.recordingId}:${profileId}:${signature}`;
    let cached = processedPlayback.get(key);
    if (cached?.url) return cached.url;
    if (!cached) {
        cached = { url: '', promise: (async () => {
            const response = await fetch(item.dataset.src, { credentials: 'same-origin' });
            if (!response.ok) throw new Error('Nahrávku se nepodařilo načíst.');
            const processed = await renderAudioEffects(await response.blob(), profile.chain);
            const url = URL.createObjectURL(audioBufferToWav(processed));
            const entry = processedPlayback.get(key);
            if (entry) { entry.url = url; delete entry.promise; }
            return url;
        })() };
        processedPlayback.set(key, cached);
    }
    try {
        return await cached.promise;
    } catch (error) {
        processedPlayback.delete(key);
        throw error;
    }
}

/** Convert an AudioBuffer to a little-endian PCM WAV blob for browser playback.
 * @param {AudioBuffer} audio - Rendered audio buffer.
 * @returns {Blob} WAV audio with interleaved 16-bit PCM channels.
 */
function audioBufferToWav(audio) {
    const channels = audio.numberOfChannels;
    const frames = audio.length;
    const bytes = new ArrayBuffer(44 + frames * channels * 2);
    const view = new DataView(bytes);
    const write = (offset, value) => Array.from(value).forEach((char, index) => view.setUint8(offset + index, char.charCodeAt(0)));
    write(0, 'RIFF'); view.setUint32(4, bytes.byteLength - 8, true); write(8, 'WAVE');
    write(12, 'fmt '); view.setUint32(16, 16, true); view.setUint16(20, 1, true);
    view.setUint16(22, channels, true); view.setUint32(24, audio.sampleRate, true);
    view.setUint32(28, audio.sampleRate * channels * 2, true);
    view.setUint16(32, channels * 2, true); view.setUint16(34, 16, true); write(36, 'data');
    view.setUint32(40, frames * channels * 2, true);
    const channelData = Array.from({ length: channels }, (_, channel) => audio.getChannelData(channel));
    let offset = 44;
    for (let frame = 0; frame < frames; frame += 1) {
        for (let channel = 0; channel < channels; channel += 1) {
            const value = Math.max(-1, Math.min(1, channelData[channel][frame]));
            view.setInt16(offset, value < 0 ? value * 32768 : value * 32767, true);
            offset += 2;
        }
    }
    return new Blob([bytes], { type: 'audio/wav' });
}

/** Bind processed playback, assignment autosave and paired take downloads.
 * @returns {void} Project controls receive delegated handlers.
 */
function bindStudioAudioProfiles() {
    document.addEventListener('change', (event) => {
        const select = event.target.closest('form[data-audio-profile-assignment] select[name="profile"]');
        if (select) select.form.requestSubmit();
    });
    document.addEventListener('pause', (event) => {
        const audio = event.target;
        if (!(audio instanceof HTMLAudioElement) || !audio.matches('[data-take-audio]')) return;
        if (processingPauses.has(audio)) processingPauses.delete(audio);
        else audio.dataset.effectsRequest = String(Number(audio.dataset.effectsRequest ?? 0) + 1);
    }, true);
    document.addEventListener('play', async (event) => {
        const audio = event.target;
        if (!(audio instanceof HTMLAudioElement) || !audio.matches('[data-take-audio]')) return;
        const row = audio.closest('[data-recording-id]');
        if (!audio.dataset.originalSrc) audio.dataset.originalSrc = audio.currentSrc || audio.src;
        const profile = readProfiles().get(row?.dataset.audioProfileId ?? '');
        const signature = profile ? JSON.stringify(profile.chain) : '';
        const activeEffects = profile && hasAudioEffects(profile.chain);
        const requestId = String(Number(audio.dataset.effectsRequest ?? 0) + 1);
        audio.dataset.effectsRequest = requestId;
        if (!activeEffects) {
            if (audio.dataset.effectsReady !== 'true') return;
            event.preventDefault(); pauseForProcessing(audio);
            if (audio.dataset.effectsUrl) URL.revokeObjectURL(audio.dataset.effectsUrl);
            delete audio.dataset.effectsUrl;
            delete audio.dataset.effectsReady;
            delete audio.dataset.effectsProfile;
            audio.src = audio.dataset.originalSrc;
            audio.play().catch(() => {});
            return;
        }
        if (audio.dataset.effectsReady === 'true'
            && audio.dataset.effectsProfile === signature) return;
        event.preventDefault(); pauseForProcessing(audio);
        if (audio.dataset.effectsReady === 'true') {
            if (audio.dataset.effectsUrl) URL.revokeObjectURL(audio.dataset.effectsUrl);
            delete audio.dataset.effectsUrl;
            delete audio.dataset.effectsReady;
            audio.src = audio.dataset.originalSrc;
        }
        try {
            const source = await fetch(audio.dataset.originalSrc, { credentials: 'same-origin' });
            if (!source.ok) throw new Error('Nahrávku se nepodařilo načíst.');
            const rendered = await renderAudioEffects(await source.blob(), profile.chain);
            const currentProfile = readProfiles().get(
                audio.closest('[data-recording-id]')?.dataset.audioProfileId ?? ''
            );
            if (audio.dataset.effectsRequest !== requestId || !audio.isConnected
                || !currentProfile || JSON.stringify(currentProfile.chain) !== signature) return;
            const url = URL.createObjectURL(audioBufferToWav(rendered));
            if (audio.dataset.effectsUrl) URL.revokeObjectURL(audio.dataset.effectsUrl);
            audio.dataset.effectsReady = 'true'; audio.dataset.effectsUrl = url;
            audio.dataset.effectsProfile = signature;
            audio.src = url; await audio.play();
        } catch (error) {
            const status = document.querySelector('[data-project-status]');
            if (status) status.textContent = error.message || 'Zvukové efekty se nepodařilo zpracovat.';
        }
    }, true);
    document.addEventListener('click', async (event) => {
        const bookButton = event.target.closest('[data-audio-book-export]');
        if (bookButton) {
            event.preventDefault();
            if (bookButton.dataset.busy === 'true') return;
            bookButton.dataset.busy = 'true';
            const status = document.querySelector('[data-project-status]');
            try {
                const manifestResponse = await fetch(bookButton.dataset.url, {
                    credentials: 'same-origin', headers: { Accept: 'application/json' },
                });
                if (!manifestResponse.ok) throw new Error('Seznam nahrávek se nepodařilo načíst.');
                const manifest = await manifestResponse.json();
                if (manifest.ok !== true || !Array.isArray(manifest.takes)) {
                    throw new Error('Server vrátil neplatný seznam nahrávek.');
                }
                const profiles = new Map((manifest.profiles ?? []).map((profile) => [
                    String(profile.id), profile,
                ]));
                const entries = [];
                let total = 0;
                for (let index = 0; index < manifest.takes.length; index += 1) {
                    const take = manifest.takes[index];
                    if (status) status.textContent = `Připravuji ZIP: ${index + 1}/${manifest.takes.length}`;
                    const response = await fetch(take.audio_url, { credentials: 'same-origin' });
                    if (!response.ok) throw new Error(`Nahrávku ${take.file_name} se nepodařilo stáhnout.`);
                    const original = await response.blob();
                    total += original.size;
                    entries.push({ name: take.file_name, blob: original });
                    const profile = profiles.get(String(take.profile_id ?? ''));
                    if (profile && hasAudioEffects(profile.chain)) {
                        const effectCopy = await makeEffectMp3(original, profile.chain);
                        total += effectCopy.size;
                        entries.push({
                            name: take.file_name.replace(/\.[^.]+$/, '') + '_effects.mp3',
                            blob: effectCopy,
                        });
                    }
                    if (total > MAX_BOOK_DOWNLOAD_BYTES) {
                        throw new Error('Kniha přesahuje velikostní limit ZIPu prohlížeče. Použijte export bez efektů.');
                    }
                }
                const archive = await makeZip(entries);
                saveBlob(archive, manifest.file_name || 'audiokniha_audio.zip');
                if (status) status.textContent = 'ZIP s originály a kopiemi s efekty je připraven.';
            } catch (error) {
                if (status) status.textContent = error.message || 'ZIP se nepodařilo vytvořit.';
            } finally { delete bookButton.dataset.busy; }
            return;
        }
        const link = event.target.closest('[data-audio-double-download]');
        if (!link) return;
        if (link.dataset.bypassOriginal === 'true') {
            delete link.dataset.bypassOriginal;
            return;
        }
        const row = link.closest('[data-recording-id]');
        const profile = readProfiles().get(row?.dataset.audioProfileId ?? '');
        if (!profile || !hasAudioEffects(profile.chain)) return;
        event.preventDefault(); link.setAttribute('aria-busy', 'true');
        try {
            const status = document.querySelector('[data-project-status]');
            if (status) status.textContent = 'Připravuji ZIP s originálem a zvukovými efekty…';
            const response = await fetch(link.href, { credentials: 'same-origin' });
            if (!response.ok) throw new Error('Originální nahrávku se nepodařilo stáhnout.');
            const original = await response.blob();
            if (original.size > MAX_TAKE_DOWNLOAD_BYTES) throw new Error('Nahrávka je příliš velká pro společný ZIP. Stáhněte originál samostatně.');
            const effects = await makeEffectMp3(original, profile.chain);
            const base = (link.dataset.fileName || 'nahravka').replace(/\.[^.]+$/, '');
            const zip = await makeZip([
                { name: link.dataset.fileName || 'nahravka', blob: original },
                { name: `${base}_effects.mp3`, blob: effects },
            ]);
            saveBlob(zip, `${base}_audio.zip`);
            if (status) status.textContent = 'ZIP s originálem a kopií s efekty je připraven.';
        } catch (error) {
            const status = document.querySelector('[data-project-status]');
            link.dataset.bypassOriginal = 'true';
            if (status) status.textContent = `${error.message || 'ZIP se nepodařilo vytvořit.'} Klikněte znovu pro stažení originálu.`;
        } finally { link.removeAttribute('aria-busy'); }
    });
    window.dabingAudioPlaybackUrl = playbackUrl;
    window.addEventListener('pagehide', () => {
        processedPlayback.forEach((entry) => {
            if (entry.url) URL.revokeObjectURL(entry.url);
        });
        processedPlayback.clear();
    });
}

bindStudioAudioProfiles();
