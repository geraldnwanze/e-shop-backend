<?php

namespace Database\Factories;

use App\Enums\MediaProviderType;
use App\Models\Media;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    public function definition(): array
    {
        $path = 'tenants/test/uploads/'.Str::uuid().'.jpg';

        return [
            'tenant_id' => Tenant::factory(),
            'provider' => MediaProviderType::Spaces,
            'disk' => 'spaces',
            'folder' => 'uploads',
            'path' => $path,
            'url' => 'https://cdn.example.test/'.$path,
            'mime_type' => 'image/jpeg',
            'size' => 12345,
            'width' => 800,
            'height' => 600,
            'alt' => fake()->sentence(3),
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->getKey()]);
    }
}
