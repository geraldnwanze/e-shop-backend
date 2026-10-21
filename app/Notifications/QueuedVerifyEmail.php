<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\URL;

class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    /**
     * Signed API URL; the endpoint verifies then redirects to the frontend.
     */
    protected function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute(
            'api.v1.auth.verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            ['id' => $notifiable->id, 'hash' => sha1($notifiable->getEmailForVerification())],
        );
    }
}
