/*
 * Project: Dabing
 * Repository: klusik/dabing
 * File: public/assets/audio-profiles/reverb.js
 * Module Type: Browser DSP effect
 * Purpose: Add deterministic convolution reverb with a controllable tail.
 * Responsibilities: Generate a bounded seeded impulse and mix dry/wet signals.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
const REVERB_MAX_TAIL_SECONDS = 3;

/**
 * Render deterministic convolution reverb and append its decaying tail.
 * @param {AudioBuffer} input - Decoded source or previous effect output.
 * @param {{mix:number,decay:number}} settings - Wet percentage and decay time.
 * @returns {Promise<AudioBuffer>} Reverb output including its generated tail.
 */
export async function processReverb(input, settings) {
    const decay = clamp(settings.decay, 0.1, REVERB_MAX_TAIL_SECONDS);
    const mix = clamp(settings.mix, 0, 100) / 100;
    const tail = Math.ceil(decay * input.sampleRate);
    const length = input.length + tail;
    const context = new OfflineAudioContext(input.numberOfChannels, length, input.sampleRate);
    const source = context.createBufferSource();
    const convolver = context.createConvolver();
    const dry = context.createGain();
    const wet = context.createGain();
    const merger = context.createGain();
    const impulse = context.createBuffer(input.numberOfChannels, tail, input.sampleRate);
    source.buffer = input;
    dry.gain.value = 1 - mix;
    wet.gain.value = mix;
    for (let channel = 0; channel < impulse.numberOfChannels; channel += 1) {
        const samples = impulse.getChannelData(channel);
        for (let index = 0; index < samples.length; index += 1) {
            const time = index / input.sampleRate;
            const seeded = Math.sin((index + 1) * (channel + 17) * 12.9898) * 43758.5453;
            const noise = (seeded - Math.floor(seeded)) * 2 - 1;
            samples[index] = noise * Math.exp(-6.9078 * time / decay);
        }
    }
    convolver.buffer = impulse;
    source.connect(dry).connect(merger);
    source.connect(convolver).connect(wet).connect(merger);
    merger.connect(context.destination);
    source.start(0);
    return context.startRendering();
}

/**
 * Clamp a numeric reverb setting.
 * @param {number} value - Requested value.
 * @param {number} minimum - Inclusive lower bound.
 * @param {number} maximum - Inclusive upper bound.
 * @returns {number} Clamped finite value.
 */
function clamp(value, minimum, maximum) {
    return Math.min(maximum, Math.max(minimum, Number(value) || 0));
}
