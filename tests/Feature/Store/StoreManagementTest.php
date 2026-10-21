<?php

use App\Enums\UserRole;
use App\Models\Domain;
use App\Models\Media;
use App\Models\StoreSetting;
use App\Models\Tenant;
use App\Models\Theme;
use App\Models\ThemeConfiguration;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['media.driver' => 'spaces']);
    Storage::fake('spaces');
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->minimal = Theme::factory()->create(['slug' => 'minimal']);
    $this->fashion = Theme::factory()->create(['slug' => 'fashion-theme']);
});

it('updates store details but never the slug or tenant', function () {
    $this->actingAs($this->a['owner'], 'sanctum')
        ->putJson('/api/v1/store', ['name' => 'New Name', 'currency' => 'USD', 'timezone' => 'Africa/Lagos', 'slug' => 'hijack', 'tenant_id' => $this->b['tenant']->id])
        ->assertOk()->assertJsonPath('data.name', 'New Name')->assertJsonPath('data.slug', 'icecream');

    expect($this->a['store']->fresh()->slug)->toBe('icecream')
        ->and($this->b['store']->fresh()->name)->not->toBe('New Name');
});

it('validates currency and timezone', function () {
    $this->actingAs($this->a['owner'], 'sanctum')
        ->putJson('/api/v1/store', ['currency' => 'dollars', 'timezone' => 'Mars/Base'])
        ->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['currency', 'timezone']]]);
});

it('forbids staff from changing store configuration', function () {
    $staff = User::factory()->forTenant($this->a['tenant'], UserRole::MerchantStaff)->create();

    $this->actingAs($staff, 'sanctum')->putJson('/api/v1/store', ['name' => 'x'])->assertForbidden();
    $this->actingAs($staff, 'sanctum')->putJson('/api/v1/store/theme', ['configuration' => ['colors' => ['primary' => '#000000']]])->assertForbidden();
    $this->actingAs($staff, 'sanctum')->putJson('/api/v1/store/settings', ['seo' => ['title' => 'x']])->assertForbidden();
});

it('creates default settings lazily and merges updates', function () {
    $this->actingAs($this->a['owner'], 'sanctum');

    $this->getJson('/api/v1/store/settings')->assertOk()->assertJsonPath('data.checkout.allow_guest_checkout', true);

    $this->putJson('/api/v1/store/settings', ['seo' => ['title' => 'Best Ice Cream'], 'contact' => ['email' => 'hi@icecream.test']])
        ->assertOk()->assertJsonPath('data.seo.title', 'Best Ice Cream')->assertJsonPath('data.contact.email', 'hi@icecream.test')
        ->assertJsonPath('data.checkout.allow_guest_checkout', true);
});

it('rejects unsafe URLs and unknown keys in settings', function () {
    $this->actingAs($this->a['owner'], 'sanctum')
        ->putJson('/api/v1/store/settings', ['seo' => ['og_image' => 'javascript:alert(1)'], 'unknown' => ['x' => 1]])
        ->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['seo.og_image']]]);
});

