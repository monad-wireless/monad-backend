<?php

namespace App\Enum;

enum UserStatus: string
{
    // A self-registered account whose email had no invited beta signup. It cannot sign in until
    // an operator sets it ACTIVE on the admin Users page: beta access is invitation only.
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case BANNED = 'banned';
    case DELETED = 'deleted';
}
