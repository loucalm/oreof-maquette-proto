import { Controller } from '@hotwired/stimulus';

/**
 * Volume horaire d'un EC : la case « EC sans volume horaire » grise et
 * désactive les saisies d'heures (les valeurs restent affichées mais ne sont
 * pas envoyées ; le serveur enregistre alors `hours = { none: true }`).
 */
export default class extends Controller {
    static targets = ['none', 'input', 'fields'];

    connect() {
        this.sync();
    }

    sync() {
        const off = this.hasNoneTarget && this.noneTarget.checked;
        this.inputTargets.forEach((i) => { i.disabled = off; });
        if (this.hasFieldsTarget) {
            this.fieldsTarget.classList.toggle('opacity-40', off);
            this.fieldsTarget.classList.toggle('pointer-events-none', off);
        }
    }
}
