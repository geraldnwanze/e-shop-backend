<?php

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->staff = User::factory()->forTenant($this->a['tenant'], UserRole::MerchantStaff)->create(['name' => 'Chidi Staff']);
    $this->product = Product::factory()->forTenant($this->a['tenant'])->create(['price' => 100000, 'stock' => 100]);
});

function ring(User $by, string $method = 'cash', int $qty = 1, array $extra = []): string
{
    static $n = 0;

    return test()->actingAs($by, 'sanctum')->postJson('/api/v1/pos/sales', [
        'items' => [['product_id' => test()->product->id, 'quantity' => $qty]],
        'payment_method' => $method,
    ] + $extra, ['Idempotency-Key' => 'shift-key-'.str_pad((string) ++$n, 6, '0', STR_PAD_LEFT)])->assertCreated()->json('data.id');
}

function startShift(User $by, int $float = 500000)
{
    return test()->actingAs($by, 'sanctum')->postJson('/api/v1/pos/shifts', ['opening_float' => $float]);
}

it('starts a shift and reports it as the current one', function () {
    $this->actingAs($this->staff, 'sanctum')->getJson('/api/v1/pos/shift')->assertOk()->assertJsonPath('data', []);

    startShift($this->staff)->assertCreated()->assertJsonPath('data.isOpen', true)->assertJsonPath('data.openingFloat', 500000)->assertJsonPath('data.cashier.name', 'Chidi Staff');

    $this->getJson('/api/v1/pos/shift')->assertJsonPath('data.cashier.id', $this->staff->id)->assertJsonPath('data.summary.expectedCash', 500000);
});

it('allows only one open shift per cashier', function () {
    startShift($this->staff)->assertCreated();
    startShift($this->staff)->assertStatus(409);

    startShift($this->a['owner'])->assertCreated();
});

it('attaches sales to the open shift and works out the expected cash', function () {
    startShift($this->staff, 200000);
    ring($this->staff, 'cash', 2);
    ring($this->staff, 'transfer');
    ring($this->staff, 'card');
    $voided = ring($this->staff, 'cash');
    $this->postJson("/api/v1/orders/{$voided}/void")->assertOk();

    $summary = $this->getJson('/api/v1/pos/shift')->json('data.summary');

    expect($summary['salesCount'])->toBe(3)->and($summary['grossSales'])->toBe(400000)
        ->and($summary['voidedCount'])->toBe(1)->and($summary['voidedTotal'])->toBe(100000)
        ->and($summary['byMethod']['cash'])->toBe(['count' => 1, 'total' => 200000])
        ->and($summary['byMethod']['transfer']['total'])->toBe(100000)
        ->and($summary['expectedCash'])->toBe(400000);
});

it('leaves sales rung up with no shift open unattached', function () {
    $id = ring($this->staff);

    expect(Order::withoutTenantScope()->find($id)->pos_shift_id)->toBeNull();
});

it('closes a shift, freezing the expected cash and showing the variance', function () {
    $shift = startShift($this->staff, 100000)->json('data.id');
    ring($this->staff, 'cash', 3);

    $this->postJson("/api/v1/pos/shifts/{$shift}/close", ['counted_cash' => 390000, 'notes' => 'Short 10k'])
        ->assertOk()->assertJsonPath('data.isOpen', false)->assertJsonPath('data.expectedCash', 400000)
        ->assertJsonPath('data.countedCash', 390000)->assertJsonPath('data.variance', -10000)->assertJsonPath('data.notes', 'Short 10k');

    $this->postJson("/api/v1/pos/shifts/{$shift}/close", ['counted_cash' => 1])->assertStatus(409);
    $this->getJson('/api/v1/pos/shift')->assertJsonPath('data', []);

    // a later sale starts a fresh shift's books, not this one's
    ring($this->staff, 'cash');
    expect(PosShift::withoutTenantScope()->find($shift)->expected_cash)->toBe(400000);
});

it('validates the cash amounts', function () {
    startShift($this->staff, -1)->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['opening_float']]]);

    $shift = startShift($this->staff)->json('data.id');
    $this->postJson("/api/v1/pos/shifts/{$shift}/close", [])->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['counted_cash']]]);
});

