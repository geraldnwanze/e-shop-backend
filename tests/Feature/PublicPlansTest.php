<?php

use App\Models\Plan;
use App\Services\Platform\PlatformSettings;

it('lists only active plans, in display order, without authentication', function () {
    Plan::factory()->create(['name' => 'Pro', 'slug' => 'pro', 'position' => 2]);
    Plan::factory()->create(['name' => 'Free', 'slug' => 'free', 'position' => 1, 'price' => 0]);
    Plan::factory()->create(['name' => 'Retired', 'slug' => 'retired', 'position' => 0, 'is_active' => false]);

    $response = http()->getJson('/api/v1/plans')->assertOk();

    expect(collect($response->json('data.plans'))->pluck('name')->all())->toBe(['Free', 'Pro'])
        ->and($response->json('data.plans.0'))->toHaveKeys(['id', 'name', 'slug', 'price', 'currency', 'interval', 'limits', 'features']);
});

it('never exposes subscriber counts or other tenants\' data', function () {
    Plan::factory()->create();

    $json = http()->getJson('/api/v1/plans')->assertOk()->json('data');

    expect($json['plans'][0])->not->toHaveKey('subscribersCount')
        ->and(array_keys($json))->toBe(['platform', 'plans']);
});

it('reflects whether registration is open and the platform identity', function () {
    http()->getJson('/api/v1/plans')->assertJsonPath('data.platform.registrationOpen', true);

    app(PlatformSettings::class)->update(['registration.enabled' => false, 'platform.name' => 'Acme Shops']);

    http()->getJson('/api/v1/plans')
        ->assertJsonPath('data.platform.registrationOpen', false)
        ->assertJsonPath('data.platform.name', 'Acme Shops');
});
