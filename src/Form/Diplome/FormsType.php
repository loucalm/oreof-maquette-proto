<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use App\Enum\NodeFamily;
use App\Repository\NodeTypeRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Étape « Formulaires » : un formulaire (NodeType de famille `parameter`, de la portée de l'emplacement) par emplacement. */
final class FormsType extends AbstractType
{
    public const SLOTS = ['formation.organisation', 'formation.presentation', 'parcours.organisation', 'parcours.presentation'];

    public function __construct(private readonly NodeTypeRepository $nodeTypes)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $parameters = array_filter($this->nodeTypes->findAllOrdered(), static fn ($t): bool => NodeFamily::Parameter === $t->getFamily());
        foreach (self::SLOTS as $slot) {
            // un emplacement `<portée>.<section>` ne propose que les formulaires de sa portée (convention `*_<portée>`, cf. ParamTypeResolver)
            $scope = explode('.', $slot)[0];
            $choices = [];
            foreach ($parameters as $nodeType) {
                if (str_ends_with($nodeType->getKey(), '_'.$scope)) {
                    $choices[$nodeType->getLabel().' ('.$nodeType->getKey().')'] = $nodeType->getKey();
                }
            }
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
        $resolver->setDefaults(['translation_domain' => 'diplome']);
    }

    /** Un nom de champ de formulaire ne peut pas contenir de point. */
    public static function fieldName(string $slot): string
    {
        return str_replace('.', '_', $slot);
    }
}
