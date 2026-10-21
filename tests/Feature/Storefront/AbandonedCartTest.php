<?php

use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\EmailSuppression;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Notifications\StoreMessageNotification;
use App\Support\Notifications\UnsubscribeLink;
use App\Support\Tenancy\TenantContext;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mime\Email;

beforeEach(function () {
    Notification::fake();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->h = storefrontHeaders($this->a['tenant']);
    $this->product = Product::factory()->forTenant($this->a['tenant'])->create(['price' => 150000, 'stock' => 5, 'name' => 'Pistachio']);
});

function remindersOn(array $extra = []): void
{
    test()->actingAs(test()->a['owner'], 'sanctum')
        ->putJson('/api/v1/store/settings', ['notifications' => ['abandoned_cart_reminder' => true] + $extra])->assertOk();
    app('auth')->forgetGuards();
}

/** A cart left $hours ago by a shopper whose email we know. */
function abandonedCart(int $hours = 5, ?string $email = 'shopper@mail.test'): Cart
{
    $cart = makeCart(test()->a['tenant'], [[test()->product, 2]]);
    $cart->forceFill(['email' => $email])->saveQuietly();
    Cart::withoutTenantScope()->whereKey($cart->getKey())->update(['updated_at' => now()->subHours($hours)]);

    return $cart->fresh();
}

/** @return list<array{0: StoreMessageNotification, 1: string}> */
function reminders(): array
{
    $sent = [];

    foreach (Notification::sentNotifications()[AnonymousNotifiable::class] ?? [] as $byClass) {
        foreach ($byClass as $rows) {
            foreach ($rows as $row) {
                if ($row['notification'] instanceof StoreMessageNotification && $row['notification']->kind === 'abandoned_cart') {
                    $sent[] = [$row['notification'], $row['notifiable']->routes['mail']];
                }
            }
        }
    }

    return $sent;
}

it('saves the shopper email on their cart and lower-cases it', function () {
    $cart = makeCart($this->a['tenant'], [[$this->product, 1]]);

    http()->putJson("/api/v1/storefront/cart/{$cart->getKey()}/contact", ['email' => 'Shopper@Mail.Test'], $this->h)->assertOk();

    expect(Cart::withoutTenantScope()->find($cart->getKey())->email)->toBe('shopper@mail.test');
});

it('rejects a bad email and a customer cart that belongs to someone else', function () {
    $cart = makeCart($this->a['tenant'], [[$this->product, 1]]);
    http()->putJson("/api/v1/storefront/cart/{$cart->getKey()}/contact", ['email' => 'nope'], $this->h)->assertUnprocessable();

    $customer = Customer::factory()->forTenant($this->a['tenant'])->create();
    $owned = makeCart($this->a['tenant'], [[$this->product, 1]], $customer);
    http()->putJson("/api/v1/storefront/cart/{$owned->getKey()}/contact", ['email' => 'x@mail.test'], $this->h)->assertForbidden();
});

it('emails a reminder with a recovery link once the delay has passed', function () {
    remindersOn();
    $cart = abandonedCart(5);

    $this->artisan('carts:send-abandoned-reminders')->assertSuccessful();

    [[$notification, $to]] = reminders();
    expect($to)->toBe('shopper@mail.test')
        ->and($notification->message['button']['url'])->toEndWith("/cart/recover/{$cart->getKey()}")
        ->and($notification->message['lines'][0][0])->toBe('2 × Pistachio')
        ->and($cart->fresh()->reminder_sent_at)->not->toBeNull();
});

it('sends only one reminder per cart', function () {
    remindersOn();
    abandonedCart(5);

    $this->artisan('carts:send-abandoned-reminders');
    $this->artisan('carts:send-abandoned-reminders');

    expect(reminders())->toHaveCount(1);
});

it('does nothing while the store has reminders switched off', function () {
    abandonedCart(5);

    $this->artisan('carts:send-abandoned-reminders')->assertSuccessful();

    expect(reminders())->toBeEmpty();
});

it('waits for the store chosen delay and skips carts that are too old', function () {
    remindersOn(['abandoned_cart_delay_hours' => 6]);
    abandonedCart(5);
    abandonedCart(24 * 8, 'old@mail.test');

    $this->artisan('carts:send-abandoned-reminders');

    expect(reminders())->toBeEmpty();
});

