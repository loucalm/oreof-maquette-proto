<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Formation;
use App\Entity\StructureTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @extends ServiceEntityRepository<StructureTemplate>
 */
class StructureTemplateRepository extends ServiceEntityRepository implements ResetInterface
{
    /** @var array<string, StructureTemplate> mémo par requête (seulement les résultats trouvés) */
    private array $byDiplome = [];

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
        return $this->byDiplome[$diplome] ??= $this->findOneBy(['diplome' => $diplome]);
    }

    /** Template du diplôme de la formation (celui qui en fixe la structure et le format des BCC). */
    public function forFormation(Formation $formation): ?StructureTemplate
    {
        return null !== $formation->getDiplome() ? $this->findOneByDiplome($formation->getDiplome()) : null;
    }

    public function reset(): void
    {
        $this->byDiplome = [];
    }

    /** @return list<StructureTemplate> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')->orderBy('t.label', 'ASC')->getQuery()->getResult();
    }
}
