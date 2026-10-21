<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Shoppers' emails are only unique within one store, so reset links are kept per store (the framework's
        // shared token table is keyed by email alone and would mix up two stores' accounts).
        Schema::create('customer_password_resets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('token_hash', 64);
            $table->timestamp('created_at');

            $table->unique(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_password_resets');
    }
};
