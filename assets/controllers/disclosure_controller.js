import { Controller } from '@hotwired/stimulus';

/**
 * Bouton « Voir / Masquer » un panneau. [data-disclosure-target="panel"] est
 * masqué par défaut ; [data-disclosure-target="label"] alterne les deux textes.
 */
export default class extends Controller {
    static targets = ['panel', 'label'];
    static values = { shown: { type: String, default: 'Masquer' }, hidden: { type: String, default: 'Voir' } };

    toggle() {
        const open = this.panelTarget.hidden;
        this.panelTarget.hidden = !open;
        if (this.hasLabelTarget) {
            this.labelTarget.textContent = open ? this.shownValue : this.hiddenValue;
        }
    }
}
