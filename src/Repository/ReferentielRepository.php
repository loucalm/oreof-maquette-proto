<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Referentiel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @extends ServiceEntityRepository<Referentiel>
 */
class ReferentielRepository extends ServiceEntityRepository implements ResetInterface
{
    /** @var array<string, array<string, string>> mémo par requête des valeurs (un appel par champ affiché) */
    private array $valuesCache = [];

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
        return $this->valuesCache[$key] ??= $this->findOneByKey($key)?->getValues() ?? [];
    }

    public function reset(): void
    {
        $this->valuesCache = [];
    }
}
