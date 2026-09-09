<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\NodeType;
use App\Enum\NodeFamily;
use App\Maquette\AttributeCatalog;
use App\Repository\NodeTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Admin des types de nœuds — c'est ici que « rien n'est figé » : le responsable
 * crée les types et leurs capacités. La hiérarchie est propre à chaque
 * formation (Formation::structure), pas au type.
 */
final class NodeTypeController extends AbstractController
{
    public function __construct(private readonly AttributeCatalog $catalog)
    {
    }

    #[Route('/node-types', name: 'node_type_index', methods: ['GET'])]
    public function index(NodeTypeRepository $repo): Response
    {
        return $this->render('node_type/index.html.twig', [
            'types' => $repo->findAllOrdered(),
            'capabilities' => $this->capabilityLabels(),
        ]);
    }

    #[Route('/node-types/new', name: 'node_type_new', methods: ['GET', 'POST'])]
    #[Route('/node-types/{id}/edit', name: 'node_type_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, EntityManagerInterface $em, ?NodeType $nodeType = null): Response
    {
        $isNew = $nodeType === null;

        if ($request->isMethod('POST')) {
            $label = trim((string) $request->request->get('label')) ?: 'Type';
            $key = trim((string) $request->request->get('key'))
                ?: (new AsciiSlugger())->slug($label)->lower()->toString();

            if ($isNew) {
                $nodeType = new NodeType($key, $label);
                $em->persist($nodeType);
            } else {
                $nodeType->setKey($key)->setLabel($label);
            }

            $nodeType
                ->setIcon(trim((string) $request->request->get('icon')) ?: null)
                ->setFamily(NodeFamily::from((string) $request->request->get('family', 'structural')))
                ->setPosition($request->request->getInt('position'))
                ->setEctsTarget($request->request->get('ectsTarget') !== '' ? $request->request->getInt('ectsTarget') : null)
                ->setCapabilities(array_fill_keys($request->request->all('capabilities'), true))
                ->setLockedCapabilities($request->request->all('lockedCapabilities'));

            $em->flush();
            $this->addFlash('success', 'Type enregistré.');

            return $this->redirectToRoute('node_type_index');
        }

        return $this->render('node_type/edit.html.twig', [
            'nodeType' => $nodeType,
            'isNew' => $isNew,
            'families' => NodeFamily::cases(),
            'capabilities' => $this->capabilityLabels(),
        ]);
    }

    #[Route('/node-types/{id}/delete', name: 'node_type_delete', methods: ['POST'])]
    public function delete(NodeType $nodeType, EntityManagerInterface $em): Response
    {
        $used = (int) $em->createQuery('SELECT COUNT(n.id) FROM App\Entity\Node n WHERE n.type = :t')
            ->setParameter('t', $nodeType)
            ->getSingleScalarResult();
        if ($used > 0) {
            $this->addFlash('warning', sprintf(
                'Impossible de supprimer « %s » : %d nœud(s) l’utilisent encore.',
                $nodeType->getLabel(),
                $used,
            ));

            return $this->redirectToRoute('node_type_index');
        }

        $em->remove($nodeType);
        $em->flush();
        $this->addFlash('info', 'Type supprimé.');

        return $this->redirectToRoute('node_type_index');
    }

    /** @return array<string, string> */
    private function capabilityLabels(): array
    {
        $out = [];
        foreach ($this->catalog->all() as $key => $def) {
            $out[$key] = $def['label'];
        }
        foreach (AttributeCatalog::FLAGS as $key => $label) {
            $out[$key] = $label;
        }

        return $out;
    }
}
