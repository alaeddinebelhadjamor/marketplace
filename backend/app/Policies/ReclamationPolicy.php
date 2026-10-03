<?php

namespace App\Policies;

use App\Models\Reclamation;
use App\Models\Seller;
use Illuminate\Auth\Access\Response;

/**
 * Une réclamation n'est accessible qu'au vendeur qui l'a ouverte. Pour un
 * autre vendeur, elle « n'existe pas » (404, comme la v1) : on ne révèle pas
 * l'existence des échanges des autres vendeurs.
 */
class ReclamationPolicy
{
    public function view(Seller $seller, Reclamation $reclamation): Response
    {
        return $this->owner($seller, $reclamation);
    }

    public function reply(Seller $seller, Reclamation $reclamation): Response
    {
        return $this->owner($seller, $reclamation);
    }

    public function resolve(Seller $seller, Reclamation $reclamation): Response
    {
        return $this->owner($seller, $reclamation);
    }

    private function owner(Seller $seller, Reclamation $reclamation): Response
    {
        return (int) $reclamation->vendeur_id === (int) $seller->seller_id
            ? Response::allow()
            : Response::denyAsNotFound('Reclamation not found');
    }
}
