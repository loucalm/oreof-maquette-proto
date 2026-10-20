<?php

declare(strict_types=1);

namespace App\Controller;

use App\Diplome\ConfigDiff;
use App\Diplome\WizardDraft;
use App\Entity\TypeDiplome;
use App\Entity\TypeDiplomeVersion;
use App\Form\Diplome\BasedOnType;
use App\Form\Diplome\FormsType;
use App\Form\Diplome\InformationsType;
use App\Repository\TypeDiplomeRepository;
use App\Repository\TypeDiplomeVersionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Assistant « Nouveau diplôme » (admin, préfixe `diplome_`). Les étapes
 * structure / MCCC / BCC sont remplies par d'autres tâches ; ici elles
 * affichent « à venir ».
 */
final class DiplomeWizardController extends AbstractController
{
    public const STEPS = ['informations', 'depart', 'formulaires', 'structure', 'mccc', 'bcc', 'recapitulatif'];
    /** Gabarit par étape ; les étapes absentes (structure, mccc, bcc) affichent « à venir ». */
    private const TEMPLATES = ['informations' => 'informations', 'depart' => 'based_on', 'formulaires' => 'forms', 'recapitulatif' => 'summary'];

    public function __construct(
        private readonly TypeDiplomeVersionRepository $versions,
        private readonly TypeDiplomeRepository $types,
        private readonly EntityManagerInterface $em,
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

        return $this->redirectToRoute('diplome_wizard_step', ['step' => 'informations']);
    }

    #[Route('/diplomes/nouveau/enregistrer', name: 'diplome_wizard_save', methods: ['POST'])]
    public function save(SessionInterface $session): RedirectResponse
    {
        $draft = $this->draft($session);
        $key = InformationsType::normalizeKey((string) ($draft->informations['sigle'] ?? ''));
        if (!$draft->informationsValid || '' === $key || null !== $this->types->findOneByKey($key)) {
            return $this->redirectToRoute('diplome_wizard_step', ['step' => 'informations']);
        }

        $info = $draft->informations;
        $type = new TypeDiplome(InformationsType::normalizeKey((string) $info['sigle']), (string) $info['label'], trim((string) $info['sigle']));
        $type->setCodeApogee('' === ($info['codeApogee'] ?? '') ? null : ($info['codeApogee'] ?? null))
            ->setDescription('' === ($info['description'] ?? '') ? null : ($info['description'] ?? null))
            ->setAccredite((bool) ($info['accredite'] ?? false))
            ->setPassageCfvu((bool) ($info['passageCfvu'] ?? false));
        $version = (new TypeDiplomeVersion($type, $draft->anneeUniversitaire))
            ->setConfig($draft->config())
            ->setBasedOn($this->basedOnReference($draft));
        $type->addVersion($version);
        $this->em->persist($type);
        $this->em->flush();

        $session->remove(WizardDraft::SESSION_KEY);
        $this->addFlash('success', $this->translator->trans('diplome.flash.created', ['%label%' => $type->getLabel()], 'diplome'));

        return $this->redirectToRoute('diplome_index');
    }

    #[Route(
        '/diplomes/nouveau/{step}',
        name: 'diplome_wizard_step',
        requirements: ['step' => 'informations|depart|formulaires|structure|mccc|bcc|recapitulatif'],
        methods: ['GET', 'POST'],
    )]
    public function step(string $step, Request $request, SessionInterface $session): Response
    {
        $draft = $this->draft($session);
        if ('informations' !== $step && !$draft->informationsValid) {
            return $this->redirectToRoute('diplome_wizard_step', ['step' => 'informations']);
        }

        $form = match ($step) {
            'informations' => $this->createForm(InformationsType::class, $this->informationsData($draft)),
            'depart' => $this->createForm(BasedOnType::class, ['basedOnVersionId' => $draft->basedOnVersionId]),
            'formulaires' => $this->createForm(FormsType::class, $this->formsData($draft)),
            default => null,
        };

        if (null !== $form) {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $this->apply($step, $form, $draft);
                $session->set(WizardDraft::SESSION_KEY, $draft);

                return $this->redirectToRoute('diplome_wizard_step', ['step' => $this->neighbour($step, 1)]);
            }
        }

        $diff = [];
        $summary = [];
        $base = null;
        if ('recapitulatif' === $step) {
            $base = null === $draft->basedOnVersionId ? null : $this->versions->find($draft->basedOnVersionId);
            $diff = null === $base ? [] : ConfigDiff::between($base->config()->toArray(), $draft->config()->toArray());
            $summary = $draft->config()->toArray();
        }

        return $this->render('diplome/wizard/'.(self::TEMPLATES[$step] ?? 'coming').'.html.twig', [
            'step' => $step,
            'steps' => self::STEPS,
            'draft' => $draft,
            'form' => $form?->createView(),
            'previous' => $this->neighbour($step, -1),
            'next' => $this->neighbour($step, 1),
            'base' => $base,
            'diff' => $diff,
            'config' => $summary,
            'basedOn' => $this->basedOnReference($draft),
            'unlocked' => $draft->informationsValid,
        ], new Response(status: null !== $form && $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function apply(string $step, FormInterface $form, WizardDraft $draft): void
    {
        /** @var array<string, mixed> $data */
        $data = $form->getData();
        switch ($step) {
            case 'informations':
                $draft->anneeUniversitaire = (string) $data['anneeUniversitaire'];
                unset($data['anneeUniversitaire']);
                $draft->informations = $data;
                $draft->informationsValid = true;
                break;
            case 'depart':
                $id = null === $data['basedOnVersionId'] ? null : (int) $data['basedOnVersionId'];
                if ($id !== $draft->basedOnVersionId) {
                    $base = null === $id ? null : $this->versions->find($id);
                    $draft->basedOnVersionId = $base?->getId();
                    $draft->configData = $base?->config()->toArray() ?? [];
                }
                break;
            case 'formulaires':
                $config = $draft->configData;
                $forms = [];
                foreach (FormsType::SLOTS as $slot) {
                    $value = $data[FormsType::fieldName($slot)] ?? null;
                    if (null !== $value && '' !== $value) {
                        $forms[$slot] = (string) $value;
                    }
                }
                $config['forms'] = $forms;
                $draft->configData = $config;
                break;
        }
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

    /** @return array<string, mixed> */
    private function informationsData(WizardDraft $draft): array
    {
        return $draft->informations + [
            'label' => null,
            'sigle' => null,
            'codeApogee' => null,
            'description' => null,
            'accredite' => true,
            'passageCfvu' => true,
        ] + ['anneeUniversitaire' => $draft->anneeUniversitaire];
    }

    /** @return array<string, string|null> */
    private function formsData(WizardDraft $draft): array
    {
        $data = [];
        foreach (FormsType::SLOTS as $slot) {
            $data[FormsType::fieldName($slot)] = $draft->config()->forms[$slot] ?? null;
        }

        return $data;
    }

    private function basedOnReference(WizardDraft $draft): ?string
    {
        $base = null === $draft->basedOnVersionId ? null : $this->versions->find($draft->basedOnVersionId);

        return null === $base ? null : $base->getTypeDiplome()->getKey().'@'.$base->getAnneeUniversitaire();
    }

    private function neighbour(string $step, int $offset): ?string
    {
        return self::STEPS[array_search($step, self::STEPS, true) + $offset] ?? null;
    }

    private function defaultYear(): string
    {
        $start = (int) date('Y') - ((int) date('n') >= 9 ? 0 : 1);

        return $start.'-'.($start + 1);
    }
}
