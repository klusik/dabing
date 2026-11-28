/*
 * Project: Dabing
 * Repository: klusik/dabing
 * File: public/assets/audio-profile-eq.js
 * Module Type: Browser DSP effect
 * Purpose: Apply the six-band parametric equalizer.
 * Responsibilities: Build logarithmic peaking filters and optional edge cuts.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
const FREQUENCY_MIN_HZ = 20;
const FREQUENCY_MAX_HZ = 20000;
const BAND_COUNT = 6;

/**
 * Render equalization and edge filters into a fresh audio buffer.
 * @param {AudioBuffer} input - Decoded source or previous effect output.
 * @param {{bands: Array<{frequency:number,gain:number,q:number,cutoff:boolean}>}} settings - EQ settings.
 * @returns {Promise<AudioBuffer>} Processed audio with the input duration.
 */
export async function processEq(input, settings) {
    const context = new OfflineAudioContext(input.numberOfChannels, input.length, input.sampleRate);
    const source = context.createBufferSource();
    source.buffer = input;
    let previous = source;
    const bands = Array.isArray(settings?.bands) ? settings.bands : [];
    bands.slice(0, BAND_COUNT).forEach((band, index) => {
        if (band.cutoff && index === 0) {
            const filter = context.createBiquadFilter();
            filter.type = 'highpass';
            filter.frequency.value = clamp(band.frequency, FREQUENCY_MIN_HZ, FREQUENCY_MAX_HZ);
            filter.Q.value = clamp(band.q, 0.2, 8);
            previous.connect(filter);
            previous = filter;
            return;
        }
        if (band.cutoff && index === BAND_COUNT - 1) {
            const filter = context.createBiquadFilter();
            filter.type = 'lowpass';
            filter.frequency.value = clamp(band.frequency, FREQUENCY_MIN_HZ, FREQUENCY_MAX_HZ);
            filter.Q.value = clamp(band.q, 0.2, 8);
            previous.connect(filter);
            previous = filter;
            return;
        }
        const filter = context.createBiquadFilter();
        filter.type = 'peaking';
        filter.frequency.value = clamp(band.frequency, FREQUENCY_MIN_HZ, FREQUENCY_MAX_HZ);
        filter.Q.value = clamp(band.q, 0.2, 8);
        filter.gain.value = clamp(band.gain, -18, 18);
        previous.connect(filter);
        previous = filter;
    });
    previous.connect(context.destination);
    source.start(0);
    return context.startRendering();
}

/**
 * Keep a numeric setting inside its supported browser DSP range.
 * @param {number} value - Requested value.
 * @param {number} minimum - Inclusive lower bound.
 * @param {number} maximum - Inclusive upper bound.
 * @returns {number} Clamped finite value.
 */
function clamp(value, minimum, maximum) {
    return Math.min(maximum, Math.max(minimum, Number(value) || 0));
}
