<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Formation;
use App\Maquette\AttributeCatalog;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Maquette\MaquetteBuilder;
use App\Repository\NodeTypeRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Nœuds de la structure pédagogique. Le nœud n'est plus une ligne en base :
 * il vit dans le document JSON `Formation::arbre`, chargé/persisté via le
 * service App\Maquette\Maquette. Les routes sont donc portée-formation :
 * `/formations/{fid}/nodes/{nid}`.
 */
final class NodeController extends AbstractController
{
    public function __construct(
        private readonly Maquette $maquette,
        private readonly MaquetteBuilder $builder,
        private readonly AttributeCatalog $catalog,
    ) {
    }

    #[Route('/formations/{fid}/nodes/{nid}', name: 'node_panel', methods: ['GET'])]
    public function panel(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid): Response
    {
        $node = $this->node($formation, $nid);

        return $this->render('node/panel.html.twig', [
            'view' => $this->builder->buildSubtree($node),
            'node' => $node,
            'catalog' => $this->catalog->all(),
            'domains' => $this->catalog->domains(),
        ]);
    }

    #[Route('/formations/{fid}/nodes/{nid}/parametres', name: 'node_params_form', methods: ['GET'])]
    public function paramsForm(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid): Response
    {
        $node = $this->node($formation, $nid);

        return $this->render('node/_params_form.html.twig', [
            'node' => $node,
            'view' => $this->builder->buildSubtree($node),
        ]);
    }

