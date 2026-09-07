<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Formation;
use App\Entity\Node;
use App\Maquette\AttributeCatalog;
use App\Maquette\MaquetteBuilder;
use App\Maquette\NodeFactory;
use App\Repository\NodeRepository;
use App\Repository\NodeTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NodeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MaquetteBuilder $builder,
        private readonly NodeRepository $nodes,
        private readonly NodeFactory $factory,
    ) {
    }

    #[Route('/nodes/{id}', name: 'node_panel', methods: ['GET'])]
    public function panel(Node $node): Response
    {
        return $this->render('node/panel.html.twig', [
            'view' => $this->builder->buildSubtree($node),
            'node' => $node,
            'catalog' => AttributeCatalog::all(),
            'domains' => AttributeCatalog::domains(),
        ]);
    }

    #[Route('/nodes/{id}', name: 'node_save', methods: ['POST'])]
    public function save(Node $node, Request $request): Response
    {
        $node->setLabel(trim((string) $request->request->get('label')));
        $node->setCode(trim((string) $request->request->get('code')) ?: null);

        // reparentage éventuel (dropdown « Nœud parent »)
        if ($request->request->has('parentId')) {
            $target = $request->request->get('parentId')
                ? $this->nodes->find($request->request->getInt('parentId'))
                : null;
            if ($target !== $node->getParent() && !$this->isDescendant($target, $node)) {
                $this->factory->move($node, $target, PHP_INT_MAX);
            }
        }

        $attrs = $node->getAttributes();
        $caps = $node->effectiveCapabilities();
        foreach (AttributeCatalog::all() as $key => $def) {
            if (!($caps[$key] ?? false)) {
                unset($attrs[$key]);
                continue;
            }
            $attrs[$key] = match ($def['field']) {
                'number' => $this->numOrNull($request->request->get("attr_$key")),
                'hours' => $this->readHours($request),
                'mccc' => $this->cleanArray($request->request->all('attr_mccc')),
                'competencies' => array_values(array_filter(array_map('trim', explode(',', (string) $request->request->get('attr_competencies'))))),
                default => trim((string) $request->request->get("attr_$key")) ?: null,
            };
        }
        $node->setAttributes(array_filter($attrs, static fn ($v) => $v !== null && $v !== '' && $v !== []));

        $this->em->flush();

        return $this->backToEditor($node, 'Nœud enregistré.');
    }

    #[Route('/formations/{id}/nodes', name: 'node_add', methods: ['POST'])]
    public function add(Formation $formation, Request $request, NodeTypeRepository $types): Response
    {
        $type = $types->findOneByKey((string) $request->request->get('typeKey'));
        if ($type === null) {
            throw $this->createNotFoundException('Type inconnu');
        }
        $parent = $request->request->get('parentId') ? $this->nodes->find($request->request->getInt('parentId')) : null;

        $node = $this->factory->create($formation, $type, $parent, trim((string) $request->request->get('label')));
        $this->em->flush();
        $this->addFlash('success', sprintf('%s ajouté.', $type->getLabel()));

        // ajout d'un nœud racine depuis « Configuration de la structure » → on y reste ;
        // ajout d'un enfant depuis le panneau d'un nœud → on ouvre le nouveau nœud.
        return $parent === null
            ? $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'param' => 'structure'])
            : $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'focus' => $node->getId()]);
    }

    #[Route('/nodes/{id}/move', name: 'node_move', methods: ['POST'])]
    public function move(Node $node, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent() ?: '{}', true) ?: [];
        $newParent = !empty($payload['parentId']) ? $this->nodes->find((int) $payload['parentId']) : null;

        if ($this->isDescendant($newParent, $node)) {
            return new JsonResponse(['ok' => false, 'error' => 'cycle'], 422);
        }

        $this->factory->move($node, $newParent, (int) ($payload['index'] ?? 0));
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/nodes/{id}/duplicate', name: 'node_duplicate', methods: ['POST'])]
    public function duplicate(Node $node): Response
    {
        $copy = $this->factory->duplicate($node);
        $this->em->flush();

        return $this->redirectToRoute('formation_editor', ['id' => $node->getFormation()->getId(), 'focus' => $copy->getId()]);
    }

    #[Route('/nodes/{id}/delete', name: 'node_delete', methods: ['POST'])]
    public function delete(Node $node): Response
    {
        $formationId = $node->getFormation()->getId();
        $this->em->remove($node);
        $this->em->flush();
        $this->addFlash('info', 'Nœud supprimé.');

        return $this->redirectToRoute('formation_editor', ['id' => $formationId]);
    }

    /** Toggle « Mutualiser » : met le nœud à disposition des autres formations. */
    #[Route('/nodes/{id}/mutualize', name: 'node_mutualize', methods: ['POST'])]
    public function mutualize(Node $node, Request $request): Response
    {
        $node->setMutualized(!$node->isMutualized());
        $this->em->flush();

        return $this->backToEditor($node, $node->isMutualized() ? 'Nœud mutualisé.' : 'Nœud retiré de la mutualisation.');
    }

    /** « Paramètre du nœud » : capacités portées par CE nœud (surcharge du type). */
    #[Route('/nodes/{id}/params', name: 'node_params', methods: ['POST'])]
    public function params(Node $node, Request $request): Response
    {
        $checked = $request->request->all('capabilities');
        $overrides = [];
        foreach (AttributeCatalog::knownCapabilities() as $cap) {
            $overrides[$cap] = \in_array($cap, $checked, true);
        }
        $node->setCapabilityOverrides($overrides);
        $this->em->flush();

        return $this->backToEditor($node, 'Capacités du nœud enregistrées.');
    }

    /** « Raccrocher » : liste des nœuds mutualisés d'autres formations, du bon type. */
    #[Route('/nodes/{id}/raccrocher', name: 'node_attach_index', methods: ['GET'])]
    public function attachIndex(Node $node): Response
    {
        $allowed = $node->getType()->getAllowedChildKeys();
        $candidates = array_filter(
            $this->nodes->findMutualized($node->getFormation()),
            static fn (Node $m) => \in_array('*', $allowed, true) || \in_array($m->getType()->getKey(), $allowed, true),
        );

        return $this->render('node/attach.html.twig', ['node' => $node, 'candidates' => $candidates]);
    }

    #[Route('/nodes/{id}/raccrocher/{source}', name: 'node_attach', methods: ['POST'])]
    public function attach(Node $node, Node $source): Response
    {
        $copy = $this->factory->duplicate($source, $node);
        $copy->setLabel($source->getLabel());
        $copy->setMutualizedFrom($source);
        $this->em->flush();
        $this->addFlash('success', sprintf('« %s » raccroché depuis « %s ».', $source->getDisplayLabel(), $source->getFormation()->getName()));

        return $this->backToEditor($copy, '');
    }

    // ─── helpers ──────────────────────────────────────────────

    private function backToEditor(Node $node, string $message): Response
    {
        if ($message !== '') {
            $this->addFlash('success', $message);
        }

        return $this->redirectToRoute('formation_editor', [
            'id' => $node->getFormation()->getId(),
            'focus' => $node->getId(),
        ]);
    }

    private function isDescendant(?Node $candidate, Node $of): bool
    {
        $cursor = $candidate;
        while ($cursor !== null) {
            if ($cursor === $of) {
                return true;
            }
            $cursor = $cursor->getParent();
        }

        return false;
    }

    private function numOrNull(mixed $v): ?float
    {
        return ($v === null || $v === '') ? null : (float) $v;
    }

    /** @return array<string, array<string, float>> */
    private function readHours(Request $request): array
    {
        $out = [];
        foreach (array_keys(AttributeCatalog::HOUR_MODALITIES) as $m) {
            foreach (array_keys(AttributeCatalog::HOUR_PLACES) as $p) {
                $val = (float) $request->request->get("attr_hours_{$m}_{$p}", 0);
                if ($val > 0) {
                    $out[$m][$p] = $val;
                }
            }
        }

        return $out;
    }

    /** @param array<mixed> $arr */
    private function cleanArray(array $arr): array
    {
        return array_filter(
            array_map(static fn ($v) => \is_string($v) ? trim($v) : $v, $arr),
            static fn ($v) => $v !== '' && $v !== null,
        );
    }
}
