<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\FieldDef;
use App\Maquette\AttributeCatalog;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Champs de formulaire « socle », amorcés depuis AttributeCatalog::SEED.
 * Tout est ensuite éditable depuis l'éditeur d'un type d'ELP (« Formulaire associé »).
 */
final class FieldDefFixtures extends Fixture
{
    /**
     * Champs des sections « Organisation »/« Présentation » (formation ET parcours —
     * un champ dont le contenu est identique aux deux niveaux est UNE seule ligne
     * partagée, cf. NodeTypeFixtures pour qui active quoi). `locked` = minimum
     * requis, ni modifiable ni retirable depuis le constructeur de formulaire :
     * les propriétés d'entité dédiées (Formation ou TreeNode) et les
     * responsables RF / RP (stockés eux dans le blob générique) — cf. FieldDef::isLocked().
     *
     * [key, label, tab, type, options, required, help, referentielKey, locked]
     */
    private const PARAM_SEED = [
        // ─── organisation ───
        ['name', 'Nom de la formation', 'organisation', 'text', [], true, null, null, true],
        ['diplome', 'Type de diplôme', 'organisation', 'choice', [], true, 'Fixé à la création de la formation, d’après son type de diplôme.', null, true],
        ['domaine', 'Domaine de formation', 'organisation', 'choice', [], true, null, 'domaines', true],
        ['composante', 'Composante porteuse de la formation', 'organisation', 'text', [], true, "Indiquer la composante porteuse du projet, qui aura en charge le dépôt de la demande de création de la formation", null, true],
        ['contacts', 'Contacts de la formation', 'organisation', 'textarea', [], false, null, null, false],
        ['sep_org_specifiques', 'Informations spécifiques', 'organisation', 'separator', [], false, null, null, false],
        ['mention', 'Mention / spécialité', 'organisation', 'choice', [], false, null, 'mentions', false],
        ['niveauEntree', "Niveau d'entrée en formation", 'organisation', 'choice', [], true, null, 'niveaux_entree', false],
        ['niveauSortie', 'Niveau de sortie de la formation', 'organisation', 'choice', [], true, null, 'niveaux_sortie', false],
        ['rncp', 'La formation est-elle inscrite au RNCP ?', 'organisation', 'radio', ['oui' => 'Oui', 'non' => 'Non'], true, null, null, false],
        ['codeRncp', 'Code RNCP', 'organisation', 'text', [], false, 'Indiquez le code RNCP (cf. www.francecompetences.fr).', null, false],
        ['nom', 'Nom du parcours', 'organisation', 'text', [], true, 'Nommer votre parcours', null, true],
        ['ectsTotal', "Volume d'ECTS total", 'organisation', 'number', [], true, 'Cela servira à vérifier le total d’ECTS de votre parcours', null, true],
        ['modalitesEnseignement', 'Modalités d’enseignement de la formation', 'organisation', 'choice', [], true, null, 'modalites_enseignement', false],
        ['composanteInscription', "Composante(s) à laquelle les étudiants pourront s'inscrire", 'organisation', 'choice', [], true, null, 'composantes', false],
        ['regimes', "Régime(s) d'inscription", 'organisation', 'checkbox', [], true, 'Choix multiples', 'regimes_inscription', false],
        ['modalitesAlternance', "Modalités de l'alternance", 'organisation', 'textarea', [], false, 'Indiquez en 3000 caractères maximum les périodes et leurs durées en centre ou en entreprise.', null, false],
        ['lieu', 'Lieu(x) de la formation', 'organisation', 'text', [], false, 'Campus ou site(s) où se déroule le parcours.', null, false],
        ['codeApogee', 'Code Apogée de la mention', 'organisation', 'text', [], false, 'Code de la mention dans Apogée', null, false],
        ['sep_org_responsables', 'Responsable(s)', 'organisation', 'separator', [], false, null, null, false],
        ['respMention', 'Responsable de la mention', 'organisation', 'choice', [], true, 'Responsable principale de la mention', 'responsables', true],
        ['coRespMention', 'Co-responsable de la mention', 'organisation', 'choice', [], false, 'Co-responsable de la mention', 'responsables', false],
        ['respParcours', 'Responsable du parcours', 'organisation', 'choice', [], true, 'Responsable principale du parcours', 'responsables', true],
        ['coRespParcours', 'Co-responsable du parcours', 'organisation', 'choice', [], false, 'Co-Responsable du parcours', 'responsables', false],
        // ─── présentation ───
        ['objectif', 'Objectif', 'presentation', 'textarea', [], true, 'Expliquez brièvement les objectifs de cet élément.', null, false],
        ['motsCles', 'Mots clés', 'presentation', 'textarea', [], true, 'Mots clés caractérisant le parcours.', null, false],
        ['resultats', 'Résultats attendus', 'presentation', 'textarea', [], true, 'Indiquez en 3000 caractères maximum les compétences acquises via la formation (compétences à acquérir, à améliorer ou à entretenir).', null, false],
        ['contenu', 'Contenu', 'presentation', 'textarea', [], true, 'Expliquez brièvement le contenu de cet élément.', null, false],
        ['langue', 'Langue', 'presentation', 'choice', [], true, "Langue dans laquelle sont dispensés les cours (à l'exception des cours de langue).", 'langues', false],
        ['niveauLangue', 'Niveau de langue', 'presentation', 'choice', [], true, 'Indiquez le niveau de langue souhaité pour intégrer le parcours.', 'niveaux_langue', false],
        ['sep_pres_rythme', 'Rythme de formation', 'presentation', 'separator', [], false, null, null, false],
        ['rythme', 'Rythme', 'presentation', 'choice', [], false, 'Choisissez un rythme de formation ou précisez-le ci-dessous.', 'rythmes', false],
        ['rythmePrecision', 'Précision du rythme', 'presentation', 'textarea', [], false, null, null, false],
        ['sep_pres_contacts', 'Contacts', 'presentation', 'separator', [], false, null, null, false],
        ['contactsParcours', 'Contacts', 'presentation', 'textarea', [], false, null, null, false],
        ['sep_pres_apres', 'Et après…', 'presentation', 'separator', [], false, null, null, false],
        ['poursuiteEtudes', "Poursuite d'études", 'presentation', 'textarea', [], true, "Indiquez en 3000 caractères maximum quelles sont les poursuites d'études envisageables.", null, false],
        ['debouches', 'Débouchés', 'presentation', 'textarea', [], true, "Indiquez les principaux débouchés professionnels accessibles à l'issue de cette formation.", null, false],
        ['codesRome', 'Code(s) ROME', 'presentation', 'choice', [], true, 'Ajoutez les codes ROME pour préciser les métiers accessibles aux diplômés.', 'codes_rome', false],
    ];

