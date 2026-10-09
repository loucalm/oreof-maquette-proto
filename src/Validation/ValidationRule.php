<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Règle de validation d'un onglet, rangée en JSON sur le type (NodeType::validationRules).
 * Deux formes, assez pour les cas réels sans moteur de formules :
 *   - « obligatoire si » : `fields[0]` devient obligatoire quand `whenField whenOp whenValue` est vrai
 *     (ex. Code RNCP obligatoire si « Inscrite au RNCP ? » = oui) ;
 *   - « au moins un parmi » : un des `fields` doit être rempli.
 * Les bornes min / max d'un champ ne sont pas des règles : elles vivent sur le champ (FieldDef).
 */
final readonly class ValidationRule
{
    public const REQUIRED_IF = 'required_if';
    public const ONE_OF = 'one_of';

    public const KINDS = [
        self::REQUIRED_IF => 'Obligatoire sous condition',
        self::ONE_OF => 'Au moins un parmi',
    ];

    public const OPERATORS = [
        'eq' => 'est égal à',
        'neq' => 'est différent de',
        'contains' => 'contient',
        'filled' => 'est rempli',
    ];

    /** @param list<string> $fields */
    public function __construct(
        public string $id,
        public string $kind,
        public string $tab,
        public array $fields,
        public ?string $whenField = null,
        public ?string $whenOp = null,
        public ?string $whenValue = null,
        public ?string $message = null,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            id: (string) ($raw['id'] ?? ''),
            kind: (string) ($raw['kind'] ?? self::REQUIRED_IF),
            tab: (string) ($raw['tab'] ?? 'props'),
            fields: array_values(array_map('strval', (array) ($raw['fields'] ?? []))),
            whenField: isset($raw['whenField']) ? (string) $raw['whenField'] : null,
            whenOp: isset($raw['whenOp']) ? (string) $raw['whenOp'] : null,
            whenValue: isset($raw['whenValue']) ? (string) $raw['whenValue'] : null,
            message: isset($raw['message']) && '' !== $raw['message'] ? (string) $raw['message'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'kind' => $this->kind,
            'tab' => $this->tab,
            'fields' => $this->fields,
            'whenField' => $this->whenField,
            'whenOp' => $this->whenOp,
            'whenValue' => $this->whenValue,
            'message' => $this->message,
        ], static fn ($v) => null !== $v);
    }
}
