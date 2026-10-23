<?php

declare(strict_types=1);

namespace App\Export\Mccc;

use App\Diplome\Spec\VersionConfig;
use App\Diplome\TypeDiplomeResolver;
use App\Entity\Formation;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Maquette\MaquetteBuilder;
use App\Maquette\NodeView;
use App\Repository\MccTypeRepository;
use App\Repository\NodeTypeRepository;
use App\Repository\ReferentielRepository;

/**
 * Construit le tableau des MCCC d'une formation, sans rien supposer du diplôme :
 * les niveaux viennent de la structure de la version du type de diplôme (à défaut,
 * de l'arbre), les colonnes MCCC des types de MCCC autorisés par la version.
 *
 * Une feuille par nœud du niveau `$sheetLevel` (par défaut le premier niveau) ;
 * les niveaux entre la feuille et l'élément deviennent des colonnes de regroupement
 * (UE, semestre…) ; chaque élément (dernier niveau) est une ligne. Les nœuds hors
 * chaîne (blocs de choix) sont traversés sans colonne propre.
 *
 * Formation à parcours : on exporte un chemin de parcours (le parcours choisi et
 * ceux dont il descend), par défaut le premier parcours terminal.
 */
final class McccTableBuilder
{
    public const HOUR_KEYS = ['presCm', 'presTd', 'presTp', 'distCm', 'distTd', 'distTp', 'te'];

    public function __construct(
        private readonly Maquette $maquette,
        private readonly MaquetteBuilder $builder,
        private readonly TypeDiplomeResolver $typeDiplomes,
        private readonly MccTypeRepository $mccTypes,
        private readonly NodeTypeRepository $nodeTypes,
        private readonly ReferentielRepository $referentiels,
    ) {
    }

    /**
     * Niveaux proposés pour découper le classeur en feuilles (tous sauf l'élément).
     *
     * @return array<string, string> clé de type de nœud → libellé
     */
    public function sheetLevels(Formation $formation): array
    {
        [, $chain] = $this->chain($formation, $this->builder->build($formation));
        $out = [];
        foreach (\array_slice($chain, 0, -1) as $key) {
            $out[$key] = $this->nodeTypes->findOneByKey($key)?->getLabel() ?? $key;
        }

        return $out;
    }

    /**
     * Parcours exportables : les parcours terminaux, libellés par leur chemin.
     *
     * @return array<string, string> nid → « Portail commun › Parcours Informatique »
     */
    public function parcoursChoices(Formation $formation): array
    {
        $doc = $this->maquette->open($formation);
        $out = [];
        foreach ($this->leafParcours($doc) as $leaf) {
            $out[$leaf->getId()] = implode(' › ', array_map(static fn (TreeNode $p) => $p->getDisplayLabel(), $this->parcoursPath($leaf)));
        }

        return $out;
    }

