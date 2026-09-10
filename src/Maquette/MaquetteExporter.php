<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Entity\Formation;

/**
 * Export de la maquette d'une formation.
 *
 * - « brut » : les 3 documents JSON tels qu'ils sont stockés (parametres /
 *   dataParcours / arbre) — pour voir la structure de stockage.
 * - « enrichi » : l'arbre calculé (libellés de type résolus, référence
 *   hiérarchique, agrégats heures / ECTS, statut) — la forme qu'un consommateur
 *   aval (fiche, LHEO, Apogée…) utiliserait.
 */
final class MaquetteExporter
{
    public function __construct(
        private readonly Maquette $maquette,
        private readonly MaquetteBuilder $builder,
    ) {
    }

    /** @return array<string, mixed> */
    public function raw(Formation $formation): array
    {
        $doc = $this->maquette->open($formation);

        return [
            'formation' => [
                'id' => $formation->getId(),
                'name' => $formation->getName(),
                'diplome' => $formation->getDiplome(),
                'domaine' => $formation->getDomaine(),
                'composante' => $formation->getComposante(),
                'multiParcours' => $formation->isMultiParcours(),
                'ectsTotal' => $formation->getEctsTotal(),
                'calendar' => ['span' => $formation->getCalendarSpan(), 'unit' => $formation->getCalendarUnit()],
                'structure' => $formation->getStructure(),
            ],
            'parametres' => $doc->parametres,
            'dataParcours' => $doc->parcours,
            'arbre' => $doc->dumpTree(),
            'stats' => $formation->getStats(),
        ];
    }

    /** @return array<string, mixed> */
    public function enriched(Formation $formation): array
    {
        $roots = $this->builder->build($formation);

        return [
            'formation' => [
                'name' => $formation->getName(),
                'diplome' => $formation->getDiplome(),
                'multiParcours' => $formation->isMultiParcours(),
                'ectsTotal' => $formation->getEctsTotal(),
                'progress' => $this->builder->progress($roots),
                'parametres' => $this->maquette->open($formation)->parametres,
            ],
            'arbre' => array_map(fn (NodeView $v) => $this->view($v), $roots),
            'bcc' => $this->bcc($formation),
        ];
    }

    /** @return array<string, mixed> */
    private function view(NodeView $v): array
    {
        $out = [
            'nid' => $v->node->getId(),
            'type' => $v->node->getType()->getKey(),
            'typeLabel' => $v->node->getType()->getLabel(),
        ];
        if ($v->ref !== '') {
            $out['ref'] = $v->ref;
        }
        $out['label'] = $v->node->getLabel();
        if ($v->node->getCode() !== null) {
            $out['code'] = $v->node->getCode();
        }
        if ($v->node->getLocked() !== []) {
            $out['locked'] = $v->node->getLocked();
        }
        $out['status'] = $v->status;
        $out['totalHours'] = round($v->totalHours, 2);
        $out['totalEcts'] = round($v->totalEcts, 2);
        if ($v->node->getAttributes() !== []) {
            $out['attributes'] = $v->node->getAttributes();
        }
        if ($v->children !== []) {
            $out['children'] = array_map(fn (NodeView $c) => $this->view($c), $v->children);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function bcc(Formation $formation): array
    {
        $doc = $this->maquette->open($formation);
        $contexts = $formation->isMultiParcours() ? $doc->parcoursNodes() : [null];

        $out = [];
        foreach ($contexts as $ctx) {
            $blocs = $ctx !== null ? $ctx->getBccBlocs() : $doc->competenceBlocs();
            $entry = ['contexte' => $ctx?->getDisplayLabel() ?? $formation->getName(), 'blocs' => []];
            foreach ($blocs as $bloc) {
                $comps = [];
                foreach ($bloc->getChildren() as $c) {
                    if ($c->getType()->getKey() === 'competence') {
                        $comps[] = array_filter([
                            'label' => $c->getLabel(),
                            'code' => $c->getCode(),
                            'description' => $c->getDescription() ?: null,
                        ]);
                    }
                }
                $entry['blocs'][] = [
                    'label' => $bloc->getLabel(),
                    'transversal' => $bloc->isTransversalBloc(),
                    'competences' => $comps,
                ];
            }
            $out[] = $entry;
        }

        return $out;
    }
}
