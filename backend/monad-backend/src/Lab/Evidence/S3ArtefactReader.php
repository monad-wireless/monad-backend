<?php

declare(strict_types=1);

namespace App\Lab\Evidence;

use App\Service\S3Service;

/** The production {@see ArtefactReader}: the staged object in the sessions bucket. */
final class S3ArtefactReader implements ArtefactReader
{
    public function __construct(private readonly S3Service $s3)
    {
    }

    public function read(string $participantId, string $recordingSessionId, string $filename, int $maxBytes): string|TooLargeToVerify|null
    {
        // `getSessionObject` returns null both for an absent object and for one over the cap, so
        // the size question is asked first through the same key.
        $body = $this->s3->getSessionObject($participantId, $recordingSessionId, $filename, $maxBytes);
        if ($body !== null) {
            return $body;
        }
        $probe = $this->s3->getSessionObject($participantId, $recordingSessionId, $filename, PHP_INT_MAX);

        return $probe === null ? null : new TooLargeToVerify(strlen($probe));
    }
}
