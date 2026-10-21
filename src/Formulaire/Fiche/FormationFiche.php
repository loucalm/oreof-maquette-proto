<?php

declare(strict_types=1);

namespace App\Formulaire\Fiche;

use App\Diplome\Spec\CanvasSpec;

/** Fiche de la formation : données dans `parametres[<entrée>]`, nom, type, domaine et composante sur l'entité. */
final class FormationFiche extends AbstractFicheTarget
{
    public function fiche(): string
    {
        return CanvasSpec::FORMATION;
    }

    public function data(string $entryKey): array
    {
        return $this->doc->formationParam($entryKey);
    }

    public function setData(string $entryKey, array $data): void
    {
        $this->doc->setFormationParam($entryKey, $data);
    }

    public function bound(): array
    {
        return [
            'name' => $this->formation->getName(),
            'diplome' => $this->formation->getDiplome(),
            'domaine' => $this->formation->getDomaine(),
            'composante' => $this->formation->getComposante(),
        ];
    }

    public function writeBound(array $input): void
    {
        if (null !== ($name = self::text($input['name'] ?? null))) {
            $this->formation->setName($name);
        }
        if (\array_key_exists('domaine', $input)) {
            $this->formation->setDomaine(self::text($input['domaine']));
        }
        if (\array_key_exists('composante', $input)) {
            $this->formation->setComposante(self::text($input['composante']));
        }
    }
}
