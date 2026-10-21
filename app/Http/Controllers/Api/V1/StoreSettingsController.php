<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\SendTestEmailRequest;
use App\Http\Requests\Store\UpdateStoreSettingsRequest;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Store;
use App\Notifications\StoreMessageNotification;
use App\Services\Themes\StoreCustomizationService;
use App\Support\ApiResponse;
use App\Support\Notifications\AbandonedCartMessage;
use App\Support\Notifications\NotificationPreferences;
use App\Support\Notifications\ReviewDigestMessage;
use App\Support\Notifications\ReviewRequestMessage;
use App\Support\Notifications\StoreMailContext;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Notification;

#[Group('Merchant: Store', weight: 21)]
class StoreSettingsController extends Controller
{
    public function __construct(private readonly StoreCustomizationService $customization) {}

    public function show(): JsonResponse
    {
        $store = Store::query()->firstOrFail();
        $this->authorize('view', $store);

        return ApiResponse::success($this->customization->settingsWithDefaults($store));
    }

    /**
     * Email a sample of a customer email to the signed-in merchant (never to anyone else), built
     * from the same wording as the real one. The discount code in a sample does not exist.
     */
    public function sendTestEmail(SendTestEmailRequest $request): JsonResponse
    {
        $store = Store::query()->firstOrFail();
        $this->authorize('update', $store);

        $mail = StoreMailContext::current();
        $money = fn (int $minor) => StoreMailContext::money($minor, $store->currency);
        $products = Product::query()->where('status', ProductStatus::Active->value)->orderBy('created_at')->limit(2)->get();
        $samples = $products->isEmpty() ? collect([['Sample product', 250000]]) : $products->map(fn (Product $p) => [$p->name, (int) $p->price]);

        if ($request->validated('type') === 'review_digest') {
            $recent = ProductReview::query()->with('product')->where('created_at', '>=', now()->subDays(7))->latest('created_at')->limit(25)->get();
            $message = ReviewDigestMessage::build($mail, $recent->isNotEmpty() ? $recent : collect([(new ProductReview(['rating' => 5, 'title' => 'Sample review', 'author_name' => 'A customer', 'status' => 'published']))->setRelation('product', new Product(['name' => 'Sample product']))->forceFill(['created_at' => now()])]), 'This is a test sent only to you'.($recent->isEmpty() ? ', with a sample review because there were none this week.' : ', using your reviews from the past week.'));
            $to = $request->user()->email;
            Notification::route('mail', $to)->notify(new StoreMessageNotification('test_email', '[Test] '.$message['subject'], $message['content']));

            return ApiResponse::success(['sentTo' => $to], "Test email sent to {$to}.");
        }

        if ($request->validated('type') === 'review_request') {
            $message = ReviewRequestMessage::build(
                $mail,
                explode(' ', trim($request->user()->name))[0] ?? null,
                'ORD-SAMPLE',
                $products->isEmpty() ? [['Sample product', $mail['storeUrl']]] : $products->map(fn (Product $p) => [$p->name, $mail['storeUrl']])->all(),
                null,
                'This is a test sent only to you. Customers will see links to each product they bought.',
            );
            $to = $request->user()->email;
            Notification::route('mail', $to)->notify(new StoreMessageNotification('test_email', '[Test] How was your order?', $message));

            return ApiResponse::success(['sentTo' => $to], "Test email sent to {$to}.");
        }

        $isFollowUp = $request->validated('type') === 'abandoned_cart_followup';
        $percent = (int) ($request->validated('discount_percent') ?? NotificationPreferences::forCurrentTenant()->recoveryDiscountPercent());
        $code = $isFollowUp && $percent > 0 ? ['code' => 'BACK-SAMPLE', 'value' => $percent, 'ends_at' => now()->addDays(AbandonedCartMessage::CODE_VALID_DAYS)] : null;

        $message = AbandonedCartMessage::build(
            $mail,
            $isFollowUp,
            $code,
            $samples->map(fn (array $s) => ["1 × {$s[0]}", $money($s[1])])->all(),
            $money((int) $samples->sum(fn (array $s) => $s[1])),
            $mail['storeUrl'],
            null,
            'This is a test sent only to you. The items are samples'.($code ? ' and the discount code will not work.' : '.'),
        );

        $to = $request->user()->email;
        Notification::route('mail', $to)->notify(new StoreMessageNotification('test_email', '[Test] '.$message['subject'], $message['content']));

        return ApiResponse::success(['sentTo' => $to], "Test email sent to {$to}.");
    }

    public function update(UpdateStoreSettingsRequest $request): JsonResponse
    {
        $store = Store::query()->firstOrFail();
        $this->authorize('update', $store);

        $this->customization->updateSettings($store, $request->validated());

        return ApiResponse::success($this->customization->settingsWithDefaults($store), 'Settings updated.');
    }
}
