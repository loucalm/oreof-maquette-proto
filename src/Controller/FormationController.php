<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Formation;
use App\Maquette\MaquetteBuilder;
use App\Maquette\TemplateApplier;
use App\Repository\FormationRepository;
use App\Repository\NodeTypeRepository;
use App\Repository\StructureTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FormationController extends AbstractController
{
    #[Route('/', name: 'formation_index', methods: ['GET'])]
    public function index(FormationRepository $formations, MaquetteBuilder $builder): Response
    {
        $rows = [];
        foreach ($formations->findAllRecent() as $formation) {
            $roots = $builder->build($formation);
            $rows[] = ['formation' => $formation, 'progress' => $builder->progress($roots)];
        }

        return $this->render('formation/index.html.twig', ['rows' => $rows]);
    }

    #[Route('/formations', name: 'formation_create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em): Response
    {
        $name = trim((string) $request->request->get('name'));
        if ($name === '') {
            $this->addFlash('danger', 'Un nom de formation est requis.');

            return $this->redirectToRoute('formation_index');
        }

        $formation = (new Formation($name))
            ->setDiplome(trim((string) $request->request->get('diplome')) ?: null)
            ->setDomaine(trim((string) $request->request->get('domaine')) ?: null)
            ->setComposante(trim((string) $request->request->get('composante')) ?: null)
            ->setMultiParcours($request->request->getBoolean('multiParcours'))
            // squelette par défaut, entièrement modifiable ensuite
            ->setStructure(['annee', 'semestre', 'ue', 'ec']);

        $em->persist($formation);
        $em->flush();
        $this->addFlash('success', sprintf('Formation « %s » créée. Complétez sa structure.', $name));

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
        ]);
    }

    /** Vue arborescence des parcours (ramification) — formations multi-parcours. */
    #[Route('/formations/{id}/parcours', name: 'formation_parcours_graph', methods: ['GET'])]
    public function parcoursGraph(Formation $formation, \App\Maquette\ParcoursGraph $graph): Response
    {
        return $this->render('formation/parcours_graph.html.twig', [
            'formation' => $formation,
            'graph' => $graph->build($formation),
        ]);
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
    public function settings(Formation $formation, Request $request, EntityManagerInterface $em, NodeTypeRepository $types): Response
    {
        $wasMulti = $formation->isMultiParcours();
        $willMulti = $request->request->getBoolean('multiParcours');

        $formation
            ->setName(trim((string) $request->request->get('name')) ?: $formation->getName())
            ->setDiplome(trim((string) $request->request->get('diplome')) ?: null)
            ->setDomaine(trim((string) $request->request->get('domaine')) ?: null)
            ->setComposante(trim((string) $request->request->get('composante')) ?: null)
            ->setMultiParcours($willMulti)
            ->setEctsTotal($request->request->get('ectsTotal') !== null && $request->request->get('ectsTotal') !== ''
                ? $request->request->getInt('ectsTotal') : null);

        if ($request->request->has('calendarUnit')) {
            $formation->setCalendarUnit($request->request->get('calendarUnit'));
        }
        if ($request->request->has('calendarSpan')) {
            $formation->setCalendarSpan($request->request->get('calendarSpan') !== ''
                ? $request->request->getInt('calendarSpan') : null);
        }

        // propriétés « formation mono-parcours » (portées par le parcours invisible)
        if ($request->request->has('regimes')) {
            $data = $formation->getParametre('structure');
            $data['regimes'] = array_values(array_filter($request->request->all('regimes')));
            $formation->setParametre('structure', $data);
        }

        $em->flush();

        // le passage mono ↔ multi réorganise l'arbre pour que rien ne casse
        if ($wasMulti !== $willMulti) {
            $moved = $this->reshapeParcoursLevel($formation, $willMulti, $types, $em);
            if ($moved) {
                $this->addFlash('success', $willMulti
                    ? 'Multi-parcours : les nœuds racine ont été rangés dans un nouveau parcours.'
                    : 'Mono-parcours : le niveau parcours a été retiré, ses nœuds sont remontés à la racine.');
            }
        }

        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'param' => 'structure']);
    }

    /**
     * Réorganise l'arbre lors du passage mono ↔ multi-parcours.
     *
     * → multi : on emballe toutes les racines « corps » dans un nouveau nœud
     *   Parcours (l'ECTS total de la formation devient sa cible).
     * → mono : on remonte les enfants de tous les parcours à la racine puis on
     *   supprime les nœuds Parcours (DQL en masse pour éviter les cascades).
     *
     * @return bool true si quelque chose a bougé
     */
    private function reshapeParcoursLevel(
        Formation $formation,
        bool $toMulti,
        NodeTypeRepository $types,
        EntityManagerInterface $em,
    ): bool {
        $roots = $formation->getRootNodes();

        if ($toMulti) {
            $bodyRoots = array_values(array_filter($roots, static fn (\App\Entity\Node $n) => !$n->isParcours()));
            $parcoursType = $types->findOneByKey('parcours');
            if ($bodyRoots === [] || $parcoursType === null) {
                return false;
            }

            $parcours = new \App\Entity\Node($parcoursType, $formation->getName());
            $parcours->setFormation($formation);
            $parcours->setPosition(0);
            if ($formation->getEctsTotal() !== null) {
                $parcours->setAttribute('ects', $formation->getEctsTotal());
            }
            $formation->addNode($parcours);
            $em->persist($parcours);

            foreach ($bodyRoots as $i => $r) {
                $r->setParent($parcours);
                $parcours->addChild($r);
                $r->setPosition($i);
            }
            $em->flush();

            return true;
        }

        // → mono
        $parcoursIds = array_values(array_filter(array_map(
            static fn (\App\Entity\Node $n) => $n->isParcours() ? $n->getId() : null,
            $roots,
        )));
        if ($parcoursIds === []) {
            return false;
        }

        if ($formation->getEctsTotal() === null) {
            foreach ($roots as $r) {
                if ($r->isParcours() && $r->getAttribute('ects')) {
                    $formation->setEctsTotal((int) $r->getAttribute('ects'));
                    $em->flush();
                    break;
                }
            }
        }

        // enfants des parcours → racine ; puis suppression des parcours
        $em->createQuery('UPDATE App\Entity\Node n SET n.parent = NULL WHERE n.parent IN (:ids)')
            ->execute(['ids' => $parcoursIds]);
        $em->createQuery('UPDATE App\Entity\Node n SET n.parcoursParent = NULL WHERE n.parcoursParent IN (:ids)')
            ->execute(['ids' => $parcoursIds]);
        $em->createQuery('DELETE App\Entity\Node n WHERE n.id IN (:ids)')
            ->execute(['ids' => $parcoursIds]);

        return true;
    }

    #[Route('/formations/{id}/apply-template', name: 'formation_apply_template', methods: ['POST'])]
    public function applyTemplate(
        Formation $formation,
        Request $request,
        StructureTemplateRepository $templates,
        TemplateApplier $applier,
        EntityManagerInterface $em,
    ): Response {
        $template = $templates->findOneByKey((string) $request->request->get('template'));
        if ($template === null) {
            $this->addFlash('danger', 'Template inconnu.');

            return $this->redirectToRoute('formation_editor', ['id' => $formation->getId()]);
        }

        $applier->apply($formation, $template);
        $em->flush();
        $this->addFlash('success', sprintf('Structure « %s » chargée. Elle reste entièrement modifiable.', $template->getLabel()));

        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'param' => 'structure']);
    }

    /**
     * Enregistre le squelette (chaîne de types) de la formation. Le tableau
     * `chain[]` est la nouvelle liste ordonnée de clés de type ; l'ajout, le
     * retrait et le glisser-déposer postent tous la liste complète.
     */
    #[Route('/formations/{id}/structure', name: 'formation_structure_save', methods: ['POST'])]
    public function structureSave(Formation $formation, Request $request, EntityManagerInterface $em): Response
    {
        $formation->setStructure(array_map('strval', (array) $request->request->all('chain')));
        $em->flush();

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
            'nodeCount' => $key === 'structure' ? $formation->getNodes()->count() : 0,
            'templates' => $templates->findAllOrdered(),
        ]);
    }

    #[Route('/formations/{id}/parametre/{key}', name: 'formation_param_save', methods: ['POST'])]
    public function paramSave(Formation $formation, string $key, Request $request, EntityManagerInterface $em): Response
    {
        if (!isset(self::PARAM_SECTIONS[$key])) {
            throw $this->createNotFoundException();
        }

        // le bloc « Informations globale » de "organisation" édite l'entité elle-même
        if ($key === 'organisation') {
            $formation
                ->setName(trim((string) $request->request->get('name')) ?: $formation->getName())
                ->setDiplome(trim((string) $request->request->get('diplome')) ?: null)
                ->setDomaine(trim((string) $request->request->get('domaine')) ?: null)
                ->setComposante(trim((string) $request->request->get('composante')) ?: null);
        }

        $data = $request->request->all('p');
        $formation->setParametre($key, array_filter($data, static fn ($v) => $v !== '' && $v !== null));
        $em->flush();
        $this->addFlash('success', sprintf('« %s » enregistré.', self::PARAM_SECTIONS[$key]['label']));

        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'param' => $key]);
    }

    #[Route('/formations/{id}/reset', name: 'formation_reset', methods: ['POST'])]
    public function reset(Formation $formation, EntityManagerInterface $em): Response
    {
        foreach ($formation->getRootNodes() as $root) {
            $em->remove($root);
        }
        $em->flush();
        $this->addFlash('info', 'Structure vidée.');

        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'param' => 'structure']);
    }
}
