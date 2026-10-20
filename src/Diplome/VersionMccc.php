<?php

declare(strict_types=1);

namespace App\Diplome;

use App\Diplome\Spec\VersionConfig;
use App\Entity\MccType;
use App\Maquette\Doc\TreeNode;
use App\Repository\MccTypeRepository;

/**
 * MCCC d'un nœud d'après la version du type de diplôme de sa formation :
 * types autorisés et profil de règles selon la nature de l'élément
 * (attribut `natureElement`, à défaut la première nature déclarée par le type).
 *
 * Sans version (formation sans type de diplôme), `config()` renvoie null et
 * l'appelant garde le comportement par template.
 */
final class VersionMccc
{
    public function __construct(
        private readonly TypeDiplomeResolver $resolver,
        private readonly MccTypeRepository $types,
    ) {
    }

    public function config(TreeNode $node): ?VersionConfig
    {
        $formation = $node->getFormation();

        return null === $formation ? null : $this->resolver->configFor($formation);
    }

    public function natureOf(TreeNode $node, VersionConfig $config): ?string
    {
        $nature = $node->getAttribute('natureElement');
        if (\is_string($nature) && '' !== $nature) {
            return $nature;
        }

        return $config->structure->natures[0] ?? array_key_first($config->mccc);
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
