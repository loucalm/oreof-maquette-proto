<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Entity\Formation;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Repository\FormationRepository;
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
 */
final class Maquette
{
    /** @var array<int, MaquetteDoc> cache par requête, fid → doc */
    private array $cache = [];

    /** @var array<string, \App\Entity\NodeType>|null */
    private ?array $typeMap = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NodeTypeRepository $types,
        private readonly FormationRepository $formations,
    ) {
    }

    public function open(Formation $formation): MaquetteDoc
    {
        $id = $formation->getId() ?? spl_object_id($formation);

        return $this->cache[$id] ??= $this->hydrate($formation);
    }

    public function save(Formation $formation, MaquetteDoc $doc): void
    {
        $doc->reindex();
        $formation->setArbre($doc->dumpTree());
        $formation->setDataParcours($doc->parcours);
        $formation->setParametres($doc->parametres);
        $this->em->flush();

        $id = $formation->getId() ?? spl_object_id($formation);
        $this->cache[$id] = $doc;
    }

    /** Vide le cache (après un reshape lourd, un import…). */
    public function forget(Formation $formation): void
    {
        unset($this->cache[$formation->getId() ?? spl_object_id($formation)]);
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

    // ─── interne ───

    /** @return array<string, \App\Entity\NodeType> */
    private function typeMap(): array
    {
        return $this->typeMap ??= $this->types->findAllIndexed();
    }

    private function hydrate(Formation $formation): MaquetteDoc
    {
        $types = $this->typeMap();
        $doc = new MaquetteDoc($formation, $types);
        $doc->parametres = $formation->getParametres();
        $doc->parcours = $formation->getDataParcours();

        $doc->roots = array_values(array_filter(array_map(
            fn (array $raw) => $this->buildNode($raw, $types),
            $formation->getArbre(),
        )));
        $doc->reindex();

        return $doc;
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
