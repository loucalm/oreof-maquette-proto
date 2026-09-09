import { Controller } from '@hotwired/stimulus';

/** Ouvre/ferme une <dialog> (modale native). */
export default class extends Controller {
    static targets = ['dialog'];

    open() {
        this.dialogTarget.showModal();
    }

    close() {
        this.dialogTarget.close();
    }

    /** Clic sur le fond (hors contenu) → ferme. */
    clickOutside(event) {
        if (event.target === this.dialogTarget) this.dialogTarget.close();
    }
}
