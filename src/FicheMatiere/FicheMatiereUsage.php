<?php

declare(strict_types=1);

namespace App\FicheMatiere;

use App\Entity\FicheMatiere;
use App\Entity\Formation;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Repository\FormationRepository;

/**
 * Usages d'une fiche matière (spec §8.1) : les EC, dans toutes les formations, qui la référencent
 * par leur attribut `ficheMatiere`. Sert au bandeau « Utilisée par N EC dans M parcours » et à
 * calculer les impacts d'une imposition.
 */
final class FicheMatiereUsage
{
    public function __construct(
        private readonly FormationRepository $formations,
        private readonly Maquette $maquette,
        private readonly EcValueResolver $values,
        private readonly FicheMatiereForms $forms,
        private readonly FicheMatiereAccess $access,
    ) {
    }

    /**
     * @return list<array{formation: Formation, ec: TreeNode, path: string, relation: FicheRelation}>
     */
    public function usages(FicheMatiere $fiche): array
    {
        $id = $fiche->getId();
        if (null === $id) {
            return [];
        }

        $result = [];
        foreach ($this->formations->findAll() as $formation) {
            $doc = $this->maquette->open($formation);
            foreach ($doc->allNodes() as $node) {
                if (!$node->getType()->isElementLevel() || $this->values->ficheId($node) !== $id) {
                    continue;
                }
                $result[] = [
                    'formation' => $formation,
                    'ec' => $node,
                    'path' => $this->path($formation, $node),
                    'relation' => $this->relation($fiche, $node),
                ];
            }
        }

        return $result;
    }

    /** Statut de remplissage de la fiche : « ok » si tous ses champs obligatoires sont remplis, sinon « incomplete ». */
    public function status(FicheMatiere $fiche): string
    {
        $values = static function (string $key) use ($fiche): mixed {
            if (\in_array($key, EcValueResolver::KEYS, true)) {
                return $fiche->getDefault($key);
            }

            return $fiche->getDataValue($key);
        };
        $required = [];
        foreach ($this->forms->fields($fiche) as $field) {
            if ($field->required) {
                $required[] = $field->getKey();
            }
        }
        if ([] === $required) {
            return 'ok';
        }
        foreach ($required as $key) {
            if (!$this->filled($values($key))) {
                return 'incomplete';
            }
        }

        return 'ok';
    }

    private function relation(FicheMatiere $fiche, TreeNode $ec): FicheRelation
    {
        return $this->access->relation($fiche, $ec);
    }

    private function filled(mixed $value): bool
    {
        return !(null === $value || '' === $value || [] === $value);
    }

    /** Chemin lisible « formation › parcours › … › EC » (spec §8.1). */
    private function path(Formation $formation, TreeNode $ec): string
    {
        $chain = [];
        for ($node = $ec; null !== $node; $node = $node->getParent()) {
            $chain[] = $node->getDisplayLabel();
        }

        return implode(' › ', array_merge([$formation->getName()], array_reverse($chain)));
    }
}
