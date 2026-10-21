<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Plain ids with name snapshots (no foreign keys): the trail must outlive the stores, users and records it describes.
            $table->uuid('tenant_id')->nullable();
            $table->string('tenant_label', 190)->nullable();
            $table->string('actor_type', 20);
            $table->uuid('actor_id')->nullable();
            $table->string('actor_label', 190)->nullable();
            $table->string('action', 80);
            $table->string('subject_type', 40)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('subject_label', 190)->nullable();
            $table->json('changes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['tenant_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
