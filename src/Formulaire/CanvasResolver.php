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
    public function __construct(
        private readonly CanvasDefaults $defaults,
        private readonly BuiltinScreenRegistry $builtins,
        private readonly NodeTypeRepository $types,
        private readonly TypeDiplomeResolver $versions,
        private readonly UrlGeneratorInterface $urls,
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
        return $this->forConfig($this->versions->configFor($formation));
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
     * sinon ; entrées masquées, écrans qui ne s'appliquent pas et formulaires disparus exclus.
     *
     * @return list<NavItem>
     */
    public function navigation(Formation $formation, ?TreeNode $parcours = null): array
    {
        $fiche = null !== $parcours ? CanvasSpec::PARCOURS : CanvasSpec::FORMATION;
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
            $items[] = new NavItem($fiche, $entry->key, $this->label($fiche, $entry), \count($items) + 1, $url, $status, null, $entry->isBuiltin());
        }

        return $items;
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
