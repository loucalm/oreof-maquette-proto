<?php

declare(strict_types=1);

namespace App\Controller;

use App\Diplome\Spec\CanvasEntry;
use App\Diplome\Spec\CanvasSpec;
use App\Diplome\Spec\VersionConfig;
use App\Diplome\WizardDraft;
use App\Entity\NodeType;
use App\Entity\TypeDiplome;
use App\Entity\TypeDiplomeVersion;
use App\Formulaire\CanvasResolver;
use App\Diplome\Wizard\CanvasStep;
use App\Form\Diplome\CanvasType;
use App\Formulaire\Preview\PreviewContext;
use App\Formulaire\Preview\PreviewStarter;
use App\Formulaire\VariantFactory;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Repository\TypeDiplomeVersionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * Actions du canevas (onglet « Formulaires » d'une version, étape de l'assistant) :
 * « Adapter » crée une variante du formulaire d'une entrée et la choisit pour cette entrée.
 * La variante neuve étant identique à son départ, la version est enregistrée sans
 * confirmation d'impact.
 */
final class CanvasController extends AbstractController
{
    public function __construct(
        private readonly CanvasResolver $canvas,
        private readonly VariantFactory $variants,
        private readonly EntityManagerInterface $em,
        private readonly CanvasStep $step,
        private readonly PreviewContext $preview,
        private readonly PreviewStarter $previews,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/diplomes/{id}/versions/{versionId}/formulaires/adapter', name: 'diplome_canvas_adapt', requirements: ['id' => '\d+', 'versionId' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(DiplomeController::CSRF_ID, tokenKey: DiplomeController::CSRF_FIELD)]
    public function adapt(TypeDiplome $type, int $versionId, Request $request, TypeDiplomeVersionRepository $versions): RedirectResponse
    {
        $version = $versions->find($versionId);
        if (null === $version || $version->getTypeDiplome()->getId() !== $type->getId()) {
            throw $this->createNotFoundException();
        }
        [$canvas, $variant] = $this->adaptEntry($this->canvas->forConfig($version->config()), $request, $type->getKey());
        $version->setConfig(VersionConfig::fromArray(['canvas' => $canvas->toArray()] + $version->config()->toArray()));
        $this->em->flush();

        return $this->toVariant($variant, $this->generateUrl('diplome_edit', ['id' => $type->getId(), 'versionId' => $versionId, 'step' => 'formulaires']));
    }

    #[Route('/diplomes/nouveau/formulaires/adapter', name: 'diplome_wizard_canvas_adapt', methods: ['POST'], priority: 10)]
    #[IsCsrfTokenValid(DiplomeController::CSRF_ID, tokenKey: DiplomeController::CSRF_FIELD)]
    public function adaptInWizard(Request $request, SessionInterface $session): RedirectResponse
    {
        $draft = $session->get(WizardDraft::SESSION_KEY);
        if (!$draft instanceof WizardDraft || !$draft->informationsValid) {
            return $this->redirectToRoute('diplome_wizard_step', ['step' => 'informations']);
        }
        [$canvas, $variant] = $this->adaptEntry($this->canvas->forConfig($draft->config()), $request, (string) $draft->informationsData()['sigle']);
        $this->em->flush();
        $config = $draft->configData;
        unset($config['forms']);
        $config['canvas'] = $canvas->toArray();
        $draft->configData = $config;
        $session->set(WizardDraft::SESSION_KEY, $draft);

        return $this->toVariant($variant, $this->generateUrl('diplome_wizard_step', ['step' => 'formulaires']));
    }

    /** Aperçu du canevas soumis (non enregistré) sur la vraie page d'une formation de la version. */
    #[Route('/diplomes/{id}/versions/{versionId}/formulaires/apercu', name: 'diplome_canvas_preview', requirements: ['id' => '\d+', 'versionId' => '\d+'], methods: ['POST'])]
    public function preview(TypeDiplome $type, int $versionId, Request $request, TypeDiplomeVersionRepository $versions): Response
    {
        $version = $versions->find($versionId);
        if (null === $version || $version->getTypeDiplome()->getId() !== $type->getId()) {
            throw $this->createNotFoundException();
        }
        $returnUrl = $this->generateUrl('diplome_edit', ['id' => $type->getId(), 'versionId' => $versionId, 'step' => 'formulaires']);

        return $this->previewDraft(WizardDraft::forEdit($version), $request, $returnUrl, $version, $type);
    }

    #[Route('/diplomes/nouveau/formulaires/apercu', name: 'diplome_wizard_canvas_preview', methods: ['POST'], priority: 10)]
    public function previewInWizard(Request $request, SessionInterface $session): Response
    {
        $draft = $session->get(WizardDraft::SESSION_KEY);
        if (!$draft instanceof WizardDraft || !$draft->informationsValid) {
            return $this->redirectToRoute('diplome_wizard_step', ['step' => 'informations']);
        }

        return $this->previewDraft(clone $draft, $request, $this->generateUrl('diplome_wizard_step', ['step' => 'formulaires']), null, null);
    }

    #[Route('/diplomes/apercu/quitter', name: 'diplome_canvas_preview_stop', methods: ['POST'])]
    #[IsCsrfTokenValid(DiplomeController::CSRF_ID, tokenKey: DiplomeController::CSRF_FIELD)]
    public function stopPreview(): RedirectResponse
    {
        $returnUrl = $this->preview->returnUrl() ?? $this->generateUrl('diplome_index');
        $this->preview->stop();

        return $this->redirect($returnUrl);
    }

    private function previewDraft(WizardDraft $draft, Request $request, string $returnUrl, ?TypeDiplomeVersion $version, ?TypeDiplome $type): Response
    {
        $form = $this->createForm(CanvasType::class, $this->step->initialData($draft))->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', $this->translator->trans('diplome.wizard.formulaires.preview_invalid', [], 'diplome'));

            return $this->redirect($returnUrl);
        }
        $this->step->apply($draft, $form->getData());
        $fiche = CanvasSpec::PARCOURS === $request->request->get('fiche') ? CanvasSpec::PARCOURS : CanvasSpec::FORMATION;

        $url = $this->previews->start($draft->config()->canvas ?? new CanvasSpec(), $fiche, $returnUrl, $version, $type);
        if (null === $url) {
            $this->addFlash('error', $this->translator->trans('diplome.wizard.formulaires.preview_no_host', [], 'diplome'));

            return $this->redirect($returnUrl);
        }

        return $this->redirect($url);
    }

