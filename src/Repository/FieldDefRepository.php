<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FieldDef;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FieldDef>
 */
class FieldDefRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FieldDef::class);
    }

    /** @return list<FieldDef> */
    public function allOrdered(): array
    {
        return $this->createQueryBuilder('f')
            ->orderBy('f.position', 'ASC')
            ->addOrderBy('f.tab', 'ASC')
            ->addOrderBy('f.label', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByKey(string $key): ?FieldDef
    {
        return $this->findOneBy(['key' => $key]);
    }
}
