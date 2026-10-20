<?php

declare(strict_types=1);

namespace App\Diplome;

use App\Diplome\Spec\VersionConfig;
use App\Entity\Formation;
use App\Entity\TypeDiplomeVersion;
use App\Repository\TypeDiplomeVersionRepository;

/**
 * Version de type de diplôme applicable à une formation : celle de son année
 * universitaire (défaut : l'année courante du prototype), à défaut la plus
 * récente antérieure. Une formation sans type n'a pas de version.
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
}
