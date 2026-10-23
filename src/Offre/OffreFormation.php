<?php

declare(strict_types=1);

namespace App\Offre;

use App\Diplome\Wizard\AdmissionStep;
use App\Diplome\TypeDiplomeResolver;
use App\Entity\Formation;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Maquette\MaquetteBuilder;
use App\Maquette\NodeView;
use App\Repository\ReferentielRepository;

/**
 * Offre d'une formation pour la gestion de l'offre : ses parcours (parcours terminaux, ou le
 * parcours unique d'une formation sans parcours), leurs années, l'ouverture et la capacité
 * d'accueil saisies, les plateformes d'admission du diplôme et les anomalies.
 *
 * Données saisies rangées dans les paramètres de la maquette, `parametres['offre']` :
 * - `parcours` : nid du parcours (ou `_formation`) → {ouverture: ouvert|non_ouvert|fermeture} ;
 * - `annees` : nid du nœud de premier niveau (l'année) → {ouvert, capacite, plateformes (clés désactivées : `sans`)} ;
 * - `statut`, `pv`, `note` : état de validation de l'offre et documents (affichés seulement).
 *
 * Une année appartient au parcours qui la contient : celle d'un parcours commun (portail, tronc
 * commun) est partagée par tous les parcours qui en descendent et ne se saisit qu'une fois.
 */
final class OffreFormation
{
    public const PARAM = 'offre';
    public const MONO = '_formation';
    public const OUVERTURES = ['ouvert', 'non_ouvert', 'fermeture'];
    public const STATUTS = ['brouillon', 'soumis_ses', 'soumis_cfvu', 'attente_pv', 'publie'];

    public function __construct(
        private readonly Maquette $maquette,
        private readonly MaquetteBuilder $builder,
        private readonly TypeDiplomeResolver $typeDiplomes,
        private readonly ReferentielRepository $referentiels,
    ) {
    }

