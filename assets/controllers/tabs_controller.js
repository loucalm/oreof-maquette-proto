import { Controller } from '@hotwired/stimulus';

/** Onglets simples : boutons [data-tabs-target=tab] / panneaux [data-tabs-target=panel], appariés par data-tab. */
export default class extends Controller {
    static targets = ['tab', 'panel'];

    connect() {
        if (this.tabTargets.length) {
            this.activate(this.tabTargets[0].dataset.tab);
        }
        this.tabTargets.forEach((t) => t.addEventListener('click', () => this.activate(t.dataset.tab)));
    }

    activate(name) {
        this.tabTargets.forEach((t) => t.classList.toggle('tab-active', t.dataset.tab === name));
        this.panelTargets.forEach((p) => { p.hidden = p.dataset.tab !== name; });
    }
}
