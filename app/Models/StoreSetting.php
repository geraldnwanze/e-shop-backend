<?php

namespace App\Models;

use App\Traits\Audited;
use App\Traits\BelongsToTenant;
use Database\Factories\StoreSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['settings'])]
class StoreSetting extends Model
{
    /** @use HasFactory<StoreSettingFactory> */
    use Audited, BelongsToTenant, HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
