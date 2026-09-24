<?php

namespace App\Notifications\Auth;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 05.13 §10 — also the "set your password" link for a new staff user
 * (§5.3), which is the same broker token. Single use, 60 minutes
 * (config/auth.php, 07 §6.1).
 */
final class ResetPassword extends Notification
{
    public function __construct(
        public readonly string $token,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = route('password.reset', ['token' => $this->token, 'email' => $notifiable->email]);
        $settingUp = $notifiable->status === 'pending';

        return (new MailMessage)
            ->subject($settingUp ? 'Set your password' : 'Reset your password')
            ->greeting("Hello {$notifiable->first_name},")
            ->line($settingUp ? 'An account has been created for you. Choose a password to start.' : 'We received a request to reset your password.')
            ->action($settingUp ? 'Set password' : 'Reset password', $url)
            ->line('This link can be used once and expires in 60 minutes. If you did not ask for this, you can ignore this email.');
    }
}
