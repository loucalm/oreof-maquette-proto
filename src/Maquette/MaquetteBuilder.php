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

        $childHours = array_sum(array_map(static fn (NodeView $c) => $c->totalHours, $children));
        $childEcts = array_sum(array_map(static fn (NodeView $c) => $c->totalEcts, $children));

        $view->totalHours = $children === [] ? $ownHours : $childHours + $ownHours;
        $view->totalEcts = $children === [] ? $ownEcts : $childEcts;

        // --- statut ---
        $view->missingCount = $this->missingRequired($node);
        $view->status = $this->resolveStatus($node, $view);

        return $view;
    }

    private function missingRequired(TreeNode $node): int
    {
        $missing = 0;
        $caps = $node->effectiveCapabilities();
        foreach ($this->catalog->all() as $key => $def) {
            if (!($caps[$key] ?? false) || !($def['required'] ?? false)) {
                continue;
            }
            $value = $node->getAttribute($key);
            if ($key === 'hours') {
                if (!AttributeCatalog::hoursProvided($value)) {
                    ++$missing;
                }
                continue;
            }
            if ($value === null || $value === '' || $value === []) {
                ++$missing;
            }
        }

        // libellé requis partout
        if (trim($node->getLabel()) === '') {
            ++$missing;
        }
        // code requis sur les EC
        if (($caps['code'] ?? false) && 'ec' === $node->getType()->getKey() && trim((string) $node->getCode()) === '') {
            ++$missing;
        }

        return $missing;
    }

    private function resolveStatus(TreeNode $node, NodeView $view): string
    {
        $childStatuses = array_map(static fn (NodeView $c) => $c->status, $view->children);
        $anyChildNotOk = \in_array(NodeView::STATUS_INCOMPLETE, $childStatuses, true)
            || \in_array(NodeView::STATUS_EMPTY, $childStatuses, true);

        $ownMissing = $view->missingCount;
        $hasAnyOwnData = $node->getAttributes() !== [] || trim($node->getLabel()) !== '';

        $needsChildren = !$node->getFormation()->isLeafType($node->getType()->getKey());
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
     * @return list<array{node: TreeNode, missing: list<string>}>
     */
    public function collectIssues(array $roots): array
    {
        $issues = [];
        $walk = function (array $views) use (&$walk, &$issues): void {
            foreach ($views as $view) {
                $missing = $this->missingFields($view->node);
                $node = $view->node;
                if (!$node->getFormation()->isLeafType($node->getType()->getKey()) && $view->children === []) {
                    $missing[] = 'aucun enfant';
                }
                if ($missing !== []) {
                    $issues[] = ['node' => $view->node, 'missing' => $missing];
                }
                $walk($view->children);
            }
        };
        $walk($roots);

        return $issues;
    }

    /** @return list<string> intitulés des champs requis manquants */
    private function missingFields(TreeNode $node): array
    {
        $missing = [];
        if (trim($node->getLabel()) === '') {
            $missing[] = 'libellé';
        }
        $caps = $node->effectiveCapabilities();
        if (($caps['code'] ?? false) && 'ec' === $node->getType()->getKey() && trim((string) $node->getCode()) === '') {
            $missing[] = "code de l'ec";
        }
        foreach ($this->catalog->all() as $key => $def) {
            if (!($caps[$key] ?? false) || !($def['required'] ?? false)) {
                continue;
            }
            $value = $node->getAttribute($key);
            $empty = $key === 'hours'
                ? !AttributeCatalog::hoursProvided($value)
                : ($value === null || $value === '' || $value === []);
            if ($empty) {
                $missing[] = mb_strtolower($def['label']);
            }
        }

        return $missing;
    }

    /**
     * Progression globale d'une formation : % de nœuds au statut OK.
     *
     * @param list<NodeView> $roots
     */
    public function progress(array $roots): int
    {
        $ok = 0;
        $total = 0;
        $walk = static function (array $views) use (&$walk, &$ok, &$total): void {
            foreach ($views as $v) {
                ++$total;
                if ($v->status === NodeView::STATUS_OK) {
                    ++$ok;
                }
                $walk($v->children);
            }
        };
        $walk($roots);

        return $total === 0 ? 0 : (int) round($ok / $total * 100);
    }
}
