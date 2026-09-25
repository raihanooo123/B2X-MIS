<?php

namespace App\Domain\Notifications;

/** 05.12 §6 — email at launch; SMS modelled, not built. */
enum NotificationChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
}
