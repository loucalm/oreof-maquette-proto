<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Formation;
use App\Entity\StructureTemplate;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Repository\StructureTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TemplateController extends AbstractController
{
    public function __construct(private readonly Maquette $maquette)
    {
    }

    #[Route('/templates', name: 'template_index', methods: ['GET'])]
    public function index(StructureTemplateRepository $repo): Response
    {
        return $this->render('template/index.html.twig', ['templates' => $repo->findAllOrdered()]);
    }

    #[Route('/templates/{id}', name: 'template_show', methods: ['GET'])]
    public function show(StructureTemplate $template): Response
    {
        return $this->render('template/show.html.twig', ['template' => $template]);
    }

    /**
     * Enregistre la structure actuelle d'une formation comme nouveau template.
     * (« une base réutilisable » — le responsable capitalise ce qu'il a construit.)
     */
    #[Route('/formations/{id}/save-as-template', name: 'template_from_formation', methods: ['POST'])]
    public function fromFormation(Formation $formation, Request $request, EntityManagerInterface $em, StructureTemplateRepository $repo): Response
    {
        $label = trim((string) $request->request->get('label')) ?: $formation->getName();
        $key = trim((string) $request->request->get('key')) ?: 'tpl-'.uniqid();
        if ($repo->findOneByKey($key)) {
            $key .= '-'.uniqid();
        }

        $template = (new StructureTemplate($key, $label))
            ->setDescription('Créé depuis la formation « '.$formation->getName().' ».')
            ->setMultiParcours($formation->isMultiParcours())
            ->setTree($this->serialize($this->maquette->open($formation)->pedagogicalRoots()));

        $em->persist($template);
        $em->flush();
        $this->addFlash('success', sprintf('Template « %s » créé à partir de cette structure.', $label));

        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'param' => 'structure']);
    }

    /**
     * @param iterable<TreeNode> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private function serialize(iterable $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $out[] = [
                'type' => $node->getType()->getKey(),
                'label' => $node->getLabel(),
                'code' => $node->getCode(),
                'attributes' => $node->getAttributes(),
                'children' => $this->serialize($node->getChildren()),
            ];
        }

        return $out;
    }
}
