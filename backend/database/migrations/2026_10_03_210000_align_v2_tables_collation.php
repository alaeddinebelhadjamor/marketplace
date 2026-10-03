<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aligne la collation des tables créées par la v2 sur celle des tables
 * existantes (utf8mb4_0900_ai_ci, défaut de MySQL 8).
 *
 * Sans cela, une jointure entre orders (0900_ai_ci) et order_magento_dates
 * (unicode_ci, défaut de Laravel) échoue : « Illegal mix of collations ».
 * Seules les tables de la v2 sont modifiées ; les tables existantes ne le sont pas.
 */
return new class extends Migration
{
    private const V2_TABLES = [
        'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'personal_access_tokens', 'activity_log', 'seller_two_factor', 'order_magento_dates',
        'commission_rates', 'payout_statements', 'metric_samples', 'product_imports',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::V2_TABLES as $table) {
            if (Schema::hasTable($table)) {
                DB::statement("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
            }
        }
    }

    public function down(): void
    {
        // Pas de retour arrière : l'ancienne collation provoquait l'erreur corrigée ici.
    }
};
