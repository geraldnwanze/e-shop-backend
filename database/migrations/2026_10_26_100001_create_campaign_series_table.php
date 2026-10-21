<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_series', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_segment_id')->nullable()->constrained('customer_segments')->nullOnDelete();
            $table->string('segment_name', 80);
            $table->string('subject', 150);
            $table->text('body');
            $table->string('button_label', 40)->nullable();
            $table->string('button_url', 2048)->nullable();
            $table->string('frequency', 10);
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->string('send_time', 5);
            $table->date('ends_on')->nullable();
            $table->string('status', 10)->default('active');
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('runs_count')->default(0);
            $table->unsignedTinyInteger('consecutive_failures')->default(0);
            $table->string('pause_reason', 250)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'next_run_at']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->foreignUuid('campaign_series_id')->nullable()->after('customer_segment_id')->constrained('campaign_series')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_series_id');
        });

        Schema::dropIfExists('campaign_series');
    }
};
