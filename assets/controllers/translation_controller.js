import { Controller } from '@hotwired/stimulus';

/**
 * Édition en ligne d'un fichier de traduction : chaque ligne bascule entre
 * affichage et édition, la sauvegarde part en AJAX (une clé à la fois) sans
 * recharger la page.
 */
export default class extends Controller {
    static targets = ['tbody'];
    static values = { saveUrl: String, deleteUrl: String };

    edit(event) {
        this.enterEditMode(event.currentTarget.closest('tr'));
    }

    cancel(event) {
        const row = event.currentTarget.closest('tr');
        if (row.dataset.isNew === 'true') {
            row.remove();
            return;
        }
        row.querySelector('[data-role="value-input"]').value = row.dataset.originalValue ?? '';
        this.exitEditMode(row);
    }

    async save(event) {
        const row = event.currentTarget.closest('tr');
        const isNew = row.dataset.isNew === 'true';
        const saveBtn = row.querySelector('[data-role="save-btn"]');
        const key = isNew
            ? row.querySelector('[data-role="key-input"]').value.trim()
            : row.dataset.translationKey;
        const value = row.querySelector('[data-role="value-input"]').value;
        const saveUrl = row.dataset.saveUrl || this.saveUrlValue;

        this.clearError(row);
        if (!key) {
            this.showError(row, 'La clé ne peut pas être vide.');
            return;
        }

        saveBtn.disabled = true;
        try {
            const res = await fetch(saveUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ key, value }),
            });
            const json = await res.json();
            if (!json.success) throw new Error(json.error ?? 'Erreur inconnue.');

            row.dataset.translationKey = key;
            row.dataset.originalValue = value;
            row.dataset.isNew = 'false';
            row.querySelector('[data-role="value-display"]').textContent = value;
            if (isNew) {
                row.querySelector('[data-role="key-display"]').textContent = key;
                row.querySelector('[data-role="key-display"]').hidden = false;
                row.querySelector('[data-role="key-input"]').hidden = true;
            }
            this.exitEditMode(row);
        } catch (e) {
            this.showError(row, e.message);
        } finally {
            saveBtn.disabled = false;
        }
    }

    async remove(event) {
        const row = event.currentTarget.closest('tr');
        const key = row.dataset.translationKey;
        const deleteUrl = row.dataset.deleteUrl || this.deleteUrlValue;
        if (!key || !window.confirm(`Supprimer la clé « ${key} » ?`)) return;

        try {
            const res = await fetch(deleteUrl, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ key }),
            });
            const json = await res.json();
            if (!json.success) throw new Error(json.error ?? 'Erreur inconnue.');
            row.remove();
        } catch (e) {
            this.showError(row, e.message);
        }
    }

    addRow() {
        const row = document.createElement('tr');
        row.className = 'border-b last:border-0';
        row.style.borderColor = 'var(--c-border)';
        row.dataset.isNew = 'true';
        row.dataset.translationKey = '';
        row.dataset.originalValue = '';
        row.innerHTML = `
            <td class="px-4 py-2.5 align-top">
                <span data-role="key-display" hidden class="font-mono text-xs" style="color:var(--c-muted)"></span>
                <input data-role="key-input" type="text" placeholder="nouvelle.cle" class="fld font-mono text-xs" />
            </td>
            <td class="px-4 py-2.5 align-top">
                <span data-role="value-display" hidden class="text-sm"></span>
                <input data-role="value-input" type="text" placeholder="Valeur" class="fld text-sm" />
                <p data-role="error" hidden class="text-xs text-red-600 mt-1"></p>
            </td>
            <td class="px-4 py-2.5 align-top text-right whitespace-nowrap">
                <button type="button" data-role="save-btn" data-action="translation#save" class="text-xs text-green-700 hover:underline">Enregistrer</button>
                <button type="button" data-action="translation#cancel" class="text-xs hover:underline ml-2" style="color:var(--c-muted)">Annuler</button>
            </td>
        `;
        this.tbodyTarget.prepend(row);
        row.querySelector('[data-role="key-input"]').focus();
    }

    enterEditMode(row) {
        const display = row.querySelector('[data-role="value-display"]');
        const input = row.querySelector('[data-role="value-input"]');
        row.dataset.originalValue = display.textContent;
        input.value = display.textContent;
        display.hidden = true;
        input.hidden = false;
        row.querySelector('[data-role="view-actions"]').hidden = true;
        row.querySelector('[data-role="edit-actions"]').hidden = false;
        input.focus();
        input.select();
    }

    exitEditMode(row) {
        row.querySelector('[data-role="value-display"]').hidden = false;
        row.querySelector('[data-role="value-input"]').hidden = true;
        row.querySelector('[data-role="view-actions"]').hidden = false;
        row.querySelector('[data-role="edit-actions"]').hidden = true;
    }

    showError(row, message) {
        const err = row.querySelector('[data-role="error"]');
        if (!err) return;
        err.textContent = message;
        err.hidden = false;
    }

    clearError(row) {
        const err = row.querySelector('[data-role="error"]');
        if (err) err.hidden = true;
    }
}