it('selects a theme and merges configuration', function () {
    $this->actingAs($this->a['owner'], 'sanctum')
        ->putJson('/api/v1/store/theme', [
            'theme_id' => $this->fashion->id,
            'configuration' => [
                'colors' => ['primary' => '#ff0066'],
                'hero' => ['title' => 'Summer'],
                'navigation' => [['label' => 'Shop', 'url' => '/products']],
                'social' => ['instagram' => 'https://instagram.com/scoops'],
                'homepage' => ['sections' => [
                    ['type' => 'hero', 'enabled' => true, 'config' => []],
                    ['type' => 'featured_products', 'enabled' => true, 'config' => ['limit' => 4]],
                ]],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.theme.id', $this->fashion->id)
        ->assertJsonPath('data.configuration.colors.primary', '#ff0066')
        ->assertJsonPath('data.configuration.colors.secondary', '#6b7280') // untouched default
        ->assertJsonPath('data.configuration.hero.title', 'Summer')
        ->assertJsonPath('data.configuration.hero.subtitle', 'Discover our latest products')
        ->assertJsonCount(2, 'data.configuration.homepage.sections')
        ->assertJsonPath('data.configuration.navigation.0.label', 'Shop');

    expect($this->a['store']->fresh()->theme_id)->toBe($this->fashion->id)
        ->and($this->b['store']->fresh()->theme_id)->toBeNull();
});

it('rejects invalid theme configuration', function (array $configuration, string $field) {
    $this->actingAs($this->a['owner'], 'sanctum')
        ->putJson('/api/v1/store/theme', ['configuration' => $configuration])
        ->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => [$field]]]);
})->with([
    'bad colour' => [['colors' => ['primary' => 'red']], 'configuration.colors.primary'],
    'unknown font' => [['fonts' => ['body' => 'Comic Sans']], 'configuration.fonts.body'],
    'unknown section' => [['homepage' => ['sections' => [['type' => 'iframe', 'enabled' => true, 'config' => []]]]], 'configuration.homepage.sections.0.type'],
    'js nav url' => [['navigation' => [['label' => 'x', 'url' => 'javascript:alert(1)']]], 'configuration.navigation.0.url'],
    'protocol-relative nav' => [['navigation' => [['label' => 'x', 'url' => '//evil.com']]], 'configuration.navigation.0.url'],
    'http hero image' => [['hero' => ['image_url' => 'http://insecure.test/a.png']], 'configuration.hero.image_url'],
    'limit too high' => [['homepage' => ['sections' => [['type' => 'featured_products', 'enabled' => true, 'config' => ['limit' => 999]]]]], 'configuration.homepage.sections.0.config.limit'],
    'unknown group' => [['scripts' => ['x' => 1]], 'configuration'],
]);

it('rejects inactive or unknown themes', function () {
    $inactive = Theme::factory()->inactive()->create();

    $this->actingAs($this->a['owner'], 'sanctum')->putJson('/api/v1/store/theme', ['theme_id' => $inactive->id])->assertUnprocessable();
    $this->actingAs($this->a['owner'], 'sanctum')->putJson('/api/v1/store/theme', ['theme_id' => fake()->uuid()])->assertUnprocessable();
});

it('lists active themes', function () {
    Theme::factory()->inactive()->create();

    $this->actingAs($this->a['owner'], 'sanctum')->getJson('/api/v1/themes')->assertOk()->assertJsonCount(2, 'data');
});

it('keeps theme configuration isolated per tenant', function () {
    $this->actingAs($this->a['owner'], 'sanctum')->putJson('/api/v1/store/theme', ['configuration' => ['hero' => ['title' => 'A only']]])->assertOk();

    $this->actingAs($this->b['owner'], 'sanctum')->getJson('/api/v1/store/theme')
        ->assertOk()->assertJsonPath('data.configuration.hero.title', 'Welcome to our store');
});

it('uploads and replaces branding, removing the old media', function () {
    $this->actingAs($this->a['owner'], 'sanctum');

    $first = $this->postJson('/api/v1/store/logo', ['file' => UploadedFile::fake()->image('logo.png')])
        ->assertOk()->json('data.logo');
    expect($first)->not->toBeNull()->and(Media::withoutTenantScope()->count())->toBe(1);

    $second = $this->postJson('/api/v1/store/logo', ['file' => UploadedFile::fake()->image('logo2.png')])->json('data.logo');
    expect($second)->not->toBe($first)->and(Media::withoutTenantScope()->count())->toBe(1);

    $this->deleteJson('/api/v1/store/logo')->assertOk()->assertJsonPath('data.logo', null);
    expect(Media::withoutTenantScope()->count())->toBe(0);
});

it('accepts png/ico favicons but not jpeg', function () {
    $this->actingAs($this->a['owner'], 'sanctum');

    $this->postJson('/api/v1/store/favicon', ['file' => UploadedFile::fake()->image('f.png', 32, 32)])->assertOk();
    $this->postJson('/api/v1/store/favicon', ['file' => UploadedFile::fake()->image('f.jpg')])->assertUnprocessable();
});

