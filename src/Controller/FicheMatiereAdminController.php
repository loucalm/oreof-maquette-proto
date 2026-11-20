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
 * Fiches matière hors diplôme : liste et création, rangées dans l'offre de formation comme dans ORéOF v2
 * (`/fiches-matiere/hors-diplome`), pas dans l'administration. Réservées à l'admin par AdminAccessSubscriber::ADMIN_ROUTES.
 */
final class FicheMatiereAdminController extends AbstractController
{
    #[Route('/fiches-matieres/hors-diplome', name: 'fiche_matiere_hors_diplome', methods: ['GET'], priority: 10)]
    public function index(FicheMatiereRepository $fiches, FicheMatiereUsage $usage, TypeDiplomeRepository $types): Response
    {
        $rows = [];
        foreach ($fiches->horsDiplome() as $fiche) {
            $rows[] = ['fiche' => $fiche, 'usages' => \count($usage->usages($fiche))];
        }

        return $this->render('fiche_matiere/hors_diplome.html.twig', [
            'rows' => $rows,
            'types' => $types->findAllOrdered(false),
        ]);
    }

    #[Route('/fiches-matieres/hors-diplome', name: 'fiche_matiere_hors_diplome_create', methods: ['POST'], priority: 10)]
    #[IsCsrfTokenValid(FicheMatiereController::CSRF_ID, tokenKey: FicheMatiereController::CSRF_FIELD)]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        TypeDiplomeRepository $types,
    ): Response {
        $libelle = trim((string) $request->request->get('libelle', ''));
        if ('' === $libelle || mb_strlen($libelle) > 250) {
            $this->addFlash('warning', 'Le libellé de la fiche est obligatoire et ne peut pas dépasser 250 caractères.');

            return $this->redirectToRoute('fiche_matiere_hors_diplome');
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
