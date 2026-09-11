import { Controller } from '@hotwired/stimulus';

/**
 * Confirmation avant une action importante / destructive.
 *
 * Deux usages :
 *  - `data-controller="confirm" data-confirm-message-value="…"` sur un
 *    `<form>` : confirme avant l'envoi (boutons Supprimer, Vider, etc.).
 *  - `data-action="change->confirm#confirmChange" data-confirm-message-param="…"`
 *    sur un radio/checkbox qui déclenche lui-même la soumission (ex. bascule
 *    mono/multi-parcours) : confirme avant de soumettre, et remet l'entrée
 *    d'origine (`data-confirm-target="restore"` + `data-confirm-checked`)
 *    si l'utilisateur annule — sinon le radio resterait visuellement sur le
 *    nouveau choix alors que rien n'a été enregistré.
 */
export default class extends Controller {
    static values = { message: { type: String, default: 'Confirmer ?' } };
    static targets = ['restore'];

    connect() {
        this.element.addEventListener('submit', this.check);
    }

    disconnect() {
        this.element.removeEventListener('submit', this.check);
    }

    check = (event) => {
        // déjà confirmé via confirmChange() juste avant : ne pas re-demander
        if (this.skipNextCheck) {
            this.skipNextCheck = false;
            return;
        }
        if (!window.confirm(this.messageValue)) {
            event.preventDefault();
            event.stopImmediatePropagation();
            this.restore();
        }
    };

    confirmChange(event) {
        const message = event.params.message || this.messageValue;
        if (window.confirm(message)) {
            this.skipNextCheck = true;
            event.currentTarget.form.requestSubmit();
        } else {
            this.restore();
        }
    }

    /** Remet les entrées `restore` à l'état qu'elles avaient avant le changement. */
    restore() {
        this.restoreTargets.forEach((el) => { el.checked = el.dataset.confirmChecked === '1'; });
    }
}
