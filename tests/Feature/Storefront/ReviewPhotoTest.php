<?php

use App\Models\Customer;
use App\Models\Media;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ReviewPhoto;
use App\Notifications\StoreMessageNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Notification::fake();
    config(['media.driver' => 'spaces']);
    Storage::fake('spaces');
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->product = Product::factory()->forTenant($this->a['tenant'])->create(['slug' => 'pistachio', 'name' => 'Pistachio', 'stock' => 50]);
});

/** @return array{0: Customer, 1: string} */
function photoCustomer(string $first = 'Ada', bool $bought = true, ?object $tenant = null): array
{
    $tenant ??= test()->a['tenant'];
    $customer = Customer::factory()->forTenant($tenant)->create(['first_name' => $first, 'last_name' => 'Obi', 'email' => strtolower($first).'@mail.test']);
    $token = $customer->createToken('t')->plainTextToken;

    if ($bought) {
        static $n = 0;
        $id = checkout($tenant, makeCart($tenant, [[test()->product, 1]], $customer), token: $token, key: 'photo-key-'.str_pad((string) ++$n, 6, '0', STR_PAD_LEFT))->assertCreated()->json('data.id');
        Order::withoutTenantScope()->whereKey($id)->update(['payment_status' => 'PAID', 'status' => 'COMPLETED']);
    }

    return [$customer, $token];
}

function uploadPhoto(string $token, ?UploadedFile $file = null, ?object $tenant = null)
{
    return http()->post('/api/v1/storefront/review-photos', ['photo' => $file ?? UploadedFile::fake()->image('cake.jpg', 800, 600)], storefrontHeaders($tenant ?? test()->a['tenant'], $token) + ['Accept' => 'application/json']);
}

function reviewWithPhotos(string $token, array $photoIds, array $extra = [])
{
    return http()->postJson('/api/v1/storefront/products/pistachio/reviews', $extra + ['rating' => 5, 'title' => 'Lovely', 'photo_ids' => $photoIds], storefrontHeaders(test()->a['tenant'], $token));
}

// ---- uploading ------------------------------------------------------------------------------

it('stores a customer photo as a clean, resized JPEG', function () {
    [, $token] = photoCustomer();

    $response = uploadPhoto($token, UploadedFile::fake()->image('big.png', 3000, 2000))->assertCreated();

    $media = Media::withoutTenantScope()->findOrFail(ReviewPhoto::withoutTenantScope()->findOrFail($response->json('data.id'))->media_id);
    expect($media->folder)->toBe('review-photos')->and($media->mime_type)->toBe('image/jpeg')->and($media->width)->toBe(1600)->and($media->height)->toBe(1067)
        ->and($media->path)->toEndWith('.jpg')->and($response->json('data.url'))->toBe($media->url);
    Storage::disk('spaces')->assertExists($media->path);
});

it('strips hidden metadata such as GPS positions from uploads', function () {
    [, $token] = photoCustomer();
    ob_start();
    imagejpeg(imagecreatetruecolor(400, 300));
    $jpeg = ob_get_clean();
    $payload = 'Exif'."\0\0".'GPS-LATITUDE-6.5244-SECRET';
    $tagged = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
    expect(str_contains($tagged, 'GPS-LATITUDE'))->toBeTrue();

    $response = uploadPhoto($token, UploadedFile::fake()->createWithContent('phone.jpg', $tagged))->assertCreated();

    $path = Media::withoutTenantScope()->find(ReviewPhoto::withoutTenantScope()->find($response->json('data.id'))->media_id)->path;
    expect(str_contains(Storage::disk('spaces')->get($path), 'GPS-LATITUDE'))->toBeFalse();
});

