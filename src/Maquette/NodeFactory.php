<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Entity\Formation;
use App\Entity\Node;
use App\Entity\NodeType;
use App\Repository\NodeRepository;
use App\Repository\NodeTypeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Création / déplacement / duplication de nœuds, avec renumérotation des
 * positions entre frères.
 */
final class NodeFactory
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NodeRepository $nodes,
        private readonly NodeTypeRepository $types,
    ) {
    }

    public function create(Formation $formation, NodeType $type, ?Node $parent, string $label = '', ?string $code = null): Node
    {
        $node = new Node($type, $label);
        $node->setCode($code);
        $node->setFormation($formation);
        $node->setParent($parent);
        $node->setPosition($this->nodes->nextPosition($formation, $parent));
        $formation->addNode($node);
        $this->em->persist($node);

        return $node;
    }

    /**
     * Déplace un nœud sous un nouveau parent (ou racine) à un index donné,
     * puis compacte les positions des frères source et cible.
     */
    public function move(Node $node, ?Node $newParent, int $index): void
    {
        $oldParent = $node->getParent();
        $node->setParent($newParent);

        $siblings = $this->siblingsOf($node->getFormation(), $newParent, exclude: $node);
        array_splice($siblings, max(0, min($index, \count($siblings))), 0, [$node]);
        $this->renumber($siblings);

        if ($oldParent !== $newParent) {
            $this->renumber($this->siblingsOf($node->getFormation(), $oldParent, exclude: $node));
        }
    }

    public function duplicate(Node $node, ?Node $parent = null): Node
    {
        $parent ??= $node->getParent();
        $copy = $this->create(
            $node->getFormation(),
            $node->getType(),
            $parent,
            $node->getLabel() !== '' ? $node->getLabel().' (copie)' : '',
            $node->getCode(),
        );
        $copy->setAttributes($node->getAttributes());
        $copy->setCapabilityOverrides($node->getCapabilityOverrides());

        foreach ($node->getChildren() as $child) {
            $this->duplicate($child, $copy);
        }

        return $copy;
    }

    /**
     * @return list<Node>
     */
    private function siblingsOf(Formation $formation, ?Node $parent, Node $exclude): array
    {
        $all = $this->nodes->findForFormation($formation);
        $list = array_filter(
            $all,
            static fn (Node $n) => $n !== $exclude
                && ($n->getParent()?->getId() ?? null) === ($parent?->getId() ?? null),
        );
        usort($list, static fn (Node $a, Node $b) => $a->getPosition() <=> $b->getPosition());

        return array_values($list);
    }

    /** @param list<Node> $ordered */
    private function renumber(array $ordered): void
    {
        foreach ($ordered as $i => $node) {
            $node->setPosition($i);
        }
    }
}
