<?php

namespace App\Service;

use App\Constants\ErrorCode;
use App\Exception\SystemException;
use App\Exception\ValidationException;
use AsyncAws\Core\Exception\Http\HttpException;
use AsyncAws\S3\Input\AbortMultipartUploadRequest;
use AsyncAws\S3\Input\CompleteMultipartUploadRequest;
use AsyncAws\S3\Input\CreateMultipartUploadRequest;
use AsyncAws\S3\Input\GetObjectRequest;
use AsyncAws\S3\Input\ListObjectsV2Request;
use AsyncAws\S3\Input\PutObjectRequest;
use AsyncAws\S3\Input\UploadPartRequest;
use AsyncAws\S3\ValueObject\CompletedMultipartUpload;
use AsyncAws\S3\ValueObject\CompletedPart;
use AsyncAws\S3\S3Client;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

class S3Service
{
    private const MAX_FILE_SIZE = 500 * 1024 * 1024; // 500 MB

    /**
     * Smallest interior part S3 accepts, 5 MiB. Protocol, not policy — the last part may be smaller.
     *
     * Published to the client as `partSizeHint` so the part size lives in one place. The client
     * currently sends 8 MiB parts, which is above this floor and small enough that losing one costs
     * a few seconds on a phone uplink.
     */
    private const MIN_PART_SIZE = 5 * 1024 * 1024;

    /** S3's part-number ceiling. At the 5 MiB floor this bounds one object at ~48 GB. */
    private const MAX_PARTS = 10_000;
    private const ALLOWED_CONTENT_TYPES = [
        'application/octet-stream',
        'application/json',
        'text/csv',
        'text/plain',
        'text/tab-separated-values',
    ];

    private S3Client $s3Client;
    private string $bucket;
    private string $region;
    private string $endpoint;
    private string $presignedUrlExpiry;

    /**
     * The store is **Hetzner Object Storage**. There is no AWS anywhere in this project.
     *
     * The client is `async-aws/s3` rather than `aws/aws-sdk-php`: a focused S3 implementation
     * (two small packages instead of the monolith's ~400 service clients) that speaks the same
     * protocol and carries no vendor defaults. The endpoint is required rather than defaulted,
     * object URLs are built from it, and the error messages name the credential fields this
     * deployment actually has.
     *
     * Path-style addressing is mandatory: virtual-hosted addressing
     * (`https://<bucket>.<endpoint>/<key>`) needs a wildcard TLS certificate Hetzner does not
     * issue.
     *
     * Sharing the project's bucket is the point — phone sessions land in the same tenancy as the
     * `csid` fleet captures and the simulation artefacts, so one credential set and one lifecycle
     * policy cover every kind of measurement this project produces.
     */
    public function __construct(
        string $region,
        string $bucket,
        string $accessKey,
        string $secretKey,
        string $presignedUrlExpiry,
        string $endpoint,
        bool $usePathStyle = true,
    ) {
        $this->bucket = $bucket;
        $this->region = $region;
        $this->endpoint = $endpoint;
        $this->presignedUrlExpiry = $presignedUrlExpiry;

        if ($endpoint === '') {
            // No silent fallback to AWS. An empty endpoint used to mean "talk to Amazon", which is
            // exactly the kind of default that sends research data to the wrong provider without
            // anyone noticing.
            throw new SystemException(ErrorCode::SYSTEM_INTERNAL_ERROR);
        }

        $this->s3Client = new S3Client([
            'endpoint' => $endpoint,
            'region' => $region,
            'accessKeyId' => $accessKey,
            'accessKeySecret' => $secretKey,
            'pathStyleEndpoint' => $usePathStyle,
        ]);
    }

    /** Public URL for an object key: endpoint + bucket + key, path-style. */
    private function objectUrl(string $objectKey): string
    {
        return sprintf('%s/%s/%s', rtrim($this->endpoint, '/'), $this->bucket, $objectKey);
    }

