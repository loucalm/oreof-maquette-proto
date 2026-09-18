<?php

declare(strict_types=1);

namespace App\Twig;

use App\Controller\FormationController;
use App\Controller\NodeTypeController;
use App\Entity\Formation;
use App\Entity\NodeType;
use App\Maquette\AttributeCatalog;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Maquette\McccValidator;
use App\Entity\StructureTemplate;
use App\Repository\FieldDefRepository;
use App\Repository\MccTypeRepository;
use App\Repository\NodeTypeRepository;
use App\Repository\ReferentielRepository;
use App\Repository\StructureTemplateRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class MaquetteExtension extends AbstractExtension
{
    public function __construct(
        private readonly NodeTypeRepository $types,
        private readonly Maquette $maquette,
        private readonly AttributeCatalog $catalog,
        private readonly UrlGeneratorInterface $router,
        private readonly \App\Maquette\Completion $completion,
        private readonly \App\Maquette\Numbering $numbering,
        private readonly ReferentielRepository $referentiels,
        private readonly MccTypeRepository $mcctypes,
        private readonly McccValidator $mcccValidator,
        private readonly StructureTemplateRepository $templates,
        private readonly FieldDefRepository $fields,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('types_allowed_for', $this->typesAllowedFor(...)),
            new TwigFunction('valid_parents', $this->validParents(...)),
            new TwigFunction('can_be_root', $this->canBeRoot(...)),
            new TwigFunction('parcours_candidates', $this->parcoursCandidates(...)),
            new TwigFunction('structure_rows', $this->structureRows(...)),
            new TwigFunction('structure_addable', $this->structureAddable(...)),
            new TwigFunction('template_structure_rows', $this->templateStructureRows(...)),
            new TwigFunction('template_structure_addable', $this->templateStructureAddable(...)),
            new TwigFunction('node_display_label', $this->nodeDisplayLabel(...)),
            new TwigFunction('template_for_diplome', fn (?string $d) => null !== $d ? $this->templates->findOneByDiplome($d) : null),
            new TwigFunction('template_type_at_depth', $this->templateTypeAtDepth(...)),
            new TwigFunction('root_type', $this->rootType(...)),
            new TwigFunction('type_meta', $this->typeMeta(...)),
            new TwigFunction('types_by_key', fn () => $this->types->findAllIndexed()),
            new TwigFunction('parcours_nodes', fn (Formation $f) => $this->maquette->open($f)->parcoursNodes()),
            new TwigFunction('mono_blocs', fn (Formation $f) => $this->maquette->open($f)->competenceBlocs()),
            new TwigFunction('node_path', $this->nodePath(...)),
            new TwigFunction('node_url', $this->nodeUrl(...)),
            new TwigFunction('number_index', fn (int $n, string $style) => $this->numbering->format($n, $style)),
            new TwigFunction('param_nav', $this->paramNav(...)),
            new TwigFunction('capability_labels', $this->capabilityLabels(...)),
            new TwigFunction('node_tab_status', $this->nodeTabStatus(...)),
            new TwigFunction('bcc_competences', $this->bccCompetences(...)),
            new TwigFunction('period_unit', $this->periodUnit(...)),
            new TwigFunction('param_sections', static fn () => FormationController::PARAM_SECTIONS),
            new TwigFunction('param_status', fn (Formation $f, string $k) => $this->completion->formationParamStatus($f, $k)),
            new TwigFunction('param_active_fields', $this->paramActiveFields(...)),
            new TwigFunction('param_field_value', fn (Formation $f, string $k, array $data) => $this->completion->formationFieldValue($f, $k, $data)),
            new TwigFunction('bcc_status', $this->bccStatus(...)),
            new TwigFunction('parcours_param_sections', static fn () => FormationController::PARCOURS_PARAM_SECTIONS),
            new TwigFunction('parcours_param_status', fn (TreeNode $p, string $k) => $this->completion->parcoursParamStatus($p, $k)),
            new TwigFunction('parcours_param_active_fields', $this->parcoursParamActiveFields(...)),
            new TwigFunction('parcours_field_value', fn (TreeNode $p, string $k, array $data) => $this->completion->parcoursFieldValue($p, $k, $data)),
            new TwigFunction('node_type_param_editable', static fn (NodeType $t) => \in_array($t->getKey(), NodeTypeController::EDITABLE_PARAM_KEYS, true)),
            new TwigFunction('referentiel', fn (string $key) => $this->referentiels->values($key)),
            new TwigFunction('mccc_types_for', fn (Formation $f) => $this->mcctypes->availableFor(
                null !== $f->getDiplome() ? $this->templates->findOneByDiplome($f->getDiplome()) : null,
            )),
            new TwigFunction('mccc_results', fn (TreeNode $n) => $this->mcccValidator->forNode($n)),
            new TwigFunction('mccc_type_label', function (string $key): ?string {
                $t = $this->mcctypes->findOneByKey($key);

                return null !== $t ? $t->getShortLabel().' — '.$t->getLabel() : null;
            }),
        ];
    }

    /**
     * Types ajoutables sous ce nœud DEPUIS LE FORMULAIRE : l'enfant prévu par
     * le squelette de la formation (résolu via l'hôte effectif — cf.
     * TreeNode::getEffectiveHostTypeKey). « Bloc de choix » n'est PAS proposé
     * ici — ce n'est pas un ELP qu'on choisit dans une liste, il n'existe que
     * comme résultat de « Transformer en choix » sur un ELP déjà en place
     * (cf. NodeController::convertToChoice) ; TreeNode::allowedChildTypeKeys()
     * continue de le considérer « accepté » pour que ce bouton fonctionne,
     * y compris pour imbriquer un choix dans un choix (en convertissant une
     * de ses alternatives déjà en place).
     *
     * @return list<NodeType>
     */
    public function typesAllowedFor(TreeNode $node): array
    {
        $byKey = $this->types->findAllIndexed();

        return array_values(array_filter(array_map(
            static fn (string $key) => $byKey[$key] ?? null,
            $node->allowedChildTypeKeys(),
        ), static fn (?NodeType $t) => null !== $t && 'bloc_choix' !== $t->getKey()));
    }

    /**
     * Lignes du tableau « Configuration de la structure ».
     *
     * @return list<array{key: string, type: ?NodeType, fixed: bool, mono: bool}>
     */
    public function structureRows(Formation $formation): array
    {
        $byKey = $this->types->findAllIndexed();
        $rows = [];

        foreach ($formation->getEffectiveStructure() as $i => $key) {
            $isRootParcours = 0 === $i && 'parcours' === $key;
            $rows[] = [
                'key' => $key,
                'type' => $byKey[$key] ?? null,
                'fixed' => $isRootParcours,
                'mono' => $isRootParcours && $formation->isSansParcours(),
            ];
        }

        return $rows;
    }

    /**
     * Types que l'on peut ajouter au bout du squelette.
     *
     * @return list<NodeType>
     */
    public function structureAddable(Formation $formation): array
    {
        $inChain = array_flip($formation->getEffectiveStructure());

        return array_values(array_filter(
            $this->types->forTree(),
            static fn (NodeType $t) => 'parcours' !== $t->getKey() && !isset($inChain[$t->getKey()]),
        ));
    }

    /**
     * Lignes du tableau structurel d'un template : « parcours » toujours en
     * position 0 (comme `Formation::getEffectiveStructure()` — un vrai nœud en
     * multi, porté par la formation elle-même en mono), dernier maillon fixé
     * en dernière position — les deux non retirables depuis ce tableau.
     *
     * @return list<array{key: string, type: ?NodeType, fixed: bool, mono: bool}>
     */
    public function templateStructureRows(StructureTemplate $template): array
    {
        $byKey = $this->types->findAllIndexed();
        $chain = array_merge(['parcours'], $template->getStructure());
        $last = \count($chain) - 1;

        $rows = [];
        foreach ($chain as $i => $key) {
            $rows[] = [
                'key' => $key,
                'type' => $byKey[$key] ?? null,
                'fixed' => 0 === $i || $i === $last,
                'mono' => 0 === $i && !$template->isAvecParcours(),
            ];
        }

        return $rows;
    }

    /**
     * Type imposé par le tableau structurel à cette profondeur de l'arbre
     * (`parentPath` = chemin `TemplateTree`, « » pour la racine), ou `null` si
     * le tableau ne couvre pas (encore) cette profondeur — l'ajout reste alors
     * libre (choix parmi tous les types), comportement permissif du proto.
     */
    public function templateTypeAtDepth(StructureTemplate $template, string $parentPath): ?NodeType
    {
        $depth = '' === trim($parentPath) ? 0 : substr_count($parentPath, '.') + 1;
        $index = $template->isAvecParcours() ? $depth - 1 : $depth;
        $byKey = $this->types->findAllIndexed();

        if ($index < 0) {
            return $byKey['parcours'] ?? null;
        }

        $key = $template->getStructure()[$index] ?? null;

        return null !== $key ? ($byKey[$key] ?? null) : null;
    }

    /** @return list<NodeType> */
    public function templateStructureAddable(StructureTemplate $template): array
    {
        $inChain = array_flip($template->getStructure());

        return array_values(array_filter(
            $this->types->forTree(),
            static fn (NodeType $t) => 'parcours' !== $t->getKey() && !isset($inChain[$t->getKey()]),
        ));
    }

    /**
     * Libellé affiché d'un nœud : sa saisie libre pour un type structurel,
     * sinon (type temporel) le libellé du type + sa référence numérotée
     * calculée (ex. « Année 1 ») — jamais de saisie côté éditeur pour ceux-là.
     */
    public function nodeDisplayLabel(TreeNode $node, ?string $ref = null): string
    {
        if (!$node->getType()->isTemporel()) {
            return $node->getDisplayLabel();
        }

        return null !== $ref && '' !== $ref
            ? $node->getType()->getLabel().' '.$ref
            : $node->getType()->getLabel();
    }

    public function rootType(Formation $formation): ?NodeType
    {
        $key = $formation->getVisibleRootTypeKey();

        return null !== $key ? ($this->types->findAllIndexed()[$key] ?? null) : null;
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public function typeMeta(): array
    {
        return array_map(
            static fn (NodeType $t) => ['label' => $t->getLabel(), 'icon' => $t->getIcon() ?? ''],
            $this->types->findAllIndexed(),
        );
    }

    /**
     * Parents valides pour ce nœud : les nœuds de la formation dont le type est,
     * dans le squelette, le parent du type de ce nœud — hors lui-même et sa
     * descendance.
     *
     * @return list<TreeNode>
     */
    public function validParents(TreeNode $node): array
    {
        $parentKey = $node->getFormation()?->getParentTypeKey($node->getType()->getKey());
        if ($parentKey === null || $node->getFormation() === null) {
            return [];
        }

        $descendants = [];
        $collect = static function (TreeNode $n) use (&$collect, &$descendants): void {
            $descendants[$n->getId()] = true;
            foreach ($n->getChildren() as $c) {
                $collect($c);
            }
        };
        $collect($node);

        return array_values(array_filter(
            $this->maquette->open($node->getFormation())->allNodes(),
            static fn (TreeNode $cand) => !isset($descendants[$cand->getId()])
                && $cand->getType()->getKey() === $parentKey,
        ));
    }

    public function canBeRoot(TreeNode $node): bool
    {
        return (bool) $node->getFormation()?->canBeRootType($node->getType()->getKey());
    }

    /**
     * Parcours pouvant servir de parent (ramification).
     *
     * @return list<TreeNode>
     */
    public function parcoursCandidates(TreeNode $node): array
    {
        if ($node->getFormation() === null) {
            return [];
        }
        $all = $this->maquette->open($node->getFormation())->parcoursNodes();

        $forbidden = [$node->getId() => true];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($all as $p) {
                $pp = $p->getParcoursParent()?->getId();
                if ($pp !== null && isset($forbidden[$pp]) && !isset($forbidden[$p->getId()])) {
                    $forbidden[$p->getId()] = true;
                    $changed = true;
                }
            }
        }

        return array_values(array_filter(
            $all,
            fn (TreeNode $p) => !isset($forbidden[$p->getId()])
                && TreeNode::parcoursPeriodsAllowChild($p->getPeriodeDebut(), $p->getPeriodeFin(), $node->getPeriodeDebut(), $node->getPeriodeFin()),
        ));
    }

    /**
     * Libellé de l'unité de temps de la formation.
     */
    public function periodUnit(Formation $formation): string
    {
        if ($formation->getCalendarUnit()) {
            return $formation->getCalendarUnit();
        }
        $key = $formation->getChildTypeKey('parcours');
        $type = $key !== null ? ($this->types->findAllIndexed()[$key] ?? null) : null;

        return $type?->getLabel() ?? 'Période';
    }

    /**
     * Statut du référentiel BCC (pastille). Contexte = la formation (mono) ou un
     * nœud parcours (multi).
     */
    public function bccStatus(Formation|TreeNode $context): string
    {
        $blocs = $context instanceof TreeNode
            ? $context->getBccBlocs()
            : $this->maquette->open($context)->competenceBlocs();

        return $this->completion->bccStatus($blocs);
    }

    /**
     * URL d'une route « nœud » (fid + nid), pour éviter de répéter le couple
     * dans chaque gabarit. Ex. `node_url(n)`, `node_url(n, 'node_save')`,
     * `node_url(p, 'parcours_param', {key: 'organisation'})`.
     *
     * @param array<string, scalar> $extra
     */
    public function nodeUrl(TreeNode $node, string $route = 'node_panel', array $extra = []): string
    {
        return $this->router->generate($route, [
            'fid' => $node->getFormation()?->getId(),
            'nid' => $node->getId(),
        ] + $extra);
    }

    /** @return list<TreeNode> du racine jusqu'au nœud. */
    public function nodePath(TreeNode $node): array
    {
        $path = [];
        for ($c = $node; $c !== null; $c = $c->getParent()) {
            array_unshift($path, $c);
        }

        return $path;
    }

    /**
     * Statut d'un onglet du panneau de nœud (pastille).
     */
    public function nodeTabStatus(TreeNode $node, string $tab): string
    {
        $caps = $node->effectiveCapabilities();

        if ($tab === 'props') {
            $missing = trim($node->getLabel()) === '';
            if (($caps['code'] ?? false) && 'ec' === $node->getType()->getKey() && trim((string) $node->getCode()) === '') {
                $missing = true;
            }
            foreach ($this->catalog->all() as $key => $def) {
                if (($def['domain'] ?? null) !== 'props' || !($caps[$key] ?? false) || !($def['required'] ?? false)) {
                    continue;
                }
                $v = $node->getAttribute($key);
                if ($v === null || $v === '' || $v === []) {
                    $missing = true;
                }
            }

            return $missing ? 'incomplete' : 'ok';
        }

        $required = 0;
        $filled = 0;
        foreach ($this->catalog->all() as $key => $def) {
            if (($def['domain'] ?? null) !== $tab || !($caps[$key] ?? false)) {
                continue;
            }
            $v = $node->getAttribute($key);
            $has = $key === 'hours'
                ? AttributeCatalog::hoursProvided($v)
                : !($v === null || $v === '' || $v === []);
            if ($def['required'] ?? false) {
                ++$required;
                $filled += $has ? 1 : 0;
            } elseif ($has) {
                ++$filled;
            }
        }

        if ($filled === 0) {
            return 'empty';
        }

        return ($required > 0 && $filled < $required) ? 'incomplete' : 'ok';
    }

    /**
     * Compétences du référentiel (BCC) proposables sur ce nœud, groupées par bloc.
     *
     * @return list<array{bloc: string, transversal: bool, items: list<array{label: string, code: ?string, description: string}>}>
     */
    public function bccCompetences(TreeNode $node): array
    {
        $parcours = null;
        for ($c = $node; $c !== null; $c = $c->getParent()) {
            if ($c->isParcours()) {
                $parcours = $c;
                break;
            }
        }
        $blocs = $parcours !== null
            ? $parcours->getBccBlocs()
            : ($node->getFormation() !== null ? $this->maquette->open($node->getFormation())->competenceBlocs() : []);

        $out = [];
        foreach ($blocs as $bloc) {
            $items = [];
            foreach ($bloc->getChildren() as $comp) {
                if ($comp->getType()->getKey() === 'competence') {
                    $items[] = [
                        'label' => $comp->getLabel(),
                        'code' => $comp->getCode(),
                        'description' => $comp->getDescription(),
                    ];
                }
            }
            if ($items !== []) {
                $out[] = ['bloc' => $bloc->getLabel(), 'transversal' => $bloc->isTransversalBloc(), 'items' => $items];
            }
        }

        return $out;
    }

    /**
     * Navigation rapide entre « Paramètre du nœud » : pour chaque type du
     * squelette, un nœud représentatif (le courant, sinon un ancêtre de ce type,
     * sinon le premier nœud de ce type).
     *
     * @return list<array{type: NodeType, target: TreeNode, current: bool}>
     */
    public function paramNav(TreeNode $node): array
    {
        $formation = $node->getFormation();
        if ($formation === null) {
            return [];
        }
        $byKey = $this->types->findAllIndexed();
        $all = $this->maquette->open($formation)->allNodes();

        $ancestors = [];
        for ($c = $node->getParent(); $c !== null; $c = $c->getParent()) {
            $ancestors[$c->getType()->getKey()] ??= $c;
        }

        $out = [];
        foreach ($formation->getEffectiveStructure() as $key) {
            $type = $byKey[$key] ?? null;
            if ($type === null) {
                continue;
            }
            $target = $key === $node->getType()->getKey() ? $node : ($ancestors[$key] ?? null);
            if ($target === null) {
                foreach ($all as $n) {
                    if ($n->getType()->getKey() === $key) {
                        $target = $n;
                        break;
                    }
                }
            }
            if ($target !== null) {
                $out[] = ['type' => $type, 'target' => $target, 'current' => $target === $node];
            }
        }

        return $out;
    }

    /** Champs actifs d'une section « Paramètre de la formation » (organisation/presentation). @return list<FieldDef> */
    public function paramActiveFields(string $key): array
    {
        $type = $this->types->findOneByKey('param_'.$key.'_formation');

        return null !== $type ? $this->fields->activeOrderedFor($type) : [];
    }

    /** Équivalent de paramActiveFields() pour une section « Paramètre du parcours ». @return list<FieldDef> */
    public function parcoursParamActiveFields(string $key): array
    {
        $type = $this->types->findOneByKey('param_'.$key.'_parcours');

        return null !== $type ? $this->fields->activeOrderedFor($type) : [];
    }

    /** @return array<string, string> */
    public function capabilityLabels(): array
    {
        $out = [];
        foreach ($this->catalog->all() as $key => $def) {
            if ('separator' === $def['field']) {
                continue; // ligne d'organisation, pas une capacité togglable par template
            }
            $out[$key] = $def['label'];
        }
        foreach (AttributeCatalog::FLAGS as $key => $label) {
            $out[$key] = $label;
        }

        return $out;
    }
}
