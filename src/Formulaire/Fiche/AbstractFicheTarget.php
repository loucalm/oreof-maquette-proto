<?php

declare(strict_types=1);

namespace App\Formulaire\Fiche;

use App\Entity\Formation;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;

/** Socle commun des fiches : la formation et sa maquette ouverte. */
abstract class AbstractFicheTarget implements FicheTarget
{
    public function __construct(
        protected readonly Formation $formation,
        protected readonly MaquetteDoc $doc,
    ) {
    }

    public function formation(): Formation
    {
        return $this->formation;
    }

    public function parcours(): ?TreeNode
    {
        return null;
    }

    public function doc(): MaquetteDoc
    {
        return $this->doc;
    }

    public function value(string $fieldKey, array $data): mixed
    {
        $bound = $this->bound();

        return \array_key_exists($fieldKey, $bound) ? $bound[$fieldKey] : ($data[$fieldKey] ?? null);
    }

    /** Texte posté nettoyé, null s'il est vide. */
    protected static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }

    /** Entier posté, null s'il est vide. */
    protected static function int(mixed $value): ?int
    {
        return null === $value || '' === $value ? null : (int) $value;
    }
}
