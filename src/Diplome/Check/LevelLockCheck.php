<?php

declare(strict_types=1);

namespace App\Diplome\Check;

use App\Diplome\Spec\StructureSpec;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Niveau verrouillé : le nombre et l'ordre de ses nœuds sous chaque parent sont figés. Compare la suite
 * ordonnée des identifiants avant et après une modification ; toute différence (ajout, suppression,
 * recréation, déplacement) est une violation bloquante, levée seulement par une dérogation sans valeur.
 */
final class LevelLockCheck
{
    public const PREFIX = 'lock:';

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /** @return list<StructureViolation> */
    public function check(MaquetteDoc $before, MaquetteDoc $after, StructureSpec $s): array
    {
        $violations = [];
        foreach ($s->levels as $level) {
            if (!$level->locked) {
                continue;
            }
            $was = self::sequences($before, $level->typeKey);
            $now = self::sequences($after, $level->typeKey);
            $label = $after->type($level->typeKey)?->getLabel() ?? $level->typeKey;
            foreach ($now + $was as $parent => $_) {
                // un parent absent d'un côté (créé ou supprimé avec son sous-arbre) relève de son propre niveau
                if (!self::exists($before, (string) $parent) || !self::exists($after, (string) $parent)) {
                    continue;
                }
                if (($was[$parent] ?? []) === ($now[$parent] ?? [])) {
                    continue;
                }
                $violations[] = new StructureViolation(
                    self::PREFIX.$level->typeKey,
                    (string) $parent,
                    $this->translator->trans('formation.check.level_locked', ['%level%' => $label], 'formation'),
                    true,
                    null,
                    null,
                );
            }
        }

        return $violations;
    }

    private static function exists(MaquetteDoc $doc, string $nid): bool
    {
        return '' === $nid || null !== $doc->node($nid);
    }

    /** @return array<string, list<string>> nid du parent ('' pour la racine) → nids ordonnés des nœuds du type */
    private static function sequences(MaquetteDoc $doc, string $typeKey): array
    {
        $out = [];
        foreach ($doc->allNodes() as $node) {
            if ($node->typeKey === $typeKey) {
                $out[$node->getParent()?->nid ?? ''][] = $node->nid;
            }
        }
        // allNodes() suit l'index, pas l'ordre de l'arbre : on relit l'ordre chez le parent
        foreach ($out as $parent => $nids) {
            $siblings = '' === $parent ? $doc->roots : ($doc->node((string) $parent)?->getChildren() ?? []);
            $out[$parent] = array_values(array_map(static fn (TreeNode $n) => $n->nid, array_filter($siblings, static fn (TreeNode $n) => $n->typeKey === $typeKey)));
        }

        return $out;
    }
}
