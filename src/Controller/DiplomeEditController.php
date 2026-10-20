<?php

declare(strict_types=1);

namespace App\Controller;

use App\Diplome\Check\StructureViolationException;
use App\Diplome\ImposedPropagator;
use App\Diplome\NextVersionCreator;
use App\Diplome\VersionUsage;
use App\Diplome\Wizard\WizardStepRegistry;
use App\Diplome\WizardDraft;
use App\Entity\TypeDiplome;
use App\Entity\TypeDiplomeVersion;
use App\Repository\TypeDiplomeVersionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Édition d'une version de type de diplôme existante (admin, préfixe `diplome_`) :
 * les étapes éditables de l'assistant deviennent des onglets, chacun enregistré
 * seul. Une version utilisée par des formations n'est enregistrée qu'avec la
 * confirmation d'impact ; les bornes et règles s'appliquent aussitôt (le garde de
 * structure relit la version). Création de la version suivante et propagation
 * des éléments imposés aux formations.
 */
final class DiplomeEditController extends AbstractController
{
    private const REQUIREMENTS = ['id' => '\d+', 'versionId' => '\d+'];

    public function __construct(
        private readonly WizardStepRegistry $steps,
        private readonly TypeDiplomeVersionRepository $versions,
        private readonly VersionUsage $usage,
        private readonly NextVersionCreator $nextVersion,
        private readonly ImposedPropagator $propagator,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/diplomes/{id}/versions/{versionId}/{step}', name: 'diplome_edit', requirements: self::REQUIREMENTS + ['step' => '[a-z]+'], methods: ['GET'])]
    public function edit(TypeDiplome $type, int $versionId, string $step): Response
    {
        $version = $this->version($type, $versionId);

        return $this->renderStep($type, $version, $step, null);
    }

    #[Route('/diplomes/{id}/versions/{versionId}/{step}', name: 'diplome_edit_save', requirements: self::REQUIREMENTS + ['step' => '[a-z]+'], methods: ['POST'])]
    public function save(TypeDiplome $type, int $versionId, string $step, Request $request): Response
    {
        $version = $this->version($type, $versionId);
        $current = $this->editableStep($step);
        $draft = WizardDraft::fromVersion($version);
        $form = $this->createForm($current->formType(), $current->initialData($draft))->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ([] !== $this->usage->formationsUsing($version) && !$request->request->getBoolean('confirmImpact')) {
                $this->addFlash('error', $this->translator->trans('diplome.edit.impact_required', [], 'diplome'));

                return $this->renderStep($type, $version, $step, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $current->apply($draft, $form->getData());
            $version->setConfig($draft->config());
            $this->em->flush();
            $this->addFlash('success', $this->translator->trans('diplome.edit.saved', ['%year%' => $version->getAnneeUniversitaire()], 'diplome'));

            return $this->redirectToRoute('diplome_edit', ['id' => $type->getId(), 'versionId' => $version->getId(), 'step' => $step]);
        }

        return $this->renderStep($type, $version, $step, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/diplomes/{id}/versions', name: 'diplome_version_new', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function newVersion(TypeDiplome $type): RedirectResponse
    {
        try {
            $version = $this->nextVersion->create($type);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('diplome_index');
        }
        $this->addFlash('success', $this->translator->trans('diplome.edit.version_created', ['%year%' => $version->getAnneeUniversitaire()], 'diplome'));

        return $this->redirectToRoute('diplome_edit', ['id' => $type->getId(), 'versionId' => $version->getId(), 'step' => $this->steps->editableKeys()[0]]);
    }

    #[Route('/diplomes/{id}/propager/{versionId}', name: 'diplome_version_propagate', requirements: self::REQUIREMENTS, methods: ['POST'])]
    public function propagate(TypeDiplome $type, int $versionId): RedirectResponse
    {
        $version = $this->version($type, $versionId);
        $config = $version->config();
        $touched = 0;
        foreach ($this->usage->formationsUsing($version) as $formation) {
            try {
                $touched += $this->propagator->propagate($formation, $config);
            } catch (StructureViolationException $e) {
                $this->addFlash('error', $this->translator->trans('diplome.edit.propagate_refused', ['%formation%' => $formation->getName(), '%message%' => $e->getMessage()], 'diplome'));
            }
        }
        $this->addFlash('success', $this->translator->trans('diplome.edit.propagated', ['%count%' => $touched], 'diplome'));

        return $this->redirectToRoute('diplome_edit', ['id' => $type->getId(), 'versionId' => $version->getId(), 'step' => $this->steps->editableKeys()[0]]);
    }

    private function renderStep(TypeDiplome $type, TypeDiplomeVersion $version, string $step, ?\Symfony\Component\Form\FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        $current = $this->editableStep($step);
        $draft = WizardDraft::fromVersion($version);
        $form ??= $this->createForm($current->formType(), $current->initialData($draft));

        return $this->render('diplome/wizard/'.$current->template().'.html.twig', $current->viewData($draft) + [
            'wizard_layout' => 'diplome/edit.html.twig',
            'step' => $step,
            'steps' => $this->steps->editableKeys(),
            'draft' => $draft,
            'form' => $form->createView(),
            'type' => $type,
            'version' => $version,
            'versions' => $type->getVersions(),
            'formations' => $this->usage->formationsUsing($version),
        ], new Response(status: $status));
    }

    private function version(TypeDiplome $type, int $versionId): TypeDiplomeVersion
    {
        $version = $this->versions->find($versionId);
        if (null === $version || $version->getTypeDiplome()->getId() !== $type->getId()) {
            throw $this->createNotFoundException();
        }

        return $version;
    }

    private function editableStep(string $key): \App\Diplome\Wizard\WizardStepInterface
    {
        $step = $this->steps->get($key);
        if (null === $step || !$step->editable()) {
            throw $this->createNotFoundException();
        }

        return $step;
    }
}
