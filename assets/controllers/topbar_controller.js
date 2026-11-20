import { Controller } from '@hotwired/stimulus';

/**
 * Topbar sur petit écran (< md) : le bouton « Menu » déplie la ligne de navigation, masquée par défaut
 * (sur grand écran elle est toujours affichée, comme dans ORéOF v2).
 */
export default class extends Controller {
    static targets = ['menu', 'button'];

    toggle() {
        const open = !this.element.classList.contains('is-open');
        this.element.classList.toggle('is-open', open);
        this.buttonTarget.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
}
