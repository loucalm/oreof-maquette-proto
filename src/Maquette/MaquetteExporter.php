<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Entity\Formation;
use App\Formulaire\FicheNavigation;

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
        private readonly BccReferences $bccRefs,
        private readonly FicheNavigation $fiches,
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
                'avecParcours' => $formation->isAvecParcours(),
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
                'avecParcours' => $formation->isAvecParcours(),
                'ectsTotal' => $formation->getEctsTotal(),
                'progress' => $this->builder->progress($roots),
                // sans les entrées masquées par le canevas de la version, chaque entrée réduite aux champs de son formulaire
                'parametres' => $this->fiches->visibleParametres($formation, $this->maquette->open($formation)->parametres),
            ],
            'arbre' => array_map(fn (NodeView $v) => $this->view($v), $roots),
            // référentiel masqué par le canevas : absent de l'export
            'bcc' => $this->fiches->bccShown($formation) ? $this->bcc($formation) : [],
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
        // attributs métier seulement (« _sourceHash », « _tpl » sont internes)
        $attributes = array_filter($v->node->getAttributes(), static fn ($k) => !str_starts_with((string) $k, '_'), \ARRAY_FILTER_USE_KEY);
        if ($attributes !== []) {
            $out['attributes'] = $attributes;
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
        $contexts = $formation->isAvecParcours() ? $doc->parcoursNodes() : [null];

        $out = [];
        foreach ($contexts as $ctx) {
            $blocs = $ctx !== null ? $ctx->getBccBlocs() : $doc->competenceBlocs();
            $entry = ['contexte' => $ctx?->getDisplayLabel() ?? $formation->getName(), 'blocs' => []];
            foreach ($this->bccRefs->tree($blocs) as $bloc) {
                $comps = [];
                foreach ($bloc['competences'] as $row) {
                    $comps[] = array_filter([
                        'code' => $row['ref'] ?: null,
                        'description' => $row['node']->getDescription() ?: null,
                    ]);
                }
                $entry['blocs'][] = [
                    'code' => $bloc['ref'] ?: null,
                    'label' => $bloc['node']->getLabel(),
                    'transversal' => $bloc['transversal'],
                    'competences' => $comps,
                ];
            }
            $out[] = $entry;
        }

        return $out;
    }
}
