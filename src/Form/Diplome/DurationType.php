<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Durée d'un diplôme : le niveau de la chaîne qui la compte et ses bornes.
 * Données : `['levelKey' => string, 'bound' => ?Bound]`.
 */
final class DurationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('levelKey', StructuralTypeKeyType::class, ['label' => 'diplome.wizard.structure.duration_level', 'required' => false])
            ->add('bound', BoundType::class, ['label' => 'diplome.wizard.structure.duration']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'diplome', 'required' => false]);
    }
}
