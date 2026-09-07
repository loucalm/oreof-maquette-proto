<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FormationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FormationRepository::class)]
class Formation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    private string $name;

    /** Libre dans le proto : "Licence", "BUT", "Master"… */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $diplome = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $composante = null;

    /** « La formation contient-elle des parcours ? » */
    #[ORM\Column]
    private bool $multiParcours = false;

    /** Cible d'ECTS total (surtout utile en mono-parcours). */
    #[ORM\Column(nullable: true)]
    private ?int $ectsTotal = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Node> */
    #[ORM\OneToMany(targetEntity: Node::class, mappedBy: 'formation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $nodes;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->nodes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDiplome(): ?string
    {
        return $this->diplome;
    }

    public function setDiplome(?string $diplome): self
    {
        $this->diplome = $diplome;

        return $this;
    }

    public function getComposante(): ?string
    {
        return $this->composante;
    }

    public function setComposante(?string $composante): self
    {
        $this->composante = $composante;

        return $this;
    }

    public function isMultiParcours(): bool
    {
        return $this->multiParcours;
    }

    public function setMultiParcours(bool $multiParcours): self
    {
        $this->multiParcours = $multiParcours;

        return $this;
    }

    public function getEctsTotal(): ?int
    {
        return $this->ectsTotal;
    }

    public function setEctsTotal(?int $ectsTotal): self
    {
        $this->ectsTotal = $ectsTotal;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Node> */
    public function getNodes(): Collection
    {
        return $this->nodes;
    }

    public function addNode(Node $node): self
    {
        if (!$this->nodes->contains($node)) {
            $this->nodes->add($node);
            $node->setFormation($this);
        }

        return $this;
    }

    public function removeNode(Node $node): self
    {
        $this->nodes->removeElement($node);

        return $this;
    }

    /** @return list<Node> Nœuds racine (sans parent), triés. */
    public function getRootNodes(): array
    {
        $roots = array_filter($this->nodes->toArray(), static fn (Node $n) => $n->getParent() === null);
        usort($roots, static fn (Node $a, Node $b) => $a->getPosition() <=> $b->getPosition());

        return array_values($roots);
    }
}