    public function load(ObjectManager $manager): void
    {
        foreach (AttributeCatalog::SEED as $i => [$key, $label, $tab, $type, $options, $required, $help, $referentielKey, $placeholder, $allowExtra]) {
            $field = (new FieldDef($key, $label))
                ->setTab($tab)
                ->setType($type)
                ->setOptions($options)
                ->setReferentielKey($referentielKey)
                ->setPlaceholder($placeholder)
                ->setAllowExtra($allowExtra)
                ->setRequired($required)
                ->setHelp($help)
                ->setPosition($i * 10)
                ->setSystem(true);
            $manager->persist($field);
        }

        // nature d'élément (ec, stage, ressource, sae…) : options tirées des natures de la version du type de diplôme
        $manager->persist((new FieldDef('natureElement', "Nature d'élément"))
            ->setTab('props')
            ->setType('choice')
            ->setOptionsSource('version_natures')
            ->setPlaceholder("Choisir la nature de l'élément")
            ->setHelp("Nature déclarée par le type de diplôme ; elle détermine les types de MCCC proposés.")
            ->setPosition(45)
            ->setSystem(true));

        $orgBase = 200;
        $presBase = 300;
        $orgI = 0;
        $presI = 0;
        foreach (self::PARAM_SEED as [$key, $label, $tab, $type, $options, $required, $help, $referentielKey, $locked]) {
            $position = 'organisation' === $tab ? $orgBase + 2 * $orgI++ : $presBase + 2 * $presI++;
            $field = (new FieldDef($key, $label))
                ->setTab($tab)
                ->setType($type)
                ->setOptions($options)
                ->setReferentielKey($referentielKey)
                ->setAllowExtra(\in_array($key, ['mention', 'composanteInscription', 'respMention', 'coRespMention', 'respParcours', 'coRespParcours', 'langue', 'niveauLangue', 'codesRome'], true))
                ->setRequired($required)
                ->setHelp($help)
                ->setPosition($position)
                ->setLocked($locked)
                // l'aide annonce « 3000 caractères maximum » : la borne la fait respecter
                ->setMax(str_contains((string) $help, '3000 caractères') ? 3000 : null)
                ->setSystem(true);
            $manager->persist($field);
        }

        $manager->flush();
    }
}
