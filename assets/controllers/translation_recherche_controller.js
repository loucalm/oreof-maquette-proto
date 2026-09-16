import { Controller } from '@hotwired/stimulus';

/** Recherche transverse dans tous les fichiers de traduction (clé ou valeur). */
export default class extends Controller {
    static targets = ['search', 'resultats'];
    static values = { url: String };

    recherche() {
        const q = this.searchTarget.value.trim();
        if (q.length < 3) {
            this.resultatsTarget.innerHTML = '';
            return;
        }
        this.resultatsTarget.innerHTML = '<p class="text-sm" style="color:var(--c-muted)">Recherche…</p>';
        fetch(`${this.urlValue}?q=${encodeURIComponent(q)}`)
            .then((r) => r.text())
            .then((html) => { this.resultatsTarget.innerHTML = html; });
    }
}
