import { Controller } from '@hotwired/stimulus';

/*
 * The living background: gradient orbs that lean away from the pointer, and a soft spotlight
 * that follows it across the chart grid. Everything is driven by four CSS custom properties
 * written once per animation frame; the CSS owns the look.
 */
export default class extends Controller {
    connect() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        this.target = { x: 0.5, y: 0.35 };
        this.current = { ...this.target };
        this.onMove = (event) => {
            this.target = { x: event.clientX / window.innerWidth, y: event.clientY / window.innerHeight };
            this.schedule();
        };
        window.addEventListener('pointermove', this.onMove, { passive: true });
        this.schedule();
    }

    disconnect() {
        window.removeEventListener('pointermove', this.onMove);
        cancelAnimationFrame(this.frame);
        this.frame = null;
    }

    schedule() {
        this.frame ??= requestAnimationFrame(() => this.step());
    }

    step() {
        this.frame = null;
        // Critically damped follow: the background drifts after the pointer, never snaps.
        this.current.x += (this.target.x - this.current.x) * 0.08;
        this.current.y += (this.target.y - this.current.y) * 0.08;

        const style = this.element.style;
        style.setProperty('--mx', (this.current.x - 0.5).toFixed(4));
        style.setProperty('--my', (this.current.y - 0.5).toFixed(4));
        style.setProperty('--px', `${(this.current.x * 100).toFixed(2)}%`);
        style.setProperty('--py', `${(this.current.y * 100).toFixed(2)}%`);

        if (Math.abs(this.target.x - this.current.x) + Math.abs(this.target.y - this.current.y) > 0.0005) {
            this.schedule();
        }
    }
}
