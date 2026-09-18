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
        $node = ['type' => $type, 'label' => $label, 'locked' => ['add', 'delete', 'duplicate'], 'uid' => self::newUid()];
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

    /**
     * Duplique un nœud (et toute sa branche) juste après lui-même, même
     * parent — même convention que MaquetteDoc::duplicateNode() côté
     * formation (« (copie) » suffixé au libellé s'il n'est pas vide). No-op
     * si le nœud est figé (jeton `duplicate` dans `locked`).
     *
     * @param list<int> $path
     */
    public function duplicate(array $path): void
    {
        $this->withParent($path, function (array &$sibs, int $i): void {
            if (!isset($sibs[$i]) || \in_array('duplicate', (array) ($sibs[$i]['locked'] ?? []), true)) {
                return;
            }
            $copy = $sibs[$i];
            if (($copy['label'] ?? '') !== '') {
                $copy['label'] .= ' (copie)';
            }
            // uid propre sur toute la branche copiée : sinon le pliage persisté (par uid, cf.
            // ensureUids()) confondrait l'original et la copie.
            [$copy] = $this->regenerateUids([$copy]);
            array_splice($sibs, $i + 1, 0, [$copy]);
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

    /**
     * Réordonne (glisser-déposer) les enfants directs du nœud à `$parentPath`
     * (« [] » = la racine) : `$order` est la liste des index d'origine dans
     * leur nouvel ordre. Ignoré silencieusement si `$order` n'est pas une
     * permutation valide des index actuels (requête corrompue/obsolète).
     *
     * @param list<int> $parentPath
     * @param list<int> $order
     */
    public function reorder(array $parentPath, array $order): void
    {
        if ($parentPath === []) {
            $this->tree = $this->applyOrder($this->tree, $order);

            return;
        }
        $this->tree = $this->walk($this->tree, $parentPath, function (array &$n) use ($order): void {
            $n['children'] ??= [];
            $n['children'] = $this->applyOrder($n['children'], $order);
        });
    }

    /**
     * @param list<array<string, mixed>> $siblings
     * @param list<int>                  $order
     *
     * @return list<array<string, mixed>>
     */
    private function applyOrder(array $siblings, array $order): array
    {
        $count = \count($siblings);
        $expected = $count > 0 ? range(0, $count - 1) : [];
        $check = $order;
        sort($check);
        if ($check !== $expected) {
            return $siblings;
        }

        return array_map(static fn (int $i) => $siblings[$i], $order);
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

    /**
     * Attribue un `uid` stable à tout nœud qui n'en a pas encore (arbres de
     * fixtures écrits à la main avant l'introduction du pliage persisté côté
     * éditeur — ce uid n'a aucun autre usage dans l'appli). Idempotent :
     * n'écrit rien si tous les nœuds en ont déjà un.
     *
     * @return bool true si au moins un uid a été généré (donc à persister)
     */
    public function ensureUids(): bool
    {
        $changed = false;
        $this->tree = $this->assignMissingUids($this->tree, $changed);

        return $changed;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private function assignMissingUids(array $nodes, bool &$changed): array
    {
        foreach ($nodes as &$n) {
            if (!\is_string($n['uid'] ?? null) || '' === $n['uid']) {
                $n['uid'] = self::newUid();
                $changed = true;
            }
            if (\is_array($n['children'] ?? null)) {
                $n['children'] = $this->assignMissingUids($n['children'], $changed);
            }
        }

        return $nodes;
    }

    /**
     * Régénère (sans condition, contrairement à assignMissingUids()) le uid de
     * toute une branche — utilisé par duplicate() pour que la copie n'hérite
     * d'aucun uid de l'original.
     *
     * @param list<array<string, mixed>> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private function regenerateUids(array $nodes): array
    {
        foreach ($nodes as &$n) {
            $n['uid'] = self::newUid();
            if (\is_array($n['children'] ?? null)) {
                $n['children'] = $this->regenerateUids($n['children']);
            }
        }

        return $nodes;
    }

    private static function newUid(): string
    {
        return bin2hex(random_bytes(4));
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
