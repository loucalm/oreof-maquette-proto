<?php

declare(strict_types=1);

namespace App\Entity;

use App\Diplome\Spec\VersionConfig;
use App\Repository\TypeDiplomeVersionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Configuration d'un type de diplôme pour une année universitaire (`AAAA-AAAA`).
 * `config` est le JSON de `VersionConfig` (lecture typée via `config()`).
 */
#[ORM\Entity(repositoryClass: TypeDiplomeVersionRepository::class)]
#[ORM\Table(name: 'type_diplome_version')]
#[ORM\UniqueConstraint(name: 'uniq_type_diplome_annee', columns: ['type_diplome_id', 'annee_universitaire'])]
class TypeDiplomeVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'versions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TypeDiplome $typeDiplome;

    /** Format `AAAA-AAAA`, ex. `2026-2027`. */
    #[ORM\Column(length: 9)]
    private string $anneeUniversitaire;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $config = [];

    /** Type de départ de la version, « clé@année » (ex. `licence@2025-2026`). */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $basedOn = null;

    public function __construct(TypeDiplome $typeDiplome, string $anneeUniversitaire)
    {
        $this->typeDiplome = $typeDiplome;
        $this->anneeUniversitaire = $anneeUniversitaire;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTypeDiplome(): TypeDiplome
    {
        return $this->typeDiplome;
    }

    public function setTypeDiplome(TypeDiplome $typeDiplome): self
    {
        $this->typeDiplome = $typeDiplome;

        return $this;
    }

    public function getAnneeUniversitaire(): string
    {
        return $this->anneeUniversitaire;
    }

    public function setAnneeUniversitaire(string $anneeUniversitaire): self
    {
        $this->anneeUniversitaire = $anneeUniversitaire;

        return $this;
    }

    public function config(): VersionConfig
    {
        return VersionConfig::fromArray($this->config);
    }

    public function setConfig(VersionConfig $config): self
    {
        $this->config = $config->toArray();

        return $this;
    }

    public function getBasedOn(): ?string
    {
        return $this->basedOn;
    }

    public function setBasedOn(?string $basedOn): self
    {
        $this->basedOn = $basedOn;

        return $this;
    }

    /** Référence « clé@année » (valeur de `basedOn` des versions qui en partent). */
    public function reference(): string
    {
        return $this->typeDiplome->getKey().'@'.$this->anneeUniversitaire;
    }

    /** Première année de `anneeUniversitaire` (2026 pour `2026-2027`). */
    public function startYear(): int
    {
        return (int) substr($this->anneeUniversitaire, 0, 4);
    }
}
