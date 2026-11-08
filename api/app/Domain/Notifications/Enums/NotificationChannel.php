<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Enums;

enum NotificationChannel: string
{
    case InApp = 'in_app';
    case Push = 'push';
    case Email = 'email';
    case Sms = 'sms';
}
