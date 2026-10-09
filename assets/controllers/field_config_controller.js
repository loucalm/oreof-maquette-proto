import { Controller } from '@hotwired/stimulus';

/**
 * Éditeur de champ (types d'ELP) : n'affiche que les réglages qui ont un sens
 * pour le type choisi. La liste vient du registre des types côté serveur
 * (App\FieldType\FieldTypeRegistry::editorConfig()) — ajouter un type dans le
 * code suffit, ce contrôleur n'a rien à connaître.
 */
export default class extends Controller {
    static targets = ['type', 'setting', 'bounds', 'minLabel', 'maxLabel', 'boundsHelp'];
    static values = { types: Object, boundsLabels: Object };

    connect() {
        this.update();
    }

    update() {
        const config = this.typesValue[this.typeTarget.value] ?? { settings: [], bounds: null };
        this.settingTargets.forEach((el) => { el.hidden = !config.settings.includes(el.dataset.setting); });

        const labels = config.bounds ? this.boundsLabelsValue[config.bounds] : null;
        this.boundsTarget.hidden = !labels;
        if (labels) {
            [this.minLabelTarget.textContent, this.maxLabelTarget.textContent, this.boundsHelpTarget.textContent] = labels;
        }
    }
}
