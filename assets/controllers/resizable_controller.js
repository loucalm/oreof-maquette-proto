import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'oreof-proto:editor-aside-width';
const MIN_WIDTH = 260;
const MIN_MAIN = 480; // largeur minimale gardée au panneau d'édition
const STEP = 24;

/**
 * Colonne gauche de l'éditeur (arbre) redimensionnable, à la manière de l'aide
 * contextuelle d'ORéOF v2 : poignée sur le bord, largeur bornée et mémorisée par
 * navigateur. La largeur vit dans la variable CSS `--aside-w` du conteneur ; au clavier,
 * la poignée (role="separator") se règle avec ← → et se réinitialise avec Début / double-clic.
 */
export default class extends Controller {
    static targets = ['handle'];

    connect() {
        this._dragging = false;
        this._onMove = this._onMove.bind(this);
        this._onUp = this._onUp.bind(this);
        // le rafraîchissement par morphing de Turbo réécrirait l'attribut style : on le laisse tel quel
        this._onMorphAttribute = (e) => {
            if (e.target === this.element && e.detail.attributeName === 'style') e.preventDefault();
        };
        document.addEventListener('turbo:before-morph-attribute', this._onMorphAttribute);
        this._onWindowResize = () => this._apply(this._current());
        window.addEventListener('resize', this._onWindowResize);

        const saved = this._read();
        if (saved) this._apply(saved);
    }

    disconnect() {
        document.removeEventListener('turbo:before-morph-attribute', this._onMorphAttribute);
        window.removeEventListener('resize', this._onWindowResize);
        this._onUp();
    }

    start(event) {
        if (event.button !== undefined && event.button !== 0) return;
        event.preventDefault();
        this._dragging = true;
        this.element.classList.add('is-resizing');
        window.addEventListener('pointermove', this._onMove);
        window.addEventListener('pointerup', this._onUp);
    }

    key(event) {
        const w = this._current();
        const next = {
            ArrowLeft: w - STEP,
            ArrowRight: w + STEP,
        }[event.key];
        if (event.key === 'Home') return this.reset();
        if (next === undefined) return;
        event.preventDefault();
        this._apply(next, true);
    }

    reset() {
        this.element.style.removeProperty('--aside-w');
        try { localStorage.removeItem(STORAGE_KEY); } catch (e) { /* stockage indisponible */ }
        this._aria();
    }

    _onMove(event) {
        if (!this._dragging) return;
        this._apply(event.clientX - this.element.getBoundingClientRect().left);
    }

    _onUp() {
        if (!this._dragging) return;
        this._dragging = false;
        this.element.classList.remove('is-resizing');
        window.removeEventListener('pointermove', this._onMove);
        window.removeEventListener('pointerup', this._onUp);
        this._write(this._current());
    }

    _current() {
        const aside = this.element.querySelector(':scope > .editor-aside');
        return Math.round(aside ? aside.getBoundingClientRect().width : MIN_WIDTH);
    }

    _apply(width, persist = false) {
        const max = Math.max(MIN_WIDTH, this.element.clientWidth - MIN_MAIN);
        const clamped = Math.round(Math.min(max, Math.max(MIN_WIDTH, width)));
        this.element.style.setProperty('--aside-w', `${clamped}px`);
        this._aria();
        if (persist) this._write(clamped);
    }

    _aria() {
        if (!this.hasHandleTarget) return;
        const w = this._current();
        this.handleTarget.setAttribute('aria-valuenow', String(w));
        this.handleTarget.setAttribute('aria-valuemin', String(MIN_WIDTH));
        this.handleTarget.setAttribute('aria-valuemax', String(Math.max(MIN_WIDTH, this.element.clientWidth - MIN_MAIN)));
    }

    _read() {
        try {
            const v = parseInt(localStorage.getItem(STORAGE_KEY) ?? '', 10);
            return Number.isFinite(v) ? v : null;
        } catch (e) {
            return null;
        }
    }

    _write(width) {
        try { localStorage.setItem(STORAGE_KEY, String(width)); } catch (e) { /* stockage indisponible */ }
    }
}
