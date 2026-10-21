<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['email', 'subscribed_at'])]
class NewsletterSubscriber extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return ['subscribed_at' => 'datetime'];
    }
}
