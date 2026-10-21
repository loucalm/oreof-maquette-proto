<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use App\Diplome\Spec\CanvasSpec;
use App\Enum\FormScope;
use App\Enum\NodeFamily;
use App\Formulaire\Builtin\BuiltinScreenRegistry;
use App\Formulaire\FormResolver;
use App\Formulaire\ResolvedField;
use App\Repository\NodeTypeRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Étape « Formulaires » : le canevas des fiches formation et parcours. Données : un tableau
 * par fiche de lignes {key, builtin, position, label, form, hidden} (cf. CanvasEntryType).
 *
 * Contrôles : une entrée à formulaire en a un ; une entrée ajoutée a un libellé ; un écran
 * intégré non masquable n'est pas masqué ; chaque champ verrouillé d'une fiche (actif dans un
 * formulaire de sa portée) reste dans une entrée affichée.
 */
final class CanvasType extends AbstractType
{
    public function __construct(
        private readonly NodeTypeRepository $types,
        private readonly FormResolver $forms,
        private readonly BuiltinScreenRegistry $builtins,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (CanvasSpec::FICHES as $fiche) {
            $builder->add($fiche, CollectionType::class, [
                'entry_type' => CanvasEntryType::class,
                'entry_options' => ['fiche' => $fiche, 'label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'prototype' => true,
                'by_reference' => false,
                'required' => false,
                'label' => 'diplome.wizard.formulaires.fiche.'.$fiche,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'diplome',
            'constraints' => [new Assert\Callback($this->validateCanvas(...))],
        ]);
    }

    /** @param array<string, list<array<string, mixed>>> $data */
    private function validateCanvas(array $data, ExecutionContextInterface $context): void
    {
        $add = static fn (string $id, array $params) => $context->buildViolation($id, $params)->setTranslationDomain('diplome')->addViolation();

        foreach (CanvasSpec::FICHES as $fiche) {
            $ficheParam = ['%fiche%' => $this->translator->trans('diplome.wizard.formulaires.fiche.'.$fiche, [], 'diplome')];
            $covered = [];
            foreach ($data[$fiche] ?? [] as $row) {
                $builtin = (string) ($row['builtin'] ?? '');
                $hidden = (bool) ($row['hidden'] ?? false);
                if ('' !== $builtin) {
                    $screen = $this->builtins->get($fiche, $builtin);
                    if (null !== $screen && $hidden && !$screen->hideable()) {
                        $add('diplome.wizard.formulaires.error.not_hideable', ['%label%' => $screen->label()]);
                    }
                    continue;
                }
                $label = trim((string) ($row['label'] ?? ''));
                if ('' === (string) ($row['key'] ?? '') && '' === $label) {
                    $add('diplome.wizard.formulaires.error.label_required', $ficheParam);
                }
                $form = '' !== (string) ($row['form'] ?? '') ? $this->types->findOneByKey((string) $row['form']) : null;
                if (null === $form) {
                    $add('diplome.wizard.formulaires.error.no_form', $ficheParam + ['%label%' => '' !== $label ? $label : (string) ($row['key'] ?? '')]);
                    continue;
                }
                if (!$hidden) {
                    foreach ($this->forms->fields($form) as $field) {
                        $covered[$field->getKey()] = true;
                    }
                }
            }
            foreach ($this->lockedFields(FormScope::from($fiche)) as $key => $field) {
                if (!isset($covered[$key])) {
                    $add('diplome.wizard.formulaires.error.locked_missing', $ficheParam + ['%field%' => $field->label]);
                }
            }
        }
    }

    /** @return array<string, ResolvedField> champs verrouillés actifs dans au moins un formulaire de la portée */
    private function lockedFields(FormScope $scope): array
    {
        $locked = [];
        foreach ($this->types->findAllOrdered() as $type) {
            if (NodeFamily::Parameter !== $type->getFamily() || $scope !== $type->getScope()) {
                continue;
            }
            foreach ($this->forms->fields($type) as $field) {
                if ($field->isLocked()) {
                    $locked[$field->getKey()] ??= $field;
                }
            }
        }

        return $locked;
    }
}
