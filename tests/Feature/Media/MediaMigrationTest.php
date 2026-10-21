<?php

use App\Enums\MediaProviderType;
use App\Models\Media;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\ThemeConfiguration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** A tiny real PNG, so the destination provider can read its dimensions. */
function tinyPng(): string
{
    $image = imagecreatetruecolor(8, 6);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

beforeEach(function () {
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    Storage::fake('spaces');
    config(['media.drivers.spaces.disk' => 'spaces', 'media.drivers.spaces.cdn_url' => 'https://cdn.example.test']);
});

function cloudinaryImage($tenant, string $name): Media
{
    return Media::factory()->create([
        'tenant_id' => $tenant->id, 'provider' => MediaProviderType::Cloudinary, 'disk' => null, 'folder' => 'products',
        'path' => "tenants/{$tenant->id}/products/{$name}", 'url' => "https://res.cloudinary.com/demo/image/upload/{$name}.png", 'mime_type' => 'image/png', 'size' => 10,
    ]);
}

it('moves an image to Spaces and repoints it everywhere its address was saved', function () {
    $media = cloudinaryImage($this->a['tenant'], 'logo-1');
    $old = $media->url;
    Store::withoutTenantScope()->where('tenant_id', $this->a['tenant']->id)->update(['logo' => $old]);
    $configuration = ThemeConfiguration::factory()->create(['store_id' => $this->a['store']->id, 'config' => ['hero' => ['image_url' => $old]]]);
    $settings = StoreSetting::factory()->create(['store_id' => $this->a['store']->id, 'settings' => ['seo' => ['og_image' => $old, 'title' => 'Shop']]]);
    Http::fake([$old => Http::response(tinyPng(), 200)]);

    $this->artisan('media:migrate', ['from' => 'cloudinary', 'to' => 'spaces'])->assertSuccessful();

    $media->refresh();
    expect($media->provider)->toBe(MediaProviderType::Spaces)
        ->and($media->url)->toStartWith('https://cdn.example.test/tenants/')
        ->and($media->url)->not->toBe($old)
        ->and($media->width)->toBe(8)
        ->and(Storage::disk('spaces')->exists($media->path))->toBeTrue()
        ->and(Store::withoutTenantScope()->where('tenant_id', $this->a['tenant']->id)->value('logo'))->toBe($media->url)
        ->and($configuration->refresh()->config['hero']['image_url'])->toBe($media->url)
        ->and($settings->refresh()->settings['seo'])->toBe(['og_image' => $media->url, 'title' => 'Shop']);
});

it('leaves other stores\' saved addresses and images already on the target alone', function () {
    $mine = cloudinaryImage($this->a['tenant'], 'mine');
    Store::withoutTenantScope()->where('tenant_id', $this->b['tenant']->id)->update(['logo' => $mine->url]);
    $already = Media::factory()->create(['tenant_id' => $this->a['tenant']->id, 'provider' => MediaProviderType::Spaces, 'path' => 'x/already.png', 'url' => 'https://cdn.example.test/x/already.png']);
    Http::fake(['*' => Http::response(tinyPng(), 200)]);

    $this->artisan('media:migrate', ['from' => 'cloudinary', 'to' => 'spaces'])->assertSuccessful();

    expect(Store::withoutTenantScope()->where('tenant_id', $this->b['tenant']->id)->value('logo'))->toBe($mine->url)
        ->and($already->refresh()->url)->toBe('https://cdn.example.test/x/already.png');
});

it('keeps an image working where it was when it cannot be fetched, and can be run again', function () {
    $media = cloudinaryImage($this->a['tenant'], 'broken');
    $old = $media->url;
    Http::fake([$old => Http::sequence()->push('gone', 404)->push(tinyPng(), 200)]);

    $this->artisan('media:migrate', ['from' => 'cloudinary', 'to' => 'spaces'])->assertFailed();

    expect($media->refresh()->provider)->toBe(MediaProviderType::Cloudinary)->and($media->url)->toBe($old)
        ->and(Storage::disk('spaces')->allFiles())->toBe([]);

    $this->artisan('media:migrate', ['from' => 'cloudinary', 'to' => 'spaces'])->assertSuccessful();

    expect($media->refresh()->provider)->toBe(MediaProviderType::Spaces);
});

it('reports what would move without moving anything on a dry run, and honours the limit', function () {
    cloudinaryImage($this->a['tenant'], 'one');
    cloudinaryImage($this->a['tenant'], 'two');
    Http::fake(['*' => Http::response(tinyPng(), 200)]);

    $this->artisan('media:migrate', ['from' => 'cloudinary', 'to' => 'spaces', '--dry-run' => true])->expectsOutputToContain('2 image(s)')->assertSuccessful();
    expect(Media::withoutTenantScope()->where('provider', 'spaces')->count())->toBe(0);

    $this->artisan('media:migrate', ['from' => 'cloudinary', 'to' => 'spaces', '--limit' => 1])->assertSuccessful();
    expect(Media::withoutTenantScope()->where('provider', 'spaces')->count())->toBe(1);
});

it('refuses the same provider twice or an unknown one', function () {
    $this->artisan('media:migrate', ['from' => 'spaces', 'to' => 'spaces'])->assertExitCode(2);
    $this->artisan('media:migrate', ['from' => 'imgur', 'to' => 'spaces'])->assertExitCode(2);
});
