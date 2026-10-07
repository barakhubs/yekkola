<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

enum DevicePlatform: string
{
    case Android = 'android';
    case Ios = 'ios';
}
