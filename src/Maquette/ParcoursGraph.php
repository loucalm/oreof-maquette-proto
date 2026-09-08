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
    private const ROW_H = 108;
    private const BOX_H = 56;
    private const HEADER_H = 56;
    private const PAD = 16;
    private const GAP = 26;

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

        $maxAnnee = 1;
        foreach ($rows as $r) {
            $maxAnnee = max($maxAnnee, $r['colEnd']);
        }

        // géométrie des boîtes (clé = id du nœud)
        $rowByNode = [];
        $boxes = [];
        foreach ($rows as $r) {
            $rowByNode[$r['node']->getId()] = $r;
            $x = self::PAD + ($r['colStart'] - 1) * self::COL_W + self::GAP / 2;
            $w = ($r['colEnd'] - $r['colStart'] + 1) * self::COL_W - self::GAP;
            $y = self::HEADER_H + $r['row'] * self::ROW_H + (self::ROW_H - self::BOX_H) / 2;
            $boxes[$r['node']->getId()] = [
                'x' => $x, 'y' => $y, 'w' => $w,
                'cx' => $x + $w / 2, 'cy' => $y + self::BOX_H / 2,
                'right' => $x + $w, 'bottom' => $y + self::BOX_H,
            ];
        }

        // liens parent → enfant, avec tracé pré-calculé
        $edges = [];
        foreach ($parcours as $p) {
            $parent = $p->getParcoursParent();
            if ($parent === null || !isset($ids[$parent->getId()]) || !isset($boxes[$parent->getId()])) {
                continue;
            }
            $a = $boxes[$parent->getId()];
            $b = $boxes[$p->getId()];
            $childRightOfParent = $rowByNode[$p->getId()]['colStart'] > $rowByNode[$parent->getId()]['colEnd'];

            if ($childRightOfParent) {
                // flux horizontal : coude en L, bord droit du parent → bord gauche de l'enfant
                $x1 = $a['right'];
                $y1 = $a['cy'];
                $x2 = $b['x'] - 2;
                $y2 = $b['cy'];
                $midX = $x1 + max(14.0, min(40.0, ($x2 - $x1) / 2));
                $r = min(10.0, abs($y2 - $y1) / 2, abs($x2 - $midX));
                $dir = $y2 >= $y1 ? 1 : -1;
                $path = sprintf(
                    'M %.1f %.1f H %.1f Q %.1f %.1f %.1f %.1f V %.1f Q %.1f %.1f %.1f %.1f H %.1f',
                    $x1, $y1, $midX - $r,
                    $midX, $y1, $midX, $y1 + $r * $dir,
                    $y2 - $r * $dir,
                    $midX, $y2, $midX + $r, $y2,
                    $x2,
                );
            } else {
                // approfondissement : le parent chevauche la colonne de l'enfant.
                // tronc vertical depuis le bas du parent, palier juste au-dessus
                // de l'enfant, puis descente ; les enfants partagent le tronc.
                $x1 = $a['cx'];
                $y1 = $a['bottom'];
                $x2 = $b['cx'];
                $y2 = $b['y'] - 2;
                if (abs($x2 - $x1) < 3.0) {
                    $path = sprintf('M %.1f %.1f V %.1f', $x1, $y1, $y2);
                } else {
                    $turnY = $y2 - 14;
                    $r = min(9.0, abs($x2 - $x1) / 2, max(1.0, abs($turnY - $y1)) / 2);
                    $dir = $x2 >= $x1 ? 1.0 : -1.0;
                    $path = sprintf(
                        'M %.1f %.1f V %.1f Q %.1f %.1f %.1f %.1f H %.1f Q %.1f %.1f %.1f %.1f V %.1f',
                        $x1, $y1,
                        $turnY - $r,
                        $x1, $turnY, $x1 + $r * $dir, $turnY,
                        $x2 - $r * $dir,
                        $x2, $turnY, $x2, $turnY + $r,
                        $y2,
                    );
                }
            }

            $edges[] = ['from' => $parent->getId(), 'to' => $p->getId(), 'path' => $path];
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
