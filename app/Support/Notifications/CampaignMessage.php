<?php

namespace App\Support\Notifications;

/**
 * Builds the branded email for a promotional campaign. The merchant writes plain text; blank lines
 * start new paragraphs and {first_name} becomes the shopper's first name.
 */
final class CampaignMessage
{
    /**
     * @param  array{store: string, accent: string, supportEmail: string|null}  $mail
     * @return array<string, mixed>
     */
    public static function build(array $mail, string $subject, string $body, ?string $buttonLabel, ?string $buttonUrl, ?string $firstName, ?string $unsubscribeUrl, ?string $footerNote = null, ?string $trackRecipientId = null): array
    {
        $name = trim((string) $firstName) !== '' ? trim((string) $firstName) : 'there';
        $personal = str_replace('{first_name}', $name, $body);
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', trim($personal)) ?: []), fn (string $p) => $p !== ''));

        return [
            'store' => $mail['store'],
            'accent' => $mail['accent'],
            'preheader' => mb_substr($paragraphs[0] ?? $subject, 0, 110),
            'heading' => $subject,
            'body' => $paragraphs,
            // With tracking on, the button goes through our redirect (which looks the real address up from the campaign) and a hidden image counts opens.
            'button' => $buttonLabel && $buttonUrl ? ['label' => $buttonLabel, 'url' => $trackRecipientId ? EmailTracking::clickUrl($trackRecipientId) : $buttonUrl] : null,
            'tracking_pixel' => $trackRecipientId ? EmailTracking::pixelUrl($trackRecipientId) : null,
            'reply_to' => $mail['supportEmail'],
            'unsubscribe_url' => $unsubscribeUrl,
            'footer' => ($footerNote ?? ($mail['supportEmail'] ? "You are receiving this because you opted in to emails from {$mail['store']}. Questions? Write to {$mail['supportEmail']}." : "You are receiving this because you opted in to emails from {$mail['store']}.")).($trackRecipientId ? ' We measure whether emails are opened and links are clicked.' : ''),
        ];
    }
}
