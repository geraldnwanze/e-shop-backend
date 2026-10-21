<?php

namespace App\Models;

use App\Enums\RefundStatus;
use App\Traits\Audited;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['status' => RefundStatus::class, 'amount' => 'integer'];
    }
}
