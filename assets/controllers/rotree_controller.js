import { Controller } from '@hotwired/stimulus';

/**
 * Consultation (lecture seule) : arbre repliable à gauche, un clic sur un nœud
 * affiche son contenu à droite.
 */
export default class extends Controller {
    static targets = ['detail', 'empty'];

    select(event) {
        const id = String(event.params.id);
        this.element.querySelectorAll('.tree-row.is-active').forEach((r) => r.classList.remove('is-active'));
        event.currentTarget.classList.add('is-active');
        this.detailTargets.forEach((d) => { d.hidden = d.dataset.node !== id; });
        if (this.hasEmptyTarget) this.emptyTarget.hidden = true;
    }

    toggle(event) {
        event.stopPropagation();
        event.currentTarget.closest('li[data-node-id]')?.classList.toggle('collapsed');
    }
}
