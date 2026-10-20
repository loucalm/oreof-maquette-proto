<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TypeDiplome;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TypeDiplome>
 */
class TypeDiplomeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TypeDiplome::class);
    }

    public function findOneByKey(string $key): ?TypeDiplome
    {
        return $this->findOneBy(['key' => $key]);
    }

    /** @return list<TypeDiplome> */
    public function findAllOrdered(bool $withArchived = true): array
    {
        $qb = $this->createQueryBuilder('t')->orderBy('t.label', 'ASC');
        if (!$withArchived) {
            $qb->andWhere('t.archived = false');
        }

        return $qb->getQuery()->getResult();
    }
}
