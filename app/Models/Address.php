<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['label', 'first_name', 'last_name', 'line1', 'line2', 'city', 'state', 'postal_code', 'country', 'phone', 'is_default'])]
class Address extends Model
{
    /** @use HasFactory<AddressFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }
}
