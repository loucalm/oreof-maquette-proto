<?php

declare(strict_types=1);

namespace App\Validation;

use App\Entity\FieldDef;
use App\Entity\NodeType;
use App\FieldType\FieldTypeRegistry;
use App\Formulaire\FormResolver;
use App\Repository\FieldDefRepository;
use App\Repository\ReferentielRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Applique à un formulaire (nœud ou section Paramètre) ce qui s'ajoute aux champs
 * obligatoires : les règles des onglets (ValidationRule) et les bornes des champs
 * (valeur, longueur, nombre de choix — cf. FieldTypeInterface::violations()).
 *
 * Ne connaît pas l'origine des valeurs : l'appelant fournit une fonction clé → valeur.
 */
final class TabValidator implements ResetInterface
{
    /** @var array<string, FieldDef>|null */
    private ?array $defs = null;

    public function __construct(
        private readonly FieldDefRepository $fields,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly ReferentielRepository $referentiels,
        private readonly FormResolver $forms,
    ) {
    }

    /**
     * @param callable(string): mixed $value      valeur d'un champ
     * @param list<string>            $activeKeys   champs présents dans ce formulaire
     * @param list<string>|null       $requiredKeys champs obligatoires de ce formulaire (réglages résolus,
     *                                              cf. FormResolver) ; à défaut, FieldDef::isRequired()
     *
     * @return array{units: int, issues: list<array{key: string, label: string, tab: string, kind: string}>}
     *                units = éléments à satisfaire en plus des champs obligatoires
     *                (conditions déclenchées, « au moins un », bornes violées) ;
     *                issues = ceux qui ne le sont pas (kind « missing » ou « invalid »)
     */
    public function check(NodeType $type, callable $value, array $activeKeys, ?array $requiredKeys = null): array
    {
        $defs = $this->defs();
        $active = array_flip($activeKeys);
        $required = null !== $requiredKeys ? array_flip($requiredKeys) : null;
        $units = 0;
        $issues = [];

        foreach ($this->forms->validationRules($type) as $rule) {
            if (ValidationRule::REQUIRED_IF === $rule->kind) {
                $target = $rule->fields[0] ?? '';
                $isRequired = null !== $required ? isset($required[$target]) : (bool) ($defs[$target] ?? null)?->isRequired();
                if (!isset($active[$target]) || $isRequired || !$this->conditionMet($rule, $value)) {
                    continue;
                }
                ++$units;
                if (!$this->isFilled($target, $value($target))) {
                    $issues[] = $this->issue($target, $rule->message ?? $this->label($target).' : '.$this->conditionText($rule), $rule->tab, 'missing');
                }
            } elseif (ValidationRule::ONE_OF === $rule->kind) {
                $targets = array_values(array_filter($rule->fields, static fn (string $k) => isset($active[$k])));
                if ([] === $targets) {
                    continue;
                }
                ++$units;
                if ([] === array_filter($targets, fn (string $k) => $this->isFilled($k, $value($k)))) {
                    $issues[] = $this->issue($targets[0], $rule->message ?? $this->describe($rule), $rule->tab, 'missing');
                }
            }
        }

        foreach ($activeKeys as $key) {
            $def = $defs[$key] ?? null;
            $v = $value($key);
            if (null === $def || !$this->isFilled($key, $v)) {
                continue;
            }
            foreach ($this->fieldTypes->get($def->getType())->violations($v, $def) as $message) {
                ++$units;
                $issues[] = $this->issue($key, $message, $def->getTab(), 'invalid');
            }
        }

        return ['units' => $units, 'issues' => $issues];
    }

    /**
     * Mentions « obligatoire si … » à afficher sous le libellé des champs concernés.
     *
     * @return array<string, string>
     */
    public function hints(NodeType $type): array
    {
        $out = [];
        foreach ($this->forms->validationRules($type) as $rule) {
            if (ValidationRule::REQUIRED_IF === $rule->kind && isset($rule->fields[0])) {
                $out[$rule->fields[0]] = $this->conditionText($rule);
            }
        }

        return $out;
    }

    /** Phrase lisible d'une règle (liste des règles, message par défaut). */
    public function describe(ValidationRule $rule): string
    {
        return ValidationRule::ONE_OF === $rule->kind
            ? 'Renseigner au moins un champ parmi : '.implode(', ', array_map(fn (string $k) => '« '.$this->label($k).' »', $rule->fields)).'.'
            : '« '.$this->label($rule->fields[0] ?? '').' » '.$this->conditionText($rule).'.';
    }

    public function reset(): void
    {
        $this->defs = null;
    }

    private function conditionText(ValidationRule $rule): string
    {
        $text = 'obligatoire si « '.$this->label((string) $rule->whenField).' » '.(ValidationRule::OPERATORS[$rule->whenOp] ?? '');

        return 'filled' === $rule->whenOp ? $text : $text.' « '.$this->valueLabel((string) $rule->whenField, (string) $rule->whenValue).' »';
    }

    /** @param callable(string): mixed $value */
    private function conditionMet(ValidationRule $rule, callable $value): bool
    {
        $field = (string) $rule->whenField;
        $actual = $value($field);
        $expected = (string) $rule->whenValue;
        // choix multiple : « est égal à » = exactement cette seule valeur cochée ; « contient » = parmi les cochées
        $equals = \is_array($actual) ? array_map('strval', array_values($actual)) === [$expected] : (string) $actual === $expected;

        return match ($rule->whenOp) {
            'eq' => $equals,
            'neq' => !$equals,
            'contains' => \is_array($actual)
                ? \in_array($expected, array_map('strval', $actual), true)
                : str_contains(mb_strtolower((string) $actual), mb_strtolower($expected)),
            'filled' => $this->isFilled($field, $actual),
            default => false,
        };
    }

    private function isFilled(string $key, mixed $v): bool
    {
        $def = $this->defs()[$key] ?? null;

        return null !== $def ? $this->fieldTypes->get($def->getType())->isFilled($v) : !(null === $v || '' === $v || [] === $v);
    }

    private function label(string $key): string
    {
        return ($this->defs()[$key] ?? null)?->getLabel() ?? $key;
    }

    private function valueLabel(string $key, string $value): string
    {
        $def = $this->defs()[$key] ?? null;
        $options = null === $def ? [] : (null !== $def->getReferentielKey() ? $this->referentiels->values($def->getReferentielKey()) : $def->getOptions());

        return (string) ($options[$value] ?? $value);
    }

    /** @return array{key: string, label: string, tab: string, kind: string} */
    private function issue(string $key, string $label, string $tab, string $kind): array
    {
        return ['key' => $key, 'label' => $label, 'tab' => $tab, 'kind' => $kind];
    }

    /** @return array<string, FieldDef> */
    private function defs(): array
    {
        if (null === $this->defs) {
            $this->defs = [];
            foreach ($this->fields->allOrdered() as $f) {
                $this->defs[$f->getKey()] = $f;
            }
        }

        return $this->defs;
    }
}
