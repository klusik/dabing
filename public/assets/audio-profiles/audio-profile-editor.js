/*
 * Project: Dabing
 * Repository: klusik/dabing
 * File: public/assets/audio-profiles/audio-profile-editor.js
 * Module Type: Browser UI controller
 * Purpose: Operate the profile editor, audio preview and WAV export.
 * Responsibilities: Edit ordered chain, graph EQ response and monitor FFT.
 * Author: Rudolf Klusal
 * License: All rights reserved
 */
import { renderAudioEffects } from './engine.js';
const root = document.querySelector('[data-profile-editor]');
const frequencies = [60,180,500,1500,5000,12000];
const defaults = {
    eq:{bands:frequencies.map((frequency)=>({frequency,gain:0,q:1,cutoff:false}))},
    compressor:{threshold:-18,ratio:3,gain:0}, normalize:{target:-3}, reverb:{mix:0,decay:1},
};
const titles={eq:'Ekvalizér',compressor:'Kompresor',normalize:'Normalizace',reverb:'Reverb'};
/** Type: number; Units: pixels; Scope: pointer reorder; Consumers: effect list.
 * Rationale: Seven pixels filters finger jitter while preserving responsive drag.
 */
const DRAG_THRESHOLD=7;
/** Type: number; Units: samples; Scope: FFT; Consumers: monitor analyser.
 * Rationale: 1024 bins balance bass resolution and affordable canvas work.
 */
const FFT_SIZE=1024;
if(root) initialize();
/**
 * Initialize profile controls, chain and optional authenticated sample.
 * @returns {void} Installs handlers and renders the initial mixer state.
 */
