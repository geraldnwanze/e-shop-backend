<?php

use App\Models\Customer;
use App\Models\Domain;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Notifications\StoreMessageNotification;
use App\Services\Reviews\ReviewService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->product = Product::factory()->forTenant($this->a['tenant'])->create(['slug' => 'pistachio', 'name' => 'Pistachio', 'stock' => 50]);
});

/** A signed-in customer, optionally with an order containing the product in the given payment state. */
function reviewer(string $first = 'Ada', string $last = 'Obi', ?string $payment = 'PAID', string $status = 'COMPLETED', ?object $tenant = null, ?Product $product = null): array
{
    $tenant ??= test()->a['tenant'];
    $customer = Customer::factory()->forTenant($tenant)->create(['first_name' => $first, 'last_name' => $last, 'email' => strtolower($first).'@mail.test']);
    $token = $customer->createToken('t')->plainTextToken;

    if ($payment !== null) {
        static $n = 0;
        $id = checkout($tenant, makeCart($tenant, [[$product ?? test()->product, 1]], $customer), token: $token, key: 'review-key-'.str_pad((string) ++$n, 6, '0', STR_PAD_LEFT))->assertCreated()->json('data.id');
        Order::withoutTenantScope()->whereKey($id)->update(['payment_status' => $payment, 'status' => $status]);
    }

    return [$customer, $token];
}

function reviewHeaders(string $token): array
{
    return storefrontHeaders(test()->a['tenant'], $token);
}

function postReview(string $token, array $data = [], string $slug = 'pistachio')
{
    return http()->postJson("/api/v1/storefront/products/{$slug}/reviews", $data + ['rating' => 5, 'title' => 'Delicious', 'body' => 'Best ice cream in town.'], reviewHeaders($token));
}

function reviewSettings(array $reviews): void
{
    test()->actingAs(test()->a['owner'], 'sanctum')->putJson('/api/v1/store/settings', ['reviews' => $reviews])->assertOk();
    app('auth')->forgetGuards();
}

// ---- who can review -------------------------------------------------------------------------

it('lets only customers with a paid, uncancelled order for the product review it', function (?string $payment, string $status, bool $eligible) {
    [, $token] = reviewer(payment: $payment, status: $status);

    http()->getJson('/api/v1/storefront/products/pistachio/reviews/mine', reviewHeaders($token))->assertOk()
        ->assertJsonPath('data.eligible', $eligible)->assertJsonPath('data.reason', $eligible ? null : 'purchase_required');
    postReview($token)->assertStatus($eligible ? 200 : 403);
})->with([
    'paid' => ['PAID', 'COMPLETED', true],
    'refunded' => ['REFUNDED', 'COMPLETED', true],
    'unpaid' => ['PENDING', 'PENDING', false],
    'cancelled' => ['PAID', 'CANCELLED', false],
    'never ordered' => [null, 'COMPLETED', false],
]);

it('does not let a purchase of one product review another', function () {
    $other = Product::factory()->forTenant($this->a['tenant'])->create(['slug' => 'vanilla', 'stock' => 50]);
    [, $token] = reviewer(product: $other);

    postReview($token, slug: 'pistachio')->assertForbidden();
    postReview($token, slug: 'vanilla')->assertOk();
});

it('needs a signed-in customer', function () {
    http()->postJson('/api/v1/storefront/products/pistachio/reviews', ['rating' => 5], storefrontHeaders($this->a['tenant']))->assertUnauthorized();
    http()->getJson('/api/v1/storefront/products/pistachio/reviews/mine', storefrontHeaders($this->a['tenant']))->assertUnauthorized();
});

// ---- writing a review -----------------------------------------------------------------------

it('publishes a review under a shortened name and updates the product rating', function () {
    [, $token] = reviewer();

    postReview($token, ['rating' => 4])->assertOk()->assertJsonPath('data.authorName', 'Ada O.')->assertJsonPath('data.status', 'published')->assertJsonPath('data.rating', 4);

    $product = $this->product->fresh();
    expect((float) $product->rating_average)->toBe(4.0)->and($product->rating_count)->toBe(1);
});

