<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\NodeFamily;
use App\Maquette\AttributeCatalog;
use App\Maquette\Referentiels;
use App\Repository\FieldDefRepository;
use App\Repository\NodeTypeRepository;
use App\Repository\StructureTemplateRepository;
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
        'overview' => ['label' => 'Vue d’ensemble', 'route' => 'admin_index', 'icon' => '⚙'],
        'types' => ['label' => 'Types de nœud', 'route' => 'node_type_index', 'icon' => '🧩'],
        'fields' => ['label' => 'Champs des formulaires', 'route' => 'field_index', 'icon' => '📝'],
        'templates' => ['label' => 'Templates de structure', 'route' => 'template_index', 'icon' => '🗂'],
        'referentiels' => ['label' => 'Référentiels', 'route' => 'admin_referentiels', 'icon' => '📚'],
    ];

    #[Route('/administration', name: 'admin_index', methods: ['GET'])]
    public function index(
        NodeTypeRepository $types,
        FieldDefRepository $fields,
        StructureTemplateRepository $templates,
    ): Response {
        $allTypes = $types->findAllOrdered();
        $allFields = $fields->allOrdered();

        return $this->render('admin/index.html.twig', [
            'cards' => [
                [
                    'section' => 'types',
                    'count' => \count($allTypes),
                    'sub' => \count(array_filter($allTypes, static fn ($t) => $t->isSystem())).' du socle',
                    'text' => 'Les briques des structures pédagogiques et du référentiel de compétences, et les capacités (champs) que chacune porte.',
                ],
                [
                    'section' => 'fields',
                    'count' => \count($allFields),
                    'sub' => \count(array_filter($allFields, static fn ($f) => $f->isSystem())).' du socle',
                    'text' => 'Les champs saisissables sur les nœuds : onglet, type de saisie, options, obligatoire.',
                ],
                [
                    'section' => 'templates',
                    'count' => \count($templates->findAllOrdered()),
                    'sub' => 'réutilisables',
                    'text' => 'Des squelettes de structure prêts à instancier dans une formation, puis modifiables librement.',
                ],
                [
                    'section' => 'referentiels',
                    'count' => \count(Referentiels::all()),
                    'sub' => 'nomenclatures',
                    'text' => 'Les listes de valeurs des formulaires : diplômes, domaines, régimes, langues, codes ROME, types de MCCC…',
                ],
            ],
        ]);
    }

    #[Route('/administration/referentiels', name: 'admin_referentiels', methods: ['GET'])]
    public function referentiels(FieldDefRepository $fields, AttributeCatalog $catalog): Response
    {
        // options des champs « liste » / « radio » (nature, type d'UE, type d'EC…)
        $choiceFields = [];
        foreach ($fields->allOrdered() as $f) {
            if (\in_array($f->getType(), ['choice', 'radio'], true) && $f->getOptions() !== []) {
                $choiceFields[] = ['label' => $f->getLabel(), 'key' => $f->getKey(), 'options' => $f->getOptions()];
            }
        }

        return $this->render('admin/referentiels.html.twig', [
            'referentiels' => Referentiels::all(),
            'mcccTypes' => AttributeCatalog::MCCC_TYPES,
            'hourModalities' => AttributeCatalog::HOUR_MODALITIES,
            'hourPlaces' => AttributeCatalog::HOUR_PLACES,
            'families' => NodeFamily::cases(),
            'fieldTypes' => \App\Entity\FieldDef::TYPES,
            'choiceFields' => $choiceFields,
        ]);
    }
}
