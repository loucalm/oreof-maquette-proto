import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/**
 * Tableau « Configuration de la structure » : réordonne le squelette (chaîne
 * de types) par glisser-déposer, puis enregistre la nouvelle liste.
 */
export default class extends Controller {
    static values = { saveUrl: String };

    connect() {
        this.sortable = new Sortable(this.element, {
            handle: '.drag-handle',
            draggable: '[data-chain-key]',
            animation: 150,
            forceFallback: true,
            fallbackOnBody: true,
            ghostClass: 'sortable-ghost',
            onEnd: () => this.save(),
        });
    }

    disconnect() {
        this.sortable?.destroy();
        this.sortable = null;
    }

    async save() {
        const keys = [...this.element.querySelectorAll('[data-chain-key]')].map((r) => r.dataset.chainKey);
        const body = new URLSearchParams();
        keys.forEach((k) => body.append('chain[]', k));

        try {
            await fetch(this.saveUrlValue, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body,
            });
        } catch (e) {
            console.error(e);
        }

        if (window.Turbo) window.Turbo.visit(window.location.href, { action: 'replace' });
        else window.location.reload();
    }
}
