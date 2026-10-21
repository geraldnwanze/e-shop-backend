<?php

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Models\Order;
use App\Models\User;

beforeEach(function () {
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
});

/**
 * A customer with the given orders: [[total, daysAgo, paymentStatus?, status?], ...]
 *
 * @param  list<array{0: int, 1: int, 2?: string, 3?: string}>  $orders
 */
function buyer(string $first, array $orders = [], int $joinedDaysAgo = 200, bool $account = false, ?object $tenant = null, ?string $email = null): Customer
{
    $tenant ??= test()->a['tenant'];
    $customer = Customer::factory()->forTenant($tenant)->create(['first_name' => $first, 'last_name' => 'Test', 'email' => $email ?? strtolower($first).'@mail.test']);
    Customer::withoutTenantScope()->whereKey($customer->id)->update(['created_at' => now()->subDays($joinedDaysAgo), 'password' => $account ? bcrypt('secret-pass1') : null]);

    foreach ($orders as $o) {
        Order::factory()->forTenant($tenant)->create([
            'customer_id' => $customer->id, 'customer_email' => $customer->email, 'total' => $o[0], 'subtotal' => $o[0],
            'placed_at' => now()->subDays($o[1]), 'payment_status' => $o[2] ?? 'PAID', 'status' => $o[3] ?? 'COMPLETED',
        ]);
    }

    return $customer;
}

function members(array $rules): array
{
    $segment = CustomerSegment::factory()->forTenant(test()->a['tenant'])->rules($rules)->create();

    return collect(test()->actingAs(test()->a['owner'], 'sanctum')->getJson("/api/v1/customer-segments/{$segment->id}/customers")->assertOk()->json('data'))->pluck('firstName')->sort()->values()->all();
}

it('finds repeat customers by paid orders and ignores unpaid and cancelled ones', function () {
    buyer('Repeat', [[100000, 10], [100000, 5]]);
    buyer('Once', [[100000, 10]]);
    buyer('Unpaid', [[100000, 10], [100000, 5, 'PENDING', 'PENDING']]);
    buyer('Cancelled', [[100000, 10], [100000, 5, 'PAID', 'CANCELLED']]);
    buyer('Refunded', [[100000, 10], [100000, 5, 'REFUNDED']]);

    expect(members(['min_orders' => 2]))->toBe(['Refunded', 'Repeat']);
});

it('finds big spenders within a spend range', function () {
    buyer('Big', [[600000, 10], [500000, 20]]);
    buyer('Mid', [[300000, 10]]);
    buyer('Small', [[50000, 10]]);

    expect(members(['min_spent' => 1000000]))->toBe(['Big'])
        ->and(members(['min_spent' => 100000, 'max_spent' => 400000]))->toBe(['Mid']);
});

it('finds lapsed customers but never someone who has not bought yet', function () {
    buyer('Lapsed', [[100000, 120]]);
    buyer('Active', [[100000, 10]]);
    buyer('Never');

    expect(members(['inactive_for_days' => 90]))->toBe(['Lapsed'])
        ->and(members(['ordered_within_days' => 30]))->toBe(['Active']);
});

it('finds new sign-ups, people who never ordered, and by account', function () {
    buyer('Fresh', [], 3, true);
    buyer('OldBuyer', [[100000, 5]], 300);
    buyer('OldGuest', [], 300);

    expect(members(['joined_within_days' => 30]))->toBe(['Fresh'])
        ->and(members(['max_orders' => 0]))->toBe(['Fresh', 'OldGuest'])
        ->and(members(['has_account' => true]))->toBe(['Fresh'])
        ->and(members(['has_account' => false]))->toBe(['OldBuyer', 'OldGuest']);
});

it('requires every rule to match', function () {
    buyer('Both', [[600000, 10], [600000, 20]]);
    buyer('OnlySpend', [[1500000, 10]]);
    buyer('OnlyCount', [[100000, 10], [100000, 5]]);

    expect(members(['min_orders' => 2, 'min_spent' => 1000000]))->toBe(['Both']);
});

