<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

use App\Diplome\ConfigDiff;
use App\Diplome\Spec\VersionConfig;
use App\Diplome\WizardDraft;
use App\Formulaire\CanvasResolver;
use App\Repository\TypeDiplomeVersionRepository;

final class SummaryStep extends AbstractWizardStep
{
    public function __construct(
        private readonly TypeDiplomeVersionRepository $versions,
        private readonly ConfigPresenter $presenter,
        private readonly CanvasResolver $canvas,
    ) {
    }

    public function key(): string
    {
        return 'recapitulatif';
    }

    public function position(): int
    {
        return 70;
    }

    public function template(): string
    {
        return 'summary';
    }

    public function viewData(WizardDraft $draft): array
    {
        $base = null === $draft->basedOnVersionId ? null : $this->versions->find($draft->basedOnVersionId);
        $diff = [];
        if (null !== $base) {
            foreach (ConfigDiff::between($this->comparable($base->config()), $this->comparable($draft->config())) as $path => $change) {
                $diff[] = ['path' => $path, 'label' => $this->presenter->pathLabel($path)] + $change;
            }
        }

        return [
            'sections' => $this->presenter->sections($draft),
            'base' => $base,
            'diff' => $diff,
        ];
    }

    /**
     * Configuration à comparer : le canevas est remplacé par le canevas effectif (une version
     * sans canevas a le canevas par défaut), pour ne signaler que les vraies différences.
     *
     * @return array<string, mixed>
     */
    private function comparable(VersionConfig $config): array
    {
        return ['canvas' => $this->canvas->forConfig($config)->toArray()] + $config->toArray();
    }
}
