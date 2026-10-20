<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use App\Entity\TypeDiplomeVersion;
use App\Repository\TypeDiplomeRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Étape « Type de départ » : version existante dont on copie la configuration (ou aucune). */
final class BasedOnType extends AbstractType
{
    public function __construct(private readonly TypeDiplomeRepository $types)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach ($this->types->findAllOrdered() as $type) {
            foreach ($type->getVersions() as $version) {
                /** @var TypeDiplomeVersion $version */
                $choices[$type->getLabel().' — '.$version->getAnneeUniversitaire()] = $version->getId();
            }
        }

        $builder->add('basedOnVersionId', ChoiceType::class, [
            'choices' => $choices,
            'required' => false,
            'placeholder' => 'diplome.wizard.depart.none',
            'label' => 'diplome.wizard.depart.field',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'diplome']);
    }
}
