import { Controller } from '@hotwired/stimulus';

/**
 * Glisser-relier sur le graphe de l'arborescence des parcours : glisser d'une
 * boîte (parcours) vers une autre la désigne comme parent ; cliquer un
 * connecteur existant le retire. Dans les deux cas, on ne fait que remplir
 * les `<select>` du tableau juste en dessous — rien n'est enregistré tant que
 * le formulaire n'est pas soumis, et le serveur revalide tout (mêmes règles
 * que la saisie manuelle) : le glisser n'est qu'une autre façon de saisir.
 *
 * Les coordonnées écran sont converties en coordonnées SVG via la matrice de
 * transformation de l'élément (`getScreenCTM`) car le viewBox n'a pas la même
 * échelle que la taille CSS rendue (`min-width:100%`).
 */
export default class extends Controller {
    static targets = ['svg', 'box', 'edge', 'parentSelect', 'dragLine'];

    connect() {
        this.dragSource = null;
        this.dragMoved = false;
        this.justDragged = false;
        this._onMove = this.onMove.bind(this);
        this._onUp = this.onUp.bind(this);
    }

    disconnect() {
        window.removeEventListener('pointermove', this._onMove);
        window.removeEventListener('pointerup', this._onUp);
    }

    startDrag(event) {
        if (event.button !== undefined && event.button !== 0) return;
        this.dragSource = event.currentTarget;
        this.dragStart = { x: event.clientX, y: event.clientY };
        this.dragMoved = false;
        window.addEventListener('pointermove', this._onMove);
        window.addEventListener('pointerup', this._onUp);
    }

    onMove(event) {
        if (!this.dragSource) return;
        const dx = event.clientX - this.dragStart.x;
        const dy = event.clientY - this.dragStart.y;
        if (!this.dragMoved && Math.hypot(dx, dy) < 5) return;
        this.dragMoved = true;

        const from = this.boxCenter(this.dragSource);
        const to = this.toSvgPoint(event.clientX, event.clientY);
        if (this.hasDragLineTarget) {
            this.dragLineTarget.hidden = false;
            this.dragLineTarget.setAttribute('x1', from.x);
            this.dragLineTarget.setAttribute('y1', from.y);
            this.dragLineTarget.setAttribute('x2', to.x);
            this.dragLineTarget.setAttribute('y2', to.y);
        }

        const sourceNid = this.dragSource.dataset.nid;
        const targetBox = this.boxUnderPointer(event);
        this.boxTargets.forEach((box) => {
            const rect = box.querySelector('rect');
            const valid = box === targetBox && box !== this.dragSource
                && (box.dataset.candidates || '').split(' ').includes(sourceNid);
            rect.style.stroke = valid ? '#16a34a' : '';
            rect.style.strokeWidth = valid ? '3' : '';
        });
    }

    onUp(event) {
        window.removeEventListener('pointermove', this._onMove);
        window.removeEventListener('pointerup', this._onUp);

        if (this.hasDragLineTarget) this.dragLineTarget.hidden = true;
        this.boxTargets.forEach((box) => {
            const rect = box.querySelector('rect');
            rect.style.stroke = '';
            rect.style.strokeWidth = '';
        });

        if (this.dragMoved && this.dragSource) {
            this.justDragged = true;
            this.connectTo(this.dragSource, this.boxUnderPointer(event));
        }
        this.dragSource = null;
    }

    /** Empêche la navigation `xlink:href` de la boîte si un glisser vient d'avoir lieu. */
    suppressIfDragged(event) {
        if (this.justDragged) {
            event.preventDefault();
            this.justDragged = false;
        }
    }

    clearEdge(event) {
        event.preventDefault();
        event.stopPropagation();
        this.setParent(event.currentTarget.dataset.child, '');
    }

    connectTo(sourceBox, targetBox) {
        if (!targetBox || targetBox === sourceBox) return;
        const sourceNid = sourceBox.dataset.nid;
        if (!(targetBox.dataset.candidates || '').split(' ').includes(sourceNid)) return;
        this.setParent(targetBox.dataset.nid, sourceNid);
    }

    setParent(childNid, parentNid) {
        const select = this.parentSelectTargets.find((s) => s.dataset.nid === childNid);
        if (!select) return;
        select.value = parentNid;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        select.style.outline = '2px solid #16a34a';
        select.style.outlineOffset = '1px';
        setTimeout(() => { select.style.outline = ''; select.style.outlineOffset = ''; }, 900);
    }

    boxUnderPointer(event) {
        const el = document.elementFromPoint(event.clientX, event.clientY);
        return el ? el.closest('[data-ramification-target="box"]') : null;
    }

    boxCenter(boxEl) {
        const rect = boxEl.querySelector('rect');
        const x = parseFloat(rect.getAttribute('x'));
        const y = parseFloat(rect.getAttribute('y'));
        const w = parseFloat(rect.getAttribute('width'));
        const h = parseFloat(rect.getAttribute('height'));

        return { x: x + w / 2, y: y + h / 2 };
    }

    toSvgPoint(clientX, clientY) {
        const svg = this.svgTarget;
        const pt = svg.createSVGPoint();
        pt.x = clientX;
        pt.y = clientY;
        const ctm = svg.getScreenCTM();

        return ctm ? pt.matrixTransform(ctm.inverse()) : pt;
    }
}
