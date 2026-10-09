import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/**
 * Éditeur des valeurs d'un référentiel : lignes ajoutées/retirées et
 * réordonnées par glisser-déposer. Les champs `values_label[]` / `values_code[]`
 * n'ont pas d'index : c'est l'ordre du DOM qui fait foi à l'enregistrement.
 */
export default class extends Controller {
    static targets = ['list', 'template', 'bulk'];

    connect() {
        this.sortable = new Sortable(this.listTarget, {
            handle: '.drag-handle',
            draggable: '[data-values-row]',
            animation: 150,
        });
    }

    disconnect() {
        this.sortable?.destroy();
        this.sortable = null;
    }

    add() {
        const row = this._appendRow('', '');
        row.querySelector('[name="values_label[]"]')?.focus();
    }

    remove(event) {
        event.target.closest('[data-values-row]')?.remove();
    }

    importBulk() {
        for (const raw of this.bulkTarget.value.split(/\r?\n/)) {
            const line = raw.trim();
            if (line === '') continue;

            const [code, label] = line.includes('|')
                ? line.split('|', 2).map((part) => part.trim())
                : ['', line];
            this._appendRow(label, code);
        }

        this.bulkTarget.value = '';
    }

    /** Ajoute une ligne (à partir du `<template>`) et renvoie son élément racine. */
    _appendRow(label, code) {
        this.listTarget.append(this.templateTarget.content.cloneNode(true));
        const row = this.listTarget.lastElementChild;
        row.querySelector('[name="values_label[]"]').value = label;
        row.querySelector('[name="values_code[]"]').value = code;

        return row;
    }
}
