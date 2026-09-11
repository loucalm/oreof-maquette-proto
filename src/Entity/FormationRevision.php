<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FormationRevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Instantané d'une formation AVANT une modification (App\Maquette\Maquette).
 *
 * Sert d'historique / filet de sécurité : « Restaurer » réapplique ce
 * snapshot comme état courant (ce qui crée à son tour une révision de l'état
 * qu'il remplace — une restauration reste elle-même annulable).
 */
#[ORM\Entity(repositoryClass: FormationRevisionRepository::class)]
#[ORM\Table(name: 'formation_revision')]
#[ORM\Index(fields: ['formation', 'createdAt'], name: 'idx_revision_formation_created')]
class FormationRevision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Formation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Formation $formation;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Description courte auto-générée (ex. « Ajout d'un nœud », « Bascule en multi-parcours »). */
    #[ORM\Column(length: 160)]
    private string $label;

    /**
     * État complet restaurable : name/diplome/domaine/composante/multiParcours/
     * ectsTotal/calendarSpan/calendarUnit/structure/parametres/dataParcours/arbre.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $snapshot;

    /** @param array<string, mixed> $snapshot */
    public function __construct(Formation $formation, string $label, array $snapshot)
    {
        $this->formation = $formation;
        $this->label = $label;
        $this->snapshot = $snapshot;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFormation(): Formation
    {
        return $this->formation;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /** @return array<string, mixed> */
    public function getSnapshot(): array
    {
        return $this->snapshot;
    }
}
