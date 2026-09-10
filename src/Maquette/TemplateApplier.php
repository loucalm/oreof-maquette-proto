<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Entity\StructureTemplate;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;

/**
 * Applique un modèle de structure à une maquette (MaquetteDoc).
 *
 * ⚠️ Écrase la structure existante (comportement voulu, confirmé côté UI).
 * L'arbre instancié reste ensuite librement modifiable.
 */
final class TemplateApplier
{
    public function apply(MaquetteDoc $doc, StructureTemplate $template): void
    {
        $doc->formation
            ->setMultiParcours($template->isMultiParcours())
            ->setStructure($this->chainFromTree($template->getTree()));

        $doc->roots = [];
        $doc->parcours = [];
        $doc->index = [];

        foreach ($template->getTree() as $spec) {
            $node = $this->instantiate($doc, (array) $spec);
            if ($node !== null) {
                $doc->roots[] = $node;
            }
        }
        $doc->reindex();
    }

    /**
     * Déduit le squelette « corps » (hors parcours) d'un arbre de template :
     * le premier type rencontré à chaque profondeur.
     *
     * @param list<array<string, mixed>> $tree
     *
     * @return list<string>
     */
    public function chainFromTree(array $tree): array
    {
        $byDepth = [];
        $walk = static function (array $nodes, int $depth) use (&$walk, &$byDepth): void {
            foreach ($nodes as $spec) {
                $type = (string) (\is_array($spec) ? ($spec['type'] ?? '') : '');
                if ($type !== '') {
                    $byDepth[$depth] ??= $type;
                }
                $children = (array) (\is_array($spec) ? ($spec['children'] ?? []) : []);
                if ($children !== []) {
                    $walk($children, $depth + 1);
                }
            }
        };
        $walk($tree, 0);
        ksort($byDepth);

        return array_values(array_filter($byDepth, static fn (string $k) => $k !== 'parcours'));
    }

    /** @param array<string, mixed> $spec */
    private function instantiate(MaquetteDoc $doc, array $spec): ?TreeNode
    {
        $typeKey = (string) ($spec['type'] ?? '');
        $type = $doc->type($typeKey);
        if ($type === null) {
            return null; // type inconnu : ignoré silencieusement (proto)
        }

        $node = new TreeNode(
            nid: $doc->newNid(),
            typeKey: $typeKey,
            label: (string) ($spec['label'] ?? ''),
            code: ($spec['code'] ?? null) !== null && $spec['code'] !== '' ? (string) $spec['code'] : null,
            attributes: \is_array($spec['attributes'] ?? null) ? $spec['attributes'] : [],
            locked: array_values(array_filter((array) ($spec['locked'] ?? []), 'is_string')),
        );
        $node->bindType($type);
        $node->doc = $doc;
        $doc->index[$node->nid] = $node;

        foreach ((array) ($spec['children'] ?? []) as $childSpec) {
            $child = $this->instantiate($doc, (array) $childSpec);
            if ($child !== null) {
                $child->parent = $node;
                $node->children[] = $child;
            }
        }

        return $node;
    }
}