it('keeps one review per customer and lets them change it', function () {
    [, $token] = reviewer();

    $id = postReview($token, ['rating' => 5])->json('data.id');
    postReview($token, ['rating' => 2, 'title' => 'Changed my mind'])->assertOk()->assertJsonPath('data.id', $id);

    expect(ProductReview::withoutTenantScope()->count())->toBe(1)->and((float) $this->product->fresh()->rating_average)->toBe(2.0);
});

it('averages across customers and drops a deleted review from the rating', function () {
    [, $one] = reviewer('Ada');
    [, $two] = reviewer('Bola', 'Eze');
    postReview($one, ['rating' => 5])->assertOk();
    postReview($two, ['rating' => 2])->assertOk();

    expect((float) $this->product->fresh()->rating_average)->toBe(3.5)->and($this->product->fresh()->rating_count)->toBe(2);

    http()->deleteJson('/api/v1/storefront/products/pistachio/reviews/mine', [], reviewHeaders($two))->assertOk();

    expect((float) $this->product->fresh()->rating_average)->toBe(5.0)->and($this->product->fresh()->rating_count)->toBe(1);
});

it('validates a review', function (array $data, string $field) {
    [, $token] = reviewer();

    postReview($token, $data)->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => [$field]]]);
})->with([
    'no rating' => [['rating' => null], 'rating'],
    'zero stars' => [['rating' => 0], 'rating'],
    'six stars' => [['rating' => 6], 'rating'],
    'fractional' => [['rating' => 4.5], 'rating'],
    'long title' => [['title' => str_repeat('x', 121)], 'title'],
    'long body' => [['body' => str_repeat('x', 2001)], 'body'],
]);

it('accepts a rating on its own', function () {
    [, $token] = reviewer();

    postReview($token, ['title' => '', 'body' => ''])->assertOk()->assertJsonPath('data.title', null)->assertJsonPath('data.body', null);
});

// ---- approval and the on/off switch ---------------------------------------------------------

it('holds reviews for approval when the store asks for it', function () {
    reviewSettings(['require_approval' => true]);
    [, $token] = reviewer();

    postReview($token)->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('message', 'Thanks! Your review will appear once the store approves it.');

    http()->getJson('/api/v1/storefront/products/pistachio/reviews', storefrontHeaders($this->a['tenant']))->assertJsonCount(0, 'data')->assertJsonPath('meta.summary.count', 0);
    expect($this->product->fresh()->rating_count)->toBe(0);

    $id = ProductReview::withoutTenantScope()->value('id');
    $this->actingAs($this->a['owner'], 'sanctum')->patchJson("/api/v1/reviews/{$id}", ['status' => 'published'])->assertOk();

    expect($this->product->fresh()->rating_count)->toBe(1);
    http()->getJson('/api/v1/storefront/products/pistachio/reviews', storefrontHeaders($this->a['tenant']))->assertJsonCount(1, 'data');
});

it('sends an edited published review back for approval', function () {
    [, $token] = reviewer();
    postReview($token)->assertJsonPath('data.status', 'published');

    reviewSettings(['require_approval' => true]);
    postReview($token, ['body' => 'Edited'])->assertJsonPath('data.status', 'pending');

    expect($this->product->fresh()->rating_count)->toBe(0);
});

it('switches reviews off for the whole store', function () {
    [, $token] = reviewer();
    postReview($token)->assertOk();
    reviewSettings(['enabled' => false]);

    postReview($token, ['rating' => 3])->assertStatus(409);
    http()->getJson('/api/v1/storefront/products/pistachio/reviews', storefrontHeaders($this->a['tenant']))->assertJsonCount(0, 'data');
    http()->getJson('/api/v1/storefront/products/pistachio/reviews/mine', reviewHeaders($token))->assertJsonPath('data.reason', 'reviews_off');
    http()->getJson('/api/v1/storefront/store', ['X-Storefront-Host' => 'icecream.mydomain.test'])->assertOk();
});

