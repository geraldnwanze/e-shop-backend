<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // When the daily review summary last looked at this store; the next summary covers everything after it.
            $table->timestamp('review_digest_checked_at')->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('review_digest_checked_at');
        });
    }
};
