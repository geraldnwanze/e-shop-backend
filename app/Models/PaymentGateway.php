<?php

namespace App\Models;

use App\Enums\PaymentProviderType;
use App\Traits\Audited;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A store's own credentials for a payment provider. Secrets are encrypted at
 * rest and hidden from every serialisation.
 */
#[Fillable(['secret_key', 'public_key', 'webhook_secret', 'is_active', 'is_default'])]
#[Hidden(['secret_key', 'webhook_secret'])]
class PaymentGateway extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return [
            'provider' => PaymentProviderType::class,
            'secret_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }
}
