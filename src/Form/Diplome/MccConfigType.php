<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Étape « MCCC » : une ligne par type de MCCC du référentiel (`types`, liste fixe
 * non modifiable) et les options quitus / assiduité / sans note.
 * Données : `types` = liste de {type, enabled, profile}, `quitus`, `assiduite`, `sansNote`.
 */
final class MccConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('types', CollectionType::class, [
                'entry_type' => McccTypeRowType::class,
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
        $resolver->setDefaults(['translation_domain' => 'diplome']);
    }
}
