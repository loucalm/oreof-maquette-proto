<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Étape « Admission » : une ligne par plateforme du référentiel `plateformes_admission`
 * (liste fixe). Données : `plateformes` = liste de {key, label, enabled, annees}.
 */
final class AdmissionConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plateformes', CollectionType::class, [
            'entry_type' => AdmissionRowType::class,
            'allow_add' => false,
            'allow_delete' => false,
            'label' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'diplome']);
    }
}
