<?php

declare(strict_types=1);

namespace App\Maquette\Doc;

use App\Entity\Formation;
use App\Entity\NodeType;

/**
 * Représentation en mémoire d'une maquette : les 3 documents JSON d'une
 * formation (propriétés formation / propriétés parcours / arbre + nœuds)
 * hydratés en graphe objet manipulable.
 *
 * Créé et persisté par le service App\Maquette\Maquette. Les mutations
 * (add/move/remove/duplicate) sont en mémoire ; `Maquette::save()` resérialise.
 */
final class MaquetteDoc
{
    /** @var list<TreeNode> nœuds racine, dans l'ordre (BCC mono compris) */
    public array $roots = [];

    /** @var array<string, TreeNode> nid → nœud (tout l'arbre, à plat) */
    public array $index = [];

    /**
     * Document 1/3 — sections « Paramètre de la formation » (mono).
     *
     * @var array<string, array<string, mixed>>
     */
    public array $parametres = [];

    /**
     * Document 2/3 — sections « Paramètre du parcours », map nid → sections.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $parcours = [];

    /** @param array<string, NodeType> $types clé → type */
    public function __construct(
        public readonly Formation $formation,
        public array $types = [],
    ) {
    }

    // ─── accès ───

    public function node(string $nid): ?TreeNode
    {
        return $this->index[$nid] ?? null;
    }

    /** @return list<TreeNode> */
    public function allNodes(): array
    {
        return array_values($this->index);
    }

    public function type(string $key): ?NodeType
    {
        return $this->types[$key] ?? null;
    }

    /**
     * Racines de la structure PÉDAGOGIQUE (hors référentiel de compétences).
     *
     * @return list<TreeNode>
     */
    public function pedagogicalRoots(): array
    {
        return array_values(array_filter($this->roots, static fn (TreeNode $n) => !$n->isCompetenceNode()));
    }

    /**
     * Blocs BCC de niveau formation (mono-parcours), transversal en tête.
     *
     * @return list<TreeNode>
     */
    public function competenceBlocs(): array
    {
        $blocs = array_values(array_filter($this->roots, static fn (TreeNode $n) => $n->isCompetenceNode()));
        usort($blocs, static fn (TreeNode $a, TreeNode $b): int => [!$a->isTransversalBloc(), $a->getPosition()] <=> [!$b->isTransversalBloc(), $b->getPosition()]);

        return $blocs;
    }

