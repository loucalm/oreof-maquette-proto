<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Formation;
use App\Entity\Node;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Node>
 */
class NodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Node::class);
    }

    /**
     * Tous les nœuds d'une formation, avec leur type, en une requête.
     *
     * @return list<Node>
     */
    public function findForFormation(Formation $formation): array
    {
        return $this->createQueryBuilder('n')
            ->addSelect('t')
            ->join('n.type', 't')
            ->where('n.formation = :f')
            ->setParameter('f', $formation)
            ->orderBy('n.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function nextPosition(Formation $formation, ?Node $parent): int
    {
        $qb = $this->createQueryBuilder('n')
            ->select('MAX(n.position)')
            ->where('n.formation = :f')
            ->setParameter('f', $formation);

        if ($parent === null) {
            $qb->andWhere('n.parent IS NULL');
        } else {
            $qb->andWhere('n.parent = :p')->setParameter('p', $parent);
        }

        $max = $qb->getQuery()->getSingleScalarResult();

        return $max === null ? 0 : ((int) $max) + 1;
    }

    /** @return list<Node> Nœuds mutualisés d'autres formations. */
    public function findMutualized(?Formation $exclude = null): array
    {
        $qb = $this->createQueryBuilder('n')
            ->addSelect('t')
            ->join('n.type', 't')
            ->where('n.mutualized = true')
            ->orderBy('n.label', 'ASC');

        if ($exclude !== null) {
            $qb->andWhere('n.formation != :f')->setParameter('f', $exclude);
        }

        return $qb->getQuery()->getResult();
    }
}
