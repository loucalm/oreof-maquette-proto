import { Controller } from '@hotwired/stimulus';

/**
 * Filtre de recherche dans l'arbre de l'éditeur de formation.
 * - Recherche insensible à la casse et aux accents (NFD + suppression diacritiques)
 * - Filtre sur libellé, référence, type (badge)
 * - Mode "todo" : ne garde que les nœuds dont le statut propre est incomplete ou empty
 * - Affiche le chemin vers les résultats (ancêtres visibles)
 * - Raccourci global "/" pour focus, Échap pour réinitialiser
 */
export default class extends Controller {
    static targets = ['input', 'tree', 'count', 'empty', 'mode'];

    static values = {
        debounceMs: { type: Number, default: 150 },
    };

    connect() {
        this._debounceTimer = null;
        this._currentMode = 'all';
        this._boundFilter = this.filter.bind(this);
        this._boundGlobalKeydown = this._onGlobalKeydown.bind(this);
        this._boundInputKeydown = this._onInputKeydown.bind(this);

        this.inputTarget.addEventListener('input', this._boundFilter);
        this.inputTarget.addEventListener('keydown', this._boundInputKeydown);
        document.addEventListener('keydown', this._boundGlobalKeydown);

        // Initialiser les boutons de mode si présents
        if (this.hasModeTarget) {
            this.modeTargets.forEach((btn) => {
                btn.addEventListener('click', (e) => this.setMode(e));
                // État initial
                if (btn.dataset.mode === this._currentMode) {
                    btn.setAttribute('aria-pressed', 'true');
                } else {
                    btn.setAttribute('aria-pressed', 'false');
                }
            });
        }
    }

    disconnect() {
        this.inputTarget.removeEventListener('input', this._boundFilter);
        this.inputTarget.removeEventListener('keydown', this._boundInputKeydown);
        document.removeEventListener('keydown', this._boundGlobalKeydown);

        if (this.hasModeTarget) {
            this.modeTargets.forEach((btn) => {
                btn.removeEventListener('click', this.setMode);
            });
        }

        if (this._debounceTimer) {
            clearTimeout(this._debounceTimer);
        }
    }

    filter() {
        clearTimeout(this._debounceTimer);
        this._debounceTimer = setTimeout(() => this._applyFilter(), this.debounceMsValue);
    }

    setMode(event) {
        const btn = event.currentTarget;
        const mode = btn.dataset.mode;
        if (!mode || mode === this._currentMode) return;

        this._currentMode = mode;
        this.modeTargets.forEach((b) => {
            b.setAttribute('aria-pressed', b.dataset.mode === mode ? 'true' : 'false');
        });
        this._applyFilter();
    }

