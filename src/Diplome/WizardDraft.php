<?php

declare(strict_types=1);

namespace App\Diplome;

use App\Diplome\Spec\VersionConfig;
use App\Entity\TypeDiplomeVersion;

/**
 * Brouillon de l'assistant « Nouveau diplôme », conservé en session sous
 * `diplome_wizard`. Objet simple et sérialisable : la configuration est gardée
 * sous sa forme tableau, `config()` la relit de façon typée.
 */
final class WizardDraft
{
    public const SESSION_KEY = 'diplome_wizard';

    /**
     * @param array<string, mixed> $informations   label, sigle, codeApogee, description, accredite, passageCfvu
     * @param array<string, mixed> $configData     forme tableau de VersionConfig
     */
    public function __construct(
        public array $informations = [],
        public ?int $basedOnVersionId = null,
        public array $configData = [],
        public string $anneeUniversitaire = '',
        public bool $informationsValid = false,
    ) {
    }

    /** Brouillon qui part de la configuration d'une version existante (duplication). */
    public static function fromVersion(TypeDiplomeVersion $version): self
    {
        $draft = new self();
        $draft->basedOnVersionId = $version->getId();
        $draft->configData = $version->config()->toArray();
        $draft->anneeUniversitaire = $version->getAnneeUniversitaire();

        return $draft;
    }

    public function config(): VersionConfig
    {
        return VersionConfig::fromArray($this->configData);
    }

    /**
     * Données de l'étape « Informations » avec leurs valeurs par défaut.
     *
     * @return array<string, mixed>
     */
    public function informationsData(): array
    {
        return $this->informations + [
            'label' => null,
            'sigle' => null,
            'codeApogee' => null,
            'description' => null,
            'accredite' => true,
            'passageCfvu' => true,
            'anneeUniversitaire' => $this->anneeUniversitaire,
        ];
    }
}
