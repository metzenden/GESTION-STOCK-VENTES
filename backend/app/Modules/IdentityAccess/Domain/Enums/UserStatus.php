<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Domain\Enums;

enum UserStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Disabled = 'disabled';
}
