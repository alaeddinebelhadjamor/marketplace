<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Produits Magento déjà consultés par l'intégration (table existante produits_consultes). */
class ProduitConsulte extends Model
{
    protected $table = 'produits_consultes';

    protected $primaryKey = 'id_produit';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['id_produit'];
}
