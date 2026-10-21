<?php

use App\Enums\UserRole;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use App\Support\Shipping\Locations;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->product = Product::factory()->forTenant($this->a['tenant'])->create(['price' => 1000000, 'stock' => 50]); // NGN 10,000
});

/**
 * @param  list<array<string, mixed>>  $rates
 */
function zone(array $rates = [], array $attributes = [], ?array $tenant = null): ShippingZone
{
    $zone = ShippingZone::factory()->forTenant(($tenant ?? test()->a)['tenant'])->create($attributes);
    $created = [];

    foreach ($rates as $i => $rate) {
        $created[] = ShippingRate::factory()->forZone($zone)->create($rate + ['position' => $i]);
    }

    return $zone->setRelation('rates', collect($created));
}

function checkoutTo(string $state, array $overrides = [], int $quantity = 1, string $country = 'NG', string $key = 'idem-key-00000001')
{
    $cart = makeCart(test()->a['tenant'], [[test()->product, $quantity]]);

    return checkout(test()->a['tenant'], $cart, array_replace_recursive(['shipping_address' => ['state' => $state, 'country' => $country]], $overrides), key: $key);
}

function quote(string $state, int $quantity = 1, string $country = 'NG')
{
    $cart = makeCart(test()->a['tenant'], [[test()->product, $quantity]]);

    return http()->postJson('/api/v1/storefront/shipping/options', ['cart_id' => $cart->id, 'country' => $country, 'state' => $state], storefrontHeaders(test()->a['tenant']));
}

describe('locations', function () {
    it('understands the ways people write Nigerian states', function (?string $typed, ?string $canonical) {
        expect(Locations::canonicalState($typed))->toBe($canonical);
    })->with([
        ['Lagos', 'Lagos'], ['lagos state', 'Lagos'], ['  LAGOS  ', 'Lagos'], ['Abuja', 'FCT'], ['F.C.T', 'FCT'], ['Federal Capital Territory', 'FCT'],
        ['akwa-ibom', 'Akwa Ibom'], ['Cross River', 'Cross River'], ['Nassarawa', 'Nasarawa'], ['Gotham', null], ['', null], [null, null],
    ]);

    it('lists 36 states plus the FCT', function () {
        expect(Locations::NIGERIAN_STATES)->toHaveCount(37)->and(Locations::NIGERIAN_STATES)->toContain('Lagos', 'FCT', 'Zamfara');
    });
});

