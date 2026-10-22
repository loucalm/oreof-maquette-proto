<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NodeType;
use App\Enum\NodeFamily;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @extends ServiceEntityRepository<NodeType>
 */
class NodeTypeRepository extends ServiceEntityRepository implements ResetInterface
{
    /** @var array<string, NodeType>|null mémo par requête : le métamodèle est relu à chaque nœud affiché */
    private ?array $indexed = null;

    /** @var list<NodeType>|null */
    private ?array $ordered = null;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NodeType::class);
    }

    public function findOneByKey(string $key): ?NodeType
    {
        return $this->findAllIndexed()[$key] ?? null;
    }

    /** @return list<NodeType> */
    public function findAllOrdered(): array
    {
        return $this->ordered ??= $this->createQueryBuilder('t')
            ->orderBy('t.position', 'ASC')
            ->addOrderBy('t.label', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Indexé par clé.
     *
     * @return array<string, NodeType>
     */
    public function findAllIndexed(): array
    {
        if (null === $this->indexed) {
            $this->indexed = [];
            foreach ($this->findAll() as $type) {
                $this->indexed[$type->getKey()] = $type;
            }
        }

        return $this->indexed;
    }

    /** Le type qui joue le rôle d'EC (réglage « niveau élément »), ou null s'il n'est pas désigné. */
    public function findElementLevel(): ?NodeType
    {
        foreach ($this->findAllOrdered() as $type) {
            if ($type->isElementLevel()) {
                return $type;
            }
        }

        return null;
    }

    public function reset(): void
    {
        $this->indexed = null;
        $this->ordered = null;
    }

    /**
     * Types utilisables dans un arbre pédagogique (squelette de formation ou de
     * template) : exclut la famille `Parameter` (sections fixes Organisation/
     * Présentation/Configuration/BCC, jamais des nœuds d'arbre) et la famille
     * `Competence` (arbre BCC parallèle — ses nœuds se créent depuis l'éditeur
     * BCC, jamais depuis le squelette pédagogique).
     *
     * @return list<NodeType>
     */
    public function forTree(): array
    {
        return array_values(array_filter(
            $this->findAllOrdered(),
            static fn (NodeType $t) => !\in_array($t->getFamily(), [NodeFamily::Parameter, NodeFamily::Competence], true),
        ));
    }

    /** Nombre d'autres types qui portent encore cette capacité (pour savoir si un FieldDef partagé peut être supprimé). */
    public function countUsingCapability(string $key, ?string $excludeTypeKey = null): int
    {
        $count = 0;
        foreach ($this->findAll() as $type) {
            if ($type->getKey() === $excludeTypeKey) {
                continue;
            }
            if ($type->hasCapability($key)) {
                ++$count;
            }
        }

        return $count;
    }
}
