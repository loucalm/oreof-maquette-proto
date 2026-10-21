<?php

declare(strict_types=1);

namespace App\Formulaire\Fiche;

use App\Diplome\Spec\CanvasSpec;

/**
 * Fiche parcours d'une formation sans parcours (parcours unique) : données rangées sur la
 * formation sous `parametres['parcours.<entrée>']`, ECTS total porté par la formation ; le nom
 * du parcours reste un champ de données.
 */
final class MonoParcoursFiche extends AbstractFicheTarget
{
    public const PREFIX = 'parcours.';

    public function fiche(): string
    {
        return CanvasSpec::PARCOURS;
    }

    public function data(string $entryKey): array
    {
        return $this->doc->formationParam(self::PREFIX.$entryKey);
    }

    public function setData(string $entryKey, array $data): void
    {
        $this->doc->setFormationParam(self::PREFIX.$entryKey, $data);
    }

    public function bound(): array
    {
        return ['ectsTotal' => $this->formation->getEctsTotal()];
    }

    public function writeBound(array $input): void
    {
        if (\array_key_exists('ectsTotal', $input)) {
            $this->formation->setEctsTotal(self::int($input['ectsTotal']));
        }
    }
}
