<?php

declare(strict_types=1);

namespace App\Formulaire\Builtin;

use App\Diplome\Spec\CanvasSpec;
use App\Entity\Formation;
use App\Maquette\Completion;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Écran intégré « Référentiel de compétences » d'une formation sans parcours. */
final class FormationBccScreen implements BuiltinScreenInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly Completion $completion,
        private readonly Maquette $maquette,
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
        return CanvasSpec::FORMATION;
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
        return $formation->isSansParcours();
    }

    public function url(Formation $formation, ?TreeNode $parcours): string
    {
        return $this->urls->generate('bcc_editor', ['fid' => $formation->getId()]);
    }

    public function status(Formation $formation, ?TreeNode $parcours): ?string
    {
        // référentiel national porté par la formation : rien à saisir
        if ($formation->hasBccReferentiel()) {
            return 'ok';
        }

        return $this->completion->bccStatus($this->maquette->open($formation)->competenceBlocs());
    }

    /** @param array<string, mixed> $query */
    public function isCurrent(array $query): bool
    {
        return !empty($query['bcc']);
    }
}
