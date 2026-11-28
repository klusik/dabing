/*
 * Project: Dabing
 * Repository: klusik/dabing
 * File: public/assets/audio-profiles/engine.js
 * Module Type: Browser audio engine
 * Purpose: Decode audio and render effects in saved order.
 * Responsibilities: Bound source memory and dispatch isolated DSP modules.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
import { processEq } from './eq.js';
import { processCompressor } from './compressor.js';
import { processNormalize } from './normalize.js';
import { processReverb } from './reverb.js';
/** Type: number; Units: bytes; Scope: source; Consumers: local picker/engine.
 * Rationale: 20 MiB bounds decode memory for local input files.
 */
export const MAX_SOURCE_BYTES = 20971520;
/** Type: number; Units: seconds; Scope: decoded source; Consumers: engine.
 * Rationale: 181 s accepts 180 s takes plus codec frame padding.
 */
export const MAX_SOURCE_SECONDS = 181;
/** Type: number; Units: seconds; Scope: rendered output; Consumers: callers.
 * Rationale: 184 s permits source padding plus a maximum 3 s reverb tail.
 */
export const MAX_RENDER_SECONDS = 184;
const processors = Object.freeze({
    eq: processEq,
    compressor: processCompressor,
    normalize: processNormalize,
    reverb: processReverb,
});
/**
 * Decode a Blob and apply enabled supported effects in saved order.
 * @param {Blob} blob - Browser-supported audio source.
 * @param {Array<{type:string,enabled:boolean,settings:Object}>} chain - Ordered effects.
 * @param {?function({type:string,enabled:boolean,settings:Object},AudioBuffer,AudioBuffer):void} [onStage] - Optional observer called after every known stage, including bypassed stages.
 * @returns {Promise<AudioBuffer>} Rendered audio buffer.
 * @throws {Error} On bounds, decode or DSP errors.
 */
export async function renderAudioEffects(blob, chain, onStage = null) {
    if (!(blob instanceof Blob)) {
        throw new TypeError('Zvukový zdroj není soubor.');
    }
    if (blob.size > MAX_SOURCE_BYTES) {
        throw new RangeError('Soubor překračuje limit 20 MiB.');
    }
    const context = new AudioContext();
    try {
        const input = await context.decodeAudioData(await blob.arrayBuffer());
        if (input.duration > MAX_SOURCE_SECONDS) {
            throw new RangeError('Zdroj je delší než limit 181 sekund.');
        }
        let output = input;
        const stages = Array.isArray(chain) ? chain : [];
        for (const effect of stages) {
            const processor = effect?.type && Object.hasOwn(processors, effect.type)
                ? processors[effect.type]
                : null;
            if (!processor) continue;
            const stageInput = output;
            if (effect.enabled === true) {
                output = await processor(output, effect.settings ?? {});
                if (output.duration > MAX_RENDER_SECONDS) {
                    throw new RangeError('Výstup je delší než limit 184 sekund.');
                }
            }
            if (typeof onStage === 'function') {
                onStage(effect, stageInput, output);
            }
        }
        return output;
    } finally {
        await context.close();
    }
}
/**
 * Check whether an effect chain contains an active supported module.
 * @param {Array<{type:string,enabled:boolean,settings:Object}>} chain - Ordered effects.
 * @returns {boolean} True when a known effect is enabled.
 */
export function hasAudioEffects(chain) {
    return Array.isArray(chain)
        && chain.some((effect) => effect?.enabled === true
            && Object.hasOwn(processors, effect.type));
}
