<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MccType;
use App\Entity\StructureTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @extends ServiceEntityRepository<MccType>
 */
class MccTypeRepository extends ServiceEntityRepository implements ResetInterface
{
    /** @var array<string, MccType> mémo par requête (seulement les résultats trouvés) */
    private array $byKey = [];

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
        return $this->byKey[$key] ??= $this->findOneBy(['key' => $key]);
    }

    public function reset(): void
    {
        $this->byKey = [];
    }

    /**
     * Types proposés pour le diplôme de ce template : ceux que le template a
     * explicitement retenus (`StructureTemplate::mcccProfiles`). Sans template
     * (diplôme libre), repli permissif : tous les types du catalogue.
     *
     * @return list<MccType>
     */
    public function availableFor(?StructureTemplate $template): array
    {
        if (null === $template || [] === $template->getMcccProfiles()) {
            return $this->allOrdered();
        }

        $keys = array_flip(array_keys($template->getMcccProfiles()));

        return array_values(array_filter(
            $this->allOrdered(),
            static fn (MccType $t): bool => isset($keys[$t->getKey()]),
        ));
    }
}