it('skips carts without an email, converted carts and shoppers who already ordered', function () {
    remindersOn();
    abandonedCart(5, null);
    abandonedCart(5, 'done@mail.test')->forceFill(['converted_at' => now()])->saveQuietly();
    abandonedCart(5, 'bought@mail.test');
    app(TenantContext::class)->runAs($this->a['tenant'], fn () => Order::factory()->forTenant($this->a['tenant'])->create(['customer_email' => 'bought@mail.test']));

    $this->artisan('carts:send-abandoned-reminders');

    expect(reminders())->toBeEmpty();
});

it('reminds logged-in customers at their account email and leaves out sold-out lines', function () {
    remindersOn();
    $customer = Customer::factory()->forTenant($this->a['tenant'])->create(['email' => 'member@mail.test']);
    $soldOut = Product::factory()->forTenant($this->a['tenant'])->create(['stock' => 3, 'name' => 'Gone']);
    $cart = makeCart($this->a['tenant'], [[$this->product, 1], [$soldOut, 2]], $customer);
    $soldOut->update(['stock' => 0]);
    Cart::withoutTenantScope()->whereKey($cart->getKey())->update(['updated_at' => now()->subHours(5)]);

    $this->artisan('carts:send-abandoned-reminders');

    [[$notification, $to]] = reminders();
    expect($to)->toBe('member@mail.test')->and($notification->message['lines'])->toHaveCount(1);
});

it('never reminds another store\'s shoppers', function () {
    remindersOn();
    $other = makeCart($this->b['tenant'], [[makeProduct($this->b['tenant'], ['stock' => 3]), 1]]);
    $other->forceFill(['email' => 'theirs@mail.test'])->saveQuietly();
    Cart::withoutTenantScope()->whereKey($other->getKey())->update(['updated_at' => now()->subHours(9)]);

    $this->artisan('carts:send-abandoned-reminders');

    expect(reminders())->toBeEmpty();
});

it('validates the reminder settings', function () {
    $this->actingAs($this->a['owner'], 'sanctum')
        ->putJson('/api/v1/store/settings', ['notifications' => ['abandoned_cart_delay_hours' => 0]])->assertUnprocessable();
});

it('renders the plain-text version of the reminder without HTML escaping', function () {
    $product = Product::factory()->forTenant($this->a['tenant'])->create(['name' => "Mum's Cookies & Cream", 'stock' => 5]);
    $line = ["1 × {$product->name}", '1.00 NGN'];

    $text = view('emails.branded-text', ['content' => ['store' => 'Scoops', 'heading' => 'Hi', 'lines' => [$line]]])->render();

    expect($text)->toContain("Mum's Cookies & Cream")->not->toContain('&amp;')->not->toContain('&#039;');
});

it('puts a one-click unsubscribe link and headers on every reminder', function () {
    remindersOn();
    abandonedCart(5, 'shopper@mail.test');

    $this->artisan('carts:send-abandoned-reminders');

    [[$notification]] = reminders();
    $url = $notification->message['unsubscribe_url'];
    expect($url)->toContain("/api/v1/unsubscribe/{$this->a['tenant']->id}")->toContain('email=shopper%40mail.test')->toContain('token=');

    $mail = $notification->toMail(new AnonymousNotifiable);
    $headers = (function () use ($mail) {
        $message = new Email;
        foreach ($mail->callbacks as $callback) {
            $callback($message);
        }

        return $message->getHeaders();
    })();

    expect($headers->get('List-Unsubscribe')->getBodyAsString())->toBe("<{$url}>")
        ->and($headers->get('List-Unsubscribe-Post')->getBodyAsString())->toBe('List-Unsubscribe=One-Click');
    expect(view('emails.branded', ['content' => $notification->message])->render())->toContain('Unsubscribe from these emails');
    expect(view('emails.branded-text', ['content' => $notification->message])->render())->toContain($url);
});

function unsubscribeParams(string $email = 'shopper@mail.test', ?string $tenantId = null): array
{
    $tenantId ??= test()->a['tenant']->id;
    parse_str(parse_url(UnsubscribeLink::url($tenantId, $email), PHP_URL_QUERY), $query);

    return $query;
}

