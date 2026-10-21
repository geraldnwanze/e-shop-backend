<?php

namespace App\Http\Resources;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Store
 */
class StoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'logo' => $this->logo,
            'favicon' => $this->favicon,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'status' => $this->status,
            'theme' => ThemeResource::make($this->whenLoaded('theme')),
            'tenant' => $this->whenLoaded('tenant', fn () => ['id' => $this->tenant->id, 'name' => $this->tenant->name, 'slug' => $this->tenant->slug, 'status' => $this->tenant->status]),
            'url' => config('tenancy.storefront_scheme').'://'.$this->slug.'.'.config('tenancy.platform_domain'),
        ];
    }
}