describe('managing zones', function () {
    beforeEach(fn () => $this->actingAs($this->a['owner'], 'sanctum'));

    $payload = fn (array $overrides = []) => array_replace([
        'name' => 'Lagos', 'countries' => ['NG'], 'states' => ['Lagos'],
        'rates' => [['name' => 'Same-day', 'description' => 'Order before 12pm', 'price' => 300000, 'free_over' => 5000000], ['name' => 'Standard', 'price' => 150000]],
    ], $overrides);

    it('creates a zone with its delivery methods and lists it back in order', function () use ($payload) {
        $this->postJson('/api/v1/shipping-zones', $payload())->assertCreated()
            ->assertJsonPath('data.name', 'Lagos')->assertJsonPath('data.states', ['Lagos'])->assertJsonPath('data.isActive', true)
            ->assertJsonPath('data.rates.0.name', 'Same-day')->assertJsonPath('data.rates.0.freeOver', 5000000)->assertJsonPath('data.rates.1.price', 150000);
        $this->postJson('/api/v1/shipping-zones', $payload(['name' => 'Rest of Nigeria', 'states' => []]))->assertCreated()->assertJsonPath('data.states', []);

        $this->getJson('/api/v1/shipping-zones')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.name', 'Lagos')->assertJsonPath('data.1.name', 'Rest of Nigeria');
        expect(ShippingZone::withoutTenantScope()->where('name', 'Lagos')->first()->tenant_id)->toBe($this->a['tenant']->id);
    });

    it('rejects bad zones and rates', function (array $overrides, string $field) use ($payload) {
        $this->postJson('/api/v1/shipping-zones', $payload($overrides))->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => [$field]]]);
    })->with([
        'no name' => [['name' => ''], 'name'],
        'no countries' => [['countries' => []], 'countries'],
        'unknown country' => [['countries' => ['XX']], 'countries.0'],
        'everywhere mixed with a country' => [['countries' => ['NG', '*'], 'states' => []], 'countries'],
        'states on a non-Nigerian zone' => [['countries' => ['GH'], 'states' => ['Lagos']], 'states'],
        'unknown state' => [['states' => ['Gotham']], 'states.0'],
        'no rates' => [['rates' => []], 'rates'],
        'unnamed rate' => [['rates' => [['name' => '', 'price' => 100]]], 'rates.0.name'],
        'negative price' => [['rates' => [['name' => 'x', 'price' => -1]]], 'rates.0.price'],
        'zero free-over threshold' => [['rates' => [['name' => 'x', 'price' => 100, 'free_over' => 0]]], 'rates.0.free_over'],
        'fractional price' => [['rates' => [['name' => 'x', 'price' => 10.5]]], 'rates.0.price'],
    ]);

    it('accepts an "everywhere else" zone and a free method', function () use ($payload) {
        $this->postJson('/api/v1/shipping-zones', $payload(['name' => 'International', 'countries' => ['*'], 'states' => [], 'rates' => [['name' => 'Courier', 'price' => 0]]]))
            ->assertCreated()->assertJsonPath('data.countries', ['*'])->assertJsonPath('data.rates.0.price', 0);
    });

    it('keeps the ids of methods that are sent back, drops the rest, and ignores ids from other stores', function () use ($payload) {
        $id = $this->postJson('/api/v1/shipping-zones', $payload())->json('data.id');
        $created = ShippingRate::withoutTenantScope()->where('shipping_zone_id', $id)->orderBy('position')->get();
        $kept = $created[0]->id;
        $theirs = zone([['name' => 'Theirs']], tenant: $this->b)->rates[0];

        $this->putJson("/api/v1/shipping-zones/{$id}", $payload(['name' => 'Greater Lagos', 'rates' => [
            ['id' => $kept, 'name' => 'Same-day (renamed)', 'price' => 400000],
            ['id' => $theirs->id, 'name' => 'Sneaky', 'price' => 1],
        ]]))->assertOk()->assertJsonPath('data.name', 'Greater Lagos')->assertJsonCount(2, 'data.rates')
            ->assertJsonPath('data.rates.0.id', $kept)->assertJsonPath('data.rates.0.name', 'Same-day (renamed)');

        $rates = ShippingRate::withoutTenantScope()->where('shipping_zone_id', $id)->get();
        expect($rates->pluck('id'))->not->toContain($created[1]->id)->and($rates->pluck('id'))->not->toContain($theirs->id)
            ->and($theirs->fresh()->name)->toBe('Theirs')->and(ShippingRate::withoutTenantScope()->whereKey($created[1]->id)->exists())->toBeFalse();
    });

    it('deletes a zone with its methods but leaves past orders readable', function () use ($payload) {
        $id = $this->postJson('/api/v1/shipping-zones', $payload(['states' => []]))->json('data.id');
        $rateId = ShippingRate::withoutTenantScope()->where('shipping_zone_id', $id)->orderBy('position')->value('id');
        $order = checkoutTo('Lagos', ['shipping_rate_id' => $rateId])->assertCreated()->json('data');

        $this->actingAs($this->a['owner'], 'sanctum')->deleteJson("/api/v1/shipping-zones/{$id}")->assertOk();

        expect(ShippingRate::withoutTenantScope()->count())->toBe(0);
        $row = Order::withoutTenantScope()->find($order['id']);
        expect($row->shipping_method)->toBe('Same-day')->and($row->shipping_rate_id)->toBeNull()->and($row->shipping_total)->toBe(300000);
    });

    it('caps the number of zones', function () use ($payload) {
        ShippingZone::factory()->forTenant($this->a['tenant'])->count(25)->create();

        $this->postJson('/api/v1/shipping-zones', $payload())->assertStatus(409);
    });

    it('is only for people who can manage the store, and only their own store', function () use ($payload) {
        $zoneB = zone([['name' => 'Theirs']], tenant: $this->b);

        $this->getJson("/api/v1/shipping-zones/{$zoneB->id}")->assertNotFound();
        $this->putJson("/api/v1/shipping-zones/{$zoneB->id}", $payload())->assertNotFound();
        $this->deleteJson("/api/v1/shipping-zones/{$zoneB->id}")->assertNotFound();
        $this->getJson('/api/v1/shipping-zones')->assertJsonCount(0, 'data');

        $staff = User::factory()->forTenant($this->a['tenant'], UserRole::MerchantStaff)->create();
        $this->actingAs($staff, 'sanctum')->getJson('/api/v1/shipping-zones')->assertForbidden();
        $this->postJson('/api/v1/shipping-zones', $payload())->assertForbidden();

        app('auth')->forgetGuards();
        $this->getJson('/api/v1/shipping-zones')->assertUnauthorized();
        expect(ShippingZone::withoutTenantScope()->where('tenant_id', $this->b['tenant']->id)->count())->toBe(1);
    });

    it('serves the places a zone can be built from', function () {
        $this->getJson('/api/v1/shipping/locations')->assertOk()->assertJsonPath('data.everywhere', '*')->assertJsonCount(37, 'data.states')->assertJsonFragment(['code' => 'NG', 'name' => 'Nigeria']);
    });
});

