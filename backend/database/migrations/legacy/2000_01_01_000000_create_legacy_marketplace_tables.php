<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Copie du schéma des tables EXISTANTES de la base marketplace
 * (mk_database_prod_restored, relevé le 3 octobre 2026).
 *
 * Cette migration n'est chargée qu'en environnement de test (voir
 * AppServiceProvider) pour construire la base SQLite en mémoire.
 * Elle n'est jamais exécutée sur la base réelle, dont ces tables existent déjà.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_seller', function (Blueprint $table) {
            $table->increments('seller_id');
            $table->string('firstname');
            $table->string('lastname');
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('shop_title');
            $table->string('company')->nullable();
            $table->string('contact_number');
            $table->text('description')->nullable();
            $table->string('address')->nullable();
            $table->string('zipcode', 20)->nullable();
            $table->string('governorate')->nullable();
            $table->smallInteger('has_patent')->default(0);
            $table->string('tax_id')->nullable();
            $table->string('logo')->nullable();
            $table->smallInteger('status')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->string('order_id');
            $table->string('sku');
            $table->string('product_name');
            $table->integer('qty');
            $table->decimal('price', 10, 2);
            $table->integer('vendor_id');
            $table->dateTime('processed_at')->nullable();
            $table->primary(['order_id', 'sku']);
        });

        Schema::create('produits_consultes', function (Blueprint $table) {
            $table->integer('id_produit')->primary();
        });

        Schema::create('reclamations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('vendeur_id');
            $table->tinyInteger('type');
            $table->tinyInteger('vendeur_viewed')->nullable()->default(0);
            $table->tinyInteger('admin_viewed')->nullable()->default(0);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->foreign('vendeur_id')->references('seller_id')->on('marketplace_seller')->cascadeOnDelete();
        });

        Schema::create('reclamation_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('reclamation_id');
            $table->tinyInteger('sender');
            $table->text('message')->nullable();
            $table->dateTime('created_at');
            $table->foreign('reclamation_id')->references('id')->on('reclamations')->cascadeOnDelete();
        });

        Schema::create('reclamation_attachments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('message_id');
            $table->string('file_path', 500);
            $table->string('file_type', 50)->nullable();
            $table->dateTime('created_at');
            $table->foreign('message_id')->references('id')->on('reclamation_messages')->cascadeOnDelete();
        });

        Schema::create('user_product_behavior', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('anonymous_id', 64)->nullable();
            $table->string('session_id', 128)->nullable()->index();
            $table->string('product_sku')->nullable();
            $table->enum('event_type', ['product_view', 'category_view', 'search', 'add_to_cart']);
            $table->unsignedInteger('category_id')->nullable();
            $table->string('search_query')->nullable();
            $table->string('source', 500)->nullable();
            $table->string('device_type', 20)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->index(['event_type', 'created_at']);
            $table->index(['product_sku', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['user_product_behavior', 'reclamation_attachments', 'reclamation_messages',
            'reclamations', 'produits_consultes', 'orders', 'marketplace_seller'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
