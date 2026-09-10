<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StructureTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StructureTemplate>
 */
class StructureTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StructureTemplate::class);
    }

    public function findOneByKey(string $key): ?StructureTemplate
    {
        return $this->findOneBy(['key' => $key]);
    }

    public function findOneByDiplome(string $diplome): ?StructureTemplate
    {
        return $this->findOneBy(['diplome' => $diplome]);
    }

    /** @return list<StructureTemplate> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')->orderBy('t.label', 'ASC')->getQuery()->getResult();
    }
}
