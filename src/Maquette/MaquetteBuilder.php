<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Entity\Formation;
use App\Entity\Node;
use App\Repository\NodeRepository;

/**
 * Construit l'arbre calculé d'une formation : NodeView imbriqués, avec statut
 * et agrégats (heures / ECTS) résolus en une passe ascendante.
 */
final class MaquetteBuilder
{
    public function __construct(private readonly NodeRepository $nodes)
    {
    }

    /**
     * @return list<NodeView> racines
     */
    public function build(Formation $formation): array
    {
        $all = $this->nodes->findForFormation($formation);

        /** @var array<int, list<Node>> $childrenByParent */
        $childrenByParent = [];
        foreach ($all as $node) {
            $pid = $node->getParent()?->getId() ?? 0;
            $childrenByParent[$pid][] = $node;
        }
        foreach ($childrenByParent as &$list) {
            usort($list, static fn (Node $a, Node $b) => $a->getPosition() <=> $b->getPosition());
        }
        unset($list);

        $roots = $childrenByParent[0] ?? [];

        return array_map(fn (Node $n) => $this->view($n, $childrenByParent), $roots);
    }

    public function buildSubtree(Node $root): NodeView
    {
        $all = $this->nodes->findForFormation($root->getFormation());
        $childrenByParent = [];
        foreach ($all as $node) {
            $pid = $node->getParent()?->getId() ?? 0;
            $childrenByParent[$pid][] = $node;
        }

        return $this->view($root, $childrenByParent);
    }

    /**
     * @param array<int, list<Node>> $childrenByParent
     */
    private function view(Node $node, array $childrenByParent): NodeView
    {
        $childNodes = $childrenByParent[$node->getId()] ?? [];
        $children = array_map(fn (Node $c) => $this->view($c, $childrenByParent), $childNodes);

        $view = new NodeView($node, $children);

        // --- agrégats ---
        $ownHours = AttributeCatalog::sumHours($node->getAttribute('hours'));
        $ownEcts = (float) ($node->getAttribute('ects') ?? 0);

        $childHours = array_sum(array_map(static fn (NodeView $c) => $c->totalHours, $children));
        $childEcts = array_sum(array_map(static fn (NodeView $c) => $c->totalEcts, $children));

        // une feuille compte ses propres valeurs ; un nœud parent additionne ses enfants
        $view->totalHours = $children === [] ? $ownHours : $childHours + $ownHours;
        $view->totalEcts = $children === [] ? $ownEcts : $childEcts;

        // --- statut ---
        $view->missingCount = $this->missingRequired($node);
        $view->status = $this->resolveStatus($node, $view);

        return $view;
    }

    private function missingRequired(Node $node): int
    {
        $missing = 0;
        $caps = $node->effectiveCapabilities();
        foreach (AttributeCatalog::all() as $key => $def) {
            if (!($caps[$key] ?? false) || !($def['required'] ?? false)) {
                continue;
            }
            $value = $node->getAttribute($key);
            if ($key === 'hours') {
                if (AttributeCatalog::sumHours($value) <= 0) {
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

        return $missing;
    }

    private function resolveStatus(Node $node, NodeView $view): string
    {
        $childStatuses = array_map(static fn (NodeView $c) => $c->status, $view->children);
        $anyChildNotOk = \in_array(NodeView::STATUS_INCOMPLETE, $childStatuses, true)
            || \in_array(NodeView::STATUS_EMPTY, $childStatuses, true);

        $ownMissing = $view->missingCount;
        $hasAnyOwnData = $node->getAttributes() !== [] || trim($node->getLabel()) !== '';

        // Un nœud qui, selon le squelette, doit avoir des enfants mais n'en a pas => incomplet
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
     * @return list<array{node: Node, missing: list<string>}>
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
    private function missingFields(Node $node): array
    {
        $missing = [];
        if (trim($node->getLabel()) === '') {
            $missing[] = 'libellé';
        }
        $caps = $node->effectiveCapabilities();
        foreach (AttributeCatalog::all() as $key => $def) {
            if (!($caps[$key] ?? false) || !($def['required'] ?? false)) {
                continue;
            }
            $value = $node->getAttribute($key);
            $empty = $key === 'hours'
                ? AttributeCatalog::sumHours($value) <= 0
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
