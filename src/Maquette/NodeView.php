<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Entity\Node;

/**
 * Vue calculée d'un nœud pour l'affichage : statut de complétude + agrégats
 * (heures et ECTS remontés des descendants).
 */
final class NodeView
{
    public const STATUS_EMPTY = 'empty';       // rien de saisi
    public const STATUS_INCOMPLETE = 'incomplete'; // partiellement saisi / enfant en défaut
    public const STATUS_OK = 'ok';

    /** @param list<NodeView> $children */
    public function __construct(
        public readonly Node $node,
        public array $children = [],
        public string $status = self::STATUS_EMPTY,
        public float $totalHours = 0.0,
        public float $totalEcts = 0.0,
        /** Nombre d'attributs requis manquants sur ce nœud seul. */
        public int $missingCount = 0,
    ) {
    }

    public function id(): ?int
    {
        return $this->node->getId();
    }

    public function hasChildren(): bool
    {
        return $this->children !== [];
    }

    /** ECTS cible du type, s'il y en a une (30 semestre, 60 année…). */
    public function ectsTarget(): ?int
    {
        return $this->node->getType()->getEctsTarget();
    }

    public function ectsReached(): bool
    {
        $target = $this->ectsTarget();

        return $target !== null && abs($this->totalEcts - $target) < 0.01;
    }
}
