<?php

declare(strict_types=1);

namespace App\Lab\Evidence;

/**
 * The one boundary the evidence seal crosses: the bytes of one uploaded artefact (IP-162).
 *
 * A port rather than `S3Service` directly so the seal's rules — which are about hashes, identity
 * and order — can be tested against a real database with a fake object store. The production
 * adapter reads the staged object from the bucket; a test hands back the bytes it uploaded.
 */
interface ArtefactReader
{
    /**
     * The artefact's bytes, `null` when absent, or {@see TooLargeToVerify} when the object is
     * larger than `$maxBytes`. Absence and size are different answers and are reported apart.
     */
    public function read(string $participantId, string $recordingSessionId, string $filename, int $maxBytes): string|TooLargeToVerify|null;
}
