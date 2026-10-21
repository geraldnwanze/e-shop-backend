<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['free', 'Free', 'Everything you need to start selling.', 0, ['products' => 25, 'custom_domains' => 0], ['Subdomain storefront', 'Email notifications']],
            ['starter', 'Starter', 'For growing stores.', 500000, ['products' => 500, 'custom_domains' => 1], ['Custom domain', 'Discount codes', 'Priority email support']],
            ['pro', 'Pro', 'For established brands.', 1500000, ['products' => null, 'custom_domains' => 5], ['Unlimited products', 'Multiple custom domains', 'Priority support']],
        ];

        foreach ($plans as $position => [$slug, $name, $description, $price, $limits, $features]) {
            Plan::query()->updateOrCreate(['slug' => $slug], [
                'name' => $name, 'description' => $description, 'price' => $price, 'currency' => 'NGN',
                'interval' => 'monthly', 'limits' => $limits, 'features' => $features, 'is_active' => true, 'position' => $position,
            ]);
        }
    }
}
