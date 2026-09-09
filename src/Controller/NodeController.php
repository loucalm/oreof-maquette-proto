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
        private readonly AttributeCatalog $catalog,
    ) {
    }

    #[Route('/nodes/{id}', name: 'node_panel', methods: ['GET'])]
    public function panel(Node $node): Response
    {
        return $this->render('node/panel.html.twig', [
            'view' => $this->builder->buildSubtree($node),
            'node' => $node,
            'catalog' => $this->catalog->all(),
            'domains' => $this->catalog->domains(),
        ]);
    }

    /** Contenu de la modale « Paramètre du nœud » (formulaire seul, dans son propre frame). */
    #[Route('/nodes/{id}/parametres', name: 'node_params_form', methods: ['GET'])]
    public function paramsForm(Node $node): Response
    {
        return $this->render('node/_params_form.html.twig', [
            'node' => $node,
            'view' => $this->builder->buildSubtree($node),
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
            if ($target !== $node->getParent()
                && !$this->isDescendant($target, $node)
                && $this->reparentAllowed($node, $target)) {
                $this->factory->move($node, $target, PHP_INT_MAX);
            }
        }

        $attrs = $node->getAttributes();
        $caps = $node->effectiveCapabilities();
        foreach ($this->catalog->all() as $key => $def) {
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
        // parcours : période de début / fin (sur l'axe temporel de la formation)
        if ($node->isParcours()) {
            unset($attrs['anneeDebut'], $attrs['anneeFin']); // ancien nommage
            foreach (['periodeDebut', 'periodeFin'] as $k) {
                $v = $request->request->get("attr_$k");
                if ($v === null || $v === '') {
                    unset($attrs[$k]);
                } else {
                    $attrs[$k] = max(1, (int) $v);
                }
            }
        }

        $node->setAttributes(array_filter($attrs, static fn ($v) => $v !== null && $v !== '' && $v !== []));

        // parcours : parent de ramification — validé une fois les périodes posées
        if ($node->isParcours() && $request->request->has('parcoursParentId')) {
            $this->applyParcoursParent($node, $request->request->getInt('parcoursParentId'));
        }

        $this->em->flush();

        return $this->backToEditor($node, 'Nœud enregistré.');
    }

    #[Route('/formations/{id}/nodes', name: 'node_add', methods: ['POST'])]
    public function add(Formation $formation, Request $request, NodeTypeRepository $types): Response
    {
        $parent = $request->request->get('parentId') ? $this->nodes->find($request->request->getInt('parentId')) : null;

        // « auto » (bouton contextuel de l'arbre) : le type se déduit du squelette
        $typeKey = trim((string) $request->request->get('typeKey'));
        if ('' === $typeKey || 'auto' === $typeKey) {
            $typeKey = null !== $parent
                ? $formation->getChildTypeKey($parent->getType()->getKey())
                : $formation->getVisibleRootTypeKey();
        }

        $type = null !== $typeKey ? $types->findOneByKey($typeKey) : null;
        if ($type === null) {
            $this->addFlash('warning', null !== $parent
                ? sprintf('« %s » ne peut pas contenir d’enfant.', $parent->getDisplayLabel())
                : 'Définissez d’abord le squelette dans « Configuration de la structure ».');

            return $this->redirectToRoute('formation_editor', null !== $parent
                ? ['id' => $formation->getId(), 'focus' => $parent->getId()]
                : ['id' => $formation->getId(), 'param' => 'structure']);
        }

        $node = $this->factory->create($formation, $type, $parent, trim((string) $request->request->get('label')));
        $this->em->flush();
        $this->addFlash('success', sprintf('%s ajouté.', $type->getLabel()));

        // on ouvre le nouveau nœud dans le panneau, qu'il soit racine ou enfant
        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'focus' => $node->getId()]);
    }

    #[Route('/nodes/{id}/move', name: 'node_move', methods: ['POST'])]
    public function move(Node $node, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent() ?: '{}', true) ?: [];
        $newParent = !empty($payload['parentId']) ? $this->nodes->find((int) $payload['parentId']) : null;

        if ($this->isDescendant($newParent, $node)) {
            return new JsonResponse(['ok' => false, 'error' => 'cycle'], 422);
        }
        if (!$this->reparentAllowed($node, $newParent)) {
            return new JsonResponse(['ok' => false, 'error' => 'hierarchy'], 422);
        }

        $this->factory->move($node, $newParent, (int) ($payload['index'] ?? 0));
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    /** Le squelette de la formation autorise-t-il ce nœud sous ce parent (ou à la racine) ? */
    private function reparentAllowed(Node $node, ?Node $newParent): bool
    {
        $formation = $node->getFormation();
        $typeKey = $node->getType()->getKey();

        return $newParent === null
            ? $formation->canBeRootType($typeKey)
            : $formation->canParentTypes($newParent->getType()->getKey(), $typeKey);
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

    /**
     * « Paramètre du nœud » : le responsable choisit, parmi les capacités que le
     * type expose, celles qui apparaissent sur CE nœud. Les capacités
     * « réservées admin » (NodeType::lockedCapabilities) sont ignorées ici.
     */
    #[Route('/nodes/{id}/params', name: 'node_params', methods: ['POST'])]
    public function params(Node $node, Request $request): Response
    {
        $checked = $request->request->all('capabilities');
        $overrides = $node->getCapabilityOverrides() ?? [];
        foreach (array_keys($node->getType()->getCapabilities()) as $cap) {
            if ($node->getType()->isCapabilityLocked($cap)) {
                unset($overrides[$cap]); // pas d'override sur une capacité verrouillée
                continue;
            }
            $overrides[$cap] = \in_array($cap, $checked, true);
        }
        // on ne garde que les overrides qui diffèrent du défaut du type
        foreach ($overrides as $cap => $val) {
            if ($val === $node->getType()->capabilityDefault($cap)) {
                unset($overrides[$cap]);
            }
        }
        $node->setCapabilityOverrides($overrides === [] ? null : $overrides);
        $this->em->flush();

        return $this->backToEditor($node, 'Paramètre du nœud enregistré.');
    }

    /** « Raccrocher » : liste des nœuds mutualisés d'autres formations, du bon type. */
    #[Route('/nodes/{id}/raccrocher', name: 'node_attach_index', methods: ['GET'])]
    public function attachIndex(Node $node): Response
    {
        // on ne peut raccrocher que des nœuds du type prévu comme enfant par le squelette
        $childKey = $node->getFormation()->getChildTypeKey($node->getType()->getKey());
        $candidates = $childKey === null ? [] : array_filter(
            $this->nodes->findMutualized($node->getFormation()),
            static fn (Node $m) => $m->getType()->getKey() === $childKey,
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

    /** Ramification : rattache $node à un parcours parent si la relation est cohérente (sinon détache + prévient). */
    private function applyParcoursParent(Node $node, int $parentId): void
    {
        if ($parentId <= 0) {
            $node->setParcoursParent(null); // « — Aucun — » choisi explicitement

            return;
        }

        $pp = $this->nodes->find($parentId);
        if ($pp === null || $pp === $node || !$pp->isParcours() || $this->isParcoursDescendant($pp, $node)) {
            return; // cible invalide : on ne touche pas au parent déjà en place
        }

        if (!Node::parcoursPeriodsAllowChild($pp->getPeriodeDebut(), $pp->getPeriodeFin(), $node->getPeriodeDebut(), $node->getPeriodeFin())) {
            $this->addFlash('warning', sprintf(
                'Ramification ignorée : « %s » couvre les périodes %d–%d. Un parcours enfant doit commencer après la période %d et se prolonger au moins jusqu’à la période %d.',
                $pp->getDisplayLabel(),
                $pp->getPeriodeDebut(),
                $pp->getPeriodeFin(),
                $pp->getPeriodeDebut(),
                $pp->getPeriodeFin(),
            ));

            return;
        }

        $node->setParcoursParent($pp);
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

    /** $candidate est-il dans la descendance parcours de $of (anti-cycle) ? */
    private function isParcoursDescendant(Node $candidate, Node $of): bool
    {
        $cursor = $candidate;
        $seen = [];
        while ($cursor !== null && !isset($seen[$cursor->getId()])) {
            if ($cursor === $of) {
                return true;
            }
            $seen[$cursor->getId()] = true;
            $cursor = $cursor->getParcoursParent();
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
