<?php

namespace App\Notifications\Auth;

use App\Models\B2bApplication;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** 05.2 §11: "Application submitted → applicant". */
final class ApplicationReceived extends Notification
{
    public function __construct(
        private readonly B2bApplication $application,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('We have received your trade account application')
            ->line("Thank you for applying for a trade account for {$this->application->company_name}.")
            ->line('Please confirm your email address using the separate email we have sent — we review applications once the address is confirmed.')
            ->line('Until then you can sign in, browse the catalogue at our standard prices and check your application status.');
    }
}
