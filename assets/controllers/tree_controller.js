import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/**
 * Arbre de nœuds : glisser-déposer imbriqué (SortableJS) + pliage des branches.
 * Au drop → POST vers l'URL de déplacement puis rechargement.
 */
export default class extends Controller {
    static values = { moveUrl: String };

    connect() {
        this.storeKey = 'tree-collapsed';
        this.restoreCollapsed();

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
        (this.sortables || []).forEach((s) => s.destroy());
        this.sortables = [];
    }

    toggle(event) {
        event.preventDefault();
        const li = event.target.closest('li[data-node-id]');
        if (!li) return;
        li.classList.toggle('collapsed');
        this.persistCollapsed();
    }

    // ─── pliage persistant ───
    get collapsedSet() {
        try {
            return new Set(JSON.parse(localStorage.getItem(this.storeKey) || '[]'));
        } catch {
            return new Set();
        }
    }

    restoreCollapsed() {
        const set = this.collapsedSet;
        this.element.querySelectorAll('li[data-node-id]').forEach((li) => {
            if (set.has(li.dataset.nodeId)) li.classList.add('collapsed');
        });
    }

    persistCollapsed() {
        const ids = [...this.element.querySelectorAll('li.collapsed[data-node-id]')].map((li) => li.dataset.nodeId);
        try {
            localStorage.setItem(this.storeKey, JSON.stringify(ids));
        } catch { /* ignore */ }
    }

    // ─── déplacement ───
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
                (evt.from).insertBefore(li, evt.from.children[evt.oldIndex] || null);
                return;
            }
        } catch (e) {
            console.error(e);
        }
        if (window.Turbo) window.Turbo.visit(window.location.href, { action: 'replace' });
        else window.location.reload();
    }
}
