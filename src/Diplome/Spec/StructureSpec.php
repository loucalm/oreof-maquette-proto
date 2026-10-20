<?php

declare(strict_types=1);

namespace App\Diplome\Spec;

/**
 * Structure d'un diplôme : chaîne de niveaux, bornes, éléments imposés, règles.
 *
 * `duration` borne le nombre de nœuds du premier niveau temporel ; `natures` =
 * clés de nature d'élément proposées au niveau feuille.
 */
final readonly class StructureSpec
{
    /**
     * @param list<LevelSpec>       $levels
     * @param list<string>          $natures
     * @param list<ImposedElement>  $imposed
     * @param list<RuleSpec>        $rules
     */
    public function __construct(
        public array $levels = [],
        public bool $parcoursAllowed = false,
        public ?Bound $duration = null,
        public array $natures = [],
        public array $imposed = [],
        public array $rules = [],
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            array_values(array_map(LevelSpec::fromArray(...), $a['levels'] ?? [])),
            (bool) ($a['parcoursAllowed'] ?? false),
            Bound::fromArray($a['duration'] ?? null),
            array_values(array_map('strval', $a['natures'] ?? [])),
            array_values(array_map(ImposedElement::fromArray(...), $a['imposed'] ?? [])),
            array_values(array_map(RuleSpec::fromArray(...), $a['rules'] ?? [])),
        );
    }

    /**
     * Forme canonique : levels, parcoursAllowed, duration, natures, imposed, rules.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'levels' => array_map(static fn (LevelSpec $l) => $l->toArray(), $this->levels),
            'parcoursAllowed' => $this->parcoursAllowed,
            'duration' => $this->duration?->toArray(),
            'natures' => $this->natures,
            'imposed' => array_map(static fn (ImposedElement $i) => $i->toArray(), $this->imposed),
            'rules' => array_map(static fn (RuleSpec $r) => $r->toArray(), $this->rules),
        ];
    }
}
