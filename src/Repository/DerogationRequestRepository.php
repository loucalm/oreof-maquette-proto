<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DerogationRequest;
use App\Entity\Formation;
use App\Enum\DerogationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DerogationRequest>
 */
class DerogationRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DerogationRequest::class);
    }

    /** @return list<DerogationRequest> en attente d'abord (les plus anciennes en tête, comme une file). */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('d')
            ->addSelect('CASE WHEN d.status = :pending THEN 0 ELSE 1 END AS HIDDEN statusRank')
            ->setParameter('pending', DerogationStatus::Pending)
            ->orderBy('statusRank', 'ASC')
            ->addOrderBy('d.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<DerogationRequest> les plus récentes d'abord. */
    public function findByFormation(Formation $formation): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.formation = :formation')
            ->setParameter('formation', $formation)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.status = :pending')
            ->setParameter('pending', DerogationStatus::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
