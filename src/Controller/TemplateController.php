<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Formation;
use App\Entity\StructureTemplate;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Maquette\TemplateTree;
use App\Repository\MccTypeRepository;
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
    public function edit(StructureTemplate $template, NodeTypeRepository $types, MccTypeRepository $mccTypes): Response
    {
        return $this->render('template/edit.html.twig', [
            'template' => $template,
            'types' => $types->forTree(),
            'mccTypes' => $mccTypes->allOrdered(),
        ]);
    }

    #[Route('/administration/templates/{id}/meta', name: 'template_meta', methods: ['POST'])]
    public function meta(StructureTemplate $template, Request $request): Response
    {
        $span = $request->request->get('calendarSpan');
        $template
            ->setLabel(trim((string) $request->request->get('label')) ?: $template->getLabel())
            ->setDescription(trim((string) $request->request->get('description')) ?: null)
            ->setDiplome($request->request->get('diplome') ?: null)
            ->setAvecParcours($request->request->getBoolean('avecParcours'))
            ->setCalendarUnit($request->request->get('calendarUnit'))
            ->setCalendarSpan('' !== $span && null !== $span ? (int) $span : null);
        $this->em->flush();
        $this->addFlash('success', 'Template mis à jour.');

        return $this->redirectToRoute('template_edit', ['id' => $template->getId()]);
    }

    /** Tableau structurel : ajoute un type en fin de chaîne (avant tout maillon déjà figé en queue par l'admin). */
    #[Route('/administration/templates/{id}/structure/ajouter', name: 'template_structure_add', methods: ['POST'])]
    public function structureAdd(StructureTemplate $template, Request $request, NodeTypeRepository $types): Response
    {
        $typeKey = trim((string) $request->request->get('type'));
        $chain = $template->getStructure();

        if ('' !== $typeKey && null !== $types->findOneByKey($typeKey) && !\in_array($typeKey, $chain, true)) {
            if ([] !== $chain) {
                array_splice($chain, \count($chain) - 1, 0, [$typeKey]);
            } else {
                $chain[] = $typeKey;
            }
            $template->setStructure($chain);
            $this->em->flush();
        }

        return $this->redirectToRoute('template_edit', ['id' => $template->getId()]);
    }

    #[Route('/administration/templates/{id}/structure/{key}/retirer', name: 'template_structure_remove', methods: ['POST'])]
    public function structureRemove(StructureTemplate $template, string $key): Response
    {
        $template->setStructure(array_values(array_filter(
            $template->getStructure(),
            static fn (string $k) => $k !== $key,
        )));
        $this->em->flush();

        return $this->redirectToRoute('template_edit', ['id' => $template->getId()]);
    }

    #[Route('/administration/templates/{id}/structure', name: 'template_structure_save', methods: ['POST'])]
    public function structureSave(StructureTemplate $template, Request $request): Response
    {
        // Le dernier maillon de la chaîne est fixe (non draggable, cf. templateStructureRows()) : le
        // formulaire de réordonnancement ne soumet donc que le préfixe déplaçable, il faut le lui rajouter.
        $chain = array_map('strval', (array) $request->request->all('chain'));
        $tail = $template->getStructure();
        $lastLink = [] !== $tail ? [array_pop($tail)] : [];
        $template->setStructure([...$chain, ...$lastLink]);
        $this->em->flush();

        if ($request->isXmlHttpRequest()) {
            return new Response(null, Response::HTTP_NO_CONTENT);
        }

        return $this->redirectToRoute('template_edit', ['id' => $template->getId()]);
    }

    /** Surcharge des capacités d'un type de nœud, spécifique à ce template (indépendant des profils MCC). */
    #[Route('/administration/templates/{id}/champs/{typeKey}', name: 'template_field_overrides', methods: ['POST'])]
    public function fieldOverrides(StructureTemplate $template, string $typeKey, Request $request, NodeTypeRepository $types): Response
    {
        $type = $types->findOneByKey($typeKey);
        if (null === $type) {
            $this->addFlash('warning', 'Type d’ELP inconnu.');

            return $this->redirectToRoute('template_edit', ['id' => $template->getId()]);
        }

        $checked = array_flip($request->request->all('capabilities'));
        $overrides = [];
        foreach (array_keys($type->getCapabilities()) as $cap) {
            if ($type->isCapabilityLocked($cap)) {
                continue; // verrouillé globalement par le type : pas négociable au niveau d'un template
            }
            $overrides[$cap] = isset($checked[$cap]);
        }
        $template->setFieldOverridesFor($typeKey, $overrides);
        $this->em->flush();
        $this->addFlash('success', sprintf('Champs de « %s » mis à jour pour ce template.', $type->getLabel()));

        return $this->redirectToRoute('template_edit', ['id' => $template->getId()]);
    }

    /**
     * Types de MCCC retenus pour ce diplôme + profil (règle) choisi pour chacun —
     * plusieurs types à la fois (ex. CC et CT pour le même diplôme), la règle se
     * choisit ici, pas besoin d'aller dans l'éditeur de type de MCCC pour ça.
     */
    #[Route('/administration/templates/{id}/mccc', name: 'template_mccc_add', methods: ['POST'])]
    public function mcccAdd(StructureTemplate $template, Request $request, MccTypeRepository $mccTypes): Response
    {
        $selectedKeys = array_map('strval', (array) $request->request->all('types'));
        $profileChoices = (array) $request->request->all('profile');

        $profiles = $template->getMcccProfiles();
        foreach ($selectedKeys as $typeKey) {
            $type = $mccTypes->findOneByKey($typeKey);
            if (null === $type) {
                continue;
            }
            $chosen = (string) ($profileChoices[$typeKey] ?? '');
            $profiles[$typeKey] = null !== $type->getProfile($chosen) ? $chosen : ($type->getProfiles()[0]['key'] ?? '');
        }
        $template->setMcccProfiles($profiles);
        $this->em->flush();

        return $this->redirectToRoute('template_edit', ['id' => $template->getId()]);
    }

    #[Route('/administration/templates/{id}/mccc/{typeKey}/profil', name: 'template_mccc_profile', methods: ['POST'])]
    public function mcccProfile(StructureTemplate $template, string $typeKey, Request $request): Response
    {
        $profiles = $template->getMcccProfiles();
        if (\array_key_exists($typeKey, $profiles)) {
            $profiles[$typeKey] = (string) $request->request->get('profile', '');
            $template->setMcccProfiles($profiles);
            $this->em->flush();
        }

        return $this->redirectToRoute('template_edit', ['id' => $template->getId()]);
    }

    #[Route('/administration/templates/{id}/mccc/{typeKey}/retirer', name: 'template_mccc_remove', methods: ['POST'])]
    public function mcccRemove(StructureTemplate $template, string $typeKey): Response
    {
        $profiles = $template->getMcccProfiles();
        unset($profiles[$typeKey]);
        $template->setMcccProfiles($profiles);
        $this->em->flush();

        return $this->redirectToRoute('template_edit', ['id' => $template->getId()]);
    }

    #[Route('/administration/templates/{id}/nodes', name: 'template_node_add', methods: ['POST'])]
    public function nodeAdd(StructureTemplate $template, Request $request, NodeTypeRepository $types): Response
    {
        $type = $types->findOneByKey((string) $request->request->get('type'));
        if ($type === null) {
            $this->addFlash('warning', 'Type d’ELP inconnu.');

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

    #[Route('/administration/templates/{id}/nodes/{path}/{op}', name: 'template_node_op', methods: ['POST'], requirements: ['path' => '[0-9.]+', 'op' => 'up|down|toggle-rigid|toggle-move|delete|duplicate|rename'])]
    public function nodeOp(StructureTemplate $template, string $path, string $op, Request $request): Response
    {
        $tree = new TemplateTree($template->getTree());
        $p = TemplateTree::path($path);

        match ($op) {
            'up' => $tree->move($p, -1),
            'down' => $tree->move($p, 1),
            'toggle-rigid' => $tree->toggleRigid($p),
            'toggle-move' => $tree->toggleMove($p),
            'delete' => $tree->remove($p),
            'duplicate' => $tree->duplicate($p),
            'rename' => $tree->rename($p, trim((string) $request->request->get('label'))),
        };

        $template->setTree($tree->tree);
        $this->em->flush();

        return $this->redirectToRoute('template_edit', ['id' => $template->getId()]);
    }

    #[Route('/administration/templates/{id}/nodes/reorder', name: 'template_node_reorder', methods: ['POST'])]
    public function nodeReorder(StructureTemplate $template, Request $request): Response
    {
        $tree = new TemplateTree($template->getTree());
        $parentPath = TemplateTree::path((string) $request->request->get('parentPath', ''));
        $order = array_map('intval', (array) $request->request->all('order'));

        $tree->reorder($parentPath, $order);
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
            ->setAvecParcours($formation->isAvecParcours())
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
