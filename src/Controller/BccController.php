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
 * La hiérarchie est fixe (bloc → compétence) : pas besoin du squelette de la
 * formation, d'où un contrôleur dédié.
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
        return $this->render('formation/bcc.html.twig', [
            'formation' => $formation,
            'blocs' => $this->buildTree($formation),
            'standalone' => 'node-panel' !== $request->headers->get('Turbo-Frame'),
        ]);
    }

    #[Route('/formations/{id}/bcc/blocs', name: 'bcc_bloc_add', methods: ['POST'])]
    public function addBloc(Formation $formation, Request $request): Response
    {
        $type = $this->types->findOneByKey(self::BLOC);
        if ($type === null) {
            $this->addFlash('danger', 'Type « bloc de compétences » introuvable.');

            return $this->back($formation);
        }

        $transversal = $request->request->getBoolean('transversal');
        if ($transversal && $formation->hasTransversalBloc()) {
            $this->addFlash('warning', 'Le bloc de compétences transversales existe déjà.');

            return $this->back($formation);
        }

        $regular = array_filter($formation->getCompetenceBlocs(), static fn (Node $b) => !$b->isTransversalBloc());
        $label = trim((string) $request->request->get('label'))
            ?: ($transversal ? 'Compétences transversales (RNCP)' : 'BC '.(\count($regular) + 1));

        $bloc = $this->factory->create($formation, $type, null, $label);
        if ($transversal) {
            $bloc->setAttribute('transversal', true);
        }
        $this->em->flush();
        $this->addFlash('success', $transversal ? 'Bloc transversal ajouté.' : sprintf('« %s » ajouté.', $label));

        return $this->back($formation);
    }

    #[Route('/nodes/{id}/bcc/competences', name: 'bcc_competence_add', methods: ['POST'])]
    public function addCompetence(Node $node, Request $request): Response
    {
        $formation = $node->getFormation();
        if (!$this->isBloc($node)) {
            throw $this->createNotFoundException();
        }

        $type = $this->types->findOneByKey(self::COMPETENCE);
        if ($type === null) {
            $this->addFlash('danger', 'Type « compétence » introuvable.');

            return $this->back($formation);
        }

        $label = trim((string) $request->request->get('label')) ?: 'Nouvelle compétence';
        $this->factory->create($formation, $type, $node, $label);
        $this->em->flush();
        $this->addFlash('success', 'Compétence ajoutée.');

        return $this->back($formation);
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

        return $this->back($node->getFormation());
    }

    #[Route('/nodes/{id}/bcc/delete', name: 'bcc_node_delete', methods: ['POST'])]
    public function delete(Node $node): Response
    {
        if (!$node->isCompetenceNode()) {
            throw $this->createNotFoundException();
        }
        $formation = $node->getFormation();
        $isBloc = $this->isBloc($node);
        $this->em->remove($node);
        $this->em->flush();
        $this->addFlash('info', $isBloc ? 'Bloc supprimé.' : 'Compétence supprimée.');

        return $this->back($formation);
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
        $formation = $node->getFormation();

        // règles fixes du BCC : un bloc reste racine, une compétence va dans un bloc
        $wantsRoot = $newParent === null;
        if ($this->isBloc($node) !== $wantsRoot) {
            return new JsonResponse(['ok' => false, 'error' => 'hierarchy'], 422);
        }
        if ($newParent !== null && (!$this->isBloc($newParent) || $newParent->getFormation() !== $formation)) {
            return new JsonResponse(['ok' => false, 'error' => 'hierarchy'], 422);
        }
        // le bloc transversal reste épinglé en tête, non réordonnable
        if ($node->isTransversalBloc()) {
            return new JsonResponse(['ok' => true]);
        }

        $oldParent = $node->getParent();

        if ($wantsRoot) {
            // réordonner parmi les blocs « réguliers » uniquement (pas les nœuds
            // pédagogiques racine, pas le bloc transversal)
            $siblings = array_values(array_filter(
                $formation->getCompetenceBlocs(),
                static fn (Node $b) => $b !== $node && !$b->isTransversalBloc(),
            ));
        } else {
            $node->setParent($newParent);
            $newParent->addChild($node);
            $siblings = $this->competenceChildren($newParent, $node);
        }

        array_splice($siblings, min($index, \count($siblings)), 0, [$node]);
        foreach ($siblings as $i => $s) {
            $s->setPosition($i);
        }

        // compacter l'ancien bloc si la compétence a changé de parent
        if ($oldParent !== null && $oldParent !== $newParent) {
            foreach ($this->competenceChildren($oldParent, $node) as $i => $s) {
                $s->setPosition($i);
            }
        }

        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    /**
     * Compétences d'un bloc, triées par position, hors $exclude.
     *
     * @return list<Node>
     */
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

    private function isBloc(Node $node): bool
    {
        return $node->getType()->getKey() === self::BLOC && $node->getParent() === null;
    }

    /**
     * @return list<array{node: Node, competences: list<Node>, transversal: bool}>
     */
    private function buildTree(Formation $formation): array
    {
        $out = [];
        foreach ($formation->getCompetenceBlocs() as $bloc) {
            $comps = array_values(array_filter(
                $bloc->getChildren()->toArray(),
                static fn (Node $c) => $c->getType()->getKey() === self::COMPETENCE,
            ));
            usort($comps, static fn (Node $a, Node $b) => $a->getPosition() <=> $b->getPosition());
            $out[] = ['node' => $bloc, 'competences' => $comps, 'transversal' => $bloc->isTransversalBloc()];
        }

        return $out;
    }

    private function back(Formation $formation): Response
    {
        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'bcc' => 1]);
    }
}
