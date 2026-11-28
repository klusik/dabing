/*
 * Project: Dabing
 * Repository: klusik/dabing
 * File: public/assets/audio-profiles/normalize.js
 * Module Type: Browser DSP effect
 * Purpose: Normalize peak level at its exact chain position.
 * Responsibilities: Measure the preceding buffer and apply peak gain.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
/**
 * Normalize the peak of the audio received at this chain position.
 * @param {AudioBuffer} input - Audio after all preceding enabled effects.
 * @param {{target:number}} settings - Target peak in dBFS.
 * @returns {Promise<AudioBuffer>} Peak-normalized audio.
 */
export async function processNormalize(input, settings) {
    const targetDb = clamp(settings.target, -24, 0);
    let peak = 0;
    for (let channel = 0; channel < input.numberOfChannels; channel += 1) {
        const samples = input.getChannelData(channel);
        for (let index = 0; index < samples.length; index += 1) {
            peak = Math.max(peak, Math.abs(samples[index]));
        }
    }
    const output = new AudioBuffer({
        numberOfChannels: input.numberOfChannels,
        length: input.length,
        sampleRate: input.sampleRate,
    });
    const gain = peak > 0 ? (10 ** (targetDb / 20)) / peak : 1;
    for (let channel = 0; channel < input.numberOfChannels; channel += 1) {
        const source = input.getChannelData(channel);
        const destination = output.getChannelData(channel);
        for (let index = 0; index < source.length; index += 1) {
            destination[index] = source[index] * gain;
        }
    }
    return output;
}

/**
 * Clamp a numeric normalization target.
 * @param {number} value - Requested dBFS value.
 * @param {number} minimum - Inclusive lower bound.
 * @param {number} maximum - Inclusive upper bound.
 * @returns {number} Clamped finite value.
 */
function clamp(value, minimum, maximum) {
    return Math.min(maximum, Math.max(minimum, Number(value) || 0));
}
