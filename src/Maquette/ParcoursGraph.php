<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Entity\Formation;
use App\Entity\Node;
use App\Repository\NodeRepository;

/**
 * Prépare la disposition de l'arborescence des parcours (ramification) :
 * une ligne par parcours, positionné en colonnes d'années, + les liens
 * parent → enfant.
 */
final class ParcoursGraph
{
    public function __construct(private readonly NodeRepository $nodes)
    {
    }

    private const COL_W = 240;
    private const ROW_H = 92;
    private const BOX_H = 54;
    private const HEADER_H = 44;
    private const PAD = 16;
    private const GAP = 14;

    /**
     * @return array{
     *     rows: list<array{node: Node, row: int, colStart: int, colEnd: int}>,
     *     edges: list<array{from: int, to: int}>,
     *     maxAnnee: int,
     *     parcours: list<Node>,
     *     boxes: array<int, array{x: float, y: float, w: float, cy: float}>,
     *     width: int,
     *     height: int,
     *     colW: int, headerH: int, boxH: int, pad: int
     * }
     */
    public function build(Formation $formation): array
    {
        $parcours = array_values(array_filter(
            $this->nodes->findForFormation($formation),
            static fn (Node $n) => $n->isParcours(),
        ));
        usort($parcours, static fn (Node $a, Node $b) => $a->getPosition() <=> $b->getPosition());

        /** @var array<int, list<Node>> $childrenOf */
        $childrenOf = [];
        $ids = [];
        foreach ($parcours as $p) {
            $ids[$p->getId()] = true;
            $childrenOf[$p->getParcoursParent()?->getId() ?? 0][] = $p;
        }

        // DFS pré-ordre depuis les racines (parcours sans parent, ou parent hors formation)
        $rows = [];
        $rowIndex = 0;
        $seen = [];
        $visit = function (Node $p) use (&$visit, &$rows, &$rowIndex, &$seen, $childrenOf): void {
            if (isset($seen[$p->getId()])) {
                return; // garde-fou anti-cycle
            }
            $seen[$p->getId()] = true;
            $rows[] = [
                'node' => $p,
                'row' => $rowIndex++,
                'colStart' => $p->getAnneeDebut(),
                'colEnd' => $p->getAnneeFin(),
            ];
            foreach ($childrenOf[$p->getId()] ?? [] as $child) {
                $visit($child);
            }
        };
        foreach ($parcours as $p) {
            $parentId = $p->getParcoursParent()?->getId();
            if ($parentId === null || !isset($ids[$parentId])) {
                $visit($p);
            }
        }
        // parcours restés hors forêt (cycle) : on les rattache en racine
        foreach ($parcours as $p) {
            if (!isset($seen[$p->getId()])) {
                $visit($p);
            }
        }

        $edges = [];
        foreach ($parcours as $p) {
            $parent = $p->getParcoursParent();
            if ($parent !== null && isset($ids[$parent->getId()])) {
                $edges[] = ['from' => $parent->getId(), 'to' => $p->getId()];
            }
        }

        $maxAnnee = 1;
        foreach ($rows as $r) {
            $maxAnnee = max($maxAnnee, $r['colEnd']);
        }

        // géométrie (clé = id du nœud ; PHP conserve les clés entières)
        $boxes = [];
        foreach ($rows as $r) {
            $x = self::PAD + ($r['colStart'] - 1) * self::COL_W + self::GAP / 2;
            $w = ($r['colEnd'] - $r['colStart'] + 1) * self::COL_W - self::GAP;
            $y = self::HEADER_H + $r['row'] * self::ROW_H + (self::ROW_H - self::BOX_H) / 2;
            $boxes[$r['node']->getId()] = ['x' => $x, 'y' => $y, 'w' => $w, 'cy' => $y + self::BOX_H / 2];
        }

        return [
            'rows' => $rows,
            'edges' => $edges,
            'maxAnnee' => $maxAnnee,
            'parcours' => $parcours,
            'boxes' => $boxes,
            'width' => self::PAD * 2 + $maxAnnee * self::COL_W,
            'height' => self::HEADER_H + \count($rows) * self::ROW_H + 20,
            'colW' => self::COL_W,
            'headerH' => self::HEADER_H,
            'boxH' => self::BOX_H,
            'pad' => self::PAD,
        ];
    }
}
