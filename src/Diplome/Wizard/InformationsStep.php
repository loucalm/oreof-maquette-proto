<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

use App\Diplome\WizardDraft;
use App\Entity\TypeDiplomeVersion;
use App\Form\Diplome\InformationsType;
use App\Repository\FormationRepository;

final class InformationsStep extends AbstractWizardStep
{
    public function __construct(private readonly FormationRepository $formations)
    {
    }

    public function key(): string
    {
        return 'informations';
    }

    public function position(): int
    {
        return 10;
    }

    public function needsInformations(): bool
    {
        return false;
    }

    public function editable(): bool
    {
        return true;
    }

    public function editsType(): bool
    {
        return true;
    }

    /** En édition : pas d'année (celle de la version), sigle figé dès qu'une formation utilise le type. */
    public function editFormOptions(TypeDiplomeVersion $version): array
    {
        $type = $version->getTypeDiplome();

        return ['existing_type' => $type, 'sigle_locked' => $this->formations->countByTypeDiplome($type) > 0];
    }

    public function persist(TypeDiplomeVersion $version, WizardDraft $draft): void
    {
        $type = $version->getTypeDiplome();
        $info = $draft->informations;
        $type->setLabel(trim((string) $info['label']))
            ->setCodeApogee($this->nullIfEmpty($info['codeApogee'] ?? null))
            ->setDescription($this->nullIfEmpty($info['description'] ?? null))
            ->setAccredite((bool) ($info['accredite'] ?? false))
            ->setPassageCfvu((bool) ($info['passageCfvu'] ?? false));
        if (!$this->formations->countByTypeDiplome($type)) {
            $sigle = trim((string) $info['sigle']);
            $type->setSigle($sigle)->setKey(InformationsRules::normalizeKey($sigle));
        }
    }

    public function formType(): string
    {
        return InformationsType::class;
    }

    public function initialData(WizardDraft $draft): array
    {
        return $draft->informationsData();
    }

    public function apply(WizardDraft $draft, mixed $formData): void
    {
        $draft->anneeUniversitaire = (string) ($formData['anneeUniversitaire'] ?? $draft->anneeUniversitaire);
        unset($formData['anneeUniversitaire']);
        $draft->informations = $formData;
        $draft->informationsValid = true;
    }

    public function template(): string
    {
        return 'informations';
    }

    private function nullIfEmpty(mixed $v): ?string
    {
        return null === $v || '' === $v ? null : (string) $v;
    }
}
