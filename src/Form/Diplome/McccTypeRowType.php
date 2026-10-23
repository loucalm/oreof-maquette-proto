<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use App\Repository\MccTypeRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Un type de MCCC dans l'étape « MCCC » : autorisé ou non dans la formation, et profil de règles
 * choisi parmi ceux de ce type. Données : {type, enabled, profile}.
 */
final class McccTypeRowType extends AbstractType
{
    public function __construct(private readonly MccTypeRepository $mccTypes)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', HiddenType::class)
            ->add('enabled', CheckboxType::class, ['required' => false, 'label' => false]);

        // les profils proposés sont ceux du type de la ligne
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $type = $this->mccTypes->findOneByKey((string) ($event->getData()['type'] ?? ''));
            $choices = [];
            foreach (null !== $type ? $type->getProfiles() : [] as $profile) {
                $choices[(string) ($profile['label'] ?? $profile['key'])] = (string) $profile['key'];
            }
            $event->getForm()->add('profile', ChoiceType::class, [
                'choices' => $choices,
                'required' => false,
                'placeholder' => 'diplome.wizard.mccc.no_profile',
                'label' => 'diplome.wizard.mccc.profile',
                'choice_translation_domain' => false,
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'diplome']);
    }
}
