import { Controller } from '@hotwired/stimulus';

/**
 * Éditeur de champ (types d'ELP) : le sens de « minimum / maximum » dépend du
 * type de saisie — valeur pour un nombre, longueur pour un texte. Pour les
 * autres types, les bornes n'ont pas d'effet : le bloc est masqué.
 */
export default class extends Controller {
    static targets = ['type', 'bounds', 'minLabel', 'maxLabel', 'help'];
    static values = { labels: Object };

    connect() {
        this.update();
    }

    update() {
        const labels = this.labelsValue[this.typeTarget.value];
        this.boundsTarget.hidden = !labels;
        if (!labels) return;
        [this.minLabelTarget.textContent, this.maxLabelTarget.textContent, this.helpTarget.textContent] = labels;
    }
}
