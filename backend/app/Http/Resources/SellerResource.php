<?php

namespace App\Http\Resources;

use App\Models\Seller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Représentation publique d'un vendeur. Le hash du mot de passe n'en sort
 * jamais, même pour l'administration.
 *
 * @mixin Seller
 */
class SellerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'seller_id' => $this->seller_id,
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'email' => $this->email,
            'shop_title' => $this->shop_title,
            'company' => $this->company,
            'contact_number' => $this->contact_number,
            'description' => $this->description,
            'address' => $this->address,
            'zipcode' => $this->zipcode,
            'governorate' => $this->governorate,
            'has_patent' => (int) $this->has_patent,
            'tax_id' => $this->tax_id,
            'logo' => $this->logo,
            'status' => (int) $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // whenLoaded() renverrait null quand la relation est chargée mais vide.
            'two_factor_enabled' => $this->resource->relationLoaded('twoFactor') && $this->twoFactor?->confirmed_at !== null,
        ];
    }
}
