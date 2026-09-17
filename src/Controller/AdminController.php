<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FieldDef;
use App\Enum\RefCategory;
use App\Maquette\AttributeCatalog;
use App\Repository\DerogationRequestRepository;
use App\Repository\MccTypeRepository;
use App\Repository\NodeTypeRepository;
use App\Repository\ReferentielRepository;
use App\Repository\StructureTemplateRepository;
use App\Service\TranslationFileManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hub d'administration (« Gestion SES ») : point d'entrée unique vers le
 * paramétrage du métamodèle et des nomenclatures de l'offre de formation.
 */
final class AdminController extends AbstractController
{
    /** Sections de l'administration — libellé, route, icône, description. */
    public const SECTIONS = [
        'overview' => ['label' => 'Vue d’ensemble', 'route' => 'admin_index', 'icon' => 'ph:gear'],
        'types' => ['label' => 'Types d’élément pédagogique', 'route' => 'node_type_index', 'icon' => 'ph:puzzle-piece'],
        'templates' => ['label' => 'Templates de structure', 'route' => 'template_index', 'icon' => 'ph:folders'],
        'referentiels' => ['label' => 'Référentiels', 'route' => 'admin_referentiels', 'icon' => 'ph:books'],
        'mcctypes' => ['label' => 'Types de MCCC', 'route' => 'admin_mcctypes', 'icon' => 'ph:list-checks'],
        'derogations' => ['label' => 'Dérogations', 'route' => 'admin_derogations', 'icon' => 'ph:flag'],
        'traductions' => ['label' => 'Traductions', 'route' => 'admin_traductions', 'icon' => 'ph:translate'],
    ];

    #[Route('/administration', name: 'admin_index', methods: ['GET'])]
    public function index(
        NodeTypeRepository $types,
        StructureTemplateRepository $templates,
        ReferentielRepository $referentiels,
        MccTypeRepository $mcctypes,
        DerogationRequestRepository $derogations,
        TranslationFileManager $translations,
    ): Response {
        $allTypes = $types->findAllOrdered();
        $allMccTypes = $mcctypes->allOrdered();
        $pendingDerogations = $derogations->countPending();
        $translationFiles = $translations->listFiles();

        return $this->render('admin/index.html.twig', [
            'cards' => [
                [
                    'section' => 'types',
                    'count' => \count($allTypes),
                    'sub' => \count(array_filter($allTypes, static fn ($t) => $t->isSystem())).' du socle',
                    'text' => 'Les briques des structures pédagogiques et du référentiel de compétences, et les capacités (champs) que chacune porte.',
                ],
                [
                    'section' => 'templates',
                    'count' => \count($templates->findAllOrdered()),
                    'sub' => 'réutilisables',
                    'text' => 'Des squelettes de structure prêts à instancier dans une formation, puis modifiables librement.',
                ],
                [
                    'section' => 'referentiels',
                    'count' => \count($referentiels->allOrdered()),
                    'sub' => 'nomenclatures',
                    'text' => 'Les listes de valeurs des formulaires : diplômes, domaines, régimes, langues, codes ROME…',
                ],
                [
                    'section' => 'mcctypes',
                    'count' => \count($allMccTypes),
                    'sub' => \count(array_filter($allMccTypes, static fn ($t) => $t->isSystem())).' du socle',
                    'text' => 'Les types de MCCC (CCI, CT…), leurs diplômes concernés et leurs règles de validation (nombre d’épreuves, coefficients…).',
                ],
                [
                    'section' => 'derogations',
                    'count' => $pendingDerogations,
                    'sub' => 'en attente',
                    'text' => 'Les demandes des responsables de formation pour ajouter/déplacer/supprimer quelque chose que le template du diplôme ne prévoit pas.',
                ],
                [
                    'section' => 'traductions',
                    'count' => \count($translationFiles),
                    'sub' => 'fichiers',
                    'text' => 'Tous les textes affichés dans l\'application, modifiables ici sans intervenir dans le code.',
                ],
            ],
        ]);
    }

    #[Route('/administration/referentiels', name: 'admin_referentiels', methods: ['GET'])]
    public function referentiels(NodeTypeRepository $types, ReferentielRepository $referentiels): Response
    {
        $all = $referentiels->allOrdered();

        $nodeTypePills = [];
        foreach ($types->findAllOrdered() as $t) {
            $nodeTypePills[$t->getKey()] = $t->getLabel();
        }

        return $this->render('admin/referentiels.html.twig', [
            'libres' => array_values(array_filter($all, static fn ($r) => $r->getCategory() === RefCategory::Libre)),
            'entites' => array_values(array_filter($all, static fn ($r) => $r->getCategory() === RefCategory::Entite)),
            'hourModalities' => AttributeCatalog::HOUR_MODALITIES,
            'hourPlaces' => AttributeCatalog::HOUR_PLACES,
            'fieldTypes' => FieldDef::TYPES,
            'nodeTypePills' => $nodeTypePills,
        ]);
    }

    #[Route('/administration/mcc-types', name: 'admin_mcctypes', methods: ['GET'])]
    public function mcctypes(MccTypeRepository $mcctypes): Response
    {
        return $this->render('mcctype/index.html.twig', [
            'types' => $mcctypes->allOrdered(),
        ]);
    }
}
