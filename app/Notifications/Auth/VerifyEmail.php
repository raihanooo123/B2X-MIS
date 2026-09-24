<?php

namespace App\Notifications\Auth;

use App\Domain\Identity\EmailVerificationLink;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** 05.13 §11. Wording and templates belong to 05.12 (unwritten). */
final class VerifyEmail extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your email address')
            ->greeting("Hello {$notifiable->first_name},")
            ->line('Please confirm this is your email address.')
            ->action('Confirm email address', EmailVerificationLink::url($notifiable))
            ->line('The link expires in 24 hours. If you did not create an account, you can ignore this email.');
    }
}
