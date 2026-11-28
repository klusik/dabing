/*
 * Project: Dabing
 * Repository: klusik/dabing
 * File: public/assets/audio-profile-preview-analysis.js
 * Module Type: Browser audio analysis
 * Purpose: Capture compact stage-by-stage traces for profile previews.
 * Responsibilities: Summarize levels and EQ spectra without retaining stage audio buffers.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
/** Peak-level window duration.
 * Type: number; Units: seconds; Scope: one signal trace; Consumers: level analyzer.
 * Rationale: 25 ms shows speech dynamics while bounding an 184-second trace to
 * at most 7,360 points. Value: 0.025 seconds.
 */
const LEVEL_STEP_SECONDS = 0.025;
/** EQ spectrum sampling interval.
 * Type: number; Units: seconds; Scope: one signal trace; Consumers: FFT analyzer.
 * Rationale: Ten frames per second preserve visible EQ changes without
 * keeping waveform-rate spectra for long recordings. Value: 0.1 seconds.
 */
const SPECTRUM_STEP_SECONDS = 0.1;
/** FFT window length.
 * Type: number; Units: samples; Scope: one spectrum frame; Consumers: FFT.
 * Rationale: 2,048 samples resolve low speech frequencies while keeping each
 * 10 Hz spectrum pass bounded. Value: 2,048 samples.
 */
const FFT_SIZE = 2048;
/** Number of logarithmic EQ bands in each spectrum frame.
 * Type: number; Units: bands; Scope: one EQ spectrum frame; Consumers: charts.
 * Rationale: 64 bands retain useful frequency shape in compact chart traces.
 * Value: 64 bands.
 */
const SPECTRUM_BANDS = 64;
/** Lower frequency edge for logarithmic spectrum bands.
 * Type: number; Units: hertz; Scope: EQ spectrum; Consumers: band mapping.
 * Rationale: 20 Hz includes the lowest useful audio range. Value: 20 Hz.
 */
const MIN_SPECTRUM_HZ = 20;
/** Upper frequency limit used when the source sample rate permits it.
 * Type: number; Units: hertz; Scope: EQ spectrum; Consumers: band mapping.
 * Rationale: 20 kHz covers audible frequencies while respecting source Nyquist.
 * Value: 20,000 Hz.
 */
const MAX_SPECTRUM_HZ = 20000;
/** Display floor for silent/near-silent level and spectrum samples.
 * Type: number; Units: dBFS; Scope: all signal traces; Consumers: chart axes.
 * Rationale: -120 dBFS avoids negative infinity and exceeds practical audio
 * measurement range. Value: -120 dBFS.
 */
const DB_FLOOR = -120;
/** Reusable compact summaries keyed weakly by their source AudioBuffer.
 * @type {WeakMap<AudioBuffer, Map<boolean, SignalTrace>>}
 */
const signalTraceCache = new WeakMap();

/** @typedef {{values:Float32Array,stepSeconds:number,duration:number,peakDb:number}} LevelTrace */
/** @typedef {{frames:Float32Array,frequencies:Float32Array,frameCount:number,stepSeconds:number,duration:number}} SpectrumTrace */
/** @typedef {{level:LevelTrace,spectrum:SpectrumTrace|null}} SignalTrace */
/** @typedef {{enabled:boolean,input:SignalTrace,output:SignalTrace}} StageTrace */

/** Render one chain and capture compact before/after traces for each known stage.
 * @param {Blob} blob - Original recording bytes, kept immutable by this module.
 * @param {Array<{type:string,enabled:boolean,settings:Object}>} chain - Ordered effects.
 * @param {(blob:Blob,chain:Array<{type:string,enabled:boolean,settings:Object}>,onStage:(effect:{type:string,enabled:boolean,settings:Object},input:AudioBuffer,output:AudioBuffer)=>void)=>Promise<AudioBuffer>} renderAudioEffects - Renderer loaded by the caller from its selected engine URL.
 * @returns {Promise<{audio:AudioBuffer,stages:Map<string,StageTrace>}>} Rendered audio and stage traces keyed by effect type.
 * @throws {Error} When decode, DSP or analysis bounds fail.
 */
export async function renderProfilePreview(blob, chain, renderAudioEffects) {
    if (typeof renderAudioEffects !== 'function') {
        throw new TypeError('Předejte funkci renderAudioEffects z načteného enginu.');
    }
    const stages = new Map();
    const audio = await renderAudioEffects(blob, chain, (effect, input, output) => {
        const includeSpectrum = effect.type === 'eq';
        stages.set(effect.type, {
            enabled: effect.enabled === true,
            input: summarizeSignal(input, includeSpectrum),
            output: summarizeSignal(output, includeSpectrum),
        });
    });
    return { audio, stages };
}