function initialize(){
    const state={chain:readChain(root.dataset.chain),blob:null,sourceUrl:'',renderUrl:'',audioContext:null,mediaSource:null,analyser:null,peaks:null,smoothing:8};
    const list=root.querySelector('[data-effect-list]'); const panel=root.querySelector('[data-effect-editor]');
    const bands=root.querySelector('[data-eq-bands]'); const graph=root.querySelector('[data-eq-graph]');
    const spectrum=root.querySelector('[data-spectrum]'); const player=root.querySelector('[data-preview-player]');
    const status=root.querySelector('[data-editor-status]'); ensureEffects(); state.selectedEffect=0; renderChain(); renderBands(); drawEq(); syncForm(); edit(0);
    root.querySelector('[data-new-profile]').addEventListener('click',newProfile);
    root.querySelector('[data-profile-select]').addEventListener('change',(event)=>{const url=new URL(location.href);event.currentTarget.value?url.searchParams.set('id',event.currentTarget.value):url.searchParams.delete('id');location.assign(url);});
    root.querySelector('[data-local-audio]').addEventListener('change',loadFile);
    root.querySelector('[data-play]').addEventListener('click',playProcessed);
    root.querySelector('[data-play-original]').addEventListener('click',playOriginal);
    root.querySelector('[data-chain-input]').form.addEventListener('submit',syncForm);
    root.querySelectorAll('[data-edge]').forEach((toggle)=>toggle.addEventListener('change',()=>{const index=toggle.dataset.edge==='highpass'?0:5;eq().settings.bands[index].cutoff=toggle.checked;renderBands();drawEq();syncForm();}));
    addEventListener('resize',drawEq); if(root.dataset.sampleUrl)loadSample(root.dataset.sampleUrl); bindSmoothing();

    /**
     * Ensure a six-band EQ model exists for the visible faders.
     * @returns {void} Inserts a bypassed EQ if none is present.
     */
    function ensureEffects(){
        for(const type of Object.keys(defaults)){
            if(!state.chain.some((effect)=>effect.type===type)){state.chain.push({type,enabled:false,settings:structuredClone(defaults[type])});}
        }
        const eqEffect=state.chain.find((effect)=>effect.type==="eq");
        if(!Array.isArray(eqEffect.settings?.bands)||eqEffect.settings.bands.length!==6)eqEffect.settings=structuredClone(defaults.eq);
        state.chain=state.chain.filter((effect,index,array)=>Object.hasOwn(defaults,effect.type)&&array.findIndex((candidate)=>candidate.type===effect.type)===index);
    }
    /** @returns {Object} Mutable equalizer effect in the chain. */
    function eq(){return state.chain.find((effect)=>effect.type==='eq');}
    /**
     * Rebuild accessible effect rows with bypass and ordering actions.
     * @returns {void} Updates the chain list DOM.
     */
    function renderChain(){
        list.replaceChildren(); state.chain.forEach((effect,index)=>{
            const row=document.createElement('li'); row.className = 'effect-row' + (effect.enabled ? '' : ' is-disabled') + (state.selectedEffect === index ? ' is-selected' : ''); row.dataset.effectIndex=String(index);
            row.innerHTML=`<button class="effect-move" type="button" aria-label="Pořadí efektu" title="Šipky nahoru/dolů">↕</button><button class="effect-name" type="button">${escapeHtml(titles[effect.type]||effect.type)}</button><button class="effect-toggle" type="button" aria-pressed="${Boolean(effect.enabled)}" aria-label="${effect.enabled?'Vypnout':'Zapnout'}">${effect.enabled?'●':'○'}</button>`;
            row.querySelector(".effect-name").addEventListener("click",()=>{state.selectedEffect=index;renderChain();edit(index);});
            row.querySelector('.effect-toggle').addEventListener('click',()=>{effect.enabled=!effect.enabled;renderChain();syncForm();drawEq();});
            const handle=row.querySelector('.effect-move'); handle.addEventListener('click',(event)=>move(index,event.shiftKey?1:-1));
            handle.addEventListener('keydown',(event)=>{if(!['ArrowUp','ArrowDown'].includes(event.key))return;event.preventDefault();move(index,event.key==='ArrowUp'?-1:1);list.querySelectorAll('.effect-move')[clamp(index+(event.key==='ArrowUp'?-1:1),0,state.chain.length-1)]?.focus();});
            bindDrag(handle,row,index); list.append(row);
        });
    }
    /**
     * Move an effect by a relative number of positions.
     * @param {number} index - Current chain index.
     * @param {number} delta - Relative movement.
     * @returns {void} Reorders and refreshes dependent UI.
     */
    function move(index,delta){const target=clamp(index+delta,0,state.chain.length-1);if(target===index)return;const [item]=state.chain.splice(index,1);state.chain.splice(target,0,item);renderChain();syncForm();drawEq();}
    /**
     * Bind pointer reorder gesture to an effect handle.
     * @param {HTMLButtonElement} handle - Reorder button.
     * @param {HTMLElement} row - Effect row.
     * @param {number} index - Row's starting index.
     * @returns {void} Pointer drag places row before the drop target.
     */
    function bindDrag(handle,row,index){let y=0, dragged=false;handle.addEventListener('pointerdown',(event)=>{y=event.clientY;dragged=false;handle.setPointerCapture(event.pointerId);});handle.addEventListener('pointermove',(event)=>{if(handle.hasPointerCapture(event.pointerId)&&Math.abs(event.clientY-y)>DRAG_THRESHOLD)dragged=true;});handle.addEventListener('pointerup',(event)=>{if(!dragged)return;const target=document.elementFromPoint(event.clientX,event.clientY)?.closest('[data-effect-index]');if(!target||target===row)return;const destination=Number(target.dataset.effectIndex);const [item]=state.chain.splice(index,1);state.chain.splice(destination,0,item);renderChain();syncForm();drawEq();});}
    /**
     * Display setting sliders for a selected effect.
     * @param {number} index - Selected effect position.
     * @returns {void} Builds effect-specific range controls.
     */
    function edit(index){
        state.selectedEffect=index;
        const item=state.chain[index]; if(!item)return;
        panel.innerHTML=`<h3>${escapeHtml(titles[item.type]||item.type)} · nastavení</h3><div class="effect-fields"></div>`;
        const fields=panel.querySelector(".effect-fields");
        if(item.type==="compressor"){
            rotary(fields,item.settings,"threshold","Práh · dB",-60,0,false);
            rotary(fields,item.settings,"ratio","Poměr",1,20,false);
            slider(fields,item.settings,"Make-up · dB","gain",0,24,.5);
        }else if(item.type==="normalize"){
            slider(fields,item.settings,"Cíl · dBFS","target",-24,0,.5);
        }else if(item.type==="reverb"){
            rotary(fields,item.settings,"mix","Wet · %",0,100,false);
            rotary(fields,item.settings,"decay","Dozvuk · s",.1,3,true);
        }else{
            fields.textContent="Zisk nastavte faderem; střední frekvenci a Q otočnými ovladači.";
        }
    }
    /**
     * Create a labeled rotary setting control for a DSP parameter.
     * @param {HTMLElement} parent - Effect settings container.
     * @param {Object} settings - Mutable effect settings.
     * @param {string} key - Setting property.
     * @param {string} label - Accessible name and displayed value.
     * @param {number} minimum - Inclusive supported minimum.
     * @param {number} maximum - Inclusive supported maximum.
     * @param {boolean} logarithmic - Scale pointer motion logarithmically.
     * @returns {void} Adds keyboard and pointer adjustable rotary control.
     */
    function rotary(parent,settings,key,label,minimum,maximum,logarithmic){
        const wrap=document.createElement("label");wrap.className="effect-field";
        const button=document.createElement("button");button.type="button";button.className="eq-knob";
        const output=document.createElement("output");wrap.append(document.createTextNode(label),button,output);parent.append(wrap);
        const format=()=>`${settings[key]}${key==="decay"?" s":key==="mix"?" %":""}`;
        const paint=()=>{button.setAttribute("aria-valuetext",format());button.setAttribute("aria-valuenow",String(settings[key]));output.value=format();};paint();button.setAttribute("aria-valuemin",String(minimum));button.setAttribute("aria-valuemax",String(maximum));
        button.setAttribute("role","slider");button.setAttribute("aria-label",label);button.tabIndex=0;
        button.addEventListener("keydown",(event)=>{if(!["ArrowUp","ArrowRight","ArrowDown","ArrowLeft"].includes(event.key))return;event.preventDefault();const direction=["ArrowUp","ArrowRight"].includes(event.key)?1:-1;const factor=event.shiftKey?1.1:1.02;settings[key]=logarithmic?clamp(settings[key]*factor**direction,minimum,maximum):clamp(settings[key]+direction*(maximum-minimum)*(event.shiftKey ? .02 : .005),minimum,maximum);paint();syncForm();});
        let y=0,start=0;button.addEventListener("pointerdown",(event)=>{y=event.clientY;start=settings[key];button.setPointerCapture(event.pointerId);});
        button.addEventListener("pointermove",(event)=>{if(!button.hasPointerCapture(event.pointerId))return;const delta=(y-event.clientY)/120;settings[key]=logarithmic?clamp(start*(maximum/minimum)**delta,minimum,maximum):clamp(start+delta*(maximum-minimum),minimum,maximum);paint();syncForm();});
    }
    /**
     * Add a labeled range control attached to one settings property.
     * @param {HTMLElement} parent - Fields container.
     * @param {Object} settings - Mutable effect settings.
     * @param {string} label - Human-readable value name.
     * @param {string} key - Settings key.
     * @param {number} minimum - Inclusive minimum.
     * @param {number} maximum - Inclusive maximum.
     * @param {number} step - Slider step.
     * @returns {void} Updates model, graph and hidden POST data.
     */
    function slider(parent,settings,label,key,minimum,maximum,step){const node=document.createElement('label');node.className='effect-field';node.innerHTML=`<span>${escapeHtml(label)}</span><input type="range" min="${minimum}" max="${maximum}" step="${step}" value="${settings[key]}"><output></output>`;const input=node.querySelector('input'),output=node.querySelector('output');input.addEventListener('input',()=>{settings[key]=Number(input.value);output.value=input.value;syncForm();drawEq();});input.dispatchEvent(new Event('input'));parent.append(node);}
    /**
     * Render six band faders and accessible frequency/Q rotary controls.
     * @returns {void} Rebuilds equalizer controls and edge switches.
     */
    function renderBands(){
        const values=eq().settings.bands; bands.replaceChildren();
        values.forEach((band,index)=>{const item=document.createElement('div');item.className='eq-band';item.innerHTML=`<span class="eq-band-name">${index===0?'LOW':index===5?'HIGH':`B${index+1}`}</span><input class="eq-gain" type="range" min="-18" max="18" step="0.5" value="${band.gain}" aria-label="Zisk pásma ${index+1} dB"><output class="eq-gain-value">${Number(band.gain).toFixed(1)} dB</output><button type="button" class="eq-knob" aria-label="Frekvence pásma ${index+1}" title="Frekvence; šipky nebo tažení">${freqLabel(band.frequency)}</button><button type="button" class="eq-knob" aria-label="Q pásma ${index+1}" title="Šířka Q; šipky nebo tažení">Q ${Number(band.q).toFixed(1)}</button>`;
            item.querySelector('.eq-gain').addEventListener('input',(event)=>{band.gain=Number(event.currentTarget.value);item.querySelector(".eq-gain-value").value=`${band.gain.toFixed(1)} dB`;drawEq();syncForm();});
            bindKnob(item.querySelectorAll('.eq-knob')[0],band,'frequency',20,20000,true,index);bindKnob(item.querySelectorAll('.eq-knob')[1],band,'q',.2,8,false,index);bands.append(item);});
        root.querySelector('[data-edge=highpass]').checked=Boolean(values[0].cutoff);root.querySelector('[data-edge=lowpass]').checked=Boolean(values[5].cutoff);
    }
    /**
     * Bind a rotary EQ setting to pointer movement and arrow keys.
     * @param {HTMLButtonElement} knob - Rotary control.
     * @param {Object} band - Mutable band settings.
     * @param {string} key - frequency or q setting.
     * @param {number} minimum - Supported minimum.
     * @param {number} maximum - Supported maximum.
     * @param {boolean} logarithmic - Use logarithmic pointer movement.
     * @param {number} bandIndex - Band position for focus restoration.
     * @returns {void} Edits the selected EQ value accessibly.
     */
    function bindKnob(knob,band,key,minimum,maximum,logarithmic,bandIndex){
        knob.addEventListener('keydown',(event)=>{if(!['ArrowUp','ArrowDown','ArrowLeft','ArrowRight'].includes(event.key))return;event.preventDefault();const delta=['ArrowUp','ArrowRight'].includes(event.key)?1:-1;band[key]=logarithmic?clamp(band[key]*(event.shiftKey?1.12:1.025)**delta,minimum,maximum):clamp(band[key]+delta*(event.shiftKey ? .5 : .1),minimum,maximum);renderBands();drawEq();syncForm();bands.querySelectorAll('.eq-band')[bandIndex]?.querySelectorAll('.eq-knob')[key==='frequency'?0:1].focus();});
        let y=0,initial=0;knob.addEventListener("keydown",(event)=>{if(!["ArrowUp","ArrowDown","ArrowLeft","ArrowRight"].includes(event.key))return;event.preventDefault();const direction=["ArrowUp","ArrowRight"].includes(event.key)?1:-1;band[key]=logarithmic?clamp(band[key]*(event.shiftKey?1.12:1.025)**direction,minimum,maximum):clamp(band[key]+direction*(event.shiftKey ? .5 : .1),minimum,maximum);renderBands();drawEq();syncForm();});knob.addEventListener("pointerdown",(event)=>{y=event.clientY;initial=band[key];knob.setPointerCapture(event.pointerId);});knob.addEventListener("pointermove",(event)=>{if(!knob.hasPointerCapture(event.pointerId))return;const delta=(y-event.clientY)/120;band[key]=logarithmic?clamp(initial*(maximum/minimum)**delta,minimum,maximum):clamp(initial+delta*(maximum-minimum),minimum,maximum);knob.textContent=key==="frequency"?freqLabel(band[key]):`Q ${Number(band[key]).toFixed(1)}`;drawEq();syncForm();});
    }
    /**
     * Plot modeled six-band response on a logarithmic frequency axis.
     * @returns {void} Draws gain grid, frequency labels and EQ curve.
     */
    function drawEq(){
        const ctx=canvasContext(graph); if(!ctx)return;
        const width=graph.clientWidth,height=graph.clientHeight,values=eq().settings.bands;
        ctx.strokeStyle="#536154"; ctx.lineWidth=1;
        [-18,-12,-6,0,6,12,18].forEach((db)=>{const y=height*(18-db)/36;ctx.beginPath();ctx.moveTo(0,y);ctx.lineTo(width,y);ctx.stroke();});
        ctx.fillStyle="#a8b2a5";ctx.font="10px sans-serif";(width<420?[20,100,1000,20000]:[20,100,1000,10000,20000]).forEach((hz)=>{const label=hz>=1000?`${hz/1000}k`:String(hz);const x=width*Math.log(hz/20)/Math.log(1000);ctx.fillText(label,clamp(x,2,width-ctx.measureText(label).width-2),height-4);});
        const responseContext=new OfflineAudioContext(1,1,48000);
        const frequencies=Float32Array.from({length:Math.max(1,Math.floor(width))},(_,index)=>20*(1000**(index/Math.max(1,width))));
        const response=new Float32Array(frequencies.length),phase=new Float32Array(frequencies.length);
        const gainDb=new Float32Array(frequencies.length);
        values.forEach((band,index)=>{
            const filter=responseContext.createBiquadFilter();
            filter.type=index===0&&band.cutoff?"highpass":index===5&&band.cutoff?"lowpass":"peaking";
            filter.frequency.value=clamp(band.frequency,20,20000);filter.Q.value=clamp(band.q,.2,8);
            filter.gain.value=clamp(band.gain,-18,18);filter.getFrequencyResponse(frequencies,response,phase);
            response.forEach((magnitude,position)=>{gainDb[position]+=20*Math.log10(Math.max(1e-6,magnitude));});
        });
        ctx.beginPath();gainDb.forEach((gain,index)=>{const y=height*(18-clamp(gain,-18,18))/36;index===0?ctx.moveTo(index,y):ctx.lineTo(index,y);});
        ctx.strokeStyle="#d7ed8a";ctx.lineWidth=2;ctx.stroke();
    }
    /** @param {HTMLCanvasElement} canvas - Target canvas. @returns {CanvasRenderingContext2D|null} Scaled drawing context. */
    function canvasContext(canvas){const rect=canvas.getBoundingClientRect();if(!rect.width||!rect.height)return null;const scale=Math.min(2,devicePixelRatio||1),width=Math.round(rect.width*scale),height=Math.round(rect.height*scale);if(canvas.width!==width||canvas.height!==height){canvas.width=width;canvas.height=height;}const ctx=canvas.getContext('2d');ctx.setTransform(scale,0,0,scale,0,0);ctx.fillStyle='#253128';ctx.fillRect(0,0,rect.width,rect.height);return ctx;}

    /**
     * Fetch a stored take using its controller-approved sample URL.
     * @param {string} url - Authenticated sample URL.
     * @returns {Promise<void>} Sets source or reports visible load failure.
     */
    /**
     * Enable original and effect preview actions after source selection.
     * @returns {void} Activates the two available playback buttons.
     */
    function enableMonitor(){root.querySelectorAll("[data-play],[data-play-original]").forEach((button)=>{button.disabled=false;});}
    async function loadSample(url){status.textContent='Načítám ukázku…';try{const response=await fetch(url,{credentials:'same-origin'});if(!response.ok)throw new Error('Ukázku se nepodařilo načíst.');state.blob=await response.blob();useSource(URL.createObjectURL(state.blob));root.querySelector('[data-file-name]').textContent=root.querySelector('[data-file-name]').textContent||'Ukázka nahrávky';enableMonitor();status.textContent='Ukázka připravena.';}catch(error){status.textContent=error.message;}}
    /**
     * Accept a bounded local audio file for private preview.
     * @param {Event} event - Local file input event.
     * @returns {void} Selects the file or displays a validation message.
     */
    function loadFile(event){const file=event.currentTarget.files?.[0];if(!file)return;if(!file.type.startsWith('audio/')){status.textContent='Vyberte zvukový soubor.';return;}if(file.size>20971520){status.textContent='Soubor překračuje limit 20 MiB.';return;}state.blob=file;useSource(URL.createObjectURL(file));root.querySelector('[data-file-name]').textContent=file.name;enableMonitor();status.textContent='Místní soubor připraven.';}
    /** @param {string} url - Newly created object URL. @returns {void} Replaces source and resets processed URL. */
    function useSource(url){if(state.sourceUrl)URL.revokeObjectURL(state.sourceUrl);if(state.renderUrl)URL.revokeObjectURL(state.renderUrl);state.sourceUrl=url;state.renderUrl='';player.src=url;}
    /** @returns {void} Enables monitor controls when a source is selected. */
    /**
     * Apply the chain, play rendered WAV and start FFT visualization.
     * @returns {Promise<void>} Resolves when playback begins.
     */
    /**
     * Encode an AudioBuffer to PCM WAV for the in-page processed preview.
     * @param {AudioBuffer} buffer - Rendered buffer from the shared effects engine.
     * @returns {Blob} Interleaved 16-bit PCM WAV audio.
     */
    function wav(buffer){
        const channels=buffer.numberOfChannels;const frames=buffer.length;const bytes=2;
        const view=new DataView(new ArrayBuffer(44+frames*channels*bytes));
        const write=(offset,value)=>{for(let index=0;index<value.length;index++){view.setUint8(offset+index,value.charCodeAt(index));}};
        write(0,"RIFF");view.setUint32(4,view.byteLength-8,true);write(8,"WAVE");write(12,"fmt ");
        view.setUint32(16,16,true);view.setUint16(20,1,true);view.setUint16(22,channels,true);
        view.setUint32(24,buffer.sampleRate,true);view.setUint32(28,buffer.sampleRate*channels*bytes,true);
        view.setUint16(32,channels*bytes,true);view.setUint16(34,16,true);write(36,"data");
        view.setUint32(40,frames*channels*bytes,true);
        for(let frame=0;frame<frames;frame++){
            for(let channel=0;channel<channels;channel++){
                const sample=clamp(buffer.getChannelData(channel)[frame],-1,1);
                const integer=sample<0?sample*32768:sample*32767;
                view.setInt16(44+(frame*channels+channel)*bytes,integer,true);
            }
        }
        return new Blob([view],{type:"audio/wav"});
    }
    async function playProcessed(){if(!state.blob)return;status.textContent='Zpracovávám…';try{const result=wav(await renderAudioEffects(state.blob,state.chain));if(state.renderUrl)URL.revokeObjectURL(state.renderUrl);state.renderUrl=URL.createObjectURL(result);player.src=state.renderUrl;await playAndAnalyze();status.textContent='Přehrává se efektovaný zvuk.';}catch(error){status.textContent=error.message;}}
    /** @returns {Promise<void>} Plays the original with FFT monitoring. */
    async function playOriginal(){if(!state.sourceUrl)return;player.src=state.sourceUrl;try{await playAndAnalyze();status.textContent='Přehrává se originál.';}catch(error){status.textContent=error.message;}}
    /** @returns {Promise<void>} Connects analyser and starts audio playback. */
    async function playAndAnalyze(){if(state.spectrumFrame)cancelAnimationFrame(state.spectrumFrame);if(!state.audioContext)state.audioContext=new AudioContext();if(!state.mediaSource)state.mediaSource=state.audioContext.createMediaElementSource(player);if(state.analyser)state.analyser.disconnect();const analyser=state.audioContext.createAnalyser();analyser.fftSize=FFT_SIZE;state.mediaSource.connect(analyser).connect(state.audioContext.destination);state.analyser=analyser;await state.audioContext.resume();await player.play();drawSpectrum();}
    /**
     * Bind the spectrum smoothing knob to pointer and keyboard controls.
     * @returns {void} Updates the monitor envelope factor and readout.
     */
    function bindSmoothing(){
        const knob=root.querySelector("[data-smoothing]");let y=0,start=state.smoothing;
        const paint=()=>{knob.textContent=String(state.smoothing);root.querySelector("[data-smoothing-value]").value=String(state.smoothing);};
        knob.setAttribute("role","slider");knob.setAttribute("aria-valuemin","1");knob.setAttribute("aria-valuemax","18");knob.setAttribute("aria-valuenow","8");
        knob.addEventListener("keydown",(event)=>{if(!["ArrowUp","ArrowDown"].includes(event.key))return;event.preventDefault();state.smoothing=clamp(state.smoothing+(event.key==="ArrowUp"?1:-1),1,18);knob.setAttribute("aria-valuenow",String(state.smoothing));paint();});
        knob.addEventListener("pointerdown",(event)=>{y=event.clientY;start=state.smoothing;knob.setPointerCapture(event.pointerId);});
        knob.addEventListener("pointermove",(event)=>{if(!knob.hasPointerCapture(event.pointerId))return;state.smoothing=clamp(Math.round(start+(y-event.clientY)/8),1,18);knob.setAttribute("aria-valuenow",String(state.smoothing));paint();});
    }
    /**
     * Draw current FFT bins with a decaying peak envelope.
     * @returns {void} Paints the live monitor canvas while audio plays.
     */
    function drawSpectrum(){const ctx=canvasContext(spectrum),analyser=state.analyser;if(!ctx||!analyser)return;const bins=new Uint8Array(analyser.frequencyBinCount);analyser.getByteFrequencyData(bins);const smoothing=state.smoothing;if(!state.peaks||state.peaks.length!==bins.length)state.peaks=new Float32Array(bins.length);const width=spectrum.clientWidth/bins.length;bins.forEach((value,index)=>{state.peaks[index]=Math.max(value,state.peaks[index]*(1-1/smoothing));const y=spectrum.clientHeight*(1-state.peaks[index]/255);ctx.fillStyle=`hsl(${95-index/bins.length*55} 52% 62%)`;ctx.fillRect(index*width,y,Math.max(1,width),spectrum.clientHeight-y);});if(!player.paused)state.spectrumFrame=requestAnimationFrame(drawSpectrum);}
    /**
     * Serialize current effect chain into the hidden save field.
     * @returns {void} Synchronizes the POST payload.
     */
    function syncForm(){root.querySelector('[data-chain-input]').value=JSON.stringify(state.chain);}
    /**
     * Clear profile identity and name for creation of a new profile.
     * @returns {void} Removes id from current URL without navigation.
     */
    function newProfile(){root.querySelector("[data-profile-id]").value="";root.querySelector("#profile-name").value="";root.querySelector("[data-profile-select]").value="";state.chain=[];state.selectedEffect=0;ensureEffects();renderChain();renderBands();edit(0);syncForm();drawEq();const url=new URL(location.href);url.searchParams.delete("id");history.replaceState({},"",url);}
}
/**
 * Parse a view-provided serialized chain with a safe empty fallback.
 * @param {string|undefined} value - JSON chain string.
 * @returns {Array<Object>} Parsed effect list.
 */
function readChain(value){try{const parsed=JSON.parse(value||'[]');return Array.isArray(parsed)?parsed.filter((item)=>item&&typeof item==='object'):[];}catch{return[];}}
/**
 * Clamp a numeric value to a supported inclusive range.
 * @param {number} value - Proposed control value.
 * @param {number} minimum - Lower bound.
 * @param {number} maximum - Upper bound.
 * @returns {number} Bounded finite number.
 */
function clamp(value,minimum,maximum){return Math.min(maximum,Math.max(minimum,Number(value)||0));}
/**
 * Escape text before inserting it into editor markup.
 * @param {string} value - Plain text.
 * @returns {string} Escaped HTML text.
 */
function escapeHtml(value){return value.replace(/[&<>"']/g,(char)=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));}
/**
 * Format an EQ frequency in hertz or kilohertz.
 * @param {number} value - Frequency in hertz.
 * @returns {string} Compact frequency text.
 */
function freqLabel(value){return value>=1000?`${(value/1000).toFixed(value>=10000?0:1)}k`:String(Math.round(value));}
