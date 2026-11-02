import { Controller } from '@hotwired/stimulus';

/*
 * demande confirmation avant une action destructrice (suppression).
 * sans javascript le formulaire part directement : le serveur reste le vrai garde-fou
 * (csrf + voter), ceci évite juste le clic malheureux.
 */
export default class extends Controller {
    static values = { message: String };

    ask(event) {
        if (!window.confirm(this.messageValue || 'sûr ?')) {
            event.preventDefault();
        }
    }
}
