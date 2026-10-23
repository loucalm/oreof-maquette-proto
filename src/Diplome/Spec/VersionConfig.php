<?php

declare(strict_types=1);

namespace App\Diplome\Spec;

use App\Maquette\Doc\TreeNode;

/**
 * Configuration complète d'une version de type de diplôme (colonne JSON de
 * `TypeDiplomeVersion`), lue de façon typée.
 *
 * - `canvas` : canevas des fiches formation et parcours (null : canevas par défaut, cf.
 *   CanvasResolver) ; l'ancienne clé `forms` (emplacement → formulaire) n'est plus que
 *   lue, dans `legacyForms`, quand `canvas` est absent ;
 * - `mccc` : clé MccType autorisée dans la formation → profil de règles (null : aucun) ;
 * - `options` : quitus / assiduité / sans note ;
 * - `bccLevels` : noms des niveaux de BCC (1 à 3) ;
 * - `bccLinkLevel` : indice 0-based du niveau relié aux éléments (défaut : le dernier) ;
 * - `admission` : plateforme d'admission (clé du référentiel `plateformes_admission`) → numéros
 *   des années où l'on y candidate (ex. Licence : Parcoursup en année 1).
 *
 * Forme canonique de `toArray()` : structure, canvas, mccc, options (quitus,
 * assiduite, sansNote), bccLevels, bccLinkLevel, admission.
 */
final readonly class VersionConfig
{
    /**
     * @param array<string, string>                                 $legacyForms
     * @param array<string, array{profile: ?string}>                 $mccc
     * @param array{quitus: bool, assiduite: bool, sansNote: bool}  $options
     * @param list<string>                                          $bccLevels
     * @param array<string, list<int>>                              $admission
     */
    public function __construct(
        public StructureSpec $structure,
        public ?CanvasSpec $canvas,
        public array $legacyForms,
        public array $mccc,
        public array $options,
        public array $bccLevels,
        public int $bccLinkLevel,
        public array $admission = [],
    ) {
    }

    /**
     * Nature d'élément d'un nœud : son attribut `natureElement`, à défaut la première nature déclarée
     * par la structure. Règle unique, partagée par le contexte de vérification.
     */
    public function natureOf(TreeNode $node): ?string
    {
        $nature = $node->getAttribute('natureElement');
        if (\is_string($nature) && '' !== $nature) {
            return $nature;
        }

        return $this->structure->natureKeys()[0] ?? null;
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $mccc = [];
        foreach ($a['mccc'] ?? [] as $key => $m) {
            if (\is_array($m['types'] ?? null)) {
                // ancienne forme, par nature d'élément : union des types, le premier profil rencontré l'emporte
                foreach ($m['types'] as $type) {
                    $mccc[(string) $type] ??= ['profile' => isset($m['profile']) ? (string) $m['profile'] : null];
                }
                continue;
            }
            $mccc[(string) $key] = ['profile' => isset($m['profile']) ? (string) $m['profile'] : null];
        }
        $o = $a['options'] ?? [];
        $bccLevels = array_values(array_map('strval', $a['bccLevels'] ?? []));

        $canvas = \is_array($a['canvas'] ?? null) ? CanvasSpec::fromArray($a['canvas']) : null;

        return new self(
            StructureSpec::fromArray($a['structure'] ?? []),
            $canvas,
            null === $canvas ? array_map('strval', $a['forms'] ?? []) : [],
            $mccc,
            [
                'quitus' => (bool) ($o['quitus'] ?? false),
                'assiduite' => (bool) ($o['assiduite'] ?? false),
                'sansNote' => (bool) ($o['sansNote'] ?? false),
            ],
            $bccLevels,
            (int) ($a['bccLinkLevel'] ?? max(0, \count($bccLevels) - 1)),
            array_map(
                static fn ($years) => array_values(array_unique(array_map('intval', (array) $years))),
                array_filter((array) ($a['admission'] ?? []), static fn ($years) => [] !== (array) $years),
            ),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        // l'ancienne clé `forms` n'est réécrite que tant qu'aucun canevas ne la remplace
        $legacy = null === $this->canvas && [] !== $this->legacyForms ? ['forms' => $this->legacyForms] : [];

        return [
            'structure' => $this->structure->toArray(),
            'canvas' => $this->canvas?->toArray(),
        ] + $legacy + [
            'mccc' => $this->mccc,
            'options' => $this->options,
            'bccLevels' => $this->bccLevels,
            'bccLinkLevel' => $this->bccLinkLevel,
            'admission' => $this->admission,
        ];
    }
}
