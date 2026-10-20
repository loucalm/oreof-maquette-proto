<?php

declare(strict_types=1);

namespace App\Diplome\Spec;

/** Règle de validation paramétrée d'un diplôme : gabarit + paramètres, portée et gravité (`error` | `warning`). */
final readonly class RuleSpec
{
    public const ERROR = 'error';
    public const WARNING = 'warning';

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public string $uid,
        public string $template,
        public array $params,
        public string $scopeLevel,
        public string $severity,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            (string) ($a['uid'] ?? ''),
            (string) ($a['template'] ?? ''),
            $a['params'] ?? [],
            (string) ($a['scopeLevel'] ?? ''),
            (string) ($a['severity'] ?? self::ERROR),
        );
    }

    /**
     * Forme canonique : uid, template, params, scopeLevel, severity.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'template' => $this->template,
            'params' => $this->params,
            'scopeLevel' => $this->scopeLevel,
            'severity' => $this->severity,
        ];
    }
}
