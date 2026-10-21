<?php

namespace App\Http\Resources;

use App\Models\CustomerSegment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerSegment
 */
class CustomerSegmentResource extends JsonResource
{
    /**
     * @param  array<string, int>  $counts  Segment id => live member count, when the caller has them
     */
    public function __construct($resource, private readonly array $counts = [])
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'rules' => $this->rules,
            'customersCount' => $this->counts[$this->id] ?? null,
            'createdAt' => $this->created_at,
        ];
    }
}
