<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('domain')->unique();
            $table->string('type', 20);
            $table->string('status', 20)->default('pending');
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->string('verification_token', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_primary']);
            $table->index(['domain', 'is_verified']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
