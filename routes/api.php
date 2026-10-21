<?php

use App\Http\Controllers\Api\V1\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Api\V1\Admin\DomainController as AdminDomainController;
use App\Http\Controllers\Api\V1\Admin\OverviewController as AdminOverviewController;
use App\Http\Controllers\Api\V1\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Api\V1\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Api\V1\Admin\StoreController as AdminStoreController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionController as AdminSubscriptionController;
use App\Http\Controllers\Api\V1\Admin\TenantController;
use App\Http\Controllers\Api\V1\Admin\ThemeController as AdminThemeController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Billing\BillingWebhookController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\CampaignSeriesController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerSegmentController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DiscountController;
use App\Http\Controllers\Api\V1\DomainController;
use App\Http\Controllers\Api\V1\EmailCampaignController;
use App\Http\Controllers\Api\V1\EmailTrackingController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\Payments\PaymentController;
use App\Http\Controllers\Api\V1\Payments\PaymentGatewayController;
use App\Http\Controllers\Api\V1\Payments\RefundController;
use App\Http\Controllers\Api\V1\Payments\WebhookController;
use App\Http\Controllers\Api\V1\PlatformController;
use App\Http\Controllers\Api\V1\PosController;
use App\Http\Controllers\Api\V1\PosShiftController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductInventoryController;
use App\Http\Controllers\Api\V1\ProductReviewController;
use App\Http\Controllers\Api\V1\ShippingLocationsController;
use App\Http\Controllers\Api\V1\ShippingZoneController;
use App\Http\Controllers\Api\V1\StoreBrandingController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\Storefront\AccountController;
use App\Http\Controllers\Api\V1\Storefront\CartController;
use App\Http\Controllers\Api\V1\Storefront\CatalogController;
use App\Http\Controllers\Api\V1\Storefront\CheckoutController;
use App\Http\Controllers\Api\V1\Storefront\CustomerAuthController;
use App\Http\Controllers\Api\V1\Storefront\NewsletterController;
use App\Http\Controllers\Api\V1\Storefront\OrderTrackingController;
use App\Http\Controllers\Api\V1\Storefront\ReviewController as StorefrontReviewController;
use App\Http\Controllers\Api\V1\Storefront\ShippingController as StorefrontShippingController;
use App\Http\Controllers\Api\V1\Storefront\StockAlertController;
use App\Http\Controllers\Api\V1\Storefront\StorefrontController;
use App\Http\Controllers\Api\V1\StorePageController;
use App\Http\Controllers\Api\V1\StoreSettingsController;
use App\Http\Controllers\Api\V1\StoreThemeController;
use App\Http\Controllers\Api\V1\ThemeController;
use App\Http\Controllers\Api\V1\UnsubscribeController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {

    // Uptime/monitoring probe (database, cache, scheduler and queue worker); see config/monitoring.php
    Route::get('health', HealthController::class)->middleware('throttle:60,1')->name('health');

    // Public platform info for the marketing site (no tenant, no auth)
    Route::get('plans', [PlatformController::class, 'plans'])->middleware('throttle:60,1')->name('plans');

    // Authentication (tenant-agnostic)
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::middleware('throttle:auth')->group(function (): void {
            Route::post('register', [AuthController::class, 'register'])->name('register');
            Route::post('login', [AuthController::class, 'login'])->name('login');
            Route::post('forgot-password', [PasswordController::class, 'forgot'])->name('password.forgot');
            Route::post('reset-password', [PasswordController::class, 'reset'])->name('password.reset');
        });

        Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verification.verify');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::post('email/resend', [EmailVerificationController::class, 'resend'])
                ->middleware('throttle:6,1')->name('verification.resend');
        });
    });

    // Public storefront: tenant resolved from the hostname
    Route::prefix('storefront')->name('storefront.')->middleware('tenant.host')->group(function (): void {
        Route::get('store', [StorefrontController::class, 'show'])->name('store');
        Route::get('categories', [CatalogController::class, 'categories'])->name('categories');
        Route::get('pages/{slug}', [StorefrontController::class, 'page'])->name('pages.show');
        Route::get('products', [CatalogController::class, 'products'])->name('products.index');
        Route::get('products/{slug}', [CatalogController::class, 'product'])->name('products.show');
        Route::get('products/{slug}/related', [CatalogController::class, 'related'])->middleware('throttle:120,1')->name('products.related');
        Route::post('products/{slug}/stock-alerts', [StockAlertController::class, 'store'])->middleware('throttle:10,1')->name('stock-alerts.store');
        Route::get('products/{slug}/reviews', [StorefrontReviewController::class, 'index'])->middleware('throttle:120,1')->name('reviews.index');

        Route::post('cart', [CartController::class, 'store'])->name('cart.store');
        Route::get('cart/{cart}', [CartController::class, 'show'])->name('cart.show');
        Route::put('cart/{cart}/contact', [CartController::class, 'contact'])->name('cart.contact');
        Route::post('cart/{cart}/discount', [CartController::class, 'previewDiscount'])->middleware('throttle:30,1')->name('cart.discount');
        Route::post('cart/{cart}/items', [CartController::class, 'addItem'])->name('cart.items.store');
        Route::patch('cart/{cart}/items/{item}', [CartController::class, 'updateItem'])->name('cart.items.update');
        Route::delete('cart/{cart}/items/{item}', [CartController::class, 'removeItem'])->name('cart.items.destroy');

        Route::post('checkout', [CheckoutController::class, 'store'])->middleware('idempotent:checkout')->name('checkout');
        Route::post('payments/initialize', [PaymentController::class, 'initialize'])->middleware(['throttle:20,1', 'idempotent:payment-init'])->name('payments.initialize');
        Route::get('payments/{reference}/verify', [PaymentController::class, 'verify'])->middleware('throttle:60,1')->name('payments.verify');
        Route::post('newsletter', [NewsletterController::class, 'store'])->middleware('throttle:10,1')->name('newsletter');
        Route::post('orders/track', [OrderTrackingController::class, 'show'])->middleware('throttle:20,1')->name('orders.track');
        Route::get('shipping/locations', [StorefrontShippingController::class, 'locations'])->name('shipping.locations');
        Route::post('shipping/options', [StorefrontShippingController::class, 'options'])->middleware('throttle:60,1')->name('shipping.options');

        Route::prefix('auth')->name('auth.')->group(function (): void {
            Route::post('register', [CustomerAuthController::class, 'register'])->middleware('throttle:auth')->name('register');
            Route::post('login', [CustomerAuthController::class, 'login'])->middleware('throttle:auth')->name('login');
            Route::post('forgot-password', [CustomerAuthController::class, 'forgotPassword'])->middleware('throttle:password-reset')->name('password.forgot');
            Route::post('reset-password', [CustomerAuthController::class, 'resetPassword'])->middleware('throttle:auth')->name('password.reset');
        });

        Route::middleware(['auth:sanctum', 'actor:customer'])->group(function (): void {
            Route::get('products/{slug}/reviews/mine', [StorefrontReviewController::class, 'mine'])->name('reviews.mine');
            Route::post('review-photos', [StorefrontReviewController::class, 'uploadPhoto'])->middleware('throttle:20,1')->name('review-photos.store');
            Route::delete('review-photos/{photo}', [StorefrontReviewController::class, 'destroyPhoto'])->name('review-photos.destroy');
            Route::post('products/{slug}/reviews', [StorefrontReviewController::class, 'store'])->middleware('throttle:10,1')->name('reviews.store');
            Route::delete('products/{slug}/reviews/mine', [StorefrontReviewController::class, 'destroyMine'])->name('reviews.destroy');
            Route::get('auth/me', [CustomerAuthController::class, 'me'])->name('auth.me');
            Route::post('auth/logout', [CustomerAuthController::class, 'logout'])->name('auth.logout');
            Route::get('account/addresses', [AccountController::class, 'addresses'])->name('account.addresses');
            Route::post('account/addresses', [AccountController::class, 'storeAddress'])->name('account.addresses.store');
            Route::delete('account/addresses/{address}', [AccountController::class, 'destroyAddress'])->name('account.addresses.destroy');
            Route::get('account/wishlist', [AccountController::class, 'wishlist'])->name('account.wishlist');
            Route::put('account/wishlist/{product}', [AccountController::class, 'addToWishlist'])->name('account.wishlist.store');
            Route::delete('account/wishlist/{product}', [AccountController::class, 'removeFromWishlist'])->name('account.wishlist.destroy');
            Route::get('account/orders', [AccountController::class, 'orders'])->name('account.orders');
            Route::get('account/orders/{order}', [AccountController::class, 'order'])->name('account.orders.show');
            Route::get('account/orders/{order}/invoice', [AccountController::class, 'invoice'])->name('account.orders.invoice');
        });
    });

    // Merchant dashboard: tenant derived from the authenticated user
    Route::middleware(['auth:sanctum', 'tenant.user'])->group(function (): void {
        Route::apiResource('categories', CategoryController::class);
        Route::apiResource('pages', StorePageController::class);
        Route::apiResource('products', ProductController::class);
        Route::get('products/{product}/inventory', [ProductInventoryController::class, 'index'])->name('products.inventory.index');
        Route::post('products/{product}/inventory', [ProductInventoryController::class, 'store'])->name('products.inventory.store');
        Route::apiResource('discounts', DiscountController::class);
        Route::get('reviews', [ProductReviewController::class, 'index'])->name('reviews.index');
        Route::patch('reviews/{review}', [ProductReviewController::class, 'update'])->name('reviews.update');
        Route::delete('reviews/{review}', [ProductReviewController::class, 'destroy'])->name('reviews.destroy');
        Route::delete('reviews/{review}/photos/{photo}', [ProductReviewController::class, 'destroyPhoto'])->name('reviews.photos.destroy');
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::get('pos/shift', [PosShiftController::class, 'current'])->name('pos.shift.current');
        Route::get('pos/shifts', [PosShiftController::class, 'index'])->name('pos.shifts.index');
        Route::post('pos/shifts', [PosShiftController::class, 'store'])->name('pos.shifts.store');
        Route::get('pos/shifts/{shift}', [PosShiftController::class, 'show'])->name('pos.shifts.show');
        Route::post('pos/shifts/{shift}/close', [PosShiftController::class, 'close'])->name('pos.shifts.close');
        Route::get('pos/reports/cashiers', [PosShiftController::class, 'cashierReport'])->name('pos.reports.cashiers');
        Route::post('pos/sales', [PosController::class, 'store'])->middleware('idempotent:pos')->name('pos.sales.store');
        Route::post('orders/{order}/void', [PosController::class, 'void'])->name('orders.void');
        Route::get('orders/{order}/invoice', [OrderController::class, 'invoice'])->name('orders.invoice');
        Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.status');
        Route::post('orders/{order}/refund', [RefundController::class, 'store'])->middleware('idempotent:refund')->name('orders.refund');
        Route::apiResource('shipping-zones', ShippingZoneController::class);
        Route::get('shipping/locations', ShippingLocationsController::class)->name('shipping.locations');
        Route::get('billing', [BillingController::class, 'show'])->name('billing.show');
        Route::post('billing/checkout', [BillingController::class, 'checkout'])->middleware('throttle:20,1')->name('billing.checkout');
        Route::post('billing/verify', [BillingController::class, 'verify'])->middleware('throttle:30,1')->name('billing.verify');
        Route::post('billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
        Route::post('billing/resume', [BillingController::class, 'resume'])->name('billing.resume');
        Route::get('payment-gateways', [PaymentGatewayController::class, 'index'])->name('payment-gateways.index');
        Route::put('payment-gateways/{provider}', [PaymentGatewayController::class, 'update'])->name('payment-gateways.update');
        Route::delete('payment-gateways/{provider}', [PaymentGatewayController::class, 'destroy'])->name('payment-gateways.destroy');
        Route::apiResource('campaign-series', CampaignSeriesController::class)->parameters(['campaign-series' => 'series']);
        Route::get('email-campaigns', [EmailCampaignController::class, 'index'])->name('email-campaigns.index');
        Route::post('email-campaigns/audience', [EmailCampaignController::class, 'audience'])->name('email-campaigns.audience');
        Route::post('email-campaigns/test', [EmailCampaignController::class, 'test'])->middleware('throttle:6,1')->name('email-campaigns.test');
        Route::post('email-campaigns', [EmailCampaignController::class, 'store'])->middleware(['throttle:10,1', 'idempotent:campaign'])->name('email-campaigns.store');
        Route::get('email-campaigns/{campaign}', [EmailCampaignController::class, 'show'])->name('email-campaigns.show');
        Route::patch('email-campaigns/{campaign}', [EmailCampaignController::class, 'update'])->name('email-campaigns.update');
        Route::get('email-campaigns/{campaign}/recipients', [EmailCampaignController::class, 'recipients'])->name('email-campaigns.recipients');
        Route::get('email-campaigns/{campaign}/export', [EmailCampaignController::class, 'export'])->name('email-campaigns.export');
        Route::post('email-campaigns/{campaign}/cancel', [EmailCampaignController::class, 'cancel'])->name('email-campaigns.cancel');
        Route::post('customer-segments/preview', [CustomerSegmentController::class, 'preview'])->name('customer-segments.preview');
        Route::get('customer-segments/{segment}/customers', [CustomerSegmentController::class, 'customers'])->name('customer-segments.customers');
        Route::get('customer-segments/{segment}/export', [CustomerSegmentController::class, 'export'])->name('customer-segments.export');
        Route::apiResource('customer-segments', CustomerSegmentController::class)->parameters(['customer-segments' => 'segment']);
        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');

        Route::get('dashboard/stats', [DashboardController::class, 'stats'])->name('dashboard.stats');
        Route::get('themes', [ThemeController::class, 'index'])->name('themes.index');

        Route::prefix('store')->name('store.')->group(function (): void {
            Route::get('/', [StoreController::class, 'show'])->name('show');
            Route::put('/', [StoreController::class, 'update'])->name('update');
            Route::get('settings', [StoreSettingsController::class, 'show'])->name('settings.show');
            Route::put('settings', [StoreSettingsController::class, 'update'])->name('settings.update');
            Route::post('settings/test-email', [StoreSettingsController::class, 'sendTestEmail'])->middleware('throttle:6,1')->name('settings.test-email');
            Route::get('theme', [StoreThemeController::class, 'show'])->name('theme.show');
            Route::put('theme', [StoreThemeController::class, 'update'])->name('theme.update');
            Route::post('logo', [StoreBrandingController::class, 'uploadLogo'])->name('logo.upload');
            Route::delete('logo', [StoreBrandingController::class, 'destroyLogo'])->name('logo.destroy');
            Route::post('favicon', [StoreBrandingController::class, 'uploadFavicon'])->name('favicon.upload');
            Route::delete('favicon', [StoreBrandingController::class, 'destroyFavicon'])->name('favicon.destroy');
        });

        Route::apiResource('media', MediaController::class)->parameters(['media' => 'media'])->except('show');
        Route::post('media/{media}/replace', [MediaController::class, 'replace'])->name('media.replace');

        Route::prefix('domains')->name('domains.')->group(function (): void {
            Route::get('/', [DomainController::class, 'index'])->name('index');
            Route::post('/', [DomainController::class, 'store'])->name('store');
            Route::get('{domain}', [DomainController::class, 'show'])->name('show');
            Route::delete('{domain}', [DomainController::class, 'destroy'])->name('destroy');
            Route::post('{domain}/verify', [DomainController::class, 'verify'])->middleware('throttle:10,1')->name('verify');
            Route::post('{domain}/primary', [DomainController::class, 'makePrimary'])->name('primary');
        });
    });

    // Platform subscription billing webhook (the platform's own Paystack account). Declared before the
    // generic {provider}/{tenant} route below, which would otherwise swallow it.
    // Open and click counting for campaign emails: the link names one recipient and is signed
    Route::get('t/o/{token}', [EmailTrackingController::class, 'open'])->middleware('throttle:600,1')->where('token', '[A-Za-z0-9.\-]+')->name('track.open');
    Route::get('t/c/{token}', [EmailTrackingController::class, 'click'])->middleware('throttle:600,1')->where('token', '[A-Za-z0-9.\-]+')->name('track.click');

    // Promotional-email opt-out, opened from an email: tenant comes from the link, authenticated by its token
    Route::get('unsubscribe/{tenant}', [UnsubscribeController::class, 'show'])->middleware('throttle:60,1')->name('unsubscribe.show');
    Route::post('unsubscribe/{tenant}', [UnsubscribeController::class, 'store'])->middleware('throttle:60,1')->name('unsubscribe.store');

    Route::post('webhooks/billing/paystack', BillingWebhookController::class)->middleware('throttle:600,1')->name('webhooks.billing');

    // Payment provider webhooks: tenant identified by URL + authenticated by provider signature
    Route::post('webhooks/{provider}/{tenant}', WebhookController::class)->middleware('throttle:600,1')->name('webhooks.payments');

    // Platform administration: platform admins only; tenant scoping is explicitly bypassed for this group.
    Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'platform.admin', 'can:manage-platform'])->group(function (): void {
        Route::get('overview', AdminOverviewController::class)->name('overview');

        Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
        Route::get('tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
        Route::post('tenants/{tenant}/suspend', [TenantController::class, 'suspend'])->name('tenants.suspend');
        Route::post('tenants/{tenant}/activate', [TenantController::class, 'activate'])->name('tenants.activate');
        Route::post('tenants/{tenant}/subscription', [AdminSubscriptionController::class, 'assign'])->name('tenants.subscription');

        Route::get('stores', [AdminStoreController::class, 'index'])->name('stores.index');
        Route::get('stores/{store}', [AdminStoreController::class, 'show'])->name('stores.show');
        Route::patch('stores/{store}', [AdminStoreController::class, 'update'])->name('stores.update');

        Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('users/{user}', [AdminUserController::class, 'show'])->name('users.show');
        Route::patch('users/{user}', [AdminUserController::class, 'update'])->name('users.update');
        Route::post('users/{user}/suspend', [AdminUserController::class, 'suspend'])->name('users.suspend');
        Route::post('users/{user}/activate', [AdminUserController::class, 'activate'])->name('users.activate');
        Route::post('users/{user}/verify-email', [AdminUserController::class, 'verifyEmail'])->name('users.verify-email');
        Route::post('users/{user}/revoke-tokens', [AdminUserController::class, 'revokeTokens'])->name('users.revoke');
        Route::post('users/{user}/password-reset', [AdminUserController::class, 'sendPasswordReset'])->middleware('throttle:10,1')->name('users.password-reset');

        Route::apiResource('themes', AdminThemeController::class)->except('show');
        Route::apiResource('plans', AdminPlanController::class)->except('show');

        Route::get('subscriptions', [AdminSubscriptionController::class, 'index'])->name('subscriptions.index');
        Route::patch('subscriptions/{subscription}', [AdminSubscriptionController::class, 'update'])->name('subscriptions.update');

        Route::get('domains', [AdminDomainController::class, 'index'])->name('domains.index');
        Route::post('domains/{domain}/verify', [AdminDomainController::class, 'verify'])->middleware('throttle:30,1')->name('domains.verify');
        Route::delete('domains/{domain}', [AdminDomainController::class, 'destroy'])->name('domains.destroy');

        Route::get('audit-logs', [AdminAuditLogController::class, 'index'])->name('audit-logs.index');
        Route::get('audit-logs/actions', [AdminAuditLogController::class, 'actions'])->name('audit-logs.actions');
        Route::get('audit-logs/export', [AdminAuditLogController::class, 'export'])->middleware('throttle:10,1')->name('audit-logs.export');
        Route::get('audit-logs/{auditLog}', [AdminAuditLogController::class, 'show'])->name('audit-logs.show');

        Route::get('settings', [AdminSettingsController::class, 'show'])->name('settings.show');
        Route::put('settings', [AdminSettingsController::class, 'update'])->name('settings.update');
    });
});
