<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TypeDiplome;
use App\Entity\TypeDiplomeVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TypeDiplomeVersion>
 */
class TypeDiplomeVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TypeDiplomeVersion::class);
    }

    /**
     * Version applicable à une année : celle de l'année, sinon la plus récente
     * dont l'année de début est antérieure, sinon null.
     */
    public function findForYear(TypeDiplome $type, string $annee): ?TypeDiplomeVersion
    {
        $year = (int) substr($annee, 0, 4);
        $best = null;
        foreach ($type->getVersions() as $version) {
            if ($version->getAnneeUniversitaire() === $annee) {
                return $version;
            }
            if ($version->startYear() < $year && (null === $best || $version->startYear() > $best->startYear())) {
                $best = $version;
            }
        }

        return $best;
    }
}
