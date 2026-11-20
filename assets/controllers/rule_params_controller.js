import { Controller } from '@hotwired/stimulus';

/**
 * Ligne de règle de l'étape Structure.
 *
 * - N'affiche que les paramètres du gabarit choisi : les champs des autres gabarits sont masqués et désactivés
 *   (ils ne sont pas envoyés).
 * - Résume la règle en une phrase (« Au moins 1 EC de type « Stage » », « Semestre n° 2 », « bloquante ») ; le
 *   formulaire complet ne s'ouvre qu'au clic sur « Modifier » (ouvert d'office pour une règle neuve ou en erreur).
 *
 * Les textes viennent de la section des règles (`data-rule-labels`, cf. structure.html.twig). Dans une phrase,
 * `%param%` est remplacé par la valeur saisie, `%param|défaut%` prend le défaut si rien n'est saisi, et un passage
 * entre crochets disparaît si l'un de ses paramètres est vide.
 */
export default class extends Controller {
    static targets = ['template', 'group', 'body', 'sentence', 'scope', 'severity', 'toggle'];

    static OPS = { '>=': '≥', '<=': '≤', '==': '=', '!=': '≠', '>': '>', '<': '<' };

    connect() {
        this.sync();
    }

    sync() {
        const selected = this.templateTarget.value;
        this.groupTargets.forEach((group) => {
            const active = group.dataset.template === selected;
            group.hidden = !active;
            group.querySelectorAll('input, select, textarea').forEach((field) => {
                field.disabled = !active;
            });
        });
        this.summarize();
    }

    toggle() {
        this.bodyTarget.hidden = !this.bodyTarget.hidden;
        this.renderToggle();
    }

    // --- résumé ---------------------------------------------------------------------------------------------

    summarize() {
        const labels = this.labels();
        if (!labels) return;
        const template = this.templateTarget.value;
        const pattern = labels.sentences[template];
        this.sentenceTarget.textContent = pattern ? this.fill(pattern, (name) => this.param(template, name, labels)) : labels.empty;

        const level = this.optionText(this.field('scopeLevel'));
        const position = (this.field('scopePosition')?.value || 'all').trim();
        let scope = '';
        if (level) {
            if (position === 'all') scope = labels.scope.all.replace('%level%', this.lower(level));
            else if (position === 'first' || position === 'last') scope = labels.scope[position].replace('%level%', level);
            else scope = labels.scope.number.replace('%level%', level).replace('%n%', position);
        }
        this.scopeTarget.textContent = scope;
        this.scopeTarget.hidden = scope === '';

        const severity = this.field('severity');
        this.severityTarget.textContent = this.optionText(severity);
        this.severityTarget.dataset.severity = severity?.value ?? '';
        this.renderToggle();
    }

    renderToggle() {
        const labels = this.labels();
        if (!labels || !this.hasToggleTarget) return;
        this.toggleTarget.textContent = this.bodyTarget.hidden ? labels.edit : labels.close;
        this.toggleTarget.setAttribute('aria-expanded', this.bodyTarget.hidden ? 'false' : 'true');
    }

    /** Valeur lisible d'un paramètre du gabarit. */
    param(template, name, labels) {
        if (name === 'bound') return this.bound(template, labels);
        const field = this.element.querySelector(`[data-template="${template}"] [name$="[${name}]"]`);
        if (!field) return '';
        if (field.tagName === 'SELECT') {
            return name === 'op' ? (this.constructor.OPS[field.value] ?? field.value) : this.optionText(field);
        }
        const value = field.value.trim();
        return field.dataset.paramType === 'nature' ? this.natureLabel(value) : value;
    }

    bound(template, labels) {
        const read = (name) => this.element.querySelector(`[data-template="${template}"] [name$="[${name}]"]`)?.value.trim() ?? '';
        const min = read('min');
        const max = read('max');
        if (min !== '' && max !== '') return (min === max ? labels.bound.exact : labels.bound.between).replace('%min%', min).replace('%max%', max).replace('%n%', min);
        if (min !== '') return labels.bound.min.replace('%n%', min);
        if (max !== '') return labels.bound.max.replace('%n%', max);
        return labels.bound.none;
    }

    fill(pattern, value) {
        const placeholder = /%([a-zA-Z]+)(?:\|([^%]*))?%/g;
        const replace = (text) => text.replace(placeholder, (_, name, fallback) => value(name) || fallback || '');
        return pattern
            .replace(/\[([^\]]*)\]/g, (_, segment) => {
                const names = [...segment.matchAll(placeholder)];
                return names.every(([, name, fallback]) => value(name) || fallback) ? replace(segment) : '';
            })
            .replace(/%([a-zA-Z]+)(?:\|([^%]*))?%/g, (_, name, fallback) => value(name) || fallback || '…');
    }

    // --- accès ----------------------------------------------------------------------------------------------

    labels() {
        if (this.cachedLabels === undefined) {
            const raw = this.element.closest('[data-rule-labels]')?.dataset.ruleLabels;
            this.cachedLabels = raw ? JSON.parse(raw) : null;
        }
        return this.cachedLabels;
    }

    field(name) {
        return this.element.querySelector(`[name$="[${name}]"]`);
    }

    /** Texte de l'option choisie, sans la clé technique ajoutée entre parenthèses (« ECTS (ects) » → « ECTS »). */
    optionText(select) {
        const option = select?.selectedOptions?.[0];
        if (!option || option.value === '') return '';
        // « ECTS (%type%) (ects) » : la clé et le gabarit de libellé (« (%type%) », résolu ailleurs selon le niveau) tombent
        return option.textContent.replace(/\s*\([^()]*\)\s*$/, '').replace(/\s*\(%[^%()]*%\)/g, '').trim();
    }

    /** Libellé d'un type d'EC à partir de sa clé (types saisis dans le panneau du niveau élément). */
    natureLabel(key) {
        if (key === '') return '';
        for (const tag of document.querySelectorAll('[data-ec-type]')) {
            const inputs = tag.querySelectorAll('input');
            if (inputs[0]?.value === key) return inputs[1]?.value || key;
        }
        return key;
    }

    lower(label) {
        return label.length > 1 && label[1] === label[1].toLowerCase() ? label[0].toLowerCase() + label.slice(1) : label;
    }
}
