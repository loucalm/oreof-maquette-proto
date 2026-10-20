<?php

declare(strict_types=1);

namespace App\Diplome\Rule;

/**
 * Paramètre d'un gabarit de règles (l'assistant en génère un champ de formulaire).
 * Types : int, string, typeKey (clé de type d'ELP), nature (nature d'élément),
 * attribute (attribut de nœud, ex. ects), op (opérateur de comparaison).
 */
final readonly class RuleParam
{
    public const TYPES = ['int', 'string', 'typeKey', 'nature', 'attribute', 'op'];

    public function __construct(
        public string $name,
        public string $label,
        public string $type,
        public bool $required = true,
    ) {
    }
}
