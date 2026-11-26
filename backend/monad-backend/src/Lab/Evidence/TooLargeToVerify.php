<?php

declare(strict_types=1);

namespace App\Lab\Evidence;

/** An artefact exists but exceeds the seal's verification cap; its hash is left unverified, not invented. */
final class TooLargeToVerify
{
    public function __construct(public readonly ?int $bytes)
    {
    }
}
