<?php

declare(strict_types=1);

namespace App\FieldType\Options;

use App\Maquette\Doc\TreeNode;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Sources d'options des champs, par clé (tag `app.field_options_source`). */
final class FieldOptionsSources
{
    /** @var array<string, FieldOptionsSourceInterface> */
    private array $sources = [];

    /** @param iterable<FieldOptionsSourceInterface> $sources */
    public function __construct(#[AutowireIterator(FieldOptionsSourceInterface::TAG)] iterable $sources)
    {
        foreach ($sources as $source) {
            $this->sources[$source->key()] = $source;
        }
    }

    /** @return array<string, string> */
    public function options(string $key, ?TreeNode $node): array
    {
        return ($this->sources[$key] ?? null)?->options($node) ?? [];
    }
}
