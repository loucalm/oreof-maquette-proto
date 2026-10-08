import { Controller } from '@hotwired/stimulus';

/**
 * Onglets : boutons [data-tabs-target=tab] / panneaux [data-tabs-target=panel],
 * appariés par data-tab. Si l'élément porte data-tabs-key-value (ex. l'id du
 * nœud), l'onglet actif est mémorisé en sessionStorage : il survit à
 * l'enregistrement (rafraîchissement de la page) et au retour sur ce nœud.
 */
export default class extends Controller {
    static targets = ['tab', 'panel'];
    static values = { key: String };

    connect() {
        this.restore();
        // rafraîchissement Turbo par morphing : le DOM revient à l'état serveur, on réapplique l'onglet
        // (page entière puis frame : l'élément lui-même est « morphé » en dernier)
        this._onMorph = (e) => { if (e.target === this.element) this.restore(); };
        this.element.addEventListener('turbo:morph-element', this._onMorph);
        this._onClick = (e) => {
            const tab = e.target.closest('[data-tabs-target="tab"]');
            if (tab && this.element.contains(tab)) {
                this.activate(tab.dataset.tab);
                this.write(tab.dataset.tab);
            }
        };
        this.element.addEventListener('click', this._onClick);
    }

    disconnect() {
        this.element.removeEventListener('click', this._onClick);
        this.element.removeEventListener('turbo:morph-element', this._onMorph);
    }

    restore() {
        const first = this.tabTargets[0];
        const stored = this.read();
        const exists = stored && this.tabTargets.some((t) => t.dataset.tab === stored);
        this.activate(exists ? stored : (first ? first.dataset.tab : 'props'));
    }

    activate(name) {
        this.tabTargets.forEach((t) => {
            const on = t.dataset.tab === name;
            t.classList.toggle('tab-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        this.panelTargets.forEach((p) => { p.hidden = p.dataset.tab !== name; });
    }

    /**
     * Ouvre l'onglet `tab` et place le curseur dans le champ `field` (clé
     * d'attribut : attr_<clé>, ou label / code). Paramètres d'action Stimulus.
     */
    focusField({ params: { tab, field } }) {
        if (tab) {
            this.activate(tab);
            this.write(tab);
        }
        const el = this.element.querySelector(
            `[name="${field}"], [name="attr_${field}"], [name^="attr_${field}["]`,
        );
        if (!el) return;
        el.closest('details')?.setAttribute('open', '');
        el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        el.focus({ preventScroll: true });
        const box = el.closest('.fld, [data-controller], label') || el;
        box.classList.add('field-flash');
        setTimeout(() => box.classList.remove('field-flash'), 1500);
    }

    read() {
        if (!this.keyValue) return null;
        try { return sessionStorage.getItem(`tabs:${this.keyValue}`); } catch { return null; }
    }

    write(name) {
        if (!this.keyValue) return;
        try { sessionStorage.setItem(`tabs:${this.keyValue}`, name); } catch { /* stockage indisponible */ }
    }
}
