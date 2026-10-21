<?php

namespace App\Models;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Support\Tenancy\DomainNormalizer;
use App\Traits\Audited;
use App\Traits\BelongsToTenant;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['domain', 'type', 'status', 'is_primary', 'is_verified', 'verification_token', 'verified_at', 'last_checked_at', 'failure_reason'])]
class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use Audited, BelongsToTenant, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'type' => DomainType::class,
            'status' => DomainStatus::class,
            'is_primary' => 'boolean',
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    /**
     * Domains are always persisted normalised.
     */
    protected function domain(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => DomainNormalizer::normalize($value));
    }
}
