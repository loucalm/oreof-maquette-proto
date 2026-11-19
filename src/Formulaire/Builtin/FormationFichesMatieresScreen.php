<?php

declare(strict_types=1);

namespace App\Formulaire\Builtin;

use App\Diplome\Spec\CanvasSpec;
use App\Entity\Formation;
use App\Maquette\Doc\TreeNode;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Écran intégré « Fiches matières » d'une formation sans parcours : fiches portées par son parcours unique. */
final class FormationFichesMatieresScreen implements BuiltinScreenInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function key(): string
    {
        return 'fiches_matieres_formation';
    }

    public function label(): string
    {
        return $this->translator->trans('fiche_matiere.index.title', [], 'fiche_matiere');
    }

    public function fiche(): string
    {
        return CanvasSpec::FORMATION;
    }

    public function hideable(): bool
    {
        return false;
    }

    public function defaultPosition(): int
    {
        return 1000; // après toutes les entrées de la fiche
    }

    public function appliesTo(Formation $formation): bool
    {
        return $formation->isSansParcours();
    }

    public function url(Formation $formation, ?TreeNode $parcours): string
    {
        return $this->urls->generate('fiche_matiere_index', ['fid' => $formation->getId()]);
    }

    public function status(Formation $formation, ?TreeNode $parcours): ?string
    {
        return null;
    }

    /** @param array<string, mixed> $query */
    public function isCurrent(array $query): bool
    {
        return !empty($query['fiches_matieres']);
    }
}
