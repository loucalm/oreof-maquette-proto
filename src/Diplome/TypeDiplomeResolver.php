<?php

declare(strict_types=1);

namespace App\Diplome;

use App\Diplome\Spec\VersionConfig;
use App\Entity\FicheMatiere;
use App\Entity\Formation;
use App\Entity\TypeDiplomeVersion;
use App\Repository\TypeDiplomeVersionRepository;

/**
 * Version de type de diplôme applicable à une formation : celle de son année
 * universitaire (défaut : l'année courante du prototype), à défaut la plus
 * récente antérieure. Une formation sans type n'a pas de version. Une fiche matière suit
 * sa formation porteuse (cf. versionForFiche()).
 */
final class TypeDiplomeResolver
{
    public const DEFAULT_YEAR = '2026-2027';

    public function __construct(private readonly TypeDiplomeVersionRepository $versions)
    {
    }

    public function versionFor(Formation $formation): ?TypeDiplomeVersion
    {
        $type = $formation->getTypeDiplome();
        if (null === $type) {
            return null;
        }
        $annee = $formation->getAnneeUniversitaire();

        return $this->versions->findForYear($type, null === $annee || '' === trim($annee) ? self::DEFAULT_YEAR : $annee);
    }

    public function configFor(Formation $formation): ?VersionConfig
    {
        return $this->versionFor($formation)?->config();
    }

    /**
     * Version qui s'applique à une fiche matière : celle de sa formation porteuse ; pour une fiche
     * hors diplôme, la dernière version de son type de diplôme ; sans type, aucune.
     */
    public function versionForFiche(FicheMatiere $fiche): ?TypeDiplomeVersion
    {
        $formation = $fiche->getFormation();
        if (null !== $formation) {
            return $this->versionFor($formation);
        }

        return $fiche->getTypeDiplome()?->latestVersion();
    }

    public function configForFiche(FicheMatiere $fiche): ?VersionConfig
    {
        return $this->versionForFiche($fiche)?->config();
    }
}
