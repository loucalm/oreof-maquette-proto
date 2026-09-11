<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Formation;
use App\Entity\FormationRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FormationRevision>
 */
class FormationRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormationRevision::class);
    }

    /** @return list<FormationRevision> les plus récentes d'abord */
    public function findRecentFor(Formation $formation, int $limit = 100): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.formation = :f')
            ->setParameter('f', $formation)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Ne garde que les $keep révisions les plus récentes de cette formation. */
    public function pruneOlderThan(Formation $formation, int $keep): void
    {
        $ids = $this->createQueryBuilder('r')
            ->select('r.id')
            ->where('r.formation = :f')
            ->setParameter('f', $formation)
            ->orderBy('r.createdAt', 'DESC')
            ->setFirstResult($keep)
            ->getQuery()
            ->getSingleColumnResult();

        if ($ids === []) {
            return;
        }
        $this->createQueryBuilder('r')
            ->delete()
            ->where('r.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    public function deleteAllFor(Formation $formation): void
    {
        $this->createQueryBuilder('r')
            ->delete()
            ->where('r.formation = :f')
            ->setParameter('f', $formation)
            ->getQuery()
            ->execute();
    }
}
