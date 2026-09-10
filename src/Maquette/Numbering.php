<?php

declare(strict_types=1);

namespace App\Maquette;

/**
 * Numérotation hiérarchique calculée des nœuds (« Année 1 », « UE 1.1 »,
 * « EC 1.1.a »…). Référence recalculée à chaque rendu, jamais stockée.
 *
 * Règle : la référence d'un nœud numéroté = référence du plus proche ANCÊTRE
 * numéroté + « . » + indice 1-based parmi les nœuds de même type de ce même
 * contexte (dans l'ordre de l'arbre). Les types non numérotés (ex. Semestre)
 * sont transparents : leurs enfants héritent du contexte du parent numéroté.
 * Le style de l'indice (décimal / alpha / romain) vient de NodeType::numberStyle.
 */
final class Numbering
{
    /**
     * Renseigne NodeView::ref pour tout l'arbre.
     *
     * @param list<NodeView> $roots
     */
    public function apply(array $roots): void
    {
        /** @var array<string, int> $counters clé "refParent|typeKey" → compteur */
        $counters = [];
        $this->walk($roots, '', $counters);
    }

    /**
     * @param list<NodeView>       $views
     * @param array<string, int>   $counters
     */
    private function walk(array $views, string $parentRef, array &$counters): void
    {
        foreach ($views as $view) {
            $type = $view->node->getType();
            if ($type->isNumbered()) {
                $key = $parentRef.'|'.$type->getKey();
                $n = ($counters[$key] = ($counters[$key] ?? 0) + 1);
                $idx = $this->format($n, $type->getNumberStyle());
                $view->ref = $parentRef === '' ? $idx : $parentRef.'.'.$idx;
                $childRef = $view->ref;
            } else {
                $childRef = $parentRef;
            }
            $this->walk($view->children, $childRef, $counters);
        }
    }

    public function format(int $n, string $style): string
    {
        return match ($style) {
            'alpha' => $this->alpha($n),
            'roman' => $this->roman($n),
            default => (string) $n,
        };
    }

    /** 1 → a, 26 → z, 27 → aa (base 26 bijective). */
    private function alpha(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(97 + $n % 26).$s;
            $n = intdiv($n, 26);
        }

        return $s !== '' ? $s : 'a';
    }

    private function roman(int $n): string
    {
        if ($n <= 0) {
            return (string) $n;
        }
        $map = [1000 => 'm', 900 => 'cm', 500 => 'd', 400 => 'cd', 100 => 'c', 90 => 'xc', 50 => 'l', 40 => 'xl', 10 => 'x', 9 => 'ix', 5 => 'v', 4 => 'iv', 1 => 'i'];
        $out = '';
        foreach ($map as $v => $sym) {
            while ($n >= $v) {
                $out .= $sym;
                $n -= $v;
            }
        }

        return $out;
    }
}
