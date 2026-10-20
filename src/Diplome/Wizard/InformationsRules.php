<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

use App\Diplome\WizardDraft;
use App\Repository\TypeDiplomeRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Règles de l'étape « Informations », partagées par le formulaire et par la
 * revalidation du brouillon à l'enregistrement.
 */
final class InformationsRules
{
    public function __construct(
        private readonly TypeDiplomeRepository $types,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /** Clé d'un type : sigle en majuscules, sans espace. */
    public static function normalizeKey(string $sigle): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', $sigle));
    }

    /** @return list<Constraint> */
    public function constraints(string $field): array
    {
        return match ($field) {
            'label' => [new Assert\NotBlank(), new Assert\Length(max: 120)],
            'sigle' => [new Assert\NotBlank(), new Assert\Length(max: 40), new Assert\Callback($this->validateUniqueSigle(...))],
            'codeApogee' => [new Assert\Length(max: 1)],
            'anneeUniversitaire' => [
                new Assert\NotBlank(),
                new Assert\Regex('/^\d{4}-\d{4}$/'),
                new Assert\Callback($this->validateConsecutiveYears(...)),
            ],
            default => [],
        };
    }

    /** @return list<string> Messages d'erreur (vide si le brouillon est valide). */
    public function violations(WizardDraft $draft): array
    {
        $data = $draft->informationsData();
        $fields = [];
        foreach (['label', 'sigle', 'codeApogee', 'anneeUniversitaire'] as $field) {
            $fields[$field] = $this->constraints($field);
            $data[$field] = '' === ($data[$field] ?? null) ? null : ($data[$field] ?? null);
        }
        $list = $this->validator->validate($data, new Assert\Collection(fields: $fields, allowExtraFields: true));

        $messages = [];
        foreach ($list as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        return $messages;
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

    private function validateConsecutiveYears(?string $value, ExecutionContextInterface $context): void
    {
        if (null !== $value && 1 === preg_match('/^(\d{4})-(\d{4})$/', $value, $m) && (int) $m[2] !== (int) $m[1] + 1) {
            $context->buildViolation('diplome.wizard.error.annee_consecutive')->setTranslationDomain('diplome')->addViolation();
        }
    }
}
