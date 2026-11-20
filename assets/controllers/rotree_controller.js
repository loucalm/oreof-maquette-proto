import { Controller } from '@hotwired/stimulus';

/**
 * Consultation (lecture seule) : arbre repliable à gauche (replié par défaut), un clic sur un nœud
 * affiche son contenu à droite et déplie sa branche.
 */
export default class extends Controller {
    static targets = ['detail', 'empty'];

    select(event) {
        const id = String(event.params.id);
        // un nœud choisi : sa branche s'ouvre (l'arbre est replié par défaut)
        event.currentTarget.closest('li[data-node-id]')?.classList.remove('collapsed');
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
