<?php

namespace App\Models;

use App\Enums\PaymentProviderType;
use App\Enums\WebhookEventStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Deliberately NOT tenant-scoped: it is written by the webhook endpoint before a
 * tenant context exists. tenant_id is set explicitly from the (signature-verified) URL.
 */
class WebhookEvent extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['provider' => PaymentProviderType::class, 'status' => WebhookEventStatus::class, 'processed_at' => 'datetime'];
    }
}
