<?php

declare(strict_types=1);

namespace App\Controller;

use App\Diplome\FormationCreator;
use App\Diplome\TypeDiplomeResolver;
use App\Entity\Formation;
use App\Enum\FormationPhase;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Maquette\MaquetteBuilder;
use App\Repository\FormationRepository;
use App\Repository\NodeTypeRepository;
use App\Repository\TypeDiplomeRepository;
use App\Twig\RoleExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FormationController extends AbstractController
{
    public function __construct(
        private readonly Maquette $maquette,
        private readonly RoleExtension $roles,
        private readonly TypeDiplomeResolver $typeResolver,
        private readonly \App\Formulaire\Fiche\ParcoursFicheTransfer $parcoursTransfer,
    ) {
    }

    #[Route('/', name: 'formation_index', methods: ['GET'])]
    public function index(
        Request $request,
        FormationRepository $formations,
        \App\Maquette\Completion $completion,
        FormationCreator $creator,
    ): Response {
        // l'ancien onglet « Consolidation de l'offre » est devenu la gestion de l'offre
        if (FormationPhase::Consolidation->value === $request->query->get('phase')) {
            return $this->redirectToRoute('offre_index');
        }

        $rows = [];
        foreach ($formations->findAllRecent() as $formation) {
            // cache de progression (Maquette::save) — pas de reconstruction d'arbre ici.
            // Amorçage : une formation jamais enregistrée (fixtures) est calculée à la volée.
            $stats = $formation->getStats();
            if ($stats === []) {
                $stats = $completion->docStats($this->maquette->open($formation));
            }

            $rows[] = [
                'formation' => $formation,
                'progress' => $stats['progress'] ?? 0,
                'parcours' => $stats['parcours'] ?? [],
            ];
        }

        return $this->render('formation/index.html.twig', [
            'creationTypes' => $creator->creationTypes(),
            'defaultYear' => TypeDiplomeResolver::DEFAULT_YEAR,
            'rows' => $rows,
        ]);
    }

    #[Route('/formations', name: 'formation_create', methods: ['POST'])]
    public function create(Request $request, TypeDiplomeRepository $typeDiplomes, FormationCreator $creator): Response
    {
        // créée depuis la gestion de l'offre : on y revient en cas d'erreur
        $back = 'offre' === $request->request->get('retour') ? 'offre_index' : 'formation_index';
        $name = trim((string) $request->request->get('name'));
        if ($name === '') {
            $this->addFlash('danger', 'Un nom de formation est requis.');

            return $this->redirectToRoute($back);
        }

        $type = $typeDiplomes->findOneByKey((string) $request->request->get('typeDiplome'));
        if (null === $type) {
            $this->addFlash('danger', 'Choisissez un type de diplôme.');

            return $this->redirectToRoute($back);
        }

        try {
            $formation = $creator->create(
                $name,
                $type,
                trim((string) $request->request->get('anneeUniversitaire')) ?: TypeDiplomeResolver::DEFAULT_YEAR,
                $request->request->getInt('duree'),
                $request->request->getBoolean('avecParcours'),
                FormationPhase::tryFrom((string) $request->request->get('phase', '')) ?? FormationPhase::Construction,
                trim((string) $request->request->get('domaine')) ?: null,
                trim((string) $request->request->get('composante')) ?: null,
            );
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute($back);
        }

        $this->addFlash('success', sprintf(
            'Formation « %s » créée d\'après le type de diplôme %s : sa structure de départ respecte les bornes du diplôme.',
            $name, $type->getLabel(),
        ));

        // formation créée en consolidation : ses informations principales se règlent dans la gestion de l'offre
        if (FormationPhase::Consolidation === $formation->getPhase()) {
            return $this->redirectToRoute('offre_configure', ['id' => $formation->getId()]);
        }

        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId()]);
    }

    #[Route('/formations/{id}/voir', name: 'formation_view', methods: ['GET'])]
    public function view(Formation $formation, MaquetteBuilder $builder, \App\Maquette\AttributeCatalog $catalog): Response
    {
        $roots = $builder->build($formation);

        return $this->render('formation/view.html.twig', [
            'formation' => $formation,
            'roots' => $roots,
            'progress' => $builder->progress($roots),
            'catalog' => $catalog->all(),
            'domains' => $catalog->domains(),
        ]);
    }

    #[Route('/formations/{id}/export', name: 'formation_export', methods: ['GET'])]
    public function export(Formation $formation, Request $request, \App\Maquette\MaquetteExporter $exporter, \App\Export\Mccc\McccTableBuilder $mccc): Response
    {
        $view = $request->query->get('view') === 'enriched' ? 'enriched' : 'raw';
        $data = $view === 'enriched' ? $exporter->enriched($formation) : $exporter->raw($formation);

        return $this->render('formation/export.html.twig', [
            'formation' => $formation,
            'view' => $view,
            'mcccSheetLevels' => $mccc->sheetLevels($formation),
            'mcccParcours' => $formation->isAvecParcours() ? $mccc->parcoursChoices($formation) : [],
            'json' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    #[Route('/formations/{id}/export.json', name: 'formation_export_json', methods: ['GET'])]
    public function exportJson(Formation $formation, Request $request, \App\Maquette\MaquetteExporter $exporter): Response
    {
        $enriched = $request->query->get('view') === 'enriched';
        $data = $enriched ? $exporter->enriched($formation) : $exporter->raw($formation);

        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($formation->getName())) ?: 'maquette';
        $name = sprintf('%s-%s.json', trim($slug, '-'), $enriched ? 'enrichi' : 'brut');

        $response = new \Symfony\Component\HttpFoundation\JsonResponse($data);
        $response->setEncodingOptions($response->getEncodingOptions() | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $name));

        return $response;
    }

    /** Historique des modifications (filet de sécurité, cf. App\Maquette\Maquette::save). */
    #[Route('/formations/{id}/history', name: 'formation_history', methods: ['GET'])]
    public function history(Formation $formation): Response
    {
        return $this->render('formation/history.html.twig', [
            'formation' => $formation,
            'rows' => $this->maquette->historyWithStatus($formation),
        ]);
    }

    /** Annuler / Rétablir rapides (bandeau d'actions) — un seul niveau de rétablissement. */
    #[Route('/formations/{id}/undo', name: 'formation_undo', methods: ['POST'])]
    public function undo(Formation $formation, Request $request): Response
    {
        $ok = $this->maquette->undo($formation);
        $this->addFlash($ok ? 'success' : 'info', $ok ? 'Dernière modification annulée.' : 'Rien à annuler.');

        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('formation_editor', ['id' => $formation->getId()]));
    }

    #[Route('/formations/{id}/redo', name: 'formation_redo', methods: ['POST'])]
    public function redo(Formation $formation, Request $request): Response
    {
        $ok = $this->maquette->redo($formation);
        $this->addFlash($ok ? 'success' : 'info', $ok ? 'Modification rétablie.' : 'Rien à rétablir.');

        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('formation_editor', ['id' => $formation->getId()]));
    }

    #[Route('/formations/{id}/history/{revisionId}/restore', name: 'formation_history_restore', methods: ['POST'])]
    public function historyRestore(
        Formation $formation,
        #[MapEntity(mapping: ['revisionId' => 'id'])] \App\Entity\FormationRevision $revision,
    ): Response {
        if ($revision->getFormation() !== $formation) {
            throw $this->createNotFoundException();
        }

        $this->maquette->restore($formation, $revision);
        $this->addFlash('success', sprintf('État du %s restauré.', $revision->getCreatedAt()->format('d/m/Y à H:i')));

        return $this->redirectToRoute('formation_history', ['id' => $formation->getId()]);
    }

    /** Vue arborescence des parcours (ramification) — formations multi-parcours. */
    #[Route('/formations/{id}/parcours', name: 'formation_parcours_graph', methods: ['GET'])]
    public function parcoursGraph(Request $request, Formation $formation, \App\Maquette\ParcoursGraph $graph, MaquetteBuilder $builder): Response
    {
        // progression et ECTS de chaque parcours, affichés dans sa boîte
        $parcoursViews = [];
        $progress = [];
        foreach ($builder->build($formation) as $view) {
            if ($view->node->isParcours()) {
                $parcoursViews[$view->node->getId()] = $view;
                $progress[$view->node->getId()] = $builder->progress([$view]);
            }
        }

        return $this->render('formation/parcours_graph.html.twig', [
            'formation' => $formation,
            'parcoursViews' => $parcoursViews,
            'parcoursProgress' => $progress,
            'graph' => $graph->build($formation),
            // Parcours d'où l'on vient (lien depuis son propre panneau « Positionnement
            // & ramification ») — mis en surbrillance dans l'arborescence.
            'current' => $request->query->get('current'),
            // Chargé dans la frame « node-panel » de l'éditeur → fragment seul.
            // Ouvert directement (lien de la consultation, URL) → page complète.
            'standalone' => 'node-panel' !== $request->headers->get('Turbo-Frame'),
        ]);
    }

    // ─── éditeur d'un parcours (2e échelle, formations multi-parcours) ───

    #[Route('/formations/{fid}/parcours/{nid}', name: 'parcours_editor', methods: ['GET'])]
    public function parcoursEditor(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid, MaquetteBuilder $builder): Response
    {
        $node = $this->parcoursNode($formation, $nid);
        $view = $builder->buildSubtree($node);

        return $this->render('formation/parcours_editor.html.twig', [
            'formation' => $formation,
            'parcours' => $node,
            'roots' => $view->children,
            'progress' => $builder->progress($view->children),
            'canUndo' => $this->maquette->canUndo($formation),
            'canRedo' => $this->maquette->canRedo($formation),
        ]);
    }

    private function parcoursNode(Formation $formation, string $nid): TreeNode
    {
        return $this->pickParcours($formation, $nid);
    }

    private function pickParcours(Formation $formation, string $nid): TreeNode
    {
        $node = $this->maquette->open($formation)->node($nid);
        if ($node === null || !$node->isParcours()) {
            throw $this->createNotFoundException();
        }

        return $node;
    }

    #[Route('/formations/{id}/verifier', name: 'formation_check', methods: ['GET'])]
    public function check(
        Formation $formation,
        MaquetteBuilder $builder,
        \App\Maquette\Completion $completion,
        \App\Formulaire\FicheNavigation $navigation,
        \Symfony\Contracts\Translation\TranslatorInterface $translator,
    ): Response
    {
        $roots = $builder->build($formation);
        $doc = $this->maquette->open($formation);

        // entrées incomplètes des fiches (formation, puis chaque parcours), d'après le canevas de la version
        $sections = [];
        foreach ($navigation->items($formation) as $item) {
            if (null !== $item->status && 'ok' !== $item->status) {
                $context = null !== $item->group ? $translator->trans('formation.editor.nav_group.'.$item->group, [], 'formation') : null;
                $sections[] = ['label' => $item->label, 'context' => $context, 'status' => $item->status,
                    'url' => $this->generateUrl('formation_editor', ['id' => $formation->getId(), 'param' => \App\Formulaire\FicheNavigation::navKey($item)])];
            }
        }
        foreach ($doc->parcoursNodes() as $p) {
            foreach ($navigation->items($formation, $p) as $item) {
                if (null !== $item->status && 'ok' !== $item->status) {
                    $sections[] = ['label' => $item->label, 'context' => $p->getDisplayLabel(), 'status' => $item->status,
                        'url' => $this->generateUrl('parcours_editor', ['fid' => $formation->getId(), 'nid' => $p->getId(), 'param' => $item->key])];
                }
            }
        }

        // anomalies regroupées par contexte (ex. « Année 1 › Semestre 1 »)
        $groups = [];
        foreach ($builder->collectIssues($roots) as $issue) {
            $groups[$issue['path'] ?: $formation->getName()][] = $issue;
        }

        // écarts à la version du type de diplôme (aucun sans version)
        $violations = array_map(static fn (\App\Diplome\Check\StructureViolation $v): array => [
            'violation' => $v,
            'node' => $doc->node($v->scopeNid),
        ], $completion->structureViolations($doc));

        return $this->render('formation/check.html.twig', [
            'formation' => $formation,
            'violations' => $violations,
            'groups' => $groups,
            'issueCount' => array_sum(array_map('count', $groups)),
            'sections' => $sections,
            'progress' => $builder->progress($roots),
        ]);
    }

    #[Route('/formations/{id}/supprimer', name: 'formation_delete', methods: ['POST'])]
    public function delete(Formation $formation, EntityManagerInterface $em): Response
    {
        $name = $formation->getName();
        $this->maquette->deleteHistory($formation);
        $em->remove($formation);
        $em->flush();
        $this->addFlash('info', sprintf('Formation « %s » supprimée.', $name));

        return $this->redirectToRoute('formation_index');
    }

    #[Route('/formations/{id}', name: 'formation_editor', methods: ['GET'])]
    public function editor(
        Formation $formation,
        MaquetteBuilder $builder,
        NodeTypeRepository $types,
    ): Response {
        $roots = $builder->build($formation);

        return $this->render('formation/editor.html.twig', [
            'formation' => $formation,
            'roots' => $roots,
            'progress' => $builder->progress($roots),
            'types' => $types->findAllOrdered(),
            'canUndo' => $this->maquette->canUndo($formation),
            'canRedo' => $this->maquette->canRedo($formation),
        ]);
    }

    #[Route('/formations/{id}/settings', name: 'formation_settings', methods: ['POST'])]
    public function settings(Formation $formation, Request $request, EntityManagerInterface $em): Response
    {
        $wasMulti = $formation->isAvecParcours();
        $willMulti = $request->request->getBoolean('avecParcours');

        // hors admin, le type de diplôme fige avec/sans parcours et la dimension temporelle ;
        // un type sans parcours n'en accepte pour personne
        $version = $this->typeResolver->versionFor($formation);
        $lockedByType = !$this->roles->isAdmin() && null !== $version;
        if ($lockedByType) {
            $willMulti = $wasMulti;
        }
        if ($willMulti && !$wasMulti && null !== $version && !$version->config()->structure->parcoursAllowed) {
            $this->addFlash('danger', sprintf('Le type de diplôme %s n’autorise pas les parcours.', $formation->getDiplome()));

            return $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'param' => 'structure']);
        }

        $doc = $this->maquette->open($formation);

        $formation
            ->setName(trim((string) $request->request->get('name')) ?: $formation->getName())
            ->setDomaine(trim((string) $request->request->get('domaine')) ?: null)
            ->setComposante(trim((string) $request->request->get('composante')) ?: null)
            ->setAvecParcours($willMulti);

        // seuls les formulaires qui exposent le champ y touchent (le bouton radio
        // mono/multi ne le poste pas → ne doit pas remettre l'ECTS total à zéro)
        if ($request->request->has('ectsTotal')) {
            $formation->setEctsTotal($request->request->get('ectsTotal') !== ''
                ? $request->request->getInt('ectsTotal') : null);
        }

        if (!$lockedByType && $request->request->has('calendarUnit')) {
            $formation->setCalendarUnit($request->request->get('calendarUnit'));
        }
        if (!$lockedByType && $request->request->has('calendarSpan')) {
            $formation->setCalendarSpan($request->request->get('calendarSpan') !== ''
                ? $request->request->getInt('calendarSpan') : null);
        }

        // le passage mono ↔ multi réorganise l'arbre pour que rien ne casse
        if ($wasMulti !== $willMulti) {
            $moved = $this->reshapeParcoursLevel($formation, $doc, $willMulti);
            if ($moved) {
                $this->addFlash('success', $willMulti
                    ? 'Multi-parcours : les éléments pédagogiques racine ont été rangés dans un nouveau parcours.'
                    : 'Mono-parcours : le niveau parcours a été retiré, ses éléments pédagogiques sont remontés à la racine.');
            }
        }

        $this->maquette->save($formation, $doc);

        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'param' => 'structure']);
    }

    /** Bascule la phase (consolidation ↔ construction) — « Basculer en construction » de l'éditeur. */
    #[Route('/formations/{id}/phase', name: 'formation_phase_save', methods: ['POST'])]
    public function phaseSave(Formation $formation, Request $request, EntityManagerInterface $em): Response
    {
        $phase = FormationPhase::tryFrom((string) $request->request->get('phase', ''));
        if (null !== $phase) {
            $formation->setPhase($phase);
            $em->flush();
            $this->addFlash('success', $phase === FormationPhase::Construction
                ? 'Formation passée en construction.'
                : 'Formation repassée en consolidation.');
        }

        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId()]);
    }

    /** Passe en construction les formations données (gestion de l'offre). */
    #[Route('/formations/phase/construction', name: 'formation_phase_batch', methods: ['POST'], priority: 10)]
    public function phaseBatch(Request $request, FormationRepository $formations, EntityManagerInterface $em): Response
    {
        $ids = array_map('intval', $request->request->all('ids'));
        $count = 0;
        foreach ($ids === [] ? [] : $formations->findBy(['id' => $ids]) as $formation) {
            if (FormationPhase::Consolidation === $formation->getPhase()) {
                $formation->setPhase(FormationPhase::Construction);
                ++$count;
            }
        }
        $em->flush();
        $this->addFlash($count > 0 ? 'success' : 'warning', $count > 0
            ? sprintf('%d formation(s) passée(s) en construction.', $count)
            : 'Aucune formation cochée.');

        return $this->redirectToRoute('offre_index');
    }

    /**
     * Réorganise l'arbre JSON lors du passage mono ↔ multi-parcours.
     *
     * → multi : on emballe les racines « corps » (et le BCC mono) dans un
     *   nouveau nœud Parcours (l'ECTS total de la formation devient sa cible).
     * → mono : on remonte les enfants de tous les parcours à la racine puis on
     *   supprime les nœuds Parcours.
     *
     * @return bool true si quelque chose a bougé
     */
    private function reshapeParcoursLevel(Formation $formation, \App\Maquette\Doc\MaquetteDoc $doc, bool $toMulti): bool
    {
        if ($toMulti) {
            $bodyRoots = array_values(array_filter(
                $doc->roots,
                static fn (TreeNode $n) => !$n->isParcours(),
            ));
            // une formation encore vide reçoit tout de même son parcours : sa fiche y est transférée
            if ($doc->type('parcours') === null || ($bodyRoots === [] && $doc->parcoursNodes() !== [])) {
                return false;
            }

            $parcours = $doc->addNode(null, 'parcours', $formation->getName());
            if ($formation->getEctsTotal() !== null) {
                $parcours->setAttribute('ects', $formation->getEctsTotal());
            }
            // la fiche du parcours unique devient celle du parcours créé
            $this->parcoursTransfer->toNode($formation, $doc, $parcours);
            foreach ($bodyRoots as $r) {
                $doc->moveNode($r, $parcours, PHP_INT_MAX);
            }

            return true;
        }

        // → mono
        $parcours = $doc->parcoursNodes();
        if ($parcours === []) {
            return false;
        }

        if ($formation->getEctsTotal() === null) {
            foreach ($parcours as $p) {
                if ($p->getAttribute('ects')) {
                    $formation->setEctsTotal((int) $p->getAttribute('ects'));
                    break;
                }
            }
        }

        // la fiche du premier parcours devient celle du parcours unique
        $this->parcoursTransfer->fromNode($formation, $doc, $parcours[0]);
        // fiches des autres parcours (et données hors canevas du premier) : conservées, non affichées
        $this->parcoursTransfer->archive($formation, $doc, $parcours, $parcours[0]);

        foreach ($parcours as $p) {
            foreach ($p->getChildren() as $child) { // snapshot : moveNode reconstruit children
                $doc->moveNode($child, null, PHP_INT_MAX);
            }
            $doc->removeNode($p, true); // contenu déjà remonté : un parcours verrouillé ne doit pas rester orphelin
        }

        return true;
    }

    /**
     * Enregistre le squelette (chaîne de types) de la formation.
     */
    #[Route('/formations/{id}/structure', name: 'formation_structure_save', methods: ['POST'])]
    public function structureSave(Formation $formation, Request $request): Response
    {
        $doc = $this->maquette->open($formation);
        $formation->setStructure(array_map('strval', (array) $request->request->all('chain')));
        $this->maquette->save($formation, $doc, 'Squelette de la formation modifié');

        if ($request->isXmlHttpRequest()) {
            return new Response(null, Response::HTTP_NO_CONTENT);
        }

        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'param' => 'structure']);
    }

    /** Écran intégré « Configuration de la structure » (cf. App\Formulaire\Builtin\StructureScreen). */
    #[Route('/formations/{id}/structure', name: 'formation_structure', methods: ['GET'])]
    public function structure(Formation $formation, \App\Repository\DerogationRequestRepository $derogations, \Symfony\Contracts\Translation\TranslatorInterface $translator): Response
    {
        return $this->render('formation/param/structure.html.twig', [
            'formation' => $formation,
            'key' => 'structure',
            'label' => $translator->trans('formation.editor.structure_section_label', [], 'formation'),
            'data' => $formation->getParametre('structure'),
            'nodeCount' => \count($this->maquette->open($formation)->allNodes()),
            // le type de diplôme fige avec/sans parcours et la dimension temporelle (hors admin)
            'lockedByType' => null !== $this->typeResolver->versionFor($formation),
            'derogations' => $derogations->findByFormation($formation),
        ]);
    }

    #[Route('/formations/{id}/reset', name: 'formation_reset', methods: ['POST'])]
    public function reset(Formation $formation): Response
    {
        $doc = $this->maquette->open($formation);
        $kept = 0;
        foreach ($doc->pedagogicalRoots() as $root) {
            if (!$doc->removeNode($root)) {
                ++$kept;
            }
        }
        $this->maquette->save($formation, $doc);
        $this->addFlash(
            $kept > 0 ? 'warning' : 'info',
            $kept > 0
                ? sprintf('Structure vidée, sauf %d élément(s) verrouillé(s) (non supprimables).', $kept)
                : 'Structure vidée.',
        );

        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'param' => 'structure']);
    }
}
