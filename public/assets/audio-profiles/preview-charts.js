/*
 * Project: Dabing
 * Repository: klusik/dabing
 * File: public/assets/audio-profiles/preview-charts.js
 * Module Type: Browser preview charts
 * Purpose: Draw measured before/after effect previews for saved profiles.
 * Responsibilities: Render compact signal, spectrum and native EQ response plots.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */

/** Type: number; Units: device pixels per CSS pixel; Scope: preview canvases;
 * Consumers: prepareCanvas(); Rationale: cap DPR at 2 for crisp mobile charts
 * without unbounded backing-store memory.
 */
const MAX_DEVICE_PIXEL_RATIO = 2;

/** Type: number; Units: plot samples; Scope: native EQ response cache;
 * Consumers: eqResponse(); Rationale: up to 512 log bins keep canvas curves
 * smooth while bounding filter calculations on narrow screens.
 */
const MAX_EQ_RESPONSE_SAMPLES = 512;

/** Type: number; Units: time-series points; Scope: one rendered trace;
 * Consumers: drawLevelTrace(); Rationale: limit each redraw to 1800 points
 * while retaining visible peaks at the canvas's pixel scale.
 */
const MAX_LEVEL_TRACE_POINTS = 1800;

/** Type: number; Units: decibels full scale; Scope: level and FFT plots;
 * Consumers: levelY(), spectrumY(); Rationale: -96 dBFS shows the practical
 * noise floor while 0 dBFS remains the upper clipping reference.
 */
const MIN_DBFS = -96;
const MAX_DBFS = 0;

/** Type: number; Units: samples; Scope: Web Audio response helper;
 * Consumers: eqResponse(); Rationale: one offline frame is sufficient because
 * getFrequencyResponse() does not render audio.
 */
const RESPONSE_CONTEXT_LENGTH = 1;

const PALETTE = Object.freeze({
    background: '#253128',
    grid: '#536154',
    label: '#a8b2a5',
    input: '#83cadd',
    output: '#d7ed8a',
    configured: '#eee6d2',
    empty: '#c3cabc',
});

const PREVIEW_NUMBER_FORMATTER = new Intl.NumberFormat('cs-CZ', {
    maximumSignificantDigits: 3,
    useGrouping: false,
});

let responseContext = null;
let cachedResponseKey = '';
let cachedResponse = null;

/** Format a finite value with Czech decimal rules and at most three significant digits.
 * @param {number} value Numeric value to display.
 * @returns {string} Localized value, or an em dash for non-finite input.
 */
export function formatPreviewNumber(value) {
    if (!Number.isFinite(value)) {
        return '—';
    }
    const normalized = Object.is(value, -0) ? 0 : value;
    return PREVIEW_NUMBER_FORMATTER.format(normalized);
}

/** Draw measured traces for one selected effect in a responsive canvas.
 * @param {HTMLCanvasElement} canvas Target chart canvas.
 * @param {{effect:{type:string,enabled:boolean,settings:Object},stage:null|{enabled:boolean,input:{level:{values:Float32Array,stepSeconds:number,duration:number,peakDb:number},spectrum:null|{frames:Float32Array,frequencies:Float32Array,frameCount:number,stepSeconds:number,duration:number}},output:{level:{values:Float32Array,stepSeconds:number,duration:number,peakDb:number},spectrum:null|{frames:Float32Array,frequencies:Float32Array,frameCount:number,stepSeconds:number,duration:number}}},time:number,original:boolean}} options Current effect, its measured stage, playhead time and source mode.
 * @returns {void} Paints the chart; missing traces show a labeled empty grid.
 */
