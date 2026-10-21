<?php

use App\Events\ProductReviewSubmitted;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Store;
use App\Models\User;
use App\Notifications\StoreMessageNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->product = Product::factory()->forTenant($this->a['tenant'])->create(['name' => 'Pistachio', 'slug' => 'pistachio']);
    // 08:30 in Lagos (UTC+1): past the send hour.
    $this->travelTo(Carbon::parse('2026-10-12 07:30:00', 'UTC'));
});

function digestOn(array $extra = []): void
{
    test()->actingAs(test()->a['owner'], 'sanctum')->putJson('/api/v1/store/settings', ['notifications' => $extra + ['merchant_review_digest' => true]])->assertOk();
    app('auth')->forgetGuards();
}

function newReview(int $rating = 5, string $status = 'published', ?Carbon $at = null, ?object $tenant = null, array $extra = []): ProductReview
{
    $tenant ??= test()->a['tenant'];
    $product = $tenant->is(test()->a['tenant']) ? test()->product : Product::factory()->forTenant($tenant)->create();
    $review = ProductReview::factory()->forTenant($tenant)->status($status)->create($extra + [
        'product_id' => $product->id, 'customer_id' => Customer::factory()->forTenant($tenant)->create()->id, 'rating' => $rating, 'author_name' => 'Ada O.', 'title' => "Rated {$rating}",
    ]);
    ProductReview::withoutTenantScope()->whereKey($review->id)->update(['created_at' => $at ?? now()->subHours(2)]);

    return $review;
}

/** @return list<array{0: StoreMessageNotification, 1: string}> */
function digests(): array
{
    $sent = [];

    foreach (Notification::sentNotifications()[AnonymousNotifiable::class] ?? [] as $byClass) {
        foreach ($byClass as $rows) {
            foreach ($rows as $row) {
                if ($row['notification'] instanceof StoreMessageNotification && $row['notification']->kind === 'review_digest') {
                    $sent[] = [$row['notification'], $row['notifiable']->routes['mail']];
                }
            }
        }
    }

    return $sent;
}

function runDigests(): void
{
    test()->artisan('reviews:send-digests')->assertSuccessful();
}

it('does nothing unless the store asked for the daily summary', function () {
    newReview();

    runDigests();

    expect(digests())->toBeEmpty();
});

it('sends one summary of the new reviews, with the numbers and the ones needing attention first', function () {
    digestOn();
    newReview(5);
    newReview(1, extra: ['title' => 'Awful']);
    newReview(4, 'pending', extra: ['title' => 'Nice']);

    runDigests();

    [[$digest, $to]] = digests();
    expect($to)->toBe($this->a['owner']->email)
        ->and($digest->subject)->toBe('Daily review summary: 3 new reviews · 1 need approval')
        ->and($digest->message['heading'])->toBe('Your daily review summary')
        ->and($digest->message['details'])->toBe([['New reviews', '3'], ['Average rating', '3.3 / 5'], ['Waiting for approval', '1'], ['Low ratings (1–2 stars)', '1']])
        ->and($digest->message['lines'][0][0])->toContain('Pistachio · Ada O.: “Nice”')->and($digest->message['lines'][0][1])->toBe('needs approval')
        ->and($digest->message['lines'][1][0])->toContain('★☆☆☆☆')
        ->and($digest->message['button']['url'])->toEndWith('/dashboard/reviews?status=pending');
});

it('points the button at the plain list when nothing needs approval, and says review in the singular', function () {
    digestOn();
    newReview(5);

    runDigests();

    expect(digests()[0][0]->subject)->toBe('Daily review summary: 1 new review')->and(digests()[0][0]->message['button']['url'])->toEndWith('/dashboard/reviews');
});

it('waits for the morning in the store\'s own time zone', function () {
    digestOn();
    newReview();
    $this->travelTo(Carbon::parse('2026-10-12 06:30:00', 'UTC')); // 07:30 in Lagos

    runDigests();
    expect(digests())->toBeEmpty();

    $this->travelTo(Carbon::parse('2026-10-12 07:00:00', 'UTC')); // 08:00 in Lagos
    runDigests();
    expect(digests())->toHaveCount(1);
});

it('follows a store that sets a different time zone', function () {
    digestOn();
    Store::withoutTenantScope()->where('tenant_id', $this->a['tenant']->id)->update(['timezone' => 'America/New_York']);
    newReview();

    runDigests(); // 03:30 in New York
    expect(digests())->toBeEmpty();

    $this->travelTo(Carbon::parse('2026-10-12 12:30:00', 'UTC')); // 08:30 in New York
    runDigests();
    expect(digests())->toHaveCount(1);
});

it('sends at most one a day and only covers reviews since the last one', function () {
    digestOn();
    newReview(5, extra: ['title' => 'First day']);

    runDigests();
    runDigests();
    expect(digests())->toHaveCount(1);

    $this->travel(30)->minutes();
    newReview(4, at: now(), extra: ['title' => 'Later today']);
    runDigests();
    expect(digests())->toHaveCount(1);

    $this->travelTo(Carbon::parse('2026-10-13 07:30:00', 'UTC'));
    newReview(3, at: now()->subHour(), extra: ['title' => 'Next day']);
    runDigests();

    expect(digests())->toHaveCount(2)->and(digests()[1][0]->message['details'][0])->toBe(['New reviews', '2'])
        ->and(collect(digests()[1][0]->message['lines'])->pluck(0)->implode(' '))->toContain('Later today')->toContain('Next day')->not->toContain('First day');
});