    #[Route('/formations/{fid}/nodes/{nid}', name: 'node_save', methods: ['POST'])]
    public function save(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid, Request $request): Response
    {
        $doc = $this->maquette->open($formation);
        $node = $this->pick($doc, $nid);

        $node->setLabel(trim((string) $request->request->get('label')));
        $node->setCode(trim((string) $request->request->get('code')) ?: null);

        if ($request->request->has('parentId')) {
            $targetNid = trim((string) $request->request->get('parentId'));
            $target = $targetNid !== '' ? $doc->node($targetNid) : null;
            if ($target !== $node->getParent()
                && !$this->isDescendant($target, $node)
                && $this->reparentAllowed($node, $target)) {
                $doc->moveNode($node, $target, PHP_INT_MAX);
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
                'competencies' => array_values(array_filter(
                    array_map(static fn ($v) => trim((string) $v), $request->request->all('attr_competencies')),
                )),
                default => trim((string) $request->request->get("attr_$key")) ?: null,
            };
        }

        if ($node->isParcours()) {
            unset($attrs['anneeDebut'], $attrs['anneeFin']);
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

        if ($node->isParcours() && $request->request->has('parcoursParentId')) {
            $this->applyParcoursParent($doc, $node, trim((string) $request->request->get('parcoursParentId')));
        }

        $this->maquette->save($formation, $doc);

        return $this->backToEditor($node, 'Nœud enregistré.');
    }

    #[Route('/formations/{fid}/nodes', name: 'node_add', methods: ['POST'])]
    public function add(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, Request $request, NodeTypeRepository $types): Response
    {
        $doc = $this->maquette->open($formation);
        $parentNid = trim((string) $request->request->get('parentId'));
        $parent = $parentNid !== '' ? $doc->node($parentNid) : null;

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

            return null !== $parent
                ? $this->editorRedirect($parent)
                : $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'param' => 'structure']);
        }

        $node = $doc->addNode($parent?->getId(), $typeKey, trim((string) $request->request->get('label')));
        $this->maquette->save($formation, $doc);
        $this->addFlash('success', sprintf('%s ajouté.', $type->getLabel()));

        return $this->editorRedirect($node);
    }

    #[Route('/formations/{fid}/nodes/{nid}/move', name: 'node_move', methods: ['POST'])]
    public function move(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid, Request $request): JsonResponse
    {
        $doc = $this->maquette->open($formation);
        $node = $doc->node($nid);
        if ($node === null) {
            return new JsonResponse(['ok' => false, 'error' => 'not-found'], 404);
        }

        $payload = json_decode($request->getContent() ?: '{}', true) ?: [];
        $newParent = !empty($payload['parentId']) ? $doc->node((string) $payload['parentId']) : null;

        if ($this->isDescendant($newParent, $node)) {
            return new JsonResponse(['ok' => false, 'error' => 'cycle'], 422);
        }
        if (!$this->reparentAllowed($node, $newParent)) {
            return new JsonResponse(['ok' => false, 'error' => 'hierarchy'], 422);
        }

        $doc->moveNode($node, $newParent, (int) ($payload['index'] ?? 0));
        $this->maquette->save($formation, $doc);

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/formations/{fid}/nodes/{nid}/duplicate', name: 'node_duplicate', methods: ['POST'])]
    public function duplicate(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid): Response
    {
        $doc = $this->maquette->open($formation);
        $copy = $doc->duplicateNode($this->pick($doc, $nid));
        $this->maquette->save($formation, $doc);

        return $this->editorRedirect($copy);
    }

    #[Route('/formations/{fid}/nodes/{nid}/delete', name: 'node_delete', methods: ['POST'])]
    public function delete(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid): Response
    {
        $doc = $this->maquette->open($formation);
        $node = $this->pick($doc, $nid);
        $parcours = $this->parcoursAncestor($node);

        $doc->removeNode($node);
        $this->maquette->save($formation, $doc);
        $this->addFlash('info', 'Nœud supprimé.');

        return $parcours !== null && $parcours !== $node
            ? $this->redirectToRoute('parcours_editor', ['fid' => $formation->getId(), 'nid' => $parcours->getId()])
            : $this->redirectToRoute('formation_editor', ['id' => $formation->getId()]);
    }

    #[Route('/formations/{fid}/nodes/{nid}/mutualize', name: 'node_mutualize', methods: ['POST'])]
    public function mutualize(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid): Response
    {
        $doc = $this->maquette->open($formation);
        $node = $this->pick($doc, $nid);
        $node->setMutualized(!$node->isMutualized());
        $this->maquette->save($formation, $doc);

        return $this->backToEditor($node, $node->isMutualized() ? 'Nœud mutualisé.' : 'Nœud retiré de la mutualisation.');
    }

    #[Route('/formations/{fid}/nodes/{nid}/params', name: 'node_params', methods: ['POST'])]
    public function params(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid, Request $request): Response
    {
        $doc = $this->maquette->open($formation);
        $node = $this->pick($doc, $nid);

        $checked = $request->request->all('capabilities');
        $overrides = $node->getCapabilityOverrides() ?? [];
        foreach (array_keys($node->getType()->getCapabilities()) as $cap) {
            if ($node->getType()->isCapabilityLocked($cap)) {
                unset($overrides[$cap]);
                continue;
            }
            $overrides[$cap] = \in_array($cap, $checked, true);
        }
        foreach ($overrides as $cap => $val) {
            if ($val === $node->getType()->capabilityDefault($cap)) {
                unset($overrides[$cap]);
            }
        }
        $node->setCapabilityOverrides($overrides === [] ? null : $overrides);
        $this->maquette->save($formation, $doc);

        return $this->backToEditor($node, 'Paramètre du nœud enregistré.');
    }

    #[Route('/formations/{fid}/nodes/{nid}/raccrocher', name: 'node_attach_index', methods: ['GET'])]
    public function attachIndex(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid): Response
    {
        $node = $this->node($formation, $nid);
        $childKey = $formation->getChildTypeKey($node->getType()->getKey());
        $candidates = $childKey === null ? [] : $this->maquette->findMutualized($formation, $childKey);

        return $this->render('node/attach.html.twig', ['node' => $node, 'candidates' => $candidates]);
    }

    #[Route('/formations/{fid}/nodes/{nid}/raccrocher/{sfid}/{snid}', name: 'node_attach', methods: ['POST'])]
    public function attach(
        #[MapEntity(mapping: ['fid' => 'id'])] Formation $formation,
        string $nid,
        #[MapEntity(mapping: ['sfid' => 'id'])] Formation $sourceFormation,
        string $snid,
    ): Response {
        $doc = $this->maquette->open($formation);
        $target = $this->pick($doc, $nid);

        $source = $this->maquette->open($sourceFormation)->node($snid);
        if ($source === null) {
            throw $this->createNotFoundException();
        }

        $copy = $doc->importSubtree($source, $target);
        $copy->setMutualizedFrom($sourceFormation->getId().':'.$snid);
        $this->maquette->save($formation, $doc);
        $this->addFlash('success', sprintf('« %s » raccroché depuis « %s ».', $source->getDisplayLabel(), $sourceFormation->getName()));

        return $this->backToEditor($copy, '');
    }

    // ─── helpers ──────────────────────────────────────────────

    private function node(Formation $formation, string $nid): TreeNode
    {
        return $this->pick($this->maquette->open($formation), $nid);
    }

    private function pick(MaquetteDoc $doc, string $nid): TreeNode
    {
        $node = $doc->node($nid);
        if ($node === null) {
            throw $this->createNotFoundException();
        }

        return $node;
    }

    private function parcoursAncestor(TreeNode $node): ?TreeNode
    {
        for ($c = $node; $c !== null; $c = $c->getParent()) {
            if ($c->isParcours()) {
                return $c;
            }
        }

        return null;
    }

    private function editorRedirect(TreeNode $node): Response
    {
        $formation = $node->getFormation();
        for ($c = $node; $c !== null; $c = $c->getParent()) {
            if ($c->isParcours()) {
                return $this->redirectToRoute('parcours_editor', [
                    'fid' => $formation->getId(), 'nid' => $c->getId(), 'focus' => $node->getId(),
                ]);
            }
        }

        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'focus' => $node->getId()]);
    }

    private function backToEditor(TreeNode $node, string $message): Response
    {
        if ($message !== '') {
            $this->addFlash('success', $message);
        }

        return $this->editorRedirect($node);
    }

    private function reparentAllowed(TreeNode $node, ?TreeNode $newParent): bool
    {
        $formation = $node->getFormation();
        $typeKey = $node->getType()->getKey();

        return $newParent === null
            ? $formation->canBeRootType($typeKey)
            : $formation->canParentTypes($newParent->getType()->getKey(), $typeKey);
    }

    private function applyParcoursParent(MaquetteDoc $doc, TreeNode $node, string $parentNid): void
    {
        if ($parentNid === '') {
            $node->setParcoursParent(null);

            return;
        }

        $pp = $doc->node($parentNid);
        if ($pp === null || $pp === $node || !$pp->isParcours() || $this->isParcoursDescendant($pp, $node)) {
            return;
        }

        if (!TreeNode::parcoursPeriodsAllowChild($pp->getPeriodeDebut(), $pp->getPeriodeFin(), $node->getPeriodeDebut(), $node->getPeriodeFin())) {
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

    private function isDescendant(?TreeNode $candidate, TreeNode $of): bool
    {
        for ($cursor = $candidate; $cursor !== null; $cursor = $cursor->getParent()) {
            if ($cursor === $of) {
                return true;
            }
        }

        return false;
    }

    private function isParcoursDescendant(TreeNode $candidate, TreeNode $of): bool
    {
        $seen = [];
        for ($cursor = $candidate; $cursor !== null && !isset($seen[$cursor->getId()]); $cursor = $cursor->getParcoursParent()) {
            if ($cursor === $of) {
                return true;
            }
            $seen[$cursor->getId()] = true;
        }

        return false;
    }

    private function numOrNull(mixed $v): ?float
    {
        return ($v === null || $v === '') ? null : (float) $v;
    }

    /**
     * @return array<string, mixed>
     */
    private function readHours(Request $request): array
    {
        $raw = $request->request->all('attr_hours');
        if (!empty($raw['none'])) {
            return ['none' => true];
        }

        $out = [];
        foreach (array_keys(AttributeCatalog::HOUR_PLACES) as $p) {
            foreach (array_keys(AttributeCatalog::HOUR_MODALITIES) as $m) {
                $val = (float) ($raw[$p][$m] ?? 0);
                if ($val > 0) {
                    $out[$p][$m] = $val;
                }
            }
        }
        $te = (float) ($raw['te'] ?? 0);
        if ($te > 0) {
            $out['te'] = $te;
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
