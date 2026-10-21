<?php

use App\Models\EmailSuppression;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockAlert;
use App\Notifications\StoreMessageNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->h = storefrontHeaders($this->a['tenant']);
    $this->product = Product::factory()->forTenant($this->a['tenant'])->create(['stock' => 0, 'track_inventory' => true, 'name' => 'Pistachio']);
});

/** @return list<string> */
function backInStockRecipients(): array
{
    $sent = [];

    foreach (Notification::sentNotifications()[AnonymousNotifiable::class] ?? [] as $byClass) {
        foreach ($byClass as $rows) {
            foreach ($rows as $row) {
                if ($row['notification'] instanceof StoreMessageNotification && $row['notification']->kind === 'back_in_stock') {
                    $sent[] = $row['notifiable']->routes['mail'];
                }
            }
        }
    }

    return $sent;
}

it('records a request for a sold-out product, once per email', function () {
    $this->postJson("/api/v1/storefront/products/{$this->product->slug}/stock-alerts", ['email' => ' Ada@Mail.test '], $this->h)->assertOk();
    $this->postJson("/api/v1/storefront/products/{$this->product->slug}/stock-alerts", ['email' => 'ada@mail.test'], $this->h)->assertOk();

    expect(StockAlert::withoutTenantScope()->count())->toBe(1)
        ->and(StockAlert::withoutTenantScope()->first()->email)->toBe('ada@mail.test');
});

it('refuses a request for something that is in stock', function () {
    $this->product->forceFill(['stock' => 3])->save();

    $this->postJson("/api/v1/storefront/products/{$this->product->slug}/stock-alerts", ['email' => 'ada@mail.test'], $this->h)->assertStatus(409);

    expect(StockAlert::withoutTenantScope()->count())->toBe(0);
});

it('validates the email and needs a variant for products that have them', function () {
    $this->postJson("/api/v1/storefront/products/{$this->product->slug}/stock-alerts", ['email' => 'nope'], $this->h)->assertUnprocessable();

    ProductVariant::factory()->create(['tenant_id' => $this->a['tenant']->getKey(), 'product_id' => $this->product->getKey(), 'stock' => 0]);

    $this->postJson("/api/v1/storefront/products/{$this->product->slug}/stock-alerts", ['email' => 'ada@mail.test'], $this->h)
        ->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['variant_id']]]);
});

it('does not find another store\'s product', function () {
    $this->postJson("/api/v1/storefront/products/{$this->product->slug}/stock-alerts", ['email' => 'ada@mail.test'], storefrontHeaders($this->b['tenant']))->assertNotFound();
});

it('emails once when the product is back, and not before', function () {
    StockAlert::factory()->create(['tenant_id' => $this->a['tenant']->getKey(), 'product_id' => $this->product->getKey(), 'email' => 'ada@mail.test']);

    $this->artisan('stock:notify-back-in-stock')->assertSuccessful();
    expect(backInStockRecipients())->toBe([]);

    $this->product->forceFill(['stock' => 4])->save();

    $this->artisan('stock:notify-back-in-stock')->assertSuccessful();
    $this->artisan('stock:notify-back-in-stock')->assertSuccessful();

    expect(backInStockRecipients())->toBe(['ada@mail.test']);
});

it('does not email a suppressed address', function () {
    StockAlert::factory()->create(['tenant_id' => $this->a['tenant']->getKey(), 'product_id' => $this->product->getKey(), 'email' => 'ada@mail.test']);
    EmailSuppression::factory()->forTenant($this->a['tenant'])->create(['email' => 'ada@mail.test']);
    $this->product->forceFill(['stock' => 4])->save();

    $this->artisan('stock:notify-back-in-stock')->assertSuccessful();

    expect(backInStockRecipients())->toBe([])
        ->and(StockAlert::withoutTenantScope()->whereNull('notified_at')->count())->toBe(0);
});
