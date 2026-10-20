<?php

declare(strict_types=1);

namespace App\Diplome\Rule;

/**
 * Règle issue d'un gabarit, prête pour RuleEvaluator.
 *
 * `part` : 'min' ou 'max' pour les deux moitiés d'un gabarit à bornes,
 * 'single' pour un gabarit sans borne. La tâche de compilation s'en sert pour
 * composer des clés stables (ex. `level:ue:count:max`).
 */
final readonly class CompiledRule
{
    /** @param array<string, mixed> $node AST de RuleEvaluator */
    public function __construct(
        public array $node,
        public bool $blocking,
        public string $message,
        public string $part = 'single',
    ) {
    }
}
