import { Controller } from '@hotwired/stimulus';

/**
 * Niveaux de la chaîne (étape Structure). Les blocs se déplacent dans la page et leur rang est
 * recopié dans le champ `position`.
 */
export default class extends Controller {
    static targets = ['list'];
    static values = { levelPrototype: String, levelIndex: Number };

    connect() {
        this.renumber();
    }

    addLevel() {
        this.listTarget.insertAdjacentHTML('beforeend', this.levelPrototypeValue.replaceAll('__name__', String(this.levelIndexValue)));
        this.levelIndexValue += 1;
        this.renumber();
    }

    removeLevel(event) {
        this.block(event)?.remove();
        this.renumber();
    }

    up(event) {
        const block = this.block(event);
        const previous = block?.previousElementSibling;
        if (previous?.matches('[data-level-block]')) this.listTarget.insertBefore(block, previous);
        this.renumber();
    }

    down(event) {
        const block = this.block(event);
        const next = block?.nextElementSibling;
        if (next?.matches('[data-level-block]')) this.listTarget.insertBefore(next, block);
        this.renumber();
    }

    /** Le type d'un niveau change : les rangs sont recopiés. */
    syncType() {
        this.renumber();
    }

    renumber() {
        const blocks = [...this.listTarget.querySelectorAll(':scope > [data-level-block]')];
        blocks.forEach((block, index) => {
            const position = block.querySelector('[data-levels-target="position"]');
            if (position) position.value = String(index);
        });
    }

    block(event) {
        return event.currentTarget.closest('[data-level-block]');
    }
}
