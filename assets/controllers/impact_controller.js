import { Controller } from '@hotwired/stimulus';

/**
 * Confirmation d'impact : un formulaire de la bibliothèque utilisé par des
 * diplômes ne se modifie qu'avec l'accord explicite de l'utilisateur.
 *
 * La case « Je confirme… » (`[data-impact-confirm]`) de la page vaut accord
 * pour toutes les modifications envoyées depuis cette page : à la soumission,
 * on ajoute alors `confirmImpact=1` au formulaire soumis (une seule fois).
 */
export default class extends Controller {
    connect() {
        this.element.addEventListener('submit', this.onSubmit, true);
    }

    disconnect() {
        this.element.removeEventListener('submit', this.onSubmit, true);
    }

    onSubmit = (event) => {
        const box = this.element.querySelector('[data-impact-confirm]');
        if (!box || !box.checked) return;

        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (form.querySelector('input[name="confirmImpact"]')) return;

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'confirmImpact';
        input.value = '1';
        form.appendChild(input);
    };
}
