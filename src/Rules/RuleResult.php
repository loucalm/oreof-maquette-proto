<?php

declare(strict_types=1);

namespace App\Rules;

/**
 * Diagnostic structuré produit par une règle évaluée — jamais un simple booléen.
 * Exploitable tel quel par une interface (message, gravité, valeurs attendue/réelle).
 */
final readonly class RuleResult
{
    public function __construct(
        public string $ruleKey,
        public bool $valid,
        public string $severity,
        public string $message,
        public mixed $expected = null,
        public mixed $actual = null,
        public string $path = '',
    ) {
    }
}