    public function build(Formation $formation, ?string $sheetLevel = null, ?string $parcoursNid = null): McccTable
    {
        $doc = $this->maquette->open($formation);
        $config = $this->typeDiplomes->configFor($formation);
        $roots = $this->builder->build($formation);

        // chemin de parcours : la racine pédagogique devient la suite des enfants de chaque parcours du chemin
        $parcours = null;
        if ($formation->isAvecParcours() && [] !== $doc->parcoursNodes()) {
            $leaves = $this->leafParcours($doc);
            $parcours = $doc->node((string) $parcoursNid);
            if (null === $parcours || !\in_array($parcours, $leaves, true)) {
                $parcours = $leaves[0] ?? null;
            }
            $path = null === $parcours ? [] : array_map(static fn (TreeNode $p) => $p->getId(), $this->parcoursPath($parcours));
            $tops = [];
            foreach ($path as $nid) {
                foreach ($roots as $root) {
                    if ($root->node->getId() === $nid) {
                        array_push($tops, ...$root->children);
                    }
                }
            }
        } else {
            $tops = $roots;
        }

        [$elementKey, $chain] = $this->chain($formation, $roots);
        $sheetLevel = \in_array($sheetLevel, \array_slice($chain, 0, -1), true) ? $sheetLevel : ($chain[0] ?? null);
        $groupKeys = \array_slice($chain, (int) array_search($sheetLevel, $chain, true) + 1, -1);

        $sheetNodes = [];
        foreach ($tops as $top) {
            $this->collect($top, $sheetLevel, $sheetNodes);
        }

        $refs = [
            'nature' => $this->referentiels->values('nature_elp'),
            'ecType' => $this->referentiels->values('types_ec'),
            'epreuve' => $this->referentiels->values('types_epreuve'),
            'natureElement' => $this->natureLabels($config),
        ];

        $sheets = [];
        $withLabels = array_fill_keys($groupKeys, false);
        foreach ($sheetNodes as $sheetNode) {
            $lines = [];
            $ects = [];
            $this->walk($sheetNode, $groupKeys, $elementKey, [], $lines, $refs, $ects);
            foreach ($lines as $line) {
                foreach ($line['groups'] ?? [] as $key => [, $label]) {
                    $withLabels[$key] = $withLabels[$key] || '' !== $label;
                }
            }
            $lines = $this->withTotals($lines, $groupKeys, $sheetNode, $ects);
            $sheets[] = new McccSheet($this->display($sheetNode), $this->subtitle($sheetNode), $lines);
        }

        $typeLabel = $formation->getTypeDiplome()?->getLabel() ?? (string) $formation->getDiplome();
        $organisation = $doc->formationParam('organisation');
        $parcoursOrg = null !== $parcours ? $doc->parcoursParam($parcours->getId(), 'organisation') : $doc->formationParam('parcours.organisation');
        $regimes = $this->referentiels->values('regimes_inscription');
        $annee = $formation->getAnneeUniversitaire() ?: $this->typeDiplomes->versionFor($formation)?->getAnneeUniversitaire();

        $info = array_values(array_filter([
            ['Type de formation', $typeLabel],
            ['Mention', (string) ($organisation['mention'] ?? $formation->getName())],
            ['Parcours', null !== $parcours ? implode(' › ', array_map(static fn (TreeNode $p) => $p->getDisplayLabel(), $this->parcoursPath($parcours))) : ''],
            ['Composante', (string) $formation->getComposante()],
            ['Régime(s) d\'inscription', implode(', ', array_map(static fn ($r) => $regimes[$r] ?? (string) $r, (array) ($parcoursOrg['regimes'] ?? [])))],
            ['Responsable de mention', (string) ($organisation['respMention'] ?? '')],
            ['Responsable de parcours', (string) ($parcoursOrg['respParcours'] ?? '')],
        ], static fn (array $row) => '' !== $row[1]));

        return new McccTable(
            'Maquette et modalités de contrôle des connaissances et compétences'.($annee ? ' — '.$annee : ''),
            $info,
            array_map(fn (string $k) => ['key' => $k, 'label' => $this->typeLabel($k)], $groupKeys),
            $withLabels,
            $this->mcccColumns($config),
            $sheets,
            $this->fileName($formation, $annee, $parcours),
        );
    }

    /**
     * Chaîne des niveaux (clés de type de nœud), de la racine à l'élément : celle de la
     * version du type de diplôme, à défaut celle du premier chemin le plus profond de l'arbre.
     *
     * @param list<NodeView> $roots
     *
     * @return array{0: ?string, 1: list<string>} [clé de l'élément, chaîne]
     */
    private function chain(Formation $formation, array $roots): array
    {
        $levels = $this->typeDiplomes->configFor($formation)?->structure->levels ?? [];
        $chain = array_values(array_map(static fn ($l) => $l->typeKey, $levels));
        if ([] === $chain) {
            $chain = $this->deepestPath(array_merge(...array_map(
                static fn (NodeView $r) => $r->node->isParcours() ? $r->children : [$r],
                $roots,
            )));
        }

        return [$chain[\count($chain) - 1] ?? null, $chain];
    }

    /**
     * @param list<NodeView> $views
     *
     * @return list<string>
     */
    private function deepestPath(array $views): array
    {
        $best = [];
        foreach ($views as $v) {
            if ($v->node->isChoiceBloc()) {
                $path = $this->deepestPath($v->children);
            } else {
                $path = [$v->node->getType()->getKey(), ...$this->deepestPath($v->children)];
            }
            if (\count($path) > \count($best)) {
                $best = $path;
            }
        }

        return $best;
    }

    /** @param list<NodeView> $out */
    private function collect(NodeView $view, ?string $sheetLevel, array &$out): void
    {
        if ($view->node->getType()->getKey() === $sheetLevel) {
            $out[] = $view;

            return;
        }
        foreach ($view->children as $child) {
            $this->collect($child, $sheetLevel, $out);
        }
    }

