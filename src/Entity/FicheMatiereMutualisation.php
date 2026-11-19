<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FicheMatiereMutualisationRepository;
use Doctrine\ORM\Mapping as ORM;

/** Un parcours autorisé à utiliser une fiche matière qu'il ne porte pas. */
#[ORM\Entity(repositoryClass: FicheMatiereMutualisationRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_fiche_mutualisation', fields: ['fiche', 'formation', 'parcoursNid'])]
class FicheMatiereMutualisation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private FicheMatiere $fiche,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Formation $formation,
        /** Nœud parcours autorisé ; null pour le parcours unique d'une formation sans parcours. */
        #[ORM\Column(length: 64, nullable: true)]
        private ?string $parcoursNid = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFiche(): FicheMatiere
    {
        return $this->fiche;
    }

    public function getFormation(): Formation
    {
        return $this->formation;
    }

    public function getParcoursNid(): ?string
    {
        return $this->parcoursNid;
    }
}
