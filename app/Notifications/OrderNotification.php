<?php

namespace App\Notifications;

/**
 * Customer-facing order email (confirmation and status updates).
 */
class OrderNotification extends BrandedMailNotification
{
    /**
     * @param  array{store: string, order_number: string, name: string, total: string, items: list<string>, accent?: string, track_url?: string, support_email?: string|null}  $summary
     */
    public function __construct(public readonly string $subject, public readonly string $intro, public readonly array $summary) {}

    protected function subjectLine(): string
    {
        return $this->subject;
    }

    public function content(): array
    {
        $s = $this->summary;

        return [
            'store' => $s['store'],
            'accent' => $s['accent'] ?? '#5b3df5',
            'preheader' => "{$this->intro} Order {$s['order_number']}.",
            'heading' => $this->subject,
            'intro' => "Hi {$s['name']}, {$this->intro}",
            'details' => [['Order number', $s['order_number']]],
            'lines' => array_map(fn (string $line) => [$line, ''], $s['items']),
            'totals' => [['Total', $s['total']]],
            'button' => isset($s['track_url']) ? ['label' => 'Track your order', 'url' => $s['track_url']] : null,
            'outro' => 'Keep your order number handy: you can use it to check on your order at any time.',
            'reply_to' => $s['support_email'] ?? null,
            'footer' => ! empty($s['support_email']) ? "Questions? Reply to this email or write to {$s['support_email']}." : null,
        ];
    }
}
