<?php

declare(strict_types=1);

namespace App\Formulaire;

use App\Diplome\Spec\CanvasEntry;
use App\Diplome\Spec\CanvasSpec;
use App\Diplome\Spec\VersionConfig;
use App\Diplome\TypeDiplomeResolver;
use App\Entity\Formation;
use App\Entity\NodeType;
use App\Formulaire\Builtin\BuiltinScreenRegistry;
use App\Formulaire\Preview\PreviewContext;
use App\Maquette\Doc\TreeNode;
use App\Repository\NodeTypeRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Canevas effectif d'une version ou d'une formation, et navigation des éditeurs qui en
 * découle. Une version sans canevas prend le canevas par défaut (CanvasDefaults), sur
 * lequel l'ancienne configuration `forms` est reportée. Les écrans intégrés sont toujours
 * présents : ceux qu'un canevas enregistré n'a pas sont ajoutés en fin de fiche.
 */
final class CanvasResolver
{
    /** Groupe de navigation des entrées du parcours unique d'une formation sans parcours. */
    public const GROUP_PARCOURS = 'parcours';

    public function __construct(
        private readonly CanvasDefaults $defaults,
        private readonly BuiltinScreenRegistry $builtins,
        private readonly NodeTypeRepository $types,
        private readonly TypeDiplomeResolver $versions,
        private readonly UrlGeneratorInterface $urls,
        private readonly PreviewContext $preview,
    ) {
    }

    public function forConfig(?VersionConfig $config): CanvasSpec
    {
        $canvas = $config?->canvas ?? $this->withLegacyForms($this->defaults->canvas(), $config?->legacyForms ?? []);

        foreach (CanvasSpec::FICHES as $fiche) {
            $entries = $canvas->entries($fiche);
            foreach ($this->builtins->forFiche($fiche) as $screen) {
                if ([] === array_filter($entries, static fn (CanvasEntry $e) => $e->builtin === $screen->key())) {
                    $entries[] = new CanvasEntry($screen->key(), null, $screen->key(), null, false);
                }
            }
            $canvas = $canvas->withEntries($fiche, $entries);
        }

        return $canvas;
    }

    public function forFormation(Formation $formation): CanvasSpec
    {
        // aperçu en cours sur cette formation : le canevas non enregistré remplace celui de la version
        $preview = $this->preview->canvasFor($formation);

        return null !== $preview ? $this->forConfig(VersionConfig::fromArray(['canvas' => $preview->toArray()])) : $this->forConfig($this->versions->configFor($formation));
    }

    public function label(string $fiche, CanvasEntry $entry): string
    {
        $default = $entry->isBuiltin()
            ? $this->builtins->get($fiche, (string) $entry->builtin)?->label()
            : $this->form($entry)?->getLabel();

        return $entry->label ?? $default ?? $entry->key;
    }

    public function form(CanvasEntry $entry): ?NodeType
    {
        return null !== $entry->form ? $this->types->findOneByKey($entry->form) : null;
    }

    /** Entrée à formulaire, visible, dont le formulaire existe ; null sinon. */
    public function formEntry(Formation $formation, string $fiche, string $key): ?CanvasEntry
    {
        $entry = $this->forFormation($formation)->entry($fiche, $key);

        return null === $entry || $entry->hidden || $entry->isBuiltin() || null === $this->form($entry) ? null : $entry;
    }

    /**
     * Liens de la barre latérale : fiche parcours si `$parcours` est donné, fiche formation
     * sinon. Formation sans parcours : chaque entrée à formulaire du parcours unique rejoint
     * l'entrée de même clé de la fiche formation (une seule page, `withMono`) ; celles sans
     * équivalent suivent, groupe GROUP_PARCOURS. Entrées masquées, écrans qui ne s'appliquent
     * pas et formulaires disparus exclus.
     *
     * @return list<NavItem>
     */
    public function navigation(Formation $formation, ?TreeNode $parcours = null): array
    {
        $fiche = null !== $parcours ? CanvasSpec::PARCOURS : CanvasSpec::FORMATION;
        $mono = null === $parcours ? $this->monoEntries($formation) : [];
        $items = [];
        foreach ($this->forFormation($formation)->entries($fiche) as $entry) {
            if ($entry->hidden) {
                continue;
            }
            if ($entry->isBuiltin()) {
                $screen = $this->builtins->get($fiche, (string) $entry->builtin);
                if (null === $screen || !$screen->appliesTo($formation)) {
                    continue;
                }
                $url = $screen->url($formation, $parcours);
                $status = $screen->status($formation, $parcours);
            } else {
                if (null === $this->form($entry)) {
                    continue;
                }
                $url = null !== $parcours
                    ? $this->urls->generate('parcours_param', ['fid' => $formation->getId(), 'nid' => $parcours->getId(), 'key' => $entry->key])
                    : $this->urls->generate('formation_param', ['id' => $formation->getId(), 'key' => $entry->key]);
                $status = null;
            }
            $withMono = !$entry->isBuiltin() && isset($mono[$entry->key]);
            if ($withMono) {
                unset($mono[$entry->key]);
            }
            $items[] = new NavItem($fiche, $entry->key, $this->label($fiche, $entry), \count($items) + 1, $url, $status, null, $entry->isBuiltin(), withMono: $withMono);
        }

        // entrées du parcours unique sans équivalent dans la fiche formation
        foreach ($mono as $entry) {
            $url = $this->urls->generate('parcours_unique_param', ['id' => $formation->getId(), 'key' => $entry->key]);
            $items[] = new NavItem(CanvasSpec::PARCOURS, $entry->key, $this->label(CanvasSpec::PARCOURS, $entry), \count($items) + 1, $url, null, self::GROUP_PARCOURS, false);
        }

        return $items;
    }

    /**
     * Vrai si, pour cette formation sans parcours, l'entrée `$key` du parcours unique est remplie
     * sur la page de l'entrée de même clé de la fiche formation.
     */
    public function monoMerged(Formation $formation, string $key): bool
    {
        if (!isset($this->monoEntries($formation)[$key])) {
            return false;
        }
        $entry = $this->forFormation($formation)->entry(CanvasSpec::FORMATION, $key);

        return null !== $entry && !$entry->hidden && !$entry->isBuiltin() && null !== $this->form($entry);
    }

    /**
     * Entrées à formulaire visibles du parcours unique (formation sans parcours), par clé.
     *
     * @return array<string, CanvasEntry>
     */
    private function monoEntries(Formation $formation): array
    {
        if (!$formation->isSansParcours()) {
            return [];
        }
        $out = [];
        foreach ($this->forFormation($formation)->entries(CanvasSpec::PARCOURS) as $entry) {
            if (!$entry->hidden && !$entry->isBuiltin() && null !== $this->form($entry)) {
                $out[$entry->key] = $entry;
            }
        }

        return $out;
    }

    /** @param array<string, string> $legacyForms emplacement `<fiche>.<entrée>` → clé de formulaire */
    private function withLegacyForms(CanvasSpec $canvas, array $legacyForms): CanvasSpec
    {
        foreach ($legacyForms as $slot => $form) {
            [$fiche, $key] = array_pad(explode('.', $slot, 2), 2, '');
            if (!\in_array($fiche, CanvasSpec::FICHES, true) || null === $canvas->entry($fiche, $key)) {
                continue;
            }
            $canvas = $canvas->withEntries($fiche, array_map(
                static fn (CanvasEntry $e) => $e->key === $key && !$e->isBuiltin() ? new CanvasEntry($e->key, $form, null, $e->label, $e->hidden) : $e,
                $canvas->entries($fiche),
            ));
        }

        return $canvas;
    }
}
