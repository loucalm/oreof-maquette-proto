<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Où se saisissent les ECTS d'un niveau, en un seul choix : libres, saisis sur chaque nœud (`LevelSpec::ects`)
 * ou somme attendue des nœuds du niveau en dessous (`LevelSpec::ectsSum`). La borne associée est un champ à part.
 */
final class EctsModeType extends AbstractType
{
    public const FREE = 'free';
    public const NODE = 'node';
    public const SUM = 'sum';

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'choices' => [
                'diplome.wizard.structure.ects_mode.free' => self::FREE,
                'diplome.wizard.structure.ects_mode.node' => self::NODE,
                'diplome.wizard.structure.ects_mode.sum' => self::SUM,
            ],
            'choice_translation_domain' => 'diplome',
            'empty_data' => self::FREE,
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }
}
