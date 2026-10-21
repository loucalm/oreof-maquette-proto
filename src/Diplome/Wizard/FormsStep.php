<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

use App\Diplome\Spec\CanvasEntry;
use App\Diplome\WizardDraft;
use App\Formulaire\CanvasResolver;
use App\Form\Diplome\FormsType;

final class FormsStep extends AbstractWizardStep
{
    public function __construct(private readonly CanvasResolver $canvas)
    {
    }

    public function key(): string
    {
        return 'formulaires';
    }

    public function position(): int
    {
        return 30;
    }

    public function editable(): bool
    {
        return true;
    }

    public function formType(): string
    {
        return FormsType::class;
    }

    public function initialData(WizardDraft $draft): array
    {
        $canvas = $this->canvas->forConfig($draft->config());
        $data = [];
        foreach (FormsType::SLOTS as $slot) {
            [$fiche, $key] = explode('.', $slot);
            $data[FormsType::fieldName($slot)] = $canvas->entry($fiche, $key)?->form;
        }

        return $data;
    }

    public function apply(WizardDraft $draft, mixed $formData): void
    {
        // les entrées que le formulaire ne propose pas sont conservées
        $canvas = $this->canvas->forConfig($draft->config());
        foreach (FormsType::SLOTS as $slot) {
            [$fiche, $key] = explode('.', $slot);
            $value = $formData[FormsType::fieldName($slot)] ?? null;
            $form = null !== $value && '' !== $value ? (string) $value : 'param_'.$key.'_'.$fiche;
            $canvas = $canvas->withEntries($fiche, array_map(
                static fn (CanvasEntry $e) => $e->key === $key && !$e->isBuiltin() ? new CanvasEntry($e->key, $form, null, $e->label, $e->hidden) : $e,
                $canvas->entries($fiche),
            ));
        }
        $config = $draft->configData;
        unset($config['forms']);
        $config['canvas'] = $canvas->toArray();
        $draft->configData = $config;
    }

    public function template(): string
    {
        return 'forms';
    }
}
