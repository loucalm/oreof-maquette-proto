import { Controller } from '@hotwired/stimulus';

/**
 * Frise des parcours (page autonome) : trace les branches parent → enfant entre les barres, comme le graphe de
 * l'éditeur. Chaque barre porte `data-nid` et, pour un parcours enfant, `data-parent`. Retracé au redimensionnement.
 */
export default class extends Controller {
    static targets = ['svg', 'bar'];

    connect() {
        this.observer = new ResizeObserver(() => this.draw());
        this.observer.observe(this.element);
        this.draw();
    }

    disconnect() {
        this.observer?.disconnect();
    }

    draw() {
        const ns = 'http://www.w3.org/2000/svg';
        const svg = this.svgTarget;
        const origin = this.element.getBoundingClientRect();
        svg.setAttribute('viewBox', `0 0 ${origin.width} ${origin.height}`);
        svg.querySelectorAll('path').forEach((p) => p.remove());
        const bars = new Map(this.barTargets.map((b) => [b.dataset.nid, b]));

        this.barTargets.forEach((child) => {
            const parent = bars.get(child.dataset.parent);
            if (!parent) return;
            const p = parent.getBoundingClientRect();
            const c = child.getBoundingClientRect();
            const ex = c.left - origin.left;
            const ey = c.top - origin.top + c.height / 2;
            let d;
            if (c.left >= p.right - 1) {
                // l'enfant commence après le parent : courbe du bord droit du parent au bord gauche de l'enfant
                // coude arrondi : horizontal, vertical au milieu de l'écart, horizontal
                const sx = p.right - origin.left;
                const sy = p.top - origin.top + p.height / 2;
                const mx = sx + (ex - sx) / 2;
                const r = Math.min(8, Math.abs(ey - sy) / 2, (ex - sx) / 2);
                const dir = ey >= sy ? 1 : -1;
                d = `M ${sx} ${sy} H ${mx - r} Q ${mx} ${sy} ${mx} ${sy + dir * r} V ${ey - dir * r} Q ${mx} ${ey} ${mx + r} ${ey} H ${ex - 2}`;
            } else {
                // périodes qui se recouvrent : descente depuis le bas du parent, puis vers l'enfant
                const x = Math.max(p.left, c.left) - origin.left - 10;
                d = `M ${x} ${p.bottom - origin.top} V ${ey} H ${ex - 2}`;
            }
            const path = document.createElementNS(ns, 'path');
            path.setAttribute('d', d);
            path.setAttribute('class', 'timeline-branch');
            path.setAttribute('marker-end', 'url(#pt-arrow)');
            svg.appendChild(path);
        });
    }
}
