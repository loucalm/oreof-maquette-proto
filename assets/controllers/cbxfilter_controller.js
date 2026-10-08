import { Controller } from '@hotwired/stimulus';

/**
 * Liste de cases à cocher filtrable (compétences BCC d'un nœud) : champ de
 * recherche qui masque les lignes hors correspondance + compteur de sélection.
 */
export default class extends Controller {
    static targets = ['input', 'row', 'group', 'count', 'empty', 'chips'];

    connect() {
        this.updateCount();
        this._onChange = () => this.updateCount();
        this.element.addEventListener('change', this._onChange);
    }

    disconnect() {
        this.element.removeEventListener('change', this._onChange);
    }

    filter() {
        const q = this.inputTarget.value.trim().toLowerCase();
        this.rowTargets.forEach((r) => {
            r.hidden = q !== '' && !(r.dataset.text || '').includes(q);
        });
        this.groupTargets.forEach((g) => {
            g.hidden = !g.querySelector('[data-cbxfilter-target="row"]:not([hidden])');
        });
        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = this.rowTargets.some((r) => !r.hidden);
        }
    }

    updateCount() {
        const checked = this.rowTargets.map((r) => r.querySelector('input')).filter((i) => i?.checked);
        if (this.hasCountTarget) {
            const n = checked.length;
            this.countTarget.textContent = n > 0 ? `${n} sélectionnée${n > 1 ? 's' : ''}` : '';
        }
        if (this.hasChipsTarget) this.renderChips(checked);
    }

    /** Étiquettes des éléments cochés, avec une croix pour décocher sans chercher dans la liste. */
    renderChips(checked) {
        this.chipsTarget.replaceChildren(...checked.map((input) => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'cbx-chip';
            chip.textContent = `${input.value} ×`;
            chip.setAttribute('aria-label', `Retirer « ${input.value} »`);
            chip.addEventListener('click', () => {
                input.checked = false;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
            return chip;
        }));
        this.chipsTarget.hidden = checked.length === 0;
    }
}
