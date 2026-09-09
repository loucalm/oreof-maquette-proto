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
        // « raccrocher » recopie un nœud d'une AUTRE formation : la copie
        // appartient à la formation du parent cible, pas à celle de la source.
        $formation = $parent?->getFormation() ?? $node->getFormation();
        $copy = $this->create(
            $formation,
            $node->getType(),
            $parent,
            $node->getLabel() !== '' ? $node->getLabel().' (copie)' : '',
            $node->getCode(),
        );
        $copy->setAttributes($node->getAttributes());
        $copy->setCapabilityOverrides($node->getCapabilityOverrides());

        $this->copyChildren($node, $copy);

        return $copy;
    }

    /**
     * Recopie récursivement les enfants. On n'utilise PAS create() ici : le
     * parent copié n'est pas encore flushé, donc pas d'ID à passer en requête
     * (nextPosition). Les positions sont simplement reprises dans l'ordre source.
     */
    private function copyChildren(Node $source, Node $target): void
    {
        $pos = 0;
        foreach ($source->getChildren() as $child) {
            $childCopy = new Node($child->getType(), $child->getLabel());
            $childCopy->setCode($child->getCode());
            $childCopy->setAttributes($child->getAttributes());
            $childCopy->setCapabilityOverrides($child->getCapabilityOverrides());
            $childCopy->setFormation($target->getFormation());
            $childCopy->setParent($target);
            $childCopy->setPosition($pos++);
            $target->addChild($childCopy);
            $target->getFormation()->addNode($childCopy);
            $this->em->persist($childCopy);

            $this->copyChildren($child, $childCopy);
        }
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
