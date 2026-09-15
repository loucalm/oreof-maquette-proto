<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Referentiel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Referentiel>
 */
class ReferentielRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Referentiel::class);
    }

    /** @return list<Referentiel> */
    public function allOrdered(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.position', 'ASC')
            ->addOrderBy('r.label', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByKey(string $key): ?Referentiel
    {
        return $this->findOneBy(['key' => $key]);
    }

    /** @return array<string, string> */
    public function values(string $key): array
    {
        return $this->findOneByKey($key)?->getValues() ?? [];
    }
}