it('tells the storefront whether reviews are on', function () {
    Domain::factory()->subdomainOf($this->a['tenant'])->create();
    $host = ['X-Storefront-Host' => 'icecream.mydomain.test'];

    http()->getJson('/api/v1/storefront/store', $host)->assertJsonPath('data.settings.reviews.enabled', true);
    reviewSettings(['enabled' => false]);
    http()->getJson('/api/v1/storefront/store', $host)->assertJsonPath('data.settings.reviews.enabled', false);
});

// ---- reading reviews ------------------------------------------------------------------------

it('lists published reviews newest first with a rating summary and no private details', function () {
    foreach ([[5, 'published', 3], [4, 'published', 1], [1, 'hidden', 2], [2, 'pending', 2]] as [$rating, $status, $daysAgo]) {
        $review = ProductReview::factory()->forTenant($this->a['tenant'])->status($status)->create(['product_id' => $this->product->id, 'rating' => $rating, 'author_name' => "Reviewer {$rating}"]);
        ProductReview::withoutTenantScope()->whereKey($review->id)->update(['created_at' => now()->subDays($daysAgo)]);
    }
    app(TenantContext::class)->runAs($this->a['tenant'], fn () => app(ReviewService::class)->recalculate($this->product));

    $response = http()->getJson('/api/v1/storefront/products/pistachio/reviews', storefrontHeaders($this->a['tenant']))->assertOk();

    expect($response->json('data'))->toHaveCount(2)->and(collect($response->json('data'))->pluck('rating')->all())->toBe([4, 5])
        ->and($response->json('data.0'))->not->toHaveKeys(['customerId', 'customerEmail', 'email'])
        ->and($response->json('meta.summary'))->toBe(['average' => 4.5, 'count' => 2, 'distribution' => ['5' => 1, '4' => 1, '3' => 0, '2' => 0, '1' => 0]]);
});

it('paginates reviews ten at a time', function () {
    ProductReview::factory()->forTenant($this->a['tenant'])->count(12)->create(['product_id' => $this->product->id]);

    http()->getJson('/api/v1/storefront/products/pistachio/reviews', storefrontHeaders($this->a['tenant']))->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 12)->assertJsonPath('meta.lastPage', 2);
});

it('shows the rating on storefront products', function () {
    [, $token] = reviewer();
    postReview($token, ['rating' => 4])->assertOk();

    http()->getJson('/api/v1/storefront/products/pistachio', storefrontHeaders($this->a['tenant']))->assertJsonPath('data.ratingAverage', 4)->assertJsonPath('data.ratingCount', 1);
    http()->getJson('/api/v1/storefront/products', storefrontHeaders($this->a['tenant']))->assertJsonPath('data.0.ratingCount', 1);
});

it('keeps reviews inside the store', function () {
    $theirs = Product::factory()->forTenant($this->b['tenant'])->create(['slug' => 'pistachio']);
    ProductReview::factory()->forTenant($this->b['tenant'])->create(['product_id' => $theirs->id, 'rating' => 1]);
    ProductReview::factory()->forTenant($this->a['tenant'])->create(['product_id' => $this->product->id, 'rating' => 5]);

    $rows = http()->getJson('/api/v1/storefront/products/pistachio/reviews', storefrontHeaders($this->a['tenant']))->json('data');

    expect($rows)->toHaveCount(1)->and($rows[0]['rating'])->toBe(5);
    http()->getJson('/api/v1/storefront/products/ghost/reviews', storefrontHeaders($this->a['tenant']))->assertNotFound();
});

// ---- merchant moderation --------------------------------------------------------------------

