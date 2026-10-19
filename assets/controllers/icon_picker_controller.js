import { Controller } from '@hotwired/stimulus';

const API = 'https://api.iconify.design';
const ICON = /^([a-z0-9-]+):([a-z0-9-]+)$/;
const MAX_RESULTS = 160;
const cache = new Map(); // 'ph:nom' -> mask-image CSS, ou null si inconnue

// Proposées tant qu'aucune recherche n'est saisie : icônes Phosphor utiles pour des éléments de formation.
const SUGGESTIONS = [
    'graduation-cap', 'student', 'chalkboard-teacher', 'book-open', 'books', 'book-bookmark', 'notebook',
    'exam', 'certificate', 'medal', 'trophy', 'target', 'puzzle-piece', 'stack', 'squares-four',
    'tree-structure', 'git-branch', 'path', 'list-bullets', 'list-checks', 'check-square-offset',
    'calendar', 'calendar-blank', 'clock', 'timer', 'hourglass', 'users', 'users-three', 'user',
    'handshake', 'briefcase', 'buildings', 'globe', 'translate', 'map-pin', 'flag', 'flask', 'atom',
    'dna', 'leaf', 'scales', 'calculator', 'code', 'monitor', 'laptop', 'presentation', 'video-camera',
    'microphone', 'palette', 'music-notes', 'lightbulb', 'star', 'bookmark', 'tag', 'hash', 'note',
    'article', 'file-text', 'clipboard-text', 'chart-bar', 'folder', 'folder-open', 'gear', 'cube',
];

/**
 * Sélecteur d'icône Phosphor : aperçu de la valeur saisie, grille de suggestions et recherche
 * dans la collection via l'API Iconify (noms en anglais). Clic sur une icône = valeur du champ.
 */
export default class extends Controller {
    static targets = ['input', 'preview', 'panel', 'search', 'weight', 'grid', 'status'];

    connect() {
        this.onOutside = (e) => { if (!this.element.contains(e.target)) this.close(); };
        document.addEventListener('click', this.onOutside);
        this.refreshPreview();
    }

    disconnect() {
        document.removeEventListener('click', this.onOutside);
        clearTimeout(this.timer);
    }

    toggle() {
        this.panelTarget.hidden ? this.open() : this.close();
    }

    open() {
        this.panelTarget.hidden = false;
        this.render();
        this.searchTarget.focus();
    }

    close() {
        this.panelTarget.hidden = true;
    }

    keydown(e) {
        if (e.key === 'Escape') { e.preventDefault(); this.close(); }
        if (e.key === 'Enter') e.preventDefault();
    }

    async refreshPreview() {
        const icon = this.inputTarget.value.trim();
        await this.load([icon]).catch(() => {});
        if (icon === this.inputTarget.value.trim()) this.paint(this.previewTarget, icon);
    }

    search() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.render(), 300);
    }

    async render() {
        const query = this.searchTarget.value.trim();
        const suffix = this.weightTarget.value;
        let names = SUGGESTIONS;
        this.statusTarget.textContent = 'Chargement…';

        try {
            if (query !== '') {
                const res = await fetch(`${API}/search?prefix=ph&limit=999&query=${encodeURIComponent(query)}`);
                if (!res.ok) throw new Error(res.statusText);
                const data = await res.json();
                // on ne garde que les formes de base : la graisse est choisie à part
                names = [...new Set((data.icons ?? []).map((i) => i.replace(/^ph:/, '').replace(/-(bold|fill|duotone|light|thin)$/, '')))].slice(0, MAX_RESULTS);
            }
            await this.load(names.map((name) => `ph:${name}${suffix}`));
        } catch {
            this.statusTarget.textContent = 'Icônes indisponibles (api.iconify.design injoignable ou saturé, réessayez dans un instant).';
            this.gridTarget.replaceChildren();
            return;
        }
        if (query !== this.searchTarget.value.trim() || suffix !== this.weightTarget.value) return; // réponse périmée

        this.statusTarget.textContent = query === ''
            ? 'Suggestions — recherchez en anglais (book, calendar, users…)'
            : `${names.length} résultat${names.length > 1 ? 's' : ''} pour « ${query} »`;

        const current = this.inputTarget.value.trim();
        this.gridTarget.replaceChildren(...names.map((name) => {
            const icon = `ph:${name}${suffix}`;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.title = icon;
            btn.dataset.icon = icon;
            btn.dataset.action = 'icon-picker#pick';
            btn.className = 'icon-picker-cell' + (icon === current ? ' is-selected' : '');
            const glyph = document.createElement('span');
            this.paint(glyph, icon);
            btn.append(glyph);
            return btn;
        }));
    }

    pick(e) {
        this.inputTarget.value = e.currentTarget.dataset.icon;
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
        this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
        this.close();
    }

    // Une seule requête Iconify par collection pour tout un lot d'icônes (une requête par SVG
    // déclenche vite un « 429 Too Many Requests »). Résultat mis en cache en data URI.
    async load(icons) {
        const byPrefix = {};
        for (const icon of icons) {
            const m = ICON.exec(icon);
            if (m && !cache.has(icon)) (byPrefix[m[1]] ??= []).push(m[2]);
        }
        await Promise.all(Object.entries(byPrefix).map(async ([prefix, names]) => {
            const res = await fetch(`${API}/${prefix}.json?icons=${names.join(',')}`);
            if (!res.ok) throw new Error(res.statusText);
            const data = await res.json();
            for (const name of names) {
                const icon = data.icons?.[name] ?? data.icons?.[data.aliases?.[name]?.parent];
                const svg = icon && `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${icon.width ?? data.width ?? 16} ${icon.height ?? data.height ?? 16}">${icon.body}</svg>`;
                cache.set(`${prefix}:${name}`, svg ? `url("data:image/svg+xml,${encodeURIComponent(svg)}")` : null);
            }
        }));
    }

    // Icône peinte en currentColor via un masque CSS (le SVG n'est jamais injecté dans le DOM).
    paint(el, icon) {
        const mask = cache.get(icon) ?? null;
        el.classList.add('icon-picker-glyph');
        el.classList.toggle('is-empty', !mask);
        el.style.maskImage = el.style.webkitMaskImage = mask ?? 'none';
    }
}
