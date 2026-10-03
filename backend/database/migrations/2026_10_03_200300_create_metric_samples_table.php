<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Valeurs des compteurs et histogrammes Prometheus.
 *
 * Contrairement à Node.js, PHP ne garde pas d'état entre deux requêtes : les
 * compteurs sont donc cumulés en base (une ligne par série) puis lus par
 * GET /metrics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_samples', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('labels', 500)->default('{}');
            $table->double('value')->default(0);
            $table->timestamp('updated_at')->nullable();
            $table->unique(['name', 'labels']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_samples');
    }
};
