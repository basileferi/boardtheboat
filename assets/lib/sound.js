/*
 * Sound design, synthesised with the Web Audio API: no audio file, no library, no request.
 *
 * One engine for the whole visit. It lives in this module rather than in a Stimulus controller
 * because Turbo replaces <body> on every navigation: a controller would tear the AudioContext
 * down and cut the ambient bed on each page change.
 *
 * Rules it follows:
 *  - nothing plays before a user gesture (browsers forbid it, and so do we);
 *  - the preference is remembered per browser; the default is "on" unless the visitor asks
 *    for reduced motion, since the calm-interface crowd rarely wants a noisy one;
 *  - the ambient bed pauses when the tab is hidden, and the context is suspended when muted.
 *
 * Anything can ask for a sound by dispatching `btb:sound` with `detail.tone`, or by calling
 * `sound.play(name)` after importing this module.
 */
const STORAGE_KEY = 'btb:sound';

function readPreference() {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored === 'on' || stored === 'off') {
            return stored === 'on';
        }
    } catch {
        // Storage blocked: fall through to the default.
    }

    return !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

class SoundEngine {
    constructor() {
        this.enabled = readPreference();
        this.ctx = null;
        this.master = null;
        this.noise = null;
        this.ambient = null;
        this.listeners = new Set();
        this.lastPlayed = {};
        this.hovered = null;

        const unlock = () => this.unlock();
        document.addEventListener('pointerdown', unlock, { capture: true, passive: true });
        document.addEventListener('keydown', unlock, { capture: true });

        document.addEventListener('btb:sound', (event) => this.play(event.detail?.tone ?? 'tap'));

        // Micro-feedback for everything interactive, delegated once for the whole document.
        document.addEventListener('pointerover', (event) => {
            const target = event.target instanceof Element ? event.target.closest('a[href], button, summary, select, label[for]') : null;
            if (target && target !== this.hovered) {
                this.hovered = target;
                this.play('hover');
            } else if (!target) {
                this.hovered = null;
            }
        }, { passive: true });

        document.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target.closest('a[href], button, summary') : null;
            if (target && !target.hasAttribute('data-sound-silent')) {
                this.play('tap');
            }
        }, { capture: true });

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.stopAmbient();
            } else if (this.enabled && this.ctx) {
                this.startAmbient();
            }
        });
    }

    subscribe(listener) {
        this.listeners.add(listener);
        listener(this.enabled);

        return () => this.listeners.delete(listener);
    }

    toggle() {
        this.setEnabled(!this.enabled);
    }

    setEnabled(enabled) {
        this.enabled = enabled;
        try {
            localStorage.setItem(STORAGE_KEY, enabled ? 'on' : 'off');
        } catch {
            // Private browsing: the preference simply does not survive the tab.
        }

        if (enabled) {
            this.unlock();
            this.play('on', true);
        } else if (this.ctx) {
            this.play('off', true);
            this.stopAmbient();
            const ctx = this.ctx;
            setTimeout(() => {
                if (!this.enabled) {
                    void ctx.suspend();
                }
            }, 450);
        }

        this.listeners.forEach((listener) => listener(enabled));
    }

    unlock() {
        if (!this.enabled) {
            return;
        }

        const ctx = this.context();
        if (ctx && !document.hidden) {
            this.startAmbient();
        }
    }

    context() {
        const Ctor = window.AudioContext ?? window.webkitAudioContext;
        if (!Ctor) {
            return null;
        }

        if (!this.ctx) {
            this.ctx = new Ctor();
            const compressor = this.ctx.createDynamicsCompressor();
            compressor.threshold.value = -18;
            compressor.ratio.value = 6;
            this.master = this.ctx.createGain();
            this.master.gain.value = 0.9;
            this.master.connect(compressor).connect(this.ctx.destination);
        }

        if (this.ctx.state === 'suspended') {
            void this.ctx.resume();
        }

        return this.ctx;
    }

    /*
     * A tone is only played when sound is on and the context already exists (i.e. a gesture
     * happened). `force` lets the toggle itself make a sound on the very click that enables it.
     */
    play(name, force = false) {
        if (!this.enabled && !force) {
            return;
        }

        const ctx = force ? this.context() : this.ctx;
        if (!ctx || ctx.state !== 'running' && !force) {
            return;
        }

        // Throttle per tone so a fast pointer sweep does not turn into a buzz.
        const now = performance.now();
        const minGap = { hover: 45, move: 30, count: 180 }[name] ?? 20;
        if (now - (this.lastPlayed[name] ?? 0) < minGap) {
            return;
        }
        this.lastPlayed[name] = now;

        const t = ctx.currentTime + 0.005;
        switch (name) {
            case 'hover':
                this.blip(t, 2400, 2000, 0.03, 0.01, 'sine');
                break;
            case 'tap':
                this.blip(t, 540, 360, 0.07, 0.045, 'triangle');
                this.burst(t, 0.025, 0.02, 4200, 6);
                break;
            case 'move':
                this.blip(t, 720, 720, 0.022, 0.012, 'square');
                break;
            case 'keep':
                this.blip(t, 660, 660, 0.08, 0.05, 'triangle');
                this.blip(t + 0.07, 990, 990, 0.12, 0.05, 'triangle');
                break;
            case 'drop':
                this.blip(t, 440, 260, 0.14, 0.045, 'sine');
                break;
            case 'on':
                [523.25, 659.25, 783.99].forEach((f, i) => this.blip(t + i * 0.06, f, f, 0.12, 0.045, 'triangle'));
                break;
            case 'off':
                [783.99, 659.25, 523.25].forEach((f, i) => this.blip(t + i * 0.05, f, f, 0.1, 0.035, 'triangle'));
                break;
            case 'whoosh':
                this.sweep(t, 0.55);
                break;
            case 'land':
                this.blip(t, 150, 62, 0.35, 0.07, 'sine');
                this.burst(t, 0.06, 0.012, 900, 1);
                break;
            case 'count':
                this.blip(t, 1318.5, 1318.5, 0.25, 0.022, 'sine');
                this.blip(t + 0.09, 1975.5, 1975.5, 0.3, 0.012, 'sine');
                break;
            default:
                break;
        }
    }

    blip(t, from, to, duration, peak, wave) {
        const osc = this.ctx.createOscillator();
        osc.type = wave;
        osc.frequency.setValueAtTime(from, t);
        if (to !== from) {
            osc.frequency.exponentialRampToValueAtTime(to, t + duration);
        }

        const gain = this.ctx.createGain();
        gain.gain.setValueAtTime(0.0001, t);
        gain.gain.exponentialRampToValueAtTime(peak, t + 0.008);
        gain.gain.exponentialRampToValueAtTime(0.0001, t + duration);

        osc.connect(gain).connect(this.master);
        osc.start(t);
        osc.stop(t + duration + 0.03);
    }

    noiseBuffer() {
        if (!this.noise) {
            const length = this.ctx.sampleRate;
            this.noise = this.ctx.createBuffer(1, length, this.ctx.sampleRate);
            const data = this.noise.getChannelData(0);
            for (let i = 0; i < length; i += 1) {
                data[i] = Math.random() * 2 - 1;
            }
        }

        return this.noise;
    }

    /* A short filtered noise tick: the "mechanical" half of a click. */
    burst(t, duration, peak, frequency, q) {
        const source = this.ctx.createBufferSource();
        source.buffer = this.noiseBuffer();
        const filter = this.ctx.createBiquadFilter();
        filter.type = 'bandpass';
        filter.frequency.value = frequency;
        filter.Q.value = q;
        const gain = this.ctx.createGain();
        gain.gain.setValueAtTime(peak, t);
        gain.gain.exponentialRampToValueAtTime(0.0001, t + duration);
        source.connect(filter).connect(gain).connect(this.master);
        source.start(t);
        source.stop(t + duration + 0.02);
    }

    /* The page wipe: band-passed noise swept upward and across the stereo field. */
    sweep(t, duration) {
        const source = this.ctx.createBufferSource();
        source.buffer = this.noiseBuffer();
        source.loop = true;

        const filter = this.ctx.createBiquadFilter();
        filter.type = 'bandpass';
        filter.Q.value = 0.9;
        filter.frequency.setValueAtTime(380, t);
        filter.frequency.exponentialRampToValueAtTime(3400, t + duration);

        const gain = this.ctx.createGain();
        gain.gain.setValueAtTime(0.0001, t);
        gain.gain.exponentialRampToValueAtTime(0.07, t + duration * 0.35);
        gain.gain.exponentialRampToValueAtTime(0.0001, t + duration);

        let chain = source.connect(filter).connect(gain);
        if (this.ctx.createStereoPanner) {
            const pan = this.ctx.createStereoPanner();
            pan.pan.setValueAtTime(-0.6, t);
            pan.pan.linearRampToValueAtTime(0.6, t + duration);
            chain = chain.connect(pan);
        }
        chain.connect(this.master);
        source.start(t);
        source.stop(t + duration + 0.05);
    }

    /* A low, slowly breathing pad under everything: two detuned saws through a moving low-pass. */
    startAmbient() {
        if (this.ambient || !this.ctx || this.ctx.state === 'closed') {
            return;
        }

        const ctx = this.ctx;
        const out = ctx.createGain();
        out.gain.setValueAtTime(0.0001, ctx.currentTime);
        out.gain.exponentialRampToValueAtTime(0.022, ctx.currentTime + 3);

        const filter = ctx.createBiquadFilter();
        filter.type = 'lowpass';
        filter.frequency.value = 420;
        filter.Q.value = 3;

        const lfo = ctx.createOscillator();
        lfo.frequency.value = 0.06;
        const depth = ctx.createGain();
        depth.gain.value = 220;
        lfo.connect(depth).connect(filter.frequency);

        const voices = [110, 110.6, 164.8, 55].map((frequency, index) => {
            const osc = ctx.createOscillator();
            osc.type = index === 3 ? 'sine' : 'sawtooth';
            osc.frequency.value = frequency;
            osc.connect(filter);
            return osc;
        });

        filter.connect(out).connect(this.master);
        [lfo, ...voices].forEach((node) => node.start());
        this.ambient = { out, nodes: [lfo, ...voices] };
    }

    stopAmbient() {
        if (!this.ambient || !this.ctx) {
            return;
        }

        const { out, nodes } = this.ambient;
        const t = this.ctx.currentTime;
        out.gain.cancelScheduledValues(t);
        out.gain.setValueAtTime(Math.max(out.gain.value, 0.0001), t);
        out.gain.exponentialRampToValueAtTime(0.0001, t + 0.6);
        nodes.forEach((node) => node.stop(t + 0.7));
        this.ambient = null;
    }
}

export const sound = new SoundEngine();