    /**
     * @return array{
     *     formation: Formation, typeLabel: string, composante: string, statut: string, pv: bool, note: bool,
     *     plateformes: list<array{key: string, label: string, annees: list<int>}>,
     *     parcours: list<array<string, mixed>>, capacite: int, ouverte: bool, anomalies: list<string>, nbAnnees: int
     * }
     */
    public function describe(Formation $formation): array
    {
        $doc = $this->maquette->open($formation);
        $offre = $doc->formationParam(self::PARAM);
        $roots = $this->builder->build($formation);
        $firstLevel = $this->firstLevel($formation, $roots);

        $labels = $this->referentiels->values(AdmissionStep::REFERENTIEL);
        $plateformes = [];
        foreach ($this->typeDiplomes->configFor($formation)?->admission ?? [] as $key => $years) {
            $plateformes[] = ['key' => $key, 'label' => $labels[$key] ?? $key, 'annees' => $years];
        }

        // chemins : parcours terminaux et années de chaque parcours du chemin
        $views = [];
        $this->index($roots, $views);
        $paths = $this->paths($formation, $doc, $roots);
        $owners = [];
        foreach ($paths as $path) {
            foreach ($path['owners'] as $owner) {
                $owners[$owner][] = $path['nid'];
            }
        }

        $parcours = [];
        $anomalies = [];
        $years = []; // nid de l'année → [ouverte, capacité, ouverte dans au moins un parcours ouvert]
        foreach ($paths as $path) {
            $ouverture = (string) ($offre['parcours'][$path['nid']]['ouverture'] ?? 'ouvert');
            $ouverture = \in_array($ouverture, self::OUVERTURES, true) ? $ouverture : 'ouvert';
            $isOuvert = 'ouvert' === $ouverture;
            $annees = [];
            foreach ($path['owners'] as $owner) {
                $ownerView = $views[$owner] ?? null;
                $children = null !== $ownerView && $ownerView->node->isParcours() ? $ownerView->children : ($ownerView ? [$ownerView] : $roots);
                foreach ($children as $child) {
                    if ($child->node->getType()->getKey() !== $firstLevel) {
                        continue;
                    }
                    $nid = $child->node->getId();
                    $data = $offre['annees'][$nid] ?? [];
                    $ordre = (int) $child->ref;
                    $off = (array) ($data['sans'] ?? []);
                    $annee = [
                        'nid' => $nid,
                        'label' => trim($child->node->getType()->getLabel().' '.$child->ref),
                        'ordre' => $ordre,
                        'partage' => \count($owners[$owner] ?? []) > 1 ? \count($owners[$owner]) : 0,
                        'ouvert' => (bool) ($data['ouvert'] ?? true),
                        'capacite' => isset($data['capacite']) && '' !== $data['capacite'] ? (int) $data['capacite'] : null,
                        'plateformes' => array_values(array_map(static fn (array $p) => [
                            'key' => $p['key'], 'label' => $p['label'], 'active' => !\in_array($p['key'], $off, true),
                        ], array_filter($plateformes, static fn (array $p) => \in_array($ordre, $p['annees'], true)))),
                    ];
                    $annees[] = $annee;
                    $years[$nid] ??= ['annee' => $annee, 'parcoursOuvert' => false];
                    $years[$nid]['parcoursOuvert'] = $years[$nid]['parcoursOuvert'] || $isOuvert;
                }
            }

            $own = [];
            if ($isOuvert && [] !== $annees && [] === array_filter($annees, static fn (array $a) => $a['ouvert'])) {
                $own[] = sprintf('%s : ouvert mais aucune année ouverte.', $path['label']);
            }
            $parcours[] = [
                'nid' => $path['nid'],
                'label' => $path['label'],
                'chemin' => $path['chemin'],
                'ouverture' => $ouverture,
                'ouvert' => $isOuvert,
                'annees' => $annees,
                'capacite' => array_sum(array_map(static fn (array $a) => $a['ouvert'] ? (int) $a['capacite'] : 0, $annees)),
                'anomalies' => $own,
            ];
            array_push($anomalies, ...$own);
        }

        // capacité et anomalies des années : une fois par année, même partagée
        $capacite = 0;
        foreach ($years as $y) {
            if (!$y['parcoursOuvert'] || !$y['annee']['ouvert']) {
                continue;
            }
            $capacite += (int) $y['annee']['capacite'];
            if (null === $y['annee']['capacite'] || $y['annee']['capacite'] <= 0) {
                $message = sprintf('%s : capacité d\'accueil à renseigner%s.', $this->yearContext($y['annee'], $parcours), $y['annee']['partage'] ? ' (année commune)' : '');
                $anomalies[] = $message;
                foreach ($parcours as &$p) {
                    if (\in_array($y['annee']['nid'], array_column($p['annees'], 'nid'), true)) {
                        $p['anomalies'][] = $message;
                    }
                }
                unset($p);
            }
        }
        if ([] === $years) {
            $anomalies[] = 'Aucune année dans la structure de la formation.';
        }
        if ('' === trim((string) $formation->getComposante())) {
            $anomalies[] = 'Composante porteuse non renseignée.';
        }

        $statut = (string) ($offre['statut'] ?? 'brouillon');

        return [
            'formation' => $formation,
            'typeLabel' => $formation->getTypeDiplome()?->getLabel() ?? (string) $formation->getDiplome(),
            'composante' => trim((string) $formation->getComposante()),
            'statut' => \in_array($statut, self::STATUTS, true) ? $statut : 'brouillon',
            'pv' => (bool) ($offre['pv'] ?? false),
            'note' => (bool) ($offre['note'] ?? false),
            'plateformes' => $plateformes,
            'parcours' => $parcours,
            'capacite' => $capacite,
            'ouverte' => [] !== array_filter($parcours, static fn (array $p) => $p['ouvert']),
            'anomalies' => array_values(array_unique($anomalies)),
            'nbAnnees' => \count($years),
        ];
    }

