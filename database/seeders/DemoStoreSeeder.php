<?php

namespace Database\Seeders;

use App\Actions\Tenants\ProvisionStoreAction;
use App\Enums\InventoryReason;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Events\OrderPlaced;
use App\Events\OrderStatusChanged;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Checkout\CheckoutService;
use App\Services\Inventory\InventoryService;
use App\Services\Orders\OrderService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Demo merchant + store (icecream.{platform_domain}) with catalogue, customers and orders.
 * Idempotent: re-running does not duplicate data.
 */
class DemoStoreSeeder extends Seeder
{
    public function run(ProvisionStoreAction $provision, TenantContext $context): void
    {
        $tenant = Tenant::query()->where('slug', 'icecream')->first()
            ?? $provision->execute('Scoops Ice Cream', 'icecream');

        $owner = User::query()->firstOrNew(['email' => 'merchant@icecream.test']);
        $owner->fill(['name' => 'Demo Merchant', 'password' => 'password']);
        $owner->tenant_id = $tenant->getKey();
        $owner->role = UserRole::MerchantOwner;
        $owner->email_verified_at ??= now();
        $owner->save();

        $context->runAs($tenant, function () {
            if (Product::query()->exists()) {
                return;
            }

            $this->seedCatalogue();
            $this->seedOrders();
        });
    }

    private function seedCatalogue(): void
    {
        $categories = collect([
            'classic' => 'Classic Flavours',
            'fruity' => 'Fruity',
            'premium' => 'Premium',
        ])->map(fn (string $name, string $slug) => Category::create(['name' => $name, 'slug' => $slug, 'position' => 0]));

        $products = [
            ['Vanilla', 'classic', 150000, 'The timeless classic, made with real vanilla beans.'],
            ['Chocolate', 'classic', 160000, 'Rich dark chocolate with a velvety finish.'],
            ['Strawberry', 'fruity', 160000, 'Fresh strawberries folded into creamy ice cream.'],
            ['Pistachio', 'premium', 220000, 'Roasted pistachios, lightly salted.'],
            ['Cookies & Cream', 'premium', 190000, 'Crushed chocolate cookies in sweet cream.'],
        ];

        foreach ($products as [$name, $category, $price, $description]) {
            $product = Product::create([
                'name' => $name, 'description' => $description, 'short_description' => $description,
                'sku' => 'ICE-'.strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 4)),
                'price' => $price, 'status' => 'active', 'stock' => 0, 'track_inventory' => true,
                'slug' => Str::slug($name),
            ]);
            $product->category_id = $categories[$category]->getKey();
            $product->save();

            foreach ([['Cup', 0, 40], ['Tub', 80000, 25]] as $position => [$size, $extra, $stock]) {
                $variant = $product->variants()->create([
                    'name' => $size, 'sku' => $product->sku.'-'.strtoupper($size), 'options' => ['Size' => $size],
                    'price' => $price + $extra, 'stock' => $stock, 'position' => $position,
                ]);
                app(InventoryService::class)->record($product, $variant, $stock, InventoryReason::Initial);
            }

            $product->forceFill(['stock' => $product->variants()->sum('stock')])->save();
        }

        Discount::create(['code' => 'WELCOME10', 'type' => 'percentage', 'value' => 10, 'is_active' => true]);
    }

    private function seedOrders(): void
    {
        Event::fake([OrderPlaced::class, OrderStatusChanged::class]);

        $carts = app(CartService::class);
        $checkout = app(CheckoutService::class);
        $orders = app(OrderService::class);

        $address = ['first_name' => 'Ada', 'last_name' => 'Obi', 'line1' => '12 Marina Road', 'city' => 'Lagos', 'state' => 'Lagos', 'postal_code' => '100001', 'country' => 'NG', 'phone' => '+2348012345678'];

        $shoppers = [
            ['ada@example.test', 'Ada', 'Obi', true, [OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Completed]],
            ['tunde@example.test', 'Tunde', 'Bakare', true, [OrderStatus::Processing]],
            ['chioma@example.test', 'Chioma', 'Eze', false, []],
        ];

        $products = Product::query()->with('variants')->get()->values();

        foreach ($shoppers as $i => [$email, $first, $last, $registered, $path]) {
            $customer = $registered
                ? Customer::create(['email' => $email, 'first_name' => $first, 'last_name' => $last]) : null;
            $customer?->forceFill(['password' => 'password', 'email_verified_at' => now()])->save();

            $cart = $carts->create($customer);
            $product = $products[$i % $products->count()];
            $carts->addItem($cart, $product->id, $product->variants->first()->id, 2);
            $second = $products[($i + 1) % $products->count()];
            $carts->addItem($cart, $second->id, $second->variants->last()->id, 1);

            $order = $checkout->placeOrder($cart, [
                'customer' => ['email' => $email, 'first_name' => $first, 'last_name' => $last, 'phone' => '+2348012345678'],
                'shipping_address' => $address,
            ], $customer);

            foreach ($path as $status) {
                $orders->transition($order, $status);
            }
        }
    }
}