/** Reuse or compute compact level and optional spectrum summaries for a buffer.
 * @param {AudioBuffer} buffer - Stage input or output buffer.
 * @param {boolean} includeSpectrum - Whether this is an EQ stage trace.
 * @returns {SignalTrace} Trace data without retaining the source buffer.
 */
function summarizeSignal(buffer, includeSpectrum) {
    let variants = signalTraceCache.get(buffer);
    if (!variants) {
        variants = new Map();
        signalTraceCache.set(buffer, variants);
    }
    const cached = variants.get(includeSpectrum);
    if (cached) return cached;
    const level = Array.from(variants.values())[0]?.level
        ?? measurePeakLevels(buffer);
    const spectrum = includeSpectrum ? measureSpectrum(buffer) : null;
    const trace = { level, spectrum };
    variants.set(includeSpectrum, trace);
    return trace;
}

/** Measure maximum absolute sample amplitude in fixed time windows.
 * @param {AudioBuffer} buffer - Source signal.
 * @returns {LevelTrace} Peak dBFS values and time scale.
 */
function measurePeakLevels(buffer) {
    const frameCount = Math.ceil(buffer.duration / LEVEL_STEP_SECONDS);
    const values = new Float32Array(frameCount);
    const channels = Array.from(
        { length: buffer.numberOfChannels },
        (_, index) => buffer.getChannelData(index)
    );
    let peakAmplitude = 0;
    for (let frame = 0; frame < frameCount; frame += 1) {
        const start = Math.floor(frame * LEVEL_STEP_SECONDS * buffer.sampleRate);
        const end = Math.min(
            buffer.length,
            Math.ceil((frame + 1) * LEVEL_STEP_SECONDS * buffer.sampleRate)
        );
        let windowPeak = 0;
        for (const channel of channels) {
            for (let sample = start; sample < end; sample += 1) {
                const amplitude = Math.abs(channel[sample]);
                if (amplitude > windowPeak) windowPeak = amplitude;
            }
        }
        if (windowPeak > peakAmplitude) peakAmplitude = windowPeak;
        values[frame] = amplitudeToDb(windowPeak);
    }
    return {
        values,
        stepSeconds: LEVEL_STEP_SECONDS,
        duration: buffer.duration,
        peakDb: amplitudeToDb(peakAmplitude),
    };
}

/** Convert an amplitude ratio to finite dBFS for chart traces.
 * @param {number} amplitude - Linear amplitude where 1 represents full scale.
 * @returns {number} dBFS value, floored for silence.
 */
function amplitudeToDb(amplitude) {
    return amplitude > 0
        ? Math.max(DB_FLOOR, 20 * Math.log10(amplitude))
        : DB_FLOOR;
}

/** Measure log-spaced, Hann-windowed FFT bands at ten frames per second.
 * @param {AudioBuffer} buffer - Source signal for one EQ stage side.
 * @returns {SpectrumTrace} Frame-major dBFS band values and frequency axis.
 */
