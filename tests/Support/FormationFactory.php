<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Formation;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Maquette;
use App\Maquette\TemplateApplier;
use App\Repository\StructureTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Crée une formation persistée à partir d'un template des fixtures (licence_lmd, master_parcours, but). */
final class FormationFactory
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Maquette $maquette,
        private readonly StructureTemplateRepository $templates,
        private readonly TemplateApplier $applier,
    ) {
    }

    public static function create(\Psr\Container\ContainerInterface $container): self
    {
        return new self(
            $container->get('doctrine')->getManager(),
            $container->get(Maquette::class),
            $container->get(StructureTemplateRepository::class),
            $container->get(TemplateApplier::class),
        );
    }

    public function fromTemplate(string $templateKey, string $name = 'Formation de test'): Formation
    {
        $template = $this->templates->findOneByKey($templateKey) ?? throw new \LogicException("Template « $templateKey » absent des fixtures.");

        $formation = new Formation($name);
        $formation->setDiplome($template->getDiplome());
        $this->em->persist($formation);
        $this->em->flush();

        $doc = $this->maquette->open($formation);
        $this->applier->apply($doc, $template);
        $this->maquette->save($formation, $doc, 'Création');

        return $formation;
    }

    /** Premier nœud du type donné (ordre de l'arbre). */
    public function first(MaquetteDoc $doc, string $typeKey): \App\Maquette\Doc\TreeNode
    {
        foreach ($doc->allNodes() as $node) {
            if ($node->typeKey === $typeKey) {
                return $node;
            }
        }

        throw new \LogicException("Aucun nœud de type « $typeKey ».");
    }
}
