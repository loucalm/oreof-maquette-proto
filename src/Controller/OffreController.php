<?php

declare(strict_types=1);

namespace App\Controller;

use App\Diplome\FormationCreator;
use App\Diplome\TypeDiplomeResolver;
use App\Entity\Formation;
use App\Offre\OffreBoard;
use App\Offre\OffreFormation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gestion de l'offre de formation (onglet « Consolidation de l'offre ») : tableau par composante,
 * formation, parcours et année, et page « Configurer » d'une formation (ouverture, capacités).
 */
final class OffreController extends AbstractController
{
    #[Route('/offre', name: 'offre_index', methods: ['GET'])]
    public function index(
        OffreBoard $board,
        FormationCreator $creator,
        #[MapQueryParameter] string $q = '',
        #[MapQueryParameter] string $composante = '',
        #[MapQueryParameter] string $type = '',
        #[MapQueryParameter] string $plateforme = '',
        #[MapQueryParameter] string $statut = '',
    ): Response {
        $filters = ['q' => $q, 'composante' => $composante, 'type' => $type, 'plateforme' => $plateforme, 'statut' => $statut];

        return $this->render('offre/index.html.twig', $board->build($filters) + [
            'filters' => $filters,
            'creationTypes' => $creator->creationTypes(),
            'defaultYear' => TypeDiplomeResolver::DEFAULT_YEAR,
        ]);
    }

    #[Route('/offre/formations/{id}', name: 'offre_configure', methods: ['GET'])]
    public function configure(Formation $formation, OffreFormation $offre): Response
    {
        return $this->render('offre/configure.html.twig', ['offre' => $offre->describe($formation)]);
    }

    #[Route('/offre/formations/{id}', name: 'offre_configure_save', methods: ['POST'])]
    public function save(Formation $formation, OffreFormation $offre, Request $request): Response
    {
        $offre->save($formation, $request->request->all('parcours'), $request->request->all('annees'));
        $this->addFlash('success', sprintf('Offre de « %s » enregistrée.', $formation->getName()));

        return $this->redirectToRoute('offre_configure', ['id' => $formation->getId()]);
    }
}
