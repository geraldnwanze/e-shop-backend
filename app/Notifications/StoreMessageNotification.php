<?php

namespace App\Notifications;

/**
 * A ready-made branded email: payment receipts, refund notices and merchant alerts all build
 * their content up front (inside the tenant context) and hand it over as plain data.
 */
class StoreMessageNotification extends BrandedMailNotification
{
    /**
     * @param  array{store: string, accent: string, preheader?: string, heading: string, intro?: string, details?: list<array{0: string, 1: string}>, lines?: list<array{0: string, 1: string}>, totals?: list<array{0: string, 1: string}>, button?: array{label: string, url: string}|null, outro?: string|null, footer?: string|null, reply_to?: string|null, unsubscribe_url?: string|null, body?: list<string>, links?: list<array{0: string, 1: string}>, tracking_pixel?: string|null}  $message
     */
    public function __construct(public readonly string $kind, public readonly string $subject, public readonly array $message) {}

    protected function subjectLine(): string
    {
        return $this->subject;
    }

    public function content(): array
    {
        return $this->message;
    }
}
