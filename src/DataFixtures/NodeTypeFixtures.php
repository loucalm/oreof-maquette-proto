<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\NodeType;
use App\Enum\NodeFamily;
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
    public const REF_PREFIX = 'nodetype-';

    public function load(ObjectManager $manager): void
    {
        $defs = [
            // clé          label        icon  famille                    position  capabilities                                                                              ectsTarget  capacités réservées admin
            ['parcours',    'Parcours',  '🧭', NodeFamily::Structural,     10,       ['ects' => true, 'mutualisable' => true],                                                  null,       []],
            ['annee',       'Année',     '📅', NodeFamily::Structural,     20,       ['mutualisable' => true],                                                                  60,         []],
            ['semestre',    'Semestre',  '🗓️', NodeFamily::Structural,     30,       ['mutualisable' => true],                                                                  30,         []],
            // autres unités de temps possibles pour le 1er niveau du squelette
            ['trimestre',   'Trimestre', '🍂', NodeFamily::Structural,     34,       ['mutualisable' => true],                                                                  20,         []],
            ['mois',        'Mois',      '📆', NodeFamily::Structural,     36,       ['mutualisable' => true],                                                                  null,       []],
            ['semaine',     'Semaine',   '🗒️', NodeFamily::Structural,     38,       ['mutualisable' => true],                                                                  null,       []],
            ['ue',          'UE',        '🧩', NodeFamily::Structural,     40,       ['ects' => true, 'ueType' => true, 'nature' => true, 'competencies' => true, 'mutualisable' => true], null, ['nature']],
            ['ec',          'EC',        '📄', NodeFamily::Structural,     50,       ['ects' => true, 'nature' => true, 'competencies' => true, 'ficheMatiere' => true, 'hours' => true, 'mccc' => true, 'mutualisable' => true], null, ['nature', 'mccc']],
            ['bloc_choix',  'Bloc de choix', '🔀', NodeFamily::Structural, 60,       ['nature' => true, 'mutualisable' => true],                                                null,       ['nature']],
            // ─── référentiel de compétences (BCC) : arbre parallèle à la structure pédagogique ───
            ['bloc_competences', 'Bloc de compétences', '🎓', NodeFamily::Competence, 70, ['mutualisable' => true],                                          null,       []],
            ['competence',       'Compétence',          '🎯', NodeFamily::Competence, 72, [],                                                                null,       []],
        ];

        foreach ($defs as [$key, $label, $icon, $family, $position, $caps, $ectsTarget, $locked]) {
            $type = (new NodeType($key, $label))
                ->setIcon($icon)
                ->setFamily($family)
                ->setPosition($position)
                ->setCapabilities($caps)
                ->setLockedCapabilities($locked)
                ->setEctsTarget($ectsTarget)
                ->setSystem(true);

            $manager->persist($type);
            $this->addReference(self::REF_PREFIX.$key, $type);
        }

        $manager->flush();
    }
}
