<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\NodeType;
use App\Enum\NodeFamily;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Types de nœuds « socle » livrés par défaut. Tout est éditable ensuite via
 * l'admin des types — le but du proto est justement de montrer que rien n'est figé.
 */
final class NodeTypeFixtures extends Fixture
{
    public const REF_PREFIX = 'nodetype-';

    /** Tous les conteneurs structurels acceptent tous les types structurels. */
    private const STRUCTURAL_CHILDREN = ['parcours', 'annee', 'semestre', 'ue', 'ec', 'bloc_choix'];

    public function load(ObjectManager $manager): void
    {
        $defs = [
            // clé            label                 icon  famille                    position  enfants autorisés                            capabilities                                                          ectsTarget
            ['parcours',      'Parcours',           '🧭', NodeFamily::Structural,     10,      self::STRUCTURAL_CHILDREN,                    ['ects' => true, 'mutualisable' => true],                              null],
            ['annee',         'Année',              '📅', NodeFamily::Structural,     20,      self::STRUCTURAL_CHILDREN,                    ['mutualisable' => true],                                             60],
            ['semestre',      'Semestre',           '🗓️', NodeFamily::Structural,     30,      self::STRUCTURAL_CHILDREN,                    ['mutualisable' => true],                                             30],
            ['ue',            'UE',                 '🧩', NodeFamily::Structural,     40,      ['ec', 'bloc_choix', 'ue'],                   ['ects' => true, 'ueType' => true, 'nature' => true, 'competencies' => true, 'mutualisable' => true], null],
            ['ec',            'EC',                 '📄', NodeFamily::Structural,     50,      [],                                          ['ects' => true, 'nature' => true, 'competencies' => true, 'ficheMatiere' => true, 'hours' => true, 'mccc' => true, 'mutualisable' => true], null],
            ['bloc_choix',    'Bloc de choix',      '🔀', NodeFamily::Structural,     60,      ['ue', 'ec'],                                ['mutualisable' => true],                                             null],

            ['bcc',           'BCC',                '🎯', NodeFamily::Competence,     70,      ['bloc_competences'],                        [],                                                                   null],
            ['bloc_competences', 'Bloc de compétences', '🟪', NodeFamily::Competence, 80,      ['competence'],                              [],                                                                   null],
            ['competence',    'Compétence',         '✅', NodeFamily::Competence,     90,      [],                                          [],                                                                   null],

            ['presentation',  'Présentation',       '📝', NodeFamily::Parameter,     100,     [],                                          [],                                                                   null],
            ['organisation',  'Organisation & localisation', '📍', NodeFamily::Parameter, 110, [],                                          [],                                                                   null],
        ];

        foreach ($defs as [$key, $label, $icon, $family, $position, $children, $caps, $ectsTarget]) {
            $type = (new NodeType($key, $label))
                ->setIcon($icon)
                ->setFamily($family)
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
