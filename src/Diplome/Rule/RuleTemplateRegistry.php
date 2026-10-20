<?php

declare(strict_types=1);

namespace App\Diplome\Rule;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Catalogue des gabarits de règles (tag `app.rule_template`). */
final class RuleTemplateRegistry
{
    /** @var array<string, RuleTemplateInterface> */
    private array $templates = [];

    /** @param iterable<RuleTemplateInterface> $templates */
    public function __construct(#[AutowireIterator(RuleTemplateInterface::TAG)] iterable $templates)
    {
        foreach ($templates as $template) {
            $this->templates[$template->key()] = $template;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->templates[$key]);
    }

    public function get(string $key): RuleTemplateInterface
    {
        return $this->templates[$key] ?? throw new \InvalidArgumentException(\sprintf('Gabarit de règle inconnu : « %s ».', $key));
    }

    /** @return array<string, RuleTemplateInterface> */
    public function all(): array
    {
        return $this->templates;
    }
}
