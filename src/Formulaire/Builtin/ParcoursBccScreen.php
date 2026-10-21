<?php

declare(strict_types=1);

namespace App\Formulaire\Builtin;

use App\Diplome\Spec\CanvasSpec;
use App\Entity\Formation;
use App\Maquette\Completion;
use App\Maquette\Doc\TreeNode;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Écran intégré « Référentiel de compétences » d'un parcours. */
final class ParcoursBccScreen implements BuiltinScreenInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly Completion $completion,
    ) {
    }

    public function key(): string
    {
        return 'bcc';
    }

    public function label(): string
    {
        return $this->translator->trans('formation.editor.bcc_section_label', [], 'formation');
    }

    public function fiche(): string
    {
        return CanvasSpec::PARCOURS;
    }

    public function hideable(): bool
    {
        return true;
    }

    public function defaultPosition(): int
    {
        return 30;
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

        return $this->urls->generate('bcc_parcours_editor', ['fid' => $formation->getId(), 'nid' => $parcours->getId()]);
    }

    public function status(Formation $formation, ?TreeNode $parcours): ?string
    {
        // référentiel national porté par la formation : rien à saisir
        if ($formation->hasBccReferentiel()) {
            return 'ok';
        }
        if (null === $parcours) {
            return null;
        }

        return $this->completion->bccStatus($parcours->getBccBlocs());
    }

    /** @param array<string, mixed> $query */
    public function isCurrent(array $query): bool
    {
        return !empty($query['bcc']);
    }
}