    /**
     * @param list<string>                  $groupKeys
     * @param array<string, array{0: string, 1: string}> $groups regroupements en cours
     * @param list<array<string, mixed>>    $lines
     * @param array<string, array<string, string>> $refs
     * @param array<string, float>                $ects  ECTS calculés par l'éditeur, par regroupement (réf. affichée)
     */
    private function walk(NodeView $view, array $groupKeys, ?string $elementKey, array $groups, array &$lines, array $refs, array &$ects): void
    {
        foreach ($view->children as $child) {
            $key = $child->node->getType()->getKey();
            if ($key === $elementKey || (null === $elementKey && !$child->hasChildren())) {
                $lines[] = $this->elementLine($child, $groups, $refs);
            } elseif (\in_array($key, $groupKeys, true)) {
                $inner = $groups;
                $inner[$key] = [$this->display($child), $child->node->getLabel()];
                $ects[$this->display($child)] = $child->totalEcts;
                $before = \count($lines);
                $this->walk($child, $groupKeys, $elementKey, $inner, $lines, $refs, $ects);
                if (\count($lines) === $before) {
                    // regroupement sans élément (UE vide) : une ligne pour qu'il apparaisse
                    $lines[] = ['type' => 'element', 'groups' => $inner, 'empty' => true, 'ects' => $child->node->getAttribute('ects'), 'hours' => [], 'mccc' => []];
                }
            } else {
                $this->walk($child, $groupKeys, $elementKey, $groups, $lines, $refs, $ects);
            }
        }
    }

    /**
     * @param array<string, array{0: string, 1: string}> $groups
     * @param array<string, array<string, string>>      $refs
     *
     * @return array<string, mixed>
     */
    private function elementLine(NodeView $view, array $groups, array $refs): array
    {
        $node = $view->node;
        $hours = $node->getAttribute('hours');
        $hours = \is_array($hours) && empty($hours['none']) ? $hours : [];
        $mccc = $node->getAttribute('mccc');
        $parent = $node->getParent();
        $kind = $node->getAttribute('natureElement');
        $ecType = $node->getAttribute('ecType');
        $nature = (string) $node->getAttribute('nature');

        return [
            'type' => 'element',
            'groups' => $groups,
            'ref' => $this->display($view),
            'code' => (string) $node->getCode(),
            'label' => $node->getLabel(),
            'kind' => \is_string($kind) && '' !== $kind ? ($refs['natureElement'][$kind] ?? $kind) : (\is_string($ecType) ? ($refs['ecType'][$ecType] ?? $ecType) : ''),
            'nature' => trim(($refs['nature'][$nature] ?? $nature).(null !== $parent && $parent->isChoiceBloc() ? ' (bloc à choix « '.$parent->getDisplayLabel().' »)' : '')),
            'competencies' => implode(', ', array_map('strval', (array) ($node->getAttribute('competencies') ?? []))),
            'ects' => $node->getAttribute('ects'),
            'hours' => [
                'presCm' => (float) ($hours['pres']['cm'] ?? 0), 'presTd' => (float) ($hours['pres']['td'] ?? 0), 'presTp' => (float) ($hours['pres']['tp'] ?? 0),
                'distCm' => (float) ($hours['dist']['cm'] ?? 0), 'distTd' => (float) ($hours['dist']['td'] ?? 0), 'distTp' => (float) ($hours['dist']['tp'] ?? 0),
                'te' => (float) ($hours['te'] ?? 0),
            ],
            'mccc' => \is_array($mccc) && '' !== (string) ($mccc['type'] ?? '') ? [(string) $mccc['type'] => $this->mcccText($mccc, $refs['epreuve'])] : [],
        ];
    }

    /**
     * Texte d'une MCCC : « Oral 30 min (50 %) ; Rapport / dossier (50 %) », ou « X » sans épreuve détaillée.
     *
     * @param array<string, mixed>  $mccc
     * @param array<string, string> $epreuves
     */
    private function mcccText(array $mccc, array $epreuves): string
    {
        $parts = [];
        foreach ((array) ($mccc['evaluations'] ?? []) as $e) {
            $type = (string) ($e['type'] ?? '');
            $label = '' === $type ? 'Épreuve' : ($epreuves[$type] ?? $type);
            $duree = isset($e['duree']) && (float) $e['duree'] > 0 ? ' '.self::duration((float) $e['duree']) : '';
            $parts[] = $label.$duree.' ('.self::number((float) ($e['weight'] ?? 0)).' %)';
        }

        return [] === $parts ? 'X' : implode(' ; ', $parts);
    }