export function drawEffectPreview(canvas, options) {
    const ctx = prepareCanvas(canvas);
    if (!ctx) {
        return;
    }
    const { width, height } = canvas.getBoundingClientRect();
    const effect = options?.effect;
    if (!effect || typeof effect.type !== 'string') {
        drawEmpty(ctx, width, height, 'Vyberte efekt');
        return;
    }
    const stage = options.stage;
    const time = Number.isFinite(options.time) ? Math.max(0, options.time) : 0;
    const original = options.original === true;

    if (effect.type === 'eq') {
        const splitY = Math.max(62, Math.round(height * 0.55));
        const spectrumRect = plotRect(width, splitY, true);
        const levelPlot = plotRect(width, height - splitY, false);
        const levelRect = {
            ...levelPlot,
            top: levelPlot.top + splitY,
            bottom: levelPlot.bottom + splitY,
        };
        drawSpectrumPlot(
            ctx, spectrumRect, effect, stage, time, original
        );
        drawLevelPlot(ctx, levelRect, effect, stage, time, original);
        return;
    }

    const rect = plotRect(width, height, false);
    drawLevelPlot(ctx, rect, effect, stage, time, original);
}

/** Prepare a DPR-scaled canvas and clear its current CSS-sized surface.
 * @param {HTMLCanvasElement} canvas Target chart canvas.
 * @returns {CanvasRenderingContext2D|null} Scaled context or null when hidden.
 */
function prepareCanvas(canvas) {
    const rect = canvas.getBoundingClientRect();
    if (rect.width < 1 || rect.height < 1) {
        return null;
    }
    const pixelRatio = Math.min(
        MAX_DEVICE_PIXEL_RATIO,
        Math.max(1, window.devicePixelRatio || 1)
    );
    const pixelWidth = Math.round(rect.width * pixelRatio);
    const pixelHeight = Math.round(rect.height * pixelRatio);
    if (canvas.width !== pixelWidth || canvas.height !== pixelHeight) {
        canvas.width = pixelWidth;
        canvas.height = pixelHeight;
    }
    const ctx = canvas.getContext('2d');
    if (!ctx) {
        return null;
    }
    ctx.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
    ctx.fillStyle = PALETTE.background;
    ctx.fillRect(0, 0, rect.width, rect.height);
    ctx.font = '10px system-ui, sans-serif';
    ctx.lineWidth = 1;
    return ctx;
}

/** Compute compact plot bounds with room for labels and a stable chart grid.
 * @param {number} width CSS canvas width.
 * @param {number} height Plot surface height.
 * @param {boolean} spectrum Whether the plot needs frequency labels.
 * @returns {{left:number,right:number,top:number,bottom:number,width:number,height:number}} Plot bounds in CSS pixels.
 */
function plotRect(width, height, spectrum) {
    const left = spectrum ? 34 : 38;
    const right = spectrum ? 28 : 8;
    const top = spectrum ? 15 : 9;
    const bottom = spectrum ? 18 : 19;
    return {
        left,
        right: Math.max(left + 1, width - right),
        top,
        bottom: Math.max(top + 1, height - bottom),
        width: Math.max(1, width - left - right),
        height: Math.max(1, height - top - bottom),
    };
}

/** Draw a measured dBFS trace against time with markers and playback cursor.
 * @param {CanvasRenderingContext2D} ctx Scaled canvas context.
 * @param {{left:number,right:number,top:number,bottom:number,width:number,height:number}} rect Plot bounds.
 * @param {{type:string,enabled:boolean,settings:Object}} effect Selected effect.
 * @param {Object|null} stage Measured before/after stage traces.
 * @param {number} time Current player time in seconds.
 * @param {boolean} original Whether to show only the original input.
 * @returns {void} Draws the level graph or an empty state.
 */
