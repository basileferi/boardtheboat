import { Controller } from '@hotwired/stimulus';

/*
 * Keyboard navigation for the offer split view.
 *
 * Progressive enhancement only: every card is a real link, so the list works with this
 * controller disabled. The controller adds j/k and arrow navigation, and keeps the
 * "currently open" marker in step with what the detail frame shows.
 */
export default class extends Controller {
    static targets = ['item', 'detail'];

    connect() {
        this.onKeydown = this.onKeydown.bind(this);
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
    }

    onKeydown(event) {
        if (event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }

        const tag = document.activeElement?.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
            return;
        }

        const keys = { j: 1, ArrowDown: 1, k: -1, ArrowUp: -1 };
        const step = keys[event.key];
        if (step === undefined) {
            return;
        }

        event.preventDefault();
        this.move(step);
    }

    move(step) {
        const items = this.itemTargets;
        if (items.length === 0) {
            return;
        }

        const currentIndex = items.findIndex((item) => item.getAttribute('aria-current') === 'true');
        const nextIndex = Math.min(items.length - 1, Math.max(0, (currentIndex === -1 ? 0 : currentIndex) + step));
        const next = items[nextIndex];

        items.forEach((item) => item.setAttribute('aria-current', item === next ? 'true' : 'false'));
        document.dispatchEvent(new CustomEvent('btb:sound', { detail: { tone: 'move' } }));
        next.click();
        next.scrollIntoView({ block: 'nearest' });
        next.focus({ preventScroll: true });
    }
}
