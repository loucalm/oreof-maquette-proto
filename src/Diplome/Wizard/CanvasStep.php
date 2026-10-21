<?php

declare(strict_types=1);

namespace App\Diplome\Wizard;

use App\Diplome\Spec\CanvasEntry;
use App\Diplome\Spec\CanvasSpec;
use App\Diplome\Spec\VersionConfig;
use App\Diplome\WizardDraft;
use App\Entity\NodeType;
use App\Enum\NodeFamily;
use App\Form\Diplome\CanvasType;
use App\Formulaire\Builtin\BuiltinScreenRegistry;
use App\Formulaire\CanvasResolver;
use App\Repository\NodeTypeRepository;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Étape « Formulaires » : le canevas des fiches formation et parcours (entrées, formulaire de
 * chacune, libellé, masquage, ordre, entrées ajoutées). Elle suit l'étape « Structure » : le
 * canevas dépend de l'autorisation des parcours qui y est réglée.
 */
final class CanvasStep extends AbstractWizardStep
{
    public function __construct(
        private readonly CanvasResolver $canvas,
        private readonly BuiltinScreenRegistry $builtins,
        private readonly NodeTypeRepository $types,
    ) {
    }

    public function key(): string
    {
        return 'formulaires';
    }

    public function position(): int
    {
        return 45;
    }

    public function editable(): bool
    {
        return true;
    }

    public function formType(): string
    {
        return CanvasType::class;
    }

    public function initialData(WizardDraft $draft): array
    {
        $canvas = $this->canvas->forConfig($draft->config());
        $data = [];
        foreach (CanvasSpec::FICHES as $fiche) {
            $data[$fiche] = array_map(
                static fn (CanvasEntry $e, int $i) => ['key' => $e->key, 'builtin' => $e->builtin, 'position' => (string) $i, 'label' => $e->label, 'form' => $e->form, 'hidden' => $e->hidden],
                $canvas->entries($fiche),
                array_keys($canvas->entries($fiche)),
            );
        }

        return $data;
    }

    public function apply(WizardDraft $draft, mixed $formData): void
    {
        $canvas = new CanvasSpec();
        foreach (CanvasSpec::FICHES as $fiche) {
            $rows = array_values($formData[$fiche] ?? []);
            // ordre soumis (rang de chaque ligne), à rang égal l'ordre des lignes
            $order = array_keys($rows);
            usort($order, static fn (int $a, int $b) => [(int) ($rows[$a]['position'] ?? $a), $a] <=> [(int) ($rows[$b]['position'] ?? $b), $b]);

            $entries = [];
            $taken = [];
            foreach ($rows as $row) {
                if (null !== ($existing = self::text($row['key'] ?? null))) {
                    $taken[$existing] = true;
                }
            }
            foreach ($order as $i) {
                $row = $rows[$i];
                $builtin = self::text($row['builtin'] ?? null);
                $label = self::text($row['label'] ?? null);
                $key = self::text($row['key'] ?? null);
                if (null === $key) {
                    $key = $this->newKey((string) $label, $taken);
                    $taken[$key] = true;
                }
                $entries[] = null !== $builtin
                    ? new CanvasEntry($key, null, $builtin, $label, (bool) ($row['hidden'] ?? false))
                    : new CanvasEntry($key, self::text($row['form'] ?? null), null, $label, (bool) ($row['hidden'] ?? false));
            }
            $canvas = $canvas->withEntries($fiche, $entries);
        }

        $config = $draft->configData;
        unset($config['forms']);
        // forConfig() rajoute les écrans intégrés retirés de la saisie
        $config['canvas'] = $this->canvas->forConfig(VersionConfig::fromArray(['canvas' => $canvas->toArray()]))->toArray();
        $draft->configData = $config;
    }

    public function template(): string
    {
        return 'forms';
    }

    public function viewData(WizardDraft $draft): array
    {
        $builtins = [];
        foreach (CanvasSpec::FICHES as $fiche) {
            foreach ($this->builtins->forFiche($fiche) as $screen) {
                $builtins[$fiche][$screen->key()] = ['label' => $screen->label(), 'hideable' => $screen->hideable()];
            }
        }
        $forms = array_values(array_filter($this->types->findAllOrdered(), static fn (NodeType $t) => NodeFamily::Parameter === $t->getFamily() && null !== $t->getScope()));

        return [
            'builtins' => $builtins,
            'formIds' => array_combine(array_map(static fn (NodeType $t) => $t->getKey(), $forms), array_map(static fn (NodeType $t) => $t->getId(), $forms)),
            'formLabels' => array_combine(array_map(static fn (NodeType $t) => $t->getKey(), $forms), array_map(static fn (NodeType $t) => $t->getLabel(), $forms)),
            'library' => $this->library($forms),
        ];
    }

    /**
     * Bibliothèque en arbre (formulaire de départ, puis ses variantes en retrait).
     *
     * @param list<NodeType> $forms
     *
     * @return list<array{form: NodeType, depth: int}>
     */
    private function library(array $forms): array
    {
        $out = [];
        $walk = function (?NodeType $parent, int $depth) use (&$walk, &$out, $forms): void {
            foreach ($forms as $form) {
                if ($form->getParent() === $parent) {
                    $out[] = ['form' => $form, 'depth' => $depth];
                    $walk($form, $depth + 1);
                }
            }
        };
        $walk(null, 0);

        return $out;
    }

    /** @param array<string, true> $taken */
    private function newKey(string $label, array $taken): string
    {
        $base = (new AsciiSlugger())->slug('' !== $label ? $label : 'entree')->lower()->toString();
        $key = $base;
        for ($n = 2; isset($taken[$key]); ++$n) {
            $key = $base.'-'.$n;
        }

        return $key;
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
