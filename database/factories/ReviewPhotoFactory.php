<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Media;
use App\Models\ProductReview;
use App\Models\ReviewPhoto;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewPhoto>
 */
class ReviewPhotoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'product_review_id' => ProductReview::factory(),
            'customer_id' => Customer::factory(),
            'media_id' => Media::factory(),
            'position' => 0,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->getKey()]);
    }
}
