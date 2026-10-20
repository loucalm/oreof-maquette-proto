<?php

declare(strict_types=1);

namespace App\Diplome\Spec;

use App\Maquette\Doc\TreeNode;

/**
 * Configuration complète d'une version de type de diplôme (colonne JSON de
 * `TypeDiplomeVersion`), lue de façon typée.
 *
 * - `forms` : emplacement (`formation.organisation`, `formation.presentation`,
 *   `parcours.organisation`, `parcours.presentation`) → clé de NodeType de famille `parameter` ;
 * - `mccc` : nature → clés MccType autorisées + profil ;
 * - `options` : quitus / assiduité / sans note ;
 * - `bccLevels` : noms des niveaux de BCC (1 à 3) ;
 * - `bccLinkLevel` : indice 0-based du niveau relié aux éléments (défaut : le dernier).
 *
 * Forme canonique de `toArray()` : structure, forms, mccc, options (quitus,
 * assiduite, sansNote), bccLevels, bccLinkLevel.
 */
final readonly class VersionConfig
{
    /**
     * @param array<string, string>                                 $forms
     * @param array<string, array{types: list<string>, profile: ?string}> $mccc
     * @param array{quitus: bool, assiduite: bool, sansNote: bool}  $options
     * @param list<string>                                          $bccLevels
     */
    public function __construct(
        public StructureSpec $structure,
        public array $forms,
        public array $mccc,
        public array $options,
        public array $bccLevels,
        public int $bccLinkLevel,
    ) {
    }

    /**
     * Nature d'élément d'un nœud : son attribut `natureElement`, à défaut la première nature déclarée
     * par la structure (à défaut, la première nature du MCCC). Règle unique, partagée par le contexte
     * de vérification et le MCCC.
     */
    public function natureOf(TreeNode $node): ?string
    {
        $nature = $node->getAttribute('natureElement');
        if (\is_string($nature) && '' !== $nature) {
            return $nature;
        }

        return $this->structure->natures[0] ?? array_key_first($this->mccc);
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $mccc = [];
        foreach ($a['mccc'] ?? [] as $nature => $m) {
            $mccc[(string) $nature] = [
                'types' => array_values(array_map('strval', $m['types'] ?? [])),
                'profile' => isset($m['profile']) ? (string) $m['profile'] : null,
            ];
        }
        $o = $a['options'] ?? [];
        $bccLevels = array_values(array_map('strval', $a['bccLevels'] ?? []));

        return new self(
            StructureSpec::fromArray($a['structure'] ?? []),
            array_map('strval', $a['forms'] ?? []),
            $mccc,
            [
                'quitus' => (bool) ($o['quitus'] ?? false),
                'assiduite' => (bool) ($o['assiduite'] ?? false),
                'sansNote' => (bool) ($o['sansNote'] ?? false),
            ],
            $bccLevels,
            (int) ($a['bccLinkLevel'] ?? max(0, \count($bccLevels) - 1)),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'structure' => $this->structure->toArray(),
            'forms' => $this->forms,
            'mccc' => $this->mccc,
            'options' => $this->options,
            'bccLevels' => $this->bccLevels,
            'bccLinkLevel' => $this->bccLinkLevel,
        ];
    }
}
