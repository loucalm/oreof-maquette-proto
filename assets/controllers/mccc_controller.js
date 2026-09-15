import { Controller } from '@hotwired/stimulus';

/**
 * MCCC d'un EC : bascule la collection d'épreuves selon le type sélectionné
 * (les types sans épreuves à pondérer n'affichent rien de plus), ajoute/retire
 * une ligne d'épreuve, et affiche la somme des coefficients en direct.
 * Un simple total côté client, pas un mirroir du moteur de règles — les
 * résultats des règles restent calculés côté serveur, à l'affichage du panneau.
 */
export default class extends Controller {
    static targets = ['typeRadio', 'collection', 'collectionLabel', 'rows', 'row', 'weightInput', 'rowTemplate', 'sum'];

    connect() {
        this.rowSeq = 0;
        this.sync();
    }

    sync() {
        const checked = this.typeRadioTargets.find((r) => r.checked);
        const hasEvaluations = checked ? checked.dataset.hasEvaluations === '1' : false;

        if (this.hasCollectionTarget) {
            this.collectionTarget.hidden = !hasEvaluations;
        }
        if (this.hasCollectionLabelTarget && checked?.dataset.evaluationsLabel) {
            this.collectionLabelTarget.textContent = checked.dataset.evaluationsLabel;
        }
        this.updateSum();
    }

    addRow() {
        if (!this.hasRowTemplateTarget) return;
        const html = this.rowTemplateTarget.innerHTML.replaceAll('__INDEX__', `new${Date.now()}${this.rowSeq++}`);
        this.rowsTarget.insertAdjacentHTML('beforeend', html);
        this.updateSum();
    }

    removeRow(event) {
        event.target.closest('[data-mccc-target="row"]')?.remove();
        this.updateSum();
    }

    updateSum() {
        if (!this.hasSumTarget) return;
        const total = this.weightInputTargets.reduce(
            (sum, input) => sum + (parseFloat(input.value.replace(',', '.')) || 0),
            0,
        );
        this.sumTarget.textContent = Math.round(total * 10) / 10;
    }
}
