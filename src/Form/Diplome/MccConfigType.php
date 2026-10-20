<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Étape « MCCC » : une ligne par nature d'élément déclarée (`natures`, liste fixe
 * non modifiable) et les options quitus / assiduité / sans note.
 * Données : `natures` = liste de {nature, types, profile}, `quitus`, `assiduite`, `sansNote`.
 */
final class MccConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('natures', CollectionType::class, [
                'entry_type' => NatureMcccType::class,
                'allow_add' => false,
                'allow_delete' => false,
                'label' => false,
            ])
            ->add('quitus', CheckboxType::class, ['required' => false, 'label' => 'diplome.wizard.mccc.options.quitus'])
            ->add('assiduite', CheckboxType::class, ['required' => false, 'label' => 'diplome.wizard.mccc.options.assiduite'])
            ->add('sansNote', CheckboxType::class, ['required' => false, 'label' => 'diplome.wizard.mccc.options.sansNote']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'diplome', 'csrf_protection' => false]);
    }
}
