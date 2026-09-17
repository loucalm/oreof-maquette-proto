<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\NodeType;
use App\Enum\NodeFamily;
use App\Enum\NodeKind;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Types de nœuds « socle » de la structure pédagogique. Tout est éditable
 * ensuite via /node-types — rien n'est figé.
 *
 * Les sections « Organisation / Présentation / Configuration / BCC » de la
 * maquette Figma ne sont PAS des types de nœud : ce sont des paramètres de
 * formation, hors périmètre du prototype (rendus fictivement).
 */
final class NodeTypeFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $defs = [
            // clé          label        icon                     famille                    position  capabilities                                                                              ectsTarget  réservées admin  numéroté  style      kind                  globalNum  choiceTransformable  système (non supprimable)
            ['parcours',    'Parcours',  'ph:compass',            NodeFamily::Structural,     10,       ['ects' => true, 'mutualisable' => true],                                                  null,       [],              false,    'decimal', NodeKind::Structurel, false,     false, true],
            ['annee',       'Année',     'ph:calendar',           NodeFamily::Structural,     20,       ['mutualisable' => true],                                                                  60,         [],              true,     'decimal', NodeKind::Temporel,   false,     false, false],
            // « Semestre » numéroté en comptage global (1, 2, 3, 4… sur toute la formation, pas
            // réinitialisé par année) : ses enfants (UE) restent numérotés contextuellement (2.1 sous Semestre 2).
            ['semestre',    'Semestre',  'ph:calendar-blank',     NodeFamily::Structural,     30,       ['mutualisable' => true],                                                                  30,         [],              true,     'decimal', NodeKind::Temporel,   true,      false, false],
            // autres unités de temps possibles pour le 1er niveau du squelette
            ['trimestre',   'Trimestre', 'ph:calendar-dots',      NodeFamily::Structural,     34,       ['mutualisable' => true],                                                                  20,         [],              false,    'decimal', NodeKind::Temporel,   false,     false, false],
            ['mois',        'Mois',      'ph:calendar-dot',       NodeFamily::Structural,     36,       ['mutualisable' => true],                                                                  null,       [],              false,    'decimal', NodeKind::Temporel,   false,     false, false],
            ['semaine',     'Semaine',   'ph:calendar-check',     NodeFamily::Structural,     38,       ['mutualisable' => true],                                                                  null,       [],              true,     'decimal', NodeKind::Temporel,   false,     false, false],
            ['ue',          'UE',        'ph:puzzle-piece',       NodeFamily::Structural,     40,       ['ects' => true, 'ueType' => true, 'nature' => true, 'competencies' => true, 'mutualisable' => true], null, ['nature'],        true,     'decimal', NodeKind::Structurel, false,     true, false],
            ['ec',          'EC',        'ph:file-text',          NodeFamily::Structural,     50,       ['code' => true, 'ecType' => true, 'ects' => true, 'nature' => true, 'competencies' => true, 'ficheMatiere' => true, 'hours' => true, 'mccc' => true, 'mutualisable' => true], null, ['code', 'nature', 'mccc', 'ficheMatiere'], true, 'alpha', NodeKind::Structurel, false, true, true],
            // transparent pour le squelette (cf. TreeNode::getEffectiveHostTypeKey) : peut
            // remplacer n'importe quel UE/EC attendu par la chaîne, et s'imbriquer en lui-même à l'infini
            ['bloc_choix',  'Bloc de choix', 'ph:shuffle',        NodeFamily::Structural, 60,       ['ects' => true, 'choiceCount' => true, 'nature' => true, 'mutualisable' => true], null,       ['nature'],      false,    'decimal', NodeKind::Structurel, false, false, false],
            // ─── référentiel de compétences (BCC) : arbre parallèle à la structure pédagogique — le
            // BCC en dépend structurellement (BccController cherche ces clés), donc protégés eux aussi.
            ['bloc_competences', 'Bloc de compétences', 'ph:graduation-cap', NodeFamily::Competence, 70, ['mutualisable' => true],                                          null,       [],              true,     'decimal', NodeKind::Structurel, false, false, true],
            ['competence',       'Compétence',          'ph:target',        NodeFamily::Competence, 72, [],                                                                null,       [],              true,     'alpha_upper', NodeKind::Structurel, false, false, true],
        ];

        foreach ($defs as [$key, $label, $icon, $family, $position, $caps, $ectsTarget, $locked, $numbered, $numberStyle, $kind, $globalNumbering, $choiceTransformable, $system]) {
            $type = (new NodeType($key, $label))
                ->setIcon($icon)
                ->setFamily($family)
                ->setKind($kind)
                ->setPosition($position)
                ->setCapabilities($caps)
                ->setLockedCapabilities($locked)
                ->setEctsTarget($ectsTarget)
                ->setNumbered($numbered)
                ->setNumberStyle($numberStyle)
                ->setGlobalNumbering($globalNumbering)
                ->setChoiceTransformable($choiceTransformable)
                ->setSystem($system);

            $manager->persist($type);
        }

        // ─── sections fixes « Formation / Parcours » : Organisation, Présentation,
        // Configuration de la structure, BCC — jamais des nœuds d'arbre (family Parameter,
        // exclues des dropdowns structurels par NodeTypeRepository::forTree()), mais leur
        // liste de champs devient configurable via le même constructeur de formulaire.
        $paramDefs = [
            ['param_organisation_formation', 'Organisation et localisation', 'ph:map-pin', 110],
            ['param_presentation_formation', 'Présentation', 'ph:notepad', 112],
            ['param_structure_formation', 'Configuration de la structure', 'ph:tree-structure', 114],
            ['param_structure_mono_formation', 'Configuration de la structure (mono-parcours)', 'ph:tree-structure', 116],
            ['param_bcc_mono_formation', 'BCC (mono parcours)', 'ph:graduation-cap', 118],
            ['param_organisation_parcours', 'Organisation et localisation', 'ph:map-pin', 120],
            ['param_presentation_parcours', 'Présentation', 'ph:notepad', 122],
            ['param_bcc_parcours', 'BCC', 'ph:graduation-cap', 124],
        ];
        foreach ($paramDefs as [$key, $label, $icon, $position]) {
            $manager->persist((new NodeType($key, $label))
                ->setIcon($icon)
                ->setFamily(NodeFamily::Parameter)
                ->setPosition($position)
                ->setSystem(true));
        }

        $manager->flush();
    }
}
