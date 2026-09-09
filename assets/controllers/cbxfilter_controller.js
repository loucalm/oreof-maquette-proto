import { Controller } from '@hotwired/stimulus';

/**
 * Liste de cases à cocher filtrable (compétences BCC d'un nœud) : champ de
 * recherche qui masque les lignes hors correspondance + compteur de sélection.
 */
export default class extends Controller {
    static targets = ['input', 'row', 'group', 'count', 'empty'];

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
        if (!this.hasCountTarget) return;
        const n = this.rowTargets.filter((r) => r.querySelector('input')?.checked).length;
        this.countTarget.textContent = n > 0 ? `${n} sélectionnée${n > 1 ? 's' : ''}` : '';
    }
}
