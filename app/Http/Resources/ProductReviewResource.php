<?php

namespace App\Http\Resources;

use App\Models\ProductReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Merchant view of a review, with who wrote it and which product it is about.
 *
 * @mixin ProductReview
 */
class ProductReviewResource extends JsonResource
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
            'customerEmail' => $this->whenLoaded('customer', fn () => $this->customer?->email),
            'product' => $this->whenLoaded('product', fn () => $this->product ? ['id' => $this->product->id, 'name' => $this->product->name, 'slug' => $this->product->slug] : null),
            'status' => $this->status,
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->filter(fn ($p) => $p->media !== null)->map(fn ($p) => ['id' => $p->id, 'url' => $p->media->url])->values()),
            'reply' => $this->reply,
            'repliedAt' => $this->replied_at,
            'createdAt' => $this->created_at,
        ];
    }
}
