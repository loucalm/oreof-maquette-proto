<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

use App\Diplome\WizardDraft;
use App\Form\Diplome\BccConfigType;

/** Étape « BCC » : noms des niveaux (liste libre, au moins un) et niveau relié aux éléments. */
final class BccStep extends AbstractWizardStep
{
    public function key(): string
    {
        return 'bcc';
    }

    public function position(): int
    {
        return 60;
    }

    public function editable(): bool
    {
        return true;
    }

    public function formType(): string
    {
        return BccConfigType::class;
    }

    public function initialData(WizardDraft $draft): array
    {
        $config = $draft->config();
        // le niveau relié par défaut (le dernier) est présenté comme « dernier niveau »
        $isDefault = [] === $config->bccLevels || $config->bccLinkLevel === \count($config->bccLevels) - 1;
        // un premier niveau à nommer pour un type neuf
        $data = [
            'levels' => [] === $config->bccLevels ? [''] : $config->bccLevels,
            'linkLevel' => $isDefault ? null : $config->bccLinkLevel,
        ];

        return $data;
    }

    public function apply(WizardDraft $draft, mixed $formData): void
    {
        $levels = array_values(array_filter(
            array_map(static fn ($n) => trim((string) $n), (array) ($formData['levels'] ?? [])),
            static fn (string $n) => '' !== $n,
        ));

        $config = $draft->configData;
        $config['bccLevels'] = $levels;
        $link = $formData['linkLevel'] ?? null;
        $config['bccLinkLevel'] = null === $link || '' === $link ? max(0, \count($levels) - 1) : (int) $link;
        $draft->configData = $config;
    }

    public function template(): string
    {
        return 'bcc';
    }
}
