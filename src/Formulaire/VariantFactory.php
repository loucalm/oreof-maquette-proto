<?php

declare(strict_types=1);

namespace App\Formulaire;

use App\Entity\NodeType;
use App\Repository\NodeTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/** Crée une variante d'un formulaire : même famille, portée et icône, aucun écart au départ. */
final class VariantFactory
{
    public function __construct(
        private readonly NodeTypeRepository $types,
        private readonly FormResolver $forms,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** La variante est persistée, pas enregistrée (flush à la charge de l'appelant). */
    public function create(NodeType $parent, ?string $label = null): NodeType
    {
        $label = null !== $label && '' !== trim($label) ? trim($label) : $parent->getLabel().' · variante';
        $base = substr($parent->getKey().'_'.((new AsciiSlugger())->slug($label, '_')->lower()->toString() ?: 'variante'), 0, 56);
        $key = $base;
        for ($i = 2; null !== $this->types->findOneByKey($key); ++$i) {
            $key = $base.'_'.$i;
        }

        $variant = (new NodeType($key, $label))
            ->setFamily($parent->getFamily())
            ->setScope($parent->getScope())
            ->setIcon($parent->getIcon())
            ->setPosition($parent->getPosition())
            ->setParent($parent);
        $this->forms->acknowledgeParentFields($variant);
        $this->em->persist($variant);
        $this->types->reset();

        return $variant;
    }
}