function measureSpectrum(buffer) {
    const nyquist = buffer.sampleRate / 2;
    const upperHz = Math.min(MAX_SPECTRUM_HZ, nyquist);
    const lowerHz = Math.min(MIN_SPECTRUM_HZ, upperHz / 2);
    const ratio = upperHz / lowerHz;
    const frequencies = new Float32Array(SPECTRUM_BANDS);
    const bandBins = [];
    for (let band = 0; band < SPECTRUM_BANDS; band += 1) {
        const lowHz = lowerHz * (ratio ** (band / SPECTRUM_BANDS));
        const highHz = lowerHz * (ratio ** ((band + 1) / SPECTRUM_BANDS));
        frequencies[band] = Math.sqrt(lowHz * highHz);
        const firstBin = Math.max(1, Math.ceil(lowHz * FFT_SIZE / buffer.sampleRate));
        const lastBin = Math.min(
            FFT_SIZE / 2 - 1,
            Math.max(firstBin, Math.floor(highHz * FFT_SIZE / buffer.sampleRate))
        );
        bandBins.push({ firstBin, lastBin });
    }
    const frameCount = Math.ceil(buffer.duration / SPECTRUM_STEP_SECONDS);
    const frames = new Float32Array(frameCount * SPECTRUM_BANDS);
    const channels = Array.from(
        { length: buffer.numberOfChannels },
        (_, index) => buffer.getChannelData(index)
    );
    const window = makeHannWindow(FFT_SIZE);
    const windowSum = window.reduce((sum, value) => sum + value, 0);
    const real = new Float64Array(FFT_SIZE);
    const imaginary = new Float64Array(FFT_SIZE);
    const powers = new Float64Array(FFT_SIZE / 2);
    const sampleStep = Math.max(1, Math.round(SPECTRUM_STEP_SECONDS * buffer.sampleRate));
    const halfWindow = FFT_SIZE / 2;
    for (let frame = 0; frame < frameCount; frame += 1) {
        powers.fill(0);
        const center = frame * sampleStep;
        for (const channel of channels) {
            for (let index = 0; index < FFT_SIZE; index += 1) {
                const sampleIndex = center + index - halfWindow;
                const sample = sampleIndex >= 0 && sampleIndex < channel.length
                    ? channel[sampleIndex]
                    : 0;
                real[index] = sample * window[index];
                imaginary[index] = 0;
            }
            fftInPlace(real, imaginary);
            for (let bin = 1; bin < powers.length; bin += 1) {
                powers[bin] += real[bin] * real[bin]
                    + imaginary[bin] * imaginary[bin];
            }
        }
        const channelCount = Math.max(1, channels.length);
        const base = frame * SPECTRUM_BANDS;
        for (let band = 0; band < SPECTRUM_BANDS; band += 1) {
            const { firstBin, lastBin } = bandBins[band];
            let power = 0;
            let binCount = 0;
            for (let bin = firstBin; bin <= lastBin; bin += 1) {
                power += powers[bin];
                binCount += 1;
            }
            if (binCount === 0) {
                const nearest = Math.min(powers.length - 1, firstBin);
                power = powers[nearest];
                binCount = 1;
            }
            const amplitude = Math.sqrt(power / (binCount * channelCount))
                * 2 / Math.max(1, windowSum);
            frames[base + band] = amplitudeToDb(amplitude);
        }
    }
    return {
        frames,
        frequencies,
        frameCount,
        stepSeconds: SPECTRUM_STEP_SECONDS,
        duration: buffer.duration,
    };
}

/** Build a Hann window for leakage-reduced spectrum frames.
 * @param {number} size - Number of samples in the FFT window.
 * @returns {Float64Array} Window coefficients.
 */
function makeHannWindow(size) {
    const values = new Float64Array(size);
    for (let index = 0; index < size; index += 1) {
        values[index] = 0.5 - 0.5 * Math.cos(2 * Math.PI * index / (size - 1));
    }
    return values;
}

/** Transform real and imaginary arrays with an iterative radix-2 FFT.
 * @param {Float64Array} real - Real input and output values.
 * @param {Float64Array} imaginary - Imaginary input and output values.
 * @returns {void} Arrays contain unnormalized frequency-domain coefficients.
 */
function fftInPlace(real, imaginary) {
    const size = real.length;
    let reversed = 0;
    for (let index = 1; index < size; index += 1) {
        let bit = size >> 1;
        while (reversed & bit) {
            reversed ^= bit;
            bit >>= 1;
        }
        reversed ^= bit;
        if (index < reversed) {
            [real[index], real[reversed]] = [real[reversed], real[index]];
            [imaginary[index], imaginary[reversed]] = [imaginary[reversed], imaginary[index]];
        }
    }
    for (let length = 2; length <= size; length <<= 1) {
        const angle = -2 * Math.PI / length;
        const stepReal = Math.cos(angle);
        const stepImaginary = Math.sin(angle);
        const half = length >> 1;
        for (let start = 0; start < size; start += length) {
            let twiddleReal = 1;
            let twiddleImaginary = 0;
            for (let offset = 0; offset < half; offset += 1) {
                const even = start + offset;
                const odd = even + half;
                const oddReal = real[odd] * twiddleReal
                    - imaginary[odd] * twiddleImaginary;
                const oddImaginary = real[odd] * twiddleImaginary
                    + imaginary[odd] * twiddleReal;
                real[odd] = real[even] - oddReal;
                imaginary[odd] = imaginary[even] - oddImaginary;
                real[even] += oddReal;
                imaginary[even] += oddImaginary;
                const nextReal = twiddleReal * stepReal
                    - twiddleImaginary * stepImaginary;
                twiddleImaginary = twiddleReal * stepImaginary
                    + twiddleImaginary * stepReal;
                twiddleReal = nextReal;
            }
        }
    }
}
