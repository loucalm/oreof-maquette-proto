<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FieldDef;
use App\Entity\NodeType;
use App\FieldType\FieldTypeRegistry;
use App\Formulaire\FormResolver;
use App\Formulaire\FormUsage;
use App\Formulaire\ResolvedField;
use App\Formulaire\VariantFactory;
use App\Enum\NodeFamily;
use App\Enum\NodeKind;
use App\Maquette\Maquette;
use App\Repository\FieldDefRepository;
use App\Repository\NodeTypeRepository;
use App\Repository\ReferentielRepository;
use App\Twig\RoleExtension;
use App\Validation\TabValidator;
use App\Validation\ValidationRule;
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
    /**
     * Clés internes, jamais choisies à la main par un admin : « Bloc de choix »
     * n'existe qu'en convertissant un ELP déjà en place (cf.
     * MaquetteExtension::typesAllowedFor()), les types « compétence » sont
     * gérés depuis l'éditeur BCC (BccController) — ni l'un ni l'autre n'a sa
     * place dans la liste éditable des ELP.
     */
    private const INTERNAL_KEYS = ['bloc_choix'];

    public function __construct(
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly TabValidator $validator,
        private readonly FormResolver $forms,
        private readonly FormUsage $usage,
        private readonly VariantFactory $variants,
    ) {
    }

    #[Route('/node-types', name: 'node_type_index', methods: ['GET'])]
    public function index(NodeTypeRepository $repo): Response
    {
        $all = $repo->findAllOrdered();
        $isElp = static fn (NodeType $t) => NodeFamily::Parameter !== $t->getFamily()
            && NodeFamily::Competence !== $t->getFamily()
            && !\in_array($t->getKey(), self::INTERNAL_KEYS, true);

        return $this->render('node_type/index.html.twig', [
            'library' => $this->library(array_values(array_filter($all, static fn (NodeType $t) => NodeFamily::Parameter === $t->getFamily()))),
            'elpTypes' => array_values(array_filter($all, $isElp)),
        ]);
    }

    /**
     * Un formulaire de fiche utilisé par des formations ne se modifie qu'avec la confirmation
     * d'impact (`confirmImpact`), comme une version de type de diplôme utilisée : la
     * modification s'applique aussitôt à toutes les formations concernées.
     */
    private function impactGuard(NodeType $nodeType, Request $request): ?Response
    {
        if (NodeFamily::Parameter !== $nodeType->getFamily() || $request->request->getBoolean('confirmImpact') || [] === $this->usage->formationsConcerned($nodeType)) {
            return null;
        }
        if ($request->isXmlHttpRequest()) {
            return new Response(null, Response::HTTP_CONFLICT);
        }
        $this->addFlash('warning', 'Ce formulaire est utilisé par des formations : cochez la confirmation d’impact pour le modifier.');

        return $this->redirectToRoute('node_type_edit', ['id' => $nodeType->getId()]);
    }

    /** Formulaire de fiche : ses réglages de champ (libellé, aide, obligatoire, onglet, ordre) lui sont propres. */
    private static function isFicheForm(NodeType $nodeType): bool
    {
        return NodeFamily::Parameter === $nodeType->getFamily();
    }

    /** Champ verrouillé (cf. FieldDef::isLocked()) : visible/déplaçable dans le constructeur, jamais éditable ni retirable depuis là. */
    private function assertFieldEditable(FieldDef $field, NodeType $nodeType): ?Response
    {
        if (!$field->isLocked()) {
            return null;
        }
        $this->addFlash('warning', sprintf(
            '« %s » est un champ verrouillé : il écrit directement sur une propriété dédiée, sa définition ne peut pas être modifiée ici.',
            $field->getLabel(),
        ));

        return $this->redirectToRoute('node_type_edit', ['id' => $nodeType->getId()]);
    }

    #[Route('/node-types/new', name: 'node_type_new', methods: ['GET', 'POST'])]
    #[Route('/node-types/{id}/edit', name: 'node_type_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, EntityManagerInterface $em, ReferentielRepository $referentiels, FieldDefRepository $fields, NodeTypeRepository $types, RoleExtension $roles, ?NodeType $nodeType = null): Response
    {
        $isNew = $nodeType === null;
        if ($request->isMethod('POST')) {
            if (!$isNew && null !== ($blocked = $this->impactGuard($nodeType, $request))) {
                return $blocked;
            }
            $label = trim((string) $request->request->get('label')) ?: 'Type';
            // la clé n'est saisie que par le super-admin ; sinon générée (création) ou conservée (édition)
            $key = $roles->isSuperAdmin() ? trim((string) $request->request->get('key')) : '';
            if ('' === $key) {
                $key = $isNew ? $this->uniqueKey($label, $types) : $nodeType->getKey();
            }
            $owner = $types->findOneByKey($key);
            if (null !== $owner && $owner !== $nodeType) {
                $this->addFlash('warning', sprintf('La clé « %s » est déjà prise par « %s ».', $key, $owner->getLabel()));

                return $isNew ? $this->redirectToRoute('node_type_new') : $this->redirectToRoute('node_type_edit', ['id' => $nodeType->getId()]);
            }

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
            'fieldTypes' => $this->fieldTypes->choices(),
            'fieldTypesConfig' => $this->fieldTypes->editorConfig(),
            'referentiels' => $referentiels->allOrdered(),
            'form' => $isNew ? [] : $this->formBuilderData($nodeType, $fields),
            'rules' => $isNew ? [] : $this->rulesByTab($nodeType),
            'catalog' => $fields->allOrdered(),
            'usage' => $isNew || !self::isFicheForm($nodeType) ? [] : $this->usage->versionsUsing($nodeType),
            'concerned' => $isNew || !self::isFicheForm($nodeType) ? [] : $this->usage->formationsConcerned($nodeType),
            'newParentFields' => !$isNew && $nodeType->isVariant() ? $this->forms->newParentFields($nodeType) : [],
            'retour' => self::returnUrl($request),
        ]);
    }

    #[Route('/node-types/{id}/delete', name: 'node_type_delete', methods: ['POST'])]
    public function delete(NodeType $nodeType, EntityManagerInterface $em, Maquette $maquette): Response
    {
        if ($nodeType->isSystem()) {
            $this->addFlash('warning', sprintf('« %s » est un type du socle : il ne peut pas être supprimé.', $nodeType->getLabel()));

            return $this->redirectToRoute('node_type_index');
        }

        if ($this->usage->isUsed($nodeType) || $this->usage->hasVariants($nodeType)) {
            $this->addFlash('warning', sprintf('Impossible de supprimer « %s » : un canevas de diplôme l’utilise, ou une variante en dérive.', $nodeType->getLabel()));

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
        if (null !== ($blocked = $this->impactGuard($nodeType, $request))) {
            return $blocked;
        }

        $label = trim((string) $request->request->get('label')) ?: 'Champ';
        $key = trim((string) $request->request->get('key'))
            ?: (new AsciiSlugger())->slug($label)->lower()->toString();

        $existing = $fields->findOneByKey($key);
        if (null !== $existing) {
            $this->addFlash('info', sprintf('Champ existant « %s » réutilisé tel quel.', $existing->getLabel()));
        } else {
            $field = (new FieldDef($key, $label))
                ->setTab((string) $request->request->get('tab', 'props'));
            $this->applyFieldRequest($field, $request);
            $em->persist($field);
        }

        $this->setCapability($nodeType, $key, true, $request->request->getBoolean('togglable'));
        $em->flush();
        $this->addFlash('success', 'Champ ajouté au formulaire.');

        return $this->redirectToRoute('node_type_edit', ['id' => $nodeType->getId()]);
    }

    /** Modifie le FieldDef partagé (impacte tous les types qui l'utilisent) + son état togglable pour CE type. */
    #[Route('/administration/node-types/{id}/champs/{fieldId}', name: 'node_type_field_edit', methods: ['POST'], requirements: ['fieldId' => '\d+'])]
    public function fieldEdit(NodeType $nodeType, int $fieldId, Request $request, EntityManagerInterface $em, FieldDefRepository $fields): Response
    {
        if (null !== ($blocked = $this->impactGuard($nodeType, $request))) {
            return $blocked;
        }

        $field = $fields->find($fieldId);
        if (null === $field || !\array_key_exists($field->getKey(), $this->forms->capabilities($nodeType))) {
            throw $this->createNotFoundException();
        }
        if (null !== ($blocked = $this->assertFieldEditable($field, $nodeType))) {
            return $blocked;
        }

        $label = trim((string) $request->request->get('label'));
        if (self::isFicheForm($nodeType)) {
            // libellé, aide, obligatoire et onglet sont propres à ce formulaire ; le type et ses options restent partagés
            $settings = '' !== $label ? ['label' => $label] : [];
            if ('separator' !== $field->getType()) {
                $settings += [
                    'help' => trim((string) $request->request->get('help')) ?: null,
                    'required' => $request->request->getBoolean('required'),
                ];
                if ($request->request->has('tab')) {
                    $settings['tab'] = (string) $request->request->get('tab');
                }
                $this->applyFieldRequest($field, $request, false);
            }
            $nodeType->setFieldSetting($field->getKey(), $settings);
        } else {
            $field->setLabel('' !== $label ? $label : $field->getLabel());
            // un séparateur n'a qu'un libellé — inutile (et dangereux) de repasser par applyFieldRequest,
            // qui réinitialiserait son type faute des autres champs du formulaire complet.
            if ('separator' !== $field->getType()) {
                $field->setTab((string) $request->request->get('tab', $field->getTab()));
                $this->applyFieldRequest($field, $request);
            }
            $this->setCapability($nodeType, $field->getKey(), true, $request->request->getBoolean('togglable'));
        }
        $em->flush();
        $this->addFlash('success', 'Champ modifié.');

        return $this->redirectToRoute('node_type_edit', ['id' => $nodeType->getId()]);
    }

    /** Insère un séparateur (ligne d'organisation) en fin de cet onglet, pour ce type. */
    #[Route('/administration/node-types/{id}/champs/separateur', name: 'node_type_separator_add', methods: ['POST'])]
    public function separatorAdd(NodeType $nodeType, Request $request, EntityManagerInterface $em, FieldDefRepository $fields): Response
    {
        if (null !== ($blocked = $this->impactGuard($nodeType, $request))) {
            return $blocked;
        }

        $tab = (string) $request->request->get('tab', 'props');
        $label = trim((string) $request->request->get('label')) ?: 'Séparateur';
        $key = 'sep_'.bin2hex(random_bytes(4));

        $tabFields = array_filter($fields->allOrdered(), static fn (FieldDef $f) => $f->getTab() === $tab);
        $maxPosition = array_reduce($tabFields, static fn (int $max, FieldDef $f) => max($max, $f->getPosition()), 0);

        $separator = (new FieldDef($key, $label))
            ->setType('separator')
            ->setTab($tab)
            ->setPosition($maxPosition + 5);
        $em->persist($separator);

        $this->setCapability($nodeType, $key, true, true);
        $em->flush();
        $this->addFlash('success', 'Séparateur ajouté.');

        return $this->redirectToRoute('node_type_edit', ['id' => $nodeType->getId()]);
    }

    /**
     * Réordonne les champs/séparateurs d'un onglet par glisser-déposer — les
     * positions déjà utilisées par cet onglet sont juste réattribuées dans le
     * nouvel ordre, ce qui laisse les autres onglets (et donc leur ordre
     * d'apparition) inchangés.
     */
    #[Route('/administration/node-types/{id}/champs/{tab}/reordonner', name: 'node_type_fields_reorder', methods: ['POST'])]
    public function fieldsReorder(NodeType $nodeType, string $tab, Request $request, EntityManagerInterface $em, FieldDefRepository $fields): Response
    {
        if (null !== ($blocked = $this->impactGuard($nodeType, $request))) {
            return $blocked;
        }

        if (self::isFicheForm($nodeType)) {
            $positions = [];
            $keys = [];
            foreach ($this->forms->fields($nodeType, true) as $f) {
                if ($f->tab === $tab) {
                    $keys[] = $f->getKey();
                    $positions[] = $f->position;
                }
            }
            sort($positions);
            foreach (array_values(array_intersect((array) $request->request->all('chain'), $keys)) as $i => $key) {
                $nodeType->setFieldSetting($key, ['position' => $positions[$i] ?? end($positions)]);
            }
        } else {
            $byKey = [];
            $positions = [];
            foreach ($fields->allOrdered() as $f) {
                if ($f->getTab() === $tab && $nodeType->hasCapability($f->getKey())) {
                    $byKey[$f->getKey()] = $f;
                    $positions[] = $f->getPosition();
                }
            }
            sort($positions);

            $orderedKeys = array_values(array_intersect((array) $request->request->all('chain'), array_keys($byKey)));
            foreach ($orderedKeys as $i => $key) {
                $byKey[$key]->setPosition($positions[$i] ?? end($positions));
            }
        }
        $em->flush();

        if ($request->isXmlHttpRequest()) {
            return new Response(null, Response::HTTP_NO_CONTENT);
        }

        return $this->redirectToRoute('node_type_edit', ['id' => $nodeType->getId()]);
    }

    /** Retire le champ du formulaire de ce type ; supprime le FieldDef du catalogue si plus aucun autre type ne l'utilise. */
    #[Route('/administration/node-types/{id}/champs/{fieldId}/supprimer', name: 'node_type_field_remove', methods: ['POST'], requirements: ['fieldId' => '\d+'])]
    public function fieldRemove(NodeType $nodeType, int $fieldId, Request $request, EntityManagerInterface $em, FieldDefRepository $fields, NodeTypeRepository $types): Response
    {
        if (null !== ($blocked = $this->impactGuard($nodeType, $request))) {
            return $blocked;
        }

        $field = $fields->find($fieldId);
        if (null === $field) {
            throw $this->createNotFoundException();
        }
        if (null !== ($blocked = $this->assertFieldEditable($field, $nodeType))) {
            return $blocked;
        }

        $key = $field->getKey();
        $parent = $nodeType->getParent();
        if (null !== $parent && \array_key_exists($key, $this->forms->capabilities($parent))) {
            // champ hérité : la variante le masque, le formulaire de départ le garde
            $nodeType->setCapabilities(array_merge($nodeType->getCapabilities(), [$key => false]));
            $em->flush();
            $this->addFlash('info', 'Champ masqué dans cette variante.');

            return $this->redirectToRoute('node_type_edit', ['id' => $nodeType->getId()]);
        }
        $this->setCapability($nodeType, $key, false, false);
        $em->flush();

        if (!$field->isSystem() && 0 === $types->countUsingCapability($key)) {
            $em->remove($field);
            $em->flush();
        }

        $this->addFlash('info', 'Champ retiré du formulaire.');

        return $this->redirectToRoute('node_type_edit', ['id' => $nodeType->getId()]);
    }

    /**
     * Ajoute une règle de validation à un onglet du type : « obligatoire sous
     * condition » (un champ + une condition sur un autre) ou « au moins un parmi ».
     */
    #[Route('/administration/node-types/{id}/regles', name: 'node_type_rule_add', methods: ['POST'])]
    public function ruleAdd(NodeType $nodeType, Request $request, EntityManagerInterface $em): Response
    {
        if (null !== ($blocked = $this->impactGuard($nodeType, $request))) {
            return $blocked;
        }

        $kind = (string) $request->request->get('kind');
        $fields = ValidationRule::ONE_OF === $kind
            ? array_values(array_filter(array_map('strval', $request->request->all('fields'))))
            : array_values(array_filter([(string) $request->request->get('field')]));
        $whenOp = (string) $request->request->get('whenOp', 'eq');

        $valid = match ($kind) {
            ValidationRule::REQUIRED_IF => 1 === \count($fields)
                && '' !== (string) $request->request->get('whenField')
                && isset(ValidationRule::OPERATORS[$whenOp])
                && ('filled' === $whenOp || '' !== trim((string) $request->request->get('whenValue'))),
            ValidationRule::ONE_OF => \count($fields) >= 2,
            default => false,
        };
        if (!$valid) {
            $this->addFlash('warning', ValidationRule::ONE_OF === $kind
                ? 'Cocher au moins deux champs pour « Au moins un parmi ».'
                : 'Choisir le champ, le champ de la condition et la valeur attendue.');

            return $this->redirectToRoute('node_type_edit', ['id' => $nodeType->getId()]);
        }

        $rule = new ValidationRule(
            id: bin2hex(random_bytes(4)),
            kind: $kind,
            tab: (string) $request->request->get('tab', 'props'),
            fields: $fields,
            whenField: ValidationRule::REQUIRED_IF === $kind ? (string) $request->request->get('whenField') : null,
            whenOp: ValidationRule::REQUIRED_IF === $kind ? $whenOp : null,
            whenValue: ValidationRule::REQUIRED_IF === $kind && 'filled' !== $whenOp ? trim((string) $request->request->get('whenValue')) : null,
            message: trim((string) $request->request->get('message')) ?: null,
        );
        $nodeType->addValidationRule($rule);
        $em->flush();
        $this->addFlash('success', 'Règle ajoutée : '.$this->validator->describe($rule));

        return $this->redirectToRoute('node_type_edit', ['id' => $nodeType->getId()]);
    }

    #[Route('/administration/node-types/{id}/regles/{ruleId}/supprimer', name: 'node_type_rule_remove', methods: ['POST'])]
    public function ruleRemove(NodeType $nodeType, string $ruleId, Request $request, EntityManagerInterface $em): Response
    {
        if (null !== ($blocked = $this->impactGuard($nodeType, $request))) {
            return $blocked;
        }

        $nodeType->removeValidationRule($ruleId);
        $em->flush();
        $this->addFlash('info', 'Règle retirée.');

        return $this->redirectToRoute('node_type_edit', ['id' => $nodeType->getId()]);
    }

    /** Crée une variante du formulaire (bibliothèque) et ouvre son écran « Adapter ». */
    #[Route('/administration/node-types/{id}/variante', name: 'node_type_variant_new', methods: ['POST'])]
    public function variantNew(NodeType $nodeType, Request $request, EntityManagerInterface $em): Response
    {
        $variant = $this->variants->create($nodeType, (string) $request->request->get('label'));
        $em->flush();
        $this->addFlash('success', sprintf('Variante « %s » créée.', $variant->getLabel()));

        return $this->redirectToRoute('node_type_edit', array_filter(['id' => $variant->getId(), 'retour' => self::returnUrl($request)]));
    }

    /** Affiche ou masque un champ dans ce formulaire ; un champ verrouillé reste affiché. */
    #[Route('/administration/node-types/{id}/champs/{fieldId}/affichage', name: 'node_type_field_visibility', methods: ['POST'], requirements: ['fieldId' => '\d+'])]
    public function fieldVisibility(NodeType $nodeType, int $fieldId, Request $request, EntityManagerInterface $em, FieldDefRepository $fields): Response
    {
        $field = $fields->find($fieldId);
        if (null === $field || !\array_key_exists($field->getKey(), $this->forms->capabilities($nodeType))) {
            throw $this->createNotFoundException();
        }
        if (null !== ($blocked = $this->impactGuard($nodeType, $request))) {
            return $blocked;
        }
        if ($field->isLocked()) {
            $this->addFlash('warning', sprintf('« %s » est un champ verrouillé : il reste affiché.', $field->getLabel()));

            return $this->redirectToRoute('node_type_edit', ['id' => $nodeType->getId()]);
        }
        $nodeType->setCapabilities(array_merge($nodeType->getCapabilities(), [$field->getKey() => $request->request->getBoolean('visible')]));
        $em->flush();

        return $this->redirectToRoute('node_type_edit', ['id' => $nodeType->getId()]);
    }

    /** La variante prend acte des champs apparus dans son formulaire de départ. */
    #[Route('/administration/node-types/{id}/nouveautes-vues', name: 'node_type_variant_ack', methods: ['POST'])]
    public function variantAcknowledge(NodeType $nodeType, EntityManagerInterface $em): Response
    {
        $this->forms->acknowledgeParentFields($nodeType);
        $em->flush();

        return $this->redirectToRoute('node_type_edit', ['id' => $nodeType->getId()]);
    }

    /**
     * Formulaires de fiche en arbre (formulaire de départ, puis ses variantes en retrait), avec le
     * nombre de versions de diplôme qui les utilisent.
     *
     * @param list<NodeType> $forms
     *
     * @return list<array{type: NodeType, depth: int, usage: int}>
     */
    private function library(array $forms): array
    {
        $out = [];
        $walk = function (?NodeType $parent, int $depth) use (&$walk, &$out, $forms): void {
            foreach ($forms as $form) {
                if ($form->getParent() === $parent) {
                    $out[] = ['type' => $form, 'depth' => $depth, 'usage' => \count($this->usage->versionsUsing($form))];
                    $walk($form, $depth + 1);
                }
            }
        };
        $walk(null, 0);

        return $out;
    }

    /** URL de retour (canevas d'un diplôme), acceptée seulement si elle reste dans /diplomes/. */
    private static function returnUrl(Request $request): ?string
    {
        $url = (string) ($request->query->get('retour') ?? $request->request->get('retour'));

        return str_starts_with($url, '/diplomes/') ? $url : null;
    }

    /**
     * Règles du type groupées par onglet, avec leur phrase lisible.
     *
     * @return array<string, list<array{id: string, text: string, message: ?string}>>
     */
    private function rulesByTab(NodeType $nodeType): array
    {
        $out = [];
        foreach ($nodeType->getValidationRules() as $rule) {
            $out[$rule->tab][] = ['id' => $rule->id, 'text' => $this->validator->describe($rule), 'message' => $rule->message];
        }

        return $out;
    }

    /** Clé dérivée du libellé (« Unité d'enseignement » → unite_d_enseignement), suffixée _2, _3… si déjà prise. */
    private function uniqueKey(string $label, NodeTypeRepository $types): string
    {
        $base = (new AsciiSlugger())->slug($label, '_')->lower()->toString() ?: 'type';
        $key = $base;
        for ($i = 2; null !== $types->findOneByKey($key); ++$i) {
            $key = $base.'_'.$i;
        }

        return $key;
    }

    /** @param bool $presentation false pour un formulaire de fiche : obligatoire et aide sont réglés par formulaire */
    private function applyFieldRequest(FieldDef $field, Request $request, bool $presentation = true): void
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
            ->setType($this->fieldTypes->has($type = (string) $request->request->get('type', 'text')) ? $type : 'text')
            ->setOptions($options)
            ->setReferentielKey($request->request->get('referentielKey'))
            ->setAllowExtra($request->request->getBoolean('allowExtra'))
            ->setQuickAdd($request->request->getBoolean('quickAdd'))
            ->setMin('' !== $min && null !== $min ? (float) $min : null)
            ->setMax('' !== $max && null !== $max ? (float) $max : null);
        if ($presentation) {
            $field
                ->setRequired($request->request->getBoolean('required'))
                ->setHelp($request->request->get('help'));
        }
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
     * Formulaire associé, groupé onglet > liste ordonnée (champs et séparateurs
     * mêlés, dans l'ordre où ils seront affichés/glissés), pour les seules
     * capacités actives de ce type qui correspondent à un FieldDef du
     * catalogue (les drapeaux hors-catalogue comme « mutualisable » n'y
     * apparaissent pas, ils sont gérés dans le bloc « Propriété de l'ELP »).
     *
     * @return array<string, list<FieldDef|ResolvedField>>
     */
    private function formBuilderData(NodeType $nodeType, FieldDefRepository $fields): array
    {
        $out = [];
        if (self::isFicheForm($nodeType)) {
            foreach ($this->forms->fields($nodeType, $nodeType->isVariant()) as $f) {
                $out[$f->tab][] = $f;
            }

            return $out;
        }
        foreach ($fields->activeOrderedFor($nodeType) as $f) {
            $out[$f->getTab()][] = $f;
        }

        return $out;
    }
}
