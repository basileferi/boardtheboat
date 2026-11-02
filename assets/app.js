/*
 * Application entrypoint, loaded through the importmap() Twig function.
 *
 * Styles are NOT imported here on purpose: they are linked from base.html.twig so
 * the app stays readable when JavaScript is unavailable (progressive enhancement).
 *
 * The three modules below are document-wide and outlive Turbo navigations on purpose:
 * the sound engine keeps one AudioContext for the visit, the wipe listens to Turbo's own
 * events, and motion re-applies its enhancements after each render.
 */
import './lib/motion.js';
import './lib/sound.js';
import './lib/wipe.js';
import './stimulus_bootstrap.js';
