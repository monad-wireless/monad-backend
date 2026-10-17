<?php

namespace App\Controller;

use App\Constants\ErrorCode;
use App\Entity\User;
use App\Exception\AuthException;
use App\Exception\ValidationException;
use App\Service\LabSessionRegister;
use App\Service\LabTelemetry;
use App\Service\S3Service;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use OpenApi\Attributes as OA;

class S3Controller extends AbstractController
{
    /** Sidecars are a few kB; anything larger is not a sidecar and is streamed without inspection. */
    private const MAX_INSPECTABLE_SIDECAR = 1024 * 1024;

    public function __construct(
        private S3Service $s3Service,
        private LabTelemetry $telemetry,
        private LabSessionRegister $register,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/api/storage/upload-url', name: 'api_storage_upload_url', methods: ['POST'])]
    #[OA\Post(
        path: '/api/storage/upload-url',
        summary: 'Get pre-signed URL for S3 upload',
        description: 'Generates a pre-signed URL that allows direct upload to S3. The client should use this URL to upload the file directly to S3 using a PUT request.',
        security: [['Bearer' => []]],
        tags: ['Storage']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['filename', 'contentType', 'fileSize'],
            properties: [
                new OA\Property(
                    property: 'filename',
                    type: 'string',
                    example: 'ble_data_2024.csv',
                    description: 'Original filename'
                ),
                new OA\Property(
                    property: 'contentType',
                    type: 'string',
                    example: 'text/csv',
                    description: 'MIME type of the file. Allowed: application/octet-stream, application/json, text/csv, text/plain'
                ),
                new OA\Property(
                    property: 'fileSize',
                    type: 'integer',
                    example: 1048576,
                    description: 'File size in bytes (max 50 MB)'
                )
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Pre-signed URL generated successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'uploadUrl',
                    type: 'string',
                    example: 'https://fsn1.your-objectstorage.com/monad-knowledge/uploads/user-id/uuid/filename.csv?X-Amz-...',
                    description: 'Pre-signed URL for uploading. Use HTTP PUT with the file content.'
                ),
                new OA\Property(
                    property: 'objectKey',
                    type: 'string',
                    example: 'uploads/550e8400-e29b-41d4-a716-446655440000/a1b2c3d4/ble_data.csv',
                    description: 'S3 object key where the file will be stored'
                ),
                new OA\Property(
                    property: 'expiresAt',
                    type: 'string',
                    format: 'date-time',
                    example: '2024-01-15T10:30:00+00:00',
                    description: 'URL expiration timestamp (ISO 8601)'
                )
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request - validation errors',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'STORAGE_301', description: 'Error code'),
                new OA\Property(property: 'message', type: 'string', example: 'File size exceeds maximum allowed (50 MB)', description: 'Human-readable error message')
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized - missing or invalid token',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'AUTH_006', description: 'Error code'),
                new OA\Property(property: 'message', type: 'string', example: 'Authentication required', description: 'Human-readable error message')
            ]
        )
    )]
    #[OA\Response(
        response: 503,
        description: 'Service unavailable - S3 error',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'STORAGE_304', description: 'Error code'),
                new OA\Property(property: 'message', type: 'string', example: 'Storage service is temporarily unavailable', description: 'Human-readable error message')
            ]
        )
    )]
    public function getUploadUrl(Request $request): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw new AuthException(ErrorCode::AUTH_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        // Validate required fields
        if (!isset($data['filename']) || empty(trim($data['filename']))) {
            throw new ValidationException(ErrorCode::STORAGE_FILENAME_REQUIRED);
        }

        if (!isset($data['contentType'])) {
            throw new ValidationException(ErrorCode::STORAGE_INVALID_FILE_TYPE);
        }

        if (!isset($data['fileSize']) || !is_int($data['fileSize']) || $data['fileSize'] <= 0) {
            throw new ValidationException(ErrorCode::STORAGE_FILE_TOO_LARGE);
        }

        $result = $this->s3Service->generateUploadUrl(
            filename: $data['filename'],
            contentType: $data['contentType'],
            fileSize: $data['fileSize'],
            userId: $user->getId()->toRfc4122(),
        );

        return $this->json($result, Response::HTTP_OK);
    }

    #[Route('/api/storage/config', name: 'api_storage_config', methods: ['GET'])]
    #[OA\Get(
        path: '/api/storage/config',
        summary: 'Get storage configuration',
        description: 'Returns the storage service configuration including allowed file types and size limits',
        security: [['Bearer' => []]],
        tags: ['Storage']
    )]
    #[OA\Response(
        response: 200,
        description: 'Storage configuration',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'maxFileSize',
                    type: 'integer',
                    example: 52428800,
                    description: 'Maximum file size in bytes'
                ),
                new OA\Property(
                    property: 'maxFileSizeMB',
                    type: 'integer',
                    example: 50,
                    description: 'Maximum file size in megabytes'
                ),
                new OA\Property(
                    property: 'allowedContentTypes',
                    type: 'array',
                    items: new OA\Items(type: 'string'),
                    example: ['application/octet-stream', 'application/json', 'text/csv', 'text/plain'],
                    description: 'List of allowed MIME types'
                )
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized - missing or invalid token',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'AUTH_006', description: 'Error code'),
                new OA\Property(property: 'message', type: 'string', example: 'Authentication required', description: 'Human-readable error message')
            ]
        )
    )]
    public function getConfig(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw new AuthException(ErrorCode::AUTH_UNAUTHORIZED);
        }

        return $this->json([
            'maxFileSize' => $this->s3Service->getMaxFileSize(),
            'maxFileSizeMB' => (int) ($this->s3Service->getMaxFileSize() / 1024 / 1024),
            'allowedContentTypes' => $this->s3Service->getAllowedContentTypes(),
        ]);
    }

    #[Route('/api/storage/upload', name: 'api_storage_upload', methods: ['POST'])]
    #[OA\Post(
        path: '/api/storage/upload',
        summary: 'Upload file to S3 (direct stream)',
        description: 'Uploads a file directly to S3 by streaming from request body. NO temp file is created on the backend. Send raw binary body with required headers.',
        security: [['Bearer' => []]],
        tags: ['Storage']
    )]
    #[OA\Header(
        header: 'X-Filename',
        description: 'Original filename',
        required: true,
        schema: new OA\Schema(type: 'string', example: 'ble_data.csv')
    )]
    #[OA\RequestBody(
        required: true,
        description: 'Raw binary file content (NOT multipart/form-data)',
        content: new OA\MediaType(
            mediaType: 'application/octet-stream',
            schema: new OA\Schema(type: 'string', format: 'binary')
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'File uploaded successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'objectKey',
                    type: 'string',
                    example: 'uploads/550e8400-e29b-41d4-a716-446655440000/a1b2c3d4/ble_data.csv',
                    description: 'S3 object key where the file was stored'
                ),
                new OA\Property(
                    property: 'url',
                    type: 'string',
                    example: 'https://fsn1.your-objectstorage.com/monad-knowledge/uploads/...',
                    description: 'URL of the uploaded file'
                ),
                new OA\Property(property: 'size', type: 'integer', example: 1048576, description: 'File size in bytes'),
                new OA\Property(property: 'contentType', type: 'string', example: 'application/octet-stream', description: 'MIME type')
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request - validation errors',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'STORAGE_301'),
                new OA\Property(property: 'message', type: 'string', example: 'File size exceeds maximum allowed (50 MB)')
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized - missing or invalid token',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'AUTH_006'),
                new OA\Property(property: 'message', type: 'string', example: 'Authentication required')
            ]
        )
    )]
    #[OA\Response(
        response: 500,
        description: 'Upload failed',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'STORAGE_300'),
                new OA\Property(property: 'message', type: 'string', example: 'File upload failed')
            ]
        )
    )]
    public function upload(Request $request): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw new AuthException(ErrorCode::AUTH_UNAUTHORIZED);
        }

        // Get metadata from headers
        $filename = $request->headers->get('X-Filename');
        $contentType = $request->headers->get('Content-Type', 'application/octet-stream');
        $contentLength = (int) $request->headers->get('Content-Length', 0);

        if (!$filename) {
            throw new ValidationException(ErrorCode::STORAGE_FILENAME_REQUIRED);
        }

        if ($contentLength <= 0) {
            throw new ValidationException(ErrorCode::STORAGE_FILE_TOO_LARGE);
        }

        // Direct stream from php://input to S3 - no temp file!
        $result = $this->s3Service->directStreamUpload(
            filename: $filename,
            contentType: $contentType,
            contentLength: $contentLength,
            userId: $user->getId()->toRfc4122(),
        );

        return $this->json($result, Response::HTTP_OK);
    }

    #[Route('/api/storage/session-upload', name: 'api_storage_session_upload', methods: ['POST'])]
    #[OA\Post(
        path: '/api/storage/session-upload',
        summary: 'Upload one lab-session artefact to S3 (direct stream)',
        description: 'Streams one artefact of a lab session straight from the request body to object storage; no temp file is created. Artefacts are stored at datasets/monad-app-sessions/{participantId}/{sessionId}/{filename}, the same convention the csid fleet captures use, so a phone session and a radio capture are siblings in one bucket. Upload the streams first and metadata.json last: its presence marks the session complete.',
        security: [['Bearer' => []]],
        tags: ['Storage']
    )]
    #[OA\Header(
        header: 'X-Filename',
        description: 'Original filename',
        required: true,
        schema: new OA\Schema(type: 'string', example: 'ble_data.tsv')
    )]
    #[OA\Header(
        header: 'X-Session-Id',
        description: 'Lab session UUID',
        required: true,
        schema: new OA\Schema(type: 'string', example: '550e8400-e29b-41d4-a716-446655440000')
    )]
    #[OA\Header(
        header: 'X-Participant-Id',
        description: 'Pseudonymous participant key. Never an e-mail: the account belongs to the game, the dataset carries only the pseudonym.',
        required: false,
        schema: new OA\Schema(type: 'string', example: '0198f2c1-1f3f-7c3a-9a1d-2f2b0a5f2f11')
    )]
    #[OA\RequestBody(
        required: true,
        description: 'Raw binary file content (NOT multipart/form-data)',
        content: new OA\MediaType(
            mediaType: 'text/tab-separated-values',
            schema: new OA\Schema(type: 'string', format: 'binary')
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'File uploaded successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'objectKey',
                    type: 'string',
                    example: 'experiments/2024/01/15/550e8400-e29b-41d4-a716-446655440000/a1b2c3d4/ble_data.tsv',
                    description: 'S3 object key where the file was stored'
                ),
                new OA\Property(
                    property: 'url',
                    type: 'string',
                    example: 'https://fsn1.your-objectstorage.com/monad-knowledge/datasets/monad-app-sessions/...',
                    description: 'URL of the uploaded file'
                ),
                new OA\Property(property: 'size', type: 'integer', example: 1048576, description: 'File size in bytes'),
                new OA\Property(property: 'contentType', type: 'string', example: 'text/tab-separated-values', description: 'MIME type')
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request - validation errors',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'STORAGE_301'),
                new OA\Property(property: 'message', type: 'string', example: 'File size exceeds maximum allowed (50 MB)')
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized - missing or invalid token',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'AUTH_006'),
                new OA\Property(property: 'message', type: 'string', example: 'Authentication required')
            ]
        )
    )]
    #[OA\Response(
        response: 500,
        description: 'Upload failed',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'STORAGE_300'),
                new OA\Property(property: 'message', type: 'string', example: 'File upload failed')
            ]
        )
    )]
    public function sessionUpload(Request $request): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw new AuthException(ErrorCode::AUTH_UNAUTHORIZED);
        }

        $filename = $request->headers->get('X-Filename');
        $sessionId = $request->headers->get('X-Session-Id');
        $contentType = $request->headers->get('Content-Type', 'text/tab-separated-values');
        $contentLength = (int) $request->headers->get('Content-Length', 0);

        // The client may carry its own pseudonym; the authenticated user id is the fallback so a
        // session can never be filed under an unattributable prefix.
        $participantId = $request->headers->get('X-Participant-Id') ?: $user->getId()->toRfc4122();

        if (!$filename) {
            throw new ValidationException(ErrorCode::STORAGE_FILENAME_REQUIRED);
        }

        if (!$sessionId) {
            throw new ValidationException(ErrorCode::STORAGE_EXPERIMENT_ID_REQUIRED);
        }

        if ($contentLength <= 0) {
            throw new ValidationException(ErrorCode::STORAGE_FILE_TOO_LARGE);
        }

        // The sidecar is small and is the only artefact worth reading here; the sample streams are
        // forwarded to object storage untouched. Read it before streaming, because php://input can
        // only be consumed once.
        $sidecar = null;
        if ($filename === 'metadata.json' && $contentLength <= self::MAX_INSPECTABLE_SIDECAR) {
            $sidecar = file_get_contents('php://input') ?: null;
        }

        // Direct stream from php://input to object storage — no temp file.
        //
        // Timed and failure-counted because this is the hop the whole instrument depends on and the
        // one nobody could see: a participant on a lab AP with no route out fails here, and until
        // this was instrumented the only trace of it was a session that never appeared in S3.
        $startedAt = microtime(true);
        try {
            $result = $this->s3Service->directSessionStreamUpload(
                filename: $filename,
                contentType: $contentType,
                contentLength: $contentLength,
                participantId: $participantId,
                sessionId: $sessionId,
                body: $sidecar,
            );
        } catch (\Throwable $e) {
            // Counted, logged, and RETHROWN. The client must still see the failure so its retry
            // logic runs — swallowing it here would turn a recoverable upload into a lost session.
            $this->telemetry->artefactFailed($filename);
            // The reason is in the MESSAGE, not only in the context array, and that is deliberate.
            // This project installs no MonologBundle, so `LoggerInterface` resolves to Symfony's
            // built-in `HttpKernel\Log\Logger`, which interpolates `{placeholders}` from the context
            // and then DISCARDS everything else. On 2026-08-20 that turned twenty-two real upload
            // failures into twenty-two identical reason-free lines in Loki, and the one field that
            // mattered — `error` — never left the process. The context array is kept as-is so a
            // future structured handler gets the fields; the placeholders are what makes the line
            // readable today.
            $this->logger->error(
                '[lab-upload] artefact FAILED: {artefact} for session {session_id} '
                . '({bytes} bytes, participant {participant}): {error}',
                [
                    'session_id' => $sessionId,
                    'participant' => $participantId,
                    'artefact' => $filename,
                    'bytes' => $contentLength,
                    'error' => $e->getMessage(),
                ]
            );
            throw $e;
        }
        $elapsed = microtime(true) - $startedAt;

        $this->telemetry->artefactAccepted($filename);
        $this->telemetry->artefactStored($filename, $contentLength, $elapsed);
        if ($sidecar !== null) {
            $this->telemetry->sessionCompleted($sidecar);
        }

        // IP-149 — the register. AFTER the S3 write succeeded and in the same request,
        // so a failure here is a logged 500 the client retries, never a session that
        // exists on S3 with no row. The sidecar completes the row; every other
        // artefact adds itself to it.
        $this->register->artefactStored($sessionId, $participantId, $user, $filename, $contentLength, $contentType, 'single');
        if ($sidecar !== null) {
            $this->register->sessionCompleted($sessionId, $participantId, $user, $sidecar);
        }

        // The counter above says how many artefacts arrived; it cannot say WHICH, for WHICH session,
        // or how big. This hop — phone to archive — is the most fragile step in the whole instrument
        // and until now it wrote nothing to the journal: on 2026-08-19 the `api` service produced 37
        // log lines in seven hours and every one was an Internet scanner probing for `.env`. A
        // session that failed to upload was therefore indistinguishable from a session nobody ran.
        //
        // Placeholders rather than context-only, for the reason spelled out on the failure path
        // above: without MonologBundle the context array never reaches the journal, so a success
        // line that names nothing is as blind as the failure line was.
        $this->logger->info(
            '[lab-upload] artefact stored: {artefact} for session {session_id} '
            . '({bytes} bytes, {content_type}, {seconds}s, participant {participant}, '
            . 'session_complete={session_complete})',
            [
                'session_id' => $sessionId,
                'participant' => $participantId,
                'artefact' => $filename,
                'bytes' => $contentLength,
                'content_type' => $contentType,
                'seconds' => round($elapsed, 3),
                // `metadata.json` arrives last, by client contract, so this flag is the line that
                // marks a session complete rather than merely in progress.
                //
                // Rendered as a string: Symfony's minimal logger interpolates scalars with
                // `strtr`, and a raw PHP bool would interpolate as "1" or as the empty string —
                // and an empty string is exactly the value you cannot tell from a missing field.
                'session_complete' => $sidecar !== null ? 'true' : 'false',
            ]
        );

        return $this->json($result, Response::HTTP_OK);
    }

    /**
     * The multipart family: begin, part, complete, abort.
     *
     * FOUR ROUTES RATHER THAN ONE, AND WHY. The single-body `session-upload` above is correct for a
     * TSV and wrong for a mesh, and the boundary is the transport rather than any limit this
     * application sets. On 2026-08-26 a survey walk lost `mesh.ply` (102.94 MB) and
     * `worldmap.armap` (30.05 MB) while nine smaller artefacts went up cleanly: the phone's
     * connection dropped mid-body and each of the four client retries restarted the same doomed
     * request. Nothing rejected them — nginx admits 520 MB, PHP admits 500 MB, and the bucket never
     * saw a byte.
     *
     * What multipart changes is the unit of loss: a dropped connection costs one part, and the retry
     * re-sends that part alone.
     *
     * **The object key is never accepted from the client.** Every one of the four requests carries
     * `X-Filename` / `X-Session-Id` / `X-Participant-Id` and the key is re-derived from them with
     * the same sanitizers the single-body path uses. A client-carried key would be a client-chosen
     * write path into the project's bucket.
     */
    #[Route('/api/storage/session-upload/begin', name: 'api_storage_session_upload_begin', methods: ['POST'])]
    #[OA\Post(
        path: '/api/storage/session-upload/begin',
        summary: 'Open a multipart upload for one large lab-session artefact',
        description: 'Opens an S3 multipart upload and returns its id plus the derived object key. Use for artefacts too large to survive a single request (mesh.ply, worldmap.armap); small streams should keep using POST /api/storage/session-upload. Send X-Total-Bytes so the size is validated before any part is transferred.',
        security: [['Bearer' => []]],
        tags: ['Storage']
    )]
    #[OA\Response(
        response: 200,
        description: 'Multipart upload opened',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'uploadId', type: 'string', example: '2~abc123'),
                new OA\Property(property: 'objectKey', type: 'string', example: 'datasets/monad-app-sessions/p-1/s-1/mesh.ply'),
                new OA\Property(property: 'partSizeHint', type: 'integer', example: 5242880, description: 'Smallest interior part S3 will accept'),
            ]
        )
    )]
    public function sessionUploadBegin(Request $request): JsonResponse
    {
        [$filename, $sessionId, $participantId] = $this->sessionArtefactIdentity($request);
        $contentType = $request->headers->get('X-Artefact-Content-Type', 'application/octet-stream');
        $totalBytes = (int) $request->headers->get('X-Total-Bytes', '0');

        if ($totalBytes <= 0) {
            throw new ValidationException(ErrorCode::STORAGE_FILE_TOO_LARGE);
        }

        $result = $this->s3Service->beginSessionMultipart(
            filename: $filename,
            contentType: $contentType,
            totalBytes: $totalBytes,
            participantId: $participantId,
            sessionId: $sessionId,
        );

        $this->logger->info(
            '[lab-upload] multipart OPENED: {artefact} for session {session_id} '
            . '({bytes} bytes, participant {participant}, upload {upload_id})',
            [
                'session_id' => $sessionId,
                'participant' => $participantId,
                'artefact' => $filename,
                'bytes' => $totalBytes,
                'upload_id' => $result['uploadId'],
            ]
        );

        return $this->json($result, Response::HTTP_OK);
    }

    #[Route('/api/storage/session-upload/part', name: 'api_storage_session_upload_part', methods: ['POST'])]
    #[OA\Post(
        path: '/api/storage/session-upload/part',
        summary: 'Stream one part of an open multipart upload',
        description: 'Raw binary body, streamed straight to object storage. Part numbers are 1-based and every part except the last must be at least 5 MiB. Returns the ETag the completion manifest needs.',
        security: [['Bearer' => []]],
        tags: ['Storage']
    )]
    #[OA\Response(
        response: 200,
        description: 'Part stored',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'partNumber', type: 'integer', example: 3),
                new OA\Property(property: 'etag', type: 'string', example: '"9b2cf5…"'),
                new OA\Property(property: 'size', type: 'integer', example: 8388608),
            ]
        )
    )]
    public function sessionUploadPart(Request $request): JsonResponse
    {
        [$filename, $sessionId, $participantId] = $this->sessionArtefactIdentity($request);
        $uploadId = (string) $request->headers->get('X-Upload-Id', '');
        $partNumber = (int) $request->headers->get('X-Part-Number', '0');
        $isLastPart = $request->headers->get('X-Last-Part') === 'true';
        $contentLength = (int) $request->headers->get('Content-Length', '0');

        if ($uploadId === '') {
            throw new ValidationException(ErrorCode::STORAGE_UPLOAD_ID_REQUIRED);
        }

        $startedAt = microtime(true);
        try {
            $result = $this->s3Service->uploadSessionPart(
                filename: $filename,
                contentLength: $contentLength,
                participantId: $participantId,
                sessionId: $sessionId,
                uploadId: $uploadId,
                partNumber: $partNumber,
                isLastPart: $isLastPart,
            );
        } catch (\Throwable $e) {
            // Counted against the artefact, not against a synthetic "part" name: the operator's
            // question is which ARTEFACT is failing, and a per-part metric name would make one
            // stuck mesh look like thirteen unrelated failures.
            $this->telemetry->artefactFailed($filename);
            $this->logger->error(
                '[lab-upload] part FAILED: {artefact} part {part} for session {session_id} '
                . '({bytes} bytes, participant {participant}): {error}',
                [
                    'session_id' => $sessionId,
                    'participant' => $participantId,
                    'artefact' => $filename,
                    'part' => $partNumber,
                    'bytes' => $contentLength,
                    'error' => $e->getMessage(),
                ]
            );
            throw $e;
        }

        $this->logger->info(
            '[lab-upload] part stored: {artefact} part {part} for session {session_id} '
            . '({bytes} bytes, {seconds}s, participant {participant}, last={last})',
            [
                'session_id' => $sessionId,
                'participant' => $participantId,
                'artefact' => $filename,
                'part' => $partNumber,
                'bytes' => $contentLength,
                'seconds' => round(microtime(true) - $startedAt, 3),
                'last' => $isLastPart ? 'true' : 'false',
            ]
        );

        return $this->json($result, Response::HTTP_OK);
    }

    #[Route('/api/storage/session-upload/complete', name: 'api_storage_session_upload_complete', methods: ['POST'])]
    #[OA\Post(
        path: '/api/storage/session-upload/complete',
        summary: 'Seal a multipart upload',
        description: 'JSON body {"uploadId": "…", "parts": [{"partNumber": 1, "etag": "…"}, …]}. Parts may arrive in any order; they are sorted before the manifest is built.',
        security: [['Bearer' => []]],
        tags: ['Storage']
    )]
    #[OA\Response(
        response: 200,
        description: 'Object assembled',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'objectKey', type: 'string', example: 'datasets/monad-app-sessions/p-1/s-1/mesh.ply'),
                new OA\Property(property: 'url', type: 'string'),
                new OA\Property(property: 'parts', type: 'integer', example: 13),
            ]
        )
    )]
    public function sessionUploadComplete(Request $request): JsonResponse
    {
        [$filename, $sessionId, $participantId] = $this->sessionArtefactIdentity($request);
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            throw new ValidationException(ErrorCode::STORAGE_PART_MANIFEST_EMPTY);
        }
        $uploadId = (string) ($payload['uploadId'] ?? '');
        if ($uploadId === '') {
            throw new ValidationException(ErrorCode::STORAGE_UPLOAD_ID_REQUIRED);
        }
        $parts = [];
        foreach ((array) ($payload['parts'] ?? []) as $part) {
            if (!is_array($part) || !isset($part['partNumber'], $part['etag'])) {
                continue;
            }
            $parts[] = ['partNumber' => (int) $part['partNumber'], 'etag' => (string) $part['etag']];
        }

        $startedAt = microtime(true);
        try {
            $result = $this->s3Service->completeSessionMultipart(
                filename: $filename,
                participantId: $participantId,
                sessionId: $sessionId,
                uploadId: $uploadId,
                parts: $parts,
            );
        } catch (\Throwable $e) {
            $this->telemetry->artefactFailed($filename);
            $this->logger->error(
                '[lab-upload] multipart COMPLETE FAILED: {artefact} for session {session_id} '
                . '({parts} part(s), participant {participant}): {error}',
                [
                    'session_id' => $sessionId,
                    'participant' => $participantId,
                    'artefact' => $filename,
                    'parts' => count($parts),
                    'error' => $e->getMessage(),
                ]
            );
            throw $e;
        }
        $elapsed = microtime(true) - $startedAt;

        // Counted here and nowhere else on this path. `artefactStored` is what says an artefact
        // reached the archive, and on a multipart upload that is true at completion, not at the
        // last part — a part list that never completes leaves no object behind.
        $totalBytes = (int) $request->headers->get('X-Total-Bytes', '0');
        $this->telemetry->artefactAccepted($filename);
        $this->telemetry->artefactStored($filename, $totalBytes, $elapsed);

        // IP-149 — same register entry the single-body path writes, so a mesh that
        // arrived in parts and a TSV that arrived whole are one kind of row. A sidecar
        // is a few kB and takes the single-body path by client contract; if one ever
        // arrives in parts it is read back from the object just sealed.
        $user = $this->getUser();
        $this->register->artefactStored(
            $sessionId,
            $participantId,
            $user instanceof User ? $user : null,
            $filename,
            $totalBytes,
            (string) $request->headers->get('X-Artefact-Content-Type', 'application/octet-stream'),
            'multipart',
        );
        if ($filename === 'metadata.json') {
            $raw = $this->s3Service->getSessionObject($participantId, $sessionId, $filename, self::MAX_INSPECTABLE_SIDECAR);
            if ($raw !== null) {
                $this->register->sessionCompleted($sessionId, $participantId, $user instanceof User ? $user : null, $raw);
            }
        }

        $this->logger->info(
            '[lab-upload] artefact stored (multipart): {artefact} for session {session_id} '
            . '({bytes} bytes, {parts} part(s), {seconds}s, participant {participant})',
            [
                'session_id' => $sessionId,
                'participant' => $participantId,
                'artefact' => $filename,
                'bytes' => $totalBytes,
                'parts' => $result['parts'],
                'seconds' => round($elapsed, 3),
            ]
        );

        return $this->json($result, Response::HTTP_OK);
    }

    #[Route('/api/storage/session-upload/abort', name: 'api_storage_session_upload_abort', methods: ['POST'])]
    #[OA\Post(
        path: '/api/storage/session-upload/abort',
        summary: 'Discard an open multipart upload and its parts',
        description: 'JSON body {"uploadId": "…"}. Call this when the client gives up: abandoned parts stay in the bucket, are billed, and are invisible to an object listing.',
        security: [['Bearer' => []]],
        tags: ['Storage']
    )]
    #[OA\Response(response: 200, description: 'Upload discarded')]
    public function sessionUploadAbort(Request $request): JsonResponse
    {
        [$filename, $sessionId, $participantId] = $this->sessionArtefactIdentity($request);
        $payload = json_decode($request->getContent(), true);
        $uploadId = is_array($payload) ? (string) ($payload['uploadId'] ?? '') : '';
        if ($uploadId === '') {
            throw new ValidationException(ErrorCode::STORAGE_UPLOAD_ID_REQUIRED);
        }

        $this->s3Service->abortSessionMultipart(
            filename: $filename,
            participantId: $participantId,
            sessionId: $sessionId,
            uploadId: $uploadId,
        );

        $this->logger->warning(
            '[lab-upload] multipart ABORTED: {artefact} for session {session_id} '
            . '(participant {participant}, upload {upload_id}) — the client gave up, '
            . 'the artefact is still on the phone',
            [
                'session_id' => $sessionId,
                'participant' => $participantId,
                'artefact' => $filename,
                'upload_id' => $uploadId,
            ]
        );

        return $this->json(['success' => true], Response::HTTP_OK);
    }

    /**
     * The three identifiers every session-artefact request carries, validated once.
     *
     * @return array{0: string, 1: string, 2: string} filename, sessionId, participantId
     */
    private function sessionArtefactIdentity(Request $request): array
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AuthException(ErrorCode::AUTH_UNAUTHORIZED);
        }

        $filename = $request->headers->get('X-Filename');
        $sessionId = $request->headers->get('X-Session-Id');
        if (!$filename) {
            throw new ValidationException(ErrorCode::STORAGE_FILENAME_REQUIRED);
        }
        if (!$sessionId) {
            throw new ValidationException(ErrorCode::STORAGE_EXPERIMENT_ID_REQUIRED);
        }

        // Same fallback as the single-body path: the client may carry its own pseudonym, and the
        // authenticated user id stands in so a session can never land on an unattributable prefix.
        $participantId = $request->headers->get('X-Participant-Id') ?: $user->getId()->toRfc4122();

        return [$filename, $sessionId, $participantId];
    }

    #[Route('/api/storage/test', name: 'api_storage_test', methods: ['GET'])]
    #[OA\Get(
        path: '/api/storage/test',
        summary: 'Test S3 connection',
        description: 'Tests the connection to the configured S3 bucket. Use this to verify your S3 configuration is correct.',
        security: [['Bearer' => []]],
        tags: ['Storage']
    )]
    #[OA\Response(
        response: 200,
        description: 'Connection test result',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'bucket', type: 'string', example: 'my-bucket'),
                new OA\Property(property: 'region', type: 'string', example: 'eu-central-1'),
                new OA\Property(property: 'message', type: 'string', example: 'Successfully connected to S3 bucket')
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized - missing or invalid token',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'AUTH_006'),
                new OA\Property(property: 'message', type: 'string', example: 'Authentication required')
            ]
        )
    )]
    public function testConnection(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw new AuthException(ErrorCode::AUTH_UNAUTHORIZED);
        }

        $result = $this->s3Service->testConnection();

        return $this->json($result, Response::HTTP_OK);
    }
}
