<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Un niveau de la chaîne de structure : type, nombre par parent, ECTS, somme ECTS, numéro de départ. */
final class LevelSpecType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // rang du niveau dans la chaîne (les blocs se déplacent dans la page, cf. levels_controller.js)
            ->add('position', HiddenType::class, ['attr' => ['data-levels-target' => 'position']])
            ->add('typeKey', StructuralTypeKeyType::class, ['label' => 'diplome.wizard.structure.level_type', 'constraints' => [new Assert\NotBlank()]])
            ->add('count', BoundType::class, ['label' => 'diplome.wizard.structure.level_count'])
            ->add('ects', BoundType::class, ['label' => 'diplome.wizard.structure.level_ects'])
            ->add('ectsSum', BoundType::class, ['label' => 'diplome.wizard.structure.level_ects_sum'])
            ->add('startAt', IntegerType::class, ['label' => 'diplome.wizard.structure.level_start_at', 'empty_data' => '1', 'attr' => ['min' => 1], 'constraints' => [new Assert\GreaterThanOrEqual(1)]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'diplome']);
    }
}
