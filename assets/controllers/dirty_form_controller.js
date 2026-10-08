import { Controller } from '@hotwired/stimulus';

/**
 * Détecte les modifications non enregistrées dans un formulaire et empêche
 * la navigation accidentelle (changement de nœud, rechargement, fermeture).
 */
export default class extends Controller {
    static values = {
        message: { type: String, default: 'Des modifications ne sont pas enregistrées. Les abandonner ?' }
    };

    static targets = ['indicator'];

    connect() {
        this._initialData = this._serializeForm();
        this._dirty = false;

        this._onInput = this._checkDirty.bind(this);
        this._onSubmit = this._onSubmit.bind(this);
        this._onBeforeUnload = this._onBeforeUnload.bind(this);
        this._onTurboBeforeVisit = this._onTurboBeforeVisit.bind(this);
        this._onClickCapture = this._onClickCapture.bind(this);

        this.element.addEventListener('input', this._onInput);
        this.element.addEventListener('change', this._onInput);
        this.element.addEventListener('submit', this._onSubmit);
        // après enregistrement, Turbo « morphe » le formulaire : son état devient la nouvelle référence
        this._onMorph = (e) => { if (e.target === this.element) this.reset(); };
        this.element.addEventListener('turbo:morph-element', this._onMorph);

        window.addEventListener('beforeunload', this._onBeforeUnload);
        document.addEventListener('turbo:before-visit', this._onTurboBeforeVisit);
        document.addEventListener('click', this._onClickCapture, true);
        // autres formulaires (ajout rapide au référentiel, monter/descendre, dupliquer…) : ils remplacent le panneau
        this._onOtherSubmit = this._onOtherSubmit.bind(this);
        document.addEventListener('submit', this._onOtherSubmit, true);
    }

    disconnect() {
        this.element.removeEventListener('input', this._onInput);
        this.element.removeEventListener('change', this._onInput);
        this.element.removeEventListener('submit', this._onSubmit);
        this.element.removeEventListener('turbo:morph-element', this._onMorph);

        window.removeEventListener('beforeunload', this._onBeforeUnload);
        document.removeEventListener('turbo:before-visit', this._onTurboBeforeVisit);
        document.removeEventListener('click', this._onClickCapture, true);
        document.removeEventListener('submit', this._onOtherSubmit, true);
    }

    /**
     * Reprend l'état courant du formulaire comme nouvelle référence (non modifié).
     */
    reset() {
        this._initialData = this._serializeForm();
        this._setDirty(false);
    }

    _serializeForm() {
        return new URLSearchParams(new FormData(this.element)).toString();
    }

    _checkDirty() {
        const current = this._serializeForm();
        this._setDirty(current !== this._initialData);
    }

    _setDirty(dirty) {
        if (this._dirty === dirty) return;
        this._dirty = dirty;
        this.element.classList.toggle('is-dirty', dirty);
        if (this.hasIndicatorTarget) {
            this.indicatorTarget.hidden = !dirty;
        }
    }

    _onSubmit() {
        this._setDirty(false);
    }

    _onBeforeUnload(event) {
        if (!this._dirty) return;
        event.preventDefault();
        event.returnValue = '';
    }

    _onTurboBeforeVisit(event) {
        if (!this._dirty) return;
        if (!confirm(this.messageValue)) {
            event.preventDefault();
        }
    }

    _onClickCapture(event) {
        if (!this._dirty) return;

        const link = event.target.closest('a[href]');
        if (!link) return;

        // Ignorer les liens à l'intérieur du formulaire
        if (this.element.contains(link)) return;

        const turboFrame = link.dataset.turboFrame;
        const isTargetedNavigation = turboFrame === 'node-panel' || turboFrame === '_top' || turboFrame === undefined;

        if (!isTargetedNavigation) return;

        if (!confirm(this.messageValue)) {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }
        // abandon accepté : ne pas redemander au turbo:before-visit qui suit
        this._setDirty(false);
    }

    _onOtherSubmit(event) {
        if (!this._dirty || event.target === this.element) return;
        if (!confirm(this.messageValue)) {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }
        this._setDirty(false);
    }
}
