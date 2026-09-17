<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NodeType;
use App\Enum\NodeFamily;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NodeType>
 */
class NodeTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NodeType::class);
    }

    public function findOneByKey(string $key): ?NodeType
    {
        return $this->findOneBy(['key' => $key]);
    }

    /** @return list<NodeType> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.position', 'ASC')
            ->addOrderBy('t.label', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Indexé par clé.
     *
     * @return array<string, NodeType>
     */
    public function findAllIndexed(): array
    {
        $map = [];
        foreach ($this->findAll() as $type) {
            $map[$type->getKey()] = $type;
        }

        return $map;
    }

    /**
     * Types utilisables dans un arbre pédagogique (squelette de formation ou de
     * template) : exclut la famille `Parameter` (sections fixes Organisation/
     * Présentation/Configuration/BCC, jamais des nœuds d'arbre) — même principe
     * que le filtrage de la famille `Competence` hors de l'arbre pédagogique.
     *
     * @return list<NodeType>
     */
    public function forTree(): array
    {
        return array_values(array_filter(
            $this->findAllOrdered(),
            static fn (NodeType $t) => NodeFamily::Parameter !== $t->getFamily(),
        ));
    }

    /** Nombre d'autres types qui portent encore cette capacité (pour savoir si un FieldDef partagé peut être supprimé). */
    public function countUsingCapability(string $key, ?string $excludeTypeKey = null): int
    {
        $count = 0;
        foreach ($this->findAll() as $type) {
            if ($type->getKey() === $excludeTypeKey) {
                continue;
            }
            if ($type->hasCapability($key)) {
                ++$count;
            }
        }

        return $count;
    }
}
