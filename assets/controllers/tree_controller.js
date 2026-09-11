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
    static values = { moveUrl: String, dupUrl: String, delUrl: String, chain: Array, typeMeta: Object, rootKey: String, baseParent: String };
    static targets = ['addForm', 'addParent', 'addType', 'addButton', 'dupForm', 'dupButton', 'delForm', 'delButton'];

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
                onMove: (evt) => this.canDropHere(evt),
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
        this.updateActions();
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

        // liens de sections (Paramètre / graphe des parcours)
        const navLink = event.target.closest('a.nav-section[data-turbo-frame="node-panel"]');
        if (navLink && this.element.contains(navLink)) {
            this.activate(navLink);
            return;
        }

        const row = event.target.closest('.tree-row');
        if (!row || !this.element.contains(row)) return;

        // reclic RÉEL sur le nœud déjà sélectionné → on désélectionne.
        // (isTrusted écarte le clic synthétique de re-entrée déclenché plus bas.)
        if (row.classList.contains('is-active')) {
            if (event.isTrusted) {
                event.preventDefault();
                this.deselect();
            }
            return;
        }

        // sélection : surbrillance immédiate puis navigation du frame
        this.activate(row);
        if (!event.target.closest('a.tree-label[data-turbo-frame]')) {
            row.querySelector('a.tree-label[data-turbo-frame]')?.click();
        }
    }

    /** Reclic sur le nœud actif : on vide la sélection et le panneau. */
    deselect() {
        this.activate(null);
        if (this.panel) {
            this.panel.removeAttribute('src');
            this.panel.innerHTML =
                '<div class="grid h-full place-items-center p-16 text-sm text-gray-400">'
                + 'Sélectionnez une section ou un nœud pour le modifier</div>';
        }
        this.updateUrl({});
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
            target = this.element.querySelector(`li[data-node-id="${id}"] > .tree-row`)
                || this.element.querySelector(`a.nav-section[href$="/nodes/${id}"]`);
            url = { focus: id };
        } else if (token.startsWith('param:')) {
            const key = token.slice(6);
            target = this.element.querySelector(`a.nav-section[href*="/parametre/${key}"]`);
            url = { param: key };
        } else if (token === 'parcours') {
            target = this.element.querySelector('a.nav-section[href*="/parcours"]');
        } else if (token === 'bcc') {
            target = this.element.querySelector('a.nav-section[href*="/bcc"]');
            url = { bcc: 1 };
        }

        this.activate(target);
        this.updateUrl(url);
    }

    activate(target) {
        this.element.querySelectorAll('.is-active').forEach((el) => el.classList.remove('is-active'));

        if (target) {
            target.classList.add('is-active');

            if (target.classList.contains('tree-row')) {
                // déplier la branche (nœud + ancêtres) pour que la ligne soit visible
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
        }

        this.updateActions();
    }

    /**
     * Barre d'actions en bas de l'arborescence : « Ajouter » (contextuel :
     * enfant du nœud sélectionné, sinon nœud racine), « Dupliquer » et
     * « Supprimer » qui agissent sur le nœud sélectionné.
     */
    updateActions() {
        const activeLi = this.element.querySelector('.tree-row.is-active')?.closest('li[data-node-id]');
        const activeId = activeLi?.dataset.nodeId || null;
        const name = activeLi?.querySelector('.tree-label')?.textContent.trim() || '';
        const lockedDel = activeLi?.dataset.locked === '1';

        // ── dupliquer / supprimer ──
        if (this.hasDupButtonTarget) {
            this.dupButtonTarget.disabled = !activeId;
            if (activeId && this.hasDupUrlValue) {
                this.dupFormTarget.action = this.dupUrlValue.replace('__ID__', activeId);
            }
        }
        if (this.hasDelButtonTarget) {
            this.delButtonTarget.disabled = !activeId || lockedDel;
            this.delButtonTarget.title = lockedDel
                ? 'Nœud imposé par le diplôme : suppression impossible'
                : 'Supprimer le nœud sélectionné';
            if (activeId && !lockedDel && this.hasDelUrlValue) {
                this.delFormTarget.action = this.delUrlValue.replace('__ID__', activeId);
                this.delFormTarget.dataset.confirmMessageValue =
                    `Supprimer « ${name || 'ce nœud'} » et tout ce qu'il contient ?`;
            }
        }

        // ── bouton « Ajouter » ──
        if (!this.hasAddButtonTarget) return;

        const meta = this.hasTypeMetaValue ? this.typeMetaValue : {};
        const chain = this.hasChainValue ? this.chainValue : [];

        if (!activeLi) {
            const rootKey = this.hasRootKeyValue ? this.rootKeyValue : '';
            // dans l'éditeur de parcours : « racine » = enfant direct du parcours
            this.addParentTarget.value = this.hasBaseParentValue ? this.baseParentValue : '';
            this.addTypeTarget.value = rootKey || 'auto';
            this.addButtonTarget.disabled = false;
            this.addButtonTarget.textContent = meta[rootKey]
                ? `Ajouter ${meta[rootKey].label}`
                : 'Ajouter un nœud';
            return;
        }

        const nodeType = activeLi.dataset.nodeType;
        const idx = chain.indexOf(nodeType);
        const childKey = idx >= 0 ? chain[idx + 1] : null;

        this.addParentTarget.value = activeId;
        this.addTypeTarget.value = childKey || 'auto';

        if (idx >= 0 && !childKey) {
            this.addButtonTarget.disabled = true;
            this.addButtonTarget.textContent = `« ${name} » ne peut pas contenir d'enfant`;
            return;
        }

        this.addButtonTarget.disabled = false;
        this.addButtonTarget.textContent = childKey && meta[childKey]
            ? `Ajouter ${meta[childKey].label}`
            : 'Ajouter un nœud';
    }

    updateUrl(params) {
        try {
            const url = new URL(window.location.href);
            ['focus', 'param', 'bcc'].forEach((k) => url.searchParams.delete(k));
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

    /**
     * Autorise le dépôt uniquement là où le squelette prévoit ce type d'enfant :
     * sous un nœud dont le type précède celui du nœud déplacé dans la chaîne,
     * ou à la racine si c'est le type racine.
     */
    canDropHere(evt) {
        // nœud imposé : réordonnancement entre frères OK, changement de parent refusé
        if (evt.dragged?.dataset.lockedMove === '1' && evt.to !== evt.from) return false;

        if (!this.hasChainValue) return true;
        const chain = this.chainValue;
        const draggedType = evt.dragged?.dataset.nodeType;
        if (!draggedType) return true;

        const parentLi = evt.to.closest('li[data-node-id]');
        if (!parentLi) {
            return draggedType === (this.hasRootKeyValue ? this.rootKeyValue : draggedType);
        }
        const i = chain.indexOf(parentLi.dataset.nodeType);
        return i >= 0 && chain[i + 1] === draggedType;
    }

    async onDrop(evt) {
        const li = evt.item;
        // rien n'a bougé (drop refusé, ou repositionné au même endroit) → pas d'appel serveur
        if (evt.from === evt.to && evt.oldIndex === evt.newIndex) return;

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
