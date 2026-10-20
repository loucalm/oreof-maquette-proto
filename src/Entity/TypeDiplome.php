<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TypeDiplomeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Type de diplôme configurable (Licence, Master, BUT…). L'identité est stable ;
 * la configuration (structure, formulaires, MCCC…) vit dans une version par
 * année universitaire (`TypeDiplomeVersion`).
 */
#[ORM\Entity(repositoryClass: TypeDiplomeRepository::class)]
#[ORM\Table(name: 'type_diplome')]
class TypeDiplome
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Sigle normalisé, identifiant stable. */
    #[ORM\Column(name: '`key`', length: 64, unique: true)]
    private string $key;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\Column(length: 40)]
    private string $sigle;

    /** Code Apogée : un seul caractère. */
    #[ORM\Column(length: 1, nullable: true)]
    private ?string $codeApogee = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $accredite = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $passageCfvu = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $archived = false;

    /** @var Collection<int, TypeDiplomeVersion> */
    #[ORM\OneToMany(targetEntity: TypeDiplomeVersion::class, mappedBy: 'typeDiplome', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['anneeUniversitaire' => 'ASC'])]
    private Collection $versions;

    public function __construct(string $key, string $label, string $sigle)
    {
        $this->key = $key;
        $this->label = $label;
        $this->sigle = $sigle;
        $this->versions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): self
    {
        $this->key = $key;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getSigle(): string
    {
        return $this->sigle;
    }

    public function setSigle(string $sigle): self
    {
        $this->sigle = $sigle;

        return $this;
    }

    public function getCodeApogee(): ?string
    {
        return $this->codeApogee;
    }

    public function setCodeApogee(?string $codeApogee): self
    {
        $this->codeApogee = $codeApogee;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function isAccredite(): bool
    {
        return $this->accredite;
    }

    public function setAccredite(bool $accredite): self
    {
        $this->accredite = $accredite;

        return $this;
    }

    public function isPassageCfvu(): bool
    {
        return $this->passageCfvu;
    }

    public function setPassageCfvu(bool $passageCfvu): self
    {
        $this->passageCfvu = $passageCfvu;

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function setArchived(bool $archived): self
    {
        $this->archived = $archived;

        return $this;
    }

    /**
     * Versions triées par année universitaire croissante.
     *
     * @return list<TypeDiplomeVersion>
     */
    public function getVersions(): array
    {
        $versions = $this->versions->toArray();
        usort($versions, static fn (TypeDiplomeVersion $a, TypeDiplomeVersion $b) => $a->getAnneeUniversitaire() <=> $b->getAnneeUniversitaire());

        return $versions;
    }

    public function addVersion(TypeDiplomeVersion $version): self
    {
        if (!$this->versions->contains($version)) {
            $this->versions->add($version);
            $version->setTypeDiplome($this);
        }

        return $this;
    }

    /** Version la plus récente (dernière année universitaire), ou null. */
    public function latestVersion(): ?TypeDiplomeVersion
    {
        $versions = $this->getVersions();

        return [] === $versions ? null : $versions[array_key_last($versions)];
    }
}
