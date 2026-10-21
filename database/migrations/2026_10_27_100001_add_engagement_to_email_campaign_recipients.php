<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_campaign_recipients', function (Blueprint $table) {
            $table->unsignedInteger('open_count')->default(0)->after('sent_at');
            $table->timestamp('first_opened_at')->nullable()->after('open_count');
            $table->timestamp('last_opened_at')->nullable()->after('first_opened_at');
            $table->unsignedInteger('click_count')->default(0)->after('last_opened_at');
            $table->timestamp('first_clicked_at')->nullable()->after('click_count');
            $table->timestamp('last_clicked_at')->nullable()->after('first_clicked_at');
        });
    }

    public function down(): void
    {
        Schema::table('email_campaign_recipients', function (Blueprint $table) {
            $table->dropColumn(['open_count', 'first_opened_at', 'last_opened_at', 'click_count', 'first_clicked_at', 'last_clicked_at']);
        });
    }
};
