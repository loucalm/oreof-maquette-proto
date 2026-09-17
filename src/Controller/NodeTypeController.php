<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FieldDef;
use App\Entity\NodeType;
use App\Enum\NodeFamily;
use App\Enum\NodeKind;
use App\Maquette\Maquette;
use App\Repository\FieldDefRepository;
use App\Repository\NodeTypeRepository;
use App\Repository\ReferentielRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Admin des types de nœuds — c'est ici que « rien n'est figé » : le responsable
 * crée les types et leur formulaire associé (via FieldDef, catalogue partagé).
 * La hiérarchie est propre à chaque formation (Formation::structure), pas au type.
 */
final class NodeTypeController extends AbstractController
{
    #[Route('/node-types', name: 'node_type_index', methods: ['GET'])]
    public function index(NodeTypeRepository $repo): Response
    {
        $all = $repo->findAllOrdered();

        return $this->render('node_type/index.html.twig', [
            'paramTypes' => array_values(array_filter($all, static fn (NodeType $t) => NodeFamily::Parameter === $t->getFamily())),
            'elpTypes' => array_values(array_filter($all, static fn (NodeType $t) => NodeFamily::Parameter !== $t->getFamily())),
        ]);
    }

    #[Route('/node-types/new', name: 'node_type_new', methods: ['GET', 'POST'])]
    #[Route('/node-types/{id}/edit', name: 'node_type_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, EntityManagerInterface $em, ReferentielRepository $referentiels, FieldDefRepository $fields, ?NodeType $nodeType = null): Response
    {
        $isNew = $nodeType === null;

        if ($request->isMethod('POST')) {
            $label = trim((string) $request->request->get('label')) ?: 'Type';
            $key = trim((string) $request->request->get('key'))
                ?: (new AsciiSlugger())->slug($label)->lower()->toString();

            if ($isNew) {
                $nodeType = new NodeType($key, $label);
                $nodeType->setFamily(NodeFamily::from((string) $request->request->get('family', 'structural')));
                $em->persist($nodeType);
            } else {
                $nodeType->setKey($key)->setLabel($label);
            }

            $nodeType
                ->setIcon(trim((string) $request->request->get('icon')) ?: null)
                ->setPosition($request->request->getInt('position'));

            // le reste des propriétés ne concerne pas les sections « Parameter » (Organisation, Présentation…)
            if (NodeFamily::Parameter !== $nodeType->getFamily()) {
                $nodeType
                    ->setKind(NodeKind::from((string) $request->request->get('kind', 'structurel')))
                    ->setEctsTarget($request->request->get('ectsTarget') !== '' ? $request->request->getInt('ectsTarget') : null)
                    ->setNumbered($request->request->getBoolean('numbered'))
                    ->setNumberStyle((string) $request->request->get('numberStyle', 'decimal'))
                    // case « numérotation héritée » = polarité inverse de globalNumbering (héritée = contextuel = pas global)
                    ->setGlobalNumbering(!$request->request->getBoolean('heritee'))
                    ->setChoiceTransformable($request->request->getBoolean('choiceTransformable'));

                $this->setCapability($nodeType, 'mutualisable', $request->request->getBoolean('mutualisable'), true);
            }

            $em->flush();
            $this->addFlash('success', 'Type enregistré.');

            return $this->redirectToRoute('node_type_edit', ['id' => $nodeType->getId()]);
        }

        return $this->render('node_type/edit.html.twig', [
            'nodeType' => $nodeType,
            'isNew' => $isNew,
            'kinds' => NodeKind::cases(),
            'fieldTypes' => FieldDef::TYPES,
            'referentielCapableTypes' => FieldDef::REFERENTIEL_CAPABLE_TYPES,
            'referentiels' => $referentiels->allOrdered(),
            'form' => $isNew ? [] : $this->formBuilderData($nodeType, $fields),
            'catalog' => $fields->allOrdered(),
        ]);
    }

    #[Route('/node-types/{id}/delete', name: 'node_type_delete', methods: ['POST'])]
    public function delete(NodeType $nodeType, EntityManagerInterface $em, Maquette $maquette): Response
    {
        if ($nodeType->isSystem()) {
            $this->addFlash('warning', sprintf('« %s » est un type du socle : il ne peut pas être supprimé.', $nodeType->getLabel()));

            return $this->redirectToRoute('node_type_index');
        }

        $used = $maquette->countNodesOfType($nodeType->getKey());
        if ($used > 0) {
            $this->addFlash('warning', sprintf(
                'Impossible de supprimer « %s » : %d ELP l’utilisent encore.',
                $nodeType->getLabel(),
                $used,
            ));

            return $this->redirectToRoute('node_type_index');
        }

        $em->remove($nodeType);
        $em->flush();
        $this->addFlash('info', 'Type supprimé.');

        return $this->redirectToRoute('node_type_index');
    }