    public function hasTransversalBloc(): bool
    {
        foreach ($this->competenceBlocs() as $b) {
            if ($b->isTransversalBloc()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<TreeNode> nœuds « parcours », dans l'ordre */
    public function parcoursNodes(): array
    {
        return array_values(array_filter($this->roots, static fn (TreeNode $n) => $n->isParcours()));
    }

    // ─── paramètres ───

    /** @return array<string, mixed> */
    public function formationParam(string $section): array
    {
        $v = $this->parametres[$section] ?? [];

        return \is_array($v) ? $v : [];
    }

    /** @param array<string, mixed> $data */
    public function setFormationParam(string $section, array $data): void
    {
        $this->parametres[$section] = $data;
    }

    /** @return array<string, mixed> */
    public function parcoursParam(string $nid, string $section): array
    {
        $v = $this->parcours[$nid][$section] ?? [];

        return \is_array($v) ? $v : [];
    }

    /** @param array<string, mixed> $data */
    public function setParcoursParam(string $nid, string $section, array $data): void
    {
        $this->parcours[$nid] ??= [];
        $this->parcours[$nid][$section] = $data;
    }

    // ─── mutations ───

    public function newNid(): string
    {
        $max = 0;
        foreach (array_keys($this->index) as $nid) {
            if (preg_match('/^n(\d+)$/', $nid, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return 'n'.($max + 1);
    }

    /**
     * Crée un nœud sous $parentNid (ou racine si null), en dernière position.
     */
    public function addNode(?string $parentNid, string $typeKey, string $label = '', ?string $code = null): TreeNode
    {
        $type = $this->types[$typeKey] ?? null;
        if ($type === null) {
            throw new \InvalidArgumentException("Type de nœud inconnu : « $typeKey ».");
        }

        $node = new TreeNode($this->newNid(), $typeKey, $label, $code !== '' ? $code : null);
        $node->bindType($type);
        $node->doc = $this;

        $parent = $parentNid !== null ? $this->node($parentNid) : null;
        if ($parent !== null) {
            $node->parent = $parent;
            $parent->children[] = $node;
        } else {
            $this->roots[] = $node;
        }
        $this->index[$node->nid] = $node;

        return $node;
    }

    /**
     * Déplace $node sous $newParent (ou racine) à l'index donné.
     */
    public function moveNode(TreeNode $node, ?TreeNode $newParent, int $index): void
    {
        $this->detach($node);
        $node->parent = $newParent;

        $bucket = $newParent !== null ? $newParent->children : $this->roots;
        $index = max(0, min($index, \count($bucket)));
        array_splice($bucket, $index, 0, [$node]);

        if ($newParent !== null) {
            $newParent->children = $bucket;
        } else {
            $this->roots = $bucket;
        }
    }

    /** Retire $node (et toute sa descendance) de l'arbre. */
    public function removeNode(TreeNode $node): void
    {
        $this->detach($node);
        foreach ($this->descendants($node) as $d) {
            unset($this->index[$d->nid], $this->parcours[$d->nid]);
            // rompt les liens de ramification pointant vers un parcours supprimé
            foreach ($this->index as $other) {
                if (($other->attributes['parcoursParent'] ?? null) === $d->nid) {
                    unset($other->attributes['parcoursParent']);
                }
            }
        }
    }

    /**
     * Duplique $node (récursivement) sous $parent (ou son parent actuel).
     * Les nids sont réattribués.
     */
    public function duplicateNode(TreeNode $node, ?TreeNode $parent = null): TreeNode
    {
        $parent ??= $node->parent;
        $copy = $this->cloneSubtree($node, $parent, $node->label !== '' ? $node->label.' (copie)' : '');

        if ($parent !== null) {
            $parent->children[] = $copy;
        } else {
            $this->roots[] = $copy;
        }

        return $copy;
    }

    /**
     * Recopie un sous-arbre issu d'une AUTRE maquette (raccrocher un nœud
     * mutualisé) sous $parent. Nouveaux nids ; libellé conservé.
     */
    public function importSubtree(TreeNode $external, ?TreeNode $parent): TreeNode
    {
        $copy = $this->cloneSubtree($external, $parent, $external->label);
        if ($parent !== null) {
            $parent->children[] = $copy;
        } else {
            $this->roots[] = $copy;
        }

        return $copy;
    }

    /**
     * Réordonne les blocs de compétences « réguliers » (hors transversal) dans
     * leur contexte ($parcours en multi, racine en mono) : $bloc est inséré à
     * l'index donné parmi les autres blocs réguliers, l'ordre des nœuds non-BCC
     * étant préservé.
     */
    public function reorderRegularBlocs(?TreeNode $context, TreeNode $bloc, int $index): void
    {
        $bucket = $context !== null ? $context->children : $this->roots;

        $nonBlocs = [];
        $regulars = [];
        $transversal = [];
        foreach ($bucket as $n) {
            if ($n === $bloc) {
                continue;
            }
            if (!$n->isBloc()) {
                $nonBlocs[] = $n;
            } elseif ($n->isTransversalBloc()) {
                $transversal[] = $n;
            } else {
                $regulars[] = $n;
            }
        }
        $index = max(0, min($index, \count($regulars)));
        array_splice($regulars, $index, 0, [$bloc]);

        $new = array_merge($nonBlocs, $transversal, $regulars);
        if ($context !== null) {
            $context->children = $new;
        } else {
            $this->roots = $new;
        }
    }

    // ─── (dé)sérialisation ───

    /** Reconstruit `index` + liens parent/doc à plat depuis `roots`. */
    public function reindex(): void
    {
        $this->index = [];
        $walk = function (array $nodes, ?TreeNode $parent) use (&$walk): void {
            foreach ($nodes as $n) {
                \assert($n instanceof TreeNode);
                $n->parent = $parent;
                $n->doc = $this;
                if ($n->nid === '' || isset($this->index[$n->nid])) {
                    $n->nid = $this->newNid();
                }
                $this->index[$n->nid] = $n;
                $walk($n->children, $n);
            }
        };
        $walk($this->roots, null);

        // purge des paramètres parcours orphelins
        $this->parcours = array_intersect_key($this->parcours, $this->index);
    }

    /** @return list<array<string, mixed>> */
    public function dumpTree(): array
    {
        return array_map(static fn (TreeNode $n) => $n->toArray(), $this->roots);
    }

    // ─── interne ───

    private function detach(TreeNode $node): void
    {
        $bucket = $node->parent !== null ? $node->parent->children : $this->roots;
        $bucket = array_values(array_filter($bucket, static fn (TreeNode $n) => $n !== $node));
        if ($node->parent !== null) {
            $node->parent->children = $bucket;
        } else {
            $this->roots = $bucket;
        }
    }

    /** @return list<TreeNode> $node inclus */
    private function descendants(TreeNode $node): array
    {
        $out = [$node];
        foreach ($node->children as $c) {
            $out = array_merge($out, $this->descendants($c));
        }

        return $out;
    }

    private function cloneSubtree(TreeNode $src, ?TreeNode $parent, ?string $label = null): TreeNode
    {
        $copy = new TreeNode(
            nid: $this->newNid(),
            typeKey: $src->typeKey,
            label: $label ?? $src->label,
            code: $src->code,
            attributes: $src->attributes,
            capabilityOverrides: $src->capabilityOverrides,
            locked: $src->locked,
        );
        $copy->bindType($src->getType());
        $copy->doc = $this;
        $copy->parent = $parent;
        $this->index[$copy->nid] = $copy;

        foreach ($src->children as $child) {
            $copy->children[] = $this->cloneSubtree($child, $copy);
        }

        return $copy;
    }
}
