<?php

use App\Enums\MediaProviderType;
use App\Exceptions\MediaException;
use App\Exceptions\MissingTenantContextException;
use App\Models\Media;
use App\Services\Media\MediaService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->m = createMerchant();
    $this->service = app(MediaService::class);
    app(TenantContext::class)->set($this->m['tenant']);
});

describe('DigitalOcean Spaces provider', function () {
    beforeEach(function () {
        config(['media.driver' => 'spaces', 'media.drivers.spaces.cdn_url' => 'https://cdn.example.test']);
        Storage::fake('spaces');
    });

    it('uploads, records metadata and namespaces the key by tenant', function () {
        $media = $this->service->upload(UploadedFile::fake()->image('cone.png', 400, 300), 'products', 'A cone');

        expect($media->provider)->toBe(MediaProviderType::Spaces)
            ->and($media->path)->toStartWith('tenants/'.$this->m['tenant']->id.'/products/')->toEndWith('.png')
            ->and($media->url)->toStartWith('https://cdn.example.test/tenants/')
            ->and($media->width)->toBe(400)->and($media->height)->toBe(300)
            ->and($media->tenant_id)->toBe($this->m['tenant']->id)
            ->and($media->alt)->toBe('A cone');
        Storage::disk('spaces')->assertExists($media->path);
    });

    it('deletes the remote file and the row', function () {
        $media = $this->service->upload(UploadedFile::fake()->image('a.jpg'));

        $this->service->delete($media);

        Storage::disk('spaces')->assertMissing($media->path);
        expect(Media::count())->toBe(0);
    });

    it('replaces the file and removes the old one', function () {
        $media = $this->service->upload(UploadedFile::fake()->image('a.jpg'));
        $old = $media->path;

        $replaced = $this->service->replace($media, UploadedFile::fake()->image('b.png', 50, 50));

        expect($replaced->id)->toBe($media->id)->and($replaced->path)->not->toBe($old)->and($replaced->width)->toBe(50);
        Storage::disk('spaces')->assertMissing($old)->assertExists($replaced->path);
    });

    it('keeps using the provider recorded on the row after the default changes', function () {
        $media = $this->service->upload(UploadedFile::fake()->image('a.jpg'));
        config(['media.driver' => 'cloudinary']);

        $this->service->delete($media);

        Storage::disk('spaces')->assertMissing($media->path);
    });

    it('requires a tenant context', function () {
        app(TenantContext::class)->clear();

        $this->service->upload(UploadedFile::fake()->image('a.jpg'));
    })->throws(MissingTenantContextException::class);
});

describe('Cloudinary provider', function () {
    beforeEach(function () {
        config([
            'media.driver' => 'cloudinary',
            'media.drivers.cloudinary' => ['cloud_name' => 'demo', 'api_key' => 'key123', 'api_secret' => 'secret456'],
        ]);
    });

    it('uploads with a signed request', function () {
        Http::fake(['api.cloudinary.com/*' => Http::response([
            'public_id' => 'tenants/x/uploads/abc', 'secure_url' => 'https://res.cloudinary.com/demo/image/upload/v1/tenants/x/uploads/abc.jpg',
            'bytes' => 2048, 'width' => 640, 'height' => 480,
        ])]);

        $media = $this->service->upload(UploadedFile::fake()->image('a.jpg'), 'products');

        expect($media->provider)->toBe(MediaProviderType::Cloudinary)
            ->and($media->path)->toBe('tenants/x/uploads/abc')->and($media->width)->toBe(640);

        Http::assertSent(function ($request) {
            $data = collect($request->data())->mapWithKeys(fn ($p) => [$p['name'] => $p['contents']]);

            return str_contains($request->url(), 'v1_1/demo/image/upload')
                && $data['api_key'] === 'key123'
                && str_starts_with($data['public_id'], 'tenants/'.$this->m['tenant']->id.'/products/')
                && $data['signature'] === sha1("public_id={$data['public_id']}&timestamp={$data['timestamp']}secret456")
                && ! $data->has('api_secret');
        });
    });

    it('surfaces upload failures without saving a row', function () {
        Http::fake(['api.cloudinary.com/*' => Http::response(['error' => ['message' => 'bad']], 400)]);

        expect(fn () => $this->service->upload(UploadedFile::fake()->image('a.jpg')))->toThrow(MediaException::class);
        expect(Media::count())->toBe(0);
    });

    it('deletes via the destroy endpoint', function () {
        Http::fake(['api.cloudinary.com/*' => Http::response(['result' => 'ok'])]);
        $media = Media::factory()->forTenant($this->m['tenant'])->create(['provider' => MediaProviderType::Cloudinary, 'disk' => null, 'path' => 'tenants/x/y']);

        $this->service->delete($media);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'image/destroy') && $r['public_id'] === 'tenants/x/y');
        expect(Media::count())->toBe(0);
    });

    it('does not delete the row when the provider deletion fails', function () {
        Http::fake(['api.cloudinary.com/*' => Http::response(['error' => 'x'], 500)]);
        $media = Media::factory()->forTenant($this->m['tenant'])->create(['provider' => MediaProviderType::Cloudinary, 'disk' => null]);

        expect(fn () => $this->service->delete($media))->toThrow(MediaException::class);
        expect(Media::count())->toBe(1);
    });

    it('builds transformed delivery URLs', function () {
        $media = Media::factory()->forTenant($this->m['tenant'])->create(['provider' => MediaProviderType::Cloudinary, 'disk' => null, 'path' => 'tenants/x/y']);

        expect($this->service->getUrl($media, ['width' => 300]))
            ->toBe('https://res.cloudinary.com/demo/image/upload/f_auto,q_auto,w_300,c_limit/tenants/x/y')
            ->and($this->service->getUrl($media))->toBe($media->url);
    });
});
