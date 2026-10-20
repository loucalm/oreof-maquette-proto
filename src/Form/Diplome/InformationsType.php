<?php

declare(strict_types=1);

namespace App\Form\Diplome;

use App\Repository\TypeDiplomeRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Étape « Informations » : identité du type et année universitaire de la première version. */
final class InformationsType extends AbstractType
{
    public function __construct(private readonly TypeDiplomeRepository $types)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, ['constraints' => [new Assert\NotBlank(), new Assert\Length(max: 120)]])
            ->add('sigle', TextType::class, ['constraints' => [
                new Assert\NotBlank(),
                new Assert\Length(max: 40),
                new Assert\Callback($this->validateUniqueSigle(...)),
            ]])
            ->add('codeApogee', TextType::class, ['required' => false, 'constraints' => [new Assert\Length(max: 1)]])
            ->add('description', TextareaType::class, ['required' => false])
            ->add('accredite', CheckboxType::class, ['required' => false])
            ->add('passageCfvu', CheckboxType::class, ['required' => false])
            ->add('anneeUniversitaire', TextType::class, ['constraints' => [
                new Assert\NotBlank(),
                new Assert\Regex('/^\d{4}-\d{4}$/'),
                new Assert\Callback(static function (?string $value, ExecutionContextInterface $context): void {
                    if (null !== $value && 1 === preg_match('/^(\d{4})-(\d{4})$/', $value, $m) && (int) $m[2] !== (int) $m[1] + 1) {
                        $context->buildViolation('diplome.wizard.error.annee_consecutive')->setTranslationDomain('diplome')->addViolation();
                    }
                }),
            ]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'diplome',
            'csrf_protection' => false,
        ]);
    }

    /** Clé d'un type : sigle en majuscules, sans espace. */
    public static function normalizeKey(string $sigle): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', $sigle));
    }

    private function validateUniqueSigle(?string $value, ExecutionContextInterface $context): void
    {
        if (null === $value || '' === trim($value)) {
            return;
        }
        $key = self::normalizeKey($value);
        foreach ($this->types->findAllOrdered() as $type) {
            if ($key === self::normalizeKey($type->getKey()) || $key === self::normalizeKey($type->getSigle())) {
                $context->buildViolation('diplome.wizard.error.sigle_taken')->setTranslationDomain('diplome')->addViolation();

                return;
            }
        }
    }
}
