<?php

namespace App\Support\Notifications;

use Carbon\CarbonInterface;

/**
 * The wording of the two cart reminder emails, shared by the real sends and the merchant's test
 * sends so a preview is always exactly what a shopper receives.
 */
final class AbandonedCartMessage
{
    /** How long a follow-up's discount code stays valid. */
    public const CODE_VALID_DAYS = 7;

    /**
     * @param  array{store: string, accent: string, supportEmail: string|null}  $mail
     * @param  array{code: string, value: int, ends_at: CarbonInterface}|null  $code  The follow-up's discount, if it carries one
     * @param  list<array{0: string, 1: string}>  $lines
     * @return array{subject: string, content: array<string, mixed>}
     */
    public static function build(array $mail, bool $isFollowUp, ?array $code, array $lines, string $subtotal, string $buttonUrl, ?string $unsubscribeUrl, ?string $footerNote = null): array
    {
        $subject = $isFollowUp ? ($code ? "{$code['value']}% off your cart, just for you" : 'Still interested?') : 'You left something behind';
        $support = $mail['supportEmail'] ? "Questions? Write to {$mail['supportEmail']}." : null;

        return [
            'subject' => $subject,
            'content' => [
                'store' => $mail['store'],
                'accent' => $mail['accent'],
                'preheader' => $code ? "Use code {$code['code']} for {$code['value']}% off. It expires in ".self::CODE_VALID_DAYS.' days.' : 'Your cart is waiting. Pick up where you left off.',
                'heading' => $isFollowUp ? ($code ? 'Here\'s something to help you decide' : 'Your cart is still waiting') : 'Still thinking it over?',
                'intro' => $isFollowUp
                    ? ($code ? "Your cart at {$mail['store']} is still saved. Take {$code['value']}% off when you finish your order today." : "Your cart at {$mail['store']} is still saved, but items can sell out.")
                    : "You left a few things in your cart at {$mail['store']}. We've saved them for you.",
                'details' => $code ? [['Your code', $code['code']], ['Discount', "{$code['value']}% off"], ['Valid until', $code['ends_at']->format('j M Y')]] : [],
                'lines' => $lines,
                'totals' => [['Subtotal', $subtotal]],
                'button' => ['label' => 'Complete your order', 'url' => $buttonUrl],
                'outro' => $code ? 'The code works once and is applied for you when you use the button. Stock is limited.' : 'Stock is limited, so items may sell out. If you have already ordered, you can ignore this email.',
                'reply_to' => $mail['supportEmail'],
                'unsubscribe_url' => $unsubscribeUrl,
                'footer' => $footerNote ?? $support,
            ],
        ];
    }
}
