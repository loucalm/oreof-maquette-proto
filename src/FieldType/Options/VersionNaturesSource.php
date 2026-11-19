<?php

declare(strict_types=1);

namespace App\FieldType\Options;

use App\Diplome\TypeDiplomeResolver;
use App\Entity\FicheMatiere;
use App\Maquette\Doc\TreeNode;

/**
 * Natures d'élément (types d'EC) déclarées par la version du type de diplôme : celle de la
 * formation du nœud, ou celle de la fiche matière (cf. TypeDiplomeResolver::versionForFiche()).
 */
final class VersionNaturesSource implements FieldOptionsSourceInterface
{
    public function __construct(private readonly TypeDiplomeResolver $resolver)
    {
    }

    public function key(): string
    {
        return 'version_natures';
    }

    public function options(TreeNode|FicheMatiere|null $subject): array
    {
        $formation = $subject instanceof TreeNode ? $subject->getFormation() : null;
        $config = match (true) {
            $subject instanceof FicheMatiere => $this->resolver->configForFiche($subject),
            null !== $formation => $this->resolver->configFor($formation),
            default => null,
        };
        $options = [];
        foreach ($config?->structure->natureKeys() ?? [] as $nature) {
            $options[$nature] = mb_strtoupper(mb_substr($nature, 0, 1)).mb_substr($nature, 1);
        }

        return $options;
    }
}
