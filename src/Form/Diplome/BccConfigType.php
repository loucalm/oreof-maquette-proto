<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Étape « BCC » : liste libre des noms de niveaux (`levels`, au moins un, chacun
 * nommé ; ajout / retrait / ordre côté écran) et niveau relié aux éléments
 * (`linkLevel`, indice 0-based dans la liste ; vide = le dernier).
 */
final class BccConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('levels', CollectionType::class, [
            'entry_type' => TextType::class,
            'entry_options' => ['label' => false, 'required' => true],
            'allow_add' => true,
            'allow_delete' => true,
            'prototype' => true,
            'label' => 'diplome.wizard.bcc.levels',
        ]);

        // les choix du niveau relié suivent le nombre de niveaux (affichés puis soumis)
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $this->addLinkLevel($event->getForm(), (array) ($event->getData()['levels'] ?? []));
        });
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $this->addLinkLevel($event->getForm(), (array) ($event->getData()['levels'] ?? []));
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'diplome',
            'constraints' => [new Assert\Callback($this->validateLevels(...))],
        ]);
    }

    /** @param array<array-key, mixed> $levels */
    private function addLinkLevel(FormInterface $form, array $levels): void
    {
        $names = array_values(array_map(static fn ($n) => trim((string) $n), $levels));
        $form->add('linkLevel', ChoiceType::class, [
            'choices' => array_keys($names),
            'choice_label' => static fn (int $i) => '' === $names[$i] ? 'diplome.wizard.bcc.level' : 'diplome.wizard.bcc.level_choice',
            'choice_translation_parameters' => static fn (int $i) => ['%n%' => $i + 1, '%name%' => $names[$i]],
            'choice_translation_domain' => 'diplome',
            'required' => false,
            'placeholder' => 'diplome.wizard.bcc.link_default',
            'label' => 'diplome.wizard.bcc.link_level',
            'help' => 'diplome.wizard.bcc.link_level_help',
        ]);
    }

    /** Au moins un niveau ; chaque niveau ajouté porte un nom. */
    private function validateLevels(array $data, ExecutionContextInterface $context): void
    {
        $names = array_map(static fn ($n) => trim((string) $n), array_values((array) ($data['levels'] ?? [])));
        if ([] === $names) {
            $context->buildViolation('diplome.wizard.bcc.error.first_required')->setTranslationDomain('diplome')->atPath('[levels]')->addViolation();
        } elseif (\in_array('', $names, true)) {
            $context->buildViolation('diplome.wizard.bcc.error.blank')->setTranslationDomain('diplome')->atPath('[levels]')->addViolation();
        }
    }
}
