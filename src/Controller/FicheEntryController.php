<?php

declare(strict_types=1);

namespace App\Controller;

use App\Diplome\Spec\CanvasSpec;
use App\Entity\Formation;
use App\Formulaire\CanvasResolver;
use App\Formulaire\EntryCompletion;
use App\Formulaire\Fiche\FicheFactory;
use App\Formulaire\Fiche\FicheTarget;
use App\Formulaire\ResolvedField;
use App\Maquette\Maquette;
use App\Validation\TabValidator;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Entrées à formulaire des fiches formation, parcours et parcours unique : le formulaire est
 * celui que le canevas de la version désigne pour l'entrée, ses données sont rangées sous la
 * clé de l'entrée. Une entrée inconnue ou masquée n'existe pas (404) ; la clé d'un écran
 * intégré renvoie vers son écran.
 */
final class FicheEntryController extends AbstractController
{
    public function __construct(
        private readonly FicheFactory $fiches,
        private readonly CanvasResolver $canvas,
        private readonly EntryCompletion $entries,
        private readonly TabValidator $validator,
        private readonly Maquette $maquette,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/formations/{id}/parametre/{key}', name: 'formation_param', methods: ['GET'])]
    public function formation(Formation $formation, string $key): Response
    {
        $builtin = $this->builtinUrl($formation, $key);
        if (null !== $builtin) {
            return $this->redirect($builtin);
        }

        return $this->show($this->fiches->formation($formation), $key, $this->generateUrl('formation_param_save', ['id' => $formation->getId(), 'key' => $key]));
    }

    #[Route('/formations/{id}/parametre/{key}', name: 'formation_param_save', methods: ['POST'])]
    public function formationSave(Formation $formation, string $key, Request $request): Response
    {
        return $this->save($this->fiches->formation($formation), $key, $request, $this->generateUrl('formation_editor', ['id' => $formation->getId(), 'param' => $key]));
    }

    #[Route('/formations/{fid}/parcours/{nid}/parametre/{key}', name: 'parcours_param', methods: ['GET'])]
    public function parcours(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid, string $key): Response
    {
        return $this->show($this->parcoursFiche($formation, $nid), $key, $this->generateUrl('parcours_param_save', ['fid' => $formation->getId(), 'nid' => $nid, 'key' => $key]));
    }

    #[Route('/formations/{fid}/parcours/{nid}/parametre/{key}', name: 'parcours_param_save', methods: ['POST'])]
    public function parcoursSave(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid, string $key, Request $request): Response
    {
        return $this->save($this->parcoursFiche($formation, $nid), $key, $request, $this->generateUrl('parcours_editor', ['fid' => $formation->getId(), 'nid' => $nid, 'param' => $key]));
    }

    #[Route('/formations/{id}/parcours-unique/parametre/{key}', name: 'parcours_unique_param', methods: ['GET'])]
    public function parcoursUnique(Formation $formation, string $key): Response
    {
        return $this->show($this->monoFiche($formation), $key, $this->generateUrl('parcours_unique_param_save', ['id' => $formation->getId(), 'key' => $key]));
    }

    #[Route('/formations/{id}/parcours-unique/parametre/{key}', name: 'parcours_unique_param_save', methods: ['POST'])]
    public function parcoursUniqueSave(Formation $formation, string $key, Request $request): Response
    {
        return $this->save($this->monoFiche($formation), $key, $request, $this->generateUrl('formation_editor', ['id' => $formation->getId(), 'param' => 'parcours.'.$key]));
    }

    private function show(FicheTarget $fiche, string $key, string $saveUrl): Response
    {
        $form = $this->entries->form($fiche->formation(), $fiche->fiche(), $key) ?? throw $this->createNotFoundException();
        $entry = $this->canvas->formEntry($fiche->formation(), $fiche->fiche(), $key);
        $label = $this->canvas->label($fiche->fiche(), $entry);
        $title = CanvasSpec::PARCOURS === $fiche->fiche() ? 'formation.param.parcours_panel_title' : 'formation.param.panel_title';

        return $this->render('formation/param/entry.html.twig', [
            'fiche' => $fiche,
            'formation' => $fiche->formation(),
            'entryKey' => $key,
            'label' => $label,
            'title' => $this->translator->trans($title, [], 'formation'),
            'breadcrumb' => array_values(array_filter([$fiche->formation()->getName(), $fiche->parcours()?->getDisplayLabel(), $label])),
            'fields' => $this->entries->fields($fiche, $key),
            'data' => $fiche->data($key),
            'issues' => $this->entries->issues($fiche, $key),
            'hints' => $this->validator->hints($form),
            'saveUrl' => $saveUrl,
        ]);
    }

    private function save(FicheTarget $fiche, string $key, Request $request, string $redirect): Response
    {
        $fields = $this->entries->inputFields($fiche, $key);
        if ([] === $fields && null === $this->entries->form($fiche->formation(), $fiche->fiche(), $key)) {
            throw $this->createNotFoundException();
        }

        // seuls les champs du formulaire de l'entrée sont réécrits : un champ masqué ou absent garde sa valeur
        $posted = $request->request->all('p');
        $bound = $fiche->bound();
        $data = $fiche->data($key);
        foreach ($fields as $field) {
            $k = $field->getKey();
            if (\array_key_exists($k, $bound)) {
                continue;
            }
            $v = $posted[$k] ?? null;
            $data[$k] = \is_string($v) ? trim($v) : $v;
        }
        $fiche->setData($key, array_filter($data, static fn ($v) => '' !== $v && null !== $v && [] !== $v));
        $fiche->writeBound($request->request->all('b'));
        $this->maquette->save($fiche->formation(), $fiche->doc());

        // enregistrement en direct (Stimulus) : pas de flash ; statut et erreurs par champ recalculés
        if ('json' === $request->getPreferredFormat()) {
            return $this->json(['status' => $this->entries->status($fiche, $key), 'issues' => $this->entries->issues($fiche, $key)]);
        }

        $entry = $this->canvas->formEntry($fiche->formation(), $fiche->fiche(), $key);
        $this->addFlash('success', sprintf('« %s » enregistré.', null !== $entry ? $this->canvas->label($fiche->fiche(), $entry) : $key));

        return $this->redirect($redirect);
    }

    private function parcoursFiche(Formation $formation, string $nid): FicheTarget
    {
        return $this->fiches->parcours($formation, $nid) ?? throw $this->createNotFoundException();
    }

    /** Le parcours unique n'existe que pour une formation sans parcours. */
    private function monoFiche(Formation $formation): FicheTarget
    {
        if ($formation->isAvecParcours()) {
            throw $this->createNotFoundException();
        }

        return $this->fiches->mono($formation);
    }

    /** URL de l'écran intégré dont c'est la clé dans la fiche formation, null sinon. */
    private function builtinUrl(Formation $formation, string $key): ?string
    {
        $entry = $this->canvas->forFormation($formation)->entry(CanvasSpec::FORMATION, $key);
        if (null === $entry || !$entry->isBuiltin()) {
            return null;
        }
        foreach ($this->canvas->navigation($formation) as $item) {
            if ($item->key === $key) {
                return $item->url;
            }
        }

        return null;
    }
}
