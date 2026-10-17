<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use ApiPlatform\OpenApi\Model\Tag;

/**
 * OpenAPI decorator for S3 storage endpoints
 */
readonly class StorageDecorator implements OpenApiFactoryInterface
{
    public function __construct(private OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $paths = $openApi->getPaths();

        // Add /api/storage/test GET - Test S3 connection
        $testPath = new PathItem(
            get: new Operation(
                tags: ['Storage'],
                summary: 'Test S3 connection',
                description: 'Tests the connection to the configured S3 bucket. Use this to verify your S3 configuration is correct.',
                responses: [
                    '200' => new Response(
                        description: 'Connection test result',
                        content: new \ArrayObject([
                            'application/json' => new MediaType(
                                schema: new \ArrayObject([
                                    'type' => 'object',
                                    'properties' => [
                                        'success' => [
                                            'type' => 'boolean',
                                            'example' => true,
                                        ],
                                        'bucket' => [
                                            'type' => 'string',
                                            'example' => 'my-bucket',
                                        ],
                                        'region' => [
                                            'type' => 'string',
                                            'example' => 'eu-central-1',
                                        ],
                                        'message' => [
                                            'type' => 'string',
                                            'example' => 'Successfully connected to S3 bucket',
                                        ],
                                    ],
                                ])
                            ),
                        ])
                    ),
                    '401' => $this->getUnauthorizedResponse(),
                ],
                security: [['Bearer' => []]]
            )
        );
        $paths->addPath('/api/storage/test', $testPath);

        // Add /api/storage/config GET - Get storage configuration
        $configPath = new PathItem(
            get: new Operation(
                tags: ['Storage'],
                summary: 'Get storage configuration',
                description: 'Returns the storage service configuration including allowed file types and size limits',
                responses: [
                    '200' => new Response(
                        description: 'Storage configuration',
                        content: new \ArrayObject([
                            'application/json' => new MediaType(
                                schema: new \ArrayObject([
                                    'type' => 'object',
                                    'properties' => [
                                        'maxFileSize' => [
                                            'type' => 'integer',
                                            'example' => 52428800,
                                            'description' => 'Maximum file size in bytes',
                                        ],
                                        'maxFileSizeMB' => [
                                            'type' => 'integer',
                                            'example' => 50,
                                            'description' => 'Maximum file size in megabytes',
                                        ],
                                        'allowedContentTypes' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'string'],
                                            'example' => ['application/octet-stream', 'application/json', 'text/csv', 'text/plain'],
                                            'description' => 'List of allowed MIME types',
                                        ],
                                    ],
                                ])
                            ),
                        ])
                    ),
                    '401' => $this->getUnauthorizedResponse(),
                ],
                security: [['Bearer' => []]]
            )
        );
        $paths->addPath('/api/storage/config', $configPath);

        // Add /api/storage/upload POST - Upload file to S3 (direct stream)
        $uploadPath = new PathItem(
            post: new Operation(
                tags: ['Storage'],
                summary: 'Upload file to S3 (direct stream)',
                description: 'Uploads a file directly to S3 by streaming from request body. NO temp file is created on the backend. Send raw binary body with X-Filename header. Max file size: 50 MB.',
                parameters: [
                    new Parameter(
                        name: 'X-Filename',
                        in: 'header',
                        description: 'Original filename (required)',
                        required: true,
                        schema: ['type' => 'string', 'example' => 'ble_data.csv']
                    ),
                ],
                requestBody: new RequestBody(
                    description: 'Raw binary file content (NOT multipart/form-data)',
                    required: true,
                    content: new \ArrayObject([
                        'application/octet-stream' => new MediaType(
                            schema: new \ArrayObject([
                                'type' => 'string',
                                'format' => 'binary',
                            ])
                        ),
                    ])
                ),
                responses: [
                    '200' => new Response(
                        description: 'File uploaded successfully',
                        content: new \ArrayObject([
                            'application/json' => new MediaType(
                                schema: new \ArrayObject([
                                    'type' => 'object',
                                    'properties' => [
                                        'success' => [
                                            'type' => 'boolean',
                                            'example' => true,
                                        ],
                                        'objectKey' => [
                                            'type' => 'string',
                                            'example' => 'uploads/550e8400-e29b-41d4-a716-446655440000/a1b2c3d4/ble_data.csv',
                                            'description' => 'S3 object key where the file was stored',
                                        ],
                                        'url' => [
                                            'type' => 'string',
                                            'example' => 'https://fsn1.your-objectstorage.com/monad-knowledge/uploads/...',
                                            'description' => 'URL of the uploaded file',
                                        ],
                                        'size' => [
                                            'type' => 'integer',
                                            'example' => 1048576,
                                            'description' => 'File size in bytes',
                                        ],
                                        'contentType' => [
                                            'type' => 'string',
                                            'example' => 'text/csv',
                                            'description' => 'MIME type of the uploaded file',
                                        ],
                                    ],
                                ])
                            ),
                        ])
                    ),
                    '400' => new Response(
                        description: 'Bad request - validation errors',
                        content: new \ArrayObject([
                            'application/json' => new MediaType(
                                schema: new \ArrayObject([
                                    'type' => 'object',
                                    'properties' => [
                                        'code' => [
                                            'type' => 'string',
                                            'example' => 'STORAGE_301',
                                        ],
                                        'message' => [
                                            'type' => 'string',
                                            'example' => 'File size exceeds maximum allowed (50 MB)',
                                        ],
                                    ],
                                ])
                            ),
                        ])
                    ),
                    '401' => $this->getUnauthorizedResponse(),
                    '500' => new Response(
                        description: 'Upload failed',
                        content: new \ArrayObject([
                            'application/json' => new MediaType(
                                schema: new \ArrayObject([
                                    'type' => 'object',
                                    'properties' => [
                                        'code' => [
                                            'type' => 'string',
                                            'example' => 'STORAGE_300',
                                        ],
                                        'message' => [
                                            'type' => 'string',
                                            'example' => 'File upload failed',
                                        ],
                                    ],
                                ])
                            ),
                        ])
                    ),
                ],
                security: [['Bearer' => []]]
            )
        );
        $paths->addPath('/api/storage/upload', $uploadPath);

        // Add /api/storage/upload-url POST - Get pre-signed URL
        $uploadUrlPath = new PathItem(
            post: new Operation(
                tags: ['Storage'],
                summary: 'Get pre-signed URL for S3 upload',
                description: 'Generates a pre-signed URL that allows direct upload to S3. The client should use this URL to upload the file directly to S3 using a PUT request.',
                requestBody: new RequestBody(
                    description: 'File metadata for pre-signed URL generation',
                    required: true,
                    content: new \ArrayObject([
                        'application/json' => new MediaType(
                            schema: new \ArrayObject([
                                'type' => 'object',
                                'required' => ['filename', 'contentType', 'fileSize'],
                                'properties' => [
                                    'filename' => [
                                        'type' => 'string',
                                        'example' => 'ble_data_2024.csv',
                                        'description' => 'Original filename',
                                    ],
                                    'contentType' => [
                                        'type' => 'string',
                                        'example' => 'text/csv',
                                        'description' => 'MIME type of the file',
                                    ],
                                    'fileSize' => [
                                        'type' => 'integer',
                                        'example' => 1048576,
                                        'description' => 'File size in bytes (max 50 MB)',
                                    ],
                                ],
                            ])
                        ),
                    ])
                ),
                responses: [
                    '200' => new Response(
                        description: 'Pre-signed URL generated successfully',
                        content: new \ArrayObject([
                            'application/json' => new MediaType(
                                schema: new \ArrayObject([
                                    'type' => 'object',
                                    'properties' => [
                                        'uploadUrl' => [
                                            'type' => 'string',
                                            'example' => 'https://fsn1.your-objectstorage.com/monad-knowledge/uploads/...?X-Amz-...',
                                            'description' => 'Pre-signed URL for uploading. Use HTTP PUT with the file content.',
                                        ],
                                        'objectKey' => [
                                            'type' => 'string',
                                            'example' => 'uploads/550e8400-e29b-41d4-a716-446655440000/a1b2c3d4/ble_data.csv',
                                            'description' => 'S3 object key where the file will be stored',
                                        ],
                                        'expiresAt' => [
                                            'type' => 'string',
                                            'format' => 'date-time',
                                            'example' => '2024-01-15T10:30:00+00:00',
                                            'description' => 'URL expiration timestamp (ISO 8601)',
                                        ],
                                    ],
                                ])
                            ),
                        ])
                    ),
                    '400' => new Response(
                        description: 'Bad request - validation errors',
                        content: new \ArrayObject([
                            'application/json' => new MediaType(
                                schema: new \ArrayObject([
                                    'type' => 'object',
                                    'properties' => [
                                        'code' => [
                                            'type' => 'string',
                                            'example' => 'STORAGE_301',
                                        ],
                                        'message' => [
                                            'type' => 'string',
                                            'example' => 'File size exceeds maximum allowed (50 MB)',
                                        ],
                                    ],
                                ])
                            ),
                        ])
                    ),
                    '401' => $this->getUnauthorizedResponse(),
                ],
                security: [['Bearer' => []]]
            )
        );
        $paths->addPath('/api/storage/upload-url', $uploadUrlPath);

        // Ensure Storage tag exists
        $rootTags = $openApi->getTags();
        $hasStorageTag = false;

        foreach ($rootTags as $tag) {
            if ($tag->getName() === 'Storage') {
                $hasStorageTag = true;
                break;
            }
        }

        if (!$hasStorageTag) {
            $newTags = $rootTags;
            $newTags[] = new Tag('Storage', 'S3 file storage endpoints for BLE data uploads');
            $openApi = $openApi->withTags($newTags);
        }

        return $openApi;
    }

    private function getUnauthorizedResponse(): Response
    {
        return new Response(
            description: 'Unauthorized - missing or invalid token',
            content: new \ArrayObject([
                'application/json' => new MediaType(
                    schema: new \ArrayObject([
                        'type' => 'object',
                        'properties' => [
                            'code' => [
                                'type' => 'string',
                                'example' => 'AUTH_006',
                            ],
                            'message' => [
                                'type' => 'string',
                                'example' => 'Authentication required',
                            ],
                        ],
                    ])
                ),
            ])
        );
    }
}
