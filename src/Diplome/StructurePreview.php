<?php

declare(strict_types=1);

namespace App\Diplome;

use App\Diplome\Spec\StructureSpec;
use App\Diplome\Spec\VersionConfig;
use App\Entity\Formation;
use App\Entity\TypeDiplome;
use App\Entity\TypeDiplomeVersion;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Repository\NodeTypeRepository;

/**
 * Aperçu de la maquette minimale d'une configuration de structure : la plus
 * petite durée autorisée, sans parcours. Tout reste en mémoire (formation et
 * version jamais persistées, document jamais enregistré).
 */
final class StructurePreview
{
    public function __construct(
        private readonly NodeTypeRepository $nodeTypes,
        private readonly StructureInstantiator $instantiator,
    ) {
    }

    /**
     * @return list<TreeNode> racines de l'arbre pédagogique
     *
     * @throws \InvalidArgumentException si la structure ne permet pas d'instancier une maquette
     */
    public function roots(StructureSpec $structure): array
    {
        $version = (new TypeDiplomeVersion(new TypeDiplome('apercu', 'Aperçu', 'APERCU'), '0000-0001'))
            ->setConfig(new VersionConfig($structure, null, [], [], ['quitus' => false, 'assiduite' => false, 'sansNote' => false], [], 0));
        $doc = new MaquetteDoc(new Formation('Aperçu'), $this->nodeTypes->findAllIndexed());
        $this->instantiator->instantiate($doc, $version, max(1, $structure->duration?->bound->min ?? 1), false);

        return $doc->pedagogicalRoots();
    }
}
