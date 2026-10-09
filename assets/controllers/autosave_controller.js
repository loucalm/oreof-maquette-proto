import { Controller } from '@hotwired/stimulus';

/**
 * Enregistrement en direct d'une section « Paramètre » : chaque modification
 * est envoyée au serveur (immédiatement sur `change`, après 1500 ms sans frappe
 * pour la saisie au clavier) et le badge de statut de la navigation de gauche
 * est recalculé depuis la réponse JSON.
 *
 * Sans JS, le bouton « Enregistrer » masqué reste le repli : un POST classique
 * avec redirection.
 */
export default class extends Controller {
    static targets = ['status', 'submit'];

    static values = {
        key: String,
    };

    connect() {
        // le bouton reste le repli sans JS : on ne le masque qu'une fois le
        // contrôleur monté.
        if (this.hasSubmitTarget) {
            this.submitTarget.hidden = true;
        }

        this._timer = null;
        this._saving = false;
        this._pending = false;

        this._onChange = this._onChange.bind(this);
        this._onInput = this._onInput.bind(this);

        this.element.addEventListener('change', this._onChange);
        this.element.addEventListener('input', this._onInput);
    }

    disconnect() {
        this.element.removeEventListener('change', this._onChange);
        this.element.removeEventListener('input', this._onInput);
        this._clearTimer();
    }

    _onChange() {
        this._clearTimer();
        this._save();
    }

    _onInput(event) {
        if (!this._debounced(event.target)) {
            return;
        }
        this._clearTimer();
        this._timer = setTimeout(() => this._save(), 1500);
    }

    /** La frappe n'est débouncée que pour les champs texte/nombre et les zones de texte. */
    _debounced(el) {
        if (el instanceof HTMLTextAreaElement) {
            return true;
        }

        return el instanceof HTMLInputElement && (el.type === 'text' || el.type === 'number');
    }

    _clearTimer() {
        if (this._timer !== null) {
            clearTimeout(this._timer);
            this._timer = null;
        }
    }

    async _save() {
        // un seul enregistrement à la fois : la demande suivante attend la fin.
        if (this._saving) {
            this._pending = true;

            return;
        }
        this._saving = true;
        this._setStatus('Enregistrement…');

        try {
            const response = await fetch(this.element.action, {
                method: 'POST',
                body: new FormData(this.element),
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (!response.ok) {
                throw new Error(String(response.status));
            }

            const data = await response.json();
            this._setStatus(`Enregistré à ${this._time()}`);
            this._applyStatus(data.status);
            this._applyIssues(data.issues ?? {});
        } catch {
            this._setStatus("Échec de l'enregistrement — réessayez");
        } finally {
            this._saving = false;
            if (this._pending) {
                this._pending = false;
                this._save();
            }
        }
    }

    _setStatus(text) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = text;
        }
    }

    _time() {
        const now = new Date();
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');

        return `${hours}:${minutes}`;
    }

    /** Erreurs par champ (règles de l'onglet, bornes) : remplace le contenu de chaque `[data-field-errors]` du formulaire. */
    _applyIssues(issues) {
        this.element.querySelectorAll('[data-field-errors]').forEach((box) => {
            const messages = issues[box.dataset.fieldErrors] ?? [];
            box.replaceChildren(...messages.map((text) => {
                const line = document.createElement('div');
                line.textContent = `⚠ ${text}`;

                return line;
            }));
        });
    }

    _applyStatus(status) {
        if (typeof status !== 'string') {
            return;
        }
        document.querySelectorAll(`[data-param-status-key="${this.keyValue}"]`).forEach((dot) => {
            dot.dataset.status = status;
        });
    }
}
