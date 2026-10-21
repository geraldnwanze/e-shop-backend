<?php

namespace App\Listeners;

use App\Events\StockLow;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Notifications\StoreMessageNotification;
use App\Support\Notifications\NotificationPreferences;
use App\Support\Notifications\StoreMailContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the merchant when a product is running low so they can restock before it sells out.
 */
class SendLowStockAlert implements ShouldQueueAfterCommit
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(StockLow $event): void
    {
        $tenant = Tenant::query()->find($event->tenantId);

        if ($tenant === null) {
            return;
        }

        $this->context->runAs($tenant, function () use ($event): void {
            $preferences = NotificationPreferences::forCurrentTenant();
            $product = Product::query()->find($event->productId);

            if ($product === null || ! $preferences->enabled('merchant_low_stock') || ! ($to = $preferences->merchantRecipient())) {
                return;
            }

            $variant = $event->variantId ? ProductVariant::query()->find($event->variantId) : null;
            $name = $variant ? "{$product->name} ({$variant->name})" : $product->name;
            $mail = StoreMailContext::current();
            $units = $event->stock === 1 ? '1 unit' : "{$event->stock} units";

            Notification::route('mail', $to)->notify(new StoreMessageNotification('low_stock', "Low stock: {$name}", [
                'store' => $mail['store'],
                'accent' => $mail['accent'],
                'preheader' => "{$name} has {$units} left.",
                'heading' => $event->stock === 0 ? 'A product just sold out' : 'A product is running low',
                'intro' => "{$name} has {$units} left, at or below your alert level of {$preferences->lowStockThreshold()}.",
                'details' => [['Product', $name], ['SKU', $variant?->sku ?? $product->sku ?? '—'], ['In stock', (string) $event->stock]],
                'button' => ['label' => 'Update stock', 'url' => "{$mail['dashboardUrl']}/products/{$product->id}"],
                'outro' => 'You get one alert each time stock drops to your alert level. Change the level or turn these off under Settings & payments.',
            ]));
        });
    }
}