it('keeps other cashiers\' shifts private but lets the owner see and close them', function () {
    $mine = startShift($this->staff)->json('data.id');
    $owners = startShift($this->a['owner'])->json('data.id');

    $this->actingAs($this->staff, 'sanctum')->getJson("/api/v1/pos/shifts/{$owners}")->assertNotFound();
    $this->postJson("/api/v1/pos/shifts/{$owners}/close", ['counted_cash' => 0])->assertNotFound();
    $this->getJson('/api/v1/pos/shifts')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine);

    $this->actingAs($this->a['owner'], 'sanctum')->getJson('/api/v1/pos/shifts')->assertJsonPath('meta.total', 2);
    $this->postJson("/api/v1/pos/shifts/{$mine}/close", ['counted_cash' => 500000])->assertOk();
});

it('never exposes another store\'s shifts', function () {
    $theirs = startShift($this->b['owner'])->json('data.id');

    $this->actingAs($this->a['owner'], 'sanctum')->getJson("/api/v1/pos/shifts/{$theirs}")->assertNotFound();
    $this->getJson('/api/v1/pos/shifts')->assertJsonPath('meta.total', 0);
    $this->postJson("/api/v1/pos/shifts/{$theirs}/close", ['counted_cash' => 0])->assertNotFound();
});

it('requires a signed-in merchant', function () {
    http()->getJson('/api/v1/pos/shift')->assertUnauthorized();
    http()->getJson('/api/v1/pos/reports/cashiers')->assertUnauthorized();
});

it('reports sales, voids and payment methods per cashier', function () {
    ring($this->staff, 'cash', 2);
    ring($this->staff, 'card', 1, ['discount_amount' => 10000]);
    ring($this->a['owner'], 'transfer', 5);
    $voided = ring($this->a['owner'], 'cash');
    $this->postJson("/api/v1/orders/{$voided}/void")->assertOk();

    $report = $this->getJson('/api/v1/pos/reports/cashiers')->assertOk()->json('data.cashiers');

    expect($report)->toHaveCount(2)->and($report[0]['name'])->toBe($this->a['owner']->name)
        ->and($report[0]['grossSales'])->toBe(500000)->and($report[0]['voidedCount'])->toBe(1)->and($report[0]['voidedTotal'])->toBe(100000);

    $staff = collect($report)->firstWhere('userId', $this->staff->id);
    expect($staff['salesCount'])->toBe(2)->and($staff['grossSales'])->toBe(290000)->and($staff['discounts'])->toBe(10000)
        ->and($staff['averageSale'])->toBe(145000)->and($staff['byMethod']['card']['total'])->toBe(90000)->and($staff['byMethod']['cash']['total'])->toBe(200000);
});

it('shows staff only their own figures in the cashier report', function () {
    ring($this->staff);
    ring($this->a['owner'], 'cash', 4);

    $report = $this->actingAs($this->staff, 'sanctum')->getJson('/api/v1/pos/reports/cashiers')->json('data.cashiers');

    expect($report)->toHaveCount(1)->and($report[0]['userId'])->toBe($this->staff->id)->and($report[0]['grossSales'])->toBe(100000);
});

it('totals cash variance across closed shifts and respects the period', function () {
    $shift = startShift($this->staff, 0)->json('data.id');
    ring($this->staff, 'cash', 2);
    $this->postJson("/api/v1/pos/shifts/{$shift}/close", ['counted_cash' => 210000])->assertOk();

    $row = $this->actingAs($this->a['owner'], 'sanctum')->getJson('/api/v1/pos/reports/cashiers')->json('data.cashiers.0');
    expect($row['shifts'])->toBe(1)->and($row['cashVariance'])->toBe(10000);

    $past = now()->subDays(30)->toDateString();
    $this->getJson("/api/v1/pos/reports/cashiers?from={$past}&to={$past}")->assertJsonCount(0, 'data.cashiers');
    $this->getJson('/api/v1/pos/reports/cashiers?from=2020-01-01&to=2026-01-01')->assertStatus(409);
});

it('names the cashier on an in-store order', function () {
    $id = ring($this->staff);

    $this->actingAs($this->a['owner'], 'sanctum')->getJson("/api/v1/orders/{$id}")->assertJsonPath('data.cashier.name', 'Chidi Staff');
});

it('includes shifts opened any time on the last day of a period', function () {
    startShift($this->staff)->assertCreated();
    $today = now()->toDateString();

    $this->actingAs($this->a['owner'], 'sanctum')->getJson("/api/v1/pos/shifts?from={$today}&to={$today}")->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/pos/shifts?status=open')->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/pos/shifts?status=closed')->assertJsonPath('meta.total', 0);
});
