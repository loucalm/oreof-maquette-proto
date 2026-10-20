<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use App\Entity\MccType;
use App\Repository\MccTypeRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** MCCC d'une nature d'élément : types de MCCC autorisés et profil de règles. */
final class NatureMcccType extends AbstractType
{
    public function __construct(private readonly MccTypeRepository $mccTypes)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $types = $this->mccTypes->allOrdered();
        $typeChoices = [];
        $profileChoices = [];
        foreach ($types as $type) {
            $typeChoices[$type->getShortLabel().' — '.$type->getLabel()] = $type->getKey();
            foreach ($type->getProfiles() as $profile) {
                $profileChoices[(string) ($profile['label'] ?? $profile['key'])] = (string) $profile['key'];
            }
        }

        $builder
            ->add('nature', HiddenType::class)
            ->add('types', ChoiceType::class, [
                'choices' => $typeChoices,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'label' => 'diplome.wizard.mccc.types',
                'choice_translation_domain' => false,
            ])
            ->add('profile', ChoiceType::class, [
                'choices' => $profileChoices,
                'required' => false,
                'placeholder' => 'diplome.wizard.mccc.no_profile',
                'label' => 'diplome.wizard.mccc.profile',
                'choice_translation_domain' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'diplome']);
    }
}
