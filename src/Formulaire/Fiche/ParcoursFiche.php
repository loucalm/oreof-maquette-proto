<?php

declare(strict_types=1);

namespace App\Formulaire\Fiche;

use App\Diplome\Spec\CanvasSpec;
use App\Entity\Formation;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;

/** Fiche d'un parcours (formation avec parcours) : données sur le nœud, nom et ECTS portés par le nœud lui-même. */
final class ParcoursFiche extends AbstractFicheTarget
{
    public function __construct(Formation $formation, MaquetteDoc $doc, private readonly TreeNode $node)
    {
        parent::__construct($formation, $doc);
    }

    public function fiche(): string
    {
        return CanvasSpec::PARCOURS;
    }

    public function parcours(): TreeNode
    {
        return $this->node;
    }

    public function data(string $entryKey): array
    {
        return $this->node->getParametre($entryKey);
    }

    public function setData(string $entryKey, array $data): void
    {
        $this->node->setParametre($entryKey, $data);
    }

    public function bound(): array
    {
        return ['nom' => $this->node->getLabel(), 'ectsTotal' => $this->node->getAttribute('ects')];
    }

    public function writeBound(array $input): void
    {
        if (null !== ($nom = self::text($input['nom'] ?? null))) {
            $this->node->setLabel($nom);
        }
        if (\array_key_exists('ectsTotal', $input)) {
            $this->node->setAttribute('ects', self::int($input['ectsTotal']));
        }
    }
}
