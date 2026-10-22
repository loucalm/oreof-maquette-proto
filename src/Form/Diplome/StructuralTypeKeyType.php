<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use App\Enum\NodeFamily;
use App\Repository\NodeTypeRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Choix d'un type de nœud de la famille structurelle (année, semestre, UE, EC…), par sa clé ; une variante de type n'est pas un niveau. */
final class StructuralTypeKeyType extends AbstractType
{
    public function __construct(private readonly NodeTypeRepository $nodeTypes)
    {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'choices' => $this->choices(),
            'placeholder' => 'diplome.wizard.structure.choose',
            'choice_translation_domain' => false,
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    /** @return array<string, string> libellé => clé */
    private function choices(): array
    {
        $choices = [];
        foreach ($this->nodeTypes->findAllOrdered() as $type) {
            if (NodeFamily::Structural === $type->getFamily() && !$type->isVariant()) {
                $choices[$type->getLabel().' ('.$type->getKey().')'] = $type->getKey();
            }
        }

        return $choices;
    }
}
