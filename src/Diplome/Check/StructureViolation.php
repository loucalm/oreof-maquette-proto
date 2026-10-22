<?php

declare(strict_types=1);

namespace App\Diplome\Check;

/**
 * Écart d'une maquette à la configuration de sa version de type de diplôme.
 *
 * `ruleKey` est stable (contrat avec le blocage des modifications) :
 * `level:<typeKey>:count|ects|ectsSum:min|max`, `duration:min|max`,
 * `imposed:<uid>`, `lock:<typeKey>`, `rule:<uid>:min|max` (ou `rule:<uid>` sans borne).
 * `scopeNid` : nœud concerné ('' pour la racine d'une maquette mono-parcours).
 */
final readonly class StructureViolation
{
    public function __construct(
        public string $ruleKey,
        public string $scopeNid,
        public string $message,
        public bool $blocking,
        public float|int|null $actual,
        public float|int|null $expected,
    ) {
    }
}
