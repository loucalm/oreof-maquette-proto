<?php

declare(strict_types=1);

namespace App\FieldType;

use App\Entity\FieldDef;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Un type de saisie d'un champ (FieldDef::type). Chaque type est une classe :
 * en ajouter un = écrire une classe, le registre (FieldTypeRegistry) la trouve
 * seul et l'éditeur de champ des types d'ELP la propose. Le type décide :
 *   - des réglages proposés dans l'éditeur de champ (settings()),
 *   - du sens des bornes min / max (bounds()),
 *   - de ce qu'est une valeur « remplie » et des valeurs invalides (isFilled(), violations()).
 */
#[AutoconfigureTag(self::TAG)]
interface FieldTypeInterface
{
    public const TAG = 'app.field_type';

    /** Réglages possibles d'un champ dans l'éditeur. */
    public const SETTING_REFERENTIEL = 'referentiel';
    public const SETTING_OPTIONS = 'options';
    public const SETTING_ALLOW_EXTRA = 'allowExtra';
    public const SETTING_QUICK_ADD = 'quickAdd';

    /** Sens des bornes min / max. */
    public const BOUNDS_VALUE = 'value';
    public const BOUNDS_LENGTH = 'length';
    public const BOUNDS_COUNT = 'count';

    /** Identifiant stocké dans FieldDef::type (ex. "text"). */
    public function key(): string;

    public function label(): string;

    /** Ordre dans la liste des types de l'éditeur. */
    public function position(): int;

    /** @return list<self::SETTING_*> */
    public function settings(): array;

    /** @return self::BOUNDS_*|null null = pas de bornes pour ce type */
    public function bounds(): ?string;

    /** Rendu spécial, géré par le noyau (heures, MCCC, compétences). */
    public function isSystem(): bool;

    /** Faux pour un élément d'organisation (séparateur) : rien à saisir. */
    public function isInput(): bool;

    /** Gabarit Twig du widget de saisie (variables : def, key, value, name, options, node, errors, variant). */
    public function widgetTemplate(): string;

    /** Gabarit Twig de l'affichage en lecture seule (variables : def, value, options, node). */
    public function viewTemplate(): string;

    public function isFilled(mixed $value): bool;

    /**
     * Messages d'erreur d'une valeur déjà remplie, au regard de la définition du champ (bornes).
     *
     * @return list<string>
     */
    public function violations(mixed $value, FieldDef $def): array;
}
