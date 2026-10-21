<?php

declare(strict_types=1);

namespace App\Formulaire\Fiche;

use App\Entity\Formation;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;

/**
 * Objet dont on remplit une fiche (la formation, un parcours, ou le parcours unique d'une
 * formation sans parcours) : où sont rangées les données de chaque entrée, et quels champs
 * verrouillés écrivent directement sur l'entité (champs « liés », rendus par
 * `formation/param/bound/<fiche>/<clé>.html.twig` et postés sous `b[<clé>]`).
 */
interface FicheTarget
{
    /** CanvasSpec::FORMATION ou CanvasSpec::PARCOURS. */
    public function fiche(): string;

    public function formation(): Formation;

    /** Nœud parcours, ou null pour la formation et pour le parcours unique. */
    public function parcours(): ?TreeNode;

    public function doc(): MaquetteDoc;

    /** @return array<string, mixed> données saisies dans l'entrée */
    public function data(string $entryKey): array;

    /** @param array<string, mixed> $data */
    public function setData(string $entryKey, array $data): void;

    /** @return array<string, mixed> champs liés à l'entité : clé de champ → valeur courante */
    public function bound(): array;

    /**
     * Valeur d'un champ : liée à l'entité si bound() la déclare, sinon lue dans les données de l'entrée.
     *
     * @param array<string, mixed> $data
     */
    public function value(string $fieldKey, array $data): mixed;

    /** @param array<string, mixed> $input valeurs postées sous `b[]` ; seules les clés présentes sont écrites */
    public function writeBound(array $input): void;
}
