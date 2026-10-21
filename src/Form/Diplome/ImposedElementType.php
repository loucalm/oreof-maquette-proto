<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Élément imposé : type, libellé, position, nature, verrous, surcharge du
 * nombre d'enfants. Son niveau parent est celui situé juste au-dessus de son
 * type dans la chaîne : il se déduit, l'admin ne le saisit pas.
 */
final class ImposedElementType extends AbstractType
{
    public const LOCKS = ['add', 'delete', 'duplicate'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('uid', HiddenType::class, ['required' => false])
            ->add('typeKey', StructuralTypeKeyType::class, ['label' => 'diplome.wizard.structure.imposed_type', 'constraints' => [new Assert\NotBlank()]])
            ->add('label', TextType::class, ['label' => 'diplome.wizard.structure.imposed_label', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 120)]])
            ->add('position', ChoiceType::class, [
                'label' => 'diplome.wizard.structure.imposed_position',
                // aide en infobulle : sous le champ, elle déborderait de sa colonne étroite
                'attr' => ['title' => 'diplome.wizard.structure.imposed_position_help'],
                'choices' => self::positionChoices(),
                'choice_translation_domain' => false,
                'empty_data' => 'all',
                'invalid_message' => 'diplome.wizard.structure.imposed_position_invalid',
            ])
            ->add('nature', ChoiceType::class, [
                'label' => 'diplome.wizard.structure.imposed_nature',
                'required' => false,
                'choices' => array_combine($options['natures'], $options['natures']),
                'choice_translation_domain' => false,
                'placeholder' => 'diplome.wizard.structure.imposed_nature_any',
                'invalid_message' => 'diplome.wizard.structure.error.unknown_nature_short',
            ])
            ->add('locked', ChoiceType::class, [
                'label' => 'diplome.wizard.structure.imposed_locked',
                'choices' => array_combine(array_map(static fn (string $l) => 'diplome.wizard.structure.lock.'.$l, self::LOCKS), self::LOCKS),
                'choice_translation_domain' => 'diplome',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('countOverride', BoundType::class, ['label' => 'diplome.wizard.structure.imposed_count']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'diplome', 'natures' => []]);
        $resolver->setAllowedTypes('natures', 'array');
    }

    /** @return array<string, string> « dans chaque », « le premier », « le dernier », puis les numéros 1 à 20 */
    private static function positionChoices(): array
    {
        $choices = ['Dans chaque' => 'all', 'Le premier' => 'first', 'Le dernier' => 'last'];
        foreach (range(1, 20) as $n) {
            $choices['N° '.$n] = (string) $n;
        }

        return $choices;
    }
}
