<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MccTypeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un type de MCCC (Contrôle continu intégral, Contrôle terminal…), avec sa
 * portée par diplôme, son éventuelle collection d'épreuves pondérées et ses
 * règles de validation. Catalogue admin sur le même principe que `FieldDef`
 * et `Referentiel` : livré par les fixtures, puis entièrement éditable.
 *
 * `rules` est une liste de `{key, label, severity, node}` où `node` est un
 * petit AST interprété par `App\Rules\RuleEvaluator` — jamais une expression
 * à parser ni un `eval()`.
 */
#[ORM\Entity(repositoryClass: MccTypeRepository::class)]
#[ORM\Table(name: 'mcc_type')]
class MccType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $key;

    #[ORM\Column(length: 20)]
    private string $shortLabel;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Diplômes (clés de `referentiel('diplomes')`) pour lesquels ce type est
     * proposé. Vide = proposé pour tous les diplômes.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $diplomes = [];

    /**
     * Schéma minimal : `{"collections": {"evaluations": {"label": "..."}}}`
     * si ce type comporte une collection d'épreuves pondérées, `[]` sinon.
     * Le rendu des champs de la collection (type d'épreuve + coefficient)
     * est fixe côté gabarit pour ce MVP — le schéma ne fait qu'activer/nommer
     * la collection.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $schema = [];

    /**
     * @var list<array{key: string, label: string, severity: string, node: array<string, mixed>}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $rules = [];

    #[ORM\Column]
    private int $position = 0;

    /** Livré par les fixtures — protège seulement de la suppression. */
    #[ORM\Column]
    private bool $system = false;

    public function __construct(string $key, string $label)
    {
        $this->key = $key;
        $this->label = $label;
        $this->shortLabel = $label;
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

    public function getShortLabel(): string
    {
        return $this->shortLabel;
    }

    public function setShortLabel(string $shortLabel): self
    {
        $this->shortLabel = $shortLabel;

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = trim((string) $description) ?: null;

        return $this;
    }

    /** @return list<string> */
    public function getDiplomes(): array
    {
        return $this->diplomes;
    }

    /** @param list<string> $diplomes */
    public function setDiplomes(array $diplomes): self
    {
        $this->diplomes = array_values($diplomes);

        return $this;
    }

    /** Ce type est-il proposé pour ce diplôme (ou pour tous, si non scopé) ? */
    public function appliesToDiplome(?string $diplome): bool
    {
        return [] === $this->diplomes || (null !== $diplome && \in_array($diplome, $this->diplomes, true));
    }

    /** @return array<string, mixed> */
    public function getSchema(): array
    {
        return $this->schema;
    }

    /** @param array<string, mixed> $schema */
    public function setSchema(array $schema): self
    {
        $this->schema = $schema;

        return $this;
    }

    public function hasEvaluations(): bool
    {
        return isset($this->schema['collections']['evaluations']);
    }

    public function getEvaluationsLabel(): string
    {
        return (string) ($this->schema['collections']['evaluations']['label'] ?? 'Épreuves');
    }

    /** @return list<array{key: string, label: string, severity: string, node: array<string, mixed>}> */
    public function getRules(): array
    {
        return $this->rules;
    }

    /** @param list<array{key: string, label: string, severity: string, node: array<string, mixed>}> $rules */
    public function setRules(array $rules): self
    {
        $this->rules = array_values($rules);

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    public function setSystem(bool $system): self
    {
        $this->system = $system;

        return $this;
    }
}
