<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Entity\Formation;
use App\Repository\ReferentielRepository;

/**
 * Grille de saisie rapide (type tableur) : ECTS et volumes horaires de tous
 * les éléments pédagogiques d'une formation (ou d'un parcours) sur une page,
 * enregistrés ligne à ligne. Une rafale de saisies ne crée qu'une révision.
 */
final class QuickGrid
{
    public const REVISION_LABEL = 'Saisie rapide (ECTS / heures)';

    public function __construct(
        private readonly Maquette $maquette,
        private readonly MaquetteBuilder $builder,
        private readonly ReferentielRepository $referentiels,
    ) {
    }

    /**
     * Colonnes d'heures : lieu × modalité (hors TE), puis TE.
     *
     * @return array{places: array<string, string>, mods: array<string, string>}
     */
    public function columns(): array
    {
        $mods = $this->referentiels->values('modalites_heure');
        unset($mods['te']);

        return ['places' => $this->referentiels->values('lieux_enseignement'), 'mods' => $mods];
    }

    /**
     * Lignes à plat, dans l'ordre de l'arbre : un « groupe » par élément qui a
     * des enfants (année, semestre, UE…), une « ligne » saisissable par feuille.
     *
     * @return list<array{kind: string, depth: int, view: NodeView}>
     */
    public function rows(Formation $formation, ?string $parcoursNid = null): array
    {
        $views = $this->builder->build($formation);
        if (null !== $parcoursNid) {
            $parcours = $this->find($views, $parcoursNid);
            $views = null !== $parcours ? $parcours->children : [];
        }

        $out = [];
        $walk = function (array $views, int $depth) use (&$walk, &$out): void {
            foreach ($views as $view) {
                if ($view->children !== []) {
                    $out[] = ['kind' => 'group', 'depth' => $depth, 'view' => $view];
                    $walk($view->children, $depth + 1);
                } else {
                    $out[] = ['kind' => 'row', 'depth' => $depth, 'view' => $view];
                }
            }
        };
        $walk($views, 0);

        return $out;
    }

    /**
     * Applique la saisie d'une ligne (champs absents = inchangés ; vide = effacé)
     * et renvoie les totaux à rafraîchir : la ligne et tous ses ancêtres.
     *
     * @param array<string, mixed> $input `ects`, `hours[lieu][modalité]`, `hours[te]`
     *
     * @return array{ok: bool, error?: string, totals?: array<string, array{text: string, state: string}>, row?: array<string, string>}
     */
    public function apply(Formation $formation, string $nid, array $input): array
    {
        $doc = $this->maquette->open($formation);
        $node = $doc->node($nid);
        if (null === $node) {
            return ['ok' => false, 'error' => 'Élément introuvable.'];
        }
        $caps = $node->effectiveCapabilities();

        if (($caps['ects'] ?? false) && \array_key_exists('ects', $input)) {
            $node->setAttribute('ects', $this->number($input['ects']));
        }

        if (($caps['hours'] ?? false) && \is_array($input['hours'] ?? null)) {
            $hours = \is_array($node->getAttribute('hours')) ? $node->getAttribute('hours') : [];
            $cols = $this->columns();
            foreach (array_keys($cols['places']) as $place) {
                foreach (array_keys($cols['mods']) as $mod) {
                    if (\array_key_exists($mod, $input['hours'][$place] ?? [])) {
                        $hours[$place][$mod] = $this->number($input['hours'][$place][$mod]);
                    }
                }
            }
            if (\array_key_exists('te', $input['hours'])) {
                $hours['te'] = $this->number($input['hours']['te']);
            }
            if (AttributeCatalog::sumHours($hours) > 0) {
                unset($hours['none']); // des heures saisies contredisent « sans volume horaire »
            }
            $node->setAttribute('hours', $this->clean($hours));
        }

        $this->maquette->save($formation, $doc, self::REVISION_LABEL, null, true);

        return ['ok' => true] + $this->totals($formation, $nid);
    }

    /** @return array{totals: array<string, array{text: string, state: string}>, row: array<string, string>} */
    private function totals(Formation $formation, string $nid): array
    {
        $path = $this->path($this->builder->build($formation), $nid) ?? [];
        $totals = [];
        foreach ($path as $view) {
            $id = $view->node->getId();
            $target = $view->ectsTarget();
            $totals['ects:'.$id] = [
                'text' => $this->fmt($view->totalEcts).(null !== $target ? ' / '.$target : ''),
                'state' => null === $target ? '' : ($view->ectsReached() ? 'ok' : 'gap'),
            ];
            $totals['hours:'.$id] = ['text' => $this->fmt($view->totalHours).' h', 'state' => ''];
            $totals['status:'.$id] = ['text' => '', 'state' => $view->status];
        }
        $leaf = end($path);

        return [
            'totals' => $totals,
            'row' => false !== $leaf ? ['hours' => $this->fmt($leaf->totalHours).' h'] : [],
        ];
    }

    /**
     * Chemin racine → nœud dans les vues calculées.
     *
     * @param list<NodeView> $views
     *
     * @return list<NodeView>|null
     */
    private function path(array $views, string $nid): ?array
    {
        foreach ($views as $view) {
            if ($view->node->getId() === $nid) {
                return [$view];
            }
            $sub = $this->path($view->children, $nid);
            if (null !== $sub) {
                return [$view, ...$sub];
            }
        }

        return null;
    }

    /** @param list<NodeView> $views */
    private function find(array $views, string $nid): ?NodeView
    {
        $path = $this->path($views, $nid);

        return null !== $path ? end($path) : null;
    }

    private function number(mixed $v): ?float
    {
        $v = str_replace(',', '.', trim((string) $v));

        return is_numeric($v) && (float) $v >= 0 ? (float) $v : null;
    }

    /** Retire les valeurs vides (récursif) pour garder le JSON compact. */
    private function clean(array $a): array
    {
        foreach ($a as $k => $v) {
            if (\is_array($v)) {
                $v = $this->clean($v);
            }
            if (null === $v || [] === $v) {
                unset($a[$k]);
            } else {
                $a[$k] = $v;
            }
        }

        return $a;
    }

    private function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, ',', ' '), '0'), ',');
    }
}