function drawLevelPlot(ctx, rect, effect, stage, time, original) {
    const input = stage?.input?.level;
    const output = original ? null : stage?.output?.level;
    const duration = Math.max(
        positive(input?.duration), positive(output?.duration), 0.01
    );
    drawDbGrid(ctx, rect, false);
    drawTimeLabels(ctx, rect, duration);

    if (effect.type === 'reverb' && input && output
        && output.duration > input.duration) {
        const tailStart = rect.left + rect.width * input.duration / duration;
        ctx.fillStyle = 'rgba(215, 237, 138, 0.08)';
        ctx.fillRect(tailStart, rect.top, rect.right - tailStart, rect.height);
    }

    if (input) {
        drawLevelTrace(ctx, rect, input, duration, PALETTE.input);
    }
    if (output) {
        drawLevelTrace(ctx, rect, output, duration, PALETTE.output);
    }

    const marker = original ? null : levelMarker(effect);
    if (marker !== null) {
        drawHorizontalMarker(ctx, rect, marker, markerLabel(effect));
    }
    if (input) {
        drawCursor(ctx, rect, time, duration);
        drawLegend(ctx, rect, original ? ['Originál'] : ['Vstup', 'Výstup']);
    } else {
        drawEmptyInPlot(ctx, rect, 'Spusťte poslech ukázky');
    }
}

/** Draw bounded points from one peak-level trace.
 * @param {CanvasRenderingContext2D} ctx Scaled canvas context.
 * @param {{left:number,right:number,top:number,bottom:number,width:number,height:number}} rect Plot bounds.
 * @param {{values:Float32Array,stepSeconds:number,duration:number,peakDb:number}} trace Measured dBFS level series.
 * @param {number} duration Shared horizontal time range.
 * @param {string} color Trace color.
 * @returns {void} Draws the measured series without inventing signal data.
 */
function drawLevelTrace(ctx, rect, trace, duration, color) {
    const values = trace.values;
    if (!(values instanceof Float32Array) || values.length < 1) {
        return;
    }
    const stride = Math.max(
        1, Math.ceil(values.length / MAX_LEVEL_TRACE_POINTS)
    );
    ctx.beginPath();
    let started = false;
    for (let index = 0; index < values.length; index += stride) {
        const value = values[index];
        if (!Number.isFinite(value)) {
            continue;
        }
        const seconds = index * positive(trace.stepSeconds);
        const x = rect.left + rect.width * Math.min(1, seconds / duration);
        const y = dbY(rect, value);
        if (!started) {
            ctx.moveTo(x, y);
            started = true;
        } else {
            ctx.lineTo(x, y);
        }
    }
    ctx.strokeStyle = color;
    ctx.lineWidth = 1.6;
    ctx.setLineDash([]);
    ctx.stroke();
}

/** Draw EQ input/output spectrum at the closest available analysis frame.
 * @param {CanvasRenderingContext2D} ctx Scaled canvas context.
 * @param {{left:number,right:number,top:number,bottom:number,width:number,height:number}} rect Plot bounds.
 * @param {{type:string,enabled:boolean,settings:Object}} effect Selected EQ effect.
 * @param {Object|null} stage Measured stage traces.
 * @param {number} time Current player time in seconds.
 * @param {boolean} original Whether to show only the original input.
 * @returns {void} Draws measured FFT traces and the configured native response.
 */
function drawSpectrumPlot(ctx, rect, effect, stage, time, original) {
    drawDbGrid(ctx, rect, true);
    drawFrequencyLabels(ctx, rect);
    const input = stage?.input?.spectrum;
    const output = original ? null : stage?.output?.spectrum;
    if (input) {
        drawSpectrumTrace(ctx, rect, input, time, PALETTE.input);
    }
    if (output) {
        drawSpectrumTrace(ctx, rect, output, time, PALETTE.output);
    }
    const response = original && stage
        ? null : configuredEqResponse(effect.settings, rect.width);
    if (response) {
        drawEqResponse(ctx, rect, response);
    }
    if (input) {
        drawLegend(ctx, rect, original
            ? ['Originál'] : ['Vstup', 'Výstup', 'Nastavení']);
    } else if (response) {
        drawEmptyInPlot(ctx, rect, 'Spusťte poslech ukázky');
    } else {
        drawEmptyInPlot(ctx, rect, 'Spusťte poslech ukázky');
    }
}