    /**
     * Test S3 connection by checking if bucket exists and is accessible
     *
     * @return array{success: bool, bucket: string, region: string, message: string}
     */
    public function testConnection(): array
    {
        try {
            $this->s3Client->bucketExists(['Bucket' => $this->bucket])->resolve();

            return [
                'success' => true,
                'bucket' => $this->bucket,
                'region' => $this->region,
                'message' => 'Successfully connected to S3 bucket',
            ];
        } catch (HttpException $e) {
            // async-aws surfaces the HTTP status rather than Amazon's error-code vocabulary, which
            // is the more honest signal against a third-party S3 implementation anyway.
            $errorCode = (string) $e->getResponse()->getStatusCode();
            $message = match ($e->getResponse()->getStatusCode()) {
                404 => 'Bucket does not exist',
                403 => 'Access denied - check HETZNER_S3_ACCESS_KEY / HETZNER_S3_SECRET_KEY and the bucket policy',
                401 => 'Invalid credentials',
                default => $e->getMessage(),
            };

            return [
                'success' => false,
                'bucket' => $this->bucket,
                'region' => $this->region,
                'message' => $message,
                'errorCode' => $errorCode,
            ];
        }
    }

    /**
     * Stream upload a file directly to S3 without storing locally
     *
     * @param UploadedFile $file The uploaded file
     * @param string $userId User ID for organizing uploads
     * @return array{success: bool, objectKey: string, url: string, size: int}
     */
    public function streamUpload(UploadedFile $file, string $userId): array
    {
        $filename = $file->getClientOriginalName();
        $contentType = $file->getMimeType() ?? 'application/octet-stream';
        $fileSize = $file->getSize();

        $this->validateUploadRequest($filename, $contentType, $fileSize);

        // Generate unique object key
        $objectKey = sprintf(
            'uploads/%s/%s/%s',
            $userId,
            Uuid::v4()->toRfc4122(),
            $this->sanitizeFilename($filename)
        );

        try {
            // Open file stream - this avoids loading entire file into memory
            $stream = fopen($file->getPathname(), 'rb');

            $this->s3Client->putObject(new PutObjectRequest([
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
                'Body' => $stream,
                'ContentType' => $contentType,
                'ContentLength' => $fileSize,
            ]))->resolve();

            if (is_resource($stream)) {
                fclose($stream);
            }

            return [
                'success' => true,
                'objectKey' => $objectKey,
                'url' => $this->objectUrl($objectKey),
                'size' => $fileSize,
                'contentType' => $contentType,
            ];
        } catch (HttpException $e) {
            throw new SystemException(
                ErrorCode::STORAGE_UPLOAD_FAILED,
                previous: $e
            );
        }
    }

    /**
     * Generate a pre-signed URL for uploading a file directly to S3
     *
     * @param string $filename Original filename
     * @param string $contentType MIME type of the file
     * @param int $fileSize Expected file size in bytes
     * @param string $userId User ID for organizing uploads
     * @return array{uploadUrl: string, objectKey: string, expiresAt: string}
     */
    public function generateUploadUrl(
        string $filename,
        string $contentType,
        int $fileSize,
        string $userId,
    ): array {
        $this->validateUploadRequest($filename, $contentType, $fileSize);

        // Generate unique object key: uploads/{userId}/{uuid}/{original_filename}
        $objectKey = sprintf(
            'uploads/%s/%s/%s',
            $userId,
            Uuid::v4()->toRfc4122(),
            $this->sanitizeFilename($filename)
        );

        try {
            $request = new PutObjectRequest([
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
                'ContentType' => $contentType,
                'ContentLength' => $fileSize,
            ]);

            $expiresAt = new \DateTimeImmutable($this->presignedUrlExpiry);
            $uploadUrl = $this->s3Client->presign($request, $expiresAt);

            return [
                'uploadUrl' => $uploadUrl,
                'objectKey' => $objectKey,
                'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
            ];
        } catch (\Exception $e) {
            throw new SystemException(
                ErrorCode::STORAGE_S3_UNAVAILABLE,
                previous: $e
            );
        }
    }

    /**
     * Pre-signed PUT URL for one lab-session artefact.
     *
     * Same key layout as {@see directSessionStreamUpload}; used when a client would rather push
     * bytes straight at the object store than proxy them through this API.
     *
     * @return array{uploadUrl: string, objectKey: string, expiresAt: string}
     */
    public function generateSessionUploadUrl(
        string $filename,
        string $contentType,
        int $fileSize,
        string $participantId,
        string $sessionId,
    ): array {
        $this->validateUploadRequest($filename, $contentType, $fileSize);

        $objectKey = sprintf(
            'datasets/monad-app-sessions/%s/%s/%s',
            $this->sanitizeIdentifier($participantId),
            $this->sanitizeIdentifier($sessionId),
            $this->sanitizeFilename($filename)
        );

        try {
            $request = new PutObjectRequest([
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
                'ContentType' => $contentType,
                'ContentLength' => $fileSize,
            ]);

            $expiresAt = new \DateTimeImmutable($this->presignedUrlExpiry);
            $uploadUrl = $this->s3Client->presign($request, $expiresAt);

            return [
                'uploadUrl' => $uploadUrl,
                'objectKey' => $objectKey,
                'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
            ];
        } catch (HttpException $e) {
            throw new SystemException(
                ErrorCode::STORAGE_UPLOAD_FAILED,
                previous: $e
            );
        }
    }

