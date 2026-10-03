<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Double authentification des vendeurs.
 * Table séparée : la table existante marketplace_seller n'est pas modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_two_factor', function (Blueprint $table) {
            $table->unsignedInteger('seller_id')->primary();
            $table->text('secret');              // chiffré par l'application
            $table->text('recovery_codes');      // chiffré par l'application
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_two_factor');
    }
};