it('shows a confirmation page and changes nothing until the button is pressed', function () {
    $query = http_build_query(unsubscribeParams());

    http()->get("/api/v1/unsubscribe/{$this->a['tenant']->id}?{$query}")->assertOk()
        ->assertSee('Unsubscribe from reminder emails?')->assertSee('s••••••@mail.test', false);

    expect(EmailSuppression::withoutTenantScope()->count())->toBe(0);
});

it('unsubscribes with the confirmation button or a mail app one-click post, and stops reminders', function () {
    remindersOn();
    abandonedCart(5, 'shopper@mail.test');
    $params = unsubscribeParams();

    http()->post("/api/v1/unsubscribe/{$this->a['tenant']->id}", $params)->assertOk()->assertSee('unsubscribed');
    http()->post("/api/v1/unsubscribe/{$this->a['tenant']->id}?".http_build_query($params), ['List-Unsubscribe' => 'One-Click'])->assertOk();

    expect(EmailSuppression::withoutTenantScope()->where('email', 'shopper@mail.test')->count())->toBe(1);

    $this->artisan('carts:send-abandoned-reminders');
    expect(reminders())->toBeEmpty();
});

it('rejects forged or edited unsubscribe links', function () {
    $params = unsubscribeParams();

    http()->post("/api/v1/unsubscribe/{$this->a['tenant']->id}", ['token' => 'nope'] + $params)->assertForbidden();
    http()->post("/api/v1/unsubscribe/{$this->a['tenant']->id}", ['email' => 'someone.else@mail.test'] + $params)->assertForbidden();
    http()->post("/api/v1/unsubscribe/{$this->b['tenant']->id}", $params)->assertForbidden();
    http()->get("/api/v1/unsubscribe/{$this->a['tenant']->id}?email=shopper@mail.test")->assertForbidden();

    expect(EmailSuppression::withoutTenantScope()->count())->toBe(0);
});

it('only silences the store the shopper unsubscribed from', function () {
    remindersOn();
    http()->post("/api/v1/unsubscribe/{$this->b['tenant']->id}", unsubscribeParams('shopper@mail.test', $this->b['tenant']->id))->assertOk();

    abandonedCart(5, 'shopper@mail.test');
    $this->artisan('carts:send-abandoned-reminders');

    expect(reminders())->toHaveCount(1);
});

function followUpsOn(array $extra = []): void
{
    remindersOn($extra + ['abandoned_cart_second_reminder' => true, 'abandoned_cart_second_delay_hours' => 24, 'abandoned_cart_discount_percent' => 15]);
}

/** Send the first reminder, then make it look like it went out $hours ago with no activity since. */
function firstReminderSent(int $hours = 25): Cart
{
    $cart = abandonedCart(5);
    test()->artisan('carts:send-abandoned-reminders');
    Cart::withoutTenantScope()->whereKey($cart->getKey())->update(['updated_at' => now()->subHours($hours), 'reminder_sent_at' => now()->subHours($hours), 'last_reminder_at' => now()->subHours($hours)]);

    return $cart->fresh();
}

it('does not send a follow-up unless the store switched it on', function () {
    remindersOn();
    firstReminderSent();

    $this->artisan('carts:send-abandoned-reminders');

    expect(reminders())->toHaveCount(1);
});

it('sends a follow-up with a single-use code for this cart only', function () {
    followUpsOn();
    $cart = firstReminderSent();

    $this->artisan('carts:send-abandoned-reminders');

    [, [$notification, $to]] = reminders();
    $code = $cart->fresh()->recovery_code;
    $discount = Discount::withoutTenantScope()->where('code', $code)->firstOrFail();

    expect($to)->toBe('shopper@mail.test')
        ->and($code)->toStartWith('BACK-')
        ->and($discount->value)->toBe(15)->and($discount->max_uses)->toBe(1)->and($discount->tenant_id)->toBe($this->a['tenant']->id)
        ->and($discount->ends_at->isBetween(now()->addDays(6), now()->addDays(8)))->toBeTrue()
        ->and($notification->subject)->toBe('15% off your cart, just for you')
        ->and($notification->message['details'][0])->toBe(['Your code', $code])
        ->and($notification->message['button']['url'])->toEndWith("/cart/recover/{$cart->getKey()}?code={$code}")
        ->and($cart->fresh()->second_reminder_sent_at)->not->toBeNull();
});

