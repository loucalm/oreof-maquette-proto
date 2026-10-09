<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Entity\StructureTemplate;
use App\Repository\NodeTypeRepository;

/**
 * Lecture « modèle générique » d'un template : pour chaque couple parent → enfant
 * (ex. Semestre → UE), combien d'enfants le template prévoit, si ce nombre est
 * imposé (verrou d'ajout) ou un simple point de départ, et quels libellés servent
 * d'exemples. Sépare ce que le diplôme impose de ce qui n'est qu'illustratif dans l'arbre.
 */
final class TemplateSummary
{
    public function __construct(private readonly NodeTypeRepository $types)
    {
    }

    /**
     * @return list<array{parent: string, child: string, min: int, max: int, fixed: bool, examples: list<string>, next: ?string}>
     */
    public function rows(StructureTemplate $template): array
    {
        $groups = [];
        $this->walk($template->getTree(), 'formation', $groups);

        $types = $this->types->findAllIndexed();
        $label = static fn (string $key) => 'formation' === $key ? 'Formation' : ($types[$key]?->getLabel() ?? $key);
        $chain = $template->getStructure();

        $rows = [];
        foreach ($groups as $g) {
            $examples = [];
            if (!($types[$g['child']]?->isTemporel() ?? false)) {
                $examples = \array_slice(array_values(array_unique(array_filter($g['labels']))), 0, 4);
            }
            $pos = array_search($g['child'], $chain, true);
            $next = false !== $pos ? ($chain[$pos + 1] ?? null) : ($chain[0] ?? null);
            $rows[] = [
                'parent' => $label($g['parent']),
                'child' => $label($g['child']),
                'min' => min($g['counts']),
                'max' => max($g['counts']),
                'fixed' => $g['fixed'],
                'examples' => $examples,
                'next' => null !== $next ? $label($next) : null,
            ];
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>>                                                                    $nodes
     * @param array<string, array{parent: string, child: string, counts: list<int>, fixed: bool, labels: list<string>}> $groups
     */
    private function walk(array $nodes, string $parentType, array &$groups): void
    {
        $byType = [];
        foreach ($nodes as $n) {
            $byType[(string) ($n['type'] ?? '')][] = $n;
        }
        foreach ($byType as $type => $siblings) {
            $key = $parentType.'>'.$type;
            $groups[$key] ??= ['parent' => $parentType, 'child' => $type, 'counts' => [], 'fixed' => true, 'labels' => []];
            $groups[$key]['counts'][] = \count($siblings);
            foreach ($siblings as $n) {
                // nombre imposé seulement si chaque frère porte le verrou d'ajout (cf. TreeNode::isLocked('add'))
                $groups[$key]['fixed'] = $groups[$key]['fixed'] && \in_array('add', (array) ($n['locked'] ?? []), true);
                $groups[$key]['labels'][] = (string) ($n['label'] ?? '');
                $this->walk((array) ($n['children'] ?? []), $type, $groups);
            }
        }
    }
}
