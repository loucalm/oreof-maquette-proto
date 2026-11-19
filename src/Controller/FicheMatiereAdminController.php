<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FicheMatiere;
use App\Entity\TypeDiplome;
use App\FicheMatiere\FicheMatiereUsage;
use App\Repository\FicheMatiereRepository;
use App\Repository\TypeDiplomeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * Fiches matière hors diplôme : liste et création dans l'administration. Le préfixe `admin_`
 * suffit à réserver ces pages à l'administrateur (AdminAccessSubscriber).
 */
final class FicheMatiereAdminController extends AbstractController
{
    #[Route('/administration/fiches-matieres', name: 'admin_fiches_matieres', methods: ['GET'])]
    public function index(FicheMatiereRepository $fiches, FicheMatiereUsage $usage, TypeDiplomeRepository $types): Response
    {
        $rows = [];
        foreach ($fiches->horsDiplome() as $fiche) {
            $rows[] = ['fiche' => $fiche, 'usages' => \count($usage->usages($fiche))];
        }

        return $this->render('admin/fiches_matieres.html.twig', [
            'rows' => $rows,
            'types' => $types->findAllOrdered(false),
        ]);
    }

    #[Route('/administration/fiches-matieres', name: 'admin_fiche_matiere_create', methods: ['POST'])]
    #[IsCsrfTokenValid(FicheMatiereController::CSRF_ID, tokenKey: FicheMatiereController::CSRF_FIELD)]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        TypeDiplomeRepository $types,
    ): Response {
        $libelle = trim((string) $request->request->get('libelle', ''));
        if ('' === $libelle || mb_strlen($libelle) > 250) {
            $this->addFlash('warning', 'Le libellé de la fiche est obligatoire et ne peut pas dépasser 250 caractères.');

            return $this->redirectToRoute('admin_fiches_matieres');
        }

        $fiche = (new FicheMatiere())
            ->setHorsDiplome(true)
            ->setData(['libelle' => $libelle]);
        $type = $this->requestedType($request, $types);
        if (null !== $type) {
            $fiche->setTypeDiplome($type);
        }
        $em->persist($fiche);
        $em->flush();
        $this->addFlash('success', sprintf('Fiche « %s » créée.', $libelle));

        return $this->redirectToRoute('fiche_matiere_edit', ['id' => $fiche->getId()]);
    }

    /** Type de diplôme facultatif, parmi les types non archivés ; absent ou inconnu = fiche pour tous. */
    private function requestedType(Request $request, TypeDiplomeRepository $types): ?TypeDiplome
    {
        $id = $request->request->get('typeDiplome');
        if (!\is_numeric($id)) {
            return null;
        }
        foreach ($types->findAllOrdered(false) as $type) {
            if ((string) $type->getId() === (string) $id) {
                return $type;
            }
        }

        return null;
    }
}
