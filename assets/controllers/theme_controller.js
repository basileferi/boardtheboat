import { Controller } from '@hotwired/stimulus';

/*
 * Light / dark / system switch. The choice is written to a first-party cookie so the server
 * renders the right `data-theme` on the next request — no flash of the wrong palette, and no
 * inline script needed to apply it early.
 */
const ORDER = ['system', 'dark', 'light'];

export default class extends Controller {
    static targets = ['label'];

    connect() {
        this.render();
    }

    cycle() {
        const current = document.documentElement.dataset.theme || 'system';
        const next = ORDER[(ORDER.indexOf(current) + 1) % ORDER.length];

        if (next === 'system') {
            delete document.documentElement.dataset.theme;
            document.cookie = 'btb_theme=; path=/; max-age=0; samesite=lax';
        } else {
            document.documentElement.dataset.theme = next;
            document.cookie = `btb_theme=${next}; path=/; max-age=31536000; samesite=lax`;
        }

        this.render();
    }

    render() {
        const current = document.documentElement.dataset.theme || 'system';
        this.element.dataset.mode = current;
        const label = this.element.dataset[`label${current[0].toUpperCase()}${current.slice(1)}`];
        this.element.setAttribute('aria-label', label ?? current);
        this.element.setAttribute('title', label ?? current);
    }
}
