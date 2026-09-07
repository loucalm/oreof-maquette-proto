<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Formation;
use App\Entity\Node;
use App\Entity\NodeType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Une formation de démo avec un petit arbre partiellement rempli, pour voir
 * les statuts (vide / incomplet / ok) et les agrégats dès l'ouverture.
 */
final class FormationFixtures extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array
    {
        return [NodeTypeFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var array<string, NodeType> $t */
        $t = [];
        foreach (['annee', 'semestre', 'ue', 'ec'] as $k) {
            $t[$k] = $this->getReference(NodeTypeFixtures::REF_PREFIX.$k, NodeType::class);
        }

        $formation = (new Formation('Licence Informatique (démo)'))
            ->setDiplome('Licence')
            ->setDomaine('Sciences, technologies, santé')
            ->setComposante('UFR Sciences Exactes et Naturelles')
            ->setMultiParcours(false)
            ->setEctsTotal(180);
        $manager->persist($formation);

        $pos = 0;
        $mk = function (NodeType $type, string $label, ?Node $parent, array $attrs = []) use ($manager, $formation, &$pos): Node {
            $n = new Node($type, $label);
            $n->setFormation($formation);
            $n->setParent($parent);
            $n->setPosition($parent === null ? $pos++ : $parent->getChildren()->count());
            $n->setAttributes($attrs);
            $formation->addNode($n);
            if ($parent !== null) {
                $parent->addChild($n);
            }
            $manager->persist($n);

            return $n;
        };

        $a1 = $mk($t['annee'], 'Année 1', null);
        $s1 = $mk($t['semestre'], 'Semestre 1', $a1);

        // UE complète
        $ue1 = $mk($t['ue'], 'UE 1.1 — Programmation', $s1, ['ects' => 6, 'nature' => 'obligatoire', 'ueType' => 'disciplinaire']);
        $mk($t['ec'], 'Algorithmique', $ue1, [
            'ects' => 3, 'nature' => 'obligatoire',
            'hours' => ['cm' => ['pres' => 12], 'td' => ['pres' => 18], 'tp' => ['pres' => 0]],
            'mccc' => ['type' => 'CC', 'note' => '100% contrôle continu'],
        ]);
        $mk($t['ec'], 'Langage C', $ue1, [
            'ects' => 3, 'nature' => 'obligatoire',
            'hours' => ['tp' => ['pres' => 24]],
            'mccc' => ['type' => 'CC + examen'],
        ]);

        // UE incomplète (ECTS manquant sur un EC, pas de MCCC)
        $ue2 = $mk($t['ue'], 'UE 1.2 — Mathématiques', $s1, ['ects' => 6, 'nature' => 'obligatoire']);
        $mk($t['ec'], 'Analyse', $ue2, ['ects' => 3, 'nature' => 'obligatoire', 'hours' => ['cm' => ['pres' => 20]]]);
        $mk($t['ec'], 'Algèbre', $ue2, ['nature' => 'obligatoire']); // volontairement vide

        // Semestre 2 vide
        $mk($t['semestre'], 'Semestre 2', $a1);

        // Années 2 et 3 vides
        $mk($t['annee'], 'Année 2', null);
        $mk($t['annee'], 'Année 3', null);

        // ─── 2e démo : formation multi-parcours avec ramification ───
        $tp = $this->getReference(NodeTypeFixtures::REF_PREFIX.'parcours', NodeType::class);
        $master = (new Formation('Master Informatique (démo multi-parcours)'))
            ->setDiplome('Master')
            ->setDomaine('Sciences, technologies, santé')
            ->setComposante('UFR Sciences Exactes et Naturelles')
            ->setMultiParcours(true)
            ->setEctsTotal(120);
        $manager->persist($master);

        $p = [];
        $specs = [
            ['Tronc commun', 1, 1, null],
            ['Parcours Données', 2, 2, 'Tronc commun'],
            ['Parcours Logiciel', 2, 2, 'Tronc commun'],
            ['Parcours Recherche', 2, 2, 'Parcours Données'],
        ];
        foreach ($specs as $i => [$label, $debut, $fin, $parentLabel]) {
            $node = new Node($tp, $label);
            $node->setFormation($master);
            $node->setPosition($i);
            $node->setAttributes(['anneeDebut' => $debut, 'anneeFin' => $fin]);
            if ($parentLabel !== null && isset($p[$parentLabel])) {
                $node->setParcoursParent($p[$parentLabel]);
            }
            $master->addNode($node);
            $manager->persist($node);
            $p[$label] = $node;
        }

        $manager->flush();
    }
}
