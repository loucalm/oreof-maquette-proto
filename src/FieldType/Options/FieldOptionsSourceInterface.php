<?php

declare(strict_types=1);

namespace App\FieldType\Options;

use App\Maquette\Doc\TreeNode;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Source d'options calculée pour un champ de formulaire de nœud (`FieldDef::optionsSource`) :
 * les options dépendent du nœud (donc de sa formation), pas d'une liste statique.
 * Ajouter une source = écrire une classe, `FieldOptionsSources` la trouve seule.
 */
#[AutoconfigureTag(self::TAG)]
interface FieldOptionsSourceInterface
{
    public const TAG = 'app.field_options_source';

    /** Valeur de `FieldDef::optionsSource`. */
    public function key(): string;

    /**
     * @return array<string, string> valeur => libellé (vide sans nœud)
     */
    public function options(?TreeNode $node): array;
}
