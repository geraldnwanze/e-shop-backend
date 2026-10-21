<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('disk', 50)->nullable();
            $table->string('folder', 100)->default('uploads');
            $table->string('path', 500);
            $table->text('url');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'folder']);
            $table->unique(['provider', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
