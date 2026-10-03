<?php

namespace App\Enums;

/** Statut d'un vendeur (marketplace_seller.status). */
enum SellerStatus: int
{
    case Pending = 0;
    case Validated = 1;
    case Refused = 2;

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Validated => 'Validé',
            self::Refused => 'Refusé',
        };
    }
}
