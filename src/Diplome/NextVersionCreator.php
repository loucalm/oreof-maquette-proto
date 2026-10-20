<?php

declare(strict_types=1);

namespace App\Diplome;

use App\Entity\TypeDiplome;
use App\Entity\TypeDiplomeVersion;
use Doctrine\ORM\EntityManagerInterface;

/** Crée la version de l'année suivante d'un type de diplôme, par copie de sa dernière version. */
final class NextVersionCreator
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @throws \DomainException si le type n'a aucune version à copier */
    public function create(TypeDiplome $type): TypeDiplomeVersion
    {
        $latest = $type->latestVersion() ?? throw new \DomainException('Le type de diplôme n’a aucune version à copier.');
        $start = $latest->startYear() + 1;

        $version = (new TypeDiplomeVersion($type, $start.'-'.($start + 1)))
            ->setConfig($latest->config())
            ->setBasedOn($latest->getBasedOn());
        $type->addVersion($version);
        $this->em->persist($version);
        $this->em->flush();

        return $version;
    }
}