it('sends the follow-up without a code when the discount is 0', function () {
    followUpsOn(['abandoned_cart_discount_percent' => 0]);
    firstReminderSent();

    $this->artisan('carts:send-abandoned-reminders');

    [, [$notification]] = reminders();
    expect($notification->message['details'])->toBe([])->and($notification->subject)->toBe('Still interested?')
        ->and($notification->message['button']['url'])->not->toContain('?code=')
        ->and(Discount::withoutTenantScope()->count())->toBe(0);
});

it('waits for the follow-up delay, restarts it on new activity and sends it only once', function () {
    followUpsOn();
    firstReminderSent(10);
    $this->artisan('carts:send-abandoned-reminders');
    expect(reminders())->toHaveCount(1);

    Cart::withoutTenantScope()->update(['updated_at' => now()->subHours(25), 'reminder_sent_at' => now()->subHours(25)]);
    $this->artisan('carts:send-abandoned-reminders');
    $this->artisan('carts:send-abandoned-reminders');
    expect(reminders())->toHaveCount(2);
});

it('holds the follow-up back while the shopper is active again', function () {
    followUpsOn();
    $cart = firstReminderSent();
    Cart::withoutTenantScope()->whereKey($cart->getKey())->update(['updated_at' => now()->subHours(2)]);

    $this->artisan('carts:send-abandoned-reminders');

    expect(reminders())->toHaveCount(1);
});

it('drops the follow-up if they bought, unsubscribed or checked out meanwhile', function () {
    followUpsOn();
    $bought = firstReminderSent();
    app(TenantContext::class)->runAs($this->a['tenant'], fn () => Order::factory()->forTenant($this->a['tenant'])->create(['customer_email' => 'shopper@mail.test']));

    $this->artisan('carts:send-abandoned-reminders');
    expect(reminders())->toHaveCount(1);

    Order::withoutTenantScope()->delete();
    EmailSuppression::factory()->forTenant($this->a['tenant'])->create(['email' => 'shopper@mail.test']);
    $this->artisan('carts:send-abandoned-reminders');
    expect(reminders())->toHaveCount(1);

    EmailSuppression::withoutTenantScope()->delete();
    $bought->forceFill(['converted_at' => now()])->saveQuietly();
    $this->artisan('carts:send-abandoned-reminders');
    expect(reminders())->toHaveCount(1);
});

it('starts both reminders over when the shopper gives a new email', function () {
    followUpsOn();
    $cart = firstReminderSent();
    Cart::withoutTenantScope()->whereKey($cart->getKey())->update(['second_reminder_sent_at' => now()]);

    http()->putJson("/api/v1/storefront/cart/{$cart->getKey()}/contact", ['email' => 'new@mail.test'], $this->h)->assertOk();

    expect($cart->fresh()->reminder_sent_at)->toBeNull()->and($cart->fresh()->second_reminder_sent_at)->toBeNull();
});

it('lets the shopper use the follow-up code once, and only that once', function () {
    followUpsOn();
    $cart = firstReminderSent();
    $this->artisan('carts:send-abandoned-reminders');
    $code = $cart->fresh()->recovery_code;

    checkout($this->a['tenant'], $cart, ['discount_code' => $code], key: 'back-code-0001')->assertCreated()
        ->assertJsonPath('data.discountCode', $code)->assertJsonPath('data.discountTotal', 45000)->assertJsonPath('data.total', 255000);

    $other = makeCart($this->a['tenant'], [[$this->product, 2]]);
    checkout($this->a['tenant'], $other, ['discount_code' => $code], key: 'back-code-0002')->assertUnprocessable();
});

it('validates the follow-up settings', function (array $notifications) {
    $this->actingAs($this->a['owner'], 'sanctum')->putJson('/api/v1/store/settings', ['notifications' => $notifications])->assertUnprocessable();
})->with([
    'delay too short' => [['abandoned_cart_second_delay_hours' => 0]],
    'delay too long' => [['abandoned_cart_second_delay_hours' => 169]],
    'discount too big' => [['abandoned_cart_discount_percent' => 51]],
    'negative discount' => [['abandoned_cart_discount_percent' => -1]],
]);

it('records which reminder went out last so recoveries can be credited', function () {
    followUpsOn();
    $cart = firstReminderSent();
    expect($cart->last_reminder_stage)->toBe('first')->and($cart->last_reminder_at)->not->toBeNull();

    $this->artisan('carts:send-abandoned-reminders');

    $fresh = $cart->fresh();
    expect($fresh->last_reminder_stage)->toBe('second')->and($fresh->last_reminder_at->isAfter($cart->last_reminder_at))->toBeTrue();
});