/** Draw one frame-major FFT spectrum against its frequency bins.
 * @param {CanvasRenderingContext2D} ctx Scaled canvas context.
 * @param {{left:number,right:number,top:number,bottom:number,width:number,height:number}} rect Plot bounds.
 * @param {{frames:Float32Array,frequencies:Float32Array,frameCount:number,stepSeconds:number,duration:number}} spectrum Measured dBFS spectrum.
 * @param {number} time Current player time in seconds.
 * @param {string} color Trace color.
 * @returns {void} Draws only the selected measured frame.
 */
function drawSpectrumTrace(ctx, rect, spectrum, time, color) {
    const frequencies = spectrum.frequencies;
    const frames = spectrum.frames;
    if (!(frequencies instanceof Float32Array)
        || !(frames instanceof Float32Array) || frequencies.length < 2
        || spectrum.frameCount < 1) {
        return;
    }
    const frameIndex = clamp(
        Math.floor(time / positive(spectrum.stepSeconds)),
        0, spectrum.frameCount - 1
    );
    const offset = frameIndex * frequencies.length;
    ctx.beginPath();
    let started = false;
    for (let index = 0; index < frequencies.length; index += 1) {
        const hz = frequencies[index];
        const value = frames[offset + index];
        if (!Number.isFinite(hz) || hz <= 0 || !Number.isFinite(value)) {
            continue;
        }
        const x = frequencyX(rect, hz);
        const y = dbY(rect, value);
        if (!started) {
            ctx.moveTo(x, y);
            started = true;
        } else {
            ctx.lineTo(x, y);
        }
    }
    ctx.strokeStyle = color;
    ctx.lineWidth = 1.4;
    ctx.setLineDash([]);
    ctx.stroke();
}

/** Return cached native Biquad frequency response for current EQ settings.
 * @param {Object} settings EQ settings containing six ordered band records.
 * @param {number} plotWidth Available plot width in CSS pixels.
 * @returns {{frequencies:Float32Array,gainDb:Float32Array}|null} Response curves or null when Web Audio is unavailable.
 */
function configuredEqResponse(settings, plotWidth) {
    const bands = settings?.bands;
    if (!Array.isArray(bands) || bands.length !== 6
        || typeof OfflineAudioContext !== 'function') {
        return null;
    }
    const sampleCount = clamp(
        Math.ceil(plotWidth), 64, MAX_EQ_RESPONSE_SAMPLES
    );
    const key = JSON.stringify([sampleCount, bands]);
    if (key === cachedResponseKey) {
        return cachedResponse;
    }
    try {
        if (!responseContext) {
            responseContext = new OfflineAudioContext(
                1, RESPONSE_CONTEXT_LENGTH, 48000
            );
        }
        const frequencies = Float32Array.from(
            { length: sampleCount },
            (_, index) => 20 * (1000 ** (index / (sampleCount - 1)))
        );
        const magnitude = new Float32Array(sampleCount);
        const phase = new Float32Array(sampleCount);
        const gainDb = new Float32Array(sampleCount);
        for (let bandIndex = 0; bandIndex < bands.length; bandIndex += 1) {
            const band = bands[bandIndex];
            const filter = responseContext.createBiquadFilter();
            filter.type = bandIndex === 0 && band.cutoff
                ? 'highpass'
                : (bandIndex === 5 && band.cutoff ? 'lowpass' : 'peaking');
            filter.frequency.value = clamp(Number(band.frequency), 20, 20000);
            filter.Q.value = clamp(Number(band.q), 0.2, 8);
            filter.gain.value = clamp(Number(band.gain), -18, 18);
            filter.getFrequencyResponse(frequencies, magnitude, phase);
            for (let index = 0; index < magnitude.length; index += 1) {
                gainDb[index] += 20 * Math.log10(
                    Math.max(0.000001, magnitude[index])
                );
            }
        }
        cachedResponseKey = key;
        cachedResponse = { frequencies, gainDb };
        return cachedResponse;
    } catch {
        return null;
    }
}

