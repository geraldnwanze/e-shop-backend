<?php

namespace App\Http\Resources;

use App\Models\StorePage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StorePage
 */
class StorePageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'body' => $this->body,
            'isPublished' => $this->is_published,
            'showInFooter' => $this->show_in_footer,
            'position' => $this->position,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
