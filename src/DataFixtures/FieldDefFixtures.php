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
        ['sigle', 'Sigle de la formation', 'organisation', 'text', [], false, 'Facultatif, 250 caractères maximum.', null, false],
        ['contacts', 'Contacts de la formation', 'organisation', 'textarea', [], false, null, null, false],
        ['sep_org_specifiques', 'Informations spécifiques', 'organisation', 'separator', [], false, null, null, false],
        ['mention', 'Mention / spécialité', 'organisation', 'choice', [], false, null, 'mentions', false],
        ['niveauEntree', "Niveau d'entrée en formation", 'organisation', 'choice', [], true, null, 'niveaux_entree', false],
        ['niveauSortie', 'Niveau de sortie de la formation', 'organisation', 'choice', [], true, null, 'niveaux_sortie', false],
        ['rncp', 'La formation est-elle inscrite au RNCP ?', 'organisation', 'radio', ['oui' => 'Oui', 'non' => 'Non'], true, null, null, false],
        ['codeRncp', 'Code RNCP', 'organisation', 'text', [], false, 'Indiquez le code RNCP (cf. www.francecompetences.fr).', null, false],
        ['nom', 'Nom du parcours', 'organisation', 'text', [], true, 'Nommer votre parcours', null, true],
        ['ectsTotal', "Volume d'ECTS total", 'organisation', 'number', [], true, 'Cela servira à vérifier le total d’ECTS de votre parcours', null, true],
        ['localisations', 'Localisation(s) de la formation', 'organisation', 'checkbox', [], true, 'Au moins un lieu. Un parcours dispensé sur plusieurs sites est à dupliquer : un parcours par lieu de formation.', 'villes', false],
        ['lieu', 'Précision du lieu', 'organisation', 'text', [], false, 'Campus, site, ou ville non proposée dans la liste.', null, false],
        ['composanteInscription', "Composante(s) à laquelle les étudiants pourront s'inscrire", 'organisation', 'choice', [], true, null, 'composantes', false],
        ['regimes', "Régime(s) d'inscription", 'organisation', 'checkbox', [], true, 'Choix multiples', 'regimes_inscription', false],
        ['modalitesAlternance', "Modalités de l'alternance", 'organisation', 'textarea', [], false, 'Indiquez en 3000 caractères maximum les périodes et leurs durées en centre ou en entreprise.', null, false],
        // ─── descriptif du parcours : chaque rubrique se masque par type de diplôme (variante du formulaire) ───
        ['sep_org_descriptif', 'Descriptif', 'organisation', 'separator', [], false, null, null, false],
        ['modalitesEnseignement', 'Modalités d’enseignement de la formation', 'organisation', 'choice', [], true, null, 'modalites_enseignement', false],
        ['stage', 'Stage(s)', 'organisation', 'radio', ['oui' => 'Oui', 'non' => 'Non'], false, null, null, false],
        ['stageDescription', 'Description du / des stage(s)', 'organisation', 'textarea', [], false, 'Indiquez en 3000 caractères maximum le nombre de stages, le(s) semestre(s) concerné(s) et leurs modalités.', null, false],
        ['stageHeures', "Nombre d'heures de stage(s) prévu", 'organisation', 'number', [], false, null, null, false],
        ['projetTutore', 'Projet(s) tutoré(s)', 'organisation', 'radio', ['oui' => 'Oui', 'non' => 'Non'], false, null, null, false],
        ['projetTutoreDescription', 'Description du / des projet(s) tutoré(s)', 'organisation', 'textarea', [], false, 'Indiquez en 3000 caractères maximum le nombre de projets, le(s) semestre(s) concerné(s) et leurs modalités.', null, false],
        ['projetTutoreHeures', "Nombre d'heures de projet(s) tutoré(s) prévu", 'organisation', 'number', [], false, null, null, false],
        ['memoire', 'TER / Mémoire de recherche', 'organisation', 'radio', ['oui' => 'Oui', 'non' => 'Non'], false, null, null, false],
        ['memoireDescription', 'Description du TER / mémoire', 'organisation', 'textarea', [], false, 'Indiquez en 3000 caractères maximum les modalités du TER ou du mémoire.', null, false],
        ['situationPro', 'Mise(s) en situation professionnelle', 'organisation', 'radio', ['oui' => 'Oui', 'non' => 'Non'], false, null, null, false],
        ['situationProDescription', 'Description de la / des mise(s) en situation professionnelle', 'organisation', 'textarea', [], false, 'Indiquez en 3000 caractères maximum les modalités des mises en situation professionnelle.', null, false],
        ['situationProHeures', "Nombre d'heures de mise en situation professionnelle prévu", 'organisation', 'number', [], false, null, null, false],
        ['codeApogee', 'Code Apogée de la mention', 'organisation', 'text', [], false, 'Code de la mention dans Apogée', null, false],
        ['sep_org_responsables', 'Responsable(s)', 'organisation', 'separator', [], false, null, null, false],
        ['respMention', 'Responsable de la mention', 'organisation', 'choice', [], true, 'Responsable principale de la mention', 'responsables', true],
        ['coRespMention', 'Co-responsable de la mention', 'organisation', 'choice', [], false, 'Co-responsable de la mention', 'responsables', false],
        ['respParcours', 'Responsable du parcours', 'organisation', 'choice', [], true, 'Responsable principale du parcours', 'responsables', true],
        ['coRespParcours', 'Co-responsable du parcours', 'organisation', 'choice', [], false, 'Co-Responsable du parcours', 'responsables', false],
        // ─── présentation ───
        ['objectif', 'Objectif', 'presentation', 'textarea', [], true, 'Indiquez en 3000 caractères maximum la finalité de la formation et/ou les activités visées après le diplôme.', null, false],
        ['motsCles', 'Mots clés', 'presentation', 'textarea', [], true, 'Indiquez en 3000 caractères maximum les mots clés caractérisant le parcours.', null, false],
        ['resultats', 'Résultats attendus', 'presentation', 'textarea', [], true, 'Indiquez en 3000 caractères maximum les compétences acquises via la formation (compétences à acquérir, à améliorer ou à entretenir).', null, false],
        ['contenu', 'Contenu', 'presentation', 'textarea', [], true, 'Indiquez en 3000 caractères maximum les thématiques traitées, en cohérence avec les objectifs.', null, false],
        ['langue', 'Langue', 'presentation', 'choice', [], true, "Langue dans laquelle sont dispensés les cours (à l'exception des cours de langue).", 'langues', false],
        ['niveauLangue', 'Niveau de langue', 'presentation', 'choice', [], true, 'Indiquez le niveau de langue souhaité pour intégrer le parcours.', 'niveaux_langue', false],
        ['prerequis', 'Prérequis recommandés', 'presentation', 'textarea', [], false, 'Indiquez en 3000 caractères maximum les prérequis pédagogiques (diplômes conseillés) pour réussir dans la formation.', null, false],
        ['sep_pres_rythme', 'Rythme de formation', 'presentation', 'separator', [], false, null, null, false],
        ['rythme', 'Rythme', 'presentation', 'choice', [], false, 'Choisissez un rythme de formation ou précisez-le ci-dessous.', 'rythmes', false],
        ['rythmePrecision', 'Précision du rythme', 'presentation', 'textarea', [], false, 'Indiquez en 3000 caractères maximum le déroulé, le calendrier, les périodes…', null, false],
        ['sep_pres_contacts', 'Contacts', 'presentation', 'separator', [], false, null, null, false],
        ['contactsParcours', 'Contacts', 'presentation', 'textarea', [], false, null, null, false],
        ['secretariat', 'Coordonnées du secrétariat pédagogique', 'presentation', 'textarea', [], false, 'Adresse postale, téléphone et adresse mail générique.', null, false],
        ['sep_pres_apres', 'Et après…', 'presentation', 'separator', [], false, null, null, false],
        ['poursuiteEtudes', "Poursuite d'études", 'presentation', 'textarea', [], true, "Indiquez en 3000 caractères maximum quelles sont les poursuites d'études envisageables.", null, false],
        ['debouches', 'Débouchés', 'presentation', 'textarea', [], true, "Indiquez en 3000 caractères maximum les principaux débouchés professionnels accessibles à l'issue de cette formation.", null, false],
        ['codesRome', 'Code(s) ROME', 'presentation', 'checkbox', [], true, 'Choix multiples : codes ROME des métiers accessibles aux diplômés.', 'codes_rome', false],
    ];

    /**
     * Champs propres au formulaire socle de la fiche matière (cf. NodeTypeFixtures,
     * `param_fiche_matiere`), dans l'ordre des onglets. `sigle`, `competencies`, `hours`, `ects`
     * et `mccc` existent déjà et sont repris tels quels (onglet et libellé réglés par le formulaire).
     *
     * [key, label, tab, type, options, required, help, referentielKey, optionsSource, max, allowExtra]
     */
    private const FICHE_MATIERE_SEED = [
        // ─── identité ───
        ['libelle', 'Libellé', 'identite', 'text', [], true, 'Libellé de la fiche matière, 250 caractères maximum.', null, null, 250, false],
        ['libelleEn', 'Libellé en anglais', 'identite', 'text', [], false, '250 caractères maximum.', null, null, 250, false],
        ['referent', 'Responsable de la fiche matière', 'identite', 'choice', [], false, null, 'responsables', null, null, true],
        ['codeApogeeFiche', 'Code Apogée', 'identite', 'text', [], false, 'Code de l’élément dans Apogée.', null, null, null, false],
        ['typeEc', "Type d'EC", 'identite', 'choice', [], false, "Facultatif : l'EC qui utilise la fiche prend ce type et ne peut pas le changer.", null, 'version_natures', null, false],
        // ─── présentation ───
        ['description', 'Description', 'presentation', 'textarea', [], false, 'Indiquez en 3000 caractères maximum le contenu de l’enseignement.', null, null, 3000, false],
        ['objectifsEnseignement', 'Objectifs', 'presentation', 'textarea', [], false, 'Indiquez en 3000 caractères maximum les objectifs de l’enseignement.', null, null, 3000, false],
        ['languesCours', 'Langue(s) des cours', 'presentation', 'checkbox', [], true, 'Au moins une langue.', 'langues', null, null, false],
        ['languesSupport', 'Langue(s) des supports', 'presentation', 'checkbox', [], true, 'Au moins une langue.', 'langues', null, null, false],
        ['modaliteEnseignement', 'Modalité d’enseignement', 'presentation', 'choice', [], false, null, 'modalites_enseignement', null, null, false],
        // ─── volumes horaires ───
        ['volumesImposes', 'Volumes horaires imposés', 'volume_horaire', 'radio', ['oui' => 'Oui', 'non' => 'Non'], false, 'Oui : les EC qui utilisent la fiche prennent ces volumes sans pouvoir les changer.', null, null, null, false],
        // ─── MCCC ───
        ['ectsImposes', 'ECTS imposés', 'mccc', 'radio', ['oui' => 'Oui', 'non' => 'Non'], false, 'Oui : les EC qui utilisent la fiche prennent ces ECTS sans pouvoir les changer.', null, null, null, false],
        ['mcccImposes', 'MCCC imposées', 'mccc', 'radio', ['oui' => 'Oui', 'non' => 'Non'], false, 'Oui : les EC qui utilisent la fiche prennent ces MCCC sans pouvoir les changer.', null, null, null, false],
    ];

    /** Rang des champs de la fiche matière : après tous les autres champs socles, de 10 en 10 (cf. NodeTypeFixtures). */
    public const FICHE_MATIERE_BASE = 400;

    public function load(ObjectManager $manager): void
    {
        foreach (AttributeCatalog::SEED as $i => [$key, $label, $tab, $type, $options, $required, $help, $referentielKey, $placeholder, $allowExtra]) {
            $field = (new FieldDef($key, $label))
                ->setTab($tab)
                ->setType($type)
                ->setOptions($options)
                ->setPlaceholder($placeholder)
                ->setAllowExtra($allowExtra)
                ->setRequired($required)
                ->setHelp($help)
                ->setPosition($i * 10)
                ->setSystem(true);
            if ('ficheMatiere' === $key) {
                $field->setOptionsSource($referentielKey);
            } else {
                $field->setReferentielKey($referentielKey);
            }
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
                ->setAllowExtra(\in_array($key, ['mention', 'composanteInscription', 'respMention', 'coRespMention', 'respParcours', 'coRespParcours', 'langue', 'niveauLangue'], true))
                ->setRequired($required)
                ->setHelp($help)
                ->setPosition($position)
                ->setLocked($locked)
                // l'aide annonce « N caractères maximum » : la borne la fait respecter
                ->setMax(preg_match('/(\d+) caractères maximum/u', (string) $help, $m) ? (float) $m[1] : null)
                ->setSystem(true);
            $manager->persist($field);
        }

        foreach (self::FICHE_MATIERE_SEED as $i => [$key, $label, $tab, $type, $options, $required, $help, $referentielKey, $optionsSource, $max, $allowExtra]) {
            $manager->persist((new FieldDef($key, $label))
                ->setTab($tab)
                ->setType($type)
                ->setOptions($options)
                ->setReferentielKey($referentielKey)
                ->setOptionsSource($optionsSource)
                ->setAllowExtra($allowExtra)
                ->setRequired($required)
                ->setHelp($help)
                ->setMax(null !== $max ? (float) $max : null)
                ->setPosition(self::FICHE_MATIERE_BASE + 10 * $i)
                ->setSystem(true));
        }

        $manager->flush();
    }
}