    /**
     * Validate the upload request parameters
     */
    private function validateUploadRequest(
        string $filename,
        string $contentType,
        int $fileSize,
    ): void {
        if (empty(trim($filename))) {
            throw new ValidationException(ErrorCode::STORAGE_FILENAME_REQUIRED);
        }

        if ($fileSize > self::MAX_FILE_SIZE) {
            throw new ValidationException(ErrorCode::STORAGE_FILE_TOO_LARGE);
        }

        if (!in_array($contentType, self::ALLOWED_CONTENT_TYPES, true)) {
            throw new ValidationException(ErrorCode::STORAGE_INVALID_FILE_TYPE);
        }
    }

    /**
     * Sanitize filename to prevent path traversal and other issues
     */
    /**
     * Path-safe form of a participant or session identifier.
     *
     * These arrive from a client and become object-key path segments, so anything that could
     * traverse (`..`, `/`) or collide must go. Restricting to `[A-Za-z0-9._-]` keeps UUIDs and
     * pseudonymous participant keys intact while making traversal impossible by construction.
     */
    private function sanitizeIdentifier(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $value) ?? '';
        $clean = trim($clean, '.');

        return $clean === '' ? 'unknown' : substr($clean, 0, 128);
    }

    private function sanitizeFilename(string $filename): string
    {
        // Remove any directory components
        $filename = basename($filename);

        // Replace any potentially problematic characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

        // Ensure filename is not empty after sanitization
        if (empty($filename)) {
            $filename = 'file';
        }

        return $filename;
    }

    /**
     * Stream upload directly from php://input to S3 (no temp file)
     *
     * Client must send raw binary body (not multipart/form-data)
     *
     * @param string $filename Filename from header
     * @param string $contentType Content-Type from header
     * @param int $contentLength Content-Length from header
     * @param string $userId User ID for organizing uploads
     * @return array{success: bool, objectKey: string, url: string, size: int}
     */
    public function directStreamUpload(
        string $filename,
        string $contentType,
        int $contentLength,
        string $userId,
        // Read `$body` below and never declared until 2026-08-26: PHP resolved it as an undefined
        // variable, which evaluates to null, so the stream branch happened to be taken and the bug
        // was invisible. Declared rather than removed, so the two direct-upload methods have the
        // same shape and a caller that has already consumed php://input can say so.
        ?string $body = null,
    ): array {
        $this->validateUploadRequest($filename, $contentType, $contentLength);

        // Generate unique object key
        $objectKey = sprintf(
            'uploads/%s/%s/%s',
            $userId,
            Uuid::v4()->toRfc4122(),
            $this->sanitizeFilename($filename)
        );

        try {
            // php://input can only be consumed once. When the caller already read it (to inspect a
            // sidecar), forward that string; otherwise stream straight through with no temp file.
            $inputStream = $body === null ? fopen('php://input', 'rb') : null;

            $this->s3Client->putObject(new PutObjectRequest([
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
                'Body' => $body ?? $inputStream,
                'ContentType' => $contentType,
                'ContentLength' => $body === null ? $contentLength : strlen($body),
            ]))->resolve();

            if (is_resource($inputStream)) {
                fclose($inputStream);
            }

            return [
                'success' => true,
                'objectKey' => $objectKey,
                'url' => $this->objectUrl($objectKey),
                'size' => $contentLength,
                'contentType' => $contentType,
            ];
        } catch (HttpException $e) {
            throw new SystemException(
                ErrorCode::STORAGE_UPLOAD_FAILED,
                previous: $e
            );
        }
    }

    /**
     * Stream one lab-session artefact from php://input straight to S3 (no temp file).
     *
     * Key layout: `datasets/monad-app-sessions/{participantId}/{sessionId}/{filename}`.
     *
     * This replaces the previous `experiments/{y}/{m}/{d}/{userId}/{enrollmentId}/` layout, which
     * partitioned by *upload date*. That made a session's artefacts land in different prefixes
     * whenever an upload was retried across midnight, and it could not be joined to a `csid`
     * capture, which is addressed by session rather than by date. The prefix now mirrors the
     * fleet's own convention so a phone session and a radio capture are siblings in one bucket.
     *
     * @return array{success: bool, objectKey: string, url: string, size: int, contentType: string}
     */
    public function directSessionStreamUpload(
        string $filename,
        string $contentType,
        int $contentLength,
        string $participantId,
        string $sessionId,
        ?string $body = null,
    ): array {
        $this->validateUploadRequest($filename, $contentType, $contentLength);

        $objectKey = sprintf(
            'datasets/monad-app-sessions/%s/%s/%s',
            $this->sanitizeIdentifier($participantId),
            $this->sanitizeIdentifier($sessionId),
            $this->sanitizeFilename($filename)
        );

        try {
            // php://input can only be consumed once. When the caller already read it (to inspect a
            // sidecar), forward that string; otherwise stream straight through with no temp file.
            $inputStream = $body === null ? fopen('php://input', 'rb') : null;

            $this->s3Client->putObject(new PutObjectRequest([
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
                'Body' => $body ?? $inputStream,
                'ContentType' => $contentType,
                'ContentLength' => $body === null ? $contentLength : strlen($body),
            ]))->resolve();

            if (is_resource($inputStream)) {
                fclose($inputStream);
            }

            return [
                'success' => true,
                'objectKey' => $objectKey,
                'url' => $this->objectUrl($objectKey),
                'size' => $contentLength,
                'contentType' => $contentType,
            ];
        } catch (HttpException $e) {
            throw new SystemException(
                ErrorCode::STORAGE_UPLOAD_FAILED,
                previous: $e
            );
        }
    }

    /**
     * The object key one lab-session artefact lands on. Derived, never accepted from a client.
     *
     * Public because the multipart path needs the *same* key on three separate requests, and a key
     * the client carried between them would be a client-chosen write path into the bucket.
     */
    public function sessionObjectKey(string $participantId, string $sessionId, string $filename): string
    {
        return sprintf(
            'datasets/monad-app-sessions/%s/%s/%s',
            $this->sanitizeIdentifier($participantId),
            $this->sanitizeIdentifier($sessionId),
            $this->sanitizeFilename($filename)
        );
    }

    /**
     * Open a multipart upload for one lab-session artefact.
     *
     * WHY THIS EXISTS. The single-body path above works up to the point where the *transport* gives
     * out, not the point where the server refuses. On 2026-08-26 a 21-minute survey walk uploaded
     * nine artefacts and lost two: `mesh.ply` (102.94 MB) and `worldmap.armap` (30.05 MB), with no
     * error in the app, in this application, or in the bucket. nginx admits 520 MB and PHP admits
     * 500 MB, so nothing here rejected them — the phone's connection dropped mid-body and the four
     * client retries each restarted the same doomed 103 MB request.
     *
     * A multipart upload changes the unit of loss. A dropped connection costs one part, the retry
     * re-sends that part alone, and the parts that already landed stay landed. That is the whole
     * property; the S3 protocol is incidental.
     *
     * @return array{uploadId: string, objectKey: string, partSizeHint: int}
     */
    public function beginSessionMultipart(
        string $filename,
        string $contentType,
        int $totalBytes,
        string $participantId,
        string $sessionId,
    ): array {
        $this->validateUploadRequest($filename, $contentType, $totalBytes);

        $objectKey = $this->sessionObjectKey($participantId, $sessionId, $filename);

        try {
            $created = $this->s3Client->createMultipartUpload(new CreateMultipartUploadRequest([
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
                'ContentType' => $contentType,
            ]));
            $uploadId = (string) $created->getUploadId();
        } catch (HttpException $e) {
            throw new SystemException(ErrorCode::STORAGE_UPLOAD_FAILED, previous: $e);
        }

        if ($uploadId === '') {
            throw new SystemException(ErrorCode::STORAGE_UPLOAD_FAILED);
        }

        return [
            'uploadId' => $uploadId,
            'objectKey' => $objectKey,
            'partSizeHint' => self::MIN_PART_SIZE,
        ];
    }

    /**
     * Stream one part from php://input into an open multipart upload.
     *
     * The part number is 1-based and every part except the last must be at least
     * [self::MIN_PART_SIZE]; that is an S3 rule rather than a choice here, and violating it fails at
     * *complete* time rather than at upload time, which is the worst place to discover it. So it is
     * checked here, where the request that broke it can be named.
     *
     * @return array{partNumber: int, etag: string, size: int}
     */
    public function uploadSessionPart(
        string $filename,
        int $contentLength,
        string $participantId,
        string $sessionId,
        string $uploadId,
        int $partNumber,
        bool $isLastPart,
    ): array {
        if ($partNumber < 1 || $partNumber > self::MAX_PARTS) {
            throw new ValidationException(ErrorCode::STORAGE_PART_NUMBER_INVALID);
        }
        if ($contentLength <= 0 || $contentLength > self::MAX_FILE_SIZE) {
            throw new ValidationException(ErrorCode::STORAGE_FILE_TOO_LARGE);
        }
        if (!$isLastPart && $contentLength < self::MIN_PART_SIZE) {
            // Rejected here rather than at complete time: S3 reports an undersized interior part as
            // an EntityTooSmall failure on CompleteMultipartUpload, by which point the client has
            // spent the whole transfer and has no way to tell which part was wrong.
            throw new ValidationException(ErrorCode::STORAGE_PART_TOO_SMALL);
        }

        $objectKey = $this->sessionObjectKey($participantId, $sessionId, $filename);
        $inputStream = fopen('php://input', 'rb');

        try {
            $uploaded = $this->s3Client->uploadPart(new UploadPartRequest([
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
                'UploadId' => $uploadId,
                'PartNumber' => $partNumber,
                'Body' => $inputStream,
                'ContentLength' => $contentLength,
            ]));
            $etag = (string) $uploaded->getETag();
        } catch (HttpException $e) {
            throw new SystemException(ErrorCode::STORAGE_UPLOAD_FAILED, previous: $e);
        } finally {
            if (is_resource($inputStream)) {
                fclose($inputStream);
            }
        }

        if ($etag === '') {
            // A part with no ETag cannot be named in the completion manifest, so it is a failure
            // even though the request succeeded. Silence here would produce a "complete" call that
            // omits a part and an object short by 8 MB.
            throw new SystemException(ErrorCode::STORAGE_UPLOAD_FAILED);
        }

        return ['partNumber' => $partNumber, 'etag' => $etag, 'size' => $contentLength];
    }

    /**
     * Seal a multipart upload. The manifest is the client's part list, in ascending part order.
     *
     * @param list<array{partNumber: int, etag: string}> $parts
     * @return array{success: bool, objectKey: string, url: string, parts: int}
     */
    public function completeSessionMultipart(
        string $filename,
        string $participantId,
        string $sessionId,
        string $uploadId,
        array $parts,
    ): array {
        if ($parts === []) {
            throw new ValidationException(ErrorCode::STORAGE_PART_MANIFEST_EMPTY);
        }

        $objectKey = $this->sessionObjectKey($participantId, $sessionId, $filename);

        usort($parts, static fn (array $a, array $b): int => $a['partNumber'] <=> $b['partNumber']);
        $completed = [];
        foreach ($parts as $part) {
            $completed[] = new CompletedPart([
                'PartNumber' => (int) $part['partNumber'],
                'ETag' => (string) $part['etag'],
            ]);
        }

        try {
            $this->s3Client->completeMultipartUpload(new CompleteMultipartUploadRequest([
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
                'UploadId' => $uploadId,
                'MultipartUpload' => new CompletedMultipartUpload(['Parts' => $completed]),
            ]))->resolve();
        } catch (HttpException $e) {
            throw new SystemException(ErrorCode::STORAGE_UPLOAD_FAILED, previous: $e);
        }

        return [
            'success' => true,
            'objectKey' => $objectKey,
            'url' => $this->objectUrl($objectKey),
            'parts' => count($completed),
        ];
    }

    /**
     * Discard an open multipart upload and the parts it holds.
     *
     * Called when the client gives up. Not housekeeping: an abandoned multipart upload keeps its
     * uploaded parts in the bucket and they are billed, invisible to every `ListObjects` view, until
     * a lifecycle rule reaps them. A client that walks out of the room mid-upload is the normal
     * case here, so the abort is part of the protocol rather than a tidy-up.
     */
    public function abortSessionMultipart(
        string $filename,
        string $participantId,
        string $sessionId,
        string $uploadId,
    ): void {
        $objectKey = $this->sessionObjectKey($participantId, $sessionId, $filename);
        try {
            $this->s3Client->abortMultipartUpload(new AbortMultipartUploadRequest([
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
                'UploadId' => $uploadId,
            ]))->resolve();
        } catch (HttpException $e) {
            throw new SystemException(ErrorCode::STORAGE_UPLOAD_FAILED, previous: $e);
        }
    }

    // ── The read side of the session prefix (IP-149) ─────────────────────────────────────────
    //
    // Three operations, all scoped UNDER `datasets/monad-app-sessions/` and all read-only. They
    // exist for the register: the backfill lists what is there, the multipart path reads back a
    // sidecar it sealed in parts, and the admin hands an operator a short-lived link to one file.
    // None of them accepts a key from a caller; every key is derived through the same sanitisers
    // the write side uses, so this class cannot be talked into reading outside the prefix.

    public const SESSIONS_PREFIX = 'datasets/monad-app-sessions/';

    /**
     * Every object under the session prefix, as `participant/session` => [filename => {bytes, last_modified}].
     *
     * Paginated through to the end: the prefix holds every session since the lab stack was written
     * and one page is 1 000 keys. `$participant` narrows the listing to one pseudonym.
     *
     * @return array<string, array<string, array{bytes: int, last_modified: string|null}>>
     */
    public function listSessionObjects(?string $participant = null): array
    {
        $prefix = self::SESSIONS_PREFIX;
        if ($participant !== null && $participant !== '') {
            $prefix .= $this->sanitizeIdentifier($participant) . '/';
        }

        $out = [];
        try {
            $result = $this->s3Client->listObjectsV2(new ListObjectsV2Request([
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
            ]));
            // async-aws iterates continuation tokens for us when the result is walked.
            foreach ($result->getContents() as $object) {
                $key = (string) $object->getKey();
                $rel = substr($key, strlen(self::SESSIONS_PREFIX));
                $parts = explode('/', $rel);
                if (count($parts) !== 3 || $parts[2] === '') {
                    continue; // not participant/session/filename — a stray key, not a session artefact
                }
                [$p, $sid, $filename] = $parts;
                $out["$p/$sid"][$filename] = [
                    'bytes' => (int) $object->getSize(),
                    'last_modified' => $object->getLastModified()?->format(\DateTimeInterface::ATOM),
                ];
            }
        } catch (HttpException $e) {
            throw new SystemException(ErrorCode::STORAGE_S3_UNAVAILABLE, previous: $e);
        }
        ksort($out);

        return $out;
    }

    /**
     * One session artefact's bytes, or null when the object is absent. Bounded by `$maxBytes`:
     * a larger object returns null rather than being read, because the only artefacts this is for
     * are sidecars and a sidecar over the cap is not a sidecar.
     */
    public function getSessionObject(string $participantId, string $sessionId, string $filename, int $maxBytes): ?string
    {
        $objectKey = $this->sessionObjectKey($participantId, $sessionId, $filename);
        try {
            $result = $this->s3Client->getObject(new GetObjectRequest([
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
            ]));
            $length = $result->getContentLength();
            if ($length !== null && $length > $maxBytes) {
                return null;
            }
            $body = $result->getBody()->getContentAsString();

            return strlen($body) > $maxBytes ? null : $body;
        } catch (HttpException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return null;
            }
            throw new SystemException(ErrorCode::STORAGE_S3_UNAVAILABLE, previous: $e);
        }
    }

    /**
     * A short-lived GET link to one session artefact, for the admin's artefact table.
     *
     * Fifteen minutes by default (`HETZNER_S3_PRESIGNED_URL_EXPIRY`), the same window the upload
     * links get. The link is only ever rendered on `/admin`, which is tailnet-only and
     * superadmin-only; it is not an API response.
     */
    public function presignedSessionGetUrl(string $participantId, string $sessionId, string $filename): string
    {
        $request = new GetObjectRequest([
            'Bucket' => $this->bucket,
            'Key' => $this->sessionObjectKey($participantId, $sessionId, $filename),
        ]);

        return $this->s3Client->presign($request, new \DateTimeImmutable($this->presignedUrlExpiry));
    }

    /**
     * Get the maximum allowed file size in bytes
     */
    public function getMaxFileSize(): int
    {
        return self::MAX_FILE_SIZE;
    }

    /**
     * Get allowed content types
     *
     * @return string[]
     */
    public function getAllowedContentTypes(): array
    {
        return self::ALLOWED_CONTENT_TYPES;
    }
}
