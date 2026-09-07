<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NodeType;
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
}
