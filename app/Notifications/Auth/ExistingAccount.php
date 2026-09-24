<?php

namespace App\Notifications\Auth;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 05.13 §5.1: someone registered with an address that already has an
 * account. The form showed the normal confirmation (no enumeration); the
 * address's owner learns the truth here.
 */
final class ExistingAccount extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You already have an account')
            ->greeting("Hello {$notifiable->first_name},")
            ->line('Someone — hopefully you — tried to register with this email address, which already has an account.')
            ->action('Sign in', route('login'))
            ->line('Signed in, you can apply for a trade account from your account. If you have forgotten your password, use "Forgot password" on the sign-in page. If this was not you, no action is needed.');
    }
}
