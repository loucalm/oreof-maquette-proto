<?php

declare(strict_types=1);

namespace App\Enum;

/** Statut d'une demande de dérogation à la structure imposée par un template. */
enum DerogationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Approved => 'Approuvée',
            self::Rejected => 'Refusée',
        };
    }
}
