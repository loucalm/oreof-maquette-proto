<?php

declare(strict_types=1);

namespace App\Maquette;

/**
 * Numérotation hiérarchique calculée des nœuds (« Année 1 », « UE 1.1 »,
 * « EC 1.1.a »…). Référence recalculée à chaque rendu, jamais stockée.
 *
 * Phase 1 : passe neutre (aucune référence). Activée en Phase 3 :
 * `NodeType.numbered` + `NodeType.numberStyle` piloteront le format, l'index
 * étant la position 1-based parmi les frères de même type.
 */
final class Numbering
{
    /**
     * @param list<NodeView> $roots
     */
    public function apply(array $roots): void
    {
        // Phase 3 : remplir NodeView::ref ici.
    }
}
