import { Controller } from '@hotwired/stimulus';

/**
 * Étape Structure : arbre épuré + panneau de réglages.
 *
 * Chaque niveau a une ligne dans l'arbre (`[data-tree-level]`) et un bloc de réglages dans le panneau
 * (`[data-level-panel]`), reliés par `data-index` (l'indice de la ligne dans la collection Symfony). L'ordre de la
 * chaîne est celui des lignes de l'arbre ; il est recopié dans le champ `position` de chaque bloc.
 * Le niveau élément (`data-element-level`, posé côté serveur depuis NodeType) reste en dernier.
 * Les résumés des lignes (« × 2 · 30 ECTS ») sont recalculés à chaque changement, avec les textes fournis par la page.
 */
export default class extends Controller {
    static targets = ['tree', 'panels', 'help', 'addMenu', 'addWrap', 'durationBound', 'durationLevel', 'durationNotice', 'ecTypeList', 'parcours'];
    static values = { labels: Object, rowPrototype: String, panelPrototype: String, ecTypePrototype: String, index: Number, ecTypeIndex: Number };

    connect() {
        // durée déjà orpheline à l'affichage (niveau retiré de la chaîne enregistrée) : le signalement reste
        this.durationCleared = this.hasDurationNoticeTarget && !this.durationNoticeTarget.hidden;
        this.layout();
        // après un enregistrement refusé, ouvrir le premier niveau en erreur
        const invalid = this.element.querySelector('[data-level-panel][data-invalid]');
        if (invalid) this.show(invalid.dataset.index);
    }

    // --- sélection -------------------------------------------------------------------------------------------

    select(event) {
        if (event.type === 'keydown') event.preventDefault();
        this.show(event.currentTarget.dataset.index);
    }

    show(index) {
        this.panels().forEach((panel) => { panel.hidden = panel.dataset.index !== String(index); });
        // écran étroit : le panneau est sous l'arbre, on l'amène à l'écran
        const shown = this.panel(index);
        if (shown && !window.matchMedia('(min-width: 900px)').matches) shown.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        this.rows().forEach((row) => row.setAttribute('aria-selected', row.dataset.index === String(index) ? 'true' : 'false'));
        this.helpTarget.hidden = this.panels().some((panel) => !panel.hidden);
    }

    // --- édition de la chaîne -------------------------------------------------------------------------------

    toggleAddMenu() {
        const used = new Set(this.rows().map((row) => row.dataset.treeLevel));
        let offered = 0;
        this.addMenuTarget.querySelectorAll('[data-type-key]').forEach((button) => {
            button.hidden = used.has(button.dataset.typeKey);
            if (!button.hidden) offered += 1;
        });
        this.addMenuTarget.querySelector('[data-add-empty]').hidden = offered > 0;
        this.addMenuTarget.hidden = !this.addMenuTarget.hidden;
    }

    /** Ajoute un niveau du type choisi juste avant le niveau élément, puis l'ouvre dans le panneau. */
    addLevel(event) {
        const { typeKey, typeLabel, temporal } = event.currentTarget.dataset;
        const index = String(this.indexValue);
        this.indexValue += 1;

        this.panelsTarget.insertAdjacentHTML('beforeend', this.panelPrototypeValue.replaceAll('__name__', index));
        const panel = this.panel(index);
        panel.querySelector('[data-field="typeKey"]').value = typeKey;
        panel.querySelectorAll('[data-level-label]').forEach((el) => { el.textContent = typeLabel; });

        const template = document.createElement('template');
        template.innerHTML = this.rowPrototypeValue.replaceAll('__name__', index).trim();
        const row = template.content.firstElementChild;
        row.dataset.treeLevel = typeKey;
        if (temporal === '1') row.dataset.temporal = '';
        row.querySelector('[data-level-label]').textContent = typeLabel;
        this.treeTarget.insertBefore(row, this.elementRow() ?? this.addWrapTarget);

        this.addMenuTarget.hidden = true;
        this.layout();
        this.show(index);
    }

