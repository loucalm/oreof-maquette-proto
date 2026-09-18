import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/**
 * Arbre du template : réordonne par glisser-déposer les enfants directs
 * d'un même nœud (chaque <ul> — racine ou branche — a sa propre instance,
 * sans groupe partagé, donc on ne peut réordonner qu'entre frères).
 */
export default class extends Controller {
    static values = { saveUrl: String, parentPath: String };

    connect() {
        this.sortable = new Sortable(this.element, {
            handle: '.drag-handle',
            draggable: '[data-node-index]',
            animation: 150,
            forceFallback: true,
            fallbackOnBody: true,
            ghostClass: 'sortable-ghost',
            onEnd: () => this.save(),
        });
    }

    disconnect() {
        this.sortable?.destroy();
        this.sortable = null;
    }

    /** Plie/déplie la branche du nœud cliqué (état non persisté — les chemins ne sont pas stables). */
    toggle(event) {
        event.preventDefault();
        event.target.closest('li.template-node')?.classList.toggle('collapsed');
    }

    async save() {
        const order = [...this.element.children]
            .filter((el) => el.matches('[data-node-index]'))
            .map((el) => el.dataset.nodeIndex);
        const body = new URLSearchParams();
        body.append('parentPath', this.parentPathValue);
        order.forEach((i) => body.append('order[]', i));

        try {
            await fetch(this.saveUrlValue, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body,
            });
        } catch (e) {
            console.error(e);
        }

        if (window.Turbo) window.Turbo.visit(window.location.href, { action: 'replace' });
        else window.location.reload();
    }
}
