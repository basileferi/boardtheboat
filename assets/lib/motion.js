/*
 * Small document-wide enhancements that are not tied to one element's lifecycle:
 *  - `has-js` on <html>, so CSS only hides what JavaScript will reveal;
 *  - the rolling label of every `.button` (the text is duplicated in a data attribute and slid
 *    in from below on hover), applied again after Turbo frames and streams replace buttons;
 *  - the pointer-following glow of cards (`--gx`/`--gy`, set through CSSOM, which the strict
 *    CSP allows where it forbids style attributes).
 */
const root = document.documentElement;
root.classList.add('has-js');

export function rollButtons(scope = document) {
    scope.querySelectorAll('.button:not([data-rolled])').forEach((button) => {
        const label = button.textContent.trim();
        if (label === '' || button.children.length > 0) {
            button.dataset.rolled = 'skip';
            return;
        }

        const roll = document.createElement('span');
        roll.className = 'button__roll';
        roll.dataset.text = label;
        const inner = document.createElement('span');
        inner.textContent = label;
        roll.append(inner);

        button.dataset.rolled = 'yes';
        button.textContent = '';
        button.append(roll);
    });
}

document.addEventListener('turbo:load', () => rollButtons());
document.addEventListener('turbo:frame-render', (event) => rollButtons(event.target));
document.addEventListener('turbo:before-stream-render', (event) => {
    const render = event.detail.render;
    event.detail.render = async (stream) => {
        await render(stream);
        rollButtons();
    };
});

document.addEventListener('pointermove', (event) => {
    const card = event.target instanceof Element ? event.target.closest('.kpi, .panel--glow') : null;
    if (!card) {
        return;
    }

    const box = card.getBoundingClientRect();
    card.style.setProperty('--gx', `${event.clientX - box.left}px`);
    card.style.setProperty('--gy', `${event.clientY - box.top}px`);
}, { passive: true });
