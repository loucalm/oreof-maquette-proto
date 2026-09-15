<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Referentiel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Admin des référentiels — les listes de valeurs (select, choix) proposées
 * dans les formulaires : types de diplôme, domaines, régimes d'inscription,
 * langues, codes ROME… Sur le même principe que les champs de formulaire
 * (FieldDef, `/champs`) : livrées par les fixtures, puis entièrement
 * modifiables ici sans toucher au code.
 */
final class ReferentielController extends AbstractController
{
    #[Route('/administration/referentiels/nouveau', name: 'referentiel_new', methods: ['GET', 'POST'])]
    #[Route('/administration/referentiels/{id}/editer', name: 'referentiel_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, EntityManagerInterface $em, ?Referentiel $referentiel = null): Response
    {
        $isNew = null === $referentiel;

        if ($request->isMethod('POST')) {
            $label = trim((string) $request->request->get('label')) ?: 'Référentiel';
            $key = trim((string) $request->request->get('key'))
                ?: (new AsciiSlugger())->slug($label)->lower()->toString();

            if ($isNew) {
                $referentiel = new Referentiel($key, $label);
                $em->persist($referentiel);
            } else {
                $referentiel->setKey($key)->setLabel($label);
            }

            // valeurs : une paire "valeur|libellé" par ligne (libellé optionnel)
            $values = [];
            foreach (preg_split('/\r?\n/', (string) $request->request->get('values')) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                [$v, $l] = array_pad(explode('|', $line, 2), 2, null);
                $v = trim((string) $v);
                if ($v !== '') {
                    $values[$v] = trim((string) ($l ?? $v)) ?: $v;
                }
            }

            $referentiel
                ->setDescription($request->request->get('description'))
                ->setValues($values)
                ->setPosition($request->request->getInt('position'));

            $em->flush();
            $this->addFlash('success', 'Référentiel enregistré.');

            return $this->redirectToRoute('admin_referentiels');
        }

        return $this->render('referentiel/edit.html.twig', [
            'referentiel' => $referentiel,
            'isNew' => $isNew,
        ]);
    }

    #[Route('/administration/referentiels/{id}/supprimer', name: 'referentiel_delete', methods: ['POST'])]
    public function delete(Referentiel $referentiel, EntityManagerInterface $em): Response
    {
        if ($referentiel->isSystem()) {
            $this->addFlash('warning', sprintf('« %s » est un référentiel du socle : il ne peut pas être supprimé.', $referentiel->getLabel()));

            return $this->redirectToRoute('admin_referentiels');
        }

        $em->remove($referentiel);
        $em->flush();
        $this->addFlash('info', 'Référentiel supprimé.');

        return $this->redirectToRoute('admin_referentiels');
    }
}