/** Draw a dashed configured EQ response on the right-hand dB scale.
 * @param {CanvasRenderingContext2D} ctx Scaled canvas context.
 * @param {{left:number,right:number,top:number,bottom:number,width:number,height:number}} rect Plot bounds.
 * @param {{frequencies:Float32Array,gainDb:Float32Array}} response Native Biquad response.
 * @returns {void} Draws the settings curve distinct from measured FFT curves.
 */
function drawEqResponse(ctx, rect, response) {
    ctx.beginPath();
    for (let index = 0; index < response.frequencies.length; index += 1) {
        const x = frequencyX(rect, response.frequencies[index]);
        const db = clamp(response.gainDb[index], -18, 18);
        const y = rect.top + rect.height * (18 - db) / 36;
        if (index === 0) {
            ctx.moveTo(x, y);
        } else {
            ctx.lineTo(x, y);
        }
    }
    ctx.strokeStyle = PALETTE.configured;
    ctx.lineWidth = 1.4;
    ctx.setLineDash([4, 3]);
    ctx.stroke();
    ctx.setLineDash([]);
    ctx.fillStyle = PALETTE.label;
    ctx.textAlign = 'right';
    ctx.fillText('+18', rect.right - 1, rect.top + 8);
    ctx.fillText('0', rect.right - 1, rect.top + rect.height / 2 + 3);
    ctx.fillText('−18', rect.right - 1, rect.bottom - 1);
    ctx.textAlign = 'left';
}

/** Draw dBFS grid lines and left-axis labels.
 * @param {CanvasRenderingContext2D} ctx Scaled canvas context.
 * @param {{left:number,right:number,top:number,bottom:number,width:number,height:number}} rect Plot bounds.
 * @param {boolean} compact Use fewer labels in the short EQ spectrum panel.
 * @returns {void} Draws reference grid and dBFS labels.
 */
function drawDbGrid(ctx, rect, compact) {
    const values = compact ? [-96, -48, 0] : [-96, -72, -48, -24, 0];
    ctx.strokeStyle = PALETTE.grid;
    ctx.fillStyle = PALETTE.label;
    ctx.lineWidth = 1;
    ctx.setLineDash([]);
    ctx.textAlign = 'right';
    ctx.textBaseline = 'middle';
    for (const value of values) {
        const y = dbY(rect, value);
        ctx.beginPath();
        ctx.moveTo(rect.left, y);
        ctx.lineTo(rect.right, y);
        ctx.stroke();
        ctx.fillText(formatPreviewNumber(value), rect.left - 4, y);
    }
    ctx.textBaseline = 'alphabetic';
    ctx.textAlign = 'left';
}

/** Draw log-frequency labels that remain separated in narrow charts.
 * @param {CanvasRenderingContext2D} ctx Scaled canvas context.
 * @param {{left:number,right:number,top:number,bottom:number,width:number,height:number}} rect Plot bounds.
 * @returns {void} Adds frequency-axis labels in hertz.
 */
function drawFrequencyLabels(ctx, rect) {
    ctx.fillStyle = PALETTE.label;
    ctx.font = '9px system-ui, sans-serif';
    ctx.textBaseline = 'alphabetic';
    const labels = [
        { hz: 100, text: '100' },
        { hz: 1000, text: '1k' },
        { hz: 10000, text: '10k' },
        { hz: 20000, text: '20k' },
    ];
    for (const item of labels) {
        const x = frequencyX(rect, item.hz);
        const textWidth = ctx.measureText(item.text).width;
        ctx.textAlign = item.hz === 20000 ? 'right' : 'center';
        ctx.fillText(item.text, clamp(x, rect.left + textWidth / 2, rect.right), rect.bottom + 13);
    }
    ctx.textAlign = 'left';
    ctx.font = '10px system-ui, sans-serif';
}

/** Draw time-axis labels at start, midpoint, and end of the shared range.
 * @param {CanvasRenderingContext2D} ctx Scaled canvas context.
 * @param {{left:number,right:number,top:number,bottom:number,width:number,height:number}} rect Plot bounds.
 * @param {number} duration Shared trace duration in seconds.
 * @returns {void} Adds compact time labels.
 */
