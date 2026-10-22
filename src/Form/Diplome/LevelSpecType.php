<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use Symfony\Component\Form\AbstractType;
use App\Diplome\Spec\LevelSpec;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
            ->add('startAt', IntegerType::class, ['label' => 'diplome.wizard.structure.level_start_at', 'empty_data' => '1', 'attr' => ['min' => 1], 'constraints' => [new Assert\GreaterThanOrEqual(1)]])
            ->add('locked', CheckboxType::class, ['label' => 'diplome.wizard.structure.level_locked', 'required' => false])
            ->add('ecLink', ChoiceType::class, [
                'label' => 'diplome.wizard.structure.level_ec_link',
                'choices' => ['diplome.wizard.structure.ec_link.contains' => LevelSpec::EC_CONTAINS, 'diplome.wizard.structure.ec_link.coefficient' => LevelSpec::EC_COEFFICIENT],
                'choice_translation_domain' => 'diplome',
                'empty_data' => LevelSpec::EC_CONTAINS,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'diplome']);
    }
}
