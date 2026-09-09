import { Controller } from '@hotwired/stimulus';

/**
 * Toast « message flash » : se referme seul après un délai, ou au clic sur ✕.
 * Le conteneur .flash-stack est en position:fixed (cf. app.css) — le message
 * reste visible quel que soit le défilement de la page.
 */
export default class extends Controller {
    static values = { delay: { type: Number, default: 4500 } };

    connect() {
        if (this.delayValue > 0) {
            this.timer = setTimeout(() => this.dismiss(), this.delayValue);
        }
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    dismiss() {
        clearTimeout(this.timer);
        this.element.classList.add('flash-leaving');
        this.element.addEventListener('animationend', () => this.element.remove(), { once: true });
        setTimeout(() => this.element.remove(), 400); // filet de sécurité
    }
}
