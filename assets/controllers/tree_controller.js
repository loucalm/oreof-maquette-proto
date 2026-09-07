import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/**
 * Arbre de nœuds : glisser-déposer imbriqué via SortableJS.
 * Au drop → POST vers l'URL de déplacement, puis rechargement de la page.
 */
export default class extends Controller {
    static values = { moveUrl: String };

    connect() {
        this.sortables = [];
        this.element.querySelectorAll('ul.tree-list').forEach((list) => {
            this.sortables.push(new Sortable(list, {
                group: 'nodes',
                handle: '.drag-handle',
                animation: 150,
                fallbackOnBody: true,
                invertSwap: true,
                ghostClass: 'sortable-ghost',
                dragClass: 'sortable-drag',
                onEnd: (evt) => this.onDrop(evt),
            }));
        });
    }

    disconnect() {
        this.sortables.forEach((s) => s.destroy());
        this.sortables = [];
    }

    async onDrop(evt) {
        const li = evt.item;
        const nodeId = li.dataset.nodeId;
        const targetList = evt.to;
        const parentId = targetList.dataset.parentId || null;
        const index = Array.from(targetList.children).indexOf(li);

        try {
            const res = await fetch(this.moveUrlValue.replace('__ID__', nodeId), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ parentId, index }),
            });
            if (!res.ok) {
                // p.ex. tentative de boucle : on annule visuellement
                evt.from.insertBefore(li, evt.from.children[evt.oldIndex] || null);
                return;
            }
        } catch (e) {
            console.error(e);
        }
        if (window.Turbo) {
            window.Turbo.visit(window.location.href, { action: 'replace' });
        } else {
            window.location.reload();
        }
    }
}
