<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\FieldDef;
use App\FieldType\FieldTypeInterface;
use App\Formulaire\ResolvedField;
use App\FieldType\FieldTypeRegistry;
use App\Maquette\Doc\TreeNode;
use App\FieldType\Options\FieldOptionsSources;
use App\Repository\ReferentielRepository;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Rendu générique d'un champ : chaque type de champ (App\FieldType) désigne son gabarit de saisie
 * et son gabarit d'affichage ; les écrans n'ont plus à connaître le type.
 *
 * La définition peut être un FieldDef (pages paramètre) ou le tableau de l'AttributeCatalog
 * (panneau d'un nœud) : les gabarits la reçoivent toujours sous la même forme (cf. normalize()).
 */
final class FieldTypeExtension extends AbstractExtension
{
    public function __construct(
        private readonly FieldTypeRegistry $types,
        private readonly ReferentielRepository $referentiels,
        private readonly FieldOptionsSources $optionsSources,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('field_widget', $this->widget(...), ['needs_environment' => true, 'is_safe' => ['html']]),
            new TwigFunction('field_view', $this->view(...), ['needs_environment' => true, 'is_safe' => ['html']]),
            new TwigFunction('field_available', $this->available(...)),
            new TwigFunction('field_type', fn (FieldDef|ResolvedField|array $def): FieldTypeInterface => $this->type($def)),
        ];
    }

    /**
     * Widget de saisie. `$inputName` est le nom HTML complet de l'input (`attr_ects`, `p[ects]`) ;
     * `$variant` (« node » | « param », obligatoire et explicite) sélectionne l'habillage de l'écran appelant.
     *
     * @param list<string> $errors
     */
    public function widget(Environment $twig, FieldDef|ResolvedField|array $def, string $key, mixed $value, string $inputName, ?TreeNode $node = null, array $errors = [], ?string $variant = null): string
    {
        if (!\in_array($variant, ['node', 'param'], true)) {
            throw new \InvalidArgumentException('field_widget() : variante « node » ou « param » requise.');
        }
        $def = $this->normalize($def);

        return $twig->render($this->type($def)->widgetTemplate(), [
            'def' => $def,
            'key' => $key,
            'value' => $value,
            'name' => $inputName,
            'options' => $this->options($def, $node),
            'node' => $node,
            'errors' => $errors,
            'variant' => $variant,
        ]);
    }

    /** Faux si les options du champ viennent d'une source calculée qui n'en donne aucune pour ce nœud : rien à choisir, on ne l'affiche pas. */
    public function available(FieldDef|ResolvedField|array $def, ?TreeNode $node = null): bool
    {
        $def = $this->normalize($def);

        return empty($def['optionsSource']) || [] !== $this->options($def, $node);
    }

    /** Affichage en lecture seule d'une valeur. */
    public function view(Environment $twig, FieldDef|ResolvedField|array $def, mixed $value, ?TreeNode $node = null): string
    {
        $def = $this->normalize($def);

        return $twig->render($this->type($def)->viewTemplate(), [
            'def' => $def,
            'value' => $value,
            'options' => $this->options($def, $node),
            'node' => $node,
        ]);
    }

    /** @param FieldDef|ResolvedField|array<string, mixed> $def */
    private function type(FieldDef|ResolvedField|array $def): FieldTypeInterface
    {
        return $this->types->get(\is_array($def) ? (string) ($def['type'] ?? $def['field'] ?? 'text') : $def->getType());
    }

    /** @param array<string, mixed> $def */
    private function options(array $def, ?TreeNode $node): array
    {
        if (!empty($def['optionsSource'])) {
            return $this->optionsSources->options((string) $def['optionsSource'], $node);
        }

        return $def['referentielKey'] ? $this->referentiels->values($def['referentielKey']) : $def['options'];
    }

    /**
     * Forme unique pour les gabarits (`type` = clé du type de champ).
     *
     * @param FieldDef|ResolvedField|array<string, mixed> $def
     *
     * @return array<string, mixed>
     */
    private function normalize(FieldDef|ResolvedField|array $def): array
    {
        // champ résolu d'un formulaire : libellé, aide et obligatoire propres à ce formulaire
        if ($def instanceof ResolvedField) {
            return ['label' => $def->label, 'required' => $def->required, 'help' => $def->help] + $this->normalize($def->def);
        }
        if ($def instanceof FieldDef) {
            return [
                'key' => $def->getKey(), 'label' => $def->getLabel(), 'type' => $def->getType(),
                'options' => $def->getOptions(), 'referentielKey' => $def->getReferentielKey(), 'optionsSource' => $def->getOptionsSource(),
                'allowExtra' => $def->isAllowExtra(), 'quickAdd' => $def->isQuickAdd(),
                'required' => $def->isRequired(), 'help' => $def->getHelp(),
                'min' => $def->getMin(), 'max' => $def->getMax(), 'placeholder' => $def->getPlaceholder(),
            ];
        }

        return ['type' => $def['field'] ?? $def['type'] ?? 'text'] + $def + [
            'options' => [], 'referentielKey' => null, 'optionsSource' => null, 'allowExtra' => false, 'quickAdd' => false,
            'required' => false, 'help' => null, 'min' => null, 'max' => null, 'placeholder' => null,
        ];
    }
}
