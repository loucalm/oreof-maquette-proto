<?php

declare(strict_types=1);

namespace App\FicheMatiere;

use App\Diplome\Spec\CanvasSpec;
use App\Diplome\Spec\VersionConfig;
use App\Diplome\TypeDiplomeResolver;
use App\Entity\FicheMatiere;
use App\Entity\NodeType;
use App\Entity\TypeDiplomeVersion;
use App\FieldType\CompetenciesFieldType;
use App\Formulaire\CanvasResolver;
use App\Formulaire\FormResolver;
use App\Formulaire\ResolvedField;

/**
 * Formulaire d'une fiche matière : celui que l'entrée unique de la fiche matière du canevas
 * de sa version désigne (socle ou variante), et les options MCCC de cette version.
 *
 * Version : celle de la formation porteuse ; pour une fiche hors diplôme, la dernière version
 * de son type de diplôme ; sans type, aucune — la fiche prend alors le formulaire par défaut
 * (cf. TypeDiplomeResolver::versionForFiche()).
 */
final class FicheMatiereForms
{
    /** Onglets de la fiche, dans l'ordre ; « mutualisation » est un écran sans champ. */
    public const TABS = ['identite', 'mutualisation', 'presentation', 'competences', 'volume_horaire', 'mccc'];

    /** Onglet des compétences : absent d'une fiche hors diplôme (pas de porteur, donc pas de BCC). */
    public const TAB_COMPETENCES = 'competences';

    public function __construct(
        private readonly TypeDiplomeResolver $versions,
        private readonly CanvasResolver $canvas,
        private readonly FormResolver $forms,
    ) {
    }

    public function versionFor(FicheMatiere $fiche): ?TypeDiplomeVersion
    {
        return $this->versions->versionForFiche($fiche);
    }

    public function configFor(FicheMatiere $fiche): ?VersionConfig
    {
        return $this->versionFor($fiche)?->config();
    }

    /** Formulaire de la fiche ; un formulaire désigné qui n'existe plus laisse place au formulaire par défaut. */
    public function formFor(FicheMatiere $fiche): NodeType
    {
        return $this->formOf($this->configFor($fiche)) ?? $this->formOf(null)
            ?? throw new \LogicException('Aucun formulaire de fiche matière dans la bibliothèque.');
    }

    /**
     * Champs visibles du formulaire de la fiche ; sans les compétences pour une fiche hors diplôme.
     *
     * @return list<ResolvedField>
     */
    public function fields(FicheMatiere $fiche): array
    {
        $fields = $this->forms->fields($this->formFor($fiche));
        if (null !== $fiche->getFormation()) {
            return $fields;
        }

        return array_values(array_filter(
            $fields,
            static fn (ResolvedField $f) => self::TAB_COMPETENCES !== $f->tab && CompetenciesFieldType::KEY !== $f->getType(),
        ));
    }

    /** @return array{quitus: bool, assiduite: bool, sansNote: bool} options MCCC de la version ; toutes fausses sans version */
    public function options(FicheMatiere $fiche): array
    {
        return $this->configFor($fiche)?->options ?? ['quitus' => false, 'assiduite' => false, 'sansNote' => false];
    }

    private function formOf(?VersionConfig $config): ?NodeType
    {
        $entry = $this->canvas->forConfig($config)->entry(CanvasSpec::FICHE_MATIERE, CanvasSpec::FICHE_MATIERE);

        return null !== $entry ? $this->canvas->form($entry) : null;
    }
}
