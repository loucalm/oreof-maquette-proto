<?php

declare(strict_types=1);

namespace App\Formulaire\Preview;

use App\Diplome\Spec\CanvasSpec;
use App\Entity\TypeDiplome;
use App\Entity\TypeDiplomeVersion;
use App\Maquette\Maquette;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Démarre un aperçu de canevas sur une formation hôte et donne l'URL de son éditeur (fiche formation, ou premier parcours). */
final class PreviewStarter
{
    public function __construct(
        private readonly PreviewHostFinder $hosts,
        private readonly PreviewContext $preview,
        private readonly Maquette $maquette,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** @return string|null URL de l'éditeur de l'hôte, null si aucune formation ne peut servir d'hôte */
    public function start(CanvasSpec $canvas, string $fiche, string $returnUrl, ?TypeDiplomeVersion $version, ?TypeDiplome $type): ?string
    {
        $host = $this->hosts->find($version, $type, CanvasSpec::PARCOURS === $fiche);
        if (null === $host) {
            return null;
        }
        $this->preview->start($host, $canvas, $returnUrl);
        $parcours = CanvasSpec::PARCOURS === $fiche && $host->isAvecParcours() ? ($this->maquette->open($host)->parcoursNodes()[0] ?? null) : null;

        return null !== $parcours
            ? $this->urls->generate('parcours_editor', ['fid' => $host->getId(), 'nid' => $parcours->getId()])
            : $this->urls->generate('formation_editor', ['id' => $host->getId()]);
    }
}
