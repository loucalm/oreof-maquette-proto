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

    private const STRUCTURAL = ['parcours', 'annee', 'semestre', 'ue', 'ec', 'bloc_choix'];

    public function load(ObjectManager $manager): void
    {
        $defs = [
            // clé          label        icon  position  enfants autorisés                    capabilities                                                                              ectsTarget
            ['parcours',    'Parcours',  '🧭', 10,       self::STRUCTURAL,                    ['ects' => true, 'mutualisable' => true],                                                  null],
            ['annee',       'Année',     '📅', 20,       ['semestre', 'ue', 'bloc_choix'],    ['mutualisable' => true],                                                                  60],
            ['semestre',    'Semestre',  '🗓️', 30,       ['ue', 'bloc_choix'],               ['mutualisable' => true],                                                                  30],
            ['ue',          'UE',        '🧩', 40,       ['ec', 'bloc_choix'],               ['ects' => true, 'ueType' => true, 'nature' => true, 'competencies' => true, 'mutualisable' => true], null],
            ['ec',          'EC',        '📄', 50,       [],                                 ['ects' => true, 'nature' => true, 'competencies' => true, 'ficheMatiere' => true, 'hours' => true, 'mccc' => true, 'mutualisable' => true], null],
            ['bloc_choix',  'Bloc de choix', '🔀', 60,   ['ue', 'ec'],                       ['nature' => true, 'mutualisable' => true],                                                null],
        ];

        foreach ($defs as [$key, $label, $icon, $position, $children, $caps, $ectsTarget]) {
            $type = (new NodeType($key, $label))
                ->setIcon($icon)
                ->setFamily(NodeFamily::Structural)
                ->setPosition($position)
                ->setAllowedChildKeys($children)
                ->setCapabilities($caps)
                ->setEctsTarget($ectsTarget)
                ->setSystem(true);

            $manager->persist($type);
            $this->addReference(self::REF_PREFIX.$key, $type);
        }

        $manager->flush();
    }
}
