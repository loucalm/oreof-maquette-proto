<?php

declare(strict_types=1);

namespace App\Formulaire\Builtin;

use App\Diplome\Spec\CanvasSpec;
use App\Entity\Formation;
use App\Maquette\Doc\TreeNode;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Écran intégré « Positionnement & ramification » d'un parcours. */
final class ParcoursPositionScreen implements BuiltinScreenInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function key(): string
    {
        return 'positionnement';
    }

    public function label(): string
    {
        return $this->translator->trans('formation.parcours_editor.ramification_section_label', [], 'formation');
    }

    public function fiche(): string
    {
        return CanvasSpec::PARCOURS;
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
        if (null === $parcours) {
            throw new \LogicException('Écran de parcours : aucun parcours fourni.');
        }

        return $this->urls->generate('node_panel', ['fid' => $formation->getId(), 'nid' => $parcours->getId()]);
    }

    public function status(Formation $formation, ?TreeNode $parcours): ?string
    {
        return null === $parcours ? null : 'ok';
    }

    /** @param array<string, mixed> $query */
    public function isCurrent(array $query): bool
    {
        return !empty($query['focus']);
    }
}
