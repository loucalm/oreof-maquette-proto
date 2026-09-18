import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/**
 * Arbre du template : réordonne par glisser-déposer les enfants directs
 * d'un même nœud (chaque <ul> — racine ou branche — a sa propre instance,
 * sans groupe partagé, donc on ne peut réordonner qu'entre frères), et
 * plie/déplie les branches — état persisté par `uid` de nœud (localStorage,
 * scopé par template) pour survivre au rechargement complet de l'arbre
 * qui suit chaque modification (rename, verrou, glisser-déposer...).
 */
export default class extends Controller {
    static values = { saveUrl: String, parentPath: String, templateId: String };

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
        this.restoreCollapsed();
    }

    disconnect() {
        this.sortable?.destroy();
        this.sortable = null;
    }

    toggle(event) {
        event.preventDefault();
        const li = event.target.closest('li.template-node');
        if (!li) return;
        const collapsed = li.classList.toggle('collapsed');
        if (li.dataset.nodeUid) this.setCollapsed(li.dataset.nodeUid, collapsed);
    }

    // ─── pliage persisté (par uid, pas par chemin — un chemin bouge dès qu'on
    // réordonne/ajoute/supprime ailleurs dans l'arbre) ───

    get storeKey() {
        return `tpltree-collapsed-${this.templateIdValue}`;
    }

    get collapsedSet() {
        try {
            return new Set(JSON.parse(localStorage.getItem(this.storeKey) || '[]'));
        } catch {
            return new Set();
        }
    }

    restoreCollapsed() {
        const set = this.collapsedSet;
        if (set.size === 0) return;
        [...this.element.children].forEach((li) => {
            if (li.dataset.nodeUid && set.has(li.dataset.nodeUid)) li.classList.add('collapsed');
        });
    }

    setCollapsed(uid, collapsed) {
        const set = this.collapsedSet;
        if (collapsed) set.add(uid);
        else set.delete(uid);
        try {
            localStorage.setItem(this.storeKey, JSON.stringify([...set]));
        } catch { /* ignore */ }
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
