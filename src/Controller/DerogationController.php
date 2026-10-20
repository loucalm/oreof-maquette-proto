<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DerogationRequest;
use App\Entity\Formation;
use App\Enum\DerogationStatus;
use App\Maquette\Maquette;
use App\Repository\DerogationRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dérogations à la structure imposée par un template : le responsable décrit
 * par texte ce qu'il voudrait (typiquement sur un ELP verrouillé), l'admin
 * SES traite la demande — il applique lui-même la modification avec ses
 * propres droits d'édition, puis marque la demande approuvée/refusée. Aucun
 * rejeu automatique de l'action demandée.
 */
final class DerogationController extends AbstractController
{
    public function __construct(private readonly Maquette $maquette)
    {
    }

    #[Route('/formations/{fid}/derogations', name: 'formation_derogation_create', methods: ['POST'])]
    public function create(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, Request $request, EntityManagerInterface $em): Response
    {
        $message = trim((string) $request->request->get('message'));
        if ('' === $message) {
            $this->addFlash('warning', 'Décrivez votre demande de dérogation avant de l’envoyer.');

            return $this->redirectToRoute('formation_editor', ['id' => $formation->getId()]);
        }

        $nodeId = trim((string) $request->request->get('nodeId')) ?: null;
        $node = null !== $nodeId ? $this->maquette->open($formation)->node($nodeId) : null;

        $derogation = new DerogationRequest($formation, $message, $nodeId, $node?->getDisplayLabel());
        $ruleKey = trim((string) $request->request->get('ruleKey'));
        if ('' !== $ruleKey) {
            $allowed = trim((string) $request->request->get('allowedValue'));
            $derogation->withDeviation(
                $ruleKey,
                trim((string) $request->request->get('scopeNid')),
                is_numeric($allowed) ? (float) $allowed : null,
            );
        }
        $em->persist($derogation);
        $em->flush();

        $this->addFlash('success', 'Demande de dérogation envoyée à l’administrateur SES.');

        if (null !== $node) {
            for ($c = $node; null !== $c; $c = $c->getParent()) {
                if ($c->isParcours()) {
                    return $this->redirectToRoute('parcours_editor', [
                        'fid' => $formation->getId(), 'nid' => $c->getId(), 'focus' => $node->getId(),
                    ]);
                }
            }

            return $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'focus' => $node->getId()]);
        }

        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId()]);
    }

    #[Route('/administration/derogations', name: 'admin_derogations', methods: ['GET'])]
    public function index(DerogationRequestRepository $repo): Response
    {
        return $this->render('derogation/index.html.twig', [
            'derogations' => $repo->findAllOrdered(),
        ]);
    }

    #[Route('/administration/derogations/{id}/resoudre', name: 'admin_derogation_resolve', methods: ['POST'])]
    public function resolve(DerogationRequest $derogation, Request $request, EntityManagerInterface $em): Response
    {
        $status = DerogationStatus::tryFrom((string) $request->request->get('status', ''));
        if (null === $status || DerogationStatus::Pending === $status) {
            throw $this->createNotFoundException();
        }

        $derogation->resolve($status, $request->request->get('adminNote'));
        $em->flush();

        // approbation avec levée du verrou : l'élément imposé redevient modifiable pour le responsable
        $unlocked = null;
        if (DerogationStatus::Approved === $status && $request->request->getBoolean('unlock') && null !== $derogation->getNodeId()) {
            $formation = $derogation->getFormation();
            $doc = $this->maquette->open($formation);
            $node = $doc->node($derogation->getNodeId());
            if (null !== $node && [] !== $node->locked) {
                $node->locked = [];
                $this->maquette->save($formation, $doc, sprintf('Dérogation accordée : verrou levé sur « %s »', $node->getDisplayLabel()));
                $unlocked = $node->getDisplayLabel();
            }
        }

        $this->addFlash('success', DerogationStatus::Approved === $status
            ? (null !== $unlocked ? sprintf('Demande approuvée, verrou levé sur « %s ».', $unlocked) : 'Demande approuvée.')
            : 'Demande refusée.');

        return $this->redirectToRoute('admin_derogations');
    }
}
