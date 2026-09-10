<?php

declare(strict_types=1);

namespace App\Maquette;

/**
 * Listes de valeurs « référentiel » du prototype : ce qui, dans une vraie
 * instance ORéOF, viendrait d'une table d'administration (nomenclatures,
 * annuaires…). Ici ce sont des constantes, exposées en lecture dans
 * /administration/referentiels et réutilisées par les formulaires.
 */
final class Referentiels
{
    public const DIPLOMES = ['Licence', 'Licence professionnelle', 'BUT', 'Master', 'DU', 'Ingénieur', 'DAEU'];

    public const DOMAINES = [
        'Sciences, technologies, santé',
        'Droit, économie, gestion',
        'Sciences humaines et sociales',
        'Arts, lettres, langues',
    ];

    public const MENTIONS = ['Informatique', 'Mathématiques', 'Physique', 'MIAGE'];

    /** Régimes d'inscription (clé stockée => libellé). */
    public const REGIMES_INSCRIPTION = [
        'FI' => 'Formation Initiale',
        'FI_APP' => 'Formation Initiale en apprentissage',
        'FC' => 'Formation continue',
        'FC_PRO' => 'Formation continue en contrat de professionnalisation',
    ];

    public const MODALITES_ENSEIGNEMENT = [
        'En présentiel', 'À distance', 'Hybride (présentiel + distanciel)', 'En alternance',
    ];

    public const RYTHMES = ['Temps plein', 'Alternance', 'Temps partiel', 'À distance'];

    public const LANGUES = ['Français', 'Anglais', 'Français et anglais', 'Autre'];

    public const NIVEAUX_LANGUE = ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'];

    public const NIVEAUX_ENTREE = ['Baccalauréat', 'Bac+1', 'Bac+2', 'Bac+3', 'Bac+4'];

    public const NIVEAUX_SORTIE = ['Bac+2', 'Bac+3', 'Bac+5'];

    public const LOCALISATIONS = ['Campus principal', 'Campus secondaire', 'À distance'];

    /** Annuaire (fictif) des responsables sélectionnables. */
    public const RESPONSABLES = ['A. Martin', 'B. Dupont', 'C. Bernard'];

    public const CODES_ROME = [
        'M1805 — Études et développement informatique',
        'M1802 — Expertise et support en systèmes d’information',
        'M1806 — Conseil et maîtrise d’ouvrage en systèmes d’information',
        'H1206 — Management et ingénierie études, recherche et développement industriel',
    ];

    public const FICHES_MATIERE = [
        'Fiche — Algorithmique', 'Fiche — Programmation', 'Fiche — Bases de données', 'Fiche — Mathématiques',
    ];

    /**
     * Regroupement pour la page d'administration (titre => paires valeur/libellé).
     *
     * @return array<string, array{description: string, values: array<int|string, string>}>
     */
    public static function all(): array
    {
        $list = static fn (array $v): array => array_combine($v, $v);

        return [
            'Types de diplôme' => ['description' => 'Proposés à la création d’une formation.', 'values' => $list(self::DIPLOMES)],
            'Domaines de formation' => ['description' => 'Grands domaines disciplinaires.', 'values' => $list(self::DOMAINES)],
            'Mentions / spécialités' => ['description' => 'Intitulés de mention proposés.', 'values' => $list(self::MENTIONS)],
            'Régimes d’inscription' => ['description' => 'Modalités d’inscription administrative (choix multiples).', 'values' => self::REGIMES_INSCRIPTION],
            'Modalités d’enseignement' => ['description' => 'Présentiel, distance, hybride, alternance.', 'values' => $list(self::MODALITES_ENSEIGNEMENT)],
            'Rythmes de formation' => ['description' => 'Temps plein, alternance, temps partiel…', 'values' => $list(self::RYTHMES)],
            'Langues d’enseignement' => ['description' => 'Langues des cours (hors cours de langue).', 'values' => $list(self::LANGUES)],
            'Niveaux de langue (CECRL)' => ['description' => 'Niveau requis pour intégrer un parcours.', 'values' => $list(self::NIVEAUX_LANGUE)],
            'Niveaux d’entrée' => ['description' => 'Diplôme requis à l’entrée.', 'values' => $list(self::NIVEAUX_ENTREE)],
            'Niveaux de sortie' => ['description' => 'Niveau visé à la sortie.', 'values' => $list(self::NIVEAUX_SORTIE)],
            'Localisations' => ['description' => 'Sites d’enseignement.', 'values' => $list(self::LOCALISATIONS)],
            'Codes ROME' => ['description' => 'Métiers accessibles aux diplômés (France Travail).', 'values' => $list(self::CODES_ROME)],
        ];
    }
}
