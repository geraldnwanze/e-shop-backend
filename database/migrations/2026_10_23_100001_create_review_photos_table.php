<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_photos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_review_id')->nullable()->constrained('product_reviews')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();

            $table->index(['product_review_id', 'position']);
            $table->index(['customer_id', 'product_review_id']);
            $table->index(['product_review_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_photos');
    }
};
