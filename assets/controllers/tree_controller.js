import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/**
 * Panneau « Structure de la formation » :
 *  - glisser-déposer imbriqué des nœuds (SortableJS) ;
 *  - pliage persistant des branches ;
 *  - surbrillance du nœud / de la section actifs, synchronisée avec le
 *    contenu réellement chargé dans le <turbo-frame id="node-panel">.
 *
 * Les liens de l'arbre et des sections ne rechargent que le frame de droite :
 * la colonne de gauche n'est pas re-rendue par le serveur, c'est donc ici
 * qu'on déplace la classe .is-active et qu'on tient l'URL à jour.
 */
export default class extends Controller {
    static values = { moveUrl: String, chain: Array, typeMeta: Object, rootKey: String };
    static targets = ['addForm', 'addParent', 'addType', 'addButton'];

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

        // ─── nœud actif ↔ panneau d'édition ───
        this.panel = document.getElementById('node-panel');
        this.onNavClick = this.onNavClick.bind(this);
        this.onFrameLoad = this.onFrameLoad.bind(this);
        this.element.addEventListener('click', this.onNavClick);
        if (this.panel) this.panel.addEventListener('turbo:frame-load', this.onFrameLoad);
        // si le frame est déjà peuplé (retour arrière, cache Turbo), on se cale ;
        // sinon on garde la surbrillance rendue par le serveur jusqu'au 1er load.
        if (this.markerToken()) this.syncActive();
        this.updateAddButton();
    }

    disconnect() {
        (this.sortables || []).forEach((s) => s.destroy());
        this.sortables = [];
        this.element.removeEventListener('click', this.onNavClick);
        if (this.panel) this.panel.removeEventListener('turbo:frame-load', this.onFrameLoad);
    }

    // ─── surbrillance ───

    markerToken() {
        const marker = this.panel && this.panel.querySelector('[data-panel-active]');
        return marker ? marker.dataset.panelActive : '';
    }

    /** Surbrillance immédiate au clic, avant la réponse du frame. */
    onNavClick(event) {
        // la poignée de glissement et les boutons (chevron, ajout) se gèrent seuls
        if (event.target.closest('.drag-handle, button')) return;

        const link = event.target.closest('a[data-turbo-frame="node-panel"]');
        if (link && this.element.contains(link)) {
            const row = link.closest('.tree-row');
            if (row) this.activate(row);
            else if (link.classList.contains('nav-section')) this.activate(link);
            return;
        }

        // clic n'importe où sur la ligne d'un nœud → on le sélectionne
        const row = event.target.closest('.tree-row');
        if (row && this.element.contains(row)) {
            const label = row.querySelector('a.tree-label[data-turbo-frame]');
            if (label) {
                this.activate(row);
                label.click(); // Turbo navigue le frame
            }
        }
    }

    onFrameLoad() {
        this.syncActive();
    }

    /** Source de vérité : le marqueur data-panel-active du contenu chargé. */
    syncActive() {
        const token = this.markerToken();
        let target = null;
        let url = {};

        if (token.startsWith('node:')) {
            const id = token.slice(5);
            target = this.element.querySelector(`li[data-node-id="${id}"] > .tree-row`);
            url = { focus: id };
        } else if (token.startsWith('param:')) {
            const key = token.slice(6);
            target = this.element.querySelector(`a.nav-section[href*="/parametre/${key}"]`);
            url = { param: key };
        } else if (token === 'parcours') {
            target = this.element.querySelector('a.nav-section[href*="/parcours"]');
        }

        this.activate(target);
        this.updateUrl(url);
    }

    activate(target) {
        this.element.querySelectorAll('.is-active').forEach((el) => el.classList.remove('is-active'));
        if (!target) return;
        target.classList.add('is-active');

        if (target.classList.contains('tree-row')) {
            // déplier les branches parentes pour que la ligne active soit visible
            let li = target.closest('li');
            let changed = false;
            while (li) {
                if (li.classList.contains('collapsed')) {
                    li.classList.remove('collapsed');
                    changed = true;
                }
                li = li.parentElement ? li.parentElement.closest('li') : null;
            }
            if (changed) this.persistCollapsed();
            target.scrollIntoView({ block: 'nearest' });
        }

        this.updateAddButton();
    }

    /**
     * Le bouton « ＋ Ajouter » de l'arbre suit la sélection : enfant du nœud
     * actif (type déduit du squelette), ou nœud racine si rien n'est
     * sélectionné. Le type réel est tranché côté serveur (valeur « auto »),
     * ici on ne fait que l'étiquette et le parent.
     */
    updateAddButton() {
        if (!this.hasAddButtonTarget) return;

        const meta = this.hasTypeMetaValue ? this.typeMetaValue : {};
        const chain = this.hasChainValue ? this.chainValue : [];
        const activeLi = this.element.querySelector('.tree-row.is-active')?.closest('li[data-node-id]');

        if (!activeLi) {
            const rootKey = this.hasRootKeyValue ? this.rootKeyValue : '';
            this.addParentTarget.value = '';
            this.addTypeTarget.value = rootKey || 'auto';
            this.addButtonTarget.disabled = false;
            this.addButtonTarget.textContent = meta[rootKey]
                ? `＋ Ajouter ${meta[rootKey].icon} ${meta[rootKey].label}`
                : '＋ Ajouter un nœud';
            return;
        }

        const nodeType = activeLi.dataset.nodeType;
        const name = activeLi.querySelector('.tree-label')?.textContent.trim() || 'ce nœud';
        const idx = chain.indexOf(nodeType);
        const childKey = idx >= 0 ? chain[idx + 1] : null;

        this.addParentTarget.value = activeLi.dataset.nodeId;
        this.addTypeTarget.value = childKey || 'auto';

        if (idx >= 0 && !childKey) {
            this.addButtonTarget.disabled = true;
            this.addButtonTarget.textContent = `＋ « ${name} » ne peut pas contenir d'enfant`;
            return;
        }

        this.addButtonTarget.disabled = false;
        this.addButtonTarget.textContent = childKey && meta[childKey]
            ? `＋ Ajouter ${meta[childKey].icon} ${meta[childKey].label} sous « ${name} »`
            : `＋ Ajouter un nœud sous « ${name} »`;
    }

    updateUrl(params) {
        try {
            const url = new URL(window.location.href);
            url.searchParams.delete('focus');
            url.searchParams.delete('param');
            Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
            window.history.replaceState(window.history.state, '', url);
        } catch { /* ignore */ }
    }

    // ─── pliage ───

    toggle(event) {
        event.preventDefault();
        const li = event.target.closest('li[data-node-id]');
        if (!li) return;
        li.classList.toggle('collapsed');
        this.persistCollapsed();
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
