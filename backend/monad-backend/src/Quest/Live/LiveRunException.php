<?php

declare(strict_types=1);

namespace App\Quest\Live;

/** A sync the service refuses, with the HTTP status that says whether a retry can help. */
final class LiveRunException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
