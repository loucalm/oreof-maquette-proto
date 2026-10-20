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

    #[Route('/diplomes/nouveau', name: 'diplome_wizard_start', methods: ['GET'])]
    public function start(Request $request, SessionInterface $session): RedirectResponse
    {
        $from = $request->query->getInt('depuis');
        $base = $from > 0 ? $this->versions->find($from) : null;
        $draft = null === $base ? new WizardDraft() : WizardDraft::fromVersion($base);
        if ('' === $draft->anneeUniversitaire) {
            $draft->anneeUniversitaire = $this->defaultYear();
        }
        $session->set(WizardDraft::SESSION_KEY, $draft);

        return $this->redirectToRoute('diplome_wizard_step', ['step' => $this->steps->keys()[0]]);
    }

    #[Route('/diplomes/nouveau/enregistrer', name: 'diplome_wizard_save', methods: ['POST'])]
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

                return $this->redirectToRoute('diplome_wizard_step', ['step' => $this->steps->neighbour($step, 1) ?? $step]);
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
