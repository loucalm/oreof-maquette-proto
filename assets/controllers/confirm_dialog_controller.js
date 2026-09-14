import { Controller } from '@hotwired/stimulus';

/**
 * Modale de confirmation globale — remplace `window.confirm()` par une
 * `<dialog>` stylée façon maquette (« ATTENTION ! » + message + Continuer/Annuler).
 *
 * Un seul exemplaire, monté une fois dans base.html.twig. Les nombreux
 * `data-controller="confirm"` de l'appli (un par formulaire à confirmer) ne
 * portent pas leur propre dialogue : ils appellent `window.confirmDialog.ask(message)`,
 * qui affiche cette modale et renvoie une Promise<boolean> résolue au clic
 * sur un des deux boutons (ou à la fermeture, ex. touche Échap → annulé).
 */
export default class extends Controller {
    static targets = ['dialog', 'message', 'cancelBtn'];

    connect() {
        window.confirmDialog = this;
        this.dialogTarget.addEventListener('cancel', this.onNativeCancel);
    }

    disconnect() {
        if (window.confirmDialog === this) delete window.confirmDialog;
        this.dialogTarget.removeEventListener('cancel', this.onNativeCancel);
    }

    /** @return {Promise<boolean>} true si « Continuer », false si « Annuler » / fermeture. */
    ask(message) {
        // une confirmation déjà ouverte (ne devrait pas arriver) : on l'annule avant d'en ouvrir une autre.
        this.settle(false);

        this.messageTarget.textContent = message;
        this.dialogTarget.showModal();
        // focus sur « Annuler » par défaut : une touche Entrée accidentelle n'exécute pas l'action.
        this.cancelBtnTarget.focus();

        return new Promise((resolve) => { this.resolve = resolve; });
    }

    confirm() {
        this.dialogTarget.close();
        this.settle(true);
    }

    cancel() {
        this.dialogTarget.close();
        this.settle(false);
    }

    /** Clic sur le fond (hors carte) → annule. */
    clickOutside(event) {
        if (event.target === this.dialogTarget) this.cancel();
    }

    /** Fermeture native (touche Échap) → annule. */
    onNativeCancel = () => this.settle(false);

    settle(value) {
        this.resolve?.(value);
        this.resolve = null;
    }
}
