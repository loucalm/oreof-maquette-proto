import { Controller } from '@hotwired/stimulus';

/** Confirmation avant une action destructive. */
export default class extends Controller {
    static values = { message: { type: String, default: 'Confirmer ?' } };

    connect() {
        this.element.addEventListener('submit', this.check);
    }

    disconnect() {
        this.element.removeEventListener('submit', this.check);
    }

    check = (event) => {
        if (!window.confirm(this.messageValue)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    };
}