    /**
     * Enregistre l'ouverture des parcours et, par année, ouverture, capacité et plateformes
     * désactivées. Seuls les parcours et années de la formation sont pris en compte.
     *
     * @param array<string, mixed> $parcours nid → {ouverture}
     * @param array<string, mixed> $annees   nid → {ouvert, capacite, plateformes: list<clé active>}
     */
    public function save(Formation $formation, array $parcours, array $annees): void
    {
        $described = $this->describe($formation);
        $doc = $this->maquette->open($formation);
        $offre = $doc->formationParam(self::PARAM);

        foreach ($described['parcours'] as $p) {
            $ouverture = (string) ($parcours[$p['nid']]['ouverture'] ?? $p['ouverture']);
            $offre['parcours'][$p['nid']] = ['ouverture' => \in_array($ouverture, self::OUVERTURES, true) ? $ouverture : 'ouvert'];
            foreach ($p['annees'] as $a) {
                if (!\array_key_exists($a['nid'], $annees)) {
                    continue;
                }
                $in = (array) $annees[$a['nid']];
                $capacite = trim((string) ($in['capacite'] ?? ''));
                $actives = array_map('strval', (array) ($in['plateformes'] ?? []));
                $offre['annees'][$a['nid']] = array_filter([
                    'ouvert' => (bool) ($in['ouvert'] ?? false),
                    'capacite' => '' === $capacite ? null : max(0, (int) $capacite),
                    'sans' => array_values(array_diff(array_column($a['plateformes'], 'key'), $actives)),
                ], static fn ($v) => null !== $v && [] !== $v);
            }
        }

        $doc->setFormationParam(self::PARAM, $offre);
        $this->maquette->save($formation, $doc, 'Offre de formation');
    }

    /**
     * Type des nœuds « année » : premier niveau de la structure du diplôme, à défaut type de la
     * première racine pédagogique.
     *
     * @param list<NodeView> $roots
     */
    private function firstLevel(Formation $formation, array $roots): ?string
    {
        $levels = $this->typeDiplomes->configFor($formation)?->structure->levels ?? [];
        if ([] !== $levels) {
            return $levels[0]->typeKey;
        }
        foreach ($roots as $root) {
            $first = $root->node->isParcours() ? ($root->children[0] ?? null) : $root;
            if (null !== $first) {
                return $first->node->getType()->getKey();
            }
        }

        return null;
    }

    /**
     * @param list<NodeView>          $views
     * @param array<string, NodeView> $out
     */
    private function index(array $views, array &$out): void
    {
        foreach ($views as $v) {
            $out[$v->node->getId()] = $v;
            if ($v->node->isParcours()) {
                $this->index($v->children, $out);
            }
        }
    }

    /**
     * Parcours terminaux de la formation, chacun avec la suite des parcours dont il descend
     * (`owners`) ; sans parcours, un parcours unique qui possède les racines.
     *
     * @param list<NodeView> $roots
     *
     * @return list<array{nid: string, label: string, chemin: string, owners: list<string>}>
     */
    private function paths(Formation $formation, MaquetteDoc $doc, array $roots): array
    {
        $all = $doc->parcoursNodes();
        if (!$formation->isAvecParcours() || [] === $all) {
            return [['nid' => self::MONO, 'label' => $formation->getName(), 'chemin' => '', 'owners' => [self::MONO]]];
        }
        $parents = [];
        foreach ($all as $p) {
            if (null !== $p->getParcoursParent()) {
                $parents[$p->getParcoursParent()->getId()] = true;
            }
        }
        $out = [];
        foreach ($all as $leaf) {
            if (isset($parents[$leaf->getId()])) {
                continue;
            }
            $chain = [];
            $seen = [];
            for ($p = $leaf; null !== $p && !isset($seen[$p->getId()]); $p = $p->getParcoursParent()) {
                $seen[$p->getId()] = true;
                array_unshift($chain, $p);
            }
            $out[] = [
                'nid' => $leaf->getId(),
                'label' => $leaf->getDisplayLabel(),
                'chemin' => implode(' › ', array_map(static fn (TreeNode $p) => $p->getDisplayLabel(), \array_slice($chain, 0, -1))),
                'owners' => array_map(static fn (TreeNode $p) => $p->getId(), $chain),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed>       $annee
     * @param list<array<string, mixed>> $parcours
     */
    private function yearContext(array $annee, array $parcours): string
    {
        if ($annee['partage']) {
            return $annee['label'];
        }
        foreach ($parcours as $p) {
            if (\in_array($annee['nid'], array_column($p['annees'], 'nid'), true)) {
                return (self::MONO === $p['nid'] ? '' : $p['label'].' · ').$annee['label'];
            }
        }

        return $annee['label'];
    }
}