it('lets the merchant filter, hide, answer and delete reviews', function () {
    $good = ProductReview::factory()->forTenant($this->a['tenant'])->create(['product_id' => $this->product->id, 'rating' => 5]);
    $bad = ProductReview::factory()->forTenant($this->a['tenant'])->create(['product_id' => $this->product->id, 'rating' => 1]);
    ProductReview::factory()->forTenant($this->a['tenant'])->status('pending')->create(['product_id' => $this->product->id, 'rating' => 3]);
    $this->actingAs($this->a['owner'], 'sanctum');

    $this->getJson('/api/v1/reviews')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.pending', 1)->assertJsonPath('data.0.product.name', 'Pistachio');
    $this->getJson('/api/v1/reviews?status=pending')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/reviews?rating=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $bad->id);
    $this->getJson('/api/v1/reviews?status=bogus')->assertUnprocessable();

    $this->patchJson("/api/v1/reviews/{$bad->id}", ['status' => 'hidden'])->assertOk()->assertJsonPath('data.status', 'hidden');
    expect($this->product->fresh()->rating_count)->toBe(1)->and((float) $this->product->fresh()->rating_average)->toBe(5.0);

    $this->patchJson("/api/v1/reviews/{$good->id}", ['reply' => 'Thank you!'])->assertOk()->assertJsonPath('data.reply', 'Thank you!')->assertJsonPath('data.status', 'published');
    $this->getJson('/api/v1/reviews?unanswered=1')->assertJsonCount(2, 'data');
    $this->patchJson("/api/v1/reviews/{$good->id}", ['reply' => ''])->assertJsonPath('data.reply', null)->assertJsonPath('data.repliedAt', null);
    $this->patchJson("/api/v1/reviews/{$good->id}", ['status' => 'banned'])->assertUnprocessable();

    $this->deleteJson("/api/v1/reviews/{$good->id}")->assertOk();
    expect($this->product->fresh()->rating_count)->toBe(0);
});

it('shows the answer to shoppers', function () {
    $review = ProductReview::factory()->forTenant($this->a['tenant'])->create(['product_id' => $this->product->id]);
    $this->actingAs($this->a['owner'], 'sanctum')->patchJson("/api/v1/reviews/{$review->id}", ['reply' => 'Glad you loved it'])->assertOk();

    http()->getJson('/api/v1/storefront/products/pistachio/reviews', storefrontHeaders($this->a['tenant']))->assertJsonPath('data.0.reply', 'Glad you loved it');
});

it('keeps moderation inside the store and behind sign-in', function () {
    $theirs = ProductReview::factory()->forTenant($this->b['tenant'])->create();
    $this->actingAs($this->a['owner'], 'sanctum');

    $this->getJson('/api/v1/reviews')->assertJsonCount(0, 'data');
    $this->patchJson("/api/v1/reviews/{$theirs->id}", ['status' => 'hidden'])->assertNotFound();
    $this->deleteJson("/api/v1/reviews/{$theirs->id}")->assertNotFound();
    http()->getJson('/api/v1/reviews')->assertUnauthorized();
});

it('validates the review settings', function () {
    $this->actingAs($this->a['owner'], 'sanctum')->putJson('/api/v1/store/settings', ['reviews' => ['enabled' => 'maybe']])->assertUnprocessable();
    $this->putJson('/api/v1/store/settings', ['reviews' => ['spam' => true]])->assertUnprocessable();
});

// ---- merchant alerts ------------------------------------------------------------------------

/** @return list<array{0: StoreMessageNotification, 1: string}> */
function reviewAlerts(): array
{
    $sent = [];

    foreach (Notification::sentNotifications()[AnonymousNotifiable::class] ?? [] as $byClass) {
        foreach ($byClass as $rows) {
            foreach ($rows as $row) {
                if ($row['notification'] instanceof StoreMessageNotification && $row['notification']->kind === 'merchant_review') {
                    $sent[] = [$row['notification'], $row['notifiable']->routes['mail']];
                }
            }
        }
    }

    return $sent;
}

