import { Controller } from '@hotwired/stimulus';

/**
 * Menu déroulant (popover) : bouton [data-action="menu#toggle"] + panneau
 * [data-menu-target="menu"]. Se ferme au clic extérieur et sur Échap.
 */
export default class extends Controller {
    static targets = ['menu'];

    connect() {
        this._onDoc = (e) => {
            if (!this.element.contains(e.target)) this.hide();
        };
        this._onKey = (e) => {
            if (e.key === 'Escape') this.hide();
        };
    }

    disconnect() {
        this.stopListening();
    }

    toggle(event) {
        event.stopPropagation();
        this.menuTarget.hidden ? this.show() : this.hide();
    }

    show() {
        this.menuTarget.hidden = false;
        document.addEventListener('click', this._onDoc);
        document.addEventListener('keydown', this._onKey);
    }

    hide() {
        this.menuTarget.hidden = true;
        this.stopListening();
    }

    stopListening() {
        document.removeEventListener('click', this._onDoc);
        document.removeEventListener('keydown', this._onKey);
    }
}
