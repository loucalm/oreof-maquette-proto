<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Formation;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Maquette\Numbering;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
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
 * Les nœuds BCC vivent dans le même document `Formation::arbre` que la structure
 * pédagogique (famille « compétence », filtrée à l'affichage). Contexte : la
 * formation en mono-parcours (blocs = racines), un nœud parcours en multi
 * (blocs = enfants du parcours).
 */
final class BccController extends AbstractController
{
    private const BLOC = 'bloc_competences';
    private const COMPETENCE = 'competence';

    public function __construct(
        private readonly Maquette $maquette,
        private readonly Numbering $numbering,
    ) {
    }

    #[Route('/formations/{fid}/bcc', name: 'bcc_editor', methods: ['GET'])]
    public function editor(Request $request, #[MapEntity(mapping: ['fid' => 'id'])] Formation $formation): Response
    {
        return $this->renderEditor($request, $formation, null);
    }

    #[Route('/formations/{fid}/parcours/{nid}/bcc', name: 'bcc_parcours_editor', methods: ['GET'])]
    public function parcoursEditor(Request $request, #[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid): Response
    {
        $parcours = $this->parcours($formation, $nid);

        return $this->renderEditor($request, $formation, $parcours);
    }

    #[Route('/formations/{fid}/bcc/blocs', name: 'bcc_bloc_add', methods: ['POST'])]
    public function addBloc(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, Request $request): Response
    {
        return $this->doAddBloc($request, $formation, null);
    }

    #[Route('/formations/{fid}/parcours/{nid}/bcc/blocs', name: 'bcc_parcours_bloc_add', methods: ['POST'])]
    public function addParcoursBloc(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid, Request $request): Response
    {
        return $this->doAddBloc($request, $formation, $this->parcours($formation, $nid));
    }

    #[Route('/formations/{fid}/nodes/{nid}/bcc/competences', name: 'bcc_competence_add', methods: ['POST'])]
    public function addCompetence(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid, Request $request): Response
    {
        $doc = $this->maquette->open($formation);
        $bloc = $this->pick($doc, $nid);
        if (!$bloc->isBloc()) {
            throw $this->createNotFoundException();
        }

        $label = trim((string) $request->request->get('label')) ?: 'Nouvelle compétence';
        $doc->addNode($bloc->getId(), self::COMPETENCE, $label);
        $this->maquette->save($formation, $doc);
        $this->addFlash('success', 'Compétence ajoutée.');

        return $this->ownerRedirect($bloc);
    }

    #[Route('/formations/{fid}/nodes/{nid}/bcc', name: 'bcc_node_save', methods: ['POST'])]
    public function save(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid, Request $request): Response
    {
        $doc = $this->maquette->open($formation);
        $node = $this->pick($doc, $nid);
        if (!$node->isCompetenceNode()) {
            throw $this->createNotFoundException();
        }

        $node->setLabel(trim((string) $request->request->get('label')) ?: $node->getDisplayLabel());
        $node->setCode(trim((string) $request->request->get('code')) ?: null);
        $node->setAttribute('description', trim((string) $request->request->get('description')));

        $this->maquette->save($formation, $doc);
        $this->addFlash('success', 'Enregistré.');

        return $this->ownerRedirect($node);
    }

    #[Route('/formations/{fid}/nodes/{nid}/bcc/delete', name: 'bcc_node_delete', methods: ['POST'])]
    public function delete(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid): Response
    {
        $doc = $this->maquette->open($formation);
        $node = $this->pick($doc, $nid);
        if (!$node->isCompetenceNode()) {
            throw $this->createNotFoundException();
        }
        $redirect = $this->ownerRedirect($node);
        $isBloc = $node->isBloc();

        $doc->removeNode($node);
        $this->maquette->save($formation, $doc);
        $this->addFlash('info', $isBloc ? 'Bloc supprimé.' : 'Compétence supprimée.');

        return $redirect;
    }

    #[Route('/formations/{fid}/nodes/{nid}/bcc/move', name: 'bcc_move', methods: ['POST'])]
    public function move(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid, Request $request): JsonResponse
    {
        $doc = $this->maquette->open($formation);
        $node = $doc->node($nid);
        if ($node === null || !$node->isCompetenceNode()) {
            return new JsonResponse(['ok' => false, 'error' => 'type'], 422);
        }

        $payload = json_decode($request->getContent() ?: '{}', true) ?: [];
        $index = max(0, (int) ($payload['index'] ?? 0));

        if ($node->isBloc()) {
            if (!empty($payload['parentId'])) {
                return new JsonResponse(['ok' => false, 'error' => 'hierarchy'], 422);
            }
            if ($node->isTransversalBloc()) {
                return new JsonResponse(['ok' => true]); // épinglé en tête
            }
            $doc->reorderRegularBlocs($this->bccContext($node), $node, $index);
        } else {
            $newParent = !empty($payload['parentId']) ? $doc->node((string) $payload['parentId']) : null;
            if ($newParent === null || !$newParent->isBloc()) {
                return new JsonResponse(['ok' => false, 'error' => 'hierarchy'], 422);
            }
            $doc->moveNode($node, $newParent, $index);
        }

        $this->maquette->save($formation, $doc);

        return new JsonResponse(['ok' => true]);
    }

    // ─── helpers ────────────────────────────────────────────────

    private function renderEditor(Request $request, Formation $formation, ?TreeNode $parcours): Response
    {
        $doc = $this->maquette->open($formation);
        $blocs = $parcours !== null ? $parcours->getBccBlocs() : $doc->competenceBlocs();

        return $this->render('formation/bcc.html.twig', [
            'formation' => $formation,
            'parcours' => $parcours,
            'blocs' => $this->buildTree($blocs),
            'hasTransversal' => $this->hasTransversal($blocs),
            'addBlocUrl' => $parcours !== null
                ? $this->generateUrl('bcc_parcours_bloc_add', ['fid' => $formation->getId(), 'nid' => $parcours->getId()])
                : $this->generateUrl('bcc_bloc_add', ['fid' => $formation->getId()]),
            'standalone' => 'node-panel' !== $request->headers->get('Turbo-Frame'),
        ]);
    }

    private function doAddBloc(Request $request, Formation $formation, ?TreeNode $parcours): Response
    {
        $doc = $this->maquette->open($formation);
        $blocs = $parcours !== null ? $parcours->getBccBlocs() : $doc->competenceBlocs();

        $transversal = $request->request->getBoolean('transversal');
        if ($transversal && $this->hasTransversal($blocs)) {
            $this->addFlash('warning', 'Le bloc de compétences transversales existe déjà.');

            return $this->contextRedirect($formation, $parcours);
        }

        // le libellé n'embarque plus « BC N » : la référence « BC 1 » est calculée
        $label = trim((string) $request->request->get('label'))
            ?: ($transversal ? 'Compétences transversales (RNCP)' : 'Nouveau bloc de compétences');

        $bloc = $doc->addNode($parcours?->getId(), self::BLOC, $label);
        if ($transversal) {
            $bloc->setAttribute('transversal', true);
        }
        $this->maquette->save($formation, $doc);
        $this->addFlash('success', $transversal ? 'Bloc transversal ajouté.' : sprintf('« %s » ajouté.', $label));

        return $this->contextRedirect($formation, $parcours);
    }

    /** @param list<TreeNode> $blocs */
    private function hasTransversal(array $blocs): bool
    {
        foreach ($blocs as $b) {
            if ($b->isTransversalBloc()) {
                return true;
            }
        }

        return false;
    }

    /** Contexte BCC d'un nœud : le parcours ancêtre, sinon null (mono). */
    private function bccContext(TreeNode $bccNode): ?TreeNode
    {
        for ($c = $bccNode->getParent(); $c !== null; $c = $c->getParent()) {
            if ($c->isParcours()) {
                return $c;
            }
        }

        return null;
    }

    /**
     * @param list<TreeNode> $blocs
     *
     * @return list<array{node: TreeNode, ref: string, transversal: bool, competences: list<array{node: TreeNode, ref: string}>}>
     */
    private function buildTree(array $blocs): array
    {
        $out = [];
        $regularIdx = 0;
        foreach ($blocs as $bloc) {
            $transversal = $bloc->isTransversalBloc();
            $blocRef = '';
            if (!$transversal && $bloc->getType()->isNumbered()) {
                $blocRef = $this->numbering->format(++$regularIdx, $bloc->getType()->getNumberStyle());
            }

            $comps = [];
            $ci = 0;
            foreach ($bloc->getChildren() as $c) {
                if ($c->getType()->getKey() !== self::COMPETENCE) {
                    continue;
                }
                $ref = '';
                if ($blocRef !== '' && $c->getType()->isNumbered()) {
                    $ref = $blocRef.'.'.$this->numbering->format(++$ci, $c->getType()->getNumberStyle());
                }
                $comps[] = ['node' => $c, 'ref' => $ref];
            }

            $out[] = ['node' => $bloc, 'ref' => $blocRef, 'transversal' => $transversal, 'competences' => $comps];
        }

        return $out;
    }

    private function ownerRedirect(TreeNode $bccNode): Response
    {
        $formation = $bccNode->getFormation();
        for ($c = $bccNode; $c !== null; $c = $c->getParent()) {
            if ($c->isParcours()) {
                return $this->redirectToRoute('parcours_editor', ['fid' => $formation->getId(), 'nid' => $c->getId(), 'bcc' => 1]);
            }
        }

        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'bcc' => 1]);
    }

    private function contextRedirect(Formation $formation, ?TreeNode $parcours): Response
    {
        return $parcours !== null
            ? $this->redirectToRoute('parcours_editor', ['fid' => $formation->getId(), 'nid' => $parcours->getId(), 'bcc' => 1])
            : $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'bcc' => 1]);
    }

    private function parcours(Formation $formation, string $nid): TreeNode
    {
        $node = $this->pick($this->maquette->open($formation), $nid);
        if (!$node->isParcours()) {
            throw $this->createNotFoundException();
        }

        return $node;
    }

    private function pick(MaquetteDoc $doc, string $nid): TreeNode
    {
        $node = $doc->node($nid);
        if ($node === null) {
            throw $this->createNotFoundException();
        }

        return $node;
    }
}