describe('quoting delivery', function () {
    it('falls back to the store flat rate until zones are set up', function () {
        quote('Lagos')->assertOk()->assertJsonPath('data.configured', false)->assertJsonPath('data.available', true)->assertJsonPath('data.options', []);
    });

    it('offers the methods of the most specific matching zone', function () {
        zone([['name' => 'Rest of Nigeria standard', 'price' => 500000]], ['name' => 'Rest of Nigeria', 'position' => 0]);
        zone([['name' => 'Lagos same-day', 'price' => 300000, 'description' => 'Before 12pm'], ['name' => 'Lagos standard', 'price' => 150000]], ['name' => 'Lagos', 'states' => ['Lagos'], 'position' => 1]);
        zone([['name' => 'International courier', 'price' => 2500000]], ['name' => 'World', 'countries' => ['*'], 'position' => 2]);

        $names = fn (string $state, string $country = 'NG') => collect(quote($state, country: $country)->assertOk()->json('data.options'))->pluck('name')->all();

        expect($names('Lagos'))->toBe(['Lagos same-day', 'Lagos standard']);
        expect($names('lagos state'))->toBe(['Lagos same-day', 'Lagos standard']);
        expect($names('Kano'))->toBe(['Rest of Nigeria standard']);
        expect($names('Accra', 'GH'))->toBe(['International courier']);
        expect(quote('Lagos')->json('data.options.0'))->toMatchArray(['name' => 'Lagos same-day', 'price' => 300000, 'free' => false, 'description' => 'Before 12pm']);
    });

    it('makes a method free once the cart reaches the minimum, using the items total', function () {
        zone([['name' => 'Standard', 'price' => 250000, 'free_over' => 2000000]]);

        quote('Lagos', quantity: 1)->assertJsonPath('data.options.0.price', 250000)->assertJsonPath('data.options.0.free', false)->assertJsonPath('data.options.0.freeOver', 2000000);
        quote('Lagos', quantity: 2)->assertJsonPath('data.options.0.price', 0)->assertJsonPath('data.options.0.free', true);
    });

    it('skips inactive zones and methods', function () {
        zone([['name' => 'Off', 'is_active' => false], ['name' => 'On']], ['name' => 'Active']);
        zone([['name' => 'Hidden']], ['name' => 'Inactive', 'is_active' => false, 'states' => ['Kano']]);

        expect(collect(quote('Kano')->json('data.options'))->pluck('name')->all())->toBe(['On']);
    });

    it('says plainly when nothing delivers to an address', function () {
        zone([['name' => 'Lagos only']], ['states' => ['Lagos']]);

        quote('Kano')->assertOk()->assertJsonPath('data.configured', true)->assertJsonPath('data.available', false)->assertJsonPath('data.message', "Sorry, we don't deliver to that address yet.");
        quote('Accra', country: 'GH')->assertJsonPath('data.available', false);
    });

    it('lists the countries the store delivers to', function () {
        $get = fn () => http()->getJson('/api/v1/storefront/shipping/locations', storefrontHeaders($this->a['tenant']))->assertOk();
        expect($get()->json('data.configured'))->toBeFalse()->and($get()->json('data.countries'))->toHaveCount(count(Locations::COUNTRIES));

        zone([['name' => 'x']], ['countries' => ['NG']]);
        zone([['name' => 'y']], ['countries' => ['GH', 'KE']]);
        expect(collect($get()->json('data.countries'))->pluck('code')->all())->toBe(['NG', 'GH', 'KE']);

        zone([['name' => 'z']], ['countries' => ['*']]);
        expect($get()->json('data.countries'))->toHaveCount(count(Locations::COUNTRIES));
    });

    it('validates the request and never reveals another store\'s zones', function () {
        zone([['name' => 'Theirs only']], tenant: $this->b);
        $cart = makeCart($this->a['tenant'], [[$this->product, 1]]);
        $h = storefrontHeaders($this->a['tenant']);

        http()->postJson('/api/v1/storefront/shipping/options', ['cart_id' => $cart->id], $h)->assertUnprocessable();
        http()->postJson('/api/v1/storefront/shipping/options', ['cart_id' => $cart->id, 'country' => 'NGA'], $h)->assertUnprocessable();
        http()->postJson('/api/v1/storefront/shipping/options', ['cart_id' => 'nope', 'country' => 'NG'], $h)->assertUnprocessable();
        quote('Lagos')->assertJsonPath('data.configured', false); // store B's zone does not count for store A
    });
});

