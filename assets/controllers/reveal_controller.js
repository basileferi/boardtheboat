import { Controller } from '@hotwired/stimulus';

/*
 * Scroll reveals for a region: every `[data-reveal]` inside it gets `is-visible` when it enters
 * the viewport (the CSS does the motion: a lift, and the gradient curtain on cards), and every
 * `[data-split]` heading is cut into words that rise one after the other.
 *
 * Only `.has-js` pages hide anything, so the content is never lost without JavaScript.
 */
export default class extends Controller {
    connect() {
        this.reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    this.observer.unobserve(entry.target);
                }
            });
        }, { rootMargin: '0px 0px -6% 0px', threshold: 0.08 });

        this.scan = this.scan.bind(this);
        this.scan();
        document.addEventListener('turbo:frame-render', this.scan);
    }

    disconnect() {
        this.observer.disconnect();
        document.removeEventListener('turbo:frame-render', this.scan);
    }

    scan() {
        this.element.querySelectorAll('[data-split]:not([data-split-done])').forEach((heading) => this.split(heading));

        let index = 0;
        this.element.querySelectorAll('[data-reveal]:not(.is-visible)').forEach((element) => {
            if (this.reduce) {
                element.classList.add('is-visible');
                return;
            }
            element.style.setProperty('--stagger', String(index % 8));
            index += 1;
            this.observer.observe(element);
        });
    }

    split(heading) {
        heading.dataset.splitDone = 'yes';
        if (this.reduce || heading.children.length > 0) {
            heading.classList.add('is-visible');
            return;
        }

        const words = heading.textContent.trim().split(/\s+/);
        heading.textContent = '';
        words.forEach((word, i) => {
            const outer = document.createElement('span');
            outer.className = 'word';
            const inner = document.createElement('span');
            inner.className = 'word__inner';
            inner.style.setProperty('--i', String(i));
            inner.textContent = word;
            outer.append(inner);
            heading.append(outer, i < words.length - 1 ? ' ' : '');
        });

        requestAnimationFrame(() => requestAnimationFrame(() => heading.classList.add('is-visible')));
    }
}
