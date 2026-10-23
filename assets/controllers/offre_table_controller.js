import { Controller } from '@hotwired/stimulus';

/**
 * Tableau de la gestion de l'offre : replier une composante (tbody[data-offre-table-target=group]),
 * déplier les années d'un parcours (lignes [data-annee-of=<id>], masquées par défaut), tout
 * déplier / replier, et filtres envoyés à la saisie.
 */
export default class extends Controller {
    static targets = ['group', 'form'];

    toggleGroup(event) {
        const group = event.currentTarget.closest('[data-offre-table-target="group"]');
        this.setGroup(group, group.dataset.collapsed !== '1');
    }

    toggleParcours(event) {
        const button = event.currentTarget;
        this.setParcours(button, button.getAttribute('aria-expanded') !== 'true');
    }

    expandGroups() { this.groupTargets.forEach((g) => this.setGroup(g, false)); }
    collapseGroups() { this.groupTargets.forEach((g) => this.setGroup(g, true)); }
    expandParcours() { this.parcoursButtons().forEach((b) => this.setParcours(b, true)); }
    collapseParcours() { this.parcoursButtons().forEach((b) => this.setParcours(b, false)); }

    submit() { this.formTarget.requestSubmit(); }

    debouncedSubmit() {
        clearTimeout(this._timer);
        this._timer = setTimeout(() => this.submit(), 350);
    }

    setGroup(group, collapsed) {
        group.dataset.collapsed = collapsed ? '1' : '0';
        group.querySelector('[data-action~="offre-table#toggleGroup"]')?.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        group.querySelectorAll('tr:not([data-group-head])').forEach((row) => {
            // une ligne d'année ne réapparaît que si son parcours est déplié
            const of = row.dataset.anneeOf;
            const parcoursOpen = !of || group.querySelector(`[data-parcours-toggle="${of}"]`)?.getAttribute('aria-expanded') === 'true';
            row.hidden = collapsed || !parcoursOpen;
        });
    }

    setParcours(button, open) {
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        const group = button.closest('[data-offre-table-target="group"]');
        const collapsed = group?.dataset.collapsed === '1';
        this.element.querySelectorAll(`[data-annee-of="${button.dataset.parcoursToggle}"]`).forEach((row) => { row.hidden = !open || collapsed; });
    }

    parcoursButtons() { return this.element.querySelectorAll('[data-parcours-toggle]'); }
}