    _onInputKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            this.inputTarget.value = '';
            if (this._currentMode !== 'all') {
                this._currentMode = 'all';
                if (this.hasModeTarget) {
                    this.modeTargets.forEach((b) => {
                        b.setAttribute('aria-pressed', b.dataset.mode === 'all' ? 'true' : 'false');
                    });
                }
            }
            this._applyFilter();
            this.inputTarget.blur();
        }
    }

    _onGlobalKeydown(event) {
        // Raccourci "/" pour focus le champ, sauf si on est déjà dans un champ éditable
        if (event.key !== '/') return;

        const target = event.target;
        const isEditing = target instanceof HTMLInputElement
            || target instanceof HTMLTextAreaElement
            || target instanceof HTMLSelectElement
            || target.isContentEditable;

        if (isEditing) return;

        event.preventDefault();
        this.inputTarget.focus();
    }

    _applyFilter() {
        const query = this._normalize(this.inputTarget.value.trim());
        const mode = this._currentMode;

        // Si pas de filtre actif : tout réafficher
        if (query === '' && mode === 'all') {
            this._showAll();
            return;
        }

        const nodes = this.treeTarget.querySelectorAll('li[data-node-id]');
        const matchMap = new Map(); // nodeId -> boolean (match direct)
        const descendantMatchMap = new Map(); // nodeId -> boolean (a un descendant qui match)

        // Première passe : déterminer quels nœuds matchent directement
        nodes.forEach((li) => {
            const matches = this._nodeMatches(li, query, mode);
            matchMap.set(li.dataset.nodeId, matches);
        });

        // Deuxième passe : propager vers les ancêtres (un nœud est visible s'il match ou si un descendant match)
        // On parcourt dans l'ordre inverse (feuilles vers racine) pour propager efficacement
        const nodeArray = Array.from(nodes);
        nodeArray.reverse().forEach((li) => {
            const id = li.dataset.nodeId;
            const hasMatchingDescendant = this._hasMatchingDescendant(li, matchMap, descendantMatchMap);
            descendantMatchMap.set(id, hasMatchingDescendant);
        });

        // Troisième passe : appliquer les classes
        let matchCount = 0;
        nodes.forEach((li) => {
            const id = li.dataset.nodeId;
            const isMatch = matchMap.get(id);
            const hasMatchDescendant = descendantMatchMap.get(id);
            li.hidden = !(isMatch || hasMatchDescendant);
            // chemin vers un résultat : déplié pendant le filtre (annule « collapsed » via le CSS)
            li.classList.toggle('tf-force-open', Boolean(hasMatchDescendant));
            li.classList.toggle('tf-match', Boolean(isMatch));
            if (isMatch) matchCount++;
        });

        // Mettre à jour le compteur
        if (this.hasCountTarget) {
            this.countTarget.textContent = matchCount > 0 ? `${matchCount} résultat${matchCount > 1 ? 's' : ''}` : '';
        }

        // Afficher/masquer le message "aucun résultat"
        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = matchCount > 0;
        }
    }

    _showAll() {
        const nodes = this.treeTarget.querySelectorAll('li[data-node-id]');
        nodes.forEach((li) => {
            li.hidden = false;
            li.classList.remove('tf-force-open', 'tf-match');
        });

        if (this.hasCountTarget) {
            this.countTarget.textContent = '';
        }
        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = true;
        }
    }

    _nodeMatches(li, query, mode) {
        // Vérifier le mode "todo" : statut propre du nœud (sa propre ligne .tree-row)
        if (mode === 'todo') {
            const row = li.querySelector(':scope > .tree-row');
            if (!row) return false;
            const statusDot = row.querySelector('.status-dot[data-status]');
            if (!statusDot) return false;
            const status = statusDot.dataset.status;
            if (status !== 'incomplete' && status !== 'empty') {
                return false;
            }
        }

        // Si pas de recherche textuelle, le mode seul suffit
        if (query === '') {
            return true;
        }

        // Recherche textuelle dans libellé, référence, type
        const row = li.querySelector(':scope > .tree-row');
        if (!row) return false;

        const labelEl = row.querySelector('.tree-label');
        const refEl = row.querySelector('.tree-ref');
        const typeBadgeEl = row.querySelector('.type-badge');

        const labelText = labelEl ? this._normalize(labelEl.textContent || '') : '';
        const refText = refEl ? this._normalize(refEl.textContent || '') : '';
        const typeText = typeBadgeEl ? this._normalize(typeBadgeEl.textContent || '') : '';

        return labelText.includes(query) || refText.includes(query) || typeText.includes(query);
    }

    _hasMatchingDescendant(li, matchMap, descendantMatchMap) {
        const childrenList = li.querySelector(':scope > ul.tree-list');
        if (!childrenList) return false;

        const childLis = childrenList.querySelectorAll(':scope > li[data-node-id]');
        for (const childLi of childLis) {
            const childId = childLi.dataset.nodeId;
            if (matchMap.get(childId) || descendantMatchMap.get(childId)) {
                return true;
            }
        }
        return false;
    }

    _normalize(str) {
        // NFD + suppression des diacritiques + minuscules
        return str.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    }
}