it('tells the merchant about a new review', function () {
    [$customer, $token] = reviewer();

    postReview($token, ['rating' => 5, 'title' => 'Delicious', 'body' => 'Best ice cream in town.'])->assertOk();

    [[$alert, $to]] = reviewAlerts();
    expect($to)->toBe($this->a['owner']->email)
        ->and($alert->subject)->toBe('New 5-star review: Pistachio')
        ->and($alert->message['heading'])->toBe('You have a new review')
        ->and($alert->message['intro'])->toContain('Ada O. reviewed Pistachio')->not->toContain('low rating')
        ->and($alert->message['details'])->toContain(['Product', 'Pistachio'])->toContain(['Rating', '★★★★★  (5/5)'])->toContain(['Customer', "Ada O. · {$customer->email}"])->toContain(['Headline', 'Delicious'])
        ->and($alert->message['body'])->toBe(['Best ice cream in town.'])
        ->and($alert->message['button']['url'])->toEndWith('/dashboard/reviews');
});

it('flags a low rating so the merchant can reply', function () {
    [, $token] = reviewer();

    postReview($token, ['rating' => 1])->assertOk();

    expect(reviewAlerts()[0][0]->message['intro'])->toContain('low rating');
});

it('says a review is waiting when the store approves reviews first', function () {
    reviewSettings(['require_approval' => true]);
    [, $token] = reviewer();

    postReview($token)->assertOk();

    [[$alert]] = reviewAlerts();
    expect($alert->subject)->toBe('Review waiting for approval: Pistachio')->and($alert->message['heading'])->toBe('A review is waiting for you')
        ->and($alert->message['intro'])->toContain('will not appear')->and($alert->message['button']['url'])->toEndWith('/dashboard/reviews?status=pending');
});

it('stays quiet when a customer edits a live review but speaks up when an edit needs approval again', function () {
    [, $token] = reviewer();
    postReview($token, ['rating' => 5])->assertOk();
    postReview($token, ['rating' => 4, 'body' => 'Edited'])->assertOk();

    expect(reviewAlerts())->toHaveCount(1);

    reviewSettings(['require_approval' => true]);
    postReview($token, ['rating' => 3, 'body' => 'Edited again'])->assertOk();

    expect(reviewAlerts())->toHaveCount(2)->and(reviewAlerts()[1][0]->message['intro'])->toContain('edited their review');
});

it('respects the merchant\'s alert setting and chosen address', function () {
    [, $token] = reviewer();
    $this->actingAs($this->a['owner'], 'sanctum')->putJson('/api/v1/store/settings', ['notifications' => ['merchant_new_review' => false]])->assertOk();
    app('auth')->forgetGuards();

    postReview($token)->assertOk();
    expect(reviewAlerts())->toBeEmpty();

    $this->actingAs($this->a['owner'], 'sanctum')->putJson('/api/v1/store/settings', ['notifications' => ['merchant_new_review' => true, 'merchant_email' => 'reviews@icecream.test']])->assertOk();
    app('auth')->forgetGuards();
    [, $second] = reviewer('Bola', 'Eze');

    postReview($second)->assertOk();
    expect(reviewAlerts()[0][1])->toBe('reviews@icecream.test');
});

it('sends nothing for a review that was refused', function () {
    [, $token] = reviewer(payment: null);

    postReview($token)->assertForbidden();
    postReview($token, ['rating' => 9])->assertUnprocessable();

    expect(reviewAlerts())->toBeEmpty();
});

it('escapes what a customer wrote in the alert email', function () {
    [, $token] = reviewer();
    postReview($token, ['title' => '<b>Bold</b>', 'body' => '<script>alert(1)</script>'])->assertOk();

    $html = view('emails.branded', ['content' => reviewAlerts()[0][0]->message])->render();

    expect($html)->not->toContain('<script>alert')->toContain('&lt;script&gt;')->toContain('&lt;b&gt;Bold&lt;/b&gt;');
});
