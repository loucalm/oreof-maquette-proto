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
use App\Repository\FieldDefRepository;
use App\Repository\MccTypeRepository;
use App\Repository\NodeTypeRepository;
use App\Repository\ReferentielRepository;
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
        private readonly FieldDefRepository $fields,
        private readonly \App\Validation\TabValidator $validator,
        private readonly \App\Maquette\BccReferences $bccRefs,
        private readonly \App\Maquette\NodeClipboard $clipboard,
        private readonly \App\Diplome\VersionMccc $versionMccc,
        private readonly \App\Diplome\ParamTypeResolver $paramTypes,
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
            new TwigFunction('node_display_label', $this->nodeDisplayLabel(...)),
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
            new TwigFunction('node_missing', fn (TreeNode $n) => $this->completion->missing($n)),
            new TwigFunction('mutualization_source', fn (TreeNode $n) => $this->maquette->mutualizationSource($n)),
            // presse-papiers « Copier / Coller » : contenu copié + raison du blocage éventuel pour ce nœud
            new TwigFunction('node_clipboard', fn (TreeNode $n) => ['clip' => $this->clipboard->get(), 'blocked' => $this->clipboard->blockedReason($n)]),
            new TwigFunction('bcc_competences', $this->bccCompetences(...)),
            new TwigFunction('bcc_competence_titles', $this->bccCompetenceTitles(...)),
            new TwigFunction('period_unit', $this->periodUnit(...)),
            new TwigFunction('param_sections', static fn () => FormationController::PARAM_SECTIONS),
            new TwigFunction('param_status', fn (Formation $f, string $k) => $this->completion->formationParamStatus($f, $k)),
            new TwigFunction('param_issues', fn (Formation $f, string $k) => $this->completion->formationParamIssues($f, $k)),
            new TwigFunction('parcours_param_issues', fn (TreeNode $p, string $k) => $this->completion->parcoursParamIssues($p, $k)),
            // mentions « obligatoire si … » des champs d'un type (règles de ses onglets)
            new TwigFunction('field_rule_hints', fn (string $typeKey) => null !== ($t = $this->types->findOneByKey($typeKey)) ? $this->validator->hints($t) : []),
            new TwigFunction('param_type_key', fn (?Formation $f, string $scope, string $key) => $this->paramTypes->typeKey($f, $scope, $key)),
            new TwigFunction('param_active_fields', $this->paramActiveFields(...)),
            new TwigFunction('param_field_value', fn (Formation $f, string $k, array $data) => $this->completion->formationFieldValue($f, $k, $data)),
            new TwigFunction('bcc_status', $this->bccStatus(...)),
            new TwigFunction('parcours_param_sections', static fn () => FormationController::PARCOURS_PARAM_SECTIONS),
            new TwigFunction('parcours_param_status', fn (TreeNode $p, string $k) => $this->completion->parcoursParamStatus($p, $k)),
            new TwigFunction('parcours_param_active_fields', $this->parcoursParamActiveFields(...)),
            new TwigFunction('parcours_field_value', fn (TreeNode $p, string $k, array $data) => $this->completion->parcoursFieldValue($p, $k, $data)),
            new TwigFunction('node_type_param_editable', static fn (NodeType $t) => \in_array($t->getKey(), NodeTypeController::EDITABLE_PARAM_KEYS, true)),
            new TwigFunction('referentiel', fn (string $key) => $this->referentiels->values($key)),
            // types de MCCC proposés pour un nœud : ceux de la version du type de diplôme (selon la nature
            // de l'élément) ; sans version (formation non typée), tous ceux du catalogue
            new TwigFunction('mccc_types_for', fn (TreeNode $n) => $this->versionMccc->typesFor($n) ?? $this->mcctypes->allOrdered()),
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
        // référentiel national porté par la formation : rien à saisir
        $formation = $context instanceof TreeNode ? $context->getFormation() : $context;
        if (true === $formation?->hasBccReferentiel()) {
            return 'ok';
        }

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
        $hasData = false;
        foreach ($this->catalog->all() as $key => $def) {
            if (($def['domain'] ?? null) !== $tab || !($caps[$key] ?? false)) {
                continue;
            }
            $v = $node->getAttribute($key);
            if ($key === 'hours' ? AttributeCatalog::hoursProvided($v) : !($v === null || $v === '' || $v === [])) {
                $hasData = true;
                break;
            }
        }

        // même source que l'arbre et « Vérifier » : champs requis manquants, MCCC non conformes,
        // règles de l'onglet ; une valeur invalide (bornes) passe l'onglet en erreur
        $inTab = array_filter($this->completion->missing($node), static fn (array $m) => $m['tab'] === $tab);
        if ([] !== array_filter($inTab, static fn (array $m) => $m['invalid'] ?? false)) {
            return 'error';
        }
        if ([] !== $inTab) {
            return ($hasData || $tab === 'props') ? 'incomplete' : 'empty';
        }

        return ($hasData || $tab === 'props') ? 'ok' : 'empty';
    }

    /**
     * Compétences du référentiel (BCC) proposables sur ce nœud, groupées par bloc.
     *
     * @return list<array{bloc: string, transversal: bool, items: list<array{id: string, ref: string, label: string, description: string, title: string}>}>
     */
    public function bccCompetences(TreeNode $node): array
    {
        if (true === $node->getFormation()?->hasBccReferentiel()) {
            return $this->apprentissagesNationaux($node->getFormation()->getBccReferentiel(), $node);
        }

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
        foreach ($this->bccRefs->tree($blocs) as $bloc) {
            $items = [];
            foreach ($bloc['competences'] as $row) {
                $c = $row['node'];
                $items[] = [
                    'id' => $c->getId(),
                    'ref' => $row['ref'],
                    // ancien identifiant (libellé) : les EC déjà liées avant le passage aux identifiants restent cochées
                    'label' => $c->getLabel(),
                    'description' => $c->getDescription(),
                    'title' => trim($row['ref'].' '.mb_strimwidth($c->getDescription(), 0, 60, '…')) ?: $c->getLabel(),
                ];
            }
            if ($items !== []) {
                $out[] = ['bloc' => trim($bloc['ref'].' '.$bloc['node']->getLabel()), 'transversal' => $bloc['transversal'], 'items' => $items];
            }
        }

        return $out;
    }

    /**
     * Intitulés lisibles des compétences liées à un nœud (valeurs stockées : identifiants,
     * ou anciens libellés) ; une valeur qui ne correspond plus à rien est affichée telle quelle.
     *
     * @param list<string> $values
     *
     * @return list<string>
     */
    public function bccCompetenceTitles(TreeNode $node, array $values): array
    {
        $byKey = [];
        foreach ($this->bccCompetences($node) as $group) {
            foreach ($group['items'] as $item) {
                $byKey[$item['id']] = $item['title'];
                $byKey[$item['label']] ??= $item['title'];
            }
        }

        return array_map(static fn (string $v) => $byKey[$v] ?? $v, array_values(array_map('strval', $values)));
    }

    /**
     * Référentiel national : les apprentissages critiques (AC) du référentiel national, groupés
     * par compétence, limités au niveau de l'année du nœud (1re année → BUT1…) quand
     * le nœud est sous une année ; tous les niveaux sinon.
     *
     * @param list<array<string, mixed>> $referentiel
     *
     * @return list<array{bloc: string, transversal: bool, items: list<array{id: string, ref: string, label: string, description: string, title: string}>}>
     */
    private function apprentissagesNationaux(array $referentiel, TreeNode $node): array
    {
        $annee = null;
        for ($c = $node; null !== $c; $c = $c->getParent()) {
            if ('annee' === $c->typeKey) {
                $siblings = $c->getParent()?->getChildren() ?? $this->maquette->open($node->getFormation())->pedagogicalRoots();
                $annee = 'BUT'.(1 + \count(array_filter($siblings, static fn (TreeNode $s) => 'annee' === $s->typeKey && $s->getPosition() < $c->getPosition())));
                break;
            }
        }

        $out = [];
        foreach ($referentiel as $competence) {
            foreach ($competence['niveaux'] ?? [] as $niveau) {
                if (null !== $annee && ($niveau['annee'] ?? null) !== $annee) {
                    continue;
                }
                $out[] = [
                    'bloc' => sprintf('C%d %s · %s', $competence['numero'] ?? 0, $competence['nomCourt'] ?? $competence['libelle'] ?? '', $niveau['annee'] ?? ''),
                    'transversal' => false,
                    'items' => array_map(static fn (array $ac) => [
                        'id' => $ac['code'],
                        'ref' => $ac['code'],
                        'label' => $ac['libelle'],
                        'description' => $ac['libelle'],
                        'title' => $ac['code'].' '.mb_strimwidth($ac['libelle'], 0, 60, '…'),
                    ], $niveau['acs'] ?? []),
                ];
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
    public function paramActiveFields(string $key, ?Formation $formation = null): array
    {
        $type = $this->paramTypes->typeFor($formation, \App\Diplome\ParamTypeResolver::SCOPE_FORMATION, $key);

        return null !== $type ? $this->fields->activeOrderedFor($type) : [];
    }

    /** Équivalent de paramActiveFields() pour une section « Paramètre du parcours ». @return list<FieldDef> */
    public function parcoursParamActiveFields(string $key, ?Formation $formation = null): array
    {
        $type = $this->paramTypes->typeFor($formation, \App\Diplome\ParamTypeResolver::SCOPE_PARCOURS, $key);

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
