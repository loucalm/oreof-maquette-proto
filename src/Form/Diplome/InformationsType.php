<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use App\Diplome\Wizard\InformationsRules;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Étape « Informations » : identité du type et année universitaire de la première version. */
final class InformationsType extends AbstractType
{
    public function __construct(private readonly InformationsRules $rules)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, ['constraints' => $this->rules->constraints('label')])
            ->add('sigle', TextType::class, ['constraints' => $this->rules->constraints('sigle')])
            ->add('codeApogee', TextType::class, ['required' => false, 'constraints' => $this->rules->constraints('codeApogee')])
            ->add('description', TextareaType::class, ['required' => false])
            ->add('accredite', CheckboxType::class, ['required' => false])
            ->add('passageCfvu', CheckboxType::class, ['required' => false])
            ->add('anneeUniversitaire', TextType::class, ['constraints' => $this->rules->constraints('anneeUniversitaire')]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'diplome', 'csrf_protection' => false]);
    }
}