/** @return list<array{0: StoreMessageNotification, 1: string}> */
function testEmails(): array
{
    $sent = [];

    foreach (Notification::sentNotifications()[AnonymousNotifiable::class] ?? [] as $byClass) {
        foreach ($byClass as $rows) {
            foreach ($rows as $row) {
                if ($row['notification'] instanceof StoreMessageNotification && $row['notification']->kind === 'test_email') {
                    $sent[] = [$row['notification'], $row['notifiable']->routes['mail']];
                }
            }
        }
    }

    return $sent;
}

function sendTest(string $type = 'abandoned_cart_first', array $extra = [])
{
    return test()->actingAs(test()->a['owner'], 'sanctum')->postJson('/api/v1/store/settings/test-email', ['type' => $type] + $extra);
}

it('emails the merchant a test of the first reminder, built from their real products', function () {
    sendTest()->assertOk()->assertJsonPath('data.sentTo', $this->a['owner']->email);

    [[$notification, $to]] = testEmails();
    expect($to)->toBe($this->a['owner']->email)
        ->and($notification->subject)->toBe('[Test] You left something behind')
        ->and($notification->message['lines'][0][0])->toBe('1 × Pistachio')
        ->and($notification->message['unsubscribe_url'])->toBeNull()
        ->and($notification->message['footer'])->toContain('test sent only to you')
        ->and($notification->message['button']['url'])->not->toContain('recover');
});

it('previews the follow-up with a sample code that is not real, using the percentage on screen', function () {
    remindersOn(['abandoned_cart_discount_percent' => 10]);

    sendTest('abandoned_cart_followup')->assertOk();
    sendTest('abandoned_cart_followup', ['discount_percent' => 35])->assertOk();

    [[$saved], [$typed]] = testEmails();
    expect($saved->subject)->toBe('[Test] 10% off your cart, just for you')
        ->and($typed->subject)->toBe('[Test] 35% off your cart, just for you')
        ->and($typed->message['details'][0])->toBe(['Your code', 'BACK-SAMPLE'])
        ->and($typed->message['footer'])->toContain('will not work')
        ->and(Discount::withoutTenantScope()->count())->toBe(0);
});

it('previews a follow-up without a code when the discount is 0', function () {
    sendTest('abandoned_cart_followup', ['discount_percent' => 0])->assertOk();

    [[$notification]] = testEmails();
    expect($notification->subject)->toBe('[Test] Still interested?')->and($notification->message['details'])->toBe([]);
});

it('falls back to a sample product in a store with no products', function () {
    Product::withoutTenantScope()->delete();

    sendTest()->assertOk();

    expect(testEmails()[0][0]->message['lines'][0][0])->toBe('1 × Sample product');
});

it('only ever sends the test to the signed-in merchant', function () {
    sendTest('abandoned_cart_first', ['email' => 'victim@mail.test', 'to' => 'victim@mail.test'])->assertOk();

    expect(testEmails())->toHaveCount(1)->and(testEmails()[0][1])->toBe($this->a['owner']->email);
});

it('validates the test email request and who may send it', function () {
    sendTest('weekly_digest')->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['type']]]);
    sendTest('abandoned_cart_followup', ['discount_percent' => 51])->assertUnprocessable();

    $staff = User::factory()->forTenant($this->a['tenant'], UserRole::MerchantStaff)->create();
    $this->actingAs($staff, 'sanctum')->postJson('/api/v1/store/settings/test-email', ['type' => 'abandoned_cart_first'])->assertForbidden();
    http()->postJson('/api/v1/store/settings/test-email', ['type' => 'abandoned_cart_first'])->assertUnauthorized();

    expect(testEmails())->toBeEmpty();
});

it('limits how fast test emails can be sent', function () {
    foreach (range(1, 6) as $i) {
        sendTest()->assertOk();
    }

    sendTest()->assertStatus(429);
});

it('marks the code in a follow-up as an automatic recovery code', function () {
    followUpsOn();
    $cart = firstReminderSent();

    $this->artisan('carts:send-abandoned-reminders');

    expect(Discount::withoutTenantScope()->where('code', $cart->fresh()->recovery_code)->value('source'))->toBe('cart_recovery');
});
