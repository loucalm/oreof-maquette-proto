<?php

declare(strict_types=1);

namespace App\FicheMatiere;

use App\Entity\FicheMatiere;
use App\Entity\Formation;
use App\Repository\FicheMatiereRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Fiches incomplètes portées par une formation, pour la page « Vérifier » (spec §9). */
final class IncompleteFiches
{
    public function __construct(
        private readonly FicheMatiereRepository $fiches,
        private readonly FicheMatiereUsage $usage,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    /**
     * @return list<array{fiche: FicheMatiere, status: string, url: string}>
     */
    public function ofFormation(Formation $formation): array
    {
        $rows = [];
        foreach ($this->fiches->ofFormation($formation) as $fiche) {
            $status = $this->usage->status($fiche);
            if ('ok' === $status) {
                continue;
            }
            $rows[] = [
                'fiche' => $fiche,
                'status' => $status,
                'url' => $this->router->generate('fiche_matiere_edit', [
                    'id' => $fiche->getId(),
                    'tab' => 'identite',
                ]),
            ];
        }

        return $rows;
    }
}
