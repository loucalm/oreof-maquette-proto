<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use App\Diplome\Rule\RuleTemplateRegistry;
use App\Diplome\Spec\Bound;
use App\Diplome\Spec\DurationMath;
use App\Diplome\Spec\DurationSpec;
use App\Diplome\Spec\LevelSpec;
use App\Diplome\Spec\StructureSpec;
use App\Repository\NodeTypeRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Étape « Structure » : durée, parcours, chaîne de niveaux, natures d'élément,
 * éléments imposés et règles. Les données sont un tableau (`levels`, `imposed`,
 * `rules` : listes de tableaux ; `duration` : {levelKey, bound} ; `natures` : liste de clés).
 */
final class StructureType extends AbstractType
{
    public function __construct(private readonly RuleTemplateRegistry $templates, private readonly NodeTypeRepository $nodeTypes)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $collection = ['allow_add' => true, 'allow_delete' => true, 'by_reference' => false, 'delete_empty' => false, 'prototype' => true, 'required' => false];

        $builder
            ->add('duration', DurationType::class, ['label' => 'diplome.wizard.structure.duration'])
            ->add('parcoursAllowed', CheckboxType::class, ['label' => 'diplome.wizard.structure.parcours_allowed', 'required' => false])
            ->add('levels', CollectionType::class, $collection + [
                'entry_type' => LevelSpecType::class,
                'label' => 'diplome.wizard.structure.levels',
                'constraints' => [new Assert\Count(min: 1, minMessage: 'diplome.wizard.structure.error.no_level')],
            ])
            ->add('natures', TextType::class, ['label' => 'diplome.wizard.structure.natures', 'help' => 'diplome.wizard.structure.natures_help', 'required' => false])
            ->add('imposed', CollectionType::class, $this->imposedOptions($collection, []))
            ->add('rules', CollectionType::class, $collection + ['entry_type' => RuleSpecType::class, 'label' => 'diplome.wizard.structure.rules']);

        // la nature d'un élément imposé se choisit parmi les natures déclarées (saisies, à la soumission)
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($collection): void {
            $event->getForm()->add('imposed', CollectionType::class, $this->imposedOptions($collection, (array) ($event->getData()['natures'] ?? [])));
        });
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($collection): void {
            $natures = array_values(array_filter(array_map('trim', explode(',', (string) ($event->getData()['natures'] ?? '')))));
            $event->getForm()->add('imposed', CollectionType::class, $this->imposedOptions($collection, $natures));
        });

        $builder->get('natures')->addModelTransformer(new CallbackTransformer(
            static fn (?array $list): string => implode(', ', $list ?? []),
            static fn (?string $text): array => array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $text)), static fn (string $n) => '' !== $n))),
        ));
    }

    /**
     * @param array<string, mixed> $collection
     * @param list<string>         $natures
     *
     * @return array<string, mixed>
     */
    private function imposedOptions(array $collection, array $natures): array
    {
        return $collection + ['entry_type' => ImposedElementType::class, 'entry_options' => ['natures' => array_values($natures)], 'label' => 'diplome.wizard.structure.imposed'];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'diplome',
            'constraints' => [new Assert\Callback($this->validateConsistency(...))],
        ]);
    }

    /** Cohérence entre les parties : les niveaux cités doivent exister dans la chaîne, les natures citées être déclarées. */
    private function validateConsistency(array $data, ExecutionContextInterface $context): void
    {
        $natures = $data['natures'] ?? [];
        // la chaîne dans l'ordre des rangs soumis (cf. StructureStep::apply())
        $levels = array_values($data['levels'] ?? []);
        $order = array_keys($levels);
        usort($order, static fn (int $a, int $b) => [(int) ($levels[$a]['position'] ?? $a), $a] <=> [(int) ($levels[$b]['position'] ?? $b), $b]);
        $chain = array_values(array_filter(array_map(static fn (int $i) => $levels[$i]['typeKey'] ?? null, $order)));
        $add = static fn (string $id, array $params = []) => $context->buildViolation($id, $params)->setTranslationDomain('diplome')->addViolation();

        if (\count($chain) !== \count(array_unique($chain))) {
            $add('diplome.wizard.structure.error.duplicate_level');
        }
        $this->validateDuration($data, $levels, $order, $chain, $context);
        foreach ($data['imposed'] ?? [] as $i => $imposed) {
            $row = ['%n%' => $i + 1];
            $at = array_search($imposed['typeKey'] ?? null, $chain, true);
            if (false === $at || 0 === $at) {
                $add('diplome.wizard.structure.error.imposed_level', $row);
            }
            $nature = (string) ($imposed['nature'] ?? '');
            if ('' !== $nature && !\in_array($nature, $natures, true)) {
                $add('diplome.wizard.structure.error.unknown_nature', $row + ['%nature%' => $nature]);
            }
        }
        foreach ($data['rules'] ?? [] as $i => $rule) {
            $row = ['%n%' => $i + 1];
            if (!\in_array($rule['scopeLevel'] ?? null, $chain, true)) {
                $add('diplome.wizard.structure.error.rule_scope', $row);
            }
            $key = (string) ($rule['template'] ?? '');
            if (!$this->templates->has($key)) {
                continue;
            }
            foreach ($this->templates->get($key)->params() as $param) {
                $value = (string) ($rule[RuleSpecType::PARAMS_PREFIX.$key][$param->name] ?? '');
                if ('nature' === $param->type && '' !== $value && !\in_array($value, $natures, true)) {
                    $add('diplome.wizard.structure.error.unknown_nature', $row + ['%nature%' => $value]);
                }
            }
        }
    }

    /**
     * La durée se compte sur un niveau temporel de la chaîne, précédé de niveaux au nombre exact, et son
     * minimum comme son maximum doivent pouvoir se répartir en années entières (cf. DurationMath).
     *
     * @param list<array<string, mixed>> $levels lignes soumises
     * @param list<int>                  $order  indices des lignes dans l'ordre de la chaîne
     * @param list<string>               $chain
     */
    private function validateDuration(array $data, array $levels, array $order, array $chain, ExecutionContextInterface $context): void
    {
        $bound = $data['duration']['bound'] ?? null;
        if (!$bound instanceof Bound) {
            return;
        }
        $levelKey = '' !== (string) ($data['duration']['levelKey'] ?? '') ? (string) $data['duration']['levelKey'] : ($chain[0] ?? '');
        $specs = array_map(static fn (int $i) => new LevelSpec((string) ($levels[$i]['typeKey'] ?? ''), $levels[$i]['count'] ?? null), $order);
        $structure = new StructureSpec($specs, false, new DurationSpec($levelKey, $bound));
        $temporal = [];
        foreach ($this->nodeTypes->findAllIndexed() as $key => $type) {
            if ($type->isTemporel()) {
                $temporal[] = $key;
            }
        }
        $problem = DurationMath::problem($structure, $temporal);
        if (null === $problem) {
            return;
        }
        $labels = $this->nodeTypes->findAllIndexed();
        $label = static fn (string $key): string => mb_strtolower(isset($labels[$key]) ? $labels[$key]->getLabel() : $key);
        $context->buildViolation($problem, [
            '%duration%' => $bound->min ?? $bound->max,
            '%factor%' => DurationMath::factor($structure),
            '%unit%' => $label($levelKey).'s',
            '%parent%' => $label($chain[0] ?? ''),
        ])->setTranslationDomain('diplome')->atPath('duration')->addViolation();
    }
}
