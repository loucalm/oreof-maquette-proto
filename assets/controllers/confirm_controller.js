import { Controller } from '@hotwired/stimulus';

/**
 * Confirmation avant une action importante / destructive — passe par la
 * modale stylée `confirm-dialog` (cf. base.html.twig) plutôt que
 * `window.confirm()`.
 *
 * Deux usages :
 *  - `data-controller="confirm" data-confirm-message-value="…"` sur un
 *    `<form>` : confirme avant l'envoi (boutons Supprimer, Vider, etc.).
 *  - `data-action="change->confirm#confirmChange" data-confirm-message-param="…"`
 *    sur un radio/checkbox qui déclenche lui-même la soumission (ex. bascule
 *    mono/avec parcours) : confirme avant de soumettre, et remet l'entrée
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
        // déjà confirmé (on a nous-même redéclenché la soumission plus bas) : ne pas re-demander.
        if (this.skipNextCheck) {
            this.skipNextCheck = false;
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();

        this.ask(this.messageValue).then((ok) => {
            if (ok) {
                this.skipNextCheck = true;
                this.element.requestSubmit();
            } else {
                this.restore();
            }
        });
    };

    confirmChange(event) {
        const message = event.params.message || this.messageValue;
        const form = event.currentTarget.form;

        this.ask(message).then((ok) => {
            if (ok) {
                this.skipNextCheck = true;
                form.requestSubmit();
            } else {
                this.restore();
            }
        });
    }

    /** Remet les entrées `restore` à l'état qu'elles avaient avant le changement. */
    restore() {
        this.restoreTargets.forEach((el) => { el.checked = el.dataset.confirmChecked === '1'; });
    }

    /** @return {Promise<boolean>} */
    ask(message) {
        // filet de sécurité si la modale globale n'est pas montée (ne devrait pas arriver).
        return window.confirmDialog ? window.confirmDialog.ask(message) : Promise.resolve(window.confirm(message));
    }
}
