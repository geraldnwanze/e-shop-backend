<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('countries'); // ISO 3166-1 alpha-2 codes, or ["*"] for everywhere else
            $table->json('states')->nullable(); // Nigerian states; null/empty = every state in the zone's countries
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'position']);
        });

        Schema::create('shipping_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('shipping_zone_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('description', 160)->nullable();
            $table->unsignedBigInteger('price')->default(0); // minor units
            $table->unsignedBigInteger('free_over')->nullable(); // free when the cart subtotal reaches this (minor units)
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['shipping_zone_id', 'is_active', 'position']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipping_method', 80)->nullable()->after('shipping_total');
            $table->foreignUuid('shipping_rate_id')->nullable()->after('shipping_method')->constrained('shipping_rates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipping_rate_id');
            $table->dropColumn('shipping_method');
        });

        Schema::dropIfExists('shipping_rates');
        Schema::dropIfExists('shipping_zones');
    }
};
