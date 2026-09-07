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
            ->setComposante(trim((string) $request->request->get('composante')) ?: null)
            ->setMultiParcours($request->request->getBoolean('multiParcours'));

        $em->persist($formation);
        $em->flush();

        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId()]);
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
        $formation
            ->setName(trim((string) $request->request->get('name')) ?: $formation->getName())
            ->setDiplome(trim((string) $request->request->get('diplome')) ?: null)
            ->setComposante(trim((string) $request->request->get('composante')) ?: null)
            ->setMultiParcours($request->request->getBoolean('multiParcours'))
            ->setEctsTotal($request->request->get('ectsTotal') !== null && $request->request->get('ectsTotal') !== ''
                ? $request->request->getInt('ectsTotal') : null);
        $em->flush();
        $this->addFlash('success', 'Paramètres enregistrés.');

        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId()]);
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

        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId()]);
    }

    /**
     * Sections « Paramètre de la formation » de la maquette Figma (organisation,
     * présentation, config structure, BCC). Hors périmètre du prototype :
     * rendu fictif pour rester fidèle à l'UI.
     */
    #[Route('/formations/{id}/parametre/{key}', name: 'formation_param', methods: ['GET'])]
    public function param(Formation $formation, string $key): Response
    {
        $sections = self::PARAM_SECTIONS;

        return $this->render('formation/_param_placeholder.html.twig', [
            'formation' => $formation,
            'key' => $key,
            'label' => $sections[$key]['label'] ?? $key,
            'blurb' => $sections[$key]['blurb'] ?? '',
        ]);
    }

    public const PARAM_SECTIONS = [
        'organisation' => ['label' => 'Organisation et localisation', 'status' => 'incomplete',
            'blurb' => 'Mention/spécialité, niveaux d’entrée et de sortie, RNCP, code Apogée, responsables, localisation.'],
        'presentation' => ['label' => 'Présentation', 'status' => 'empty',
            'blurb' => 'Objectifs, résultats attendus, contenu, rythme, poursuites d’études, débouchés, codes ROME.'],
        'structure' => ['label' => 'Configuration de la structure', 'status' => 'ok',
            'blurb' => 'Mono ou multi-parcours, chargement d’un template de structure.'],
        'bcc' => ['label' => 'BCC', 'status' => 'incomplete',
            'blurb' => 'Référentiel de compétences : blocs (BC) et compétences, compétences transversales RNCP.'],
    ];

    #[Route('/formations/{id}/reset', name: 'formation_reset', methods: ['POST'])]
    public function reset(Formation $formation, EntityManagerInterface $em): Response
    {
        foreach ($formation->getRootNodes() as $root) {
            $em->remove($root);
        }
        $em->flush();
        $this->addFlash('info', 'Structure vidée.');

        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId()]);
    }
}
