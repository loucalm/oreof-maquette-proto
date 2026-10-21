<?php

declare(strict_types=1);

namespace App\Formulaire\Builtin;

use App\Diplome\Spec\CanvasSpec;
use App\Entity\Formation;
use App\Maquette\Doc\TreeNode;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Écran intégré « Arborescence des parcours » d'une formation avec parcours. */
final class RamificationScreen implements BuiltinScreenInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function key(): string
    {
        return 'ramification';
    }

    public function label(): string
    {
        return $this->translator->trans('formation.editor.ramification_section_label', [], 'formation');
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
        return 31;
    }

    public function appliesTo(Formation $formation): bool
    {
        return $formation->isAvecParcours();
    }

    public function url(Formation $formation, ?TreeNode $parcours): string
    {
        return $this->urls->generate('formation_parcours_graph', ['id' => $formation->getId()]);
    }

    public function status(Formation $formation, ?TreeNode $parcours): ?string
    {
        return null;
    }

    /** @param array<string, mixed> $query */
    public function isCurrent(array $query): bool
    {
        return empty($query['param']) && empty($query['bcc']) && empty($query['focus']);
    }
}
