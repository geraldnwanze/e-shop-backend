<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ThemeSeeder;
use Database\Seeders\ThemeShowcaseSeeder;

it('creates one dressed demo store per theme, with a merchant login, and can be run again safely', function () {
    $this->seed(ThemeSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->seed(ThemeShowcaseSeeder::class);
    $this->seed(ThemeShowcaseSeeder::class);

    $slugs = ['minimal', 'fashion', 'modern', 'electronics', 'beauty', 'restaurant'];

    expect(Tenant::query()->whereIn('slug', $slugs)->count())->toBe(6);

    foreach ($slugs as $slug) {
        $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();
        $store = Store::withoutTenantScope()->where('tenant_id', $tenant->getKey())->with('theme')->firstOrFail();

        expect($store->theme->slug)->toBe($slug)
            ->and(Product::withoutTenantScope()->where('tenant_id', $tenant->getKey())->count())->toBe(8)
            ->and(User::query()->where('email', "merchant@{$slug}.test")->exists())->toBeTrue();
    }
});
