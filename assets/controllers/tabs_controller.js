import { Controller } from '@hotwired/stimulus';

/**
 * Onglets : boutons [data-tabs-target=tab] / panneaux [data-tabs-target=panel],
 * appariés par data-tab. Le panneau spécial "__params" (Paramètre du nœud) est
 * masqué par défaut et affiché via toParams().
 */
export default class extends Controller {
    static targets = ['tab', 'panel'];

    connect() {
        const first = this.tabTargets[0];
        this.activate(first ? first.dataset.tab : 'props');
        this.tabTargets.forEach((t) => t.addEventListener('click', () => this.activate(t.dataset.tab)));
    }

    activate(name) {
        this.tabTargets.forEach((t) => t.classList.toggle('tab-active', t.dataset.tab === name));
        this.panelTargets.forEach((p) => {
            const isParams = p.dataset.tab === '__params';
            p.hidden = isParams ? true : p.dataset.tab !== name;
        });
    }

    toParams() {
        this.tabTargets.forEach((t) => t.classList.remove('tab-active'));
        this.panelTargets.forEach((p) => { p.hidden = p.dataset.tab !== '__params'; });
    }
}