it('creates, shows, updates and deletes a segment with a live count', function () {
    buyer('Repeat', [[100000, 10], [100000, 5]]);
    $this->actingAs($this->a['owner'], 'sanctum');

    $id = $this->postJson('/api/v1/customer-segments', ['name' => 'Repeat buyers', 'description' => 'Bought twice or more', 'rules' => ['min_orders' => 2, 'has_account' => null, 'max_spent' => '']])
        ->assertCreated()->assertJsonPath('data.name', 'Repeat buyers')->assertJsonPath('data.rules', ['min_orders' => 2])->assertJsonPath('data.customersCount', 1)->json('data.id');

    $this->getJson("/api/v1/customer-segments/{$id}")->assertOk()->assertJsonPath('data.customersCount', 1);
    $this->getJson('/api/v1/customer-segments')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.customersCount', 1);

    $this->putJson("/api/v1/customer-segments/{$id}", ['name' => 'Loyal', 'rules' => ['min_orders' => 3]])->assertOk()->assertJsonPath('data.name', 'Loyal')->assertJsonPath('data.customersCount', 0);
    $this->deleteJson("/api/v1/customer-segments/{$id}")->assertOk();
    $this->getJson("/api/v1/customer-segments/{$id}")->assertNotFound();
});

it('previews how many customers a set of rules would match', function () {
    buyer('One', [[100000, 10], [100000, 5]]);
    buyer('Two', [[100000, 10]]);

    $this->actingAs($this->a['owner'], 'sanctum')->postJson('/api/v1/customer-segments/preview', ['rules' => ['max_orders' => 1]])
        ->assertOk()->assertJsonPath('data.customersCount', 1);
    $this->postJson('/api/v1/customer-segments/preview', ['rules' => ['min_orders' => 2]])->assertJsonPath('data.customersCount', 1);
    $this->postJson('/api/v1/customer-segments/preview', ['rules' => []])->assertUnprocessable();
});

it('validates segments', function (array $payload, string $field) {
    $this->actingAs($this->a['owner'], 'sanctum')->postJson('/api/v1/customer-segments', $payload)->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => [$field]]]);
})->with([
    'no name' => [['rules' => ['min_orders' => 1]], 'name'],
    'no rules' => [['name' => 'Empty', 'rules' => []], 'rules'],
    'unknown rule' => [['name' => 'X', 'rules' => ['favourite_colour' => 'blue']], 'rules'],
    'max below min orders' => [['name' => 'X', 'rules' => ['min_orders' => 5, 'max_orders' => 2]], 'rules.max_orders'],
    'max below min spend' => [['name' => 'X', 'rules' => ['min_spent' => 500, 'max_spent' => 100]], 'rules.max_spent'],
    'contradictory recency' => [['name' => 'X', 'rules' => ['ordered_within_days' => 30, 'inactive_for_days' => 60]], 'rules.inactive_for_days'],
    'silly days' => [['name' => 'X', 'rules' => ['joined_within_days' => 0]], 'rules.joined_within_days'],
]);

it('caps the number of segments per store', function () {
    CustomerSegment::factory()->forTenant($this->a['tenant'])->count(CustomerSegment::MAX_PER_STORE)->create();

    $this->actingAs($this->a['owner'], 'sanctum')->postJson('/api/v1/customer-segments', ['name' => 'One too many', 'rules' => ['min_orders' => 1]])->assertStatus(409);
});

it('lists a segment\'s members biggest spender first with their figures', function () {
    buyer('Small', [[100000, 10], [100000, 5]]);
    buyer('Big', [[900000, 10], [900000, 5]]);
    $segment = CustomerSegment::factory()->forTenant($this->a['tenant'])->rules(['min_orders' => 2])->create();

    $rows = $this->actingAs($this->a['owner'], 'sanctum')->getJson("/api/v1/customer-segments/{$segment->id}/customers")->assertOk()->json('data');

    expect($rows)->toHaveCount(2)->and($rows[0]['firstName'])->toBe('Big')->and($rows[0]['totalSpent'])->toBe(1800000)->and($rows[0]['paidOrders'])->toBe(2)
        ->and($rows[0]['lastOrderAt'])->not->toBeNull();
});

