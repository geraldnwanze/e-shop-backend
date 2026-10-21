<?php

use App\Enums\UserRole;
use App\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['media.driver' => 'spaces']);
    Storage::fake('spaces');
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
});

it('uploads an image and lists it', function () {
    $this->actingAs($this->a['owner'], 'sanctum')
        ->postJson('/api/v1/media', ['file' => UploadedFile::fake()->image('p.jpg'), 'folder' => 'products', 'alt' => 'Vanilla'])
        ->assertCreated()->assertJsonPath('data.folder', 'products')->assertJsonPath('data.alt', 'Vanilla');

    $this->getJson('/api/v1/media')->assertOk()->assertJsonCount(1, 'data');
});

it('rejects dangerous or oversized uploads', function (UploadedFile $file) {
    $this->actingAs($this->a['owner'], 'sanctum')
        ->postJson('/api/v1/media', ['file' => $file])
        ->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['file']]]);
    expect(Media::withoutTenantScope()->count())->toBe(0);
})->with([
    'svg' => fn () => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    'php disguised as jpg' => fn () => UploadedFile::fake()->createWithContent('shell.jpg', '<?php system($_GET["c"]); ?>'),
    'pdf' => fn () => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
    'too large' => fn () => UploadedFile::fake()->image('big.jpg')->size(6000),
]);

it('lets another tenant\'s media stay invisible and untouchable', function () {
    $media = Media::factory()->forTenant($this->a['tenant'])->create();

    $this->actingAs($this->b['owner'], 'sanctum');
    $this->getJson('/api/v1/media')->assertJsonCount(0, 'data');
    $this->patchJson("/api/v1/media/{$media->id}", ['alt' => 'hacked'])->assertNotFound();
    $this->deleteJson("/api/v1/media/{$media->id}")->assertNotFound();
    $this->postJson("/api/v1/media/{$media->id}/replace", ['file' => UploadedFile::fake()->image('x.jpg')])->assertNotFound();

    expect($media->fresh()->alt)->not->toBe('hacked');
});

it('deletes own media', function () {
    $this->actingAs($this->a['owner'], 'sanctum');
    $uuid = $this->postJson('/api/v1/media', ['file' => UploadedFile::fake()->image('p.jpg')])->json('data.id');

    $this->deleteJson("/api/v1/media/{$uuid}")->assertOk();
    expect(Media::withoutTenantScope()->count())->toBe(0);
});

it('requires authentication and a catalogue permission', function () {
    $this->postJson('/api/v1/media', ['file' => UploadedFile::fake()->image('p.jpg')])->assertUnauthorized();

    $customer = User::factory()->forTenant($this->a['tenant'], UserRole::Customer)->create();
    $this->actingAs($customer, 'sanctum')->getJson('/api/v1/media')->assertForbidden();
});
