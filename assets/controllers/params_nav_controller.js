import { Controller } from '@hotwired/stimulus';

/**
 * Select « Aller aux paramètres de… » dans la modale : recharge le frame
 * des paramètres sur un autre nœud sans fermer la modale.
 */
export default class extends Controller {
    go(event) {
        const url = event.target.value;
        const frame = document.getElementById('node-params-frame');
        if (url && frame) frame.src = url;
    }
}
