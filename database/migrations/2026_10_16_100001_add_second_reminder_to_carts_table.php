<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->timestamp('second_reminder_sent_at')->nullable()->after('reminder_sent_at');
            $table->string('recovery_code', 40)->nullable()->after('second_reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn(['second_reminder_sent_at', 'recovery_code']);
        });
    }
};