function drawTimeLabels(ctx, rect, duration) {
    ctx.fillStyle = PALETTE.label;
    ctx.font = '9px system-ui, sans-serif';
    ctx.textBaseline = 'alphabetic';
    const mid = duration / 2;
    ctx.textAlign = 'left';
    ctx.fillText('0s', rect.left, rect.bottom + 13);
    ctx.textAlign = 'center';
    ctx.fillText(`${formatPreviewNumber(mid)}s`, rect.left + rect.width / 2, rect.bottom + 13);
    ctx.textAlign = 'right';
    ctx.fillText(`${formatPreviewNumber(duration)}s`, rect.right, rect.bottom + 13);
    ctx.textAlign = 'left';
    ctx.font = '10px system-ui, sans-serif';
}

/** Draw a playback-position cursor through the time plot.
 * @param {CanvasRenderingContext2D} ctx Scaled canvas context.
 * @param {{left:number,right:number,top:number,bottom:number,width:number,height:number}} rect Plot bounds.
 * @param {number} time Current playback time.
 * @param {number} duration Shared trace duration.
 * @returns {void} Draws a compact vertical playhead.
 */
function drawCursor(ctx, rect, time, duration) {
    const x = rect.left + rect.width * clamp(time / duration, 0, 1);
    ctx.beginPath();
    ctx.moveTo(x, rect.top);
    ctx.lineTo(x, rect.bottom);
    ctx.strokeStyle = PALETTE.configured;
    ctx.lineWidth = 1;
    ctx.setLineDash([2, 3]);
    ctx.stroke();
    ctx.setLineDash([]);
}

/** Draw compressor threshold or normalization target in dBFS.
 * @param {CanvasRenderingContext2D} ctx Scaled canvas context.
 * @param {{left:number,right:number,top:number,bottom:number,width:number,height:number}} rect Plot bounds.
 * @param {number} value Marker value.
 * @param {string} label Marker name.
 * @returns {void} Draws a labeled configured threshold line.
 */
function drawHorizontalMarker(ctx, rect, value, label) {
    const y = dbY(rect, value);
    if (y < rect.top || y > rect.bottom) {
        return;
    }
    ctx.beginPath();
    ctx.moveTo(rect.left, y);
    ctx.lineTo(rect.right, y);
    ctx.strokeStyle = PALETTE.configured;
    ctx.setLineDash([4, 3]);
    ctx.stroke();
    ctx.setLineDash([]);
    ctx.fillStyle = PALETTE.configured;
    ctx.textAlign = 'right';
    ctx.fillText(`${label} ${formatPreviewNumber(value)} dBFS`, rect.right - 2, Math.max(rect.top + 9, y - 3));
    ctx.textAlign = 'left';
}

/** Select the horizontal dBFS marker for a supported dynamics effect.
 * @param {{type:string,settings:Object}} effect Selected effect.
 * @returns {number|null} Threshold/target or null for effects without a marker.
 */
function levelMarker(effect) {
    if (effect.type === 'compressor') {
        return finiteOrNull(effect.settings?.threshold);
    }
    if (effect.type === 'normalize') {
        return finiteOrNull(effect.settings?.target);
    }
    return null;
}

/** Return a Czech label for the configured level marker.
 * @param {{type:string}} effect Selected effect.
 * @returns {string} Localized marker label.
 */
function markerLabel(effect) {
    return effect.type === 'compressor' ? 'Práh' : 'Cíl';
}

/** Draw compact trace legend labels at the top of a graph.
 * @param {CanvasRenderingContext2D} ctx Scaled canvas context.
 * @param {{left:number,right:number,top:number,bottom:number,width:number,height:number}} rect Plot bounds.
 * @param {string[]} labels Legend entries.
 * @returns {void} Draws labels that fit the graph width.
 */
