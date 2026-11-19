<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FicheMatiere;
use App\Entity\FicheMatiereMutualisation;
use App\Entity\Formation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FicheMatiere>
 */
class FicheMatiereRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FicheMatiere::class);
    }

    /** Fiches portées par un parcours (`null` = parcours unique d'une formation sans parcours). @return list<FicheMatiere> */
    public function portedBy(Formation $f, ?string $parcoursNid): array
    {
        $qb = $this->sorted()->andWhere('x.formation = :f')->setParameter('f', $f);

        return $this->whereParcours($qb, 'x.parcoursNid', $parcoursNid)->getQuery()->getResult();
    }

    /** @return list<FicheMatiere> */
    public function horsDiplome(): array
    {
        return $this->sorted()->andWhere('x.horsDiplome = true')->getQuery()->getResult();
    }

    /** Fiches portées ailleurs et mutualisées vers ce parcours. @return list<FicheMatiere> */
    public function mutualisedTo(Formation $f, ?string $parcoursNid): array
    {
        $qb = $this->sorted()
            ->innerJoin(FicheMatiereMutualisation::class, 'm', 'WITH', 'm.fiche = x')
            ->andWhere('m.formation = :f')
            ->setParameter('f', $f);

        return $this->whereParcours($qb, 'm.parcoursNid', $parcoursNid)->getQuery()->getResult();
    }

    /** Toutes les fiches portées par une formation, tous parcours confondus. @return list<FicheMatiere> */
    public function ofFormation(Formation $f): array
    {
        return $this->sorted()->andWhere('x.formation = :f')->setParameter('f', $f)->getQuery()->getResult();
    }

    /** @return list<FicheMatiere> */
    public function search(string $q, int $limit = 20): array
    {
        $qb = $this->sorted()->setMaxResults($limit);
        if ('' !== trim($q)) {
            $qb->andWhere("LOWER(x.libelle) LIKE :q ESCAPE '\\'")
                ->setParameter('q', '%'.addcslashes(mb_strtolower(trim($q)), '%_\\').'%');
        }

        return $qb->getQuery()->getResult();
    }

    private function sorted(): QueryBuilder
    {
        return $this->createQueryBuilder('x')->orderBy('x.libelle', 'ASC')->addOrderBy('x.id', 'ASC');
    }

    private function whereParcours(QueryBuilder $qb, string $field, ?string $nid): QueryBuilder
    {
        return null === $nid
            ? $qb->andWhere($field.' IS NULL')
            : $qb->andWhere($field.' = :nid')->setParameter('nid', $nid);
    }
}
