<?php

declare(strict_types=1);

namespace App\FieldType\Options;

use App\Entity\FicheMatiere;
use App\FicheMatiere\FicheMatiereAccess;
use App\Maquette\Doc\TreeNode;
use App\Repository\FicheMatiereRepository;

/**
 * Fiches matière utilisables par un EC : celles du parcours (porteur, ancêtre, mutualisées) puis hors
 * diplôme, sans doublon. La fiche déjà rattachée à l'EC est ajoutée si elle n'est pas autorisée, avec
 * un libellé « (non autorisée) » pour qu'elle reste visible et modifiable.
 */
final class FichesMatiereSource implements FieldOptionsSourceInterface
{
    public function __construct(
        private readonly FicheMatiereAccess $access,
        private readonly FicheMatiereRepository $fiches,
    ) {
    }

    public function key(): string
    {
        return 'fiches_matiere';
    }

    public function options(TreeNode|FicheMatiere|null $subject): array
    {
        if (!$subject instanceof TreeNode) {
            return [];
        }

        $options = [];
        $seen = [];
        foreach ($this->access->available($subject) as $fiche) {
            $id = (int) $fiche->getId();
            $seen[$id] = true;
            $options[(string) $id] = $fiche->getLibelle();
        }

        $current = $subject->getAttribute('ficheMatiere');
        if (\is_int($current) || (\is_string($current) && ctype_digit($current))) {
            $id = (int) $current;
            if (!isset($seen[$id])) {
                $fiche = $this->fiches->find($id);
                $label = null !== $fiche ? $fiche->getLibelle() : sprintf('Fiche #%d', $id);
                $options[(string) $id] = $label.' (non autorisée)';
            }
        }

        return $options;
    }
}
