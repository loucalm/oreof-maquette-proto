<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Formation;
use App\Entity\StructureTemplate;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Maquette\TemplateTree;
use App\Repository\NodeTypeRepository;
use App\Repository\StructureTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Templates de structure : l'admin construit ici l'arbre imposé d'un diplôme
 * (éditeur visuel — ajout / suppression / réordonnancement / verrou par nœud).
 * À la création d'une formation, le template du diplôme est appliqué et ses
 * nœuds verrouillés figent la structure côté responsable de formation.
 */
final class TemplateController extends AbstractController
{
    public function __construct(
        private readonly Maquette $maquette,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/administration/templates', name: 'template_index', methods: ['GET'])]
    public function index(StructureTemplateRepository $repo): Response
    {
        return $this->render('template/index.html.twig', ['templates' => $repo->findAllOrdered()]);
    }

    #[Route('/administration/templates/new', name: 'template_new', methods: ['POST'])]
    public function new(Request $request, StructureTemplateRepository $repo): Response
    {
        $label = trim((string) $request->request->get('label')) ?: 'Nouveau template';
        $key = (new AsciiSlugger())->slug($label)->lower()->toString();
        if ($repo->findOneByKey($key)) {
            $key .= '-'.substr(uniqid(), -4);
        }
        $template = new StructureTemplate($key, $label);
        $this->em->persist($template);
        $this->em->flush();

        return $this->redirectToRoute('template_edit', ['id' => $template->getId()]);
    }

    #[Route('/administration/templates/{id}', name: 'template_edit', methods: ['GET'])]
    public function edit(StructureTemplate $template, NodeTypeRepository $types): Response
    {
        return $this->render('template/edit.html.twig', [
            'template' => $template,
            'types' => $types->findAllOrdered(),
        ]);
    }

    #[Route('/administration/templates/{id}/meta', name: 'template_meta', methods: ['POST'])]
    public function meta(StructureTemplate $template, Request $request): Response
    {
        $template
            ->setLabel(trim((string) $request->request->get('label')) ?: $template->getLabel())
            ->setDescription(trim((string) $request->request->get('description')) ?: null)
            ->setDiplome($request->request->get('diplome') ?: null)
            ->setMultiParcours($request->request->getBoolean('multiParcours'));
        $this->em->flush();
        $this->addFlash('success', 'Template mis à jour.');

        return $this->redirectToRoute('template_edit', ['id' => $template->getId()]);
    }

    #[Route('/administration/templates/{id}/nodes', name: 'template_node_add', methods: ['POST'])]
    public function nodeAdd(StructureTemplate $template, Request $request, NodeTypeRepository $types): Response
    {
        $type = $types->findOneByKey((string) $request->request->get('type'));
        if ($type === null) {
            $this->addFlash('warning', 'Type de nœud inconnu.');

            return $this->redirectToRoute('template_edit', ['id' => $template->getId()]);
        }

        $tree = new TemplateTree($template->getTree());
        $tree->addChild(
            TemplateTree::path((string) $request->request->get('parent')),
            $type->getKey(),
            trim((string) $request->request->get('label')) ?: $type->getLabel(),
        );
        $template->setTree($tree->tree);
        $this->em->flush();

        return $this->redirectToRoute('template_edit', ['id' => $template->getId()]);
    }

    #[Route('/administration/templates/{id}/nodes/{path}/{op}', name: 'template_node_op', methods: ['POST'], requirements: ['path' => '[0-9.]+', 'op' => 'up|down|lock|delete|rename'])]
    public function nodeOp(StructureTemplate $template, string $path, string $op, Request $request): Response
    {
        $tree = new TemplateTree($template->getTree());
        $p = TemplateTree::path($path);

        match ($op) {
            'up' => $tree->move($p, -1),
            'down' => $tree->move($p, 1),
            'lock' => $tree->toggleLock($p),
            'delete' => $tree->remove($p),
            'rename' => $tree->rename($p, trim((string) $request->request->get('label'))),
        };

        $template->setTree($tree->tree);
        $this->em->flush();

        return $this->redirectToRoute('template_edit', ['id' => $template->getId()]);
    }

    #[Route('/administration/templates/{id}/delete', name: 'template_delete', methods: ['POST'])]
    public function delete(StructureTemplate $template): Response
    {
        $this->em->remove($template);
        $this->em->flush();
        $this->addFlash('info', 'Template supprimé.');

        return $this->redirectToRoute('template_index');
    }

    /**
     * Enregistre la structure actuelle d'une formation comme nouveau template.
     */
    #[Route('/formations/{id}/save-as-template', name: 'template_from_formation', methods: ['POST'])]
    public function fromFormation(Formation $formation, Request $request, StructureTemplateRepository $repo): Response
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

        $this->em->persist($template);
        $this->em->flush();
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
            $entry = [
                'type' => $node->getType()->getKey(),
                'label' => $node->getLabel(),
            ];
            if ($node->getCode() !== null) {
                $entry['code'] = $node->getCode();
            }
            if ($node->getAttributes() !== []) {
                $entry['attributes'] = $node->getAttributes();
            }
            if ($node->getLocked() !== []) {
                $entry['locked'] = $node->getLocked();
            }
            $children = $this->serialize($node->getChildren());
            if ($children !== []) {
                $entry['children'] = $children;
            }
            $out[] = $entry;
        }

        return $out;
    }
}