    /**
     * Associe un champ au formulaire de ce type : réutilise le FieldDef existant
     * si la clé existe déjà dans le catalogue (ignore alors le reste du
     * formulaire), sinon en crée un nouveau.
     */
    #[Route('/administration/node-types/{id}/champs', name: 'node_type_field_add', methods: ['POST'])]
    public function fieldAdd(NodeType $nodeType, Request $request, EntityManagerInterface $em, FieldDefRepository $fields): Response
    {
        $label = trim((string) $request->request->get('label')) ?: 'Champ';
        $key = trim((string) $request->request->get('key'))
            ?: (new AsciiSlugger())->slug($label)->lower()->toString();

        $existing = $fields->findOneByKey($key);
        if (null !== $existing) {
            $this->addFlash('info', sprintf('Champ existant « %s » réutilisé tel quel.', $existing->getLabel()));
        } else {
            $field = (new FieldDef($key, $label))
                ->setTab((string) $request->request->get('tab', 'props'))
                ->setCategory($request->request->get('category'));
            $this->applyFieldRequest($field, $request);
            $em->persist($field);
        }

        $this->setCapability($nodeType, $key, true, $request->request->getBoolean('togglable'));
        $em->flush();
        $this->addFlash('success', 'Champ ajouté au formulaire.');

        return $this->redirectToRoute('node_type_edit', ['id' => $nodeType->getId()]);
    }

    /** Modifie le FieldDef partagé (impacte tous les types qui l'utilisent) + son état togglable pour CE type. */
    #[Route('/administration/node-types/{id}/champs/{fieldId}', name: 'node_type_field_edit', methods: ['POST'])]
    public function fieldEdit(NodeType $nodeType, int $fieldId, Request $request, EntityManagerInterface $em, FieldDefRepository $fields): Response
    {
        $field = $fields->find($fieldId);
        if (null === $field || !$nodeType->hasCapability($field->getKey())) {
            throw $this->createNotFoundException();
        }

        $field->setLabel(trim((string) $request->request->get('label')) ?: $field->getLabel());
        $field->setTab((string) $request->request->get('tab', $field->getTab()));
        $field->setCategory($request->request->get('category'));
        $this->applyFieldRequest($field, $request);

        $this->setCapability($nodeType, $field->getKey(), true, $request->request->getBoolean('togglable'));
        $em->flush();
        $this->addFlash('success', 'Champ modifié.');

        return $this->redirectToRoute('node_type_edit', ['id' => $nodeType->getId()]);
    }

    /** Retire le champ du formulaire de ce type ; supprime le FieldDef du catalogue si plus aucun autre type ne l'utilise. */
    #[Route('/administration/node-types/{id}/champs/{fieldId}/supprimer', name: 'node_type_field_remove', methods: ['POST'])]
    public function fieldRemove(NodeType $nodeType, int $fieldId, EntityManagerInterface $em, FieldDefRepository $fields, NodeTypeRepository $types): Response
    {
        $field = $fields->find($fieldId);
        if (null === $field) {
            throw $this->createNotFoundException();
        }

        $key = $field->getKey();
        $this->setCapability($nodeType, $key, false, false);
        $em->flush();

        if (!$field->isSystem() && 0 === $types->countUsingCapability($key)) {
            $em->remove($field);
            $em->flush();
        }

        $this->addFlash('info', 'Champ retiré du formulaire.');

        return $this->redirectToRoute('node_type_edit', ['id' => $nodeType->getId()]);
    }

    private function applyFieldRequest(FieldDef $field, Request $request): void
    {
        $options = [];
        foreach (preg_split('/\r?\n/', (string) $request->request->get('options')) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$v, $l] = array_pad(explode('|', $line, 2), 2, null);
            $v = trim((string) $v);
            if ($v !== '') {
                $options[$v] = trim((string) ($l ?? $v)) ?: $v;
            }
        }

        $min = $request->request->get('min');
        $max = $request->request->get('max');

        $field
            ->setType((string) $request->request->get('type', 'text'))
            ->setOptions($options)
            ->setReferentielKey($request->request->get('referentielKey'))
            ->setAllowExtra($request->request->getBoolean('allowExtra'))
            ->setQuickAdd($request->request->getBoolean('quickAdd'))
            ->setRequired($request->request->getBoolean('required'))
            ->setHelp($request->request->get('help'))
            ->setMin('' !== $min && null !== $min ? (float) $min : null)
            ->setMax('' !== $max && null !== $max ? (float) $max : null);
    }

    private function setCapability(NodeType $nodeType, string $key, bool $on, bool $togglable): void
    {
        $capabilities = $nodeType->getCapabilities();
        if ($on) {
            $capabilities[$key] = true;
        } else {
            unset($capabilities[$key]);
        }
        $nodeType->setCapabilities($capabilities);

        $locked = $nodeType->getLockedCapabilities();
        if ($on && !$togglable) {
            $locked[] = $key;
        } else {
            $locked = array_values(array_filter($locked, static fn ($k) => $k !== $key));
        }
        $nodeType->setLockedCapabilities($locked);
    }

    /**
     * Formulaire associé, groupé onglet > séparateur (catégorie) > champ, pour
     * les seules capacités actives de ce type qui correspondent à un FieldDef
     * du catalogue (les drapeaux hors-catalogue comme « mutualisable » n'y
     * apparaissent pas, ils sont gérés dans le bloc « Propriété de l'ELP »).
     *
     * @return array<string, array<string, list<FieldDef>>>
     */
    private function formBuilderData(NodeType $nodeType, FieldDefRepository $fields): array
    {
        $byKey = [];
        foreach ($fields->allOrdered() as $f) {
            $byKey[$f->getKey()] = $f;
        }

        $out = [];
        foreach ($nodeType->getCapabilities() as $key => $on) {
            if (!$on || !isset($byKey[$key])) {
                continue;
            }
            $field = $byKey[$key];
            $out[$field->getTab()][$field->getCategory() ?? ''][] = $field;
        }

        return $out;
    }
}
