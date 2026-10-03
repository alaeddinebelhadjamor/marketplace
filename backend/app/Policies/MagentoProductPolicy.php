<?php

namespace App\Policies;

use App\Models\Seller;
use App\Services\Magento\MagentoProductService;
use Illuminate\Auth\Access\Response;

/**
 * Propriété d'un produit Magento : un vendeur n'agit que sur les produits
 * dont l'attribut seller_id est le sien (403 sinon, comme la v1).
 */
class MagentoProductPolicy
{
    public function update(Seller $seller, array $product): Response
    {
        return $this->owns($seller, $product)
            ? Response::allow()
            : Response::deny('Non autorisé à modifier ce produit');
    }

    public function delete(Seller $seller, array $product): Response
    {
        return $this->owns($seller, $product)
            ? Response::allow()
            : Response::deny("Vous n'êtes pas autorisé");
    }

    public function view(Seller $seller, array $product): Response
    {
        return $this->owns($seller, $product)
            ? Response::allow()
            : Response::denyAsNotFound('Produit introuvable');
    }

    private function owns(Seller $seller, array $product): bool
    {
        return MagentoProductService::sellerIdOf($product) === (int) $seller->seller_id;
    }
}
