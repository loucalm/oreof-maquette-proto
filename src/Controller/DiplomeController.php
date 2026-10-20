<?php

declare(strict_types=1);

namespace App\Controller;

use App\Diplome\Spec\Bound;
use App\Entity\TypeDiplome;
use App\Diplome\Wizard\WizardStepRegistry;
use App\Repository\FormationRepository;
use App\Repository\TypeDiplomeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Section « Diplômes » (admin) : liste des types de diplôme, archivage et
 * suppression protégée. L'accès est réservé à l'admin par `AdminAccessSubscriber`
 * (préfixe `diplome_`).
 */
final class DiplomeController extends AbstractController
{
    public function __construct(
        private readonly TypeDiplomeRepository $types,
        private readonly FormationRepository $formations,
        private readonly WizardStepRegistry $steps,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/diplomes', name: 'diplome_index', methods: ['GET'])]
    public function index(): Response
    {
        $all = $this->types->findAllOrdered();
        $labelsByKey = [];
        foreach ($all as $t) {
            $labelsByKey[$t->getKey()] = $t->getLabel();
        }

        $rows = [];
        foreach ($all as $type) {
            $latest = $type->latestVersion();
            $config = $latest?->config();
            $basedOn = $latest?->getBasedOn();
            $basedOnKey = null === $basedOn ? null : explode('@', $basedOn)[0];

            $rows[] = [
                'type' => $type,
                'basedOn' => null === $basedOnKey ? null : ($labelsByKey[$basedOnKey] ?? $basedOnKey),
                'duration' => $this->durationLabel($config?->structure->duration),
                'bcc' => null === $config || [] === $config->bccLevels ? null : implode(' › ', $config->bccLevels),
                'formations' => $this->formations->countByTypeDiplome($type),
                'version' => $latest?->getAnneeUniversitaire(),
                'versionId' => $latest?->getId(),
                'editStep' => $this->steps->editableKeys()[0] ?? null,
            ];
        }

        return $this->render('diplome/index.html.twig', ['rows' => $rows]);
    }

    #[Route('/diplomes/{id}/archiver', name: 'diplome_archive', methods: ['POST'])]
    public function archive(TypeDiplome $type): RedirectResponse
    {
        $type->setArchived(true);
        $this->em->flush();
        $this->addFlash('success', $this->translator->trans('diplome.flash.archived', ['%label%' => $type->getLabel()], 'diplome'));

        return $this->redirectToRoute('diplome_index');
    }

    #[Route('/diplomes/{id}/desarchiver', name: 'diplome_unarchive', methods: ['POST'])]
    public function unarchive(TypeDiplome $type): RedirectResponse
    {
        $type->setArchived(false);
        $this->em->flush();
        $this->addFlash('success', $this->translator->trans('diplome.flash.unarchived', ['%label%' => $type->getLabel()], 'diplome'));

        return $this->redirectToRoute('diplome_index');
    }

    #[Route('/diplomes/{id}/supprimer', name: 'diplome_delete', methods: ['POST'])]
    public function delete(TypeDiplome $type): RedirectResponse
    {
        $count = $this->formations->countByTypeDiplome($type);
        if ($count > 0) {
            $this->addFlash('error', $this->translator->trans('diplome.flash.delete_refused', ['%label%' => $type->getLabel(), '%count%' => $count], 'diplome'));

            return $this->redirectToRoute('diplome_index');
        }

        $label = $type->getLabel();
        $this->em->remove($type);
        $this->em->flush();
        $this->addFlash('success', $this->translator->trans('diplome.flash.deleted', ['%label%' => $label], 'diplome'));

        return $this->redirectToRoute('diplome_index');
    }

    private function durationLabel(?Bound $bound): ?string
    {
        if (null === $bound || (null === $bound->min && null === $bound->max)) {
            return null;
        }
        $key = match (true) {
            $bound->isExact() => 'exact',
            null !== $bound->min && null !== $bound->max => 'range',
            null !== $bound->min => 'min',
            default => 'max',
        };

        return $this->translator->trans('diplome.index.duration_'.$key, ['%n%' => $bound->min, '%min%' => $bound->min, '%max%' => $bound->max], 'diplome');
    }
}
