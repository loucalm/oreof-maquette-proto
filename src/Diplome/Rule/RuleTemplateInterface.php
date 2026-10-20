<?php

declare(strict_types=1);

namespace App\Diplome\Rule;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Gabarit de règle paramétrable choisi par l'admin. Il se compile en une ou
 * deux règles (minimum non bloquant, maximum bloquant) évaluées par RuleEvaluator
 * contre le contexte de StructureContextBuilder (collections `children`, `leaves`).
 */
#[AutoconfigureTag(self::TAG)]
interface RuleTemplateInterface
{
    public const TAG = 'app.rule_template';

    public function key(): string;

    public function label(): string;

    /** @return list<RuleParam> */
    public function params(): array;

    /**
     * @param array<string, mixed> $params
     *
     * @return list<CompiledRule>
     */
    public function compile(array $params): array;
}
