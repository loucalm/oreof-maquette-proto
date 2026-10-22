<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un type d'EC : sa clé (cachée, stable : un renommage ne la change pas) et son libellé. Un type ajouté
 * arrive sans clé ; elle est dérivée de son libellé. Données : `['key' => string, 'label' => string]`.
 */
final class EcTypeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('key', HiddenType::class, ['required' => false])
            ->add('label', TextType::class, [
                'label' => 'diplome.wizard.structure.ec_type_label',
                // le panneau EC peut être masqué : le contrôle se fait côté serveur
                'required' => false,
                'constraints' => [new Assert\NotBlank(message: 'diplome.wizard.structure.error.ec_type_label')],
            ]);

        $builder->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();
            if (!\is_array($data) || '' !== trim((string) ($data['key'] ?? ''))) {
                return;
            }
            $data['key'] = self::keyFor((string) ($data['label'] ?? ''));
            $event->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'diplome']);
    }

    /** Clé d'un type d'EC dérivée de son libellé (« Projet tutoré » → `projet_tutore`). */
    public static function keyFor(string $label): string
    {
        return (new AsciiSlugger('fr'))->slug(trim($label), '_')->lower()->toString();
    }
}
