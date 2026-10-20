/**
 * Refus du garde de structure reçu en AJAX (réponse 422 JSON de StructureViolationListener) :
 * affiche le même toast que le flash serveur — message + lien « Demander une dérogation ».
 *
 * @param {Response} response réponse non-ok d'un fetch
 * @returns {Promise<boolean>} true si c'était un refus du garde (toast affiché)
 */
export async function showGuardRefusal(response) {
    if (response.status !== 422) return false;
    let data;
    try {
        data = await response.clone().json();
    } catch {
        return false;
    }
    if (!data || !Array.isArray(data.violations)) return false;

    let stack = document.querySelector('.flash-stack');
    if (!stack) {
        stack = document.createElement('div');
        stack.className = 'flash-stack';
        stack.setAttribute('role', 'status');
        stack.setAttribute('aria-live', 'polite');
        stack.dataset.turboTemporary = '';
        document.body.appendChild(stack);
    }

    const toast = document.createElement('div');
    toast.className = 'flash flash--warning';
    toast.dataset.controller = 'flash';
    toast.dataset.flashDelayValue = '12000'; // le temps de lire et de cliquer le lien de dérogation

    const msg = document.createElement('span');
    msg.className = 'flash-msg';
    msg.textContent = data.message || data.error || data.violations[0]?.message || '';
    toast.appendChild(msg);

    if (data.derogationUrl) {
        const link = document.createElement('a');
        link.className = 'flash-undo';
        link.href = data.derogationUrl;
        link.dataset.turboFrame = '_top';
        link.textContent = data.derogationLabel || '';
        toast.appendChild(link);
    }

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'flash-x';
    close.dataset.action = 'flash#dismiss';
    close.setAttribute('aria-label', data.closeLabel || '×');
    close.textContent = '×';
    toast.appendChild(close);

    stack.appendChild(toast);

    return true;
}
