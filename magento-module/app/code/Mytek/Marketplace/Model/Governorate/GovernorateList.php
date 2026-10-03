<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Model\Governorate;

/**
 * Les 24 gouvernorats de Tunisie, source unique utilisée par le formulaire vendeur (admin) et
 * par l'attribut produit filtrable seller_governorate (amélioration B).
 */
class GovernorateList
{
    public const ALL = [
        'Ariana', 'Béja', 'Ben Arous', 'Bizerte', 'Gabès', 'Gafsa', 'Jendouba', 'Kairouan',
        'Kasserine', 'Kébili', 'Le Kef', 'Mahdia', 'La Manouba', 'Médenine', 'Monastir', 'Nabeul',
        'Sfax', 'Sidi Bouzid', 'Siliana', 'Sousse', 'Tataouine', 'Tozeur', 'Tunis', 'Zaghouan',
    ];
}
