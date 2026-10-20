import { Controller } from '@hotwired/stimulus';
import { showGuardRefusal } from '../guard_refusal.js';

/**
 * Grille de saisie rapide (type tableur) :
 * - enregistrement différé par ligne (600 ms) + envoi immédiat sur blur/change
 * - navigation clavier (flèches, Enter) entre inputs actifs
 * - mise à jour des totaux et valeurs de ligne via la réponse JSON
 */
export default class extends Controller {
    static targets = ['row', 'input', 'status'];

    connect() {
        this._rowTimers = new Map();
        this._rowControllers = new Map();
        this._allInputs = [];

        this._onInput = this._onInput.bind(this);
        this._onBlur = this._onBlur.bind(this);
        this._onChange = this._onChange.bind(this);
        this._onKeydown = this._onKeydown.bind(this);

        this.element.addEventListener('input', this._onInput);
        this.element.addEventListener('blur', this._onBlur, true);
        this.element.addEventListener('change', this._onChange);
        this.element.addEventListener('keydown', this._onKeydown);

        this._refreshInputs();
    }

    disconnect() {
        this.element.removeEventListener('input', this._onInput);
        this.element.removeEventListener('blur', this._onBlur, true);
        this.element.removeEventListener('change', this._onChange);
        this.element.removeEventListener('keydown', this._onKeydown);

        this._rowTimers.forEach((timer) => clearTimeout(timer));
        this._rowTimers.clear();

        this._rowControllers.forEach((controller) => controller.abort());
        this._rowControllers.clear();
    }

    _refreshInputs() {
        this._allInputs = Array.from(this.inputTargets).filter((input) => !input.disabled && !input.readOnly);
    }

    _getRow(input) {
        return input.closest('[data-quick-grid-target="row"]');
    }

    _getActiveInputsInRow(row) {
        return Array.from(row.querySelectorAll('[data-quick-grid-target="input"]')).filter(
            (input) => !input.disabled && !input.readOnly
        );
    }

    _getStatusElement(row) {
        return row.querySelector('[data-quick-grid-target="status"]');
    }

    _onInput(event) {
        const input = event.target;
        if (!input.matches('[data-quick-grid-target="input"]') || input.disabled || input.readOnly) {
            return;
        }

        const row = this._getRow(input);
        if (!row) return;

        const existingTimer = this._rowTimers.get(row);
        if (existingTimer) {
            clearTimeout(existingTimer);
        }

        const timer = setTimeout(() => this._saveRow(row), 600);
        this._rowTimers.set(row, timer);
    }

    _onBlur(event) {
        const input = event.target;
        if (!input.matches('[data-quick-grid-target="input"]') || input.disabled || input.readOnly) {
            return;
        }

        const row = this._getRow(input);
        if (!row) return;

        this._flushRowTimer(row);
    }

    _onChange(event) {
        const input = event.target;
        if (!input.matches('[data-quick-grid-target="input"]') || input.disabled || input.readOnly) {
            return;
        }

        const row = this._getRow(input);
        if (!row) return;

        this._flushRowTimer(row);
    }

    _flushRowTimer(row) {
        const timer = this._rowTimers.get(row);
        if (timer) {
            clearTimeout(timer);
            this._rowTimers.delete(row);
            this._saveRow(row);
        }
    }

    _saveRow(row) {
        const url = row.dataset.url;
        if (!url) return;

        const inputs = this._getActiveInputsInRow(row);
        if (inputs.length === 0) return;

        const formData = new FormData();
        inputs.forEach((input) => {
            if (input.name) {
                formData.append(input.name, input.value);
            }
        });

        const controller = new AbortController();
        const existingController = this._rowControllers.get(row);
        if (existingController) {
            existingController.abort();
        }
        this._rowControllers.set(row, controller);

        const status = this._getStatusElement(row);
        if (status) {
            status.dataset.state = 'saving';
        }

        fetch(url, {
            method: 'POST',
            body: formData,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok) {
                    // refus du garde de structure : toast avec le motif et le lien de dérogation
                    const shown = await showGuardRefusal(response);
                    const data = shown ? await response.json() : null;
                    throw new Error(data?.message || `HTTP ${response.status}`);
                }
                return response.json();
            })
            .then((data) => {
                if (data.ok) {
                    this._applyResponse(row, data);
                    if (status) {
                        status.dataset.state = 'saved';
                    }
                } else {
                    throw new Error(data.error || 'Erreur inconnue');
                }
            })
            .catch((error) => {
                if (error.name === 'AbortError') {
                    return;
                }
                if (status) {
                    status.dataset.state = 'error';
                    status.title = error.message;
                }
            })
            .finally(() => {
                this._rowControllers.delete(row);
            });
    }

    _applyResponse(row, data) {
        if (data.totals) {
            Object.entries(data.totals).forEach(([key, value]) => {
                const elements = this.element.querySelectorAll(`[data-total="${key}"]`);
                elements.forEach((el) => this._setTotalElement(el, value));
            });
        }

        if (data.row) {
            Object.entries(data.row).forEach(([key, value]) => {
                const elements = row.querySelectorAll(`[data-total="${key}"]`);
                elements.forEach((el) => this._setTotalElement(el, value));
            });
        }
    }

    _setTotalElement(element, value) {
        if (value && typeof value === 'object' && 'text' in value) {
            element.textContent = value.text;
            if ('state' in value) {
                element.dataset.state = value.state;
            }
        } else {
            element.textContent = value;
        }
    }

    _onKeydown(event) {
        if (event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }

        const input = event.target;
        if (!input.matches('[data-quick-grid-target="input"]') || input.disabled || input.readOnly) {
            return;
        }

        const row = this._getRow(input);
        if (!row) return;

        let targetInput = null;

        switch (event.key) {
            case 'Enter':
            case 'ArrowDown':
                targetInput = this._findVerticalNeighbor(row, input, 1);
                break;
            case 'ArrowUp':
                targetInput = this._findVerticalNeighbor(row, input, -1);
                break;
            case 'ArrowRight':
                targetInput = this._findHorizontalNeighbor(input, 1);
                break;
            case 'ArrowLeft':
                targetInput = this._findHorizontalNeighbor(input, -1);
                break;
        }

        if (targetInput) {
            event.preventDefault();
            targetInput.focus();
            if (typeof targetInput.select === 'function') targetInput.select();
        }
    }

    _findVerticalNeighbor(currentRow, currentInput, direction) {
        const col = currentInput.dataset.col;
        if (!col) return null;

        const rows = this.rowTargets;
        const currentIndex = rows.indexOf(currentRow);
        if (currentIndex === -1) return null;

        for (let i = currentIndex + direction; i >= 0 && i < rows.length; i += direction) {
            const row = rows[i];
            const inputs = this._getActiveInputsInRow(row);
            const target = inputs.find((input) => input.dataset.col === col);
            if (target) {
                return target;
            }
        }
        return null;
    }

    _findHorizontalNeighbor(currentInput, direction) {
        const inputs = this._allInputs;
        const currentIndex = inputs.indexOf(currentInput);
        if (currentIndex === -1) return null;

        const targetIndex = currentIndex + direction;
        if (targetIndex >= 0 && targetIndex < inputs.length) {
            return inputs[targetIndex];
        }
        return null;
    }
}
