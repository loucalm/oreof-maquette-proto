import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/**
 * Éditeur du référentiel de compétences (BCC) :
 *  - glisser-déposer des blocs entre eux et des compétences (y compris d'un
 *    bloc à l'autre) ;
 *  - édition en ligne : le crayon d'un bloc / d'une compétence déplie son
 *    formulaire (libellé du bloc ou description de la compétence ; le code est calculé) + son bouton de suppression.
 */
export default class extends Controller {
    static values = { moveUrl: String };
    static targets = ['blocList', 'compList', 'edit'];

    connect() {
        this.sortables = [];

        if (this.hasBlocListTarget) {
            this.sortables.push(new Sortable(this.blocListTarget, {
                group: 'bcc-blocs',
                handle: '.drag-handle',
                draggable: '.bcc-bloc:not(.bcc-bloc--transversal)',
                animation: 150,
                ghostClass: 'sortable-ghost',
                // le bloc transversal (RNCP) reste en tête : rien ne se dépose avant lui
                onMove: (evt) => !evt.related.classList.contains('bcc-bloc--transversal'),
                onEnd: (evt) => this.persist(evt, null),
            }));
        }

        this.compListTargets.forEach((list) => {
            this.sortables.push(new Sortable(list, {
                group: 'bcc-competences',
                handle: '.drag-handle',
                draggable: '.bcc-comp',
                animation: 150,
                ghostClass: 'sortable-ghost',
                fallbackOnBody: true,
                onEnd: (evt) => this.persist(evt, evt.to.dataset.parentId || null),
            }));
        });
    }

    disconnect() {
        (this.sortables || []).forEach((s) => s.destroy());
        this.sortables = [];
    }

    // ─── édition en ligne ───

    toggle(event) {
        const id = String(event.params.id);
        const holder = this.element.querySelector(`[data-bcc-node="${id}"]`);
        if (!holder) return;
        const forms = holder.querySelectorAll('[data-bcc-target="edit"]');
        const show = forms[0]?.hidden;
        forms.forEach((f) => { f.hidden = !show; });
        if (show) holder.querySelector('input[name="label"], textarea[name="description"]')?.focus();
    }

    cancel(event) {
        const holder = event.target.closest('[data-bcc-node]');
        holder?.querySelectorAll('[data-bcc-target="edit"]').forEach((f) => { f.hidden = true; });
    }

    // ─── déplacement ───

    async persist(evt, parentId) {
        if (evt.from === evt.to && evt.oldIndex === evt.newIndex) return;

        const nodeId = evt.item.dataset.nodeId;
        // l'index se compte hors bloc transversal : il est épinglé en tête et ne participe pas à l'ordre
        const index = Array.from(evt.to.children)
            .filter((el) => !el.classList.contains('bcc-bloc--transversal'))
            .indexOf(evt.item);

        try {
            const res = await fetch(this.moveUrlValue.replace('__ID__', nodeId), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ parentId, index }),
            });
            if (!res.ok) {
                evt.from.insertBefore(evt.item, evt.from.children[evt.oldIndex] || null);
                return;
            }
        } catch (e) {
            console.error(e);
            return;
        }
        if (window.Turbo) window.Turbo.visit(window.location.href, { action: 'replace' });
        else window.location.reload();
    }
}
