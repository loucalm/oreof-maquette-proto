<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Une plateforme d'admission dans l'étape « Admission » : retenue ou non, années concernées (« 1 », « 1, 2 »). */
final class AdmissionRowType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('key', HiddenType::class)
            ->add('enabled', CheckboxType::class, ['required' => false, 'label' => false])
            ->add('annees', TextType::class, [
                'required' => false,
                'label' => 'diplome.wizard.admission.annees',
                'constraints' => [new Assert\Regex(pattern: '/^\s*(\d+\s*(,\s*\d+\s*)*)?$/', message: 'diplome.wizard.admission.error.annees')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'diplome']);
    }
}
