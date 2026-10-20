<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

use App\Diplome\ConfigDiff;
use App\Diplome\WizardDraft;
use App\Repository\TypeDiplomeVersionRepository;

final class SummaryStep extends AbstractWizardStep
{
    public function __construct(
        private readonly TypeDiplomeVersionRepository $versions,
        private readonly ConfigPresenter $presenter,
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
            foreach (ConfigDiff::between($base->config()->toArray(), $draft->config()->toArray()) as $path => $change) {
                $diff[] = ['path' => $path, 'label' => $this->presenter->pathLabel($path)] + $change;
            }
        }

        return [
            'sections' => $this->presenter->sections($draft),
            'base' => $base,
            'diff' => $diff,
        ];
    }
}
