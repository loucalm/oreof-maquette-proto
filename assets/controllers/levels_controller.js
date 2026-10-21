import { Controller } from '@hotwired/stimulus';

/**
 * Niveaux de la chaîne (étape Structure) : chaque niveau est un bloc qui porte les éléments
 * imposés devant exister dans chacun de ses nœuds ; ils sont du type du niveau SUIVANT, et le
 * dernier niveau n'en a pas. Les blocs se déplacent dans la page et leur rang est recopié dans
 * le champ `position`.
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
        this.renumber();
    }

    removeImposed(event) {
        event.currentTarget.closest('[data-imposed-row]')?.remove();
        this.renumber();
    }

    /** Le type d'un niveau change : les éléments imposés du niveau précédent le suivent. */
    syncType() {
        this.renumber();
    }

    renumber() {
        const blocks = [...this.listTarget.querySelectorAll(':scope > [data-level-block]')];
        blocks.forEach((block, index) => {
            const position = block.querySelector('[data-levels-target="position"]');
            if (position) position.value = String(index);

            const next = blocks[index + 1]?.querySelector('[data-level-type]');
            const rows = block.querySelectorAll('[data-imposed-row]');
            block.querySelectorAll('[data-imposed-type]').forEach((input) => { input.value = next?.value ?? ''; });
            block.querySelectorAll('[data-imposed-child-label]').forEach((el) => { el.textContent = next?.selectedOptions[0]?.textContent ?? ''; });
            block.querySelectorAll('[data-imposed-children]').forEach((el) => { el.hidden = !blocks[index + 2]; });
            block.querySelectorAll('[data-imposed-count]').forEach((el) => { el.textContent = String(rows.length); });
            const toggle = block.querySelector('[data-imposed-toggle]');
            if (toggle) toggle.hidden = !next && rows.length === 0;
            const panel = block.querySelector('[data-imposed-panel]');
            if (panel && !next && rows.length === 0) panel.hidden = true;
        });
    }

    block(event) {
        return event.currentTarget.closest('[data-level-block]');
    }
}