    up(event) {
        const row = this.rowOf(event);
        const previous = row?.previousElementSibling;
        if (previous?.matches('[data-tree-level]')) this.treeTarget.insertBefore(row, previous);
        this.layout();
    }

    down(event) {
        const row = this.rowOf(event);
        const next = row?.nextElementSibling;
        if (next?.matches('[data-tree-level]') && !next.matches('[data-element-level]')) this.treeTarget.insertBefore(next, row);
        this.layout();
    }

    remove(event) {
        const panel = event.currentTarget.closest('[data-level-panel]');
        this.row(panel.dataset.index)?.remove();
        panel.remove();
        this.show(null);
        this.layout();
    }

    addEcType() {
        const html = this.ecTypePrototypeValue.replaceAll('__name__', String(this.ecTypeIndexValue));
        this.ecTypeIndexValue += 1;
        this.ecTypeListTarget.insertAdjacentHTML('beforeend', html);
        this.ecTypeListTarget.lastElementChild?.querySelector('input[type="text"]')?.focus();
        this.layout();
    }

    removeEcType(event) {
        event.currentTarget.closest('[data-ec-type]')?.remove();
        this.layout();
    }

    /** Un réglage a changé : contraintes entre réglages, puis l'arbre. */
    changed(event) {
        const field = event.target;
        if (field.matches?.('[data-field="locked"]') && field.value === '1') {
            const count = field.closest('[data-level-panel]').querySelector('[data-count-field]');
            // premier niveau : pas de nombre saisi (il découle de la durée), le verrou ne le touche pas
            if (count.hidden) { this.layout(); return; }
            const mode = count.querySelector('[data-bound-target="mode"]');
            const value = count.querySelector('input[data-bound-target="value"]');
            if (mode.value !== 'exact') {
                // verrouillé ⇒ nombre exact ; valeur proposée : le minimum actuel
                if (value.value === '') value.value = '1';
                mode.value = 'exact';
                mode.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
        if (field.matches?.('[data-ec-type] input[type="text"]')) field.size = Math.max(6, field.value.length);
        this.layout();
    }

    // --- mise en page ---------------------------------------------------------------------------------------

    layout() {
        const rows = this.rows();
        const last = rows.length - 1;
        const elementAtEnd = rows[last]?.matches('[data-element-level]');
        const aboveIndex = elementAtEnd && last >= 2 ? last - 1 : -1;
        const coefficient = aboveIndex >= 0 && this.field(rows[aboveIndex], 'ecLink')?.value === 'coefficient';

        rows.forEach((row, i) => {
            const panel = this.panel(row.dataset.index);
            if (!panel) return;
            this.field(row, 'position').value = String(i);
            const isElement = row.matches('[data-element-level]');
            const coefRow = isElement && coefficient;
            row.style.marginLeft = `${(coefRow ? i - 1 : i) * 22}px`;

            // nombre : déduit de la durée pour le premier niveau, sinon « Nombre par <parent> »
            panel.querySelector('[data-count-field]').hidden = i === 0;
            panel.querySelector('[data-count-derived]').hidden = i !== 0;
            const parent = i === 0 ? null : rows[coefRow ? i - 2 : i - 1];
            panel.querySelector('[data-count-label]').textContent = this.labelsValue.countPer.replace('%parent%', parent ? this.lower(this.labelOf(parent)) : '');

            // verrouillé ⇒ seul « exactement » reste proposé
            const locked = this.field(row, 'locked')?.value === '1';
            panel.querySelectorAll('[data-count-field] [data-bound-target="mode"] option').forEach((option) => { option.disabled = locked && i > 0 && option.value !== 'exact'; });
            row.querySelector('[data-icon="locked"]').hidden = !locked;
            row.querySelector('[data-icon="editable"]').hidden = locked;

            // « Les EC… » : seulement le niveau juste au-dessus du niveau élément (et pas le premier)
            const linkField = panel.querySelector('[data-ec-link-field]');
            linkField.hidden = i !== aboveIndex;
            const link = this.field(row, 'ecLink');
            if (i !== aboveIndex && link && link.value !== 'contains') link.value = 'contains';
            panel.querySelector('[data-ec-link-note]').hidden = link?.value !== 'coefficient';

            // ECTS : un niveau élément rattaché par coefficient a des ECTS libres (ils sont saisis au niveau au-dessus)
            const ectsMode = this.field(row, 'ectsMode');
            if (ectsMode) {
                const forced = coefRow;
                if (forced && ectsMode.value !== 'free') ectsMode.value = 'free';
                ectsMode.querySelectorAll('option').forEach((option) => { option.disabled = forced && option.value !== 'free'; });
                panel.querySelector('[data-ects-bound]').hidden = ectsMode.value === 'free';
            }

            // « Parcours autorisés » suit le premier niveau
            if (i === 0 && this.hasParcoursTarget) {
                const slot = panel.querySelector('[data-parcours-slot]');
                if (slot && !slot.contains(this.parcoursTarget)) slot.appendChild(this.parcoursTarget);
            }

            const coef = row.querySelector('[data-coef]');
            coef.hidden = !coefRow;
            coef.textContent = coefRow ? this.labelsValue.coef.replace('%level%', this.labelOf(rows[aboveIndex])) : '';
        });

        this.addWrapTarget.style.marginLeft = `${Math.max(0, last) * 22}px`;
        this.syncDurationLevels(rows);
        this.summarize(rows);
    }

    /**
     * La liste du niveau de durée ne propose que les niveaux temporels de la chaîne, dans l'ordre. Si le niveau choisi
     * quitte la chaîne, la durée est effacée (pas reportée sur un autre niveau) et la ligne de durée le signale.
     */
    syncDurationLevels(rows) {
        const select = this.durationLevelTarget;
        const current = select.value;
        const temporal = rows.filter((row) => row.hasAttribute('data-temporal'));
        const wanted = temporal.map((row) => row.dataset.treeLevel);
        if (current !== '' && !wanted.includes(current)) {
            this.clearDuration();
            this.durationCleared = true;
        }
        const empty = current === '' || !wanted.includes(current);
        if (!empty) this.durationCleared = false;
        const values = (empty ? [''] : []).concat(wanted);
        if (values.join('|') !== [...select.options].map((option) => option.value).join('|')) {
            select.replaceChildren(
                ...(empty ? [new Option('—', '')] : []),
                ...temporal.map((row) => new Option(this.lower(this.labelOf(row)), row.dataset.treeLevel)),
            );
            select.value = empty ? '' : current;
        }
        if (this.hasDurationNoticeTarget) this.durationNoticeTarget.hidden = !(empty && this.durationCleared);
    }

    clearDuration() {
        const mode = this.durationBoundTarget.querySelector('[data-bound-target="mode"]');
        this.durationBoundTarget.querySelectorAll('input').forEach((input) => { input.value = ''; });
        mode.value = 'none';
        // sans propagation : seule la borne (bound#update) doit réagir, pas une nouvelle mise en page
        mode.dispatchEvent(new Event('change'));
    }

    summarize(rows) {
        const duration = this.boundOf(this.durationBoundTarget);
        const durationKey = this.durationLevelTarget.value || rows[0]?.dataset.treeLevel;
        rows.forEach((row, i) => {
            const panel = this.panel(row.dataset.index);
            if (!panel) return;
            const parts = [];
            const count = i === 0 ? this.firstCount(rows, duration, durationKey) : this.boundText('count', this.boundOf(panel.querySelector('[data-count-field]')));
            if (count) parts.push(count);
            if (this.field(row, 'ectsMode')?.value !== 'free') {
                const ects = this.boundText('ects', this.boundOf(panel.querySelector('[data-ects-bound]')));
                if (ects) parts.push(ects);
            }
            const types = panel.querySelectorAll('[data-ec-type]').length;
            if (panel.querySelector('[data-ec-types]')) parts.push((types === 1 ? this.labelsValue.typesOne : this.labelsValue.typesMany).replace('%n%', types));
            row.querySelector('[data-summary]').textContent = parts.join(' · ');
            row.querySelector('[data-icon="duration"]').hidden = !(duration.mode !== 'none' && row.dataset.treeLevel === durationKey);
        });
    }

    /** Nombre du premier niveau : comme DurationMath::firstLevelBound() (minimum arrondi au-dessus, maximum au-dessous). */
    firstCount(rows, duration, durationKey) {
        const at = rows.findIndex((row) => row.dataset.treeLevel === durationKey);
        if (at < 0) return '';
        const { min, max } = this.minMax(duration);
        if (min === null && max === null) return '';
        let factor = 1;
        for (let i = 1; i <= at; i += 1) {
            const count = this.minMax(this.boundOf(this.panel(rows[i].dataset.index).querySelector('[data-count-field]')));
            factor *= count.min > 0 ? count.min : 1;
        }
        return this.boundText('count', this.fromMinMax(min === null ? null : Math.ceil(min / factor), max === null ? null : Math.floor(max / factor)));
    }

    /** Borne du formulaire (mode + nombres) → minimum / maximum, comme BoundType. */
    minMax({ mode, value, upper }) {
        if (value === null) return { min: null, max: null };
        switch (mode) {
            case 'exact': return { min: value, max: value };
            case 'between': return { min: value, max: upper };
            case 'min': return { min: value, max: null };
            case 'max': return { min: null, max: value };
            default: return { min: null, max: null };
        }
    }

    /** Minimum / maximum → borne du formulaire, comme BoundType::viewData(). */
    fromMinMax(min, max) {
        if (min === null && max === null) return { mode: 'none', value: null, upper: null };
        if (min === max) return { mode: 'exact', value: min, upper: null };
        if (min !== null && max !== null) return { mode: 'between', value: min, upper: max };
        return min !== null ? { mode: 'min', value: min, upper: null } : { mode: 'max', value: max, upper: null };
    }

    boundText(kind, { mode, value, upper }) {
        const l = this.labelsValue[kind];
        if (value === null || !l[mode]) return '';
        if (mode === 'between' && upper === null) return '';
        return l[mode].replace('%n%', value).replace('%min%', value).replace('%max%', upper);
    }

    boundOf(container) {
        const number = (el) => (el && el.value !== '' ? Number(el.value) : null);
        return {
            mode: container?.querySelector('[data-bound-target="mode"]')?.value ?? 'none',
            value: number(container?.querySelector('input[data-bound-target="value"]')),
            upper: number(container?.querySelector('input[data-bound-target="upper"]')),
        };
    }

    // --- accès ----------------------------------------------------------------------------------------------

    rows() {
        return [...this.treeTarget.querySelectorAll(':scope > [data-tree-level]')];
    }

    panels() {
        return [...this.panelsTarget.querySelectorAll(':scope > [data-level-panel]')];
    }

    row(index) {
        return this.treeTarget.querySelector(`:scope > [data-tree-level][data-index="${index}"]`);
    }

    panel(index) {
        return this.panelsTarget.querySelector(`:scope > [data-level-panel][data-index="${index}"]`);
    }

    elementRow() {
        return this.treeTarget.querySelector(':scope > [data-tree-level][data-element-level]');
    }

    rowOf(event) {
        return this.row(event.currentTarget.closest('[data-level-panel]').dataset.index);
    }

    /** Champ `data-field` du bloc de réglages d'une ligne de l'arbre. */
    field(row, name) {
        return this.panel(row.dataset.index)?.querySelector(`[data-field="${name}"]`);
    }

    labelOf(row) {
        return row.querySelector('[data-level-label]').textContent.trim();
    }

    /** Libellé dans une phrase : « Semestre » → « semestre », mais « UE » reste « UE » (cf. _structure_tree.html.twig). */
    lower(label) {
        return label.length > 1 && label[1] === label[1].toLowerCase() ? label[0].toLowerCase() + label.slice(1) : label;
    }
}
