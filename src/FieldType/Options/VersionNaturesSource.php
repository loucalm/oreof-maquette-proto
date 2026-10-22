<?php

declare(strict_types=1);

namespace App\FieldType\Options;

use App\Diplome\TypeDiplomeResolver;
use App\Maquette\Doc\TreeNode;

/** Natures d'élément déclarées par la version du type de diplôme de la formation du nœud. */
final class VersionNaturesSource implements FieldOptionsSourceInterface
{
    public function __construct(private readonly TypeDiplomeResolver $resolver)
    {
    }

    public function key(): string
    {
        return 'version_natures';
    }

    public function options(?TreeNode $node): array
    {
        $formation = $node?->getFormation();
        $config = null === $formation ? null : $this->resolver->configFor($formation);
        $options = [];
        foreach ($config?->structure->natureKeys() ?? [] as $nature) {
            $options[$nature] = mb_strtoupper(mb_substr($nature, 0, 1)).mb_substr($nature, 1);
        }

        return $options;
    }
}
