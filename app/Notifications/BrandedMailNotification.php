<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base for every store email: one branded HTML layout (store name, theme colour) plus a
 * plain-text alternative. Subclasses carry plain data captured inside the tenant context, so the
 * queued send needs no tenant-scoped model access.
 */
abstract class BrandedMailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    abstract protected function subjectLine(): string;

    /**
     * @return array{store: string, accent: string, preheader?: string, heading: string, intro?: string, details?: list<array{0: string, 1: string}>, lines?: list<array{0: string, 1: string}>, totals?: list<array{0: string, 1: string}>, button?: array{label: string, url: string}|null, outro?: string|null, footer?: string|null, reply_to?: string|null, unsubscribe_url?: string|null, body?: list<string>, links?: list<array{0: string, 1: string}>, tracking_pixel?: string|null}
     */
    abstract public function content(): array;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $content = $this->content();

        $mail = (new MailMessage)
            ->from(config('mail.from.address'), $content['store'])
            ->subject("[{$content['store']}] {$this->subjectLine()}")
            ->view(['emails.branded', 'emails.branded-text'], ['content' => $content]);

        if (! empty($content['unsubscribe_url'])) {
            $url = $content['unsubscribe_url'];

            // Lets mail apps show their own "Unsubscribe" button that works in one tap.
            $mail->withSymfonyMessage(function ($message) use ($url): void {
                $message->getHeaders()->addTextHeader('List-Unsubscribe', "<{$url}>");
                $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
        }

        if (! empty($content['reply_to'])) {
            $mail->replyTo($content['reply_to']);
        }

        return $mail;
    }
}
