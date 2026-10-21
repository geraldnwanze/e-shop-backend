<?php

namespace App\Support\Notifications;

use App\Models\ProductReview;
use Illuminate\Support\Collection;

/**
 * The merchant's daily review summary: headline numbers, then the reviews that most need attention.
 */
final class ReviewDigestMessage
{
    /** Reviews listed in one email before it says "and N more". */
    public const MAX_LISTED = 10;

    /**
     * @param  array{store: string, accent: string, supportEmail: string|null, dashboardUrl: string}  $mail
     * @param  Collection<int, ProductReview>  $reviews  Reviews with their product loaded
     * @return array{subject: string, content: array<string, mixed>}
     */
    public static function build(array $mail, Collection $reviews, ?string $footerNote = null): array
    {
        $count = $reviews->count();
        $pending = $reviews->where('status', 'pending')->count();
        $low = $reviews->where('rating', '<=', 2)->count();
        $average = $count > 0 ? round($reviews->avg('rating'), 1) : 0;

        $ordered = $reviews->sortBy([fn ($a, $b) => ($b->status === 'pending') <=> ($a->status === 'pending'), fn ($a, $b) => $a->rating <=> $b->rating, fn ($a, $b) => $b->created_at <=> $a->created_at])->values();
        $listed = $ordered->take(self::MAX_LISTED);
        $more = $count - $listed->count();

        $lines = $listed->map(function ($r): array {
            $stars = str_repeat('★', $r->rating).str_repeat('☆', 5 - $r->rating);
            $headline = $r->title ? ": “{$r->title}”" : '';

            return ["{$stars}  {$r->product?->name} · {$r->author_name}{$headline}", $r->status === 'pending' ? 'needs approval' : ''];
        })->all();

        if ($more > 0) {
            $lines[] = ["…and {$more} more", ''];
        }

        $noun = $count === 1 ? 'review' : 'reviews';

        return [
            'subject' => "Daily review summary: {$count} new {$noun}".($pending > 0 ? " · {$pending} need approval" : ''),
            'content' => [
                'store' => $mail['store'],
                'accent' => $mail['accent'],
                'preheader' => "{$count} new {$noun} since yesterday".($low > 0 ? ", {$low} low-rated" : '').'.',
                'heading' => 'Your daily review summary',
                'intro' => 'Here is what customers said about your products since the last summary.',
                'details' => array_values(array_filter([
                    ['New reviews', (string) $count],
                    ['Average rating', number_format((float) $average, 1).' / 5'],
                    $pending > 0 ? ['Waiting for approval', (string) $pending] : null,
                    $low > 0 ? ['Low ratings (1–2 stars)', (string) $low] : null,
                ])),
                'lines' => $lines,
                'button' => ['label' => $pending > 0 ? 'Review what is waiting' : 'Read and reply', 'url' => "{$mail['dashboardUrl']}/reviews".($pending > 0 ? '?status=pending' : '')],
                'outro' => 'You get this once a day when there are new reviews. Switch to an email per review, or turn these off, under Settings & payments.',
                'reply_to' => null,
                'footer' => $footerNote,
            ],
        ];
    }
}
