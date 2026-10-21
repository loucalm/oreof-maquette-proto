<?php

declare(strict_types=1);

namespace App\Formulaire;

use App\Diplome\Spec\CanvasEntry;
use App\Diplome\Spec\CanvasSpec;
use App\Enum\FormScope;
use App\Formulaire\Builtin\BuiltinScreenRegistry;
use App\Repository\NodeTypeRepository;

/**
 * Canevas par défaut, construit depuis les données : les formulaires de la bibliothèque
 * qui déclarent une entrée par défaut (NodeType::getDefaultEntryKey(), à son rang
 * getDefaultEntryPosition()) et les écrans intégrés (à leur defaultPosition()). À rang
 * égal, les formulaires passent avant les écrans intégrés.
 */
final class CanvasDefaults
{
    public function __construct(
        private readonly NodeTypeRepository $types,
        private readonly BuiltinScreenRegistry $builtins,
    ) {
    }

    public function canvas(): CanvasSpec
    {
        $spec = new CanvasSpec();
        foreach (CanvasSpec::FICHES as $fiche) {
            $rows = [];
            foreach ($this->types->findAllOrdered() as $type) {
                if (null !== $type->getDefaultEntryKey() && $type->getScope() === FormScope::from($fiche)) {
                    $rows[] = [$type->getDefaultEntryPosition() ?? 0, 0, $type->getDefaultEntryKey(), new CanvasEntry($type->getDefaultEntryKey(), $type->getKey(), null, null, false)];
                }
            }
            foreach ($this->builtins->forFiche($fiche) as $screen) {
                $rows[] = [$screen->defaultPosition(), 1, $screen->key(), new CanvasEntry($screen->key(), null, $screen->key(), null, false)];
            }
            usort($rows, static fn (array $a, array $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
            $spec = $spec->withEntries($fiche, array_map(static fn (array $row) => $row[3], $rows));
        }

        return $spec;
    }
}
