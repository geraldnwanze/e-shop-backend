<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            // Unlike reminder_sent_at, these are never reset, so a sale can still be traced to the reminder behind it.
            $table->timestamp('last_reminder_at')->nullable()->after('second_reminder_sent_at');
            $table->string('last_reminder_stage', 10)->nullable()->after('last_reminder_at');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignUuid('cart_id')->nullable()->after('customer_id')->constrained('carts')->nullOnDelete();
        });

        DB::table('carts')->whereNotNull('second_reminder_sent_at')->update(['last_reminder_at' => DB::raw('second_reminder_sent_at'), 'last_reminder_stage' => 'second']);
        DB::table('carts')->whereNull('second_reminder_sent_at')->whereNotNull('reminder_sent_at')->update(['last_reminder_at' => DB::raw('reminder_sent_at'), 'last_reminder_stage' => 'first']);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cart_id');
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn(['last_reminder_at', 'last_reminder_stage']);
        });
    }
};
