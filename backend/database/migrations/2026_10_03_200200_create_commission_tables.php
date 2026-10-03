<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commissions et relevés de paiement des vendeurs.
 * Le taux de la v1 (5 %) reste la valeur par défaut ; un taux propre à un
 * vendeur peut le remplacer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rates', function (Blueprint $table) {
            $table->unsignedInteger('seller_id')->primary();
            $table->decimal('rate', 5, 4);
            $table->timestamps();
        });

        Schema::create('payout_statements', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('seller_id')->index();
            $table->char('period', 7); // AAAA-MM
            $table->unsignedInteger('orders_count');
            $table->unsignedInteger('items_qty');
            $table->decimal('gross', 12, 2);
            $table->decimal('commission_rate', 5, 4);
            $table->decimal('commission', 12, 2);
            $table->decimal('net', 12, 2);
            $table->string('status', 16)->default('pending'); // pending | paid
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['seller_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_statements');
        Schema::dropIfExists('commission_rates');
    }
};
