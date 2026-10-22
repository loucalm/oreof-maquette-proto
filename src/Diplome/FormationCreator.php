<?php

declare(strict_types=1);

namespace App\Diplome;

use App\Diplome\Check\StructureViolationException;
use App\Diplome\Spec\DurationMath;
use App\Diplome\Spec\DurationSpec;
use App\Entity\Formation;
use App\Entity\TypeDiplome;
use App\Enum\FormationPhase;
use App\Maquette\Maquette;
use App\Repository\TypeDiplomeVersionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Crée une formation pilotée par un type de diplôme : version applicable à l'année
 * universitaire choisie, durée dans les bornes, maquette de départ générée par
 * StructureInstantiator. Toute demande hors règles est refusée AVANT d'écrire quoi que ce soit.
 */
final class FormationCreator
{
    /** Année universitaire au format AAAA-AAAA, la seconde année suivant la première. */
    private const YEAR_PATTERN = '/^(\d{4})-(\d{4})$/';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Maquette $maquette,
        private readonly TypeDiplomeVersionRepository $versions,
        private readonly StructureInstantiator $instantiator,
        private readonly TranslatorInterface $translator,
        private readonly DurationLabeler $durations,
    ) {
    }

    /**
     * @throws \DomainException message à afficher tel quel au responsable
     */
    public function create(
        string $name,
        TypeDiplome $type,
        string $year,
        int $duration,
        bool $avecParcours,
        FormationPhase $phase = FormationPhase::Construction,
        ?string $domaine = null,
        ?string $composante = null,
    ): Formation {
        if ($type->isArchived()) {
            throw new \DomainException($this->t('formation.creation.error.archived', ['%type%' => $type->getLabel()]));
        }
        if (1 !== preg_match(self::YEAR_PATTERN, $year, $m) || (int) $m[2] !== (int) $m[1] + 1) {
            throw new \DomainException($this->t('formation.creation.error.year'));
        }
        $version = $this->versions->findForYear($type, $year)
            ?? throw new \DomainException($this->t('formation.creation.error.no_version', ['%type%' => $type->getLabel(), '%year%' => $year]));

        $structure = $version->config()->structure;
        if (!StructureInstantiator::acceptsDuration($structure->duration?->bound, $duration)) {
            throw new \DomainException($this->t('formation.creation.error.duration', [
                '%type%' => $type->getLabel(),
                '%duration%' => $duration,
                '%bounds%' => $this->describeDuration($structure->duration),
            ]));
        }
        if (DurationMath::factor($structure) > 1 && 0 !== $duration % DurationMath::factor($structure)) {
            throw new \DomainException($this->t('formation.creation.error.duration_not_divisible', [
                '%duration%' => $duration,
                '%factor%' => DurationMath::factor($structure),
                '%unit%' => $this->durations->unit($structure->duration?->levelKey ?? ''),
                '%parent%' => $this->durations->unit(($structure->levels[0] ?? null)?->typeKey ?? '', 1),
            ]));
        }
        if ($avecParcours && !$structure->parcoursAllowed) {
            throw new \DomainException($this->t('formation.creation.error.no_parcours', ['%type%' => $type->getLabel()]));
        }

        try {
            // tout ou rien : un échec de génération ou du garde de structure ne laisse aucune formation
            return $this->em->wrapInTransaction(function () use ($name, $type, $year, $duration, $avecParcours, $phase, $domaine, $composante, $version, $structure): Formation {
                $formation = (new Formation($name))
                    ->setTypeDiplome($type)
                    ->setDomaine($domaine)
                    ->setComposante($composante)
                    ->setPhase($phase)
                    ->setAnneeUniversitaire($year)
                    ->setCalendarSpan(intdiv($duration, DurationMath::factor($structure)));

                $this->em->persist($formation);
                $this->em->flush();

                $doc = $this->maquette->open($formation);
                $this->instantiator->instantiate($doc, $version, $duration, $avecParcours);
                $this->maquette->save($formation, $doc, 'Création');

                return $formation;
            });
        } catch (\InvalidArgumentException|StructureViolationException $e) {
            throw new \DomainException($this->t('formation.creation.error.invalid', ['%type%' => $type->getLabel(), '%message%' => $e->getMessage()]), 0, $e);
        }
    }

    /** Durée acceptée par la version, en clair (« 6 semestres », « 3 à 4 années »…), pour l'aide du formulaire et les refus. */
    public function describeDuration(?DurationSpec $duration): string
    {
        return $this->durations->describe($duration) ?? $this->t('formation.creation.bounds.free');
    }

    /** @param array<string, scalar> $params */
    private function t(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params, 'formation');
    }
}
