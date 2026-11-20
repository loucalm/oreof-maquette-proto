<?php

declare(strict_types=1);

namespace App\Controller;

use App\Diplome\Spec\CanvasSpec;
use App\Entity\Formation;
use App\Formulaire\CanvasResolver;
use App\Formulaire\EntryCompletion;
use App\Formulaire\FicheNavigation;
use App\Formulaire\Fiche\FicheFactory;
use App\Formulaire\Fiche\FicheTarget;
use App\Formulaire\ResolvedField;
use App\Maquette\Doc\TreeNode;
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
 *
 * Formation sans parcours : l'entrée du parcours par défaut qui a la même clé qu'une entrée de la
 * fiche formation se remplit sur la page de celle-ci, dans le même formulaire, sans séparation ni
 * doublon (cf. CanvasResolver::monoMerged, EntryCompletion::mergedFields) ; un champ des deux
 * fiches est enregistré dans les deux.
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
        private readonly FicheNavigation $navigation,
    ) {
    }

    #[Route('/formations/{id}/parametre/{key}', name: 'formation_param', methods: ['GET'])]
    public function formation(Formation $formation, string $key): Response
    {
        $builtin = $this->builtinUrl($formation, $key);
        if (null !== $builtin) {
            return $this->redirect($builtin);
        }

        $mono = $this->canvas->monoMerged($formation, $key) ? $this->fiches->mono($formation) : null;

        return $this->show($this->fiches->formation($formation), $key, $this->generateUrl('formation_param_save', ['id' => $formation->getId(), 'key' => $key]), null, $mono);
    }

    #[Route('/formations/{id}/parametre/{key}', name: 'formation_param_save', methods: ['POST'])]
    public function formationSave(Formation $formation, string $key, Request $request): Response
    {
        $mono = $this->canvas->monoMerged($formation, $key) ? $this->fiches->mono($formation) : null;

        return $this->save($this->fiches->formation($formation), $key, $request, $this->generateUrl('formation_editor', ['id' => $formation->getId(), 'param' => $key]), $mono);
    }

    #[Route('/formations/{fid}/parcours/{nid}/parametre/{key}', name: 'parcours_param', methods: ['GET'])]
    public function parcours(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid, string $key): Response
    {
        $fiche = $this->parcoursFiche($formation, $nid);
        $builtin = $this->builtinUrl($formation, $key, $fiche->parcours());
        if (null !== $builtin) {
            return $this->redirect($builtin);
        }

        return $this->show($fiche, $key, $this->generateUrl('parcours_param_save', ['fid' => $formation->getId(), 'nid' => $nid, 'key' => $key]));
    }

    #[Route('/formations/{fid}/parcours/{nid}/parametre/{key}', name: 'parcours_param_save', methods: ['POST'])]
    public function parcoursSave(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid, string $key, Request $request): Response
    {
        return $this->save($this->parcoursFiche($formation, $nid), $key, $request, $this->generateUrl('parcours_editor', ['fid' => $formation->getId(), 'nid' => $nid, 'param' => $key]));
    }

    #[Route('/formations/{id}/parcours-unique/parametre/{key}', name: 'parcours_unique_param', methods: ['GET'])]
    public function parcoursUnique(Formation $formation, string $key): Response
    {
        if ($this->canvas->monoMerged($formation, $key)) {
            return $this->redirectToRoute('formation_param', ['id' => $formation->getId(), 'key' => $key]);
        }

        return $this->show($this->monoFiche($formation), $key, $this->generateUrl('parcours_unique_param_save', ['id' => $formation->getId(), 'key' => $key]), CanvasResolver::GROUP_PARCOURS.'.'.$key);
    }

    #[Route('/formations/{id}/parcours-unique/parametre/{key}', name: 'parcours_unique_param_save', methods: ['POST'])]
    public function parcoursUniqueSave(Formation $formation, string $key, Request $request): Response
    {
        $param = $this->canvas->monoMerged($formation, $key) ? $key : CanvasResolver::GROUP_PARCOURS.'.'.$key;

        return $this->save($this->monoFiche($formation), $key, $request, $this->generateUrl('formation_editor', ['id' => $formation->getId(), 'param' => $param]));
    }

    /**
     * @param string|null      $statusKey clé de la pastille de la barre latérale, si elle diffère de la clé d'entrée
     * @param FicheTarget|null $mono      parcours par défaut dont l'entrée de même clé se remplit dans le même formulaire
     */
    private function show(FicheTarget $fiche, string $key, string $saveUrl, ?string $statusKey = null, ?FicheTarget $mono = null): Response
    {
        $form = $this->entries->form($fiche->formation(), $fiche->fiche(), $key) ?? throw $this->createNotFoundException();
        $entry = $this->canvas->formEntry($fiche->formation(), $fiche->fiche(), $key);
        $label = $this->canvas->label($fiche->fiche(), $entry);
        $title = CanvasSpec::PARCOURS === $fiche->fiche() ? 'formation.param.parcours_panel_title' : 'formation.param.panel_title';

        $fiches = null === $mono ? [$fiche] : [$fiche, $mono];
        $items = null === $mono
            ? array_map(static fn (ResolvedField $f) => ['field' => $f, 'fiche' => $fiche], $this->entries->fields($fiche, $key))
            : $this->entries->mergedFields($fiche, $mono, $key);
        // valeurs : celles de la première fiche l'emportent ; la seconde complète (champ commun saisi avant la fusion)
        $data = [];
        $hints = [];
        foreach (array_reverse($fiches) as $f) {
            $data = array_filter($f->data($key), static fn ($v) => '' !== $v && null !== $v && [] !== $v) + $data;
            $hints = $this->validator->hints($this->entries->form($f->formation(), $f->fiche(), $key) ?? $form) + $hints;
        }

        return $this->render('formation/param/entry.html.twig', [
            'formation' => $fiche->formation(),
            'entryKey' => $key,
            'label' => $label,
            'title' => $this->translator->trans($title, [], 'formation'),
            'breadcrumb' => array_values(array_filter([$fiche->formation()->getName(), $fiche->parcours()?->getDisplayLabel(), $label])),
            'fiche' => $fiche,
            'items' => $items,
            'data' => $data,
            'issues' => $this->issues($fiches, $key),
            'hints' => $hints,
            'saveUrl' => $saveUrl,
            'statusKey' => $statusKey ?? $key,
        ]);
    }

    /**
     * Anomalies par champ de une ou deux fiches (page fusionnée), sans doublon.
     *
     * @param list<FicheTarget> $fiches
     *
     * @return array<string, list<string>>
     */
    private function issues(array $fiches, string $key): array
    {
        $out = [];
        foreach ($fiches as $fiche) {
            foreach ($this->entries->issues($fiche, $key) as $field => $messages) {
                $out[$field] = array_values(array_unique([...$out[$field] ?? [], ...$messages]));
            }
        }

        return $out;
    }

    /** @param FicheTarget|null $mono parcours par défaut rempli dans le même formulaire : ses champs (communs compris) sont enregistrés aussi */
    private function save(FicheTarget $fiche, string $key, Request $request, string $redirect, ?FicheTarget $mono = null): Response
    {
        if ([] === $this->entries->inputFields($fiche, $key) && null === $this->entries->form($fiche->formation(), $fiche->fiche(), $key)) {
            throw $this->createNotFoundException();
        }
        $fiches = null === $mono ? [$fiche] : [$fiche, $mono];
        foreach ($fiches as $target) {
            $this->write($target, $key, $request);
        }
        $this->maquette->save($fiche->formation(), $fiche->doc());

        // enregistrement en direct (Stimulus) : pas de flash ; statut et erreurs par champ recalculés
        if ('json' === $request->getPreferredFormat()) {
            // page partagée avec le parcours par défaut : la pastille porte le statut des deux fiches
            $status = null === $fiche->parcours() && $this->canvas->monoMerged($fiche->formation(), $key)
                ? $this->navigation->entryStatus($fiche->formation(), $key)
                : $this->entries->status($fiche, $key);

            return $this->json(['status' => $status, 'issues' => $this->issues($fiches, $key)]);
        }

        $entry = $this->canvas->formEntry($fiche->formation(), $fiche->fiche(), $key);
        $this->addFlash('success', sprintf('« %s » enregistré.', null !== $entry ? $this->canvas->label($fiche->fiche(), $entry) : $key));

        return $this->redirect($redirect);
    }

    /** Réécrit les champs du formulaire de l'entrée : un champ masqué ou absent garde sa valeur. */
    private function write(FicheTarget $fiche, string $key, Request $request): void
    {
        $posted = $request->request->all('p');
        $bound = $fiche->bound();
        $data = $fiche->data($key);
        foreach ($this->entries->inputFields($fiche, $key) as $field) {
            $k = $field->getKey();
            if (\array_key_exists($k, $bound)) {
                continue;
            }
            $v = $posted[$k] ?? null;
            $data[$k] = \is_string($v) ? trim($v) : $v;
        }
        $fiche->setData($key, array_filter($data, static fn ($v) => '' !== $v && null !== $v && [] !== $v));
        $fiche->writeBound($request->request->all('b'));
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

    /** URL de l'écran intégré dont c'est la clé dans la fiche (formation, ou parcours si `$parcours`), null sinon. */
    private function builtinUrl(Formation $formation, string $key, ?TreeNode $parcours = null): ?string
    {
        $entry = $this->canvas->forFormation($formation)->entry(null !== $parcours ? CanvasSpec::PARCOURS : CanvasSpec::FORMATION, $key);
        if (null === $entry || !$entry->isBuiltin()) {
            return null;
        }
        foreach ($this->canvas->navigation($formation, $parcours) as $item) {
            if ($item->key === $key) {
                return $item->url;
            }
        }

        return null;
    }
}
