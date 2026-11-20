<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Diplome\TypeDiplomeResolver;
use App\Entity\Formation;
use App\FicheMatiere\EcValueResolver;
use App\Maquette\Doc\TreeNode;

/**
 * Construit l'arbre calculé d'une formation : NodeView imbriqués, avec statut,
 * agrégats (heures / ECTS) et référence hiérarchique, résolus en une passe.
 */
final class MaquetteBuilder
{
    public function __construct(
        private readonly Maquette $maquette,
        private readonly AttributeCatalog $catalog,
        private readonly Numbering $numbering,
        private readonly Completion $completion,
        private readonly TypeDiplomeResolver $typeDiplomes,
        private readonly EcValueResolver $values,
    ) {
    }

    /**
     * @return list<NodeView> racines pédagogiques
     */
    public function build(Formation $formation): array
    {
        $roots = $this->maquette->open($formation)->pedagogicalRoots();
        $views = array_map(fn (TreeNode $n) => $this->view($n), $roots);
        $this->numbering->apply($views, $this->startAt($formation), $this->perPeriod($formation));

        return $views;
    }

    public function buildSubtree(TreeNode $root): NodeView
    {
        $view = $this->view($root);
        $formation = $root->getFormation();
        $this->numbering->apply([$view], null === $formation ? [] : $this->startAt($formation), null === $formation ? [] : $this->perPeriod($formation));

        return $view;
    }

    /**
     * Premier indice de numérotation par type, selon la version de type de diplôme.
     *
     * @return array<string, int>
     */
    private function startAt(Formation $formation): array
    {
        $startAt = [];
        foreach ($this->typeDiplomes->configFor($formation)?->structure->levels ?? [] as $level) {
            $startAt[$level->typeKey] = $level->startAt;
        }

        return $startAt;
    }

    /**
     * Nœuds de chaque niveau par période (nœud du premier niveau) : 1 pour le premier niveau, puis le produit des
     * nombres exacts par parent (2 semestres par année…). S'arrête au premier niveau sans nombre exact.
     *
     * @return array<string, int>
     */
    private function perPeriod(Formation $formation): array
    {
        $out = [];
        $factor = 1;
        foreach ($this->typeDiplomes->configFor($formation)?->structure->levels ?? [] as $i => $level) {
            if ($i > 0) {
                if (null === $level->count || !$level->count->isExact() || null === $level->count->min) {
                    break;
                }
                $factor *= max(1, $level->count->min);
            }
            $out[$level->typeKey] = $factor;
        }

        return $out;
    }

    /**
     * Vue d'un nœud AVEC sa référence hiérarchique correcte (numérotée depuis
     * les racines de la formation), pour le panneau d'édition.
     */
    public function locate(Formation $formation, string $nid): ?NodeView
    {
        return $this->findView($this->build($formation), $nid);
    }