it('refuses files that are not genuine photos', function (callable $file) {
    [, $token] = photoCustomer();

    uploadPhoto($token, $file())->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['photo']]]);

    expect(ReviewPhoto::withoutTenantScope()->count())->toBe(0)->and(Media::withoutTenantScope()->count())->toBe(0);
})->with([
    'a text file named jpg' => [fn () => UploadedFile::fake()->createWithContent('a.jpg', 'not an image at all')],
    'a gif' => [fn () => UploadedFile::fake()->image('a.gif', 400, 300)],
    'an svg' => [fn () => UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
    'a php file' => [fn () => UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;')],
    'a tiny image' => [fn () => UploadedFile::fake()->image('a.jpg', 50, 50)],
    'a huge image' => [fn () => UploadedFile::fake()->image('a.jpg', 9000, 9000)],
]);

it('refuses photos over the size limit', function () {
    config(['media.max_upload_kb' => 10]);
    [, $token] = photoCustomer();

    uploadPhoto($token, UploadedFile::fake()->image('a.jpg', 1200, 1200)->size(50))->assertUnprocessable();
});

it('needs a signed-in customer who has bought something', function () {
    http()->post('/api/v1/storefront/review-photos', ['photo' => UploadedFile::fake()->image('a.jpg', 400, 300)], storefrontHeaders($this->a['tenant']) + ['Accept' => 'application/json'])->assertUnauthorized();

    [, $browser] = photoCustomer('Never', bought: false);
    uploadPhoto($browser)->assertForbidden();
});

it('limits how many unused photos one customer can hold', function () {
    [$customer, $token] = photoCustomer();
    ReviewPhoto::factory()->forTenant($this->a['tenant'])->count(ReviewPhoto::MAX_UNATTACHED)->create(['product_review_id' => null, 'customer_id' => $customer->id, 'media_id' => null]);

    uploadPhoto($token)->assertStatus(409);
});

// ---- putting photos on a review -------------------------------------------------------------

it('attaches uploaded photos to a review in order and shows them to shoppers', function () {
    [, $token] = photoCustomer();
    $one = uploadPhoto($token)->json('data.id');
    $two = uploadPhoto($token)->json('data.id');

    reviewWithPhotos($token, [$two, $one])->assertOk()->assertJsonPath('data.photos.0.id', $two)->assertJsonPath('data.photos.1.id', $one);

    $public = http()->getJson('/api/v1/storefront/products/pistachio/reviews', storefrontHeaders($this->a['tenant']))->assertOk();
    expect($public->json('data.0.photos'))->toHaveCount(2)->and($public->json('data.0.photos.0'))->toHaveKeys(['id', 'url', 'width', 'height'])->not->toHaveKey('customerId')
        ->and($public->json('meta.photos'))->toHaveCount(2)->and($public->json('meta.photos.0'))->toHaveKeys(['id', 'url', 'reviewId']);
});

it('will not attach another customer\'s photo, a missing one, or more than three', function () {
    [, $token] = photoCustomer();
    [, $other] = photoCustomer('Bola');
    $theirs = uploadPhoto($other)->json('data.id');
    $mine = collect(range(1, 4))->map(fn () => uploadPhoto($token)->json('data.id'))->all();

    reviewWithPhotos($token, [$theirs])->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['photo_ids']]]);
    reviewWithPhotos($token, [(string) Str::uuid()])->assertUnprocessable();
    reviewWithPhotos($token, $mine)->assertUnprocessable();
    reviewWithPhotos($token, [$mine[0], $mine[0]])->assertUnprocessable();
    reviewWithPhotos($token, ['not-a-uuid'])->assertUnprocessable();

    expect(ProductReview::withoutTenantScope()->count())->toBe(0);
});

it('lets a customer swap photos when editing, removing the old files', function () {
    [, $token] = photoCustomer();
    $a = uploadPhoto($token)->json('data.id');
    $b = uploadPhoto($token)->json('data.id');
    reviewWithPhotos($token, [$a, $b])->assertOk();
    $removedPath = Media::withoutTenantScope()->find(ReviewPhoto::withoutTenantScope()->find($b)->media_id)->path;
    $c = uploadPhoto($token)->json('data.id');

    reviewWithPhotos($token, [$a, $c])->assertOk()->assertJsonCount(2, 'data.photos')->assertJsonPath('data.photos.1.id', $c);

    expect(ReviewPhoto::withoutTenantScope()->whereKey($b)->exists())->toBeFalse();
    Storage::disk('spaces')->assertMissing($removedPath);

    reviewWithPhotos($token, [])->assertOk()->assertJsonCount(0, 'data.photos');
    expect(ReviewPhoto::withoutTenantScope()->count())->toBe(0);
});

it('keeps photos when an edit does not mention them', function () {
    [, $token] = photoCustomer();
    reviewWithPhotos($token, [uploadPhoto($token)->json('data.id')])->assertOk();

    http()->postJson('/api/v1/storefront/products/pistachio/reviews', ['rating' => 4], storefrontHeaders($this->a['tenant'], $token))->assertOk()->assertJsonCount(1, 'data.photos');
});

it('removes photos and their files when the review is deleted', function () {
    [, $token] = photoCustomer();
    $id = uploadPhoto($token)->json('data.id');
    reviewWithPhotos($token, [$id])->assertOk();
    $path = Media::withoutTenantScope()->find(ReviewPhoto::withoutTenantScope()->find($id)->media_id)->path;

    http()->deleteJson('/api/v1/storefront/products/pistachio/reviews/mine', [], storefrontHeaders($this->a['tenant'], $token))->assertOk();

    expect(ReviewPhoto::withoutTenantScope()->count())->toBe(0)->and(Media::withoutTenantScope()->count())->toBe(0);
    Storage::disk('spaces')->assertMissing($path);
});

