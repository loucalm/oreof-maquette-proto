<?php

declare(strict_types=1);

namespace App\Diplome\Spec;

/**
 * Canevas d'une version : la liste ordonnée des entrées de la fiche formation et de la
 * fiche parcours (cf. CanvasResolver pour le canevas effectif d'une formation), et le
 * formulaire de la fiche matière.
 *
 * La fiche matière n'a qu'une entrée, de clé FICHE_MATIERE, dont seul le formulaire se
 * choisit : ni libellé, ni masquage, ni écran intégré, ni entrée ajoutée (une fiche
 * d'élément, cf. spec canevas §8). Toute liste reçue est ramenée à cette entrée unique.
 */
final readonly class CanvasSpec
{
    public const FORMATION = 'formation';
    public const PARCOURS = 'parcours';
    public const FICHE_MATIERE = 'fiche_matiere';
    /** Toutes les fiches du canevas. */
    public const FICHES = [self::FORMATION, self::PARCOURS, self::FICHE_MATIERE];
    /** Fiches affichées sur une formation (barres latérales, pages d'entrée, parcours unique). */
    public const FORMATION_FICHES = [self::FORMATION, self::PARCOURS];

    /** @var list<CanvasEntry> */
    public array $ficheMatiere;

    /**
     * @param list<CanvasEntry> $formation
     * @param list<CanvasEntry> $parcours
     * @param list<CanvasEntry> $ficheMatiere ramenée à une seule entrée (cf. single())
     */
    public function __construct(
        public array $formation = [],
        public array $parcours = [],
        array $ficheMatiere = [],
    ) {
        $this->ficheMatiere = self::single($ficheMatiere);
    }

    /** Vrai si les entrées de cette fiche sont libres (ajout, retrait, libellé, masquage, ordre). */
    public static function hasFreeEntries(string $fiche): bool
    {
        return \in_array($fiche, self::FORMATION_FICHES, true);
    }

    /** @return list<CanvasEntry> */
    public function entries(string $fiche): array
    {
        return match ($fiche) {
            self::PARCOURS => $this->parcours,
            self::FICHE_MATIERE => $this->ficheMatiere,
            default => $this->formation,
        };
    }

    public function entry(string $fiche, string $key): ?CanvasEntry
    {
        foreach ($this->entries($fiche) as $entry) {
            if ($entry->key === $key) {
                return $entry;
            }
        }

        return null;
    }

    /** @param list<CanvasEntry> $entries */
    public function withEntries(string $fiche, array $entries): self
    {
        $entries = array_values($entries);

        return match ($fiche) {
            self::PARCOURS => new self($this->formation, $entries, $this->ficheMatiere),
            self::FICHE_MATIERE => new self($this->formation, $this->parcours, $entries),
            default => new self($entries, $this->parcours, $this->ficheMatiere),
        };
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $list = static fn (string $fiche): array => array_values(array_map(
            static fn (array $e) => CanvasEntry::fromArray($e),
            array_filter($a[$fiche] ?? [], 'is_array'),
        ));

        return new self($list(self::FORMATION), $list(self::PARCOURS), $list(self::FICHE_MATIERE));
    }

    /** @return array{formation: list<array<string, mixed>>, parcours: list<array<string, mixed>>, fiche_matiere: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            self::FORMATION => array_map(static fn (CanvasEntry $e) => $e->toArray(), $this->formation),
            self::PARCOURS => array_map(static fn (CanvasEntry $e) => $e->toArray(), $this->parcours),
            self::FICHE_MATIERE => array_map(static fn (CanvasEntry $e) => $e->toArray(), $this->ficheMatiere),
        ];
    }

    /**
     * Entrée unique de la fiche matière : le formulaire de la première entrée qui en désigne un
     * (à défaut, aucune entrée : CanvasResolver prend alors le formulaire par défaut).
     *
     * @param list<CanvasEntry> $entries
     *
     * @return list<CanvasEntry>
     */
    private static function single(array $entries): array
    {
        foreach ($entries as $entry) {
            if (null !== $entry->form) {
                return [new CanvasEntry(self::FICHE_MATIERE, $entry->form, null, null, false)];
            }
        }

        return [];
    }
}
