import { Controller } from '@hotwired/stimulus';

/**
 * Collection de formulaire Symfony : ajout d'une ligne depuis le prototype
 * (`__name__` remplacé par un index neuf), retrait, et déplacement d'une ligne
 * vers le haut / le bas. Symfony garde les lignes existantes dans l'ordre de
 * leur index : on échange donc les valeurs des champs entre deux lignes plutôt
 * que de déplacer les nœuds du DOM.
 */
export default class extends Controller {
    static targets = ['list'];
    static values = { prototype: String, index: Number };

    add() {
        const html = this.prototypeValue.replaceAll('__name__', String(this.indexValue));
        this.indexValue += 1;
        this.listTarget.insertAdjacentHTML('beforeend', html);
    }

    remove(event) {
        event.currentTarget.closest('[data-collection-row]')?.remove();
    }

    up(event) {
        const row = event.currentTarget.closest('[data-collection-row]');
        this._swap(row, row?.previousElementSibling);
    }

    down(event) {
        const row = event.currentTarget.closest('[data-collection-row]');
        this._swap(row, row?.nextElementSibling);
    }

    _swap(a, b) {
        if (!a || !b || !b.matches('[data-collection-row]')) return;
        const fieldsA = [...a.querySelectorAll('input, select, textarea')];
        const fieldsB = [...b.querySelectorAll('input, select, textarea')];
        fieldsA.forEach((fa, i) => {
            const fb = fieldsB[i];
            if (!fb) return;
            if (fa.type === 'checkbox' || fa.type === 'radio') {
                [fa.checked, fb.checked] = [fb.checked, fa.checked];
            } else {
                [fa.value, fb.value] = [fb.value, fa.value];
            }
            fa.dispatchEvent(new Event('change', { bubbles: true }));
            fb.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }
}
