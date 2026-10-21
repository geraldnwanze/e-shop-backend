<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Set only on self-serve subscriptions; admin-assigned ones leave these empty and are never auto-managed.
            $table->unsignedSmallInteger('billing_period')->nullable()->after('plan_id');
            $table->boolean('auto_renew')->default(true)->after('billing_period');
            $table->text('authorization_code')->nullable()->after('auto_renew'); // encrypted reusable card token
            $table->string('card_brand', 30)->nullable()->after('authorization_code');
            $table->string('card_last4', 4)->nullable()->after('card_brand');
            $table->string('payer_email')->nullable()->after('card_last4');
            $table->unsignedSmallInteger('failed_attempts')->default(0)->after('payer_email');
            $table->timestamp('next_attempt_at')->nullable()->after('failed_attempts');
            $table->timestamp('reminder_sent_at')->nullable()->after('next_attempt_at');

            $table->index(['status', 'current_period_ends_at']);
        });

        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->uuid('id');
            $table->primary('id');
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('plan_id')->constrained()->restrictOnDelete();
            $table->string('reference')->unique();
            $table->string('purpose', 20); // subscribe | renewal
            $table->unsignedSmallInteger('period_months');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->string('status', 20)->default('pending');
            $table->string('provider_reference')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('period_starts_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['subscription_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['status', 'current_period_ends_at']);
            $table->dropColumn(['billing_period', 'auto_renew', 'authorization_code', 'card_brand', 'card_last4', 'payer_email', 'failed_attempts', 'next_attempt_at', 'reminder_sent_at']);
        });
    }
};
