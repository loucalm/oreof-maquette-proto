import { Controller } from '@hotwired/stimulus';

/**
 * Niveaux de BCC de l'éditeur de diplôme : ajout depuis le prototype, retrait
 * (jamais le dernier niveau restant), montée / descente, numérotation des lignes
 * et liste « Niveau relié aux éléments » tenue à jour (nom des niveaux, indice
 * suivi lors des retraits et déplacements).
 *
 * Symfony garde les lignes dans l'ordre de leur index : les nouvelles lignes
 * sont ajoutées en fin, et un déplacement échange les valeurs des champs.
 */
export default class extends Controller {
    static targets = ['list', 'row', 'input', 'number', 'up', 'down', 'remove', 'link'];
    static values = { prototype: String, index: Number, label: String, choice: String };

    connect() {
        this.refresh();
    }

    add() {
        const html = this.prototypeValue
            .replaceAll('__name__', String(this.indexValue))
            .replaceAll('__n__', String(this.rowTargets.length + 1));
        this.indexValue += 1;
        this.listTarget.insertAdjacentHTML('beforeend', html);
        this.refresh();
        this.inputTargets.at(-1)?.focus();
    }

    remove(event) {
        if (this.rowTargets.length <= 1) return;
        const row = event.currentTarget.closest('[data-bcc-levels-target~="row"]');
        const removed = this.rowTargets.indexOf(row);
        const link = this._link();
        row.remove();
        if (link === removed) this._setLink(null);
        else if (link !== null && link > removed) this._setLink(link - 1);
        this.refresh();
    }

    up(event) {
        this._move(event, -1);
    }

    down(event) {
        this._move(event, 1);
    }

    sync() {
        this.refresh();
    }

    refresh() {
        const rows = this.rowTargets;
        rows.forEach((row, i) => {
            const n = row.querySelector('[data-bcc-levels-target~="number"]');
            if (n) n.textContent = this._format(this.labelValue, i, '');
            row.querySelector('[data-bcc-levels-target~="up"]').disabled = i === 0;
            row.querySelector('[data-bcc-levels-target~="down"]').disabled = i === rows.length - 1;
            row.querySelector('[data-bcc-levels-target~="remove"]').disabled = rows.length <= 1;
        });
        this._renderLink();
    }

    _move(event, delta) {
        const rows = this.rowTargets;
        const i = rows.indexOf(event.currentTarget.closest('[data-bcc-levels-target~="row"]'));
        const j = i + delta;
        if (i < 0 || j < 0 || j >= rows.length) return;
        const a = rows[i].querySelector('input');
        const b = rows[j].querySelector('input');
        [a.value, b.value] = [b.value, a.value];
        const link = this._link();
        if (link === i) this._setLink(j);
        else if (link === j) this._setLink(i);
        this.refresh();
    }

    _link() {
        if (!this.hasLinkTarget || this.linkTarget.value === '') return null;
        return Number(this.linkTarget.value);
    }

    _setLink(value) {
        this._pending = value === null ? '' : String(value);
    }

    /** Une option par niveau, après l'option « dernier niveau (défaut) ». */
    _renderLink() {
        if (!this.hasLinkTarget) return;
        const select = this.linkTarget;
        const current = this._pending ?? select.value;
        this._pending = undefined;
        const placeholder = select.querySelector('option[value=""]');
        select.querySelectorAll('option:not([value=""])').forEach((o) => o.remove());
        this.inputTargets.forEach((input, i) => {
            const name = input.value.trim();
            const opt = document.createElement('option');
            opt.value = String(i);
            opt.textContent = name === '' ? this._format(this.labelValue, i, '') : this._format(this.choiceValue, i, name);
            select.append(opt);
        });
        const valid = current !== '' && Number(current) < this.inputTargets.length;
        select.value = valid ? current : '';
        if (!valid && placeholder) placeholder.selected = true;
    }

    _format(pattern, i, name) {
        return pattern.replace('%n%', String(i + 1)).replace('%name%', name);
    }
}
