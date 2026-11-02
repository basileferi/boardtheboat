import { Controller } from '@hotwired/stimulus';

/*
 * A ring that trails the pointer and swells over anything clickable. It never replaces the
 * system cursor (that stays, for precision and accessibility); it only annotates it. Fine
 * pointers only, and nothing at all under reduced motion.
 */
const INTERACTIVE = 'a[href], button, summary, select, input, textarea, label[for], [data-cursor]';

export default class extends Controller {
    connect() {
        if (!window.matchMedia('(pointer: fine)').matches || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        this.pos = { x: -100, y: -100 };
        this.ring = { x: -100, y: -100 };
        this.onMove = (event) => {
            this.pos = { x: event.clientX, y: event.clientY };
            this.element.classList.add('is-active');
            const hit = event.target instanceof Element ? event.target.closest(INTERACTIVE) : null;
            this.element.classList.toggle('is-hover', hit !== null);
        };
        this.onDown = () => this.element.classList.add('is-down');
        this.onUp = () => this.element.classList.remove('is-down');
        this.onLeave = () => this.element.classList.remove('is-active');

        window.addEventListener('pointermove', this.onMove, { passive: true });
        window.addEventListener('pointerdown', this.onDown, { passive: true });
        window.addEventListener('pointerup', this.onUp, { passive: true });
        document.documentElement.addEventListener('pointerleave', this.onLeave);
        this.loop = () => {
            this.ring.x += (this.pos.x - this.ring.x) * 0.18;
            this.ring.y += (this.pos.y - this.ring.y) * 0.18;
            this.element.style.setProperty('--cx', `${this.pos.x}px`);
            this.element.style.setProperty('--cy', `${this.pos.y}px`);
            this.element.style.setProperty('--rx', `${this.ring.x}px`);
            this.element.style.setProperty('--ry', `${this.ring.y}px`);
            this.frame = requestAnimationFrame(this.loop);
        };
        this.frame = requestAnimationFrame(this.loop);
    }

    disconnect() {
        cancelAnimationFrame(this.frame);
        window.removeEventListener('pointermove', this.onMove);
        window.removeEventListener('pointerdown', this.onDown);
        window.removeEventListener('pointerup', this.onUp);
        document.documentElement.removeEventListener('pointerleave', this.onLeave);
    }
}
