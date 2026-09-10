import { Controller } from '@hotwired/stimulus';

/**
 * Aperçu JSON : coloration syntaxique légère + copie dans le presse-papier.
 * Le <code data-json-target="src"> contient déjà le JSON indenté côté serveur.
 */
export default class extends Controller {
    static targets = ['src', 'btn'];

    connect() {
        if (this.hasSrcTarget) {
            this.raw = this.srcTarget.textContent;
            this.srcTarget.innerHTML = this.highlight(this.raw);
        }
    }

    highlight(json) {
        const esc = json
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
        return esc.replace(
            /("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/g,
            (match) => {
                let cls = 'j-num';
                if (/^"/.test(match)) {
                    cls = /:$/.test(match) ? 'j-key' : 'j-str';
                } else if (/true|false/.test(match)) {
                    cls = 'j-bool';
                } else if (/null/.test(match)) {
                    cls = 'j-null';
                }
                return `<span class="${cls}">${match}</span>`;
            },
        );
    }

    async copy() {
        try {
            await navigator.clipboard.writeText(this.raw ?? this.srcTarget.textContent);
            this.flash('✓ Copié');
        } catch {
            this.flash('Copie impossible');
        }
    }

    flash(text) {
        if (!this.hasBtnTarget) return;
        const original = this.btnTarget.textContent;
        this.btnTarget.textContent = text;
        setTimeout(() => { this.btnTarget.textContent = original; }, 1400);
    }
}
