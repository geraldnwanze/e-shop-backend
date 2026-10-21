<?php

namespace App\Models;

use App\Enums\MediaProviderType;
use App\Traits\BelongsToTenant;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['provider', 'disk', 'folder', 'path', 'url', 'mime_type', 'size', 'width', 'height', 'alt'])]
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'media';

    protected function casts(): array
    {
        return [
            'provider' => MediaProviderType::class,
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }
}
