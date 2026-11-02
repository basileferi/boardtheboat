import { Controller } from '@hotwired/stimulus';
import { sound } from '../lib/sound.js';

/*
 * The sound switch and the named sound actions templates can bind to (data-action="sonar#keep").
 *
 * The engine itself lives in lib/sound.js and survives page changes; this controller only
 * mirrors its state on the toggle. Note: Stimulus owns `this.context` on every controller —
 * the previous version stored its AudioContext there, which broke the controller on connect
 * and is why the switch could never be turned on.
 */
export default class extends Controller {
    static targets = ['toggle', 'label'];

    connect() {
        this.unsubscribe = sound.subscribe((enabled) => this.render(enabled));
    }

    disconnect() {
        this.unsubscribe?.();
    }

    toggle() {
        sound.toggle();
    }

    keep() {
        sound.play('keep');
    }

    drop() {
        sound.play('drop');
    }

    move() {
        sound.play('move');
    }

    render(enabled) {
        if (!this.hasToggleTarget) {
            return;
        }

        this.toggleTarget.setAttribute('aria-pressed', enabled ? 'true' : 'false');
        const label = this.toggleTarget.dataset[enabled ? 'labelOn' : 'labelOff'];
        this.toggleTarget.setAttribute('aria-label', label);
        if (this.hasLabelTarget) {
            this.labelTarget.textContent = label;
        }
    }
}