it('lets a customer remove an unused upload but nothing else of theirs', function () {
    [, $token] = photoCustomer();
    [, $other] = photoCustomer('Bola');
    $unused = uploadPhoto($token)->json('data.id');
    $used = uploadPhoto($token)->json('data.id');
    reviewWithPhotos($token, [$used])->assertOk();
    $headers = storefrontHeaders($this->a['tenant'], $token);

    http()->deleteJson("/api/v1/storefront/review-photos/{$used}", [], $headers)->assertNotFound();
    http()->deleteJson("/api/v1/storefront/review-photos/{$unused}", [], storefrontHeaders($this->a['tenant'], $other))->assertNotFound();
    http()->deleteJson("/api/v1/storefront/review-photos/{$unused}", [], $headers)->assertOk();

    expect(ReviewPhoto::withoutTenantScope()->whereKey($unused)->exists())->toBeFalse();
});

it('only shows photos from published reviews', function () {
    [, $token] = photoCustomer();
    reviewWithPhotos($token, [uploadPhoto($token)->json('data.id')])->assertOk();
    $id = ProductReview::withoutTenantScope()->value('id');
    $this->actingAs($this->a['owner'], 'sanctum')->patchJson("/api/v1/reviews/{$id}", ['status' => 'hidden'])->assertOk();

    $public = http()->getJson('/api/v1/storefront/products/pistachio/reviews', storefrontHeaders($this->a['tenant']));

    expect($public->json('data'))->toBeEmpty()->and($public->json('meta.photos'))->toBeEmpty();
});

// ---- merchant -------------------------------------------------------------------------------

it('shows the merchant a review\'s photos and lets them remove just one', function () {
    [, $token] = photoCustomer();
    $keep = uploadPhoto($token)->json('data.id');
    $remove = uploadPhoto($token)->json('data.id');
    reviewWithPhotos($token, [$keep, $remove])->assertOk();
    $review = ProductReview::withoutTenantScope()->first();
    $path = Media::withoutTenantScope()->find(ReviewPhoto::withoutTenantScope()->find($remove)->media_id)->path;
    $this->actingAs($this->a['owner'], 'sanctum');

    $this->getJson('/api/v1/reviews')->assertOk()->assertJsonCount(2, 'data.0.photos');
    $this->deleteJson("/api/v1/reviews/{$review->id}/photos/{$remove}")->assertOk()->assertJsonCount(1, 'data.photos')->assertJsonPath('data.photos.0.id', $keep);

    expect($review->fresh()->status)->toBe('published');
    Storage::disk('spaces')->assertMissing($path);
});

it('keeps merchant photo removal inside the store and the right review', function () {
    [, $token] = photoCustomer();
    $id = uploadPhoto($token)->json('data.id');
    reviewWithPhotos($token, [$id])->assertOk();
    $review = ProductReview::withoutTenantScope()->first();
    $other = ProductReview::factory()->forTenant($this->a['tenant'])->create(['product_id' => $this->product->id]);

    $this->actingAs($this->b['owner'], 'sanctum')->deleteJson("/api/v1/reviews/{$review->id}/photos/{$id}")->assertNotFound();
    $this->actingAs($this->a['owner'], 'sanctum')->deleteJson("/api/v1/reviews/{$other->id}/photos/{$id}")->assertNotFound();
    expect(ReviewPhoto::withoutTenantScope()->whereKey($id)->exists())->toBeTrue();
});

it('keeps customer photos out of the merchant media library', function () {
    [, $token] = photoCustomer();
    uploadPhoto($token)->assertCreated();
    Media::factory()->forTenant($this->a['tenant'])->create();
    $this->actingAs($this->a['owner'], 'sanctum');

    $this->getJson('/api/v1/media')->assertOk()->assertJsonCount(1, 'data');
});

it('mentions the photos in the merchant\'s review alert', function () {
    [, $token] = photoCustomer();
    reviewWithPhotos($token, [uploadPhoto($token)->json('data.id'), uploadPhoto($token)->json('data.id')])->assertOk();

    $alert = collect(Notification::sentNotifications()[AnonymousNotifiable::class] ?? [])->flatten(2)->first(fn ($row) => $row['notification'] instanceof StoreMessageNotification && $row['notification']->kind === 'merchant_review');

    expect($alert['notification']->message['details'])->toContain(['Photos', '2']);
});

// ---- cleanup --------------------------------------------------------------------------------

it('deletes uploads that were never used after a day, and nothing else', function () {
    [, $token] = photoCustomer();
    $stale = uploadPhoto($token)->json('data.id');
    $fresh = uploadPhoto($token)->json('data.id');
    $used = uploadPhoto($token)->json('data.id');
    reviewWithPhotos($token, [$used])->assertOk();
    ReviewPhoto::withoutTenantScope()->whereKey([$stale, $used])->update(['created_at' => now()->subDays(2)]);
    $stalePath = Media::withoutTenantScope()->find(ReviewPhoto::withoutTenantScope()->find($stale)->media_id)->path;

    $this->artisan('reviews:prune-photos')->assertSuccessful();

    expect(ReviewPhoto::withoutTenantScope()->pluck('id')->all())->toContain($fresh, $used)->not->toContain($stale);
    Storage::disk('spaces')->assertMissing($stalePath);
});
