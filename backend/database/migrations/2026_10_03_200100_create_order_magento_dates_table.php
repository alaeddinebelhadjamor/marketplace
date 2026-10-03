<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Date réelle de chaque commande Magento.
 *
 * La table existante orders ne garde que la date de synchronisation
 * (processed_at), limite citée au chapitre 4 du rapport. Cette table annexe
 * conserve la date de création de la commande dans Magento sans modifier orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_magento_dates', function (Blueprint $table) {
            $table->string('order_id')->primary();
            $table->dateTime('ordered_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_magento_dates');
    }
};
