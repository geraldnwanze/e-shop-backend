<?php

namespace Database\Seeders;

use App\Actions\Tenants\ProvisionStoreAction;
use App\Enums\MediaProviderType;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Events\OrderPlaced;
use App\Events\OrderStatusChanged;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ShippingZone;
use App\Models\Store;
use App\Models\StorePage;
use App\Models\Tenant;
use App\Models\Theme;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Checkout\CheckoutService;
use App\Services\Orders\OrderService;
use App\Services\Reviews\ReviewService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * One demo store per storefront theme ({theme}.{platform_domain}), each dressed in that theme's
 * recommended colours and fonts, with products, photos, reviews, pages, a discount code, delivery
 * zones, a merchant login (merchant@{theme}.test) and a shopper with an order (shopper@{theme}.test).
 * Both passwords are "password". Photos are sample images from picsum.photos. Re-running skips stores
 * that already exist.
 */
class ThemeShowcaseSeeder extends Seeder
{
    public function run(ProvisionStoreAction $provision, TenantContext $context): void
    {
        foreach ($this->stores() as $slug => $spec) {
            $existing = Tenant::query()->where('slug', $slug)->first();

            if ($existing !== null) {
                $context->runAs($existing, fn () => $this->refreshPhotos($slug, $spec));
                $this->command?->line("Refreshed photos for {$slug} (store already exists).");

                continue;
            }

            $tenant = $provision->execute($spec['name'], $slug);

            $owner = new User(['name' => $spec['name'].' Owner', 'email' => "merchant@{$slug}.test", 'password' => 'password']);
            $owner->tenant_id = $tenant->getKey();
            $owner->role = UserRole::MerchantOwner;
            $owner->email_verified_at = now();
            $owner->save();

            $context->runAs($tenant, function () use ($slug, $spec): void {
                $this->dress($slug, $spec);
                $this->stock($slug, $spec);
                $this->extras($slug, $spec);
                $this->shopper($slug);
            });

            $this->command?->info("Created {$slug}.".config('tenancy.platform_domain'));
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function dress(string $slug, array $spec): void
    {
        $store = Store::query()->firstOrFail();
        $theme = Theme::query()->where('slug', $slug)->firstOrFail();
        $store->update(['theme_id' => $theme->getKey(), 'description' => $spec['tagline']]);

        $configuration = $store->themeConfiguration()->firstOrFail();
        $config = array_replace_recursive($configuration->config, [
            'colors' => $spec['colors'],
            'fonts' => $spec['fonts'],
            'hero' => ['title' => $spec['hero'][0], 'subtitle' => $spec['hero'][1], 'cta_label' => $spec['hero'][2], 'cta_url' => '/products', 'image_url' => $this->photos($slug)['hero']],
            'footer' => ['text' => $spec['footer']],
            'social' => ['instagram' => 'https://instagram.com/', 'facebook' => 'https://facebook.com/', 'tiktok' => 'https://tiktok.com/'],
        ]);
        $config['navigation'] = [['label' => 'Home', 'url' => '/'], ['label' => 'Shop', 'url' => '/products'], ['label' => 'Our story', 'url' => '/pages/about']];
        $config['homepage']['sections'] = [
            ['type' => 'hero', 'enabled' => true, 'config' => []],
            ['type' => 'featured_products', 'enabled' => true, 'config' => ['limit' => 8]],
            ['type' => 'categories', 'enabled' => true, 'config' => []],
            ['type' => 'testimonials', 'enabled' => true, 'config' => ['items' => $spec['testimonials']]],
            ['type' => 'newsletter', 'enabled' => true, 'config' => []],
        ];
        $configuration->update(['config' => $config, 'theme_id' => $theme->getKey()]);

        $settings = $store->settings()->firstOrFail();
        $settings->update(['settings' => array_replace_recursive($settings->settings, [
            'contact' => ['email' => "hello@{$slug}.test", 'phone' => '+2348012345678', 'address' => $spec['address']],
            'seo' => ['title' => $spec['name'], 'description' => $spec['tagline']],
            'whatsapp' => ['enabled' => true, 'number' => '2348012345678', 'message' => 'Hi! I would like to order from '.$spec['name'].'.'],
        ])]);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function stock(string $slug, array $spec): void
    {
        $categories = collect($spec['categories'])->map(fn (string $name, int $i) => Category::create(['name' => $name, 'slug' => Str::slug($name), 'position' => $i, 'description' => "{$name} from {$spec['name']}."]))->values();

        foreach ($spec['products'] as $index => [$name, $categoryIndex, $price, $compare, $description, $stock, $variantSet]) {
            $product = Product::create([
                'name' => $name, 'slug' => Str::slug($name), 'description' => $description."\n\n".$spec['extra'], 'short_description' => $description,
                'sku' => strtoupper(substr($slug, 0, 3)).'-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'price' => $price * 100, 'compare_at_price' => $compare ? $compare * 100 : null,
                'status' => 'active', 'stock' => $stock, 'track_inventory' => true,
            ]);
            $product->category_id = $categories[$categoryIndex]->getKey();
            $product->save();

            foreach ([0, 1] as $position) {
                $media = Media::create([
                    'provider' => MediaProviderType::Spaces, 'disk' => null, 'folder' => 'demo', 'path' => "demo/{$slug}-".Str::slug($name)."-{$position}",
                    'url' => $this->photos($slug)['products'][$index][$position], 'mime_type' => 'image/jpeg', 'size' => 0, 'width' => 900, 'height' => 900, 'alt' => $name,
                ]);
                $product->images()->create(['media_id' => $media->getKey(), 'alt' => $name, 'position' => $position]);
            }

            if ($variantSet !== null) {
                $total = 0;
                foreach ($spec['variantSets'][$variantSet] as $position => [$label, $extra, $variantStock]) {
                    $product->variants()->create(['name' => $label, 'sku' => $product->sku.'-'.Str::upper(Str::slug($label)), 'options' => [$spec['variantLabel'] => $label], 'price' => ($price + $extra) * 100, 'stock' => $variantStock, 'position' => $position]);
                    $total += $variantStock;
                }
                $product->forceFill(['stock' => $total])->save();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function extras(string $slug, array $spec): void
    {
        Discount::create(['code' => 'WELCOME10', 'type' => 'percentage', 'value' => 10, 'is_active' => true]);

        $lagos = ShippingZone::create(['name' => 'Lagos', 'countries' => ['NG'], 'states' => ['Lagos'], 'is_active' => true, 'position' => 0]);
        $lagos->rates()->create(['name' => 'Same-day delivery', 'description' => 'Order before 2pm', 'price' => 150000, 'free_over' => 3000000, 'is_active' => true, 'position' => 0]);
        $rest = ShippingZone::create(['name' => 'Everywhere else', 'countries' => ['*'], 'states' => null, 'is_active' => true, 'position' => 1]);
        $rest->rates()->create(['name' => 'Standard delivery', 'description' => '2 to 4 business days', 'price' => 250000, 'free_over' => 5000000, 'is_active' => true, 'position' => 0]);

        StorePage::create(['title' => 'Our story', 'slug' => 'about', 'body' => $spec['about'], 'position' => 0]);
        StorePage::create(['title' => 'Shipping & returns', 'slug' => 'shipping-returns', 'body' => "Delivery\nOrders are dispatched within 1 to 2 business days. Same-day delivery is available in Lagos for orders placed before 2pm.\n\nReturns\nNot happy? Contact us within 7 days of delivery. Items must be unused and in their original packaging.", 'position' => 1]);
        StorePage::create(['title' => 'Privacy policy', 'slug' => 'privacy', 'body' => "We collect only what we need to process and deliver your order, and we never sell your information.\n\nYou can ask us to see, correct or delete your information at any time.", 'position' => 2]);

        $reviews = app(ReviewService::class);
        $authors = ['Ada O.', 'Tunde B.', 'Chioma E.', 'Ibrahim S.'];
        $bodies = ['Exactly what I hoped for. Great quality.', 'Arrived fast and well packed. Will order again.', 'Lovely, and worth the price.', 'Good value. My friends keep asking where I got it.'];
        foreach (Product::query()->orderBy('slug')->take(5)->get() as $i => $product) {
            foreach (array_slice($authors, 0, 2 + ($i % 3)) as $j => $author) {
                ProductReview::create(['product_id' => $product->getKey(), 'rating' => 5 - (($i + $j) % 3 === 0 ? 1 : 0), 'title' => ['Love it', 'Great buy', 'Recommended', 'Very happy'][($i + $j) % 4], 'body' => $bodies[($i + $j) % 4], 'author_name' => $author, 'status' => ProductReview::STATUS_PUBLISHED]);
            }
            $reviews->recalculate($product);
        }
    }

    private function shopper(string $slug): void
    {
        Event::fake([OrderPlaced::class, OrderStatusChanged::class]);

        $customer = Customer::create(['email' => "shopper@{$slug}.test", 'first_name' => 'Ada', 'last_name' => 'Shopper', 'phone' => '+2348012345678']);
        $customer->forceFill(['password' => 'password', 'email_verified_at' => now()])->save();

        $carts = app(CartService::class);
        $cart = $carts->create($customer);
        $products = Product::query()->with('variants')->where('stock', '>', 6)->orderBy('slug')->take(2)->get();
        foreach ($products as $product) {
            $carts->addItem($cart, $product->id, $product->variants->first()?->id, 1);
        }

        $order = app(CheckoutService::class)->placeOrder($cart, [
            'customer' => ['email' => $customer->email, 'first_name' => 'Ada', 'last_name' => 'Shopper', 'phone' => '+2348012345678'],
            'shipping_address' => ['first_name' => 'Ada', 'last_name' => 'Shopper', 'line1' => '12 Marina Road', 'city' => 'Ikeja', 'state' => 'Lagos', 'postal_code' => '100001', 'country' => 'NG', 'phone' => '+2348012345678'],
        ], $customer);

        foreach ([OrderStatus::Processing, OrderStatus::Shipped] as $status) {
            app(OrderService::class)->transition($order, $status);
        }
    }

    /**
     * The curated photos for a store: a hero and two images per product, in product order.
     *
     * @return array{hero: string, products: list<array{0: string, 1: string}>}
     */
    private function photos(string $slug): array
    {
        static $all = null;
        $all ??= json_decode((string) file_get_contents(__DIR__.'/data/showcase-photos.json'), true, flags: JSON_THROW_ON_ERROR);

        return $all[$slug];
    }

    /**
     * Point an existing demo store's hero and product images at the curated photos.
     *
     * @param  array<string, mixed>  $spec
     */
    private function refreshPhotos(string $slug, array $spec): void
    {
        $photos = $this->photos($slug);
        $store = Store::query()->first();
        $configuration = $store?->themeConfiguration()->first();

        if ($configuration !== null) {
            $config = $configuration->config;
            $config['hero']['image_url'] = $photos['hero'];
            $configuration->update(['config' => $config]);
        }

        foreach ($spec['products'] as $index => [$name]) {
            $product = Product::query()->where('slug', Str::slug($name))->with('images.media')->first();

            foreach ($product?->images ?? [] as $position => $image) {
                $image->media?->update(['url' => $photos['products'][$index][$position] ?? $photos['products'][$index][0]]);
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function stores(): array
    {
        $sizes = [['Small', 0, 8], ['Medium', 0, 12], ['Large', 0, 6]];

        return [
            'minimal' => [
                'name' => 'Everyday Goods', 'tagline' => 'Well-made essentials for the home.',
                'colors' => ['primary' => '#111827', 'secondary' => '#6b7280', 'accent' => '#f59e0b', 'background' => '#ffffff', 'text' => '#111827'],
                'fonts' => ['heading' => 'Inter', 'body' => 'Inter'],
                'hero' => ['Simple things, done properly', 'Thoughtfully made homeware you will use every day.', 'Shop the collection'],
                'footer' => 'Everyday Goods. Well-made essentials for the home.', 'address' => '14 Admiralty Way, Lekki, Lagos',
                'extra' => 'Made to last and easy to care for.',
                'about' => "Everyday Goods began with a simple idea: the things you use every day should be well made.\n\nWe work with small makers to bring you a short list of essentials, and we stand behind every one.",
                'categories' => ['Kitchen', 'Living', 'Bath'], 'variantLabel' => 'Size', 'variantSets' => ['sizes' => $sizes],
                'testimonials' => [['quote' => 'Beautifully made and exactly as pictured.', 'author' => 'Ada O.'], ['quote' => 'Fast delivery and lovely packaging.', 'author' => 'Tunde B.'], ['quote' => 'My kitchen has never looked better.', 'author' => 'Chioma E.']],
                'products' => [
                    ['Ceramic Mug Set', 0, 12500, 15000, 'Four hand-glazed mugs in soft neutral tones.', 30, null],
                    ['Oak Cutting Board', 0, 18000, null, 'Solid oak with a juice groove and leather loop.', 14, null],
                    ['Linen Table Runner', 1, 9500, null, 'Stonewashed linen that softens with every wash.', 25, null],
                    ['Soy Wax Candle', 1, 7500, 9000, 'Slow-burning candle in a reusable glass jar.', 3, null],
                    ['Woven Storage Basket', 1, 14000, null, 'Handwoven seagrass basket in three sizes.', 20, 'sizes'],
                    ['Cotton Bath Towel', 2, 8500, null, 'Heavyweight Turkish cotton, quick drying.', 40, null],
                    ['Bamboo Soap Dish', 2, 3500, null, 'Keeps soap dry and lasts longer.', 0, null],
                    ['Glass Water Carafe', 0, 11000, 13500, 'Hand-blown carafe with a matching glass.', 18, null],
                ],
            ],
            'fashion' => [
                'name' => 'Maison Noir', 'tagline' => 'Modern tailoring and everyday luxury.',
                'colors' => ['primary' => '#1c1917', 'secondary' => '#78716c', 'accent' => '#a16207', 'background' => '#faf8f5', 'text' => '#1c1917'],
                'fonts' => ['heading' => 'Playfair Display', 'body' => 'Lato'],
                'hero' => ['The new season', 'Considered pieces, cut to last beyond the trend.', 'Discover the edit'],
                'footer' => 'Maison Noir. Modern tailoring and everyday luxury.', 'address' => '7 Awolowo Road, Ikoyi, Lagos',
                'extra' => 'Cut from premium fabric. Dry clean or gentle wash.',
                'about' => "Maison Noir is a small atelier in Lagos making clothes that fit well and last.\n\nEvery piece is cut, finished and checked by hand in limited runs.",
                'categories' => ['Women', 'Men', 'Accessories'], 'variantLabel' => 'Size', 'variantSets' => ['clothes' => [['S', 0, 6], ['M', 0, 9], ['L', 0, 5]]],
                'testimonials' => [['quote' => 'The fit is perfect and the fabric feels luxurious.', 'author' => 'Zainab A.'], ['quote' => 'My go-to for events. Always compliments.', 'author' => 'Femi K.'], ['quote' => 'Worth every naira.', 'author' => 'Nneka U.']],
                'products' => [
                    ['Tailored Wool Blazer', 0, 85000, 98000, 'A sharp, structured blazer in soft Italian wool.', 0, 'clothes'],
                    ['Silk Slip Dress', 0, 64000, null, 'Bias-cut silk that drapes beautifully.', 0, 'clothes'],
                    ['Wide-Leg Trousers', 0, 42000, null, 'High-waisted with a fluid, floor-skimming leg.', 0, 'clothes'],
                    ['Oxford Shirt', 1, 36000, 42000, 'Crisp cotton poplin with a relaxed collar.', 0, 'clothes'],
                    ['Linen Overshirt', 1, 48000, null, 'Lightweight and breathable for warm evenings.', 0, 'clothes'],
                    ['Leather Belt', 2, 18000, null, 'Full-grain leather with a brushed brass buckle.', 22, null],
                    ['Structured Tote', 2, 72000, 80000, 'Roomy structured tote with a suede lining.', 3, null],
                    ['Silk Scarf', 2, 21000, null, 'Hand-rolled edges and a bold print.', 0, null],
                ],
            ],
            'modern' => [
                'name' => 'Pulse Lifestyle', 'tagline' => 'Bold gear for an active life.',
                'colors' => ['primary' => '#5b21b6', 'secondary' => '#6b7280', 'accent' => '#f97316', 'background' => '#fffdf7', 'text' => '#111111'],
                'fonts' => ['heading' => 'Poppins', 'body' => 'DM Sans'],
                'hero' => ['Move louder', 'Gear and goods for people who never sit still.', 'Get moving'],
                'footer' => 'Pulse Lifestyle. Bold gear for an active life.', 'address' => '22 Allen Avenue, Ikeja, Lagos',
                'extra' => 'Built for daily use and backed by a 12-month guarantee.',
                'about' => "Pulse started in a garage with a simple rule: if it is not fun to use, we do not sell it.\n\nWe pick gear that is bold, tough and easy to love.",
                'categories' => ['Fitness', 'Outdoors', 'Everyday'], 'variantLabel' => 'Colour', 'variantSets' => ['colours' => [['Violet', 0, 10], ['Orange', 0, 10], ['Black', 0, 8]]],
                'testimonials' => [['quote' => 'Tough, bright and a joy to use.', 'author' => 'Dayo M.'], ['quote' => 'Best gym bag I have owned.', 'author' => 'Ify N.'], ['quote' => 'Fast shipping and great service.', 'author' => 'Sam T.']],
                'products' => [
                    ['Insulated Bottle', 0, 9500, null, 'Keeps drinks cold for 24 hours or hot for 12.', 0, 'colours'],
                    ['Resistance Band Set', 0, 12000, 15000, 'Five levels of resistance with a carry pouch.', 35, null],
                    ['Yoga Mat Pro', 0, 22000, null, 'Non-slip, 6mm cushioning, with a strap.', 16, null],
                    ['Trail Backpack 24L', 1, 38000, 45000, 'Water-resistant with a padded back panel.', 12, 'colours'],
                    ['Camp Hammock', 1, 17500, null, 'Packs down small and holds up to 200kg.', 9, null],
                    ['Compact Umbrella', 2, 6500, null, 'Wind-tested and light enough for any bag.', 3, null],
                    ['Canvas Weekender', 2, 31000, null, 'Roomy canvas bag with leather handles.', 14, null],
                    ['Sunglasses', 2, 14500, 18000, 'Polarised lenses with UV400 protection.', 28, null],
                ],
            ],
            'electronics' => [
                'name' => 'VoltHub', 'tagline' => 'Smart tech at honest prices.',
                'colors' => ['primary' => '#0f172a', 'secondary' => '#64748b', 'accent' => '#0891b2', 'background' => '#f8fafc', 'text' => '#0f172a'],
                'fonts' => ['heading' => 'Montserrat', 'body' => 'Roboto'],
                'hero' => ['Power up your day', 'Phones, audio and accessories with real warranty.', 'Shop gadgets'],
                'footer' => 'VoltHub. Smart tech at honest prices.', 'address' => '5 Computer Village, Ikeja, Lagos',
                'extra' => '12-month warranty. Genuine products only.',
                'about' => "VoltHub is a Lagos electronics store built on trust: genuine products, clear prices and real warranty.\n\nIf something goes wrong, we fix it or replace it.",
                'categories' => ['Phones', 'Audio', 'Accessories'], 'variantLabel' => 'Storage', 'variantSets' => ['storage' => [['128GB', 0, 7], ['256GB', 60000, 5]]],
                'testimonials' => [['quote' => 'Genuine product and quick delivery.', 'author' => 'Kunle P.'], ['quote' => 'Great prices and honest advice.', 'author' => 'Amaka I.'], ['quote' => 'Warranty claim was sorted in two days.', 'author' => 'Yusuf L.']],
                'products' => [
                    ['Nova X Smartphone', 0, 420000, 465000, '6.7-inch AMOLED, 50MP camera and all-day battery.', 0, 'storage'],
                    ['Nova Lite Smartphone', 0, 210000, null, '6.5-inch display with a fast octa-core chip.', 11, null],
                    ['Wireless Earbuds Pro', 1, 38000, 45000, 'Active noise cancelling with 30 hours of battery.', 24, null],
                    ['Bluetooth Speaker', 1, 27500, null, 'Waterproof 360-degree sound that lasts 12 hours.', 3, null],
                    ['Over-Ear Headphones', 1, 62000, null, 'Studio-tuned drivers with plush memory-foam pads.', 8, null],
                    ['65W Fast Charger', 2, 14500, null, 'Charges a phone and a laptop at the same time.', 40, null],
                    ['Power Bank 20,000mAh', 2, 24000, 28000, 'Dual-port fast charging in a slim body.', 0, null],
                    ['Braided USB-C Cable', 2, 4500, null, 'Tough 2-metre cable rated for 100W.', 60, null],
                ],
            ],
            'beauty' => [
                'name' => 'Glow Studio', 'tagline' => 'Gentle skincare and radiant makeup.',
                'colors' => ['primary' => '#9d174d', 'secondary' => '#a8a29e', 'accent' => '#db2777', 'background' => '#fffaf8', 'text' => '#3b2a2f'],
                'fonts' => ['heading' => 'Playfair Display', 'body' => 'Nunito'],
                'hero' => ['Glow, naturally', 'Kind-to-skin formulas made with ingredients you can pronounce.', 'Find your routine'],
                'footer' => 'Glow Studio. Gentle skincare and radiant makeup.', 'address' => '3 Palm Grove, Victoria Island, Lagos',
                'extra' => 'Dermatologist tested. Not tested on animals.',
                'about' => "Glow Studio makes skincare and makeup for real skin, in every shade.\n\nOur formulas are simple, gentle and made to be used every day.",
                'categories' => ['Skincare', 'Makeup', 'Body'], 'variantLabel' => 'Shade', 'variantSets' => ['shades' => [['Rose', 0, 9], ['Nude', 0, 12], ['Berry', 0, 6]]],
                'testimonials' => [['quote' => 'My skin has never felt softer.', 'author' => 'Tola A.'], ['quote' => 'The lipstick shade is perfect.', 'author' => 'Hauwa G.'], ['quote' => 'Smells divine and works beautifully.', 'author' => 'Bisi O.']],
                'products' => [
                    ['Hydrating Face Serum', 0, 18500, 22000, 'Hyaluronic acid and vitamin B5 for plump, dewy skin.', 26, null],
                    ['Gentle Cleansing Balm', 0, 12500, null, 'Melts away makeup without stripping the skin.', 19, null],
                    ['Daily Mineral SPF 50', 0, 14000, null, 'Lightweight sunscreen with no white cast.', 3, null],
                    ['Velvet Matte Lipstick', 1, 9500, null, 'Comfortable colour that lasts through the day.', 0, 'shades'],
                    ['Skin Tint Foundation', 1, 16500, 19000, 'Breathable coverage with a natural finish.', 15, null],
                    ['Cream Blush', 1, 8500, null, 'Blendable colour for a soft, healthy flush.', 22, 'shades'],
                    ['Shea Body Butter', 2, 11000, null, 'Rich whipped butter for all-day softness.', 30, null],
                    ['Rose Bath Oil', 2, 13500, null, 'A soothing soak scented with real rose.', 12, null],
                ],
            ],
            'restaurant' => [
                'name' => 'Casa Brasa', 'tagline' => 'Wood-fired food, made fresh daily.',
                'colors' => ['primary' => '#7c2d12', 'secondary' => '#a16207', 'accent' => '#d97706', 'background' => '#fffbf5', 'text' => '#2b1d14'],
                'fonts' => ['heading' => 'Merriweather', 'body' => 'Open Sans'],
                'hero' => ['Fire, smoke and flavour', 'Slow-cooked classics and fresh pizzas, ready when you are.', 'See the menu'],
                'footer' => 'Casa Brasa. Wood-fired food, made fresh daily.', 'address' => '9 Bourdillon Road, Ikoyi, Lagos',
                'extra' => 'Made fresh to order. Tell us about any allergies.',
                'about' => "Casa Brasa cooks over live wood fire, the slow way.\n\nEvery dish is made fresh to order from ingredients we trust, and delivered hot.",
                'categories' => ['Mains', 'Pizza', 'Desserts'], 'variantLabel' => 'Portion', 'variantSets' => ['portion' => [['Regular', 0, 20], ['Large', 3000, 15]]],
                'testimonials' => [['quote' => 'The best smoked chicken in Lagos.', 'author' => 'Segun R.'], ['quote' => 'Pizza arrived hot and perfect.', 'author' => 'Linda C.'], ['quote' => 'Our office lunch favourite.', 'author' => 'Emeka D.']],
                'products' => [
                    ['Smoked Chicken', 0, 8500, 10000, 'Half a chicken smoked over oak with house spice.', 30, 'portion'],
                    ['Slow Beef Short Rib', 0, 12000, null, 'Eight-hour braise with a red wine glaze.', 14, null],
                    ['Grilled Prawns', 0, 14000, null, 'Garlic butter prawns with charred lemon.', 3, null],
                    ['Margherita Pizza', 1, 6500, null, 'San Marzano tomato, mozzarella and fresh basil.', 40, 'portion'],
                    ['Spicy Suya Pizza', 1, 7800, 9000, 'Beef suya, onions and a cool yaji drizzle.', 24, null],
                    ['Four Cheese Pizza', 1, 8200, null, 'Mozzarella, cheddar, parmesan and goat cheese.', 0, null],
                    ['Chocolate Fondant', 2, 4000, null, 'Warm centre, served with vanilla cream.', 16, null],
                    ['Plantain Cheesecake', 2, 3800, 4500, 'Caramelised plantain on a biscuit base.', 12, null],
                ],
            ],
        ];
    }
}
