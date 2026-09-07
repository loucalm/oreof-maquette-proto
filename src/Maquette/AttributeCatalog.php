<?php

declare(strict_types=1);

namespace App\Maquette;

/**
 * Catalogue des attributs pédagogiques normalisés.
 *
 * Un attribut n'est saisissable sur un nœud que si la *capacité* du même nom est
 * active (NodeType::capabilities, éventuellement surchargée par le nœud).
 * Le diplôme / la structure décide donc « où vit quoi », pas le code.
 *
 * Chaque entrée : domain (onglet), label, type de champ, options éventuelles,
 * et `required` = compte pour le statut de complétude.
 */
final class AttributeCatalog
{
    public const DOMAIN_PROPS = 'props';
    public const DOMAIN_HOURS = 'volume_horaire';
    public const DOMAIN_MCCC = 'mccc';

    /** Sous-champs du volume horaire : modalité x lieu. */
    public const HOUR_MODALITIES = ['cm' => 'CM', 'td' => 'TD', 'tp' => 'TP'];
    public const HOUR_PLACES = ['pres' => 'Présentiel', 'dist' => 'Distanciel', 'travail' => 'Travail étudiant'];

    /**
     * @return array<string, array{
     *     domain: string, label: string, field: string,
     *     options?: array<string, string>, required?: bool, help?: string
     * }>
     */
    public static function all(): array
    {
        return [
            'ects' => [
                'domain' => self::DOMAIN_PROPS,
                'label' => 'ECTS',
                'field' => 'number',
                'required' => true,
                'help' => 'Crédits ECTS portés par ce nœud.',
            ],
            'ueType' => [
                'domain' => self::DOMAIN_PROPS,
                'label' => "Type d'UE",
                'field' => 'choice',
                'options' => [
                    'disciplinaire' => 'Disciplinaire',
                    'transversale' => 'Transversale',
                    'langue' => 'Langue',
                    'projet' => 'Projet / stage',
                    'libre' => 'Ouverture / libre',
                ],
                'required' => false,
            ],
            'nature' => [
                'domain' => self::DOMAIN_PROPS,
                'label' => "Nature de l'élément",
                'field' => 'choice',
                'options' => [
                    'obligatoire' => 'Obligatoire',
                    'choix_libre' => 'À choix libre',
                    'choix_restreint' => 'À choix restreint',
                    'specifique_sante' => 'Spécifique santé facultative',
                ],
                'required' => true,
            ],
            'competencies' => [
                'domain' => self::DOMAIN_PROPS,
                'label' => 'Compétences associées',
                'field' => 'competencies',
                'required' => false,
                'help' => 'Sélection de compétences du référentiel (BCC).',
            ],
            'ficheMatiere' => [
                'domain' => self::DOMAIN_PROPS,
                'label' => 'Fiche matière',
                'field' => 'text',
                'required' => false,
                'help' => 'Intitulé de la fiche matière obligatoire rattachée.',
            ],
            'hours' => [
                'domain' => self::DOMAIN_HOURS,
                'label' => 'Volume horaire',
                'field' => 'hours',
                'required' => true,
            ],
            'mccc' => [
                'domain' => self::DOMAIN_MCCC,
                'label' => 'MCCC',
                'field' => 'mccc',
                'required' => true,
            ],
        ];
    }

    /** Capacités qui ne sont pas des attributs de saisie mais des drapeaux. */
    public const FLAGS = [
        'mutualisable' => 'Nœud mutualisable / raccrochable',
    ];

    public static function domains(): array
    {
        return [
            self::DOMAIN_PROPS => 'Propriétés',
            self::DOMAIN_HOURS => 'Volume horaire',
            self::DOMAIN_MCCC => 'MCCC',
        ];
    }

    /** @return list<string> capacités connues (attributs + flags) */
    public static function knownCapabilities(): array
    {
        return [...array_keys(self::all()), ...array_keys(self::FLAGS)];
    }

    /**
     * Somme des heures d'un sac d'attributs `hours`.
     *
     * @param array<string, mixed>|null $hours
     */
    public static function sumHours(mixed $hours): float
    {
        if (!\is_array($hours)) {
            return 0.0;
        }
        $total = 0.0;
        foreach (self::HOUR_MODALITIES as $m => $_) {
            foreach (self::HOUR_PLACES as $p => $_p) {
                $total += (float) ($hours[$m][$p] ?? 0);
            }
        }

        return $total;
    }
}
