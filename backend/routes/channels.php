<?php

use App\Models\Seller;
use Illuminate\Support\Facades\Broadcast;

// Canal privé d'un vendeur : seul ce vendeur peut s'y abonner.
Broadcast::channel('seller.{sellerId}', function (Seller $seller, int $sellerId) {
    return (int) $seller->seller_id === $sellerId;
});
