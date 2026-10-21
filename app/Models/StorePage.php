<?php

namespace App\Models;

use App\Traits\Audited;
use App\Traits\BelongsToTenant;
use Database\Factories\StorePageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An information page of a store (About, Contact, Shipping & returns, Privacy, Terms...). The body is
 * plain text with blank lines between paragraphs, so a page can never carry markup or scripts.
 */
#[Fillable(['title', 'slug', 'body', 'is_published', 'show_in_footer', 'position'])]
class StorePage extends Model
{
    /** @use HasFactory<StorePageFactory> */
    use Audited, BelongsToTenant, HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'show_in_footer' => 'boolean', 'position' => 'integer'];
    }
}
