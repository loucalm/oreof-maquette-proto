<?php

declare(strict_types=1);

namespace App\Formulaire;

use App\Diplome\Spec\CanvasSpec;
use App\Entity\Formation;
use App\Formulaire\Builtin\FormationBccScreen;
use App\Formulaire\Builtin\ParcoursBccScreen;
use App\Formulaire\Builtin\BuiltinScreenRegistry;
use App\Formulaire\Fiche\FicheFactory;
use App\Formulaire\Fiche\FicheTarget;
use App\Formulaire\Fiche\MonoParcoursFiche;
use App\Maquette\Doc\TreeNode;

/**
 * Ce que montrent les pages d'une formation à partir du canevas : liens de la barre latérale
 * (avec statut et panneau ouvert), entrées affichées en lecture seule (page Voir) et
 * paramètres exportés (export enrichi). Une entrée masquée n'apparaît nulle part ; ses
 * données restent stockées.
 */
final class FicheNavigation
{
    public function __construct(
        private readonly CanvasResolver $canvas,
        private readonly EntryCompletion $entries,
        private readonly FicheFactory $fiches,
        private readonly BuiltinScreenRegistry $builtins,
    ) {
    }

    /**
     * @param array<string, mixed> $query paramètres de requête de l'éditeur (panneau ouvert)
     *
     * @return list<NavItem>
     */
    public function items(Formation $formation, ?TreeNode $parcours = null, array $query = []): array
    {
        $fiche = null !== $parcours ? $this->fiches->forNode($parcours) : $this->fiches->formation($formation);
        $param = $query['param'] ?? null;
        $items = [];
        foreach ($this->canvas->navigation($formation, $parcours) as $item) {
            $navKey = self::navKey($item);
            if ($item->builtin) {
                $entry = $this->canvas->forFormation($formation)->entry($item->fiche, $item->key);
                $current = $param === $navKey || (null === $param && (bool) $this->builtins->get($item->fiche, (string) $entry?->builtin)?->isCurrent($query));
                $items[] = new NavItem($item->fiche, $item->key, $item->label, $item->number, $item->url, $item->status, $item->group, true, $current);
                continue;
            }
            if ($item->withMono) {
                $status = $this->entryStatus($formation, $item->key);
            } else {
                $target = CanvasResolver::GROUP_PARCOURS === $item->group ? $this->fiches->mono($formation) : $fiche;
                $status = $this->entries->status($target, $item->key);
            }
            $items[] = new NavItem($item->fiche, $item->key, $item->label, $item->number, $item->url, $status, $item->group, false, $param === $navKey, $item->withMono);
        }

        return $items;
    }

    /**
     * Statut d'une entrée de la fiche formation ; sans parcours, combiné avec celui de l'entrée
     * de même clé du parcours unique quand les deux sont remplies sur la même page.
     */
    public function entryStatus(Formation $formation, string $key): string
    {
        $status = $this->entries->status($this->fiches->formation($formation), $key);
        if (!$this->canvas->monoMerged($formation, $key)) {
            return $status;
        }
        $mono = $this->entries->status($this->fiches->mono($formation), $key);

        return match (true) {
            \in_array('error', [$status, $mono], true) => 'error',
            $status === $mono => $status,
            default => 'incomplete',
        };
    }

    /** Vrai si l'entrée `$key` du parcours unique se remplit sur la page de l'entrée de même clé de la formation. */
    public function monoMerged(Formation $formation, string $key): bool
    {
        return $this->canvas->monoMerged($formation, $key);
    }

    /** Vrai si un écran intégré de cette classe est affiché pour la formation (entrée visible et applicable). */
    public function shows(Formation $formation, string $screenClass): bool
    {
        $canvas = $this->canvas->forFormation($formation);
        foreach ([CanvasSpec::FORMATION, CanvasSpec::PARCOURS] as $fiche) {
            foreach ($canvas->entries($fiche) as $entry) {
                $screen = $entry->isBuiltin() && !$entry->hidden ? $this->builtins->get($fiche, (string) $entry->builtin) : null;
                if ($screen instanceof $screenClass && $screen->appliesTo($formation)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Vrai si le référentiel de compétences est affiché : écran BCC de la formation, ou des parcours s'il y en a. */
    public function bccShown(Formation $formation): bool
    {
        return $this->shows($formation, $formation->isAvecParcours() ? ParcoursBccScreen::class : FormationBccScreen::class);
    }

    /** Clé d'un lien dans l'éditeur (paramètre `param`, pastille de statut) : `<groupe>.<entrée>` dans un groupe. */
    public static function navKey(NavItem $item): string
    {
        return null !== $item->group ? $item->group.'.'.$item->key : $item->key;
    }

    /**
     * Entrées à formulaire visibles d'une fiche, pour un affichage en lecture seule.
     *
     * @return list<array{key: string, label: string, fields: list<ResolvedField>, data: array<string, mixed>}>
     */
    public function entries(FicheTarget $fiche): array
    {
        $out = [];
        foreach ($this->canvas->forFormation($fiche->formation())->entries($fiche->fiche()) as $entry) {
            if (null === $this->canvas->formEntry($fiche->formation(), $fiche->fiche(), $entry->key)) {
                continue;
            }
            $out[] = [
                'key' => $entry->key,
                'label' => $this->canvas->label($fiche->fiche(), $entry),
                'fields' => $this->entries->inputFields($fiche, $entry->key),
                'data' => $fiche->data($entry->key),
            ];
        }

        return $out;
    }

    /**
     * Paramètres d'une formation tels qu'exportés : sans les entrées masquées, chaque entrée
     * réduite aux champs de son formulaire ; les clés qui ne sont pas des entrées (référentiel
     * BCC, structure…) sont laissées telles quelles.
     *
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    public function visibleParametres(Formation $formation, array $stored): array
    {
        $fiches = [[$this->fiches->formation($formation), '']];
        if ($formation->isSansParcours()) {
            $fiches[] = [$this->fiches->mono($formation), MonoParcoursFiche::PREFIX];
        }
        foreach ($fiches as [$fiche, $prefix]) {
            foreach ($this->canvas->forFormation($formation)->entries($fiche->fiche()) as $entry) {
                if ($entry->isBuiltin()) {
                    continue;
                }
                $key = $prefix.$entry->key;
                if (null === $this->canvas->formEntry($formation, $fiche->fiche(), $entry->key)) {
                    unset($stored[$key]);
                    continue;
                }
                if (\is_array($stored[$key] ?? null)) {
                    $keep = array_flip(array_map(static fn (ResolvedField $f) => $f->getKey(), $this->entries->inputFields($fiche, $entry->key)));
                    $stored[$key] = array_intersect_key($stored[$key], $keep);
                }
            }
        }

        return $stored;
    }
}
