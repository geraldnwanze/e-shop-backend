<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('cancelled_at');
            $table->timestamp('review_requested_at')->nullable()->after('delivered_at');
            $table->index(['tenant_id', 'delivered_at']);
        });

        // Orders that were already delivered before this existed must not trigger a burst of old requests.
        DB::table('orders')->whereIn('status', ['DELIVERED', 'COMPLETED'])->where('channel', 'online')
            ->update(['delivered_at' => DB::raw('updated_at'), 'review_requested_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'delivered_at']);
            $table->dropColumn(['delivered_at', 'review_requested_at']);
        });
    }
};
