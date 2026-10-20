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

    /**
     * Dérogation accordée pour cette règle sur ce nœud qui couvre la valeur constatée.
     * Règles de couverture (cf. DerogationRequest::covers) : clé en `:max` → couverte si
     * actual <= valeur accordée ; en `:min` → actual >= valeur accordée ; `imposed:*` → couverte
     * sans comparaison ; toute autre clé → actual == valeur accordée, ou sans comparaison si
     * aucune valeur n'est accordée. `$actual` null (valeur inconnue) ne couvre jamais une
     * règle `:min`/`:max` : on échoue fermé.
     */
    public function grantedCovering(Formation $formation, string $ruleKey, string $scopeNid, float|int|null $actual): ?DerogationRequest
    {
        /** @var list<DerogationRequest> $candidates */
        $candidates = $this->createQueryBuilder('d')
            ->where('d.formation = :formation')
            ->andWhere('d.status = :approved')
            ->andWhere('d.ruleKey = :rule')
            ->andWhere('d.scopeNid = :scope')
            ->setParameter('formation', $formation)
            ->setParameter('approved', DerogationStatus::Approved)
            ->setParameter('rule', $ruleKey)
            ->setParameter('scope', $scopeNid)
            ->orderBy('d.resolvedAt', 'DESC')
            ->getQuery()
            ->getResult();

        foreach ($candidates as $candidate) {
            if ($candidate->covers($actual)) {
                return $candidate;
            }
        }

        return null;
    }
}
