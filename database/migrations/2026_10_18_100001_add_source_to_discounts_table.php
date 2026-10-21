<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            $table->string('source', 20)->default('manual')->after('code');
            $table->index(['tenant_id', 'source']);
        });

        // Codes already issued by cart reminders are exactly the ones carts remember.
        $codes = DB::table('carts')->whereNotNull('recovery_code')->select('tenant_id', 'recovery_code')->get();

        foreach ($codes as $cart) {
            DB::table('discounts')->where('tenant_id', $cart->tenant_id)->where('code', $cart->recovery_code)->update(['source' => 'cart_recovery']);
        }
    }

    public function down(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'source']);
            $table->dropColumn('source');
        });
    }
};
