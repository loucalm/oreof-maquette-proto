<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Étape « BCC » : 1 à 3 noms de niveaux (`level1` obligatoire, pas de trou) et
 * niveau relié aux éléments (`linkLevel`, indice 0-based).
 */
final class BccConfigType extends AbstractType
{
    public const MAX_LEVELS = 3;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        for ($i = 1; $i <= self::MAX_LEVELS; ++$i) {
            $builder->add('level'.$i, TextType::class, [
                'required' => 1 === $i,
                'label' => 'diplome.wizard.bcc.level',
                'label_translation_parameters' => ['%n%' => $i],
                'constraints' => 1 === $i ? [new Assert\NotBlank(message: 'diplome.wizard.bcc.error.first_required')] : [],
            ]);
        }
        $builder->add('linkLevel', ChoiceType::class, [
            'choices' => array_combine(
                array_map(static fn (int $i) => 'diplome.wizard.bcc.level_n_'.$i, range(1, self::MAX_LEVELS)),
                range(0, self::MAX_LEVELS - 1),
            ),
            'choice_translation_domain' => 'diplome',
            'required' => false,
            'placeholder' => 'diplome.wizard.bcc.link_default',
            'label' => 'diplome.wizard.bcc.link_level',
            'help' => 'diplome.wizard.bcc.link_level_help',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'diplome',
            'csrf_protection' => false,
            'constraints' => [new Assert\Callback($this->validateLevels(...))],
        ]);
    }

    /** Pas de niveau vide avant un niveau saisi ; le niveau relié doit exister. */
    private function validateLevels(array $data, ExecutionContextInterface $context): void
    {
        $filled = [];
        $gap = false;
        for ($i = 1; $i <= self::MAX_LEVELS; ++$i) {
            $name = trim((string) ($data['level'.$i] ?? ''));
            if ('' === $name) {
                $gap = true;
            } elseif ($gap) {
                $context->buildViolation('diplome.wizard.bcc.error.gap')->setTranslationDomain('diplome')->addViolation();
                break;
            } else {
                $filled[] = $name;
            }
        }
        if (null !== ($data['linkLevel'] ?? null) && '' !== $data['linkLevel'] && (int) $data['linkLevel'] >= max(1, \count($filled))) {
            $context->buildViolation('diplome.wizard.bcc.error.link_level')->setTranslationDomain('diplome')->addViolation();
        }
    }
}
