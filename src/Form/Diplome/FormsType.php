<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use App\Enum\NodeFamily;
use App\Repository\NodeTypeRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Étape « Formulaires » : un formulaire (NodeType de famille `parameter`) par emplacement. */
final class FormsType extends AbstractType
{
    public const SLOTS = ['formation.organisation', 'formation.presentation', 'parcours.organisation', 'parcours.presentation'];

    public function __construct(private readonly NodeTypeRepository $nodeTypes)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach ($this->nodeTypes->findAllOrdered() as $nodeType) {
            if (NodeFamily::Parameter === $nodeType->getFamily()) {
                $choices[$nodeType->getLabel().' ('.$nodeType->getKey().')'] = $nodeType->getKey();
            }
        }
        foreach (self::SLOTS as $slot) {
            $builder->add(self::fieldName($slot), ChoiceType::class, [
                'choices' => $choices,
                'required' => false,
                'placeholder' => 'diplome.wizard.formulaires.none',
                'label' => 'diplome.wizard.formulaires.slot.'.$slot,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'diplome', 'csrf_protection' => false]);
    }

    /** Un nom de champ de formulaire ne peut pas contenir de point. */
    public static function fieldName(string $slot): string
    {
        return str_replace('.', '_', $slot);
    }
}
