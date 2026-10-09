<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Maquette\Doc\TreeNode;

/**
 * Références calculées des blocs et compétences d'un contexte BCC (formation sans
 * parcours, ou un parcours) : « BC 2 » pour un bloc, « 2.A » pour sa 1re compétence.
 * Dérivées de la position, jamais saisies : source unique pour l'éditeur BCC, les
 * listes de choix d'un EC et l'export.
 */
final class BccReferences
{
    private const COMPETENCE = 'competence';

    public function __construct(private readonly Numbering $numbering)
    {
    }

    /**
     * @param list<TreeNode> $blocs
     *
     * @return list<array{node: TreeNode, ref: string, transversal: bool, competences: list<array{node: TreeNode, ref: string}>}>
     */
    public function tree(array $blocs): array
    {
        $out = [];
        $regularIdx = 0;
        foreach ($blocs as $bloc) {
            $transversal = $bloc->isTransversalBloc();
            $blocRef = '';
            if (!$transversal && $bloc->getType()->isNumbered()) {
                $blocRef = $this->numbering->format(++$regularIdx, $bloc->getType()->getNumberStyle());
            }

            $comps = [];
            $ci = 0;
            foreach ($bloc->getChildren() as $c) {
                if (self::COMPETENCE !== $c->getType()->getKey()) {
                    continue;
                }
                $ref = '';
                if ('' !== $blocRef && $c->getType()->isNumbered()) {
                    $ref = $blocRef.'.'.$this->numbering->format(++$ci, $c->getType()->getNumberStyle());
                }
                $comps[] = ['node' => $c, 'ref' => $ref];
            }

            $out[] = ['node' => $bloc, 'ref' => $blocRef, 'transversal' => $transversal, 'competences' => $comps];
        }

        return $out;
    }
}