function drawLegend(ctx, rect, labels) {
    const colors = labels.length === 1
        ? [PALETTE.input]
        : labels.length === 2
            ? [PALETTE.input, PALETTE.output]
            : [PALETTE.input, PALETTE.output, PALETTE.configured];
    ctx.font = '9px system-ui, sans-serif';
    ctx.textBaseline = 'alphabetic';
    let x = rect.left;
    const y = Math.max(9, rect.top - 3);
    for (let index = 0; index < labels.length; index += 1) {
        const color = colors[index];
        ctx.beginPath();
        ctx.moveTo(x, y - 3);
        ctx.lineTo(x + 10, y - 3);
        ctx.strokeStyle = color;
        ctx.setLineDash(index === 2 ? [3, 2] : []);
        ctx.stroke();
        ctx.setLineDash([]);
        x += 13;
        ctx.fillStyle = PALETTE.label;
        ctx.textAlign = 'left';
        ctx.fillText(labels[index], x, y);
        x += ctx.measureText(labels[index]).width + 8;
    }
    ctx.font = '10px system-ui, sans-serif';
}

/** Draw a concise empty-state note inside an otherwise useful grid.
 * @param {CanvasRenderingContext2D} ctx Scaled canvas context.
 * @param {{left:number,right:number,top:number,bottom:number,width:number,height:number}} rect Plot bounds.
 * @param {string} label Empty-state instruction.
 * @returns {void} Draws the note without replacing axis context.
 */
function drawEmptyInPlot(ctx, rect, label) {
    ctx.fillStyle = PALETTE.empty;
    ctx.font = '10px system-ui, sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(label, rect.left + rect.width / 2, rect.top + rect.height / 2, rect.width - 8);
    ctx.textAlign = 'left';
    ctx.textBaseline = 'alphabetic';
}

/** Fill a hidden/unsupported chart with a readable empty state.
 * @param {CanvasRenderingContext2D} ctx Scaled canvas context.
 * @param {number} width CSS canvas width.
 * @param {number} height CSS canvas height.
 * @param {string} message Short user guidance.
 * @returns {void} Draws an empty-state grid and message.
 */
function drawEmpty(ctx, width, height, message) {
    const rect = plotRect(width, height, false);
    drawDbGrid(ctx, rect, false);
    drawEmptyInPlot(ctx, rect, message);
}

/** Map a dBFS value to a vertical plot position.
 * @param {{top:number,height:number}} rect Plot bounds.
 * @param {number} value dBFS input.
 * @returns {number} Clamped CSS y position.
 */
function dbY(rect, value) {
    return rect.top + rect.height * (MAX_DBFS - clamp(value, MIN_DBFS, MAX_DBFS))
        / (MAX_DBFS - MIN_DBFS);
}

/** Map frequency to an x position on a logarithmic 20 Hz–20 kHz axis.
 * @param {{left:number,width:number}} rect Plot bounds.
 * @param {number} hz Frequency in hertz.
 * @returns {number} CSS x position.
 */
function frequencyX(rect, hz) {
    const bounded = clamp(hz, 20, 20000);
    return rect.left + rect.width * Math.log(bounded / 20) / Math.log(1000);
}

/** Return a positive finite time step or a harmless structural fallback.
 * @param {number|undefined} value Source time step.
 * @returns {number} Positive time step.
 */
function positive(value) {
    return Number.isFinite(value) && value > 0 ? value : 0;
}

/** Convert a possible value to a finite number or null.
 * @param {unknown} value Candidate numeric value.
 * @returns {number|null} Finite number, or null.
 */
function finiteOrNull(value) {
    return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

/** Clamp a finite number to an inclusive range.
 * @param {number} value Candidate value.
 * @param {number} minimum Inclusive lower limit.
 * @param {number} maximum Inclusive upper limit.
 * @returns {number} Bounded value or minimum for non-finite input.
 */
function clamp(value, minimum, maximum) {
    return Number.isFinite(value)
        ? Math.min(maximum, Math.max(minimum, value))
        : minimum;
}
