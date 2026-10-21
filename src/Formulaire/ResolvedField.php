<?php

declare(strict_types=1);

namespace App\Formulaire;

use App\Entity\FieldDef;

/**
 * Champ d'un formulaire tel qu'il s'affiche : la définition partagée (FieldDef) et les
 * réglages effectifs de CE formulaire (libellé, aide, obligatoire, onglet, position),
 * résolus le long de sa lignée (cf. FormResolver). `origins` dit en quoi il diffère de
 * son formulaire de départ, pour l'écran « Adapter ».
 */
final readonly class ResolvedField
{
    public const INHERITED = 'inherited';
    public const ADDED = 'added';
    public const HIDDEN = 'hidden';
    public const LABEL = 'label';
    public const HELP = 'help';
    public const REQUIRED = 'required';
    public const OPTIONAL = 'optional';
    public const MOVED = 'moved';

    /** @param list<string> $origins */
    public function __construct(
        public FieldDef $def,
        public string $label,
        public ?string $help,
        public bool $required,
        public string $tab,
        public int $position,
        public bool $hidden,
        public array $origins,
    ) {
    }

    public function getId(): ?int
    {
        return $this->def->getId();
    }

    public function getKey(): string
    {
        return $this->def->getKey();
    }

    public function getType(): string
    {
        return $this->def->getType();
    }

    public function isLocked(): bool
    {
        return $this->def->isLocked();
    }

    public function getMin(): ?float
    {
        return $this->def->getMin();
    }

    public function getMax(): ?float
    {
        return $this->def->getMax();
    }

    public function getPlaceholder(): ?string
    {
        return $this->def->getPlaceholder();
    }

    public function getReferentielKey(): ?string
    {
        return $this->def->getReferentielKey();
    }

    public function isAllowExtra(): bool
    {
        return $this->def->isAllowExtra();
    }

    public function isQuickAdd(): bool
    {
        return $this->def->isQuickAdd();
    }

    /** @return array<string, string> */
    public function getOptions(): array
    {
        return $this->def->getOptions();
    }
}
