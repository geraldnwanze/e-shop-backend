<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // The storefront list sorts newest-first by id (UUID v7 is time-ordered) or by price, within one store's active products.
            $table->index(['tenant_id', 'status', 'id'], 'products_store_status_id_index');
            $table->index(['tenant_id', 'status', 'price'], 'products_store_status_price_index');
        });

        Schema::table('carts', function (Blueprint $table) {
            // The reminder job scans open carts (not converted) by how long they have been idle, across all stores.
            $table->index(['converted_at', 'updated_at'], 'carts_open_idle_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_store_status_id_index');
            $table->dropIndex('products_store_status_price_index');
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->dropIndex('carts_open_idle_index');
        });
    }
};
