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
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Étape « Structure » : durée, parcours, chaîne de niveaux, types d'EC et règles.
 * Les données sont un tableau (`levels`, `rules` : listes de tableaux ;
 * `duration` : {levelKey, bound} ; `natures` : liste de {key, label}, cf. EcTypeType).
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
            ->add('natures', CollectionType::class, $collection + ['entry_type' => EcTypeType::class, 'label' => 'diplome.wizard.structure.natures', 'help' => 'diplome.wizard.structure.natures_help'])
            ->add('rules', CollectionType::class, $collection + ['entry_type' => RuleSpecType::class, 'label' => 'diplome.wizard.structure.rules']);
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
        $natures = array_values(array_filter(array_map(static fn (array $t) => (string) ($t['key'] ?? ''), $data['natures'] ?? []), static fn (string $k) => '' !== $k));
        $initial = $context->getRoot() instanceof FormInterface ? $context->getRoot()->getConfig()->getData() : null;
        $usage = \is_array($initial) ? ($initial['usage'] ?? []) : [];
        // la chaîne dans l'ordre des rangs soumis (cf. StructureStep::apply())
        $levels = array_values($data['levels'] ?? []);
        $order = array_keys($levels);
        usort($order, static fn (int $a, int $b) => [(int) ($levels[$a]['position'] ?? $a), $a] <=> [(int) ($levels[$b]['position'] ?? $b), $b]);
        $chain = array_values(array_filter(array_map(static fn (int $i) => $levels[$i]['typeKey'] ?? null, $order)));
        $add = static fn (string $id, array $params = []) => $context->buildViolation($id, $params)->setTranslationDomain('diplome')->addViolation();

        if (\count($chain) !== \count(array_unique($chain))) {
            $add('diplome.wizard.structure.error.duplicate_level');
        }
        if (\count($natures) !== \count(array_unique($natures))) {
            $add('diplome.wizard.structure.error.duplicate_ec_type');
        }
        $this->validateDuration($data, $levels, $order, $chain, $context);
        $this->validateElementLevel($levels, $order, $chain, $context);
        // le premier niveau n'a pas de nombre saisi (il découle de la durée) : son verrou ne demande rien
        foreach (\array_slice($order, 1) as $i) {
            $count = $levels[$i]['count'] ?? null;
            if (!empty($levels[$i]['locked']) && !($count instanceof Bound && $count->isExact())) {
                $context->buildViolation('diplome.wizard.structure.error.locked_exact')->setTranslationDomain('diplome')->atPath('levels['.$i.'].count')->addViolation();
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
                    $label = $usage['labels'][$value] ?? null;
                    null !== $label
                        ? $add('diplome.wizard.structure.error.ec_type_used_rule', ['%type%' => $label])
                        : $add('diplome.wizard.structure.error.unknown_nature', $row + ['%nature%' => $value]);
                }
            }
        }
    }

    /**
     * Le niveau élément (EC) ferme la chaîne, et seul le niveau juste au-dessus de lui peut rattacher les EC par coefficient
     * (sans être le premier niveau).
     *
     * @param list<array<string, mixed>> $levels
     * @param list<int>                  $order
     * @param list<string>               $chain
     */
    private function validateElementLevel(array $levels, array $order, array $chain, ExecutionContextInterface $context): void
    {
        $element = $this->nodeTypes->findElementLevel();
        if (null === $element) {
            $context->buildViolation('diplome.wizard.structure.error.element_undefined')->setTranslationDomain('diplome')->addViolation();

            return;
        }
        if ([] === $chain) {
            return;
        }
        $params = ['%element%' => $element->getLabel()];
        $last = \count($chain) - 1;
        if ($chain[$last] !== $element->getKey()) {
            $id = \in_array($element->getKey(), $chain, true) ? 'diplome.wizard.structure.error.element_not_last' : 'diplome.wizard.structure.error.element_missing';
            $context->buildViolation($id, $params)->setTranslationDomain('diplome')->addViolation();
        }
        foreach (array_values($order) as $at => $i) {
            if (LevelSpec::EC_COEFFICIENT === ($levels[$i]['ecLink'] ?? null) && !(1 <= $at && $at === $last - 1)) {
                $context->buildViolation('diplome.wizard.structure.error.ec_link_level', $params)->setTranslationDomain('diplome')->addViolation();
            }
        }
        // EC rattachés par coefficient : les ECTS se saisissent au niveau au-dessus, ceux de l'EC restent libres
        $order = array_values($order);
        if ($last >= 2 && $chain[$last] === $element->getKey() && LevelSpec::EC_COEFFICIENT === ($levels[$order[$last - 1]]['ecLink'] ?? null)
            && EctsModeType::FREE !== ($levels[$order[$last]]['ectsMode'] ?? EctsModeType::FREE)) {
            $context->buildViolation('diplome.wizard.structure.error.ec_ects_coefficient', $params + ['%level%' => $this->nodeTypes->findOneByKey($chain[$last - 1])?->getLabel() ?? $chain[$last - 1]])
                ->setTranslationDomain('diplome')->atPath('levels['.$order[$last].'].ectsMode')->addViolation();
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
