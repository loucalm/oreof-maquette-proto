<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Formation;
use App\Maquette\Maquette;
use App\Maquette\QuickGrid;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Saisie rapide des ECTS et volumes horaires, façon tableur (une page,
 * navigation au clavier, enregistrement automatique ligne à ligne).
 */
final class QuickGridController extends AbstractController
{
    public function __construct(
        private readonly QuickGrid $grid,
        private readonly Maquette $maquette,
    ) {
    }

    #[Route('/formations/{id}/saisie-rapide', name: 'formation_quick_grid', methods: ['GET'])]
    public function show(Formation $formation, Request $request): Response
    {
        $parcoursList = $formation->isAvecParcours() ? $this->maquette->open($formation)->parcoursNodes() : [];
        $parcoursNid = $request->query->get('parcours');
        if ([] !== $parcoursList && (null === $parcoursNid || !\in_array($parcoursNid, array_map(static fn ($p) => $p->getId(), $parcoursList), true))) {
            $parcoursNid = $parcoursList[0]->getId();
        }

        return $this->render('formation/quick_grid.html.twig', [
            'formation' => $formation,
            'parcoursList' => $parcoursList,
            'parcoursNid' => [] !== $parcoursList ? $parcoursNid : null,
            'rows' => $this->grid->rows($formation, [] !== $parcoursList ? $parcoursNid : null),
            'columns' => $this->grid->columns(),
        ]);
    }

    #[Route('/formations/{fid}/nodes/{nid}/saisie-rapide', name: 'formation_quick_grid_save', methods: ['POST'])]
    public function save(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid, Request $request): JsonResponse
    {
        $result = $this->grid->apply($formation, $nid, $request->request->all());

        return new JsonResponse($result, $result['ok'] ? 200 : 422);
    }
}
