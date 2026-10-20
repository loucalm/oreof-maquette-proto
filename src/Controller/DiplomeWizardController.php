<?php

declare(strict_types=1);

namespace App\Controller;

use App\Diplome\Wizard\DraftPublisher;
use App\Diplome\Wizard\WizardStepRegistry;
use App\Diplome\WizardDraft;
use App\Repository\TypeDiplomeVersionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Assistant « Nouveau diplôme » (admin, préfixe `diplome_`). Chaque étape est un
 * service `WizardStepInterface` ; le contrôleur résout l'étape, traite son
 * formulaire, applique les données au brouillon, redirige ou rend.
 */
final class DiplomeWizardController extends AbstractController
{
    public function __construct(
        private readonly WizardStepRegistry $steps,
        private readonly TypeDiplomeVersionRepository $versions,
        private readonly DraftPublisher $publisher,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Entrée de l'assistant : reprend le brouillon en cours, sauf demande explicite d'en commencer un
     * nouveau (`?nouveau=1`, bouton « Recommencer ») ou de dupliquer une autre version (`?depuis=`).
     */
    #[Route('/diplomes/nouveau', name: 'diplome_wizard_start', methods: ['GET'])]
    public function start(Request $request, SessionInterface $session): RedirectResponse
    {
        $current = $session->get(WizardDraft::SESSION_KEY);
        $from = $request->query->getInt('depuis');
        $fresh = $request->query->getBoolean('nouveau')
            || !$current instanceof WizardDraft
            || ($from > 0 && $from !== $current->basedOnVersionId);

        if ($fresh) {
            $base = $from > 0 ? $this->versions->find($from) : null;
            $draft = null === $base ? new WizardDraft() : WizardDraft::fromVersion($base);
            if ('' === $draft->anneeUniversitaire) {
                $draft->anneeUniversitaire = $this->defaultYear();
            }
            $session->set(WizardDraft::SESSION_KEY, $draft);
        }

        return $this->redirectToRoute('diplome_wizard_step', ['step' => $this->steps->keys()[0]]);
    }

    #[Route('/diplomes/nouveau/enregistrer', name: 'diplome_wizard_save', methods: ['POST'])]
    #[IsCsrfTokenValid(DiplomeController::CSRF_ID, tokenKey: DiplomeController::CSRF_FIELD)]
    public function save(SessionInterface $session): RedirectResponse
    {
        try {
            $type = $this->publisher->publish($this->draft($session));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('diplome_wizard_step', ['step' => 'informations']);
        }

        $session->remove(WizardDraft::SESSION_KEY);
        $this->addFlash('success', $this->translator->trans('diplome.flash.created', ['%label%' => $type->getLabel()], 'diplome'));

        return $this->redirectToRoute('diplome_index');
    }

    #[Route('/diplomes/nouveau/{step}', name: 'diplome_wizard_step', requirements: ['step' => '[a-z]+'], methods: ['GET', 'POST'])]
    public function step(string $step, Request $request, SessionInterface $session): Response
    {
        $current = $this->steps->get($step) ?? throw $this->createNotFoundException();
        $draft = $this->draft($session);
        if ($current->needsInformations() && !$draft->informationsValid) {
            return $this->redirectToRoute('diplome_wizard_step', ['step' => 'informations']);
        }

        $form = null;
        if (null !== $current->formType()) {
            $form = $this->createForm($current->formType(), $current->initialData($draft))->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $current->apply($draft, $form->getData());
                $session->set(WizardDraft::SESSION_KEY, $draft);

                // « Précédent » et les liens d'étapes soumettent aussi l'étape (champ `goto`) : la saisie n'est pas perdue
                $goto = $request->request->getString('goto');
                $target = null !== $this->steps->get($goto) ? $goto : ($this->steps->neighbour($step, 1) ?? $step);

                return $this->redirectToRoute('diplome_wizard_step', ['step' => $target]);
            }
        }

        return $this->render('diplome/wizard/'.$current->template().'.html.twig', $current->viewData($draft) + [
            'step' => $step,
            'steps' => $this->steps->keys(),
            'draft' => $draft,
            'form' => $form?->createView(),
            'previous' => $this->steps->neighbour($step, -1),
            'next' => $this->steps->neighbour($step, 1),
            'unlocked' => $draft->informationsValid,
        ], new Response(status: $form?->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function draft(SessionInterface $session): WizardDraft
    {
        $draft = $session->get(WizardDraft::SESSION_KEY);
        if (!$draft instanceof WizardDraft) {
            $draft = new WizardDraft(anneeUniversitaire: $this->defaultYear());
            $session->set(WizardDraft::SESSION_KEY, $draft);
        }

        return $draft;
    }

    private function defaultYear(): string
    {
        $start = (int) date('Y') - ((int) date('n') >= 9 ? 0 : 1);

        return $start.'-'.($start + 1);
    }
}
