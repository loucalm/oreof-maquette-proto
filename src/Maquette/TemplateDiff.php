<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Entity\Formation;
use App\Entity\StructureTemplate;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Repository\StructureTemplateRepository;

/**
 * Écarts entre une formation et le template de son diplôme. Le template est
 * appliqué par copie à la création : s'il évolue ensuite, les formations
 * existantes ne suivent pas. Ce service les compare (par position : chemin de
 * « type#rang parmi les frères de même type ») et peut propager les réglages.
 */
final class TemplateDiff
{
    public function __construct(
        private readonly StructureTemplateRepository $templates,
        private readonly Maquette $maquette,
    ) {
    }

    public function templateFor(Formation $formation): ?StructureTemplate
    {
        $diplome = $formation->getDiplome();

        return null !== $diplome ? $this->templates->findOneByDiplome($diplome) : null;
    }

    /**
     * @return array{template: StructureTemplate, missing: list<string>, unlocked: list<string>, overrides: list<string>}|null
     */
    public function diff(Formation $formation): ?array
    {
        $template = $this->templateFor($formation);
        if (null === $template) {
            return null;
        }
        $doc = $this->maquette->open($formation);
        $nodes = $this->index($doc->pedagogicalRoots());
        $byUid = $this->byUid($doc);

        $missing = [];
        $unlocked = [];
        foreach ($this->specIndex($template->getTree()) as $key => $spec) {
            $locks = array_values(array_filter((array) ($spec['locked'] ?? []), 'is_string'));
            if ([] === $locks) {
                continue; // seuls les éléments imposés comptent
            }
            $node = $this->match($spec, $key, $byUid, $nodes);
            if (null === $node) {
                $missing[] = $this->specLabel($spec);
            } elseif (array_diff($locks, $node->locked) !== []) {
                $unlocked[] = $node->getDisplayLabel() ?: $this->specLabel($spec);
            }
        }

        $overrides = [];
        foreach ($template->getFieldOverrides() as $typeKey => $caps) {
            foreach ($doc->allNodes() as $node) {
                if ($node->typeKey === $typeKey && ($node->capabilityOverrides ?? []) != $caps) {
                    $overrides[] = $doc->type($typeKey)?->getLabel() ?? $typeKey;
                    break;
                }
            }
        }

        return ['template' => $template, 'missing' => $missing, 'unlocked' => $unlocked, 'overrides' => $overrides];
    }

    /**
     * Applique aux nœuds existants les réglages de champs du template et, si
     * demandé, ses verrous (y compris ceux levés depuis par une dérogation).
     *
     * @return int nombre de nœuds modifiés
     */
    public function propagate(Formation $formation, bool $relock): int
    {
        $template = $this->templateFor($formation);
        if (null === $template) {
            return 0;
        }
        $doc = $this->maquette->open($formation);
        $changed = [];

        foreach ($template->getFieldOverrides() as $typeKey => $caps) {
            foreach ($doc->allNodes() as $node) {
                if ($node->typeKey === $typeKey && ($node->capabilityOverrides ?? []) != $caps) {
                    $node->capabilityOverrides = $caps;
                    $changed[$node->nid] = true;
                }
            }
        }

        if ($relock) {
            $nodes = $this->index($doc->pedagogicalRoots());
            $byUid = $this->byUid($doc);
            foreach ($this->specIndex($template->getTree()) as $key => $spec) {
                $locks = array_values(array_filter((array) ($spec['locked'] ?? []), 'is_string'));
                $node = $this->match($spec, $key, $byUid, $nodes);
                if (null !== $node && [] !== $locks && array_diff($locks, $node->locked) !== []) {
                    $node->locked = array_values(array_unique([...$node->locked, ...$locks]));
                    $changed[$node->nid] = true;
                }
            }
        }

        if ([] !== $changed) {
            $this->maquette->save($formation, $doc, sprintf('Réglages du template « %s » réappliqués', $template->getLabel()));
        }

        return \count($changed);
    }

    /**
     * @param list<TreeNode> $nodes
     *
     * @return array<string, TreeNode>
     */
    private function index(array $nodes, string $prefix = ''): array
    {
        $out = [];
        $rank = [];
        foreach ($nodes as $node) {
            if ($node->isCompetenceNode()) {
                continue;
            }
            $rank[$node->typeKey] = ($rank[$node->typeKey] ?? 0) + 1;
            $key = $prefix.'/'.$node->typeKey.'#'.$rank[$node->typeKey];
            $out[$key] = $node;
            $out += $this->index($node->getChildren(), $key);
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $specs
     *
     * @return array<string, array<string, mixed>>
     */
    private function specIndex(array $specs, string $prefix = ''): array
    {
        $out = [];
        $rank = [];
        foreach ($specs as $spec) {
            $type = (string) ($spec['type'] ?? '');
            $rank[$type] = ($rank[$type] ?? 0) + 1;
            $key = $prefix.'/'.$type.'#'.$rank[$type];
            $out[$key] = $spec;
            $out += $this->specIndex((array) ($spec['children'] ?? []), $key);
        }

        return $out;
    }

    /** @return array<string, TreeNode> nœuds instanciés depuis un template, par uid du nœud de template */
    private function byUid(MaquetteDoc $doc): array
    {
        $out = [];
        foreach ($doc->allNodes() as $node) {
            $uid = $node->getAttribute('_tpl');
            if (\is_string($uid) && '' !== $uid) {
                $out[$uid] = $node;
            }
        }

        return $out;
    }

    /**
     * Nœud de la formation correspondant à un nœud du template : par uid quand la
     * formation a été créée après le suivi des identités, sinon par position
     * (et seulement s'il n'est pas lui-même issu d'un autre nœud de template).
     *
     * @param array<string, mixed>     $spec
     * @param array<string, TreeNode>  $byUid
     * @param array<string, TreeNode>  $byPosition
     */
    private function match(array $spec, string $key, array $byUid, array $byPosition): ?TreeNode
    {
        $uid = (string) ($spec['uid'] ?? '');
        if ('' !== $uid && [] !== $byUid) {
            return $byUid[$uid] ?? null;
        }
        $node = $byPosition[$key] ?? null;

        return null !== $node && null === $node->getAttribute('_tpl') ? $node : null;
    }

    /** @param array<string, mixed> $spec */
    private function specLabel(array $spec): string
    {
        return '' !== (string) ($spec['label'] ?? '') ? (string) $spec['label'] : (string) ($spec['type'] ?? '?');
    }
}
