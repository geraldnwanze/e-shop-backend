<?php

namespace App\Models;

use App\Traits\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value'])]
class PlatformSetting extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
