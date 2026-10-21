<?php

namespace App\Support\Notifications;

use App\Enums\UserRole;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * A store's email notification switches, read inside a tenant context. Stores that
 * predate a switch fall back to the platform default, so new emails are on by default.
 */
final class NotificationPreferences
{
    /**
     * @param  array<string, mixed>  $notifications
     */
    public function __construct(private readonly array $notifications, private readonly ?string $contactEmail) {}

    public static function forCurrentTenant(): self
    {
        $settings = StoreSetting::query()->first()?->settings ?? [];
        $defaults = config('themes.default_settings.notifications');

        return new self(
            array_replace($defaults, is_array($settings['notifications'] ?? null) ? $settings['notifications'] : []),
            $settings['contact']['email'] ?? null,
        );
    }

    public function enabled(string $key): bool
    {
        return (bool) ($this->notifications[$key] ?? false);
    }

    /**
     * The store's public contact address, shown to customers in the email footer.
     */
    public function supportEmail(): ?string
    {
        return $this->contactEmail ?: null;
    }

    public function lowStockThreshold(): int
    {
        return max(0, (int) ($this->notifications['low_stock_threshold'] ?? 0));
    }

    /**
     * Hours a cart must sit untouched before the shopper gets a reminder.
     */
    public function abandonedCartDelayHours(): int
    {
        return max(1, (int) ($this->notifications['abandoned_cart_delay_hours'] ?? 4));
    }

    /**
     * Days after delivery before the customer is asked to review what they bought.
     */
    public function reviewRequestDelayDays(): int
    {
        return max(1, (int) ($this->notifications['review_request_delay_days'] ?? 3));
    }

    /**
     * Hours after the first reminder (and the shopper's last activity) before the follow-up goes out.
     */
    public function secondReminderDelayHours(): int
    {
        return max(1, (int) ($this->notifications['abandoned_cart_second_delay_hours'] ?? 24));
    }

    /**
     * Percentage taken off by the code in the follow-up; 0 sends the reminder without a code.
     */
    public function recoveryDiscountPercent(): int
    {
        return max(0, min(50, (int) ($this->notifications['abandoned_cart_discount_percent'] ?? 0)));
    }

    /**
     * Where merchant alerts go: the address chosen for notifications, else the store's contact
     * address, else the owner's login email.
     */
    public function merchantRecipient(): ?string
    {
        $recipient = $this->notifications['merchant_email'] ?: $this->contactEmail;

        return $recipient ?: User::query()
            ->where('tenant_id', app(TenantContext::class)->id())
            ->where('role', UserRole::MerchantOwner->value)
            ->orderBy('created_at')->value('email');
    }
}
