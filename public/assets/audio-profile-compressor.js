/*
 * Project: Dabing
 * Repository: klusik/dabing
 * File: public/assets/audio-profile-compressor.js
 * Module Type: Browser DSP effect
 * Purpose: Compress dynamic peaks and apply make-up gain.
 * Responsibilities: Configure the native dynamics compressor and output gain.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
/**
 * Render compression to a new buffer while preserving channel count and duration.
 * @param {AudioBuffer} input - Decoded source or previous effect output.
 * @param {{threshold:number,ratio:number,gain:number}} settings - Compressor settings.
 * @returns {Promise<AudioBuffer>} Compressed audio.
 */
export async function processCompressor(input, settings) {
    const context = new OfflineAudioContext(input.numberOfChannels, input.length, input.sampleRate);
    const source = context.createBufferSource();
    const compressor = context.createDynamicsCompressor();
    const makeup = context.createGain();
    source.buffer = input;
    compressor.threshold.value = clamp(settings.threshold, -60, 0);
    compressor.ratio.value = clamp(settings.ratio, 1, 20);
    compressor.knee.value = 12;
    compressor.attack.value = 0.003;
    compressor.release.value = 0.18;
    makeup.gain.value = 10 ** (clamp(settings.gain, 0, 24) / 20);
    source.connect(compressor).connect(makeup).connect(context.destination);
    source.start(0);
    return context.startRendering();
}

/**
 * Clamp a numeric compressor setting.
 * @param {number} value - Requested value.
 * @param {number} minimum - Inclusive lower bound.
 * @param {number} maximum - Inclusive upper bound.
 * @returns {number} Clamped finite value.
 */
function clamp(value, minimum, maximum) {
    return Math.min(maximum, Math.max(minimum, Number(value) || 0));
}