it('serves theme, configuration and public settings on the storefront by hostname', function () {
    Domain::factory()->subdomainOf($this->a['tenant'])->create();
    $this->actingAs($this->a['owner'], 'sanctum')->putJson('/api/v1/store/theme', [
        'theme_id' => $this->fashion->id, 'configuration' => ['colors' => ['primary' => '#123456']],
    ])->assertOk();
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/storefront/store', ['X-Storefront-Host' => 'icecream.mydomain.test'])
        ->assertOk()
        ->assertJsonPath('data.theme.slug', $this->fashion->slug)
        ->assertJsonPath('data.configuration.colors.primary', '#123456')
        ->assertJsonPath('data.primaryDomain', 'icecream.mydomain.test')
        ->assertJsonMissingPath('data.settings.payments');

    // another tenant's host never receives this tenant's theme or configuration
    $this->getJson('/api/v1/storefront/store', ['X-Storefront-Host' => 'fashion.mydomain.test'])
        ->assertOk()->assertJsonPath('data.store.slug', 'fashion')->assertJsonPath('data.theme', null)
        ->assertJsonPath('data.configuration.colors.primary', '#111827');
});

it('provisions default theme, configuration and settings on registration', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', [
        'name' => 'N', 'email' => 'n@example.com', 'password' => 'secret-pass1', 'password_confirmation' => 'secret-pass1',
        'store_name' => 'Brand New', 'store_slug' => 'brandnew',
    ])->assertCreated();

    $tenant = Tenant::where('slug', 'brandnew')->firstOrFail();
    $store = $tenant->store;

    expect($store->theme_id)->toBe($this->minimal->id)
        ->and(ThemeConfiguration::withoutTenantScope()->where('store_id', $store->id)->exists())->toBeTrue()
        ->and(StoreSetting::withoutTenantScope()->where('store_id', $store->id)->exists())->toBeTrue();
});

it('stores the WhatsApp number as digits only and shows it on the storefront once enabled', function () {
    Domain::factory()->subdomainOf($this->a['tenant'])->create();
    $host = ['X-Storefront-Host' => 'icecream.mydomain.test'];

    $this->getJson('/api/v1/storefront/store', $host)->assertJsonPath('data.settings.whatsapp', ['enabled' => false, 'number' => null, 'message' => null]);

    $this->actingAs($this->a['owner'], 'sanctum')
        ->putJson('/api/v1/store/settings', ['whatsapp' => ['enabled' => true, 'number' => '+234 801-234 5678', 'message' => 'Hi, I want to order']])
        ->assertOk()->assertJsonPath('data.whatsapp.number', '2348012345678');
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/storefront/store', $host)
        ->assertJsonPath('data.settings.whatsapp', ['enabled' => true, 'number' => '2348012345678', 'message' => 'Hi, I want to order']);
});

it('hides the WhatsApp number from the storefront while the button is switched off', function () {
    Domain::factory()->subdomainOf($this->a['tenant'])->create();
    $this->actingAs($this->a['owner'], 'sanctum')
        ->putJson('/api/v1/store/settings', ['whatsapp' => ['enabled' => false, 'number' => '2348012345678']])->assertOk();
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/storefront/store', ['X-Storefront-Host' => 'icecream.mydomain.test'])
        ->assertJsonPath('data.settings.whatsapp.enabled', false)->assertJsonPath('data.settings.whatsapp.number', null);
});

it('requires a valid WhatsApp number to enable the button', function (array $whatsapp) {
    $this->actingAs($this->a['owner'], 'sanctum')
        ->putJson('/api/v1/store/settings', ['whatsapp' => $whatsapp])
        ->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['whatsapp.number']]]);
})->with([
    'missing number' => [['enabled' => true]],
    'too short' => [['enabled' => true, 'number' => '12345']],
    'letters only' => [['enabled' => true, 'number' => 'call me']],
]);
