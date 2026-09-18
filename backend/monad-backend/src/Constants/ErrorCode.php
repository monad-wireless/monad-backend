<?php

namespace App\Constants;

/**
 * Error codes for API responses
 * Format: CATEGORY_XXX where XXX is a 3-digit number
 *
 * Categories:
 * - AUTH: Authentication and authorization errors (001-099)
 * - VALIDATION: Input validation errors (100-199)
 * - RESOURCE: Resource-related errors (200-299)
 * - STORAGE: Object-storage errors (300-399)
 * - LAB: Lab instrument and ground-truth errors (400-499)
 * - SYSTEM: System and server errors (900-999)
 */
class ErrorCode
{
    // Authentication & Authorization (001-099)
    public const AUTH_INVALID_CREDENTIALS = 'AUTH_001';
    public const AUTH_EMAIL_NOT_FOUND = 'AUTH_002';
    public const AUTH_ACCOUNT_DISABLED = 'AUTH_003';
    public const AUTH_TOKEN_EXPIRED = 'AUTH_004';
    public const AUTH_TOKEN_INVALID = 'AUTH_005';
    public const AUTH_UNAUTHORIZED = 'AUTH_006';
    public const AUTH_EMAIL_ALREADY_EXISTS = 'AUTH_007';
    public const AUTH_ACCOUNT_DELETED = 'AUTH_008';

    // Validation Errors (100-199)
    public const VALIDATION_EMAIL_REQUIRED = 'VALIDATION_100';
    public const VALIDATION_EMAIL_INVALID = 'VALIDATION_101';
    public const VALIDATION_EMAIL_TOO_LONG = 'VALIDATION_102';
    public const VALIDATION_PASSWORD_REQUIRED = 'VALIDATION_103';
    public const VALIDATION_PASSWORD_EMPTY = 'VALIDATION_104';
    public const VALIDATION_PASSWORD_TOO_SHORT = 'VALIDATION_105';
    public const VALIDATION_PASSWORD_TOO_LONG = 'VALIDATION_106';
    public const VALIDATION_NAME_TOO_LONG = 'VALIDATION_107';
    /** IP-149 — the handset descriptor in a quest-start body. */
    public const VALIDATION_HANDSET_MALFORMED = 'VALIDATION_108';
    public const VALIDATION_HANDSET_TOO_LARGE = 'VALIDATION_109';
    /** IP-157 — the per-user notification surface (/api/me/notifications*, push-token, preferences). */
    public const VALIDATION_BODY_NOT_OBJECT = 'VALIDATION_110';
    public const VALIDATION_PUSH_TOKEN_REQUIRED = 'VALIDATION_111';
    public const VALIDATION_PUSH_PLATFORM_INVALID = 'VALIDATION_112';
    public const VALIDATION_PREFERENCES_MALFORMED = 'VALIDATION_113';
    public const VALIDATION_AFTER_INVALID = 'VALIDATION_114';
    public const VALIDATION_FAILED = 'VALIDATION_199';

    // Resource Errors (200-299)
    public const RESOURCE_NOT_FOUND = 'RESOURCE_200';
    public const RESOURCE_ALREADY_EXISTS = 'RESOURCE_201';
    public const RESOURCE_FORBIDDEN = 'RESOURCE_202';

    // Storage Errors (300-399)
    public const STORAGE_UPLOAD_FAILED = 'STORAGE_300';
    public const STORAGE_FILE_TOO_LARGE = 'STORAGE_301';
    public const STORAGE_INVALID_FILE_TYPE = 'STORAGE_302';
    public const STORAGE_FILENAME_REQUIRED = 'STORAGE_303';
    public const STORAGE_S3_UNAVAILABLE = 'STORAGE_304';
    public const STORAGE_EXPERIMENT_ID_REQUIRED = 'STORAGE_305';
    public const STORAGE_UPLOAD_ID_REQUIRED = 'STORAGE_306';
    public const STORAGE_PART_NUMBER_INVALID = 'STORAGE_307';
    public const STORAGE_PART_TOO_SMALL = 'STORAGE_308';
    public const STORAGE_PART_MANIFEST_EMPTY = 'STORAGE_309';

    // Lab Errors (400-499)
    public const LAB_GROUND_TRUTH_EMPTY_BATCH = 'LAB_400';
    public const LAB_GROUND_TRUTH_BATCH_TOO_LARGE = 'LAB_401';
    public const LAB_GROUND_TRUTH_MALFORMED_BODY = 'LAB_402';
    public const LAB_SESSION_ID_REQUIRED = 'LAB_403';

