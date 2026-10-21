<?php

namespace App\Support\Notifications;

/**
 * The email asking a customer to review what they bought, with one link per product to its page.
 */
final class ReviewRequestMessage
{
    /**
     * @param  array{store: string, accent: string, supportEmail: string|null}  $mail
     * @param  list<array{0: string, 1: string}>  $products  [name, URL of the product's review section]
     * @return array<string, mixed>
     */
    public static function build(array $mail, ?string $firstName, string $orderNumber, array $products, ?string $unsubscribeUrl, ?string $footerNote = null): array
    {
        $name = trim((string) $firstName) !== '' ? trim((string) $firstName) : 'there';
        $many = count($products) > 1;
        $support = $mail['supportEmail'] ? "Questions? Write to {$mail['supportEmail']}." : null;

        return [
            'store' => $mail['store'],
            'accent' => $mail['accent'],
            'preheader' => 'Tell others what you thought. It only takes a minute.',
            'heading' => 'How did we do?',
            'intro' => "Hi {$name}, thanks for your order {$orderNumber} from {$mail['store']}. Your honest opinion helps other shoppers choose, and it helps us get better.",
            'links' => array_map(fn (array $p) => ["Review {$p[0]}", $p[1]], $products),
            'outro' => $many ? 'You will need to sign in with the email you ordered with (or create an account with it) to leave a review.' : 'You will need to sign in with the email you ordered with (or create an account with it) to leave your review.',
            'reply_to' => $mail['supportEmail'],
            'unsubscribe_url' => $unsubscribeUrl,
            'footer' => $footerNote ?? $support,
        ];
    }
}
