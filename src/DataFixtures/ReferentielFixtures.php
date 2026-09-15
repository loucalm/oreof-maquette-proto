<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Referentiel;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Nomenclatures « socle » de l'offre de formation (types de diplôme, domaines,
 * régimes d'inscription, langues…), amorcées ici puis entièrement éditables
 * dans /administration/referentiels.
 */
final class ReferentielFixtures extends Fixture
{
    private const SEED = [
        // [key, label, description, values]
        ['diplomes', 'Types de diplôme', 'Proposés à la création d’une formation.', [
            'Licence' => 'Licence', 'Licence professionnelle' => 'Licence professionnelle', 'BUT' => 'BUT',
            'Master' => 'Master', 'DU' => 'DU', 'Ingénieur' => 'Ingénieur', 'DAEU' => 'DAEU',
        ]],
        ['domaines', 'Domaines de formation', 'Grands domaines disciplinaires.', [
            'Sciences, technologies, santé' => 'Sciences, technologies, santé',
            'Droit, économie, gestion' => 'Droit, économie, gestion',
            'Sciences humaines et sociales' => 'Sciences humaines et sociales',
            'Arts, lettres, langues' => 'Arts, lettres, langues',
        ]],
        ['mentions', 'Mentions / spécialités', 'Intitulés de mention proposés.', [
            'Informatique' => 'Informatique', 'Mathématiques' => 'Mathématiques',
            'Physique' => 'Physique', 'MIAGE' => 'MIAGE',
        ]],
        ['regimes_inscription', 'Régimes d’inscription', 'Modalités d’inscription administrative (choix multiples).', [
            'FI' => 'Formation Initiale',
            'FI_APP' => 'Formation Initiale en apprentissage',
            'FC' => 'Formation continue',
            'FC_PRO' => 'Formation continue en contrat de professionnalisation',
        ]],
        ['modalites_enseignement', 'Modalités d’enseignement', 'Présentiel, distance, hybride, alternance.', [
            'En présentiel' => 'En présentiel', 'À distance' => 'À distance',
            'Hybride (présentiel + distanciel)' => 'Hybride (présentiel + distanciel)', 'En alternance' => 'En alternance',
        ]],
        ['rythmes', 'Rythmes de formation', 'Temps plein, alternance, temps partiel…', [
            'Temps plein' => 'Temps plein', 'Alternance' => 'Alternance',
            'Temps partiel' => 'Temps partiel', 'À distance' => 'À distance',
        ]],
        ['langues', 'Langues d’enseignement', 'Langues des cours (hors cours de langue).', [
            'Français' => 'Français', 'Anglais' => 'Anglais',
            'Français et anglais' => 'Français et anglais', 'Autre' => 'Autre',
        ]],
        ['niveaux_langue', 'Niveaux de langue (CECRL)', 'Niveau requis pour intégrer un parcours.', [
            'A1' => 'A1', 'A2' => 'A2', 'B1' => 'B1', 'B2' => 'B2', 'C1' => 'C1', 'C2' => 'C2',
        ]],
        ['niveaux_entree', 'Niveaux d’entrée', 'Diplôme requis à l’entrée.', [
            'Baccalauréat' => 'Baccalauréat', 'Bac+1' => 'Bac+1', 'Bac+2' => 'Bac+2',
            'Bac+3' => 'Bac+3', 'Bac+4' => 'Bac+4',
        ]],
        ['niveaux_sortie', 'Niveaux de sortie', 'Niveau visé à la sortie.', [
            'Bac+2' => 'Bac+2', 'Bac+3' => 'Bac+3', 'Bac+5' => 'Bac+5',
        ]],
        ['localisations', 'Localisations', 'Sites d’enseignement.', [
            'Campus principal' => 'Campus principal', 'Campus secondaire' => 'Campus secondaire', 'À distance' => 'À distance',
        ]],
        ['responsables', 'Annuaire des responsables', 'Responsables sélectionnables (annuaire fictif du prototype).', [
            'A. Martin' => 'A. Martin', 'B. Dupont' => 'B. Dupont', 'C. Bernard' => 'C. Bernard',
        ]],
        ['codes_rome', 'Codes ROME', 'Métiers accessibles aux diplômés (France Travail).', [
            'M1805 — Études et développement informatique' => 'M1805 — Études et développement informatique',
            'M1802 — Expertise et support en systèmes d’information' => 'M1802 — Expertise et support en systèmes d’information',
            'M1806 — Conseil et maîtrise d’ouvrage en systèmes d’information' => 'M1806 — Conseil et maîtrise d’ouvrage en systèmes d’information',
            'H1206 — Management et ingénierie études, recherche et développement industriel' => 'H1206 — Management et ingénierie études, recherche et développement industriel',
        ]],
        ['fiches_matiere', 'Fiches matière', 'Fiches réutilisables proposées sur un EC.', [
            'Fiche — Algorithmique' => 'Fiche — Algorithmique',
            'Fiche — Programmation' => 'Fiche — Programmation',
            'Fiche — Bases de données' => 'Fiche — Bases de données',
            'Fiche — Mathématiques' => 'Fiche — Mathématiques',
        ]],
        ['types_epreuve', 'Types d’épreuve (MCCC)', 'Proposés dans les collections d’évaluations d’un type de MCCC.', [
            'cc' => 'Contrôle continu', 'ct' => 'Contrôle terminal', 'oral' => 'Oral',
            'rapport' => 'Rapport / dossier', 'projet' => 'Projet',
        ]],
    ];

    public function load(ObjectManager $manager): void
    {
        foreach (self::SEED as $i => [$key, $label, $description, $values]) {
            $ref = (new Referentiel($key, $label))
                ->setDescription($description)
                ->setValues($values)
                ->setPosition($i * 10)
                ->setSystem(true);
            $manager->persist($ref);
        }

        $manager->flush();
    }
}