    // System Errors (900-999)
    public const SYSTEM_INTERNAL_ERROR = 'SYSTEM_900';
    public const SYSTEM_DATABASE_ERROR = 'SYSTEM_901';
    public const SYSTEM_SERVICE_UNAVAILABLE = 'SYSTEM_902';

    /**
     * Get human-readable description for error code (for development/logging)
     */
    public static function getDescription(string $code): string
    {
        return match ($code) {
            self::AUTH_INVALID_CREDENTIALS => 'Invalid email or password',
            self::AUTH_EMAIL_NOT_FOUND => 'Email address not found',
            self::AUTH_ACCOUNT_DISABLED => 'Account has been disabled',
            self::AUTH_TOKEN_EXPIRED => 'Authentication token has expired',
            self::AUTH_TOKEN_INVALID => 'Authentication token is invalid',
            self::AUTH_UNAUTHORIZED => 'Authentication required',
            self::AUTH_EMAIL_ALREADY_EXISTS => 'Email address already registered',
            self::AUTH_ACCOUNT_DELETED => 'Account has been deleted',

            self::VALIDATION_EMAIL_REQUIRED => 'Email address is required',
            self::VALIDATION_EMAIL_INVALID => 'Email address format is invalid',
            self::VALIDATION_EMAIL_TOO_LONG => 'Email address is too long (max 180 characters)',
            self::VALIDATION_PASSWORD_REQUIRED => 'Password is required',
            self::VALIDATION_PASSWORD_EMPTY => 'Password cannot be empty',
            self::VALIDATION_PASSWORD_TOO_SHORT => 'Password is too short (min 8 characters)',
            self::VALIDATION_PASSWORD_TOO_LONG => 'Password is too long (max 255 characters)',
            self::VALIDATION_NAME_TOO_LONG => 'Name is too long (max 255 characters)',
            self::VALIDATION_HANDSET_MALFORMED => 'Handset descriptor is malformed: it must be an object with handset_id and platform (ios|android), and only the keys the API knows',
            self::VALIDATION_HANDSET_TOO_LARGE => 'Handset descriptor exceeds 64 kB',
            self::VALIDATION_BODY_NOT_OBJECT => 'Request body must be a JSON object',
            self::VALIDATION_PUSH_TOKEN_REQUIRED => 'Push token is required (a non-empty string of at most 4096 characters)',
            self::VALIDATION_PUSH_PLATFORM_INVALID => 'Push platform must be ios or android',
            self::VALIDATION_PREFERENCES_MALFORMED => 'Notification preferences must be an object with boolean notify_general and notify_callouts',
            self::VALIDATION_AFTER_INVALID => 'The after parameter must be an ISO-8601 instant, e.g. 2026-09-16T08:00:00Z',
            self::VALIDATION_FAILED => 'Validation failed',

            self::RESOURCE_NOT_FOUND => 'Requested resource not found',
            self::RESOURCE_ALREADY_EXISTS => 'Resource already exists',
            self::RESOURCE_FORBIDDEN => 'Access to resource is forbidden',

            self::STORAGE_UPLOAD_FAILED => 'File upload failed',
            self::STORAGE_FILE_TOO_LARGE => 'File size exceeds maximum allowed (500 MB)',
            self::STORAGE_INVALID_FILE_TYPE => 'File type is not allowed',
            self::STORAGE_FILENAME_REQUIRED => 'Filename is required',
            self::STORAGE_S3_UNAVAILABLE => 'Storage service is temporarily unavailable',
            self::STORAGE_EXPERIMENT_ID_REQUIRED => 'Experiment ID is required',
            self::STORAGE_UPLOAD_ID_REQUIRED => 'Multipart upload id is required',
            self::STORAGE_PART_NUMBER_INVALID => 'Part number must be between 1 and 10000',
            self::STORAGE_PART_TOO_SMALL => 'Every part except the last must be at least 5 MiB',
            self::STORAGE_PART_MANIFEST_EMPTY => 'The completion manifest names no parts',

            self::LAB_GROUND_TRUTH_EMPTY_BATCH => 'No ground-truth events in request',
            self::LAB_GROUND_TRUTH_BATCH_TOO_LARGE => 'Too many ground-truth events in one request',
            self::LAB_GROUND_TRUTH_MALFORMED_BODY => 'Request body is not a ground-truth event or batch',
            self::LAB_SESSION_ID_REQUIRED => 'Lab session id is required',

            self::SYSTEM_INTERNAL_ERROR => 'Internal server error',
            self::SYSTEM_DATABASE_ERROR => 'Database operation failed',
            self::SYSTEM_SERVICE_UNAVAILABLE => 'Service temporarily unavailable',

            default => 'Unknown error',
        };
    }
}
