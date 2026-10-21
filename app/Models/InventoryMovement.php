<?php

namespace App\Models;

use App\Enums\InventoryReason;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['reason' => InventoryReason::class, 'delta' => 'integer', 'stock_after' => 'integer'];
    }
}
