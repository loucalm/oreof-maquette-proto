<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Entity\Formation;
use App\Entity\FormationRevision;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Repository\FormationRepository;
use App\Repository\FormationRevisionRepository;
use App\Repository\NodeTypeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service unique de manipulation des maquettes.
 *
 * Une formation stocke sa maquette dans 3 colonnes JSON (`parametres`,
 * `dataParcours`, `arbre`). Ce service les hydrate en un MaquetteDoc
 * manipulable, et le resérialise. Toute création / modification / déplacement /
 * suppression de nœud, tout paramètre de formation ou de parcours, et l'export
 * passent par ici — plus aucune ligne `Node` en base.
 *
 * Historique : `open()` capture l'état complet de la formation tel qu'il est
 * au tout premier accès de la requête ; `save()` compare cet état « avant » à
 * l'état qui vient d'être écrit et, s'il diffère, l'archive dans une
 * FormationRevision (avec une description auto-générée) avant d'écraser —
 * aucun contrôleur n'a besoin de le déclencher explicitement.
 */
final class Maquette
{
    /** Nombre de révisions conservées par formation (purge à chaque save). */
    private const MAX_REVISIONS = 50;

    /** @var array<int, MaquetteDoc> cache par requête, fid → doc */
    private array $cache = [];

    /** @var array<int, array<string, mixed>> état complet au 1er open() de la requête, fid → snapshot */
    private array $before = [];

