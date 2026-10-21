<?php

declare(strict_types=1);

namespace App\Formulaire;

use App\Entity\FieldDef;
use App\Entity\NodeType;
use App\Repository\FieldDefRepository;
use App\Validation\ValidationRule;

/**
 * Champs effectifs d'un formulaire (NodeType). Un formulaire peut dériver d'un autre
 * (variante) : il ne stocke que ses écarts — capacités (true = champ ajouté, false =
 * champ masqué) et réglages de champ (libellé, aide, obligatoire, onglet, position).
 * La résolution part de la racine de la lignée ; le formulaire le plus proche gagne.
 * Un champ verrouillé (FieldDef::isLocked()) ne peut être ni masqué ni rendu facultatif
 * par une variante.
 */
final class FormResolver
{
    public function __construct(private readonly FieldDefRepository $fieldDefs)
    {
    }

    /** @return list<NodeType> racine d'abord */
    public function lineage(NodeType $form): array
    {
        $lineage = [];
        for ($t = $form; null !== $t; $t = $t->getParent()) {
            array_unshift($lineage, $t);
        }

        return $lineage;
    }

    /** @return array<string, bool> capacités fusionnées le long de la lignée */
    public function capabilities(NodeType $form): array
    {
        $merged = [];
        foreach ($this->lineage($form) as $t) {
            foreach ($t->getCapabilities() as $key => $value) {
                $merged[$key] = (bool) $value;
            }
        }

        return $merged;
    }

    /** @return list<ResolvedField> */
    public function fields(NodeType $form, bool $withHidden = false): array
    {
        $lineage = $this->lineage($form);
        $parent = $form->getParent();
        $base = [];
        if (null !== $parent) {
            foreach ($this->fields($parent, true) as $f) {
                $base[$f->getKey()] = $f;
            }
        }

        $capabilities = $this->capabilities($form);
        $out = [];
        foreach ($this->fieldDefs->allOrdered() as $def) {
            $key = $def->getKey();
            if (!\array_key_exists($key, $capabilities)) {
                continue;
            }
            $field = $this->resolve($def, $lineage, $capabilities[$key], $base[$key] ?? null, null !== $parent);
            if ($withHidden || !$field->hidden) {
                $out[] = $field;
            }
        }
        usort($out, static fn (ResolvedField $a, ResolvedField $b) => [$a->position, $a->def->getId()] <=> [$b->position, $b->def->getId()]);

        return $out;
    }

    /** @return list<ValidationRule> règles d'onglet de la lignée, racine d'abord */
    public function validationRules(NodeType $form): array
    {
        $rules = [];
        foreach ($this->lineage($form) as $t) {
            array_push($rules, ...$t->getValidationRules());
        }

        return $rules;
    }

    /** @return list<ResolvedField> champs visibles du parent apparus depuis le dernier acquittement de la variante */
    public function newParentFields(NodeType $variant): array
    {
        $parent = $variant->getParent();
        if (null === $parent) {
            return [];
        }
        $known = array_flip($variant->getKnownParentFields());

        return array_values(array_filter($this->fields($parent), static fn (ResolvedField $f) => !isset($known[$f->getKey()])));
    }

    public function acknowledgeParentFields(NodeType $variant): void
    {
        $parent = $variant->getParent();
        $variant->setKnownParentFields(null === $parent ? [] : array_map(static fn (ResolvedField $f) => $f->getKey(), $this->fields($parent)));
    }

    /** @param list<NodeType> $lineage */
    private function resolve(FieldDef $def, array $lineage, bool $active, ?ResolvedField $base, bool $isVariant): ResolvedField
    {
        $key = $def->getKey();
        $settings = [];
        $everActive = false;
        $everRequired = $def->isRequired();
        foreach ($lineage as $t) {
            $own = $t->getFieldSettings()[$key] ?? [];
            $settings = array_replace($settings, $own);
            $everActive = $everActive || (bool) ($t->getCapabilities()[$key] ?? false);
            $everRequired = $everRequired || true === ($own['required'] ?? null);
        }

        // un champ verrouillé ne peut être ni masqué ni rendu facultatif plus bas dans la lignée
        $locked = $def->isLocked();
        $hidden = $locked ? !$everActive : !$active;
        $required = $locked ? $everRequired : (bool) ($settings['required'] ?? $def->isRequired());

        $label = (string) ($settings['label'] ?? $def->getLabel());
        $help = \array_key_exists('help', $settings) ? $settings['help'] : $def->getHelp();
        $tab = (string) ($settings['tab'] ?? $def->getTab());
        $position = (int) ($settings['position'] ?? $def->getPosition());

        // référence des origines : le champ du parent, à défaut la définition partagée
        $ref = null !== $base && !$base->hidden
            ? ['label' => $base->label, 'help' => $base->help, 'required' => $base->required, 'tab' => $base->tab, 'position' => $base->position]
            : ['label' => $def->getLabel(), 'help' => $def->getHelp(), 'required' => $def->isRequired(), 'tab' => $def->getTab(), 'position' => $def->getPosition()];

        $origins = [];
        if ($hidden) {
            $origins[] = ResolvedField::HIDDEN;
        } elseif ($isVariant && (null === $base || $base->hidden)) {
            $origins[] = ResolvedField::ADDED;
        }
        if ($label !== $ref['label']) {
            $origins[] = ResolvedField::LABEL;
        }
        if ($help !== $ref['help']) {
            $origins[] = ResolvedField::HELP;
        }
        if ($required !== $ref['required']) {
            $origins[] = $required ? ResolvedField::REQUIRED : ResolvedField::OPTIONAL;
        }
        if ($tab !== $ref['tab'] || $position !== $ref['position']) {
            $origins[] = ResolvedField::MOVED;
        }

        return new ResolvedField($def, $label, $help, $required, $tab, $position, $hidden, [] === $origins ? [ResolvedField::INHERITED] : $origins);
    }
}
