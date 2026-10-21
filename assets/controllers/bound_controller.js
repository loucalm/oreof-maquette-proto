import { Controller } from '@hotwired/stimulus';

/**
 * Borne compacte (BoundType) : le nombre n'apparaît que si un mode est choisi, la borne
 * haute seulement pour « entre ». Les champs masqués restent dans le formulaire.
 */
export default class extends Controller {
    static targets = ['mode', 'value', 'upper'];

    connect() {
        this.update();
    }

    update() {
        const mode = this.modeTarget.value;
        this.valueTargets.forEach((el) => { el.hidden = mode === 'none'; });
        this.upperTargets.forEach((el) => { el.hidden = mode !== 'between'; });
    }
}
