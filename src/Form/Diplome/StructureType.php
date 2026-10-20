<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Étape « Structure » : durée, parcours, chaîne de niveaux, natures d'élément,
 * éléments imposés et règles. Les données sont un tableau (`levels`, `imposed`,
 * `rules` : listes de tableaux ; `duration` : ?Bound ; `natures` : liste de clés).
 */
final class StructureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $collection = ['allow_add' => true, 'allow_delete' => true, 'by_reference' => false, 'delete_empty' => false, 'prototype' => true, 'required' => false];

        $builder
            ->add('duration', BoundType::class, ['label' => 'diplome.wizard.structure.duration'])
            ->add('parcoursAllowed', CheckboxType::class, ['label' => 'diplome.wizard.structure.parcours_allowed', 'required' => false])
            ->add('levels', CollectionType::class, $collection + [
                'entry_type' => LevelSpecType::class,
                'label' => 'diplome.wizard.structure.levels',
                'constraints' => [new Assert\Count(min: 1, minMessage: 'diplome.wizard.structure.error.no_level')],
            ])
            ->add('natures', TextType::class, ['label' => 'diplome.wizard.structure.natures', 'help' => 'diplome.wizard.structure.natures_help', 'required' => false])
            ->add('imposed', CollectionType::class, $collection + ['entry_type' => ImposedElementType::class, 'label' => 'diplome.wizard.structure.imposed'])
            ->add('rules', CollectionType::class, $collection + ['entry_type' => RuleSpecType::class, 'label' => 'diplome.wizard.structure.rules']);

        $builder->get('natures')->addModelTransformer(new CallbackTransformer(
            static fn (?array $list): string => implode(', ', $list ?? []),
            static fn (?string $text): array => array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $text)), static fn (string $n) => '' !== $n))),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'diplome',
            'csrf_protection' => false,
            'constraints' => [new Assert\Callback($this->validateConsistency(...))],
        ]);
    }

    /** Cohérence entre les parties : les niveaux cités doivent exister dans la chaîne. */
    private function validateConsistency(array $data, ExecutionContextInterface $context): void
    {
        $chain = array_values(array_filter(array_map(static fn ($l) => $l['typeKey'] ?? null, $data['levels'] ?? [])));
        $add = static fn (string $id, array $params = []) => $context->buildViolation($id, $params)->setTranslationDomain('diplome')->addViolation();

        if (\count($chain) !== \count(array_unique($chain))) {
            $add('diplome.wizard.structure.error.duplicate_level');
        }
        foreach ($data['imposed'] ?? [] as $i => $imposed) {
            $row = ['%n%' => $i + 1];
            $at = array_search($imposed['typeKey'] ?? null, $chain, true);
            if (false === $at || 0 === $at) {
                $add('diplome.wizard.structure.error.imposed_level', $row);
            }
        }
        foreach ($data['rules'] ?? [] as $i => $rule) {
            $row = ['%n%' => $i + 1];
            if (!\in_array($rule['scopeLevel'] ?? null, $chain, true)) {
                $add('diplome.wizard.structure.error.rule_scope', $row);
            }
        }
    }
}
