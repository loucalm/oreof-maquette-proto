<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Entity\Formation;
use App\Entity\Node;
use App\Entity\StructureTemplate;
use App\Repository\NodeTypeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applique un modèle de structure à une formation.
 *
 * ⚠️ Écrase la structure existante (comportement voulu, confirmé côté UI).
 * L'arbre instancié reste ensuite librement modifiable.
 */
final class TemplateApplier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NodeTypeRepository $types,
    ) {
    }

    public function apply(Formation $formation, StructureTemplate $template): void
    {
        foreach ($formation->getRootNodes() as $root) {
            $this->em->remove($root);
        }
        $formation->getNodes()->clear();
        $this->em->flush();

        $formation->setMultiParcours($template->isMultiParcours());

        $typeMap = $this->types->findAllIndexed();
        foreach ($template->getTree() as $i => $spec) {
            $this->instantiate($formation, null, $spec, $i, $typeMap);
        }
    }

    /**
     * @param array<string, mixed>    $spec
     * @param array<string, \App\Entity\NodeType> $typeMap
     */
    private function instantiate(Formation $formation, ?Node $parent, array $spec, int $position, array $typeMap): void
    {
        $typeKey = (string) ($spec['type'] ?? '');
        $type = $typeMap[$typeKey] ?? null;
        if ($type === null) {
            return; // type inconnu : on ignore silencieusement dans le proto
        }

        $node = new Node($type, (string) ($spec['label'] ?? ''));
        $node->setCode($spec['code'] ?? null);
        $node->setAttributes((array) ($spec['attributes'] ?? []));
        $node->setPosition($position);
        $node->setFormation($formation);
        $node->setParent($parent);
        $formation->addNode($node);
        $this->em->persist($node);

        foreach ((array) ($spec['children'] ?? []) as $j => $childSpec) {
            $this->instantiate($formation, $node, (array) $childSpec, $j, $typeMap);
        }
    }
}
