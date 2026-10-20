<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

use App\Diplome\WizardDraft;
use App\Entity\TypeDiplome;
use App\Entity\TypeDiplomeVersion;
use App\Repository\TypeDiplomeVersionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Crée le type et sa première version depuis un brouillon, après revalidation complète. */
final class DraftPublisher
{
    public function __construct(
        private readonly InformationsRules $rules,
        private readonly TypeDiplomeVersionRepository $versions,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** @throws \DomainException si le brouillon n'est plus valide (message lisible) */
    public function publish(WizardDraft $draft): TypeDiplome
    {
        $violations = $draft->informationsValid ? $this->rules->violations($draft) : [$this->translator->trans('diplome.wizard.error.incomplete', [], 'diplome')];
        if ([] !== $violations) {
            throw new \DomainException(implode(' ', $violations));
        }

        $info = $draft->informations;
        $sigle = trim((string) $info['sigle']);
        $type = new TypeDiplome(InformationsRules::normalizeKey($sigle), trim((string) $info['label']), $sigle);
        $type->setCodeApogee($this->nullIfEmpty($info['codeApogee'] ?? null))
            ->setDescription($this->nullIfEmpty($info['description'] ?? null))
            ->setAccredite((bool) ($info['accredite'] ?? false))
            ->setPassageCfvu((bool) ($info['passageCfvu'] ?? false));
        $base = null === $draft->basedOnVersionId ? null : $this->versions->find($draft->basedOnVersionId);
        $type->addVersion((new TypeDiplomeVersion($type, $draft->anneeUniversitaire))
            ->setConfig($draft->config())
            ->setBasedOn($base?->reference()));

        try {
            $this->em->persist($type);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw new \DomainException($this->translator->trans('diplome.wizard.error.sigle_taken', [], 'diplome'));
        }

        return $type;
    }

    private function nullIfEmpty(mixed $v): ?string
    {
        return null === $v || '' === $v ? null : (string) $v;
    }
}
