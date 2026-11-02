/*
 * Page transitions: a two-layer curtain that rises over the page when a link is followed, and
 * lifts once Turbo has rendered the next one.
 *
 * Only link clicks to another path are wiped. Filter forms, pagination inside a frame and the
 * back button stay instant: a transition there would be decoration standing in the way of work.
 * Turbo lets us hold the render with `turbo:before-render` until the curtain is closed, so
 * nothing ever flashes underneath it. Reduced motion skips all of it.
 */
import { sound } from './sound.js';

const root = document.documentElement;
const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
const COVER_MS = 620;
const REVEAL_MS = 900;

let wantWipe = false;
let covering = null;
let safety = null;

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

document.addEventListener('turbo:click', (event) => {
    const link = event.target instanceof Element ? event.target.closest('a') : null;
    // A link that drives a frame (the split view) swaps a fragment, not the page.
    const frame = link?.dataset.turboFrame ?? link?.closest('turbo-frame')?.id;
    const url = new URL(event.detail.url, window.location.href);
    wantWipe = !reduce.matches && (frame === undefined || frame === '_top') && url.pathname !== window.location.pathname;
});

// Whatever happens to the visit, the curtain never stays down for more than a few seconds.
function release() {
    covering = null;
    root.classList.remove('is-covering', 'is-revealing');
}

document.addEventListener('turbo:visit', (event) => {
    if (!wantWipe || event.detail.action === 'restore') {
        wantWipe = false;
        return;
    }

    wantWipe = false;
    root.classList.remove('is-revealing');
    root.classList.add('is-covering');
    sound.play('whoosh');
    covering = wait(COVER_MS);
    clearTimeout(safety);
    safety = setTimeout(release, 5000);
});

document.addEventListener('turbo:before-render', async (event) => {
    if (!covering) {
        return;
    }

    event.preventDefault();
    const pending = covering;
    covering = null;
    await pending;
    event.detail.resume();
});

document.addEventListener('turbo:load', () => {
    if (!root.classList.contains('is-covering')) {
        return;
    }

    clearTimeout(safety);
    requestAnimationFrame(() => {
        root.classList.add('is-revealing');
        root.classList.remove('is-covering');
        sound.play('land');
        setTimeout(() => root.classList.remove('is-revealing'), REVEAL_MS);
    });
});

// A visit that fails (network error, 500) must not leave the curtain down.
document.addEventListener('turbo:fetch-request-error', release);
