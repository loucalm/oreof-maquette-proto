<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Referentiel;
use App\Enum\RefCategory;
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
        // [key, label, description, values, category, entiteCible]
        ['diplomes', 'Types de diplôme', 'Proposés à la création d’une formation.', [
            'Licence' => 'Licence', 'Licence professionnelle' => 'Licence professionnelle', 'BUT' => 'BUT',
            'Master' => 'Master', 'DU' => 'DU', 'Ingénieur' => 'Ingénieur', 'DAEU' => 'DAEU',
        ], RefCategory::Libre, null],
        ['domaines', 'Domaines de formation', 'Grands domaines disciplinaires.', [
            'Sciences, technologies, santé' => 'Sciences, technologies, santé',
            'Droit, économie, gestion' => 'Droit, économie, gestion',
            'Sciences humaines et sociales' => 'Sciences humaines et sociales',
            'Arts, lettres, langues' => 'Arts, lettres, langues',
        ], RefCategory::Libre, null],
        ['mentions', 'Mentions / spécialités', 'Intitulés de mention proposés.', [
            'Informatique' => 'Informatique', 'Mathématiques' => 'Mathématiques',
            'Physique' => 'Physique', 'MIAGE' => 'MIAGE',
        ], RefCategory::Libre, null],
        ['regimes_inscription', 'Régimes d’inscription', 'Modalités d’inscription administrative (choix multiples).', [
            'FI' => 'Formation Initiale',
            'FI_APP' => 'Formation Initiale en apprentissage',
            'FC' => 'Formation continue',
            'FC_PRO' => 'Formation continue en contrat de professionnalisation',
        ], RefCategory::Libre, null],
        ['modalites_enseignement', 'Modalités d’enseignement', 'Présentiel, distance, hybride, alternance.', [
            'En présentiel' => 'En présentiel', 'À distance' => 'À distance',
            'Hybride (présentiel + distanciel)' => 'Hybride (présentiel + distanciel)', 'En alternance' => 'En alternance',
        ], RefCategory::Libre, null],
        ['rythmes', 'Rythmes de formation', 'Temps plein, alternance, temps partiel…', [
            'Temps plein' => 'Temps plein', 'Alternance' => 'Alternance',
            'Temps partiel' => 'Temps partiel', 'À distance' => 'À distance',
        ], RefCategory::Libre, null],
        ['langues', 'Langues d’enseignement', 'Langues des cours (hors cours de langue).', [
            'Français' => 'Français', 'Anglais' => 'Anglais',
            'Français et anglais' => 'Français et anglais', 'Autre' => 'Autre',
        ], RefCategory::Libre, null],
        ['niveaux_langue', 'Niveaux de langue (CECRL)', 'Niveau requis pour intégrer un parcours.', [
            'A1' => 'A1', 'A2' => 'A2', 'B1' => 'B1', 'B2' => 'B2', 'C1' => 'C1', 'C2' => 'C2',
        ], RefCategory::Libre, null],
        ['niveaux_entree', 'Niveaux d’entrée', 'Diplôme requis à l’entrée.', [
            'Baccalauréat' => 'Baccalauréat', 'Bac+1' => 'Bac+1', 'Bac+2' => 'Bac+2',
            'Bac+3' => 'Bac+3', 'Bac+4' => 'Bac+4',
        ], RefCategory::Libre, null],
        ['niveaux_sortie', 'Niveaux de sortie', 'Niveau visé à la sortie.', [
            'Bac+2' => 'Bac+2', 'Bac+3' => 'Bac+3', 'Bac+5' => 'Bac+5',
        ], RefCategory::Libre, null],
        ['localisations', 'Localisations', 'Sites d’enseignement.', [
            'Campus principal' => 'Campus principal', 'Campus secondaire' => 'Campus secondaire', 'À distance' => 'À distance',
        ], RefCategory::Libre, null],
        ['responsables', 'Annuaire des responsables', 'Responsables sélectionnables (annuaire fictif du prototype).', [
            'A. Martin' => 'A. Martin', 'B. Dupont' => 'B. Dupont', 'C. Bernard' => 'C. Bernard',
        ], RefCategory::Entite, 'Annuaire des personnels (ORéOF)'],
        ['codes_rome', 'Codes ROME', 'Métiers accessibles aux diplômés (France Travail).', [
            'M1805 — Études et développement informatique' => 'M1805 — Études et développement informatique',
            'M1802 — Expertise et support en systèmes d’information' => 'M1802 — Expertise et support en systèmes d’information',
            'M1806 — Conseil et maîtrise d’ouvrage en systèmes d’information' => 'M1806 — Conseil et maîtrise d’ouvrage en systèmes d’information',
            'H1206 — Management et ingénierie études, recherche et développement industriel' => 'H1206 — Management et ingénierie études, recherche et développement industriel',
        ], RefCategory::Entite, 'Répertoire ROME (France Travail, API externe)'],
        ['fiches_matiere', 'Fiches matière', 'Fiches réutilisables proposées sur un EC.', [
            'Fiche — Algorithmique' => 'Fiche — Algorithmique',
            'Fiche — Programmation' => 'Fiche — Programmation',
            'Fiche — Bases de données' => 'Fiche — Bases de données',
            'Fiche — Mathématiques' => 'Fiche — Mathématiques',
        ], RefCategory::Libre, null],
        ['types_epreuve', 'Types d’épreuve (MCCC)', 'Proposés dans les collections d’évaluations d’un type de MCCC.', [
            'cc' => 'Contrôle continu', 'ct' => 'Contrôle terminal', 'oral' => 'Oral',
            'rapport' => 'Rapport / dossier', 'projet' => 'Projet',
        ], RefCategory::Libre, null],
        ['etablissements', 'Établissements', 'Établissements porteurs d’une formation.', [
            'Université A' => 'Université A', 'Université B' => 'Université B',
        ], RefCategory::Entite, 'Établissement (annuaire ORéOF)'],
        ['composantes', 'Composantes', 'Composantes / UFR porteuses d’une formation.', [
            'UFR Sciences' => 'UFR Sciences', 'UFR Droit-Économie-Gestion' => 'UFR Droit-Économie-Gestion',
        ], RefCategory::Entite, 'Composante (annuaire ORéOF)'],
        ['plateformes_admission', 'Plateformes d’admission', 'Plateformes de candidature (Parcoursup, mon master…).', [
            'Parcoursup' => 'Parcoursup', 'Mon Master' => 'Mon Master', 'eCandidat' => 'eCandidat',
        ], RefCategory::Entite, 'Plateforme d’admission (intégration externe)'],
    ];

    public function load(ObjectManager $manager): void
    {
        foreach (self::SEED as $i => [$key, $label, $description, $values, $category, $entiteCible]) {
            $ref = (new Referentiel($key, $label))
                ->setDescription($description)
                ->setValues($values)
                ->setPosition($i * 10)
                ->setSystem(true)
                ->setCategory($category)
                ->setEntiteCible($entiteCible);
            $manager->persist($ref);
        }

        $manager->flush();
    }
}
