<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Entity\Formation;
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
    ) {
    }

    /**
     * @return list<NodeView> racines pédagogiques
     */
    public function build(Formation $formation): array
    {
        $roots = $this->maquette->open($formation)->pedagogicalRoots();
        $views = array_map(fn (TreeNode $n) => $this->view($n), $roots);
        $this->numbering->apply($views);

        return $views;
    }

    public function buildSubtree(TreeNode $root): NodeView
    {
        $view = $this->view($root);
        $this->numbering->apply([$view]);

        return $view;
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
        $ownHours = AttributeCatalog::sumHours($node->getAttribute('hours'));
        $ownEcts = (float) ($node->getAttribute('ects') ?? 0);

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
            $view->totalEcts = $children === [] ? $ownEcts : $childEcts;
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
        // attributs techniques (« _comments », « _sourceHash »…) : pas une saisie
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
