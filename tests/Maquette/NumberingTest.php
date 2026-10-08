<?php

declare(strict_types=1);

namespace App\Tests\Maquette;

use App\Enum\NodeFamily;
use App\Enum\NodeKind;
use App\Maquette\Doc\TreeNode;
use App\Maquette\NodeView;
use App\Maquette\Numbering;
use App\Entity\NodeType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NumberingTest extends TestCase
{
    private Numbering $numbering;

    protected function setUp(): void
    {
        $this->numbering = new Numbering();
    }

    /**
     * @return list<array{int, string, string}>
     */
    public static function formatData(): array
    {
        return [
            // decimal
            [1, 'decimal', '1'],
            [5, 'decimal', '5'],
            [42, 'decimal', '42'],

            // alpha (lowercase)
            [1, 'alpha', 'a'],
            [26, 'alpha', 'z'],
            [27, 'alpha', 'aa'],
            [28, 'alpha', 'ab'],
            [52, 'alpha', 'az'],
            [53, 'alpha', 'ba'],
            [702, 'alpha', 'zz'],
            [703, 'alpha', 'aaa'],

            // alpha_upper
            [1, 'alpha_upper', 'A'],
            [26, 'alpha_upper', 'Z'],
            [27, 'alpha_upper', 'AA'],

            // roman
            [1, 'roman', 'i'],
            [4, 'roman', 'iv'],
            [5, 'roman', 'v'],
            [9, 'roman', 'ix'],
            [10, 'roman', 'x'],
            [14, 'roman', 'xiv'],
            [40, 'roman', 'xl'],
            [50, 'roman', 'l'],
            [90, 'roman', 'xc'],
            [100, 'roman', 'c'],
            [400, 'roman', 'cd'],
            [500, 'roman', 'd'],
            [900, 'roman', 'cm'],
            [1000, 'roman', 'm'],
            [1994, 'roman', 'mcmxciv'],

            // roman(0) returns "0"
            [0, 'roman', '0'],
            [-1, 'roman', '-1'],

            // unknown style falls back to decimal
            [42, 'unknown', '42'],
            [7, 'weird_style', '7'],
        ];
    }

    #[DataProvider('formatData')]
    public function testFormat(int $n, string $style, string $expected): void
    {
        $result = $this->numbering->format($n, $style);

        $this->assertSame($expected, $result);
    }

    private function createNodeType(
        string $key,
        string $label,
        bool $numbered = false,
        string $numberStyle = 'decimal',
        bool $globalNumbering = false
    ): NodeType {
        $type = new NodeType($key, $label);
        $type->setNumbered($numbered);
        $type->setNumberStyle($numberStyle);
        $type->setGlobalNumbering($globalNumbering);
        $type->setFamily(NodeFamily::Structural);
        $type->setKind(NodeKind::Structurel);

        return $type;
    }

    private function createTreeNode(string $nid, string $typeKey, string $label = ''): TreeNode
    {
        return new TreeNode(
            nid: $nid,
            typeKey: $typeKey,
            label: $label,
        );
    }

    private function createNodeView(TreeNode $node, array $children = []): NodeView
    {
        return new NodeView(
            node: $node,
            children: $children,
        );
    }

    public function testApplyContextualNumbering(): void
    {
        // Create types
        $anneeType = $this->createNodeType('annee', 'Année', true, 'decimal');
        $ueType = $this->createNodeType('ue', 'UE', true, 'decimal');

        // Build tree: Annee 1 -> UE 1.1, UE 1.2 | Annee 2 -> UE 2.1
        $annee1 = $this->createTreeNode('a1', 'annee', 'Année 1');
        $ue11 = $this->createTreeNode('u11', 'ue', 'UE 1.1');
        $ue12 = $this->createTreeNode('u12', 'ue', 'UE 1.2');
        $annee1->children = [$ue11, $ue12];

        $annee2 = $this->createTreeNode('a2', 'annee', 'Année 2');
        $ue21 = $this->createTreeNode('u21', 'ue', 'UE 2.1');
        $annee2->children = [$ue21];

        $annee1->bindType($anneeType);
        $ue11->bindType($ueType);
        $ue12->bindType($ueType);
        $annee2->bindType($anneeType);
        $ue21->bindType($ueType);

        $viewAnnee1 = $this->createNodeView($annee1, [
            $this->createNodeView($ue11),
            $this->createNodeView($ue12),
        ]);
        $viewAnnee2 = $this->createNodeView($annee2, [
            $this->createNodeView($ue21),
        ]);

        $this->numbering->apply([$viewAnnee1, $viewAnnee2]);

        $this->assertSame('1', $viewAnnee1->ref);
        $this->assertSame('1.1', $viewAnnee1->children[0]->ref);
        $this->assertSame('1.2', $viewAnnee1->children[1]->ref);
        $this->assertSame('2', $viewAnnee2->ref);
        $this->assertSame('2.1', $viewAnnee2->children[0]->ref);
    }

    public function testApplyNonNumberedTypeTransparent(): void
    {
        // Create types: Semestre is NOT numbered, UE is numbered
        $anneeType = $this->createNodeType('annee', 'Année', true, 'decimal');
        $semestreType = $this->createNodeType('semestre', 'Semestre', false); // NOT numbered
        $ueType = $this->createNodeType('ue', 'UE', true, 'decimal');

        // Build tree: Annee 1 -> Semestre 1 -> UE 1.1, UE 1.2 | Semestre 2 -> UE 1.3, UE 1.4
        $annee1 = $this->createTreeNode('a1', 'annee');
        $sem1 = $this->createTreeNode('s1', 'semestre');
        $ue11 = $this->createTreeNode('u11', 'ue');
        $ue12 = $this->createTreeNode('u12', 'ue');
        $sem1->children = [$ue11, $ue12];

        $sem2 = $this->createTreeNode('s2', 'semestre');
        $ue13 = $this->createTreeNode('u13', 'ue');
        $ue14 = $this->createTreeNode('u14', 'ue');
        $sem2->children = [$ue13, $ue14];

        $annee1->children = [$sem1, $sem2];

        $annee1->bindType($anneeType);
        $sem1->bindType($semestreType);
        $sem2->bindType($semestreType);
        $ue11->bindType($ueType);
        $ue12->bindType($ueType);
        $ue13->bindType($ueType);
        $ue14->bindType($ueType);

        $viewAnnee1 = $this->createNodeView($annee1, [
            $this->createNodeView($sem1, [
                $this->createNodeView($ue11),
                $this->createNodeView($ue12),
            ]),
            $this->createNodeView($sem2, [
                $this->createNodeView($ue13),
                $this->createNodeView($ue14),
            ]),
        ]);

        $this->numbering->apply([$viewAnnee1]);

        // Année is numbered, gets "1"
        $this->assertSame('1', $viewAnnee1->ref);

        // Semestre is NOT numbered, transparent - no ref of its own
        $this->assertSame('', $viewAnnee1->children[0]->ref);
        $this->assertSame('', $viewAnnee1->children[1]->ref);

        // UE inherits from Année (the nearest numbered ancestor)
        // First semestre's UEs: 1.1, 1.2
        $this->assertSame('1.1', $viewAnnee1->children[0]->children[0]->ref);
        $this->assertSame('1.2', $viewAnnee1->children[0]->children[1]->ref);

        // Second semestre's UEs: 1.3, 1.4 (continuing the count under the same parent)
        $this->assertSame('1.3', $viewAnnee1->children[1]->children[0]->ref);
        $this->assertSame('1.4', $viewAnnee1->children[1]->children[1]->ref);
    }

    public function testApplyGlobalNumbering(): void
    {
        // Create types: Annee is numbered (contextual), Semestre is numbered GLOBAL
        $anneeType = $this->createNodeType('annee', 'Année', true, 'decimal');
        $semestreType = $this->createNodeType('semestre', 'Semestre', true, 'decimal', true); // global!
        $ueType = $this->createNodeType('ue', 'UE', true, 'decimal');

        // Build tree:
        // Annee 1
        //   Semestre 1 (global counter = 1)
        //     UE 1.1, UE 1.2
        //   Semestre 2 (global counter = 2)
        //     UE 2.1, UE 2.2
        // Annee 2
        //   Semestre 3 (global counter = 3)
        //     UE 3.1
        //   Semestre 4 (global counter = 4)
        //     UE 4.1
        $annee1 = $this->createTreeNode('a1', 'annee');
        $sem1 = $this->createTreeNode('s1', 'semestre');
        $ue11 = $this->createTreeNode('u11', 'ue');
        $ue12 = $this->createTreeNode('u12', 'ue');
        $sem1->children = [$ue11, $ue12];

        $sem2 = $this->createTreeNode('s2', 'semestre');
        $ue21 = $this->createTreeNode('u21', 'ue');
        $ue22 = $this->createTreeNode('u22', 'ue');
        $sem2->children = [$ue21, $ue22];

        $annee1->children = [$sem1, $sem2];

        $annee2 = $this->createTreeNode('a2', 'annee');
        $sem3 = $this->createTreeNode('s3', 'semestre');
        $ue31 = $this->createTreeNode('u31', 'ue');
        $sem3->children = [$ue31];

        $sem4 = $this->createTreeNode('s4', 'semestre');
        $ue41 = $this->createTreeNode('u41', 'ue');
        $sem4->children = [$ue41];

        $annee2->children = [$sem3, $sem4];

        $annee1->bindType($anneeType);
        $annee2->bindType($anneeType);
        $sem1->bindType($semestreType);
        $sem2->bindType($semestreType);
        $sem3->bindType($semestreType);
        $sem4->bindType($semestreType);
        $ue11->bindType($ueType);
        $ue12->bindType($ueType);
        $ue21->bindType($ueType);
        $ue22->bindType($ueType);
        $ue31->bindType($ueType);
        $ue41->bindType($ueType);

        $viewAnnee1 = $this->createNodeView($annee1, [
            $this->createNodeView($sem1, [
                $this->createNodeView($ue11),
                $this->createNodeView($ue12),
            ]),
            $this->createNodeView($sem2, [
                $this->createNodeView($ue21),
                $this->createNodeView($ue22),
            ]),
        ]);
        $viewAnnee2 = $this->createNodeView($annee2, [
            $this->createNodeView($sem3, [
                $this->createNodeView($ue31),
            ]),
            $this->createNodeView($sem4, [
                $this->createNodeView($ue41),
            ]),
        ]);

        $this->numbering->apply([$viewAnnee1, $viewAnnee2]);

        // Année: contextual numbering
        $this->assertSame('1', $viewAnnee1->ref);
        $this->assertSame('2', $viewAnnee2->ref);

        // Semestre: GLOBAL numbering (no parent prefix, continuous across whole tree)
        $this->assertSame('1', $viewAnnee1->children[0]->ref);  // Semestre 1
        $this->assertSame('2', $viewAnnee1->children[1]->ref);  // Semestre 2
        $this->assertSame('3', $viewAnnee2->children[0]->ref);  // Semestre 3
        $this->assertSame('4', $viewAnnee2->children[1]->ref);  // Semestre 4

        // UE: contextual under their parent Semestre (which IS numbered, so UE prefixes with Semestre's ref)
        $this->assertSame('1.1', $viewAnnee1->children[0]->children[0]->ref);
        $this->assertSame('1.2', $viewAnnee1->children[0]->children[1]->ref);
        $this->assertSame('2.1', $viewAnnee1->children[1]->children[0]->ref);
        $this->assertSame('2.2', $viewAnnee1->children[1]->children[1]->ref);
        $this->assertSame('3.1', $viewAnnee2->children[0]->children[0]->ref);
        $this->assertSame('4.1', $viewAnnee2->children[1]->children[0]->ref);
    }

    public function testApplyAlphaStyle(): void
    {
        $anneeType = $this->createNodeType('annee', 'Année', true, 'decimal');
        $ueType = $this->createNodeType('ue', 'UE', true, 'alpha'); // alpha style

        $annee1 = $this->createTreeNode('a1', 'annee');
        $ue11 = $this->createTreeNode('u11', 'ue');
        $ue12 = $this->createTreeNode('u12', 'ue');
        $ue13 = $this->createTreeNode('u13', 'ue');
        $annee1->children = [$ue11, $ue12, $ue13];

        $annee1->bindType($anneeType);
        $ue11->bindType($ueType);
        $ue12->bindType($ueType);
        $ue13->bindType($ueType);

        $viewAnnee1 = $this->createNodeView($annee1, [
            $this->createNodeView($ue11),
            $this->createNodeView($ue12),
            $this->createNodeView($ue13),
        ]);

        $this->numbering->apply([$viewAnnee1]);

        $this->assertSame('1', $viewAnnee1->ref);
        $this->assertSame('1.a', $viewAnnee1->children[0]->ref);
        $this->assertSame('1.b', $viewAnnee1->children[1]->ref);
        $this->assertSame('1.c', $viewAnnee1->children[2]->ref);
    }

    public function testApplyRomanStyle(): void
    {
        $anneeType = $this->createNodeType('annee', 'Année', true, 'decimal');
        $ueType = $this->createNodeType('ue', 'UE', true, 'roman'); // roman style

        $annee1 = $this->createTreeNode('a1', 'annee');
        $ue11 = $this->createTreeNode('u11', 'ue');
        $ue14 = $this->createTreeNode('u14', 'ue');
        $ue19 = $this->createTreeNode('u19', 'ue');
        $annee1->children = [$ue11, $ue14, $ue19];

        $annee1->bindType($anneeType);
        $ue11->bindType($ueType);
        $ue14->bindType($ueType);
        $ue19->bindType($ueType);

        $viewAnnee1 = $this->createNodeView($annee1, [
            $this->createNodeView($ue11),
            $this->createNodeView($ue14),
            $this->createNodeView($ue19),
        ]);

        $this->numbering->apply([$viewAnnee1]);

        $this->assertSame('1', $viewAnnee1->ref);
        $this->assertSame('1.i', $viewAnnee1->children[0]->ref);
        $this->assertSame('1.ii', $viewAnnee1->children[1]->ref);
        $this->assertSame('1.iii', $viewAnnee1->children[2]->ref);
    }

    public function testApplyAlphaUpperStyle(): void
    {
        $anneeType = $this->createNodeType('annee', 'Année', true, 'decimal');
        $ueType = $this->createNodeType('ue', 'UE', true, 'alpha_upper');

        $annee1 = $this->createTreeNode('a1', 'annee');
        $ue11 = $this->createTreeNode('u11', 'ue');
        $ue12 = $this->createTreeNode('u12', 'ue');
        $annee1->children = [$ue11, $ue12];

        $annee1->bindType($anneeType);
        $ue11->bindType($ueType);
        $ue12->bindType($ueType);

        $viewAnnee1 = $this->createNodeView($annee1, [
            $this->createNodeView($ue11),
            $this->createNodeView($ue12),
        ]);

        $this->numbering->apply([$viewAnnee1]);

        $this->assertSame('1.A', $viewAnnee1->children[0]->ref);
        $this->assertSame('1.B', $viewAnnee1->children[1]->ref);
    }

    public function testApplyMultipleRoots(): void
    {
        $anneeType = $this->createNodeType('annee', 'Année', true, 'decimal');
        $ueType = $this->createNodeType('ue', 'UE', true, 'decimal');

        $annee1 = $this->createTreeNode('a1', 'annee');
        $ue11 = $this->createTreeNode('u11', 'ue');
        $annee1->children = [$ue11];
        $annee1->bindType($anneeType);
        $ue11->bindType($ueType);

        $annee2 = $this->createTreeNode('a2', 'annee');
        $ue21 = $this->createTreeNode('u21', 'ue');
        $annee2->children = [$ue21];
        $annee2->bindType($anneeType);
        $ue21->bindType($ueType);

        $viewAnnee1 = $this->createNodeView($annee1, [$this->createNodeView($ue11)]);
        $viewAnnee2 = $this->createNodeView($annee2, [$this->createNodeView($ue21)]);

        $this->numbering->apply([$viewAnnee1, $viewAnnee2]);

        $this->assertSame('1', $viewAnnee1->ref);
        $this->assertSame('1.1', $viewAnnee1->children[0]->ref);
        $this->assertSame('2', $viewAnnee2->ref);
        $this->assertSame('2.1', $viewAnnee2->children[0]->ref);
    }

    public function testApplyEmptyTree(): void
    {
        $this->assertNull($this->numbering->apply([]));
    }
}
