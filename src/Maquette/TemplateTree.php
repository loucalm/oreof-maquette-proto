<?php

declare(strict_types=1);

namespace App\Maquette;

/**
 * Manipulation de l'arbre JSON d'un StructureTemplate par « chemin » (indices
 * séparés par des points : « 0.1.2 » = tree[0].children[1].children[2] ;
 * chaîne vide = la racine). Utilisé par l'éditeur visuel de template.
 */
final class TemplateTree
{
    /** @param list<array<string, mixed>> $tree */
    public function __construct(public array $tree = [])
    {
    }

    /** @return list<int> */
    public static function path(string $path): array
    {
        $path = trim($path);

        return $path === '' ? [] : array_map('intval', explode('.', $path));
    }

    /**
     * Un nœud nouvellement créé dans le template part figé par défaut (ni
     * ajout d'un autre nœud du même type à cette position, ni suppression, ni
     * duplication) mais reste déplaçable — l'admin ouvre au cas par cas.
     *
     * @param list<int> $path
     */
    public function addChild(array $path, string $type, string $label): void
    {
        $node = ['type' => $type, 'label' => $label, 'locked' => ['add', 'delete', 'duplicate']];
        if ($path === []) {
            $this->tree[] = $node;

            return;
        }
        $this->tree = $this->walk($this->tree, $path, static function (array &$n) use ($node): void {
            $n['children'] ??= [];
            $n['children'][] = $node;
        });
    }

    /** @param list<int> $path */
    public function remove(array $path): void
    {
        $this->withParent($path, static function (array &$sibs, int $i): void {
            array_splice($sibs, $i, 1);
        });
    }

    /** @param list<int> $path */
    public function move(array $path, int $dir): void
    {
        $this->withParent($path, static function (array &$sibs, int $i) use ($dir): void {
            $j = $i + $dir;
            if (isset($sibs[$j])) {
                [$sibs[$i], $sibs[$j]] = [$sibs[$j], $sibs[$i]];
            }
        });
    }

    /** Bascule le trio « figé » (add + delete + duplicate) d'un seul geste. */
    public function toggleRigid(array $path): void
    {
        $this->toggleLockedTokens($path, ['add', 'delete', 'duplicate']);
    }

    /** Bascule le verrou de déplacement, indépendamment du trio « figé ». */
    public function toggleMove(array $path): void
    {
        $this->toggleLockedTokens($path, ['move']);
    }

    /**
     * Ajoute ou retire un groupe de jetons de `locked` ensemble (tous présents
     * → tous retirés ; sinon tous ajoutés), sans toucher aux autres jetons.
     *
     * @param list<int>    $path
     * @param list<string> $tokens
     */
    private function toggleLockedTokens(array $path, array $tokens): void
    {
        $this->tree = $this->walk($this->tree, $path, static function (array &$n) use ($tokens): void {
            $current = (array) ($n['locked'] ?? []);
            $allSet = [] === array_diff($tokens, $current);
            $n['locked'] = $allSet
                ? array_values(array_diff($current, $tokens))
                : array_values(array_unique([...$current, ...$tokens]));
            if ($n['locked'] === []) {
                unset($n['locked']);
            }
        });
    }

    /** @param list<int> $path */
    public function rename(array $path, string $label): void
    {
        $this->tree = $this->walk($this->tree, $path, static function (array &$n) use ($label): void {
            $n['label'] = $label;
        });
    }

    // ─── interne ───

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<int>                  $path
     *
     * @return list<array<string, mixed>>
     */
    private function walk(array $nodes, array $path, callable $cb): array
    {
        $i = array_shift($path);
        if (!isset($nodes[$i])) {
            return $nodes;
        }
        if ($path === []) {
            $cb($nodes[$i]);
        } else {
            $nodes[$i]['children'] ??= [];
            $nodes[$i]['children'] = $this->walk($nodes[$i]['children'], $path, $cb);
        }

        return $nodes;
    }

    /**
     * Applique $cb(&$siblings, $index) sur la liste qui contient le nœud du
     * chemin (et son index).
     *
     * @param list<int> $path
     */
    private function withParent(array $path, callable $cb): void
    {
        if ($path === []) {
            return;
        }
        $idx = array_pop($path);
        if ($path === []) {
            $cb($this->tree, $idx);

            return;
        }
        $this->tree = $this->walk($this->tree, $path, static function (array &$n) use ($cb, $idx): void {
            $n['children'] ??= [];
            $cb($n['children'], $idx);
        });
    }
}
