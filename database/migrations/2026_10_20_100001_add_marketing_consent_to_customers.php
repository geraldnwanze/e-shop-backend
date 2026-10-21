<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('accepts_marketing')->default(false)->after('phone');
            $table->timestamp('marketing_opted_in_at')->nullable()->after('accepts_marketing');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['accepts_marketing', 'marketing_opted_in_at']);
        });
    }
};
