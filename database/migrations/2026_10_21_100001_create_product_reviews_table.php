<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('order_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 120)->nullable();
            $table->text('body')->nullable();
            $table->string('author_name', 80);
            $table->string('status', 10)->default('published');
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'customer_id']);
            $table->index(['product_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('rating_average', 3, 2)->default(0)->after('stock');
            $table->unsignedInteger('rating_count')->default(0)->after('rating_average');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['rating_average', 'rating_count']);
        });

        Schema::dropIfExists('product_reviews');
    }
};
