<?php

declare(strict_types=1);

namespace App\Formulaire\Builtin;

use App\Diplome\Spec\CanvasSpec;
use App\Entity\Formation;
use App\Maquette\Doc\TreeNode;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Écran intégré « Configuration de la structure » d'une formation. */
final class StructureScreen implements BuiltinScreenInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function key(): string
    {
        return 'structure';
    }

    public function label(): string
    {
        return $this->translator->trans('formation.editor.structure_section_label', [], 'formation');
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
        return 40;
    }

    public function appliesTo(Formation $formation): bool
    {
        return true;
    }

    public function url(Formation $formation, ?TreeNode $parcours): string
    {
        return $this->urls->generate('formation_structure', ['id' => $formation->getId()]);
    }

    public function status(Formation $formation, ?TreeNode $parcours): ?string
    {
        return [] === $formation->getStructure() ? 'empty' : 'ok';
    }

    /** @param array<string, mixed> $query */
    public function isCurrent(array $query): bool
    {
        return 'structure' === ($query['param'] ?? null);
    }
}