    /** @return array{0: CanvasSpec, 1: NodeType} canevas où l'entrée désigne la nouvelle variante, et la variante */
    private function adaptEntry(CanvasSpec $canvas, Request $request, string $sigle): array
    {
        $fiche = (string) $request->request->get('fiche');
        $key = (string) $request->request->get('entry');
        $entry = \in_array($fiche, CanvasSpec::FICHES, true) ? $canvas->entry($fiche, $key) : null;
        $form = null !== $entry ? $this->canvas->form($entry) : null;
        if (null === $form) {
            throw $this->createNotFoundException();
        }

        $variant = $this->variants->create($form, $form->getLabel().' · '.$sigle);
        $canvas = $canvas->withEntries($fiche, array_map(
            static fn (CanvasEntry $e) => $e->key === $key ? new CanvasEntry($e->key, $variant->getKey(), null, $e->label, $e->hidden) : $e,
            $canvas->entries($fiche),
        ));

        return [$canvas, $variant];
    }

    private function toVariant(NodeType $variant, string $canvasUrl): RedirectResponse
    {
        $this->addFlash('success', sprintf('Variante « %s » créée et choisie pour cette entrée.', $variant->getLabel()));

        return $this->redirectToRoute('node_type_edit', ['id' => $variant->getId(), 'retour' => $canvasUrl]);
    }
}
