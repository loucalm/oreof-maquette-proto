<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MccType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MccType>
 */
class MccTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MccType::class);
    }

    /** @return list<MccType> */
    public function allOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.position', 'ASC')
            ->addOrderBy('t.label', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByKey(string $key): ?MccType
    {
        return $this->findOneBy(['key' => $key]);
    }

    /** @return list<MccType> types proposés pour ce diplôme, dans l'ordre d'affichage */
    public function availableFor(?string $diplome): array
    {
        return array_values(array_filter(
            $this->allOrdered(),
            static fn (MccType $t): bool => $t->appliesToDiplome($diplome),
        ));
    }
}
