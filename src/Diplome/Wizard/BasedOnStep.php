<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

use App\Diplome\WizardDraft;
use App\Form\Diplome\BasedOnType;
use App\Repository\TypeDiplomeVersionRepository;

final class BasedOnStep extends AbstractWizardStep
{
    public function __construct(private readonly TypeDiplomeVersionRepository $versions)
    {
    }

    public function key(): string
    {
        return 'depart';
    }

    public function position(): int
    {
        return 20;
    }

    public function formType(): string
    {
        return BasedOnType::class;
    }

    public function initialData(WizardDraft $draft): array
    {
        return ['basedOnVersionId' => $draft->basedOnVersionId];
    }

    public function apply(WizardDraft $draft, mixed $formData): void
    {
        $id = null === $formData['basedOnVersionId'] ? null : (int) $formData['basedOnVersionId'];
        if ($id === $draft->basedOnVersionId) {
            return;
        }
        $base = null === $id ? null : $this->versions->find($id);
        $draft->basedOnVersionId = $base?->getId();
        $draft->configData = $base?->config()->toArray() ?? [];
    }

    public function template(): string
    {
        return 'based_on';
    }
}
