import { Controller } from '@hotwired/stimulus';

/**
 * Menu déroulant (popover) : bouton [data-action="menu#toggle"] + panneau
 * [data-menu-target="menu"]. Se ferme au clic extérieur et sur Échap.
 *
 * Chaque menu (un par ligne « Actions », le sélecteur de rôle, …) a sa propre
 * instance de ce contrôleur, sans lien entre elles — `toggle()` ne connaissait
 * donc que SON propre panneau, et son `event.stopPropagation()` (nécessaire
 * pour ne pas se refermer aussitôt : voir show()) empêchait en prime le clic
 * d'atteindre `document`, où les AUTRES instances écoutent pour se fermer.
 * Résultat : ouvrir un 2e menu sans avoir fermé le 1er les empilait tous.
 * `openMenu` (module-level, partagé par toutes les instances) garde une
 * référence vers le seul menu ouvert à la fois et ferme l'ancien avant chaque
 * nouvelle ouverture, sans dépendre de l'ordre de propagation des clics.
 */
let openMenu = null;

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
        if (openMenu === this) openMenu = null;
        this.stopListening();
    }

    toggle(event) {
        event.stopPropagation();
        this.menuTarget.hidden ? this.show() : this.hide();
    }

    show() {
        if (openMenu && openMenu !== this) openMenu.hide();
        openMenu = this;
        this.menuTarget.hidden = false;
        document.addEventListener('click', this._onDoc);
        document.addEventListener('keydown', this._onKey);
    }

    hide() {
        if (openMenu === this) openMenu = null;
        this.menuTarget.hidden = true;
        this.stopListening();
    }

    stopListening() {
        document.removeEventListener('click', this._onDoc);
        document.removeEventListener('keydown', this._onKey);
    }
}
