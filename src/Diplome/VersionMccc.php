<?php

declare(strict_types=1);

namespace App\Diplome;

use App\Diplome\Spec\VersionConfig;
use App\Entity\Formation;
use App\Entity\MccType;
use App\Entity\TypeDiplomeVersion;
use App\Maquette\Doc\TreeNode;
use App\Repository\MccTypeRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * MCCC d'un nœud d'après la version du type de diplôme de sa formation :
 * types autorisés et profil de règles selon la nature de l'élément
 * (attribut `natureElement`, à défaut la première nature déclarée par le type).
 *
 * Sans version (formation sans type de diplôme), `config()` renvoie null et
 * aucune règle n'est appliquée.
 */
final class VersionMccc implements ResetInterface
{
    /** @var \WeakMap<Formation, array{0: ?TypeDiplomeVersion}> version résolue par formation (évite de la recalculer à chaque nœud affiché) */
    private \WeakMap $versions;

    public function __construct(
        private readonly TypeDiplomeResolver $resolver,
        private readonly MccTypeRepository $types,
    ) {
        $this->versions = new \WeakMap();
    }

    public function config(TreeNode $node): ?VersionConfig
    {
        $formation = $node->getFormation();

        if (null === $formation) {
            return null;
        }
        $this->versions[$formation] ??= [$this->resolver->versionFor($formation)];

        return $this->versions[$formation][0]?->config();
    }

    public function reset(): void
    {
        $this->versions = new \WeakMap();
    }

    public function natureOf(TreeNode $node, VersionConfig $config): ?string
    {
        return $config->natureOf($node);
    }

    /** @return list<MccType> types proposés pour ce nœud, ou null sans version */
    public function typesFor(TreeNode $node): ?array
    {
        $config = $this->config($node);
        if (null === $config) {
            return null;
        }
        $nature = $this->natureOf($node, $config);
        $allowed = array_flip($config->mccc[(string) $nature]['types'] ?? []);

        return array_values(array_filter(
            $this->types->allOrdered(),
            static fn (MccType $t): bool => isset($allowed[$t->getKey()]),
        ));
    }

    /**
     * @return array{0: bool, 1: ?string} [le nœud a une version, clé du profil à appliquer]
     */
    public function profileFor(TreeNode $node): array
    {
        $config = $this->config($node);
        if (null === $config) {
            return [false, null];
        }

        return [true, $config->mccc[(string) $this->natureOf($node, $config)]['profile'] ?? null];
    }
}