it('shows spend on the customers list and filters it by segment', function () {
    buyer('Repeat', [[100000, 10], [200000, 5]]);
    buyer('Once', [[100000, 10]]);
    buyer('Never');
    $segment = CustomerSegment::factory()->forTenant($this->a['tenant'])->rules(['min_orders' => 2])->create();
    $this->actingAs($this->a['owner'], 'sanctum');

    $all = collect($this->getJson('/api/v1/customers')->assertOk()->json('data'));
    expect($all)->toHaveCount(3)->and($all->firstWhere('firstName', 'Repeat')['totalSpent'])->toBe(300000)->and($all->firstWhere('firstName', 'Never')['totalSpent'])->toBe(0)
        ->and($all->firstWhere('firstName', 'Never')['lastOrderAt'])->toBeNull();

    $this->getJson("/api/v1/customers?segment={$segment->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.firstName', 'Repeat');
    $this->getJson('/api/v1/customers?segment=not-a-uuid')->assertUnprocessable();
});

it('keeps segments and their members inside the store', function () {
    buyer('Mine', [[100000, 10], [100000, 5]]);
    buyer('Theirs', [[100000, 10], [100000, 5]], tenant: $this->b['tenant'], email: 'theirs@mail.test');
    $theirSegment = CustomerSegment::factory()->forTenant($this->b['tenant'])->create();
    $mySegment = CustomerSegment::factory()->forTenant($this->a['tenant'])->rules(['min_orders' => 2])->create();
    $this->actingAs($this->a['owner'], 'sanctum');

    $this->getJson('/api/v1/customer-segments')->assertJsonCount(1, 'data');
    foreach (['', '/customers', '/export'] as $suffix) {
        $this->getJson("/api/v1/customer-segments/{$theirSegment->id}{$suffix}")->assertNotFound();
    }
    $this->putJson("/api/v1/customer-segments/{$theirSegment->id}", ['name' => 'Hijack'])->assertNotFound();
    $this->deleteJson("/api/v1/customer-segments/{$theirSegment->id}")->assertNotFound();
    $this->getJson("/api/v1/customer-segments/{$mySegment->id}/customers")->assertJsonCount(1, 'data')->assertJsonPath('data.0.firstName', 'Mine');
    $this->getJson("/api/v1/customers?segment={$theirSegment->id}")->assertNotFound();
});

it('exports a segment as a spreadsheet and neutralises formulas in names', function () {
    buyer('Ada', [[150050, 10], [100000, 5]], email: 'ada@mail.test');
    Customer::withoutTenantScope()->where('email', 'ada@mail.test')->update(['first_name' => '=HYPERLINK("http://evil.test")']);
    Customer::withoutTenantScope()->where('email', 'ada@mail.test')->update(['phone' => '+234 801 234 5678']);
    buyer('Eve', [[100000, 10], [100000, 5]], email: 'eve@mail.test');
    Customer::withoutTenantScope()->where('email', 'eve@mail.test')->update(['phone' => '+cmd|calc']);
    $segment = CustomerSegment::factory()->forTenant($this->a['tenant'])->rules(['min_orders' => 2])->create(['name' => 'Repeat Buyers!']);

    $response = $this->actingAs($this->a['owner'], 'sanctum')->get("/api/v1/customer-segments/{$segment->id}/export");
    $csv = $response->streamedContent();

    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($response->headers->get('Content-Disposition'))->toContain('segment-repeat-buyers-.csv')
        ->and($csv)->toContain('Name,Email,Phone,"Has account","Paid orders","Total spent (NGN)","Last order",Joined')
        ->and($csv)->toContain('ada@mail.test')->toContain('2500.50')
        ->and($csv)->toContain("\"'=HYPERLINK(\"\"http://evil.test\"\") Test\"")
        ->and($csv)->toContain('"+234 801 234 5678"')->toContain("'+cmd|calc");
});

it('is for people who can manage customers', function () {
    $staff = User::factory()->forTenant($this->a['tenant'], UserRole::MerchantStaff)->create();

    $this->actingAs($staff, 'sanctum')->getJson('/api/v1/customer-segments')->assertForbidden();
    $this->postJson('/api/v1/customer-segments', ['name' => 'Staff attempt', 'rules' => ['min_orders' => 1]])->assertForbidden();
    http()->getJson('/api/v1/customer-segments')->assertUnauthorized();
});
