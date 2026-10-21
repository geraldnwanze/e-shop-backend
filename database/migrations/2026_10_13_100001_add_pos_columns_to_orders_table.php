<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('channel', 10)->default('online')->after('order_number');
            $table->string('payment_method', 20)->nullable()->after('payment_status');
            $table->unsignedBigInteger('amount_tendered')->nullable()->after('total');
            $table->foreignUuid('cashier_id')->nullable()->after('customer_id')->constrained('users')->nullOnDelete();

            $table->index(['tenant_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'channel']);
            $table->dropConstrainedForeignId('cashier_id');
            $table->dropColumn(['channel', 'payment_method', 'amount_tendered']);
        });
    }
};
