<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use App\Diplome\Spec\CanvasSpec;
use App\Entity\NodeType;
use App\Enum\FormScope;
use App\Enum\NodeFamily;
use App\Repository\NodeTypeRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Une entrée du canevas d'une fiche : clé (vide pour une entrée ajoutée), écran intégré
 * éventuel, libellé, formulaire (formulaires de la bibliothèque de la portée de la fiche,
 * variantes sous leur formulaire de départ), masquée, et rang dans la fiche. L'entrée unique
 * de la fiche matière n'a que son formulaire (cf. CanvasSpec::hasFreeEntries()).
 */
final class CanvasEntryType extends AbstractType
{
    public function __construct(private readonly NodeTypeRepository $types)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $free = CanvasSpec::hasFreeEntries($options['fiche']);
        $builder
            ->add('key', HiddenType::class)
            ->add('builtin', HiddenType::class)
            ->add('position', HiddenType::class, ['attr' => ['data-canvas-position' => '']]);
        if ($free) {
            $builder->add('label', TextType::class, ['required' => false, 'label' => 'diplome.wizard.formulaires.label']);
        }
        $builder->add('form', ChoiceType::class, [
            'choices' => $this->choices(FormScope::from($options['fiche'])),
            'required' => false,
            'placeholder' => 'diplome.wizard.formulaires.none',
            'label' => 'diplome.wizard.formulaires.form',
        ]);
        // fiche matière : seul le formulaire se choisit (ni libellé, ni masquage)
        if ($free) {
            $builder->add('hidden', CheckboxType::class, ['required' => false, 'label' => 'diplome.wizard.formulaires.hidden']);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('fiche');
        $resolver->setAllowedValues('fiche', CanvasSpec::FICHES);
        $resolver->setDefaults(['translation_domain' => 'diplome']);
    }

    /** @return array<string, string> libellé → clé ; une variante est indentée sous son formulaire de départ */
    private function choices(FormScope $scope): array
    {
        $forms = array_filter($this->types->findAllOrdered(), static fn (NodeType $t) => NodeFamily::Parameter === $t->getFamily() && $scope === $t->getScope());
        $choices = [];
        $add = function (?NodeType $parent, int $depth) use (&$add, &$choices, $forms): void {
            foreach ($forms as $form) {
                if ($form->getParent() === $parent) {
                    $choices[str_repeat('  ↳ ', $depth).$form->getLabel().' ('.$form->getKey().')'] = $form->getKey();
                    $add($form, $depth + 1);
                }
            }
        };
        $add(null, 0);

        return $choices;
    }
}
