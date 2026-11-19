<?php

declare(strict_types=1);

namespace App\FieldType\Options;

use App\Entity\FicheMatiere;
use App\Maquette\Doc\TreeNode;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Source d'options calculée pour un champ de formulaire (`FieldDef::optionsSource`) : les options
 * dépendent du sujet saisi — un nœud (donc sa formation) ou une fiche matière (donc sa version) —,
 * pas d'une liste statique.
 * Ajouter une source = écrire une classe, `FieldOptionsSources` la trouve seule.
 */
#[AutoconfigureTag(self::TAG)]
interface FieldOptionsSourceInterface
{
    public const TAG = 'app.field_options_source';

    /** Valeur de `FieldDef::optionsSource`. */
    public function key(): string;

    /**
     * @return array<string, string> valeur => libellé (vide sans sujet)
     */
    public function options(TreeNode|FicheMatiere|null $subject): array;
}
