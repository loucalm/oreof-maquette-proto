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
                'mccc' => $this->cleanArray($request->request->all("attr_mccc")),
                'competencies' => array_values(array_filter(array_map('trim', explode(',', (string) $request->request->get('attr_competencies'))))),
                default => trim((string) $request->request->get("attr_$key")) ?: null,
            };
        }
        $node->setAttributes(array_filter($attrs, static fn ($v) => $v !== null && $v !== '' && $v !== []));

        $this->em->flush();

        return $this->turboOrRedirect($node, 'Nœud enregistré.');
    }

    #[Route('/formations/{id}/nodes', name: 'node_add', methods: ['POST'])]
    public function add(Formation $formation, Request $request, NodeTypeRepository $types, NodeFactory $factory, NodeRepository $nodes): Response
    {
        $type = $types->findOneByKey((string) $request->request->get('typeKey'));
        if ($type === null) {
            throw $this->createNotFoundException('Type inconnu');
        }
        $parent = null;
        if ($request->request->get('parentId')) {
            $parent = $nodes->find($request->request->getInt('parentId'));
        }

        $node = $factory->create($formation, $type, $parent, trim((string) $request->request->get('label')));
        $this->em->flush();

        $this->addFlash('success', sprintf('%s ajouté.', $type->getLabel()));

        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'focus' => $node->getId()]);
    }

    #[Route('/nodes/{id}/move', name: 'node_move', methods: ['POST'])]
    public function move(Node $node, Request $request, NodeFactory $factory, NodeRepository $nodes): JsonResponse
    {
        $payload = json_decode($request->getContent() ?: '{}', true) ?: [];
        $newParent = !empty($payload['parentId']) ? $nodes->find((int) $payload['parentId']) : null;
        $index = (int) ($payload['index'] ?? 0);

        // garde-fou : pas de boucle (déposer un nœud dans sa propre descendance)
        $cursor = $newParent;
        while ($cursor !== null) {
            if ($cursor === $node) {
                return new JsonResponse(['ok' => false, 'error' => 'cycle'], 422);
            }
            $cursor = $cursor->getParent();
        }

        $factory->move($node, $newParent, $index);
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/nodes/{id}/duplicate', name: 'node_duplicate', methods: ['POST'])]
    public function duplicate(Node $node, NodeFactory $factory): Response
    {
        $copy = $factory->duplicate($node);
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

    #[Route('/nodes/{id}/params', name: 'node_params', methods: ['POST'])]
    public function params(Node $node, Request $request): Response
    {
        $overrides = [];
        foreach (AttributeCatalog::knownCapabilities() as $cap) {
            $overrides[$cap] = $request->request->getBoolean("cap_$cap");
        }
        $node->setCapabilityOverrides($overrides);
        $node->setMutualized($request->request->getBoolean('mutualized'));
        $this->em->flush();

        return $this->turboOrRedirect($node, 'Paramètres du nœud enregistrés.');
    }

    // ─── helpers ───────────────────────────────────────────────

    private function turboOrRedirect(Node $node, string $message): Response
    {
        $this->addFlash('success', $message);

        return $this->redirectToRoute('formation_editor', [
            'id' => $node->getFormation()->getId(),
            'focus' => $node->getId(),
        ]);
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
        return array_filter(array_map(static fn ($v) => \is_string($v) ? trim($v) : $v, $arr), static fn ($v) => $v !== '' && $v !== null);
    }
}
