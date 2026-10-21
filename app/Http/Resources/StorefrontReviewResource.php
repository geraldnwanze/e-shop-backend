<?php

namespace App\Http\Resources;

use App\Models\ProductReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a shopper sees: no email, no customer id.
 *
 * @mixin ProductReview
 */
class StorefrontReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'title' => $this->title,
            'body' => $this->body,
            'authorName' => $this->author_name,
            'status' => $this->status,
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->filter(fn ($p) => $p->media !== null)->map(fn ($p) => ['id' => $p->id, 'url' => $p->media->url, 'width' => $p->media->width, 'height' => $p->media->height])->values()),
            'reply' => $this->reply,
            'repliedAt' => $this->replied_at,
            'createdAt' => $this->created_at,
        ];
    }
}