    /** @var array<string, \App\Entity\NodeType>|null */
    private ?array $typeMap = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NodeTypeRepository $types,
        private readonly FormationRepository $formations,
        private readonly FormationRevisionRepository $revisions,
        private readonly Completion $completion,
    ) {
    }

    public function open(Formation $formation): MaquetteDoc
    {
        $id = $formation->getId() ?? spl_object_id($formation);
        $this->before[$id] ??= $this->snapshotOf($formation);

        return $this->cache[$id] ??= $this->hydrate($formation);
    }

    public function save(Formation $formation, MaquetteDoc $doc, ?string $label = null): void
    {
        $doc->reindex();
        $id = $formation->getId() ?? spl_object_id($formation);
        $before = $this->before[$id] ?? null;

        $formation->setArbre($doc->dumpTree());
        $formation->setDataParcours($doc->parcours);
        $formation->setParametres($doc->parametres);
        $formation->setStats($this->completion->docStats($doc));

        if ($before !== null && $formation->getId() !== null) {
            $after = $this->snapshotOf($formation);
            $label ??= $this->describeChange($before, $after);
            if ($label !== null) {
                $this->em->persist(new FormationRevision($formation, $label, $before));
                $this->revisions->pruneOlderThan($formation, self::MAX_REVISIONS);
            }
        }

        $this->em->flush();

        $this->before[$id] = $this->snapshotOf($formation);
        $this->cache[$id] = $doc;
    }

    /**
     * Réapplique l'état d'une révision comme état courant. L'état remplacé
     * est lui-même archivé (via save()) : une restauration reste annulable.
     */
    public function restore(Formation $formation, FormationRevision $revision): void
    {
        $this->open($formation); // capture l'état courant comme « avant », pour cette restauration
        $snap = $revision->getSnapshot();

        $formation
            ->setName((string) ($snap['name'] ?? $formation->getName()))
            ->setDiplome($snap['diplome'] ?? null)
            ->setDomaine($snap['domaine'] ?? null)
            ->setComposante($snap['composante'] ?? null)
            ->setMultiParcours((bool) ($snap['multiParcours'] ?? false))
            ->setEctsTotal($snap['ectsTotal'] ?? null)
            ->setCalendarSpan($snap['calendarSpan'] ?? null)
            ->setCalendarUnit($snap['calendarUnit'] ?? null)
            ->setStructure((array) ($snap['structure'] ?? []));

        $doc = $this->hydrateRaw($formation, $snap);
        $this->save($formation, $doc, sprintf('Restauration de l’état du %s', $revision->getCreatedAt()->format('d/m/Y à H:i')));
    }

    /** Recalcule et persiste le cache de stats sans autre modification (amorçage). */
    public function refreshStats(Formation $formation): void
    {
        $formation->setStats($this->completion->docStats($this->open($formation)));
        $this->em->flush();
    }

    /** Vide le cache (après un reshape lourd, un import…). */
    public function forget(Formation $formation): void
    {
        unset($this->cache[$formation->getId() ?? spl_object_id($formation)]);
    }

    /** @return list<FormationRevision> les plus récentes d'abord */
    public function history(Formation $formation): array
    {
        return $this->revisions->findRecentFor($formation, self::MAX_REVISIONS);
    }

    /** Avant suppression définitive de la formation (sinon révisions orphelines). */
    public function deleteHistory(Formation $formation): void
    {
        $this->revisions->deleteAllFor($formation);
    }

    /**
     * Nœuds mutualisés d'autres formations, d'une clé de type donnée.
     * Chaque nœud porte sa formation d'origine (`->getFormation()`).
     *
     * @return list<TreeNode>
     */
    public function findMutualized(Formation $exclude, string $typeKey): array
    {
        $out = [];
        foreach ($this->formations->findAll() as $other) {
            if ($other === $exclude) {
                continue;
            }
            foreach ($this->open($other)->allNodes() as $node) {
                if ($node->mutualized && $node->typeKey === $typeKey) {
                    $out[] = $node;
                }
            }
        }

        return $out;
    }

    /** Nombre de nœuds d'un type donné, toutes formations confondues. */
    public function countNodesOfType(string $typeKey): int
    {
        $count = 0;
        foreach ($this->formations->findAll() as $formation) {
            foreach ($this->open($formation)->allNodes() as $node) {
                if ($node->typeKey === $typeKey) {
                    ++$count;
                }
            }
        }

        return $count;
    }

    // ─── interne ───

    /** @return array<string, \App\Entity\NodeType> */
    private function typeMap(): array
    {
        return $this->typeMap ??= $this->types->findAllIndexed();
    }

    private function hydrate(Formation $formation): MaquetteDoc
    {
        return $this->hydrateRaw($formation, $this->snapshotOf($formation));
    }

    /** @param array<string, mixed> $snap */
    private function hydrateRaw(Formation $formation, array $snap): MaquetteDoc
    {
        $types = $this->typeMap();
        $doc = new MaquetteDoc($formation, $types);
        $doc->parametres = (array) ($snap['parametres'] ?? []);
        $doc->parcours = (array) ($snap['dataParcours'] ?? []);

        $doc->roots = array_values(array_filter(array_map(
            fn (array $raw) => $this->buildNode($raw, $types),
            (array) ($snap['arbre'] ?? []),
        )));
        $doc->reindex();

        return $doc;
    }

    /** État complet et restaurable d'une formation. @return array<string, mixed> */
    private function snapshotOf(Formation $formation): array
    {
        return [
            'name' => $formation->getName(),
            'diplome' => $formation->getDiplome(),
            'domaine' => $formation->getDomaine(),
            'composante' => $formation->getComposante(),
            'multiParcours' => $formation->isMultiParcours(),
            'ectsTotal' => $formation->getEctsTotal(),
            'calendarSpan' => $formation->getCalendarSpan(),
            'calendarUnit' => $formation->getCalendarUnit(),
            'structure' => $formation->getStructure(),
            'parametres' => $formation->getParametres(),
            'dataParcours' => $formation->getDataParcours(),
            'arbre' => $formation->getArbre(),
        ];
    }

    /**
     * Description courte et automatique du changement entre 2 snapshots, pour
     * l'historique — aucun contrôleur n'a besoin de fournir de libellé.
     * `null` = rien n'a changé (pas de révision à créer).
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function describeChange(array $before, array $after): ?string
    {
        if ($before == $after) {
            return null;
        }
        if ($before['multiParcours'] !== $after['multiParcours']) {
            return $after['multiParcours'] ? 'Bascule en multi-parcours' : 'Bascule en mono-parcours';
        }

        if ($before['arbre'] !== $after['arbre']) {
            $oldIds = $this->flattenIds($before['arbre']);
            $newIds = $this->flattenIds($after['arbre']);
            $added = array_diff($newIds, $oldIds);
            $removed = array_diff($oldIds, $newIds);

            if ($removed !== [] && $added === []) {
                return 1 === \count($removed) ? 'Suppression d’un nœud' : \sprintf('Suppression de %d nœuds', \count($removed));
            }
            if ($added !== [] && $removed === []) {
                return 1 === \count($added) ? 'Ajout d’un nœud' : \sprintf('Ajout de %d nœuds', \count($added));
            }
            if ($added !== [] && $removed !== []) {
                return 'Structure réorganisée';
            }

            return 'Nœud(s) modifié(s) ou déplacé(s)';
        }
        if ($before['parametres'] !== $after['parametres']) {
            return 'Paramètres de la formation modifiés';
        }
        if ($before['dataParcours'] !== $after['dataParcours']) {
            return 'Paramètres d’un parcours modifiés';
        }
        if ($before['structure'] !== $after['structure']) {
            return 'Squelette de la formation modifié';
        }

        return 'Informations générales modifiées';
    }

    /**
     * @param list<array<string, mixed>> $nodes
     *
     * @return list<string>
     */
    private function flattenIds(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $n) {
            $out[] = (string) ($n['nid'] ?? '');
            if (!empty($n['children'])) {
                $out = array_merge($out, $this->flattenIds($n['children']));
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>                   $raw
     * @param array<string, \App\Entity\NodeType>    $types
     */
    private function buildNode(array $raw, array $types): ?TreeNode
    {
        $node = TreeNode::fromArray($raw);
        $type = $types[$node->typeKey] ?? null;
        if ($type === null) {
            return null; // type inconnu : on ignore silencieusement (proto)
        }
        $node->bindType($type);

        foreach ((array) ($raw['children'] ?? []) as $childRaw) {
            if (\is_array($childRaw)) {
                $child = $this->buildNode($childRaw, $types);
                if ($child !== null) {
                    $child->parent = $node;
                    $node->children[] = $child;
                }
            }
        }

        return $node;
    }
}
