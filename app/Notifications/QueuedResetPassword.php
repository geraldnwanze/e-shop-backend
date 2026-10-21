<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    /**
     * Points the link at the frontend reset page; the SPA posts token + email
     * back to /api/v1/auth/reset-password.
     */
    protected function resetUrl($notifiable): string
    {
        return rtrim(config('tenancy.frontend_url'), '/').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }

    public function toMail($notifiable): MailMessage
    {
        return $this->buildMailMessage($this->resetUrl($notifiable));
    }
}
