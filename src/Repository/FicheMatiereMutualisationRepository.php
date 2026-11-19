<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FicheMatiereMutualisation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FicheMatiereMutualisation>
 */
class FicheMatiereMutualisationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FicheMatiereMutualisation::class);
    }
}
