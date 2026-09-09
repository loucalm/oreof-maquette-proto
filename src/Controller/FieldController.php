<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FieldDef;
use App\Repository\FieldDefRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Admin des champs de formulaire des nœuds — début de l'interface qui permet
 * de créer / modifier les champs, leur onglet et leur catégorie.
 */
final class FieldController extends AbstractController
{
    #[Route('/champs', name: 'field_index', methods: ['GET'])]
    public function index(FieldDefRepository $repo): Response
    {
        /** @var array<string, list<FieldDef>> $byTab */
        $byTab = [];
        foreach ($repo->allOrdered() as $f) {
            $byTab[$f->getTab()][] = $f;
        }

        return $this->render('field/index.html.twig', ['byTab' => $byTab]);
    }

    #[Route('/champs/nouveau', name: 'field_new', methods: ['GET', 'POST'])]
    #[Route('/champs/{id}/editer', name: 'field_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, EntityManagerInterface $em, FieldDefRepository $repo, ?FieldDef $field = null): Response
    {
        $isNew = null === $field;

        if ($request->isMethod('POST')) {
            $label = trim((string) $request->request->get('label')) ?: 'Champ';
            $key = trim((string) $request->request->get('key'))
                ?: (new AsciiSlugger())->slug($label)->lower()->toString();

            if ($isNew) {
                $field = new FieldDef($key, $label);
                $em->persist($field);
            } else {
                $field->setKey($key)->setLabel($label);
            }

            // options « choix » : une paire "valeur|libellé" par ligne
            $options = [];
            foreach (preg_split('/\r?\n/', (string) $request->request->get('options')) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                [$v, $l] = array_pad(explode('|', $line, 2), 2, null);
                $v = trim((string) $v);
                if ($v !== '') {
                    $options[$v] = trim((string) ($l ?? $v)) ?: $v;
                }
            }

            $field
                ->setTab((string) $request->request->get('tab', 'props'))
                ->setCategory($request->request->get('category'))
                ->setType((string) $request->request->get('type', 'text'))
                ->setOptions($options)
                ->setRequired($request->request->getBoolean('required'))
                ->setHelp($request->request->get('help'))
                ->setPosition($request->request->getInt('position'));

            $em->flush();
            $this->addFlash('success', 'Champ enregistré.');

            return $this->redirectToRoute('field_index');
        }

        return $this->render('field/edit.html.twig', [
            'field' => $field,
            'isNew' => $isNew,
            'types' => FieldDef::TYPES,
            'tabs' => $this->knownTabs($repo),
        ]);
    }

    #[Route('/champs/{id}/supprimer', name: 'field_delete', methods: ['POST'])]
    public function delete(FieldDef $field, EntityManagerInterface $em): Response
    {
        $em->remove($field);
        $em->flush();
        $this->addFlash('info', 'Champ supprimé.');

        return $this->redirectToRoute('field_index');
    }

    /** @return list<string> onglets déjà utilisés + les 3 connus */
    private function knownTabs(FieldDefRepository $repo): array
    {
        $tabs = ['props', 'volume_horaire', 'mccc'];
        foreach ($repo->allOrdered() as $f) {
            if (!\in_array($f->getTab(), $tabs, true)) {
                $tabs[] = $f->getTab();
            }
        }

        return $tabs;
    }
}
