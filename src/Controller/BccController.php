<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Formation;
use App\Entity\Node;
use App\Maquette\NodeFactory;
use App\Repository\NodeRepository;
use App\Repository\NodeTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Référentiel de compétences (BCC) : un arbre parallèle à la structure
 * pédagogique — des blocs de compétences, chacun contenant des compétences,
 * plus un bloc « compétences transversales (RNCP) » optionnel.
 *
 * Contexte : la formation en mono-parcours (blocs = nœuds racine), un nœud
 * parcours en multi-parcours (blocs = enfants du parcours). La hiérarchie est
 * fixe (bloc → compétence) : pas besoin du squelette, d'où un contrôleur dédié.
 */
final class BccController extends AbstractController
{
    private const BLOC = 'bloc_competences';
    private const COMPETENCE = 'competence';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NodeRepository $nodes,
        private readonly NodeFactory $factory,
        private readonly NodeTypeRepository $types,
    ) {
    }

    #[Route('/formations/{id}/bcc', name: 'bcc_editor', methods: ['GET'])]
    public function editor(Request $request, Formation $formation): Response
    {
        return $this->renderEditor($request, $formation, null);
    }

    #[Route('/parcours/{id}/bcc', name: 'bcc_parcours_editor', methods: ['GET'])]
    public function parcoursEditor(Request $request, Node $node): Response
    {
        if (!$node->isParcours()) {
            throw $this->createNotFoundException();
        }

        return $this->renderEditor($request, $node->getFormation(), $node);
    }

    #[Route('/formations/{id}/bcc/blocs', name: 'bcc_bloc_add', methods: ['POST'])]
    public function addBloc(Formation $formation, Request $request): Response
    {
        return $this->doAddBloc($request, $formation, null);
    }

    #[Route('/parcours/{id}/bcc/blocs', name: 'bcc_parcours_bloc_add', methods: ['POST'])]
    public function addParcoursBloc(Node $node, Request $request): Response
    {
        if (!$node->isParcours()) {
            throw $this->createNotFoundException();
        }

        return $this->doAddBloc($request, $node->getFormation(), $node);
    }

    #[Route('/nodes/{id}/bcc/competences', name: 'bcc_competence_add', methods: ['POST'])]
    public function addCompetence(Node $node, Request $request): Response
    {
        if (!$node->isBloc()) {
            throw $this->createNotFoundException();
        }

        $type = $this->types->findOneByKey(self::COMPETENCE);
        if ($type === null) {
            $this->addFlash('danger', 'Type « compétence » introuvable.');

            return $this->ownerRedirect($node);
        }

        $label = trim((string) $request->request->get('label')) ?: 'Nouvelle compétence';
        $this->factory->create($node->getFormation(), $type, $node, $label);
        $this->em->flush();
        $this->addFlash('success', 'Compétence ajoutée.');

        return $this->ownerRedirect($node);
    }

    #[Route('/nodes/{id}/bcc', name: 'bcc_node_save', methods: ['POST'])]
    public function save(Node $node, Request $request): Response
    {
        if (!$node->isCompetenceNode()) {
            throw $this->createNotFoundException();
        }

        $node->setLabel(trim((string) $request->request->get('label')) ?: $node->getDisplayLabel());
        $node->setCode(trim((string) $request->request->get('code')) ?: null);
        $node->setAttribute('description', trim((string) $request->request->get('description')));

        $this->em->flush();
        $this->addFlash('success', 'Enregistré.');

        return $this->ownerRedirect($node);
    }

    #[Route('/nodes/{id}/bcc/delete', name: 'bcc_node_delete', methods: ['POST'])]
    public function delete(Node $node): Response
    {
        if (!$node->isCompetenceNode()) {
            throw $this->createNotFoundException();
        }
        $redirect = $this->ownerRedirect($node);
        $isBloc = $node->isBloc();
        $this->em->remove($node);
        $this->em->flush();
        $this->addFlash('info', $isBloc ? 'Bloc supprimé.' : 'Compétence supprimée.');

        return $redirect;
    }

    #[Route('/nodes/{id}/bcc/move', name: 'bcc_move', methods: ['POST'])]
    public function move(Node $node, Request $request): JsonResponse
    {
        if (!$node->isCompetenceNode()) {
            return new JsonResponse(['ok' => false, 'error' => 'type'], 422);
        }

        $payload = json_decode($request->getContent() ?: '{}', true) ?: [];
        $newParent = !empty($payload['parentId']) ? $this->nodes->find((int) $payload['parentId']) : null;
        $index = max(0, (int) ($payload['index'] ?? 0));

        // règles fixes du BCC : un bloc reste au niveau des blocs, une compétence va dans un bloc
        if ($node->isBloc()) {
            if ($newParent !== null) {
                return new JsonResponse(['ok' => false, 'error' => 'hierarchy'], 422);
            }
            if ($node->isTransversalBloc()) {
                return new JsonResponse(['ok' => true]); // épinglé en tête
            }
            $siblings = array_values(array_filter(
                $this->siblingBlocs($node),
                static fn (Node $b) => !$b->isTransversalBloc(),
            ));
        } else {
            if ($newParent === null || !$newParent->isBloc() || $newParent->getFormation() !== $node->getFormation()) {
                return new JsonResponse(['ok' => false, 'error' => 'hierarchy'], 422);
            }
            $oldParent = $node->getParent();
            $node->setParent($newParent);
            $newParent->addChild($node);
            $siblings = $this->competenceChildren($newParent, $node);
        }

        array_splice($siblings, min($index, \count($siblings)), 0, [$node]);
        foreach ($siblings as $i => $s) {
            $s->setPosition($i);
        }

        if (isset($oldParent) && $oldParent !== null && $oldParent !== $newParent) {
            foreach ($this->competenceChildren($oldParent, $node) as $i => $s) {
                $s->setPosition($i);
            }
        }

        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    // ─── helpers ────────────────────────────────────────────────

    private function renderEditor(Request $request, Formation $formation, ?Node $parcours): Response
    {
        return $this->render('formation/bcc.html.twig', [
            'formation' => $formation,
            'parcours' => $parcours,
            'blocs' => $this->buildTree($this->blocsOf($formation, $parcours)),
            'hasTransversal' => $this->hasTransversal($formation, $parcours),
            'addBlocUrl' => $parcours
                ? $this->generateUrl('bcc_parcours_bloc_add', ['id' => $parcours->getId()])
                : $this->generateUrl('bcc_bloc_add', ['id' => $formation->getId()]),
            'standalone' => 'node-panel' !== $request->headers->get('Turbo-Frame'),
        ]);
    }

    private function doAddBloc(Request $request, Formation $formation, ?Node $parcours): Response
    {
        $type = $this->types->findOneByKey(self::BLOC);
        if ($type === null) {
            $this->addFlash('danger', 'Type « bloc de compétences » introuvable.');

            return $this->contextRedirect($formation, $parcours);
        }

        $transversal = $request->request->getBoolean('transversal');
        if ($transversal && $this->hasTransversal($formation, $parcours)) {
            $this->addFlash('warning', 'Le bloc de compétences transversales existe déjà.');

            return $this->contextRedirect($formation, $parcours);
        }

        $regular = array_filter($this->blocsOf($formation, $parcours), static fn (Node $b) => !$b->isTransversalBloc());
        $label = trim((string) $request->request->get('label'))
            ?: ($transversal ? 'Compétences transversales (RNCP)' : 'BC '.(\count($regular) + 1));

        $bloc = $this->factory->create($formation, $type, $parcours, $label);
        if ($transversal) {
            $bloc->setAttribute('transversal', true);
        }
        $this->em->flush();
        $this->addFlash('success', $transversal ? 'Bloc transversal ajouté.' : sprintf('« %s » ajouté.', $label));

        return $this->contextRedirect($formation, $parcours);
    }

    /** @return list<Node> */
    private function blocsOf(Formation $formation, ?Node $parcours): array
    {
        return $parcours ? $parcours->getBccBlocs() : $formation->getCompetenceBlocs();
    }

    private function hasTransversal(Formation $formation, ?Node $parcours): bool
    {
        return $parcours ? $parcours->hasTransversalBloc() : $formation->hasTransversalBloc();
    }

    /** Autres blocs du même contexte (même parent) que $bloc. @return list<Node> */
    private function siblingBlocs(Node $bloc): array
    {
        $parentId = $bloc->getParent()?->getId();
        $out = array_values(array_filter(
            $this->nodes->findForFormation($bloc->getFormation()),
            static fn (Node $b) => $b !== $bloc && $b->isBloc() && $b->getParent()?->getId() === $parentId,
        ));
        usort($out, static fn (Node $a, Node $b) => $a->getPosition() <=> $b->getPosition());

        return $out;
    }

    /** @return list<Node> */
    private function competenceChildren(Node $bloc, ?Node $exclude = null): array
    {
        $out = array_values(array_filter(
            $this->nodes->findForFormation($bloc->getFormation()),
            static fn (Node $c) => $c !== $exclude
                && $c->getParent() === $bloc
                && $c->getType()->getKey() === self::COMPETENCE,
        ));
        usort($out, static fn (Node $a, Node $b) => $a->getPosition() <=> $b->getPosition());

        return $out;
    }

    /**
     * @param list<Node> $blocs
     *
     * @return list<array{node: Node, competences: list<Node>, transversal: bool}>
     */
    private function buildTree(array $blocs): array
    {
        $out = [];
        foreach ($blocs as $bloc) {
            $comps = array_values(array_filter(
                $bloc->getChildren()->toArray(),
                static fn (Node $c) => $c->getType()->getKey() === self::COMPETENCE,
            ));
            usort($comps, static fn (Node $a, Node $b) => $a->getPosition() <=> $b->getPosition());
            $out[] = ['node' => $bloc, 'competences' => $comps, 'transversal' => $bloc->isTransversalBloc()];
        }

        return $out;
    }

    /** Redirige vers l'éditeur du contexte du nœud BCC (parcours ou formation). */
    private function ownerRedirect(Node $bccNode): Response
    {
        for ($c = $bccNode; $c !== null; $c = $c->getParent()) {
            if ($c->isParcours()) {
                return $this->redirectToRoute('parcours_editor', ['id' => $c->getId(), 'bcc' => 1]);
            }
        }

        return $this->redirectToRoute('formation_editor', ['id' => $bccNode->getFormation()->getId(), 'bcc' => 1]);
    }

    private function contextRedirect(Formation $formation, ?Node $parcours): Response
    {
        return $parcours
            ? $this->redirectToRoute('parcours_editor', ['id' => $parcours->getId(), 'bcc' => 1])
            : $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'bcc' => 1]);
    }
}