    /**
     * Ajoute une ligne de total après chaque nœud du premier niveau de regroupement
     * (quand il y en a au moins deux, ex. semestres d'une année), puis le total de la feuille.
     *
     * @param list<array<string, mixed>> $lines
     * @param list<string>               $groupKeys
     * @param array<string, float>       $ects ECTS calculés par l'éditeur, par regroupement
     *
     * @return list<array<string, mixed>>
     */
    private function withTotals(array $lines, array $groupKeys, NodeView $sheetNode, array $ects): array
    {
        $out = [];
        if (\count($groupKeys) >= 2) {
            $first = $groupKeys[0];
            $current = null;
            $chunk = [];
            foreach ($lines as $line) {
                $group = $line['groups'][$first][0] ?? '';
                if (null !== $current && $group !== $current) {
                    array_push($out, ...$chunk);
                    $out[] = $this->total('Total '.$current, $chunk, $ects[$current] ?? 0.0);
                    $chunk = [];
                }
                $current = $group;
                $chunk[] = $line;
            }
            if ([] !== $chunk) {
                array_push($out, ...$chunk);
                $out[] = $this->total('Total '.$current, $chunk, $ects[$current] ?? 0.0);
            }
        } else {
            $out = $lines;
        }
        $out[] = $this->total('Total '.$this->display($sheetNode), $lines, $sheetNode->totalEcts);

        return $out;
    }

    /**
     * Ligne de total : ECTS tels que calculés par l'éditeur (NodeView), heures sommées sur les éléments.
     *
     * @param list<array<string, mixed>> $lines
     */
    private function total(string $label, array $lines, float $ects): array
    {
        $hours = array_fill_keys(self::HOUR_KEYS, 0.0);
        foreach ($lines as $line) {
            if ('element' !== ($line['type'] ?? null)) {
                continue;
            }
            foreach (self::HOUR_KEYS as $k) {
                $hours[$k] += (float) ($line['hours'][$k] ?? 0);
            }
        }

        return ['type' => 'total', 'label' => $label, 'ects' => $ects, 'hours' => $hours];
    }

    /** @return array<string, string> */
    private function mcccColumns(?VersionConfig $config): array
    {
        $allowed = null !== $config && [] !== $config->mccc ? $config->mccc : null;
        $out = [];
        foreach ($this->mccTypes->allOrdered() as $type) {
            if (null === $allowed || isset($allowed[$type->getKey()])) {
                $out[$type->getKey()] = $type->getLabel().' ('.$type->getShortLabel().')';
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    private function natureLabels(?VersionConfig $config): array
    {
        $out = [];
        foreach ($config?->structure->natures ?? [] as $nature) {
            $out[$nature->key] = $nature->label;
        }

        return $out;
    }

    private function display(NodeView $view): string
    {
        $type = $view->node->getType()->getLabel();

        return '' !== $view->ref ? $type.' '.$view->ref : ($view->node->getLabel() ?: $type);
    }

    private function subtitle(NodeView $view): string
    {
        $label = $view->node->getLabel();

        return '' !== $label && $label !== $this->display($view) ? $this->display($view).' — '.$label : $this->display($view);
    }

    private function typeLabel(string $key): string
    {
        return $this->nodeTypes->findOneByKey($key)?->getLabel() ?? $key;
    }

    /** @return list<TreeNode> parcours sans parcours enfant, dans l'ordre */
    private function leafParcours(MaquetteDoc $doc): array
    {
        $all = $doc->parcoursNodes();
        $parents = [];
        foreach ($all as $p) {
            if (null !== $p->getParcoursParent()) {
                $parents[$p->getParcoursParent()->getId()] = true;
            }
        }

        return array_values(array_filter($all, static fn (TreeNode $p) => !isset($parents[$p->getId()])));
    }

    /** @return list<TreeNode> du parcours racine au parcours donné */
    private function parcoursPath(TreeNode $parcours): array
    {
        $path = [];
        $seen = [];
        for ($p = $parcours; null !== $p && !isset($seen[$p->getId()]); $p = $p->getParcoursParent()) {
            $seen[$p->getId()] = true;
            array_unshift($path, $p);
        }

        return $path;
    }

    private function fileName(Formation $formation, ?string $annee, ?TreeNode $parcours): string
    {
        $slug = static fn (string $s) => trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s), '-');
        $parts = array_filter(['MCCC', $annee, $slug($formation->getName()), null !== $parcours ? $slug($parcours->getDisplayLabel()) : null]);

        return implode('-', $parts).'.xlsx';
    }

    private static function duration(float $minutes): string
    {
        $h = intdiv((int) round($minutes), 60);
        $m = (int) round($minutes) % 60;

        return $h > 0 ? sprintf('%dh%02d', $h, $m) : $m.' min';
    }

    private static function number(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
    }
}