it('stays quiet on a day with no reviews and starts the next summary from there', function () {
    digestOn();
    newReview(5, at: now()->subDays(3)); // earlier than the first summary's window, so there is nothing new
    Store::withoutTenantScope()->where('tenant_id', $this->a['tenant']->id)->update(['review_digest_checked_at' => now()->subDay()]);

    runDigests();
    expect(digests())->toBeEmpty()->and(Store::withoutTenantScope()->where('tenant_id', $this->a['tenant']->id)->first()->review_digest_checked_at)->not->toBeNull();

    $this->travelTo(Carbon::parse('2026-10-13 07:30:00', 'UTC'));
    newReview(5, at: now()->subHours(3));
    runDigests();

    expect(digests())->toHaveCount(1)->and(digests()[0][0]->message['details'][0])->toBe(['New reviews', '1']);
});

it('lists the first ten reviews and counts the rest', function () {
    digestOn();
    foreach (range(1, 13) as $i) {
        newReview(5, extra: ['title' => "Review {$i}"]);
    }

    runDigests();

    $lines = digests()[0][0]->message['lines'];
    expect($lines)->toHaveCount(11)->and($lines[10][0])->toBe('…and 3 more')->and(digests()[0][0]->message['details'][0])->toBe(['New reviews', '13']);
});

it('replaces the email for each review while it is on', function () {
    [$customer] = [Customer::factory()->forTenant($this->a['tenant'])->create()];
    $order = null;
    digestOn();
    $this->actingAs($this->a['owner'], 'sanctum');

    event(new ProductReviewSubmitted($this->a['tenant']->id, newReview()->id, true));

    $eachAlert = collect(Notification::sentNotifications()[AnonymousNotifiable::class] ?? [])->flatten(2)->filter(fn ($row) => $row['notification'] instanceof StoreMessageNotification && $row['notification']->kind === 'merchant_review');
    expect($eachAlert)->toBeEmpty();

    digestOn(['merchant_review_digest' => false]);
    event(new ProductReviewSubmitted($this->a['tenant']->id, newReview()->id, true));
    $eachAlert = collect(Notification::sentNotifications()[AnonymousNotifiable::class] ?? [])->flatten(2)->filter(fn ($row) => $row['notification'] instanceof StoreMessageNotification && $row['notification']->kind === 'merchant_review');
    expect($eachAlert)->toHaveCount(1);
});

it('needs review alerts to be on, and honours the alert address', function () {
    digestOn(['merchant_new_review' => false]);
    newReview();
    runDigests();
    expect(digests())->toBeEmpty();

    digestOn(['merchant_new_review' => true, 'merchant_email' => 'reviews@icecream.test']);
    Store::withoutTenantScope()->where('tenant_id', $this->a['tenant']->id)->update(['review_digest_checked_at' => null]);
    runDigests();
    expect(digests()[0][1])->toBe('reviews@icecream.test');
});

it('never mixes in another store\'s reviews or sends for an unverified owner', function () {
    digestOn();
    newReview(1, tenant: $this->b['tenant']);

    runDigests();
    expect(digests())->toBeEmpty();

    newReview();
    User::query()->whereKey($this->a['owner']->id)->update(['email_verified_at' => null]);
    config(['tenancy.require_verified_email_to_sell' => true]);
    runDigests();
    expect(digests())->toBeEmpty();
});

it('emails the merchant a test of the summary and validates the new setting', function () {
    newReview(2, extra: ['title' => 'Too sweet']);
    $this->actingAs($this->a['owner'], 'sanctum')->postJson('/api/v1/store/settings/test-email', ['type' => 'review_digest', 'email' => 'victim@mail.test'])
        ->assertOk()->assertJsonPath('data.sentTo', $this->a['owner']->email);

    $sent = collect(Notification::sentNotifications()[AnonymousNotifiable::class] ?? [])->flatten(2)->first();
    expect($sent['notifiable']->routes['mail'])->toBe($this->a['owner']->email)->and($sent['notification']->subject)->toStartWith('[Test] Daily review summary')
        ->and($sent['notification']->message['footer'])->toContain('your reviews from the past week');

    $this->putJson('/api/v1/store/settings', ['notifications' => ['merchant_review_digest' => 'sometimes']])->assertUnprocessable();
});

it('escapes what customers wrote in the summary', function () {
    digestOn();
    newReview(5, extra: ['title' => '<script>alert(1)</script>', 'author_name' => '<b>Eve</b>']);

    runDigests();

    $html = view('emails.branded', ['content' => digests()[0][0]->message])->render();
    expect($html)->not->toContain('<script>alert')->toContain('&lt;script&gt;')->toContain('&lt;b&gt;Eve&lt;/b&gt;');
});
