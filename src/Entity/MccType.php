<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MccTypeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un type de MCCC (Contrôle continu intégral, Contrôle terminal…) : une
 * identité stable (libellé, description, collection d'épreuves pondérées ou
 * non) et une liste de **profils de règles** nommés (ex. « Licence », «
 * Master ») — un même type peut se comporter différemment selon le diplôme
 * sans dupliquer sa fiche. Le rattachement type↔profil pour un diplôme donné
 * se décide au niveau du template (`StructureTemplate::mcccProfiles`), pas ici.
 * Catalogue admin sur le même principe que `FieldDef` et `Referentiel` :
 * livré par les fixtures, puis entièrement éditable.
 *
 * Chaque profil porte une liste de règles `{key, label, severity, node}` où
 * `node` est un petit AST interprété par `App\Rules\RuleEvaluator` — jamais
 * une expression à parser ni un `eval()`.
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
     * @var list<array{key: string, label: string, description?: ?string, rules: list<array{key: string, label: string, severity: string, node: array<string, mixed>}>}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $profiles = [];

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

    /** @return list<array{key: string, label: string, description?: ?string, rules: list<array<string, mixed>>}> */
    public function getProfiles(): array
    {
        return $this->profiles;
    }

    /** @param list<array{key: string, label: string, description?: ?string, rules: list<array<string, mixed>>}> $profiles */
    public function setProfiles(array $profiles): self
    {
        $this->profiles = array_values($profiles);

        return $this;
    }

    /** @return array{key: string, label: string, description?: ?string, rules: list<array<string, mixed>>}|null */
    public function getProfile(string $key): ?array
    {
        foreach ($this->profiles as $profile) {
            if (($profile['key'] ?? null) === $key) {
                return $profile;
            }
        }

        return null;
    }

    /** Règles du profil demandé, ou tableau vide si le profil n'existe pas (comportement permissif). */
    public function getProfileRules(?string $key): array
    {
        return $this->getProfile((string) $key)['rules'] ?? [];
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