describe('checkout', function () {
    it('charges the chosen method and records it on the order', function () {
        $zone = zone([['name' => 'Same-day', 'price' => 300000], ['name' => 'Standard', 'price' => 150000]], ['states' => ['Lagos']]);

        $order = checkoutTo('Lagos', ['shipping_rate_id' => $zone->rates[1]->id])->assertCreated()
            ->assertJsonPath('data.shippingTotal', 150000)->assertJsonPath('data.shippingMethod', 'Standard')->assertJsonPath('data.total', 1150000)->json('data');

        $row = Order::withoutTenantScope()->find($order['id']);
        expect($row->shipping_rate_id)->toBe($zone->rates[1]->id)->and($row->shipping_method)->toBe('Standard');
    });

    it('picks the only method automatically, and demands a choice when there are several', function () {
        zone([['name' => 'Standard', 'price' => 200000]]);
        checkoutTo('Lagos')->assertCreated()->assertJsonPath('data.shippingTotal', 200000);

        zone([['name' => 'Express', 'price' => 400000], ['name' => 'Economy', 'price' => 100000]], ['states' => ['Kano']]);
        checkoutTo('Kano', key: 'idem-key-kano-0001')->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['shipping_rate_id']]]);
    });

    it('applies the free-shipping threshold to the items total, before any discount code', function () {
        $zone = zone([['name' => 'Standard', 'price' => 250000, 'free_over' => 2000000]]);
        Discount::factory()->forTenant($this->a['tenant'])->fixed(500000)->create(['code' => 'FIVEK']);

        checkoutTo('Lagos', ['shipping_rate_id' => $zone->rates[0]->id, 'discount_code' => 'FIVEK'], quantity: 2)->assertCreated()
            ->assertJsonPath('data.shippingTotal', 0)->assertJsonPath('data.discountTotal', 500000)->assertJsonPath('data.total', 1500000);
    });

    it('refuses an address no zone delivers to, without creating anything', function () {
        zone([['name' => 'Lagos only']], ['states' => ['Lagos']]);

        checkoutTo('Kano')->assertUnprocessable()->assertJsonPath('meta.errorCode', 'SHIPPING_UNAVAILABLE');

        expect(Order::withoutTenantScope()->count())->toBe(0)->and($this->product->fresh()->stock)->toBe(50);
    });

    it('rejects a method the address does not qualify for, an inactive one, and another store\'s', function () {
        $lagos = zone([['name' => 'Lagos only', 'price' => 100000]], ['states' => ['Lagos']]);
        $rest = zone([['name' => 'Rest', 'price' => 900000], ['name' => 'Disabled', 'is_active' => false]], ['name' => 'Rest']);
        $theirs = zone([['name' => 'Theirs']], tenant: $this->b);

        foreach ([$lagos->rates[0]->id => 'Kano', $rest->rates[1]->id => 'Kano', $theirs->rates[0]->id => 'Kano'] as $rateId => $state) {
            checkoutTo($state, ['shipping_rate_id' => $rateId], key: 'idem-key-'.substr($rateId, 0, 8))->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['shipping_rate_id']]]);
        }

        expect(Order::withoutTenantScope()->count())->toBe(0);
    });

    it('keeps charging the flat rate while no zones exist', function () {
        $this->actingAs($this->a['owner'], 'sanctum')->putJson('/api/v1/store/settings', ['checkout' => ['shipping_flat_rate' => 175000]])->assertOk();
        app('auth')->forgetGuards();

        checkoutTo('Lagos')->assertCreated()->assertJsonPath('data.shippingTotal', 175000)->assertJsonPath('data.shippingMethod', null);
        http()->postJson('/api/v1/storefront/shipping/options', ['cart_id' => makeCart($this->a['tenant'], [[$this->product, 1]])->id, 'country' => 'NG'], storefrontHeaders($this->a['tenant']))
            ->assertJsonPath('data.flatRate', 175000);
    });

    it('ignores a price sent by the client', function () {
        $zone = zone([['name' => 'Standard', 'price' => 200000]]);

        checkoutTo('Lagos', ['shipping_rate_id' => $zone->rates[0]->id, 'shipping_total' => 1, 'shipping' => 0, 'shipping_price' => 0])->assertCreated()->assertJsonPath('data.shippingTotal', 200000);
    });
});
