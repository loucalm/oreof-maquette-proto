<?php

declare(strict_types=1);

namespace App\Formulaire;

use App\Diplome\Spec\CanvasSpec;
use App\Diplome\VersionUsage;
use App\Entity\Formation;
use App\Entity\NodeType;
use App\Entity\TypeDiplomeVersion;
use App\Repository\NodeTypeRepository;
use App\Repository\TypeDiplomeVersionRepository;

/**
 * Qui utilise un formulaire de la bibliothèque : une version l'utilise si une entrée de son
 * canevas (masquée ou non) désigne ce formulaire ou l'une de ses variantes descendantes.
 */
final class FormUsage
{
    public function __construct(
        private readonly TypeDiplomeVersionRepository $versions,
        private readonly NodeTypeRepository $types,
        private readonly CanvasResolver $canvas,
        private readonly FormResolver $forms,
        private readonly VersionUsage $usage,
    ) {
    }

    /** @return list<TypeDiplomeVersion> */
    public function versionsUsing(NodeType $form): array
    {
        $using = [];
        foreach ($this->versions->findAll() as $version) {
            $canvas = $this->canvas->forConfig($version->config());
            foreach (CanvasSpec::FICHES as $fiche) {
                foreach ($canvas->entries($fiche) as $entry) {
                    $entryForm = $this->canvas->form($entry);
                    if (null !== $entryForm && \in_array($form, $this->forms->lineage($entryForm), true)) {
                        $using[$version->getId()] = $version;
                        continue 3;
                    }
                }
            }
        }

        return array_values($using);
    }

    /** @return list<Formation> formations touchées par une modification du formulaire */
    public function formationsConcerned(NodeType $form): array
    {
        $formations = [];
        foreach ($this->versionsUsing($form) as $version) {
            foreach ($this->usage->formationsUsing($version) as $formation) {
                $formations[$formation->getId()] = $formation;
            }
        }

        return array_values($formations);
    }

    public function isUsed(NodeType $form): bool
    {
        return [] !== $this->versionsUsing($form);
    }

    public function hasVariants(NodeType $form): bool
    {
        foreach ($this->types->findAllOrdered() as $type) {
            if ($type->getParent() === $form) {
                return true;
            }
        }

        return false;
    }
}
