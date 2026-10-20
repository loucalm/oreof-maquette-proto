import { Controller } from '@hotwired/stimulus';

/**
 * Ligne de règle : n'affiche que les paramètres du gabarit choisi. Les champs
 * des autres gabarits sont masqués et désactivés (ils ne sont pas envoyés).
 */
export default class extends Controller {
    static targets = ['template', 'group'];

    connect() {
        this.sync();
    }

    sync() {
        const selected = this.templateTarget.value;
        this.groupTargets.forEach((group) => {
            const active = group.dataset.template === selected;
            group.hidden = !active;
            group.querySelectorAll('input, select, textarea').forEach((field) => {
                field.disabled = !active;
            });
        });
    }
}
