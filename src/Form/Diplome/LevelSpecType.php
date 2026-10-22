<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use App\Diplome\Spec\LevelSpec;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un niveau de la chaîne de structure, tel que le panneau de réglages le présente : type, nombre par parent,
 * statut dans la formation, rattachement des EC, ECTS (un mode et une borne, cf. EctsModeType), numéro de départ.
 */
final class LevelSpecType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // rang du niveau dans la chaîne (les lignes se déplacent dans l'arbre, cf. structure_tree_controller.js)
            ->add('position', HiddenType::class, ['attr' => ['data-field' => 'position']])
            ->add('typeKey', StructuralTypeKeyType::class, ['label' => 'diplome.wizard.structure.level_type', 'constraints' => [new Assert\NotBlank()]])
            ->add('count', BoundType::class, ['label' => 'diplome.wizard.structure.level_count'])
            ->add('locked', ChoiceType::class, [
                'label' => 'diplome.wizard.structure.level_locked',
                'choices' => ['diplome.wizard.structure.locked.no' => false, 'diplome.wizard.structure.locked.yes' => true],
                'choice_translation_domain' => 'diplome',
                'required' => false,
                'placeholder' => false,
            ])
            ->add('ecLink', ChoiceType::class, [
                'label' => 'diplome.wizard.structure.level_ec_link',
                'choices' => ['diplome.wizard.structure.ec_link.contains' => LevelSpec::EC_CONTAINS, 'diplome.wizard.structure.ec_link.coefficient' => LevelSpec::EC_COEFFICIENT],
                'choice_translation_domain' => 'diplome',
                'empty_data' => LevelSpec::EC_CONTAINS,
            ])
            ->add('ectsMode', EctsModeType::class, ['label' => 'diplome.wizard.structure.level_ects'])
            ->add('ectsBound', BoundType::class, ['label' => 'diplome.wizard.structure.level_ects_bound'])
            ->add('startAt', IntegerType::class, ['label' => 'diplome.wizard.structure.level_start_at', 'empty_data' => '1', 'attr' => ['min' => 1], 'constraints' => [new Assert\GreaterThanOrEqual(1)]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'diplome']);
    }
}
