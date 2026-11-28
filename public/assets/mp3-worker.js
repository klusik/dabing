/*
 * Project: Dabing
 * Repository: klusik/dabing
 * File: public/assets/mp3-worker.js
 * Module Type: Dedicated browser worker
 * Purpose: Encode normalized mono PCM samples as an MP3 take.
 * Responsibilities: Validate bounded PCM input, encode fixed-format MP3, and transfer a bounded result.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
'use strict';

/**
 * Target PCM sample rate.
 * Type: number
 * Units: samples per second (Hz)
 * Scope: mono input and MP3 output for one take
 * Consumers: message validation and LAME encoder construction
 * Rationale: keep a predictable 44.1 kHz output after UI resampling.
 * Value: 44100
 */
const TARGET_SAMPLE_RATE_HZ = 44100;

/**
 * Target constant bit rate.
 * Type: number
 * Units: kilobits per second
 * Scope: one MP3 take
 * Consumers: LAME encoder construction
 * Rationale: balance speech clarity and upload size.
 * Value: 128
 */
const TARGET_BITRATE_KBPS = 128;

/**
 * Required encoded channel count.
 * Type: number
 * Units: channels
 * Scope: one speech take
 * Consumers: LAME encoder construction
 * Rationale: voice takes are stored as compact mono audio.
 * Value: 1
 */
const CHANNEL_COUNT = 1;

/**
 * Encoder chunk size.
 * Type: number
 * Units: PCM samples
 * Scope: each encoder call
 * Consumers: sample slicing loop
 * Rationale: align with the MP3 encoder frame size.
 * Value: 1152
 */
const SAMPLES_PER_CHUNK = 1152;

/**
 * Maximum take duration accepted by the worker.
 * Type: number
 * Units: seconds
 * Scope: one audio conversion request
 * Consumers: maximum sample count
 * Rationale: allow 180-second recordings plus up to four seconds of rendered
 * audio tail while keeping the upload source limit enforced by the recorder.
 * Value: 184
 */
const MAX_TAKE_SECONDS = 184;

/**
 * Maximum mono input length.
 * Type: number
 * Units: Int16 samples
 * Scope: one conversion request at the target sample rate
 * Consumers: PCM validation and encoder loop
 * Rationale: bound worker memory and CPU to the maximum processed export length.
 * Value: 8114400
 */
const MAX_INPUT_SAMPLES = TARGET_SAMPLE_RATE_HZ * MAX_TAKE_SECONDS;

/**
 * Maximum encoded result length.
 * Type: number
 * Units: bytes
 * Scope: one MP3 output buffer
 * Consumers: chunk accumulation and final allocation
 * Rationale: bound output allocation above a 184-second 128 kbps stream.
 * Value: 4194304
 */
const MAX_OUTPUT_BYTES = 4194304;

let encoderAvailable = false;
try {
    importScripts('./lame-all.js');
    encoderAvailable = typeof lamejs !== 'undefined'
        && typeof lamejs.Mp3Encoder === 'function';
} catch (error) {
    encoderAvailable = false;
}

/**
 * Validate a single mono PCM conversion request.
 * @param {unknown} value - Candidate worker message payload.
 * @returns {{samples:Int16Array,sampleRate:number}|null} Valid input or null.
 */
function validateRequest(value) {
    if (!value || typeof value !== 'object') return null;
    const request = /** @type {{samples?:unknown,sampleRate?:unknown}} */ (value);
    if (!(request.samples instanceof Int16Array)
        || request.samples.length < 1
        || request.samples.length > MAX_INPUT_SAMPLES
        || request.samples.buffer.byteLength > MAX_INPUT_SAMPLES * Int16Array.BYTES_PER_ELEMENT
        || request.sampleRate !== TARGET_SAMPLE_RATE_HZ) {
        return null;
    }
    return {
        samples: request.samples,
        sampleRate: request.sampleRate
    };
}

/**
 * Convert one bounded PCM request into a transferable MP3 buffer.
 * @param {{samples:Int16Array,sampleRate:number}} request - Validated mono PCM.
 * @returns {ArrayBuffer} Encoded MP3 bytes.
 * @throws {Error} If the encoder fails or output exceeds its bound.
 */
function encodeMp3(request) {
    const encoder = new lamejs.Mp3Encoder(
        CHANNEL_COUNT,
        request.sampleRate,
        TARGET_BITRATE_KBPS
    );
    /** @type {Uint8Array[]} */
    const chunks = [];
    let totalBytes = 0;

    const append = (chunk) => {
        if (!chunk || chunk.length === 0) return;
        const bytes = chunk instanceof Uint8Array
            ? chunk
            : new Uint8Array(chunk);
        totalBytes += bytes.byteLength;
        if (totalBytes > MAX_OUTPUT_BYTES) {
            throw new Error('Encoded output exceeds its limit.');
        }
        chunks.push(bytes);
    };

    for (let offset = 0; offset < request.samples.length;
        offset += SAMPLES_PER_CHUNK) {
        append(encoder.encodeBuffer(
            request.samples.subarray(offset, offset + SAMPLES_PER_CHUNK)
        ));
    }
    append(encoder.flush());

    const output = new Uint8Array(totalBytes);
    let outputOffset = 0;
    for (const chunk of chunks) {
        output.set(chunk, outputOffset);
        outputOffset += chunk.byteLength;
    }
    return output.buffer;
}

self.onmessage = (event) => {
    const request = validateRequest(event.data);
    if (!encoderAvailable || request === null) {
        self.postMessage({ok: false});
        return;
    }
    try {
        const buffer = encodeMp3(request);
        self.postMessage({ok: true, buffer}, [buffer]);
    } catch (error) {
        self.postMessage({ok: false});
    }
};
