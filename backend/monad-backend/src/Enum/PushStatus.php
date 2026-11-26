<?php

namespace App\Enum;

/**
 * What the worker did with one recipient's push (IP-157). `skipped` is a decision, not a failure:
 * no token, no OS permission, or the type's opt-in is off.
 */
enum PushStatus: string
{
    case SKIPPED = 'skipped';
    case QUEUED = 'queued';
    case SENT = 'sent';
    case FAILED = 'failed';
}
