<?php

namespace App\Support\Notifications;

/**
 * The email telling a shopper that something they asked about is available again.
 */
final class BackInStockMessage
{
    /**
     * @param  array{store: string, accent: string, supportEmail: string|null}  $mail
     * @return array<string, mixed>
     */
    public static function build(array $mail, string $productName, string $productUrl, ?string $unsubscribeUrl): array
    {
        return [
            'store' => $mail['store'],
            'accent' => $mail['accent'],
            'preheader' => "{$productName} is available again.",
            'heading' => 'Good news, it is back',
            'intro' => "{$productName} is back in stock at {$mail['store']}. Stock can go quickly, so grab it while it lasts.",
            'button' => ['label' => 'Shop now', 'url' => $productUrl],
            'outro' => 'You are getting this one-time email because you asked to be told when it returned.',
            'reply_to' => $mail['supportEmail'],
            'unsubscribe_url' => $unsubscribeUrl,
            'footer' => $mail['supportEmail'] ? "Questions? Write to {$mail['supportEmail']}." : null,
        ];
    }
}
