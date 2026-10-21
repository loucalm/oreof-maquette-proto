<?php

declare(strict_types=1);

namespace App\Formulaire;

/** Lien de la barre latérale d'un éditeur, issu d'une entrée visible du canevas (cf. CanvasResolver::navigation()). */
final readonly class NavItem
{
    public function __construct(
        public string $fiche,
        public string $key,
        public string $label,
        public int $number,
        public string $url,
        public ?string $status,
        public ?string $group,
        public bool $builtin,
        public bool $current = false,
    ) {
    }
}
