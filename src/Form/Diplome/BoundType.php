<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use App\Diplome\Spec\Bound;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Borne : un mode (aucune / au moins / au plus / entre / exactement) et un ou
 * deux nombres. La donnée du formulaire est un `?Bound` (null = aucune borne).
 * `value` porte le nombre du mode ; `upper` la borne haute du mode « entre ».
 */
final class BoundType extends AbstractType
{
    public const NONE = 'none';
    public const MODES = ['none', 'min', 'max', 'between', 'exact'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('mode', ChoiceType::class, [
                'choices' => array_combine(array_map(static fn (string $m) => 'diplome.wizard.structure.bound.'.$m, self::MODES), self::MODES),
                'choice_translation_domain' => 'diplome',
                'label' => false,
            ])
            ->add('value', IntegerType::class, ['required' => false, 'label' => false, 'attr' => ['min' => 0, 'size' => 4]])
            ->add('upper', IntegerType::class, ['required' => false, 'label' => false, 'attr' => ['min' => 0, 'size' => 4]]);

        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?Bound $bound): array => match (true) {
                null === $bound || (null === $bound->min && null === $bound->max) => ['mode' => self::NONE, 'value' => null, 'upper' => null],
                $bound->isExact() => ['mode' => 'exact', 'value' => $bound->min, 'upper' => null],
                null !== $bound->min && null !== $bound->max => ['mode' => 'between', 'value' => $bound->min, 'upper' => $bound->max],
                null !== $bound->min => ['mode' => 'min', 'value' => $bound->min, 'upper' => null],
                default => ['mode' => 'max', 'value' => $bound->max, 'upper' => null],
            },
            static function (array $data): ?Bound {
                $mode = $data['mode'] ?? self::NONE;
                $value = $data['value'] ?? null;
                $upper = $data['upper'] ?? null;
                if (self::NONE === $mode || null === $mode) {
                    return null;
                }
                if (!\in_array($mode, self::MODES, true) || null === $value || $value < 0 || ('between' === $mode && (null === $upper || $upper < $value))) {
                    throw new TransformationFailedException('Borne invalide.', 0, null, 'diplome.wizard.structure.bound_invalid');
                }

                return match ($mode) {
                    'min' => new Bound($value, null),
                    'max' => new Bound(null, $value),
                    'exact' => Bound::exactly($value),
                    default => new Bound($value, $upper),
                };
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required' => false,
            'error_bubbling' => false,
            'invalid_message' => 'diplome.wizard.structure.bound_invalid',
            'translation_domain' => 'diplome',
            'empty_data' => static fn () => null,
        ]);
    }
}
