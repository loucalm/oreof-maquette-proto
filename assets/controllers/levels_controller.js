import { Controller } from '@hotwired/stimulus';

/**
 * Niveaux de la chaîne (étape Structure) : chaque niveau est un bloc qui porte ses éléments
 * imposés. Les blocs se déplacent dans la page et leur rang est recopié dans le champ
 * `position` ; un élément imposé prend le type du niveau qui le contient.
 */
export default class extends Controller {
    static targets = ['list'];
    static values = { levelPrototype: String, levelIndex: Number, imposedPrototype: String, imposedIndex: Number };

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

    toggleImposed(event) {
        const panel = this.block(event)?.querySelector('[data-imposed-panel]');
        if (panel) panel.hidden = !panel.hidden;
    }

    addImposed(event) {
        const block = this.block(event);
        if (!block) return;
        const list = block.querySelector('[data-imposed-list]');
        list.insertAdjacentHTML('beforeend', this.imposedPrototypeValue.replaceAll('__name__', String(this.imposedIndexValue)));
        this.imposedIndexValue += 1;
        block.querySelector('[data-imposed-panel]').hidden = false;
        this.syncBlock(block);
    }

    removeImposed(event) {
        const block = this.block(event);
        event.currentTarget.closest('[data-imposed-row]')?.remove();
        if (block) this.syncBlock(block);
    }

    /** Le type d'un niveau change : ses éléments imposés le suivent. */
    syncType(event) {
        const block = this.block(event);
        if (block) this.syncBlock(block);
    }

    syncBlock(block) {
        const type = block.querySelector('[data-level-type]')?.value ?? '';
        block.querySelectorAll('[data-imposed-type]').forEach((input) => { input.value = type; });
        const count = block.querySelectorAll('[data-imposed-row]').length;
        block.querySelectorAll('[data-imposed-count]').forEach((el) => { el.textContent = String(count); });
    }

    renumber() {
        this.listTarget.querySelectorAll(':scope > [data-level-block]').forEach((block, index) => {
            const position = block.querySelector('[data-levels-target="position"]');
            if (position) position.value = String(index);
            this.syncBlock(block);
        });
    }

    block(event) {
        return event.currentTarget.closest('[data-level-block]');
    }
}
