import { Controller } from '@hotwired/stimulus';

/*
 * Submits the filter form shortly after the user stops typing, so the list follows the
 * filters without a click. The submit button stays in the markup: with JavaScript off the
 * form is an ordinary GET form and the page still works.
 */
export default class extends Controller {
    static values = { delay: { type: Number, default: 350 } };

    connect() {
        this.timer = null;
        this.element.addEventListener('input', this.schedule);
        this.element.addEventListener('change', this.schedule);
    }

    disconnect() {
        clearTimeout(this.timer);
        this.element.removeEventListener('input', this.schedule);
        this.element.removeEventListener('change', this.schedule);
    }

    schedule = (event) => {
        // A select or checkbox is a deliberate choice: submit at once, do not wait.
        const immediate = event.target.tagName !== 'INPUT' || event.target.type !== 'text';
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.element.requestSubmit(), immediate ? 0 : this.delayValue);
    };
}
