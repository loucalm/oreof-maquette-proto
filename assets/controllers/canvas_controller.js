import { Controller } from '@hotwired/stimulus';

/**
 * Canevas d'une fiche (formation, parcours) : ajout d'une entrée depuis le
 * prototype (`__name__` remplacé par un index neuf), retrait, et déplacement
 * d'une entrée vers le haut / le bas. Les entrées se déplacent comme nœuds du
 * DOM : leur rang est porté par le champ `position`, renuméroté à chaque
 * changement (`renumber()`), l'ordre des champs soumis n'a donc pas d'importance.
 */
export default class extends Controller {
    static targets = ['list'];
    static values = { prototype: String, index: Number };

    connect() {
        this.renumber();
    }

    add() {
        const html = this.prototypeValue.replaceAll('__name__', String(this.indexValue));
        this.indexValue += 1;
        this.listTarget.insertAdjacentHTML('beforeend', html);
        this.renumber();
    }

    remove(event) {
        event.currentTarget.closest('[data-canvas-row]')?.remove();
        this.renumber();
    }

    up(event) {
        const row = event.currentTarget.closest('[data-canvas-row]');
        const previous = row?.previousElementSibling;
        if (!row || !previous?.matches('[data-canvas-row]')) return;
        this.listTarget.insertBefore(row, previous);
        this.renumber();
    }

    down(event) {
        const row = event.currentTarget.closest('[data-canvas-row]');
        const next = row?.nextElementSibling;
        if (!row || !next?.matches('[data-canvas-row]')) return;
        this.listTarget.insertBefore(next, row);
        this.renumber();
    }

    renumber() {
        this.listTarget.querySelectorAll('[data-canvas-row]').forEach((row, index) => {
            const position = row.querySelector('[data-canvas-position]');
            if (position) position.value = String(index);
        });
    }
}
