<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Le nom de boutique sert d'identifiant de connexion : son unicité, jusqu'ici
| contrôlée par l'application seule (409), est garantie par un index unique.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_seller', function (Blueprint $table) {
            $table->unique('shop_title', 'marketplace_seller_shop_title_unique');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_seller', function (Blueprint $table) {
            $table->dropUnique('marketplace_seller_shop_title_unique');
        });
    }
};
