<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

use App\Diplome\WizardDraft;
use App\Form\Diplome\BccConfigType;

/** Étape « BCC » : noms des niveaux (1 à 3) et niveau relié aux éléments. */
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
        $data = ['linkLevel' => $config->bccLinkLevel];
        for ($i = 1; $i <= BccConfigType::MAX_LEVELS; ++$i) {
            $data['level'.$i] = $config->bccLevels[$i - 1] ?? null;
        }

        return $data;
    }

    public function apply(WizardDraft $draft, mixed $formData): void
    {
        $levels = [];
        for ($i = 1; $i <= BccConfigType::MAX_LEVELS; ++$i) {
            $name = trim((string) ($formData['level'.$i] ?? ''));
            if ('' !== $name) {
                $levels[] = $name;
            }
        }

        $config = $draft->configData;
        $config['bccLevels'] = $levels;
        $config['bccLinkLevel'] = (int) $formData['linkLevel'];
        $draft->configData = $config;
    }

    public function template(): string
    {
        return 'bcc';
    }
}
