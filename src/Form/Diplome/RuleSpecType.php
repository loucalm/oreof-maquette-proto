<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use App\Diplome\Rule\RuleParam;
use App\Diplome\Rule\RuleTemplateRegistry;
use App\Diplome\Spec\RuleSpec;
use App\Repository\FieldDefRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormError;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Règle : gabarit (liste fermée), portée, gravité, et un groupe de champs par
 * gabarit (`params_<gabarit>`) généré depuis `RuleTemplateInterface::params()`
 * — les paramètres internes n'y figurent pas. Le groupe du gabarit choisi est
 * seul retenu ; ses paramètres obligatoires sont contrôlés à la soumission.
 */
final class RuleSpecType extends AbstractType
{
    public const PARAMS_PREFIX = 'params_';
    private const OPS = ['>=', '<=', '>', '<', '=='];

    public function __construct(
        private readonly RuleTemplateRegistry $templates,
        private readonly FieldDefRepository $fieldDefs,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $templateChoices = [];
        foreach ($this->templates->all() as $key => $template) {
            $templateChoices[$template->label()] = $key;
        }

        $builder
            ->add('uid', HiddenType::class, ['required' => false])
            ->add('template', ChoiceType::class, [
                'label' => 'diplome.wizard.structure.rule_template',
                'choices' => $templateChoices,
                'choice_translation_domain' => false,
                'placeholder' => 'diplome.wizard.structure.choose',
                'constraints' => [new Assert\NotBlank()],
                'attr' => ['data-rule-params-target' => 'template', 'data-action' => 'change->rule-params#sync'],
            ])
            ->add('scopeLevel', StructuralTypeKeyType::class, ['label' => 'diplome.wizard.structure.rule_scope', 'constraints' => [new Assert\NotBlank()]])
            ->add('severity', ChoiceType::class, [
                'label' => 'diplome.wizard.structure.rule_severity',
                'choices' => ['diplome.wizard.structure.severity.error' => RuleSpec::ERROR, 'diplome.wizard.structure.severity.warning' => RuleSpec::WARNING],
                'choice_translation_domain' => 'diplome',
            ]);

        foreach ($this->templates->all() as $key => $template) {
            $group = $builder->create(self::PARAMS_PREFIX.$key, FormType::class, ['label' => false, 'required' => false, 'attr' => ['data-rule-params-target' => 'group', 'data-template' => $key]]);
            foreach ($template->params() as $param) {
                if (!$param->internal) {
                    $group->add($param->name, ...$this->field($param));
                }
            }
            $builder->add($group);
        }

        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->checkRequiredParams(...));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'diplome']);
    }

    private function checkRequiredParams(FormEvent $event): void
    {
        $form = $event->getForm();
        $data = $event->getData();
        $key = $data['template'] ?? null;
        if (!\is_string($key) || !$this->templates->has($key)) {
            return;
        }
        foreach ($this->templates->get($key)->params() as $param) {
            $value = $data[self::PARAMS_PREFIX.$key][$param->name] ?? null;
            if ($param->required && !$param->internal && (null === $value || '' === $value)) {
                $form->addError(new FormError($this->translator->trans('diplome.wizard.structure.rule_param_missing', ['%param%' => $param->label], 'diplome')));
            }
        }
    }

    /** @return array{0: class-string, 1: array<string, mixed>} */
    private function field(RuleParam $param): array
    {
        $options = ['label' => $param->label, 'required' => false, 'translation_domain' => false];

        return match ($param->type) {
            'int' => [IntegerType::class, $options],
            'typeKey' => [StructuralTypeKeyType::class, $options + ['placeholder' => '']],
            'attribute' => [ChoiceType::class, $options + ['choices' => $this->attributeChoices(), 'placeholder' => '']],
            'op' => [ChoiceType::class, $options + ['choices' => array_combine(self::OPS, self::OPS), 'placeholder' => '']],
            default => [TextType::class, $options], // string, nature
        };
    }

    /** @return array<string, string> libellé => clé des champs numériques des éléments */
    private function attributeChoices(): array
    {
        $choices = [];
        foreach ($this->fieldDefs->allOrdered() as $field) {
            if ('number' === $field->getType()) {
                $choices[$field->getLabel().' ('.$field->getKey().')'] = $field->getKey();
            }
        }

        return $choices;
    }
}
