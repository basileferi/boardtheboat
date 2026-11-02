import { Controller } from '@hotwired/stimulus';
import { sound } from '../lib/sound.js';

/*
 * Counts a figure up from zero the first time it scrolls into view. The server already renders
 * the final value, so without JavaScript (or with reduced motion) the number is simply there.
 */
export default class extends Controller {
    static values = { to: Number, duration: { type: Number, default: 1600 }, suffix: { type: String, default: '' } };

    connect() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || this.toValue === 0) {
            return;
        }

        this.format = new Intl.NumberFormat(document.documentElement.lang || 'fr');
        this.element.textContent = this.format.format(0) + this.suffixValue;
        this.observer = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                this.observer.disconnect();
                this.start();
            }
        }, { threshold: 0.4 });
        this.observer.observe(this.element);
    }

    disconnect() {
        this.observer?.disconnect();
        cancelAnimationFrame(this.frame);
    }

    start() {
        const begin = performance.now();
        const tick = (now) => {
            const progress = Math.min(1, (now - begin) / this.durationValue);
            // Exponential ease-out, the same curve the CSS uses for reveals.
            const eased = progress === 1 ? 1 : 1 - 2 ** (-10 * progress);
            this.element.textContent = this.format.format(Math.round(this.toValue * eased)) + this.suffixValue;

            if (progress < 1) {
                this.frame = requestAnimationFrame(tick);
            } else {
                sound.play('count');
            }
        };
        this.frame = requestAnimationFrame(tick);
    }
}