    /**
     * @param list<NodeView> $views
     */
    private function findView(array $views, string $nid): ?NodeView
    {
        foreach ($views as $v) {
            if ($v->node->getId() === $nid) {
                return $v;
            }
            $found = $this->findView($v->children, $nid);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function view(TreeNode $node): NodeView
    {
        // le BCC (famille compétence) est un arbre parallèle : jamais dans la
        // structure pédagogique, même quand il est porté par un parcours.
        $childNodes = array_values(array_filter(
            $node->getChildren(),
            static fn (TreeNode $c) => !$c->isCompetenceNode(),
        ));
        $children = array_map(fn (TreeNode $c) => $this->view($c), $childNodes);

        $view = new NodeView($node, $children);

        // --- agrégats ---
        $ownHours = AttributeCatalog::sumHours($this->values->value($node, 'hours'));
        $ownEcts = (float) ($this->values->value($node, 'ects') ?? 0);

        if ($node->isChoiceBloc()) {
            // Les alternatives d'un bloc de choix sont mutuellement exclusives :
            // pas question de sommer leurs ECTS/heures (on choisit UNE branche,
            // pas toutes). Le bloc porte son propre ECTS (requis) ; à défaut,
            // et pour les heures (qu'il ne porte pas lui-même), on retient
            // l'alternative la plus lourde comme estimation prudente.
            $childHoursList = array_map(static fn (NodeView $c) => $c->totalHours, $children);
            $childEctsList = array_map(static fn (NodeView $c) => $c->totalEcts, $children);
            $view->totalHours = $ownHours > 0 ? $ownHours : ($childHoursList === [] ? 0.0 : max($childHoursList));
            $view->totalEcts = $ownEcts > 0 ? $ownEcts : ($childEctsList === [] ? 0.0 : max($childEctsList));
        } else {
            $childHours = array_sum(array_map(static fn (NodeView $c) => $c->totalHours, $children));
            $childEcts = array_sum(array_map(static fn (NodeView $c) => $c->totalEcts, $children));

            $view->totalHours = $children === [] ? $ownHours : $childHours + $ownHours;
            // ECTS saisis à ce niveau (UE d'un BUT, dont les EC n'apportent qu'un coefficient) : ils comptent tels quels ;
            // sinon, la somme des enfants
            $view->totalEcts = $children === [] || $ownEcts > 0 ? $ownEcts : $childEcts;
        }

        // --- statut ---
        $s = $this->completion->node($node);
        $view->reqCount = $s['req'];
        $view->filledCount = $s['filled'];
        $view->missingCount = $s['req'] - $s['filled'];
        $view->status = $this->resolveStatus($node, $view);

        return $view;
    }

    private function resolveStatus(TreeNode $node, NodeView $view): string
    {
        $childStatuses = array_map(static fn (NodeView $c) => $c->status, $view->children);
        $anyChildNotOk = \in_array(NodeView::STATUS_INCOMPLETE, $childStatuses, true)
            || \in_array(NodeView::STATUS_EMPTY, $childStatuses, true);

        $ownMissing = $view->missingCount;
        // attributs techniques (« _sourceHash »…) : pas une saisie
        $ownAttributes = array_filter($node->getAttributes(), static fn ($k) => !str_starts_with((string) $k, '_'), \ARRAY_FILTER_USE_KEY);
        $hasAnyOwnData = $ownAttributes !== [] || trim($node->getLabel()) !== '';

        $needsChildren = !$node->isLeaf();
        if ($needsChildren && $view->children === []) {
            return $hasAnyOwnData ? NodeView::STATUS_INCOMPLETE : NodeView::STATUS_EMPTY;
        }

        if ($ownMissing === 0 && !$anyChildNotOk) {
            return NodeView::STATUS_OK;
        }

        if (!$hasAnyOwnData && $view->children === []) {
            return NodeView::STATUS_EMPTY;
        }

        return NodeView::STATUS_INCOMPLETE;
    }

    /**
     * Liste à plat des anomalies de saisie (pour « Vérifier la saisie »).
     *
     * @param list<NodeView> $roots
     *
     * @return list<array{node: TreeNode, missing: list<string>, ref: string, path: string}>
     */
    public function collectIssues(array $roots): array
    {
        $issues = [];
        $walk = function (array $views, string $path) use (&$walk, &$issues): void {
            foreach ($views as $view) {
                $missing = $this->missingFields($view->node);
                $node = $view->node;
                if (!$node->isLeaf() && $view->children === []) {
                    $missing[] = 'aucun enfant';
                }
                // écart à la cible d'ECTS du type (30 par semestre, 60 par année…)
                $target = $view->ectsTarget();
                if (null !== $target && !$view->ectsReached() && $view->children !== []) {
                    $gap = $view->totalEcts - $target;
                    $missing[] = sprintf('ECTS : %s / %d (%s%s)', round($view->totalEcts, 1), $target, $gap > 0 ? '+' : '', round($gap, 1));
                }
                $label = $node->getType()->isTemporel()
                    ? trim($node->getType()->getLabel().' '.$view->ref)
                    : $node->getDisplayLabel();
                if ($missing !== []) {
                    $issues[] = ['node' => $view->node, 'missing' => $missing, 'ref' => $view->ref, 'path' => $path];
                }
                $walk($view->children, '' === $path ? $label : $path.' › '.$label);
            }
        };
        $walk($roots, '');

        return $issues;
    }

    /** @return list<string> intitulés des champs requis manquants */
    private function missingFields(TreeNode $node): array
    {
        return array_map(
            static fn (array $m) => isset($m['detail']) ? $m['label'].' ('.$m['detail'].')' : $m['label'],
            $this->completion->missing($node),
        );
    }

    /**
     * Progression globale : % de champs requis remplis sur l'ensemble des nœuds.
     * Même formule que le cache Formation::stats (Completion). Réutilise les
     * req/filled déjà calculés par view() — pas de second parcours de l'arbre.
     *
     * @param list<NodeView> $roots
     */
    public function progress(array $roots): int
    {
        $acc = ['req' => 0, 'filled' => 0];
        $walk = function (array $views) use (&$walk, &$acc): void {
            foreach ($views as $v) {
                $acc['req'] += $v->reqCount;
                $acc['filled'] += $v->filledCount;
                $walk($v->children);
            }
        };
        $walk($roots);

        return $this->completion->pct($acc);
    }
}
