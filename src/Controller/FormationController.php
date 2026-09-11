<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Formation;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Maquette\MaquetteBuilder;
use App\Maquette\TemplateApplier;
use App\Repository\FormationRepository;
use App\Repository\NodeTypeRepository;
use App\Repository\StructureTemplateRepository;
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
    ) {
    }

    #[Route('/', name: 'formation_index', methods: ['GET'])]
    public function index(FormationRepository $formations, \App\Maquette\Completion $completion): Response
    {
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

        return $this->render('formation/index.html.twig', ['rows' => $rows]);
    }

    #[Route('/formations', name: 'formation_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        StructureTemplateRepository $templates,
        TemplateApplier $applier,
    ): Response {
        $name = trim((string) $request->request->get('name'));
        if ($name === '') {
            $this->addFlash('danger', 'Un nom de formation est requis.');

            return $this->redirectToRoute('formation_index');
        }

        $diplome = trim((string) $request->request->get('diplome')) ?: null;

        $formation = (new Formation($name))
            ->setDiplome($diplome)
            ->setDomaine(trim((string) $request->request->get('domaine')) ?: null)
            ->setComposante(trim((string) $request->request->get('composante')) ?: null)
            ->setMultiParcours($request->request->getBoolean('multiParcours'))
            ->setStructure(['annee', 'semestre', 'ue', 'ec']);

        $em->persist($formation);
        $em->flush();

        // structure imposée par le diplôme, s'il en existe une
        $template = $diplome !== null ? $templates->findOneByDiplome($diplome) : null;
        if ($template !== null) {
            $doc = $this->maquette->open($formation);
            $applier->apply($doc, $template);
            $this->maquette->save($formation, $doc);
            $this->addFlash('success', sprintf(
                'Formation « %s » créée. La structure imposée par le diplôme %s (« %s ») a été appliquée — ses nœuds verrouillés 🔒 ne peuvent pas être supprimés.',
                $name, $diplome, $template->getLabel(),
            ));
        } else {
            $this->addFlash('success', sprintf('Formation « %s » créée. Complétez sa structure.', $name));
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
    public function export(Formation $formation, Request $request, \App\Maquette\MaquetteExporter $exporter): Response
    {
        $view = $request->query->get('view') === 'enriched' ? 'enriched' : 'raw';
        $data = $view === 'enriched' ? $exporter->enriched($formation) : $exporter->raw($formation);

        return $this->render('formation/export.html.twig', [
            'formation' => $formation,
            'view' => $view,
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
            'revisions' => $this->maquette->history($formation),
        ]);
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
    public function parcoursGraph(Request $request, Formation $formation, \App\Maquette\ParcoursGraph $graph): Response
    {
        return $this->render('formation/parcours_graph.html.twig', [
            'formation' => $formation,
            'graph' => $graph->build($formation),
            // Chargé dans la frame « node-panel » de l'éditeur → fragment seul.
            // Ouvert directement (lien de la consultation, URL) → page complète.
            'standalone' => 'node-panel' !== $request->headers->get('Turbo-Frame'),
        ]);
    }

    // ─── éditeur d'un parcours (2e échelle, formations multi-parcours) ───

    /** Sections « Paramètre du parcours » (portées par le nœud parcours). */
    public const PARCOURS_PARAM_SECTIONS = [
        'organisation' => 'Organisation et localisation',
        'presentation' => 'Présentation',
    ];

    /**
     * Clés requises par section (pour la pastille de statut). Le détail des
     * champs et leur rendu vivent dans les gabarits parcours_<section>.html.twig.
     *
     * @var array<string, list<string>>
     */
    public const PARCOURS_PARAM_FIELDS = [
        'organisation' => [
            'modalitesEnseignement', 'composante', 'regimes', 'modalitesAlternance',
            'lieu', 'respParcours',
        ],
        'presentation' => [
            'objectif', 'motsCles', 'resultats', 'contenu', 'langue', 'niveauLangue',
            'poursuiteEtudes', 'debouches', 'codesRome',
        ],
    ];

    public static function parcoursParamStatus(TreeNode $parcours, string $key): string
    {
        $data = $parcours->getParametre($key);
        $keys = self::PARCOURS_PARAM_FIELDS[$key] ?? [];

        $filled = \count(array_filter($keys, static function (string $k) use ($data): bool {
            $v = $data[$k] ?? null;

            return \is_array($v) ? $v !== [] : trim((string) $v) !== '';
        }));
        // section « organisation » : le volume d'ECTS est porté par le nœud
        $total = \count($keys);
        if ($key === 'organisation') {
            ++$total;
            $filled += $parcours->getAttribute('ects') ? 1 : 0;
        }

        if ($filled === 0) {
            return 'empty';
        }

        return $filled === $total ? 'ok' : 'incomplete';
    }

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
        ]);
    }

    #[Route('/formations/{fid}/parcours/{nid}/parametre/{key}', name: 'parcours_param', methods: ['GET'])]
    public function parcoursParam(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid, string $key): Response
    {
        if (!isset(self::PARCOURS_PARAM_SECTIONS[$key])) {
            throw $this->createNotFoundException();
        }
        $node = $this->parcoursNode($formation, $nid);

        return $this->render("formation/param/parcours_$key.html.twig", [
            'formation' => $formation,
            'parcours' => $node,
            'key' => $key,
            'label' => self::PARCOURS_PARAM_SECTIONS[$key],
            'data' => $node->getParametre($key),
            'saveUrl' => $this->generateUrl('parcours_param_save', ['fid' => $formation->getId(), 'nid' => $nid, 'key' => $key]),
        ]);
    }

    #[Route('/formations/{fid}/parcours/{nid}/parametre/{key}', name: 'parcours_param_save', methods: ['POST'])]
    public function parcoursParamSave(
        #[MapEntity(mapping: ['fid' => 'id'])] Formation $formation,
        string $nid,
        string $key,
        Request $request,
    ): Response {
        if (!isset(self::PARCOURS_PARAM_SECTIONS[$key])) {
            throw $this->createNotFoundException();
        }
        $doc = $this->maquette->open($formation);
        $node = $this->pickParcours($formation, $nid);

        $data = $node->getParametre($key);
        foreach ($request->request->all('p') as $k => $v) {
            $data[$k] = \is_string($v) ? trim($v) : $v;
        }
        if ($request->request->has('regimes')) {
            $data['regimes'] = array_values(array_filter($request->request->all('regimes')));
        }
        $node->setParametre($key, array_filter(
            $data,
            static fn ($v) => $v !== '' && $v !== null && $v !== [],
        ));

        // section « organisation » : le nom et le volume d'ECTS sont portés par
        // le nœud parcours lui-même.
        if ($key === 'organisation') {
            $nom = trim((string) $request->request->get('nom'));
            if ($nom !== '') {
                $node->setLabel($nom);
            }
            if ($request->request->has('ects')) {
                $ects = $request->request->get('ects');
                $node->setAttribute('ects', ($ects === '' || $ects === null) ? null : (int) $ects);
            }
        }

        $this->maquette->save($formation, $doc);
        $this->addFlash('success', sprintf('« %s » enregistré.', self::PARCOURS_PARAM_SECTIONS[$key]));

        return $this->redirectToRoute('parcours_editor', ['fid' => $formation->getId(), 'nid' => $nid, 'param' => $key]);
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
    public function check(Formation $formation, MaquetteBuilder $builder): Response
    {
        $roots = $builder->build($formation);

        return $this->render('formation/check.html.twig', [
            'formation' => $formation,
            'issues' => $builder->collectIssues($roots),
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
        StructureTemplateRepository $templates,
    ): Response {
        $roots = $builder->build($formation);

        return $this->render('formation/editor.html.twig', [
            'formation' => $formation,
            'roots' => $roots,
            'progress' => $builder->progress($roots),
            'types' => $types->findAllOrdered(),
            'templates' => $templates->findAllOrdered(),
        ]);
    }

    #[Route('/formations/{id}/settings', name: 'formation_settings', methods: ['POST'])]
    public function settings(Formation $formation, Request $request, EntityManagerInterface $em): Response
    {
        $wasMulti = $formation->isMultiParcours();
        $willMulti = $request->request->getBoolean('multiParcours');

        $doc = $this->maquette->open($formation);

        $formation
            ->setName(trim((string) $request->request->get('name')) ?: $formation->getName())
            ->setDiplome(trim((string) $request->request->get('diplome')) ?: null)
            ->setDomaine(trim((string) $request->request->get('domaine')) ?: null)
            ->setComposante(trim((string) $request->request->get('composante')) ?: null)
            ->setMultiParcours($willMulti);

        // seuls les formulaires qui exposent le champ y touchent (le bouton radio
        // mono/multi ne le poste pas → ne doit pas remettre l'ECTS total à zéro)
        if ($request->request->has('ectsTotal')) {
            $formation->setEctsTotal($request->request->get('ectsTotal') !== ''
                ? $request->request->getInt('ectsTotal') : null);
        }

        if ($request->request->has('calendarUnit')) {
            $formation->setCalendarUnit($request->request->get('calendarUnit'));
        }
        if ($request->request->has('calendarSpan')) {
            $formation->setCalendarSpan($request->request->get('calendarSpan') !== ''
                ? $request->request->getInt('calendarSpan') : null);
        }

        // propriétés « formation mono-parcours » rangées dans parametres.structure
        if ($request->request->has('regimes') || $request->request->has('p')) {
            $data = $doc->formationParam('structure');
            if ($request->request->has('regimes')) {
                $data['regimes'] = array_values(array_filter($request->request->all('regimes')));
            }
            foreach ($request->request->all('p') as $k => $v) {
                $data[$k] = \is_string($v) ? trim($v) : $v;
            }
            $doc->setFormationParam('structure', array_filter(
                $data,
                static fn ($v) => $v !== '' && $v !== null && $v !== [],
            ));
        }

        // le passage mono ↔ multi réorganise l'arbre pour que rien ne casse
        if ($wasMulti !== $willMulti) {
            $moved = $this->reshapeParcoursLevel($formation, $doc, $willMulti);
            if ($moved) {
                $this->addFlash('success', $willMulti
                    ? 'Multi-parcours : les nœuds racine ont été rangés dans un nouveau parcours.'
                    : 'Mono-parcours : le niveau parcours a été retiré, ses nœuds sont remontés à la racine.');
            }
        }

        $this->maquette->save($formation, $doc);

        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'param' => 'structure']);
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
            if ($bodyRoots === [] || $doc->type('parcours') === null) {
                return false;
            }

            $parcours = $doc->addNode(null, 'parcours', $formation->getName());
            if ($formation->getEctsTotal() !== null) {
                $parcours->setAttribute('ects', $formation->getEctsTotal());
            }
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

        foreach ($parcours as $p) {
            foreach ($p->getChildren() as $child) { // snapshot : moveNode reconstruit children
                $doc->moveNode($child, null, PHP_INT_MAX);
            }
            $doc->removeNode($p);
        }

        return true;
    }

    #[Route('/formations/{id}/apply-template', name: 'formation_apply_template', methods: ['POST'])]
    public function applyTemplate(
        Formation $formation,
        Request $request,
        StructureTemplateRepository $templates,
        TemplateApplier $applier,
    ): Response {
        $template = $templates->findOneByKey((string) $request->request->get('template'));
        if ($template === null) {
            $this->addFlash('danger', 'Template inconnu.');

            return $this->redirectToRoute('formation_editor', ['id' => $formation->getId()]);
        }

        $doc = $this->maquette->open($formation);
        $applier->apply($doc, $template);
        $this->maquette->save($formation, $doc);
        $this->addFlash('success', sprintf('Structure « %s » chargée. Elle reste entièrement modifiable.', $template->getLabel()));

        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'param' => 'structure']);
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

    public const PARAM_SECTIONS = [
        'organisation' => ['label' => 'Organisation et localisation', 'requiredKeys' => ['niveauEntree', 'niveauSortie', 'respMention']],
        'presentation' => ['label' => 'Présentation', 'requiredKeys' => ['objectif', 'resultats', 'contenu']],
        'structure' => ['label' => 'Configuration de la structure', 'requiredKeys' => []],
    ];

    /**
     * Schéma d'affichage lecture seule des sections « Paramètre de la formation ».
     * `entity` = valeur lue sur l'entité (getX), sinon dans parametres[section].
     * `long` = texte multi-lignes. La section « structure » a un rendu dédié.
     *
     * @var array<string, list<array{key: string, label: string, entity?: bool, long?: bool}>>
     */
    public const PARAM_FIELDS = [
        'organisation' => [
            ['key' => 'name', 'label' => 'Nom de la formation', 'entity' => true],
            ['key' => 'diplome', 'label' => 'Type de diplôme', 'entity' => true],
            ['key' => 'domaine', 'label' => 'Domaine de formation', 'entity' => true],
            ['key' => 'composante', 'label' => 'Composante porteuse de la formation', 'entity' => true],
            ['key' => 'contacts', 'label' => 'Contacts de la formation', 'long' => true],
            ['key' => 'mention', 'label' => 'Mention / spécialité'],
            ['key' => 'niveauEntree', 'label' => "Niveau d'entrée en formation"],
            ['key' => 'niveauSortie', 'label' => 'Niveau de sortie de la formation'],
            ['key' => 'rncp', 'label' => 'Inscrite au RNCP ?'],
            ['key' => 'codeRncp', 'label' => 'Code RNCP'],
            ['key' => 'codeApogee', 'label' => 'Code Apogée de la mention'],
            ['key' => 'respMention', 'label' => 'Responsable de la mention'],
            ['key' => 'coRespMention', 'label' => 'Co-responsable de la mention'],
        ],
        'presentation' => [
            ['key' => 'objectif', 'label' => 'Objectif de la formation', 'long' => true],
            ['key' => 'resultats', 'label' => 'Résultats attendus de la formation', 'long' => true],
            ['key' => 'contenu', 'label' => 'Contenu de la formation', 'long' => true],
            ['key' => 'rythme', 'label' => 'Rythme de la formation'],
            ['key' => 'rythmePrecision', 'label' => 'Précision du rythme de formation', 'long' => true],
        ],
    ];

    /**
     * Statut d'une section « Paramètre de la formation » (pastille de l'arbre).
     */
    public static function paramStatus(Formation $formation, string $key): string
    {
        if ($key === 'structure') {
            return $formation->getStructure() === [] ? 'empty' : 'ok';
        }
        $required = self::PARAM_SECTIONS[$key]['requiredKeys'] ?? [];
        if ($key === 'organisation') {
            // le nom + composante viennent de l'entité
            $baseOk = trim((string) $formation->getComposante()) !== '' && trim((string) ($formation->getDomaine() ?? '')) !== '';
        } else {
            $baseOk = true;
        }
        $data = $formation->getParametre($key);
        $filled = array_filter($required, static fn ($k) => trim((string) ($data[$k] ?? '')) !== '');

        if ($data === [] && !$baseOk) {
            return 'empty';
        }

        return (\count($filled) === \count($required) && $baseOk) ? 'ok' : 'incomplete';
    }

    #[Route('/formations/{id}/parametre/{key}', name: 'formation_param', methods: ['GET'])]
    public function param(
        Formation $formation,
        string $key,
        StructureTemplateRepository $templates,
    ): Response {
        if (!isset(self::PARAM_SECTIONS[$key])) {
            throw $this->createNotFoundException();
        }

        return $this->render("formation/param/$key.html.twig", [
            'formation' => $formation,
            'key' => $key,
            'label' => self::PARAM_SECTIONS[$key]['label'],
            'data' => $formation->getParametre($key),
            // seulement pour "structure"
            'nodeCount' => $key === 'structure' ? \count($this->maquette->open($formation)->allNodes()) : 0,
            'templates' => $templates->findAllOrdered(),
        ]);
    }

    #[Route('/formations/{id}/parametre/{key}', name: 'formation_param_save', methods: ['POST'])]
    public function paramSave(Formation $formation, string $key, Request $request): Response
    {
        if (!isset(self::PARAM_SECTIONS[$key])) {
            throw $this->createNotFoundException();
        }
        $doc = $this->maquette->open($formation);

        // le bloc « Informations globale » de "organisation" édite l'entité elle-même
        if ($key === 'organisation') {
            $formation
                ->setName(trim((string) $request->request->get('name')) ?: $formation->getName())
                ->setDiplome(trim((string) $request->request->get('diplome')) ?: null)
                ->setDomaine(trim((string) $request->request->get('domaine')) ?: null)
                ->setComposante(trim((string) $request->request->get('composante')) ?: null);
        }

        $data = $request->request->all('p');
        $doc->setFormationParam($key, array_filter($data, static fn ($v) => $v !== '' && $v !== null));
        $this->maquette->save($formation, $doc);
        $this->addFlash('success', sprintf('« %s » enregistré.', self::PARAM_SECTIONS[$key]['label']));

        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'param' => $key]);
    }

    #[Route('/formations/{id}/reset', name: 'formation_reset', methods: ['POST'])]
    public function reset(Formation $formation): Response
    {
        $doc = $this->maquette->open($formation);
        foreach ($doc->pedagogicalRoots() as $root) {
            $doc->removeNode($root);
        }
        $this->maquette->save($formation, $doc);
        $this->addFlash('info', 'Structure vidée.');

        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'param' => 'structure']);
    }
}
