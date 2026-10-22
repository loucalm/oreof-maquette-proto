<?php

declare(strict_types=1);

namespace App\Diplome\Spec;

/**
 * Structure d'un diplôme : chaîne de niveaux, bornes, types d'EC, règles.
 *
 * `duration` : niveau qui compte la durée et ses bornes ; `natures` : types d'élément
 * (clé + libellé) proposés au niveau feuille.
 */
final readonly class StructureSpec
{
    /**
     * @param list<LevelSpec>       $levels
     * @param list<EcType>          $natures
     * @param list<RuleSpec>        $rules
     */
    public function __construct(
        public array $levels = [],
        public bool $parcoursAllowed = false,
        public ?DurationSpec $duration = null,
        public array $natures = [],
        public array $rules = [],
    ) {
    }

    /** @param array<string, mixed> $a les clés inconnues (héritées de formes anciennes) sont ignorées */
    public static function fromArray(array $a): self
    {
        $levels = array_values(array_map(LevelSpec::fromArray(...), $a['levels'] ?? []));

        return new self(
            $levels,
            (bool) ($a['parcoursAllowed'] ?? false),
            DurationSpec::fromArray($a['duration'] ?? null, $levels[0]->typeKey ?? ''),
            array_values(array_map(EcType::fromArray(...), $a['natures'] ?? [])),
            array_values(array_map(RuleSpec::fromArray(...), $a['rules'] ?? [])),
        );
    }

    /** @return list<string> */
    public function natureKeys(): array
    {
        return array_map(static fn (EcType $t) => $t->key, $this->natures);
    }

    /**
     * Forme canonique : levels, parcoursAllowed, duration, natures, rules.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'levels' => array_map(static fn (LevelSpec $l) => $l->toArray(), $this->levels),
            'parcoursAllowed' => $this->parcoursAllowed,
            'duration' => $this->duration?->toArray(),
            'natures' => array_map(static fn (EcType $t) => $t->toArray(), $this->natures),
            'rules' => array_map(static fn (RuleSpec $r) => $r->toArray(), $this->rules),
        ];
    }
}
