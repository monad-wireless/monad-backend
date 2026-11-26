<?php

namespace App\Quest;

use App\Constants\ErrorCode;
use App\Entity\Handset;
use App\Exception\ValidationException;

/**
 * The phone's description of itself, validated (IP-149).
 *
 * CLOSED TOP-LEVEL KEYS. A key this class does not know fails the request rather
 * than being stored. That is the whole validation posture: the admin renders every
 * key it knows, and a field that arrived unannounced would be stored, never shown,
 * and discovered in the dataset a year later. Inside `sensors`, `radio` and
 * `state` the contents are platform-shaped by design and are stored as sent.
 *
 * VERBATIM. `toArray()` returns the body as received, minus nothing and plus
 * nothing. The enrollment freezes exactly these bytes, the sidecar on S3 carries
 * exactly these bytes, and the two can be compared. A normalisation step here
 * would be a place where two builds could be made to look alike.
 *
 * UNKNOWN IS ABSENT. The app omits what its platform cannot answer (iOS publishes
 * no BLE PHY set; `radio` is then `{}`), and this class does not fill in defaults.
 */
final class HandsetDescriptor
{
    /** Bytes. A descriptor with an Android sensor inventory is a few kB; this is not a payload channel. */
    public const MAX_BYTES = 64 * 1024;

    public const KEYS = [
        'handset_id', 'platform', 'machine', 'manufacturer', 'model', 'soc',
        'os_version', 'os_build', 'app_version', 'build_id',
        'capabilities', 'sensors', 'radio', 'state',
    ];

    private const STRING_KEYS = [
        'handset_id' => 64, 'platform' => 16, 'machine' => 64, 'manufacturer' => 64, 'model' => 128,
        'soc' => 64, 'os_version' => 64, 'os_build' => 64, 'app_version' => 64, 'build_id' => 128,
    ];

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data)
    {
    }

    /**
     * Parse the request body. `null` when the body is empty: an app build that predates IP-149.
     *
     * @throws ValidationException on anything that is present and wrong
     */
    public static function fromRequestBody(string $raw): ?self
    {
        if (trim($raw) === '') {
            return null;
        }
        if (strlen($raw) > self::MAX_BYTES) {
            throw new ValidationException(ErrorCode::VALIDATION_HANDSET_TOO_LARGE);
        }
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            throw new ValidationException(ErrorCode::VALIDATION_HANDSET_MALFORMED);
        }
        // A body with no `handset` key is tolerated as "nothing reported", so a future body field
        // does not have to carry a handset to be legal.
        if (!array_key_exists('handset', $body)) {
            return null;
        }

        return self::fromArray($body['handset']);
    }

    /** @throws ValidationException */
    public static function fromArray(mixed $handset): self
    {
        if (!is_array($handset) || array_is_list($handset)) {
            throw new ValidationException(ErrorCode::VALIDATION_HANDSET_MALFORMED);
        }
        foreach (array_keys($handset) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new ValidationException(ErrorCode::VALIDATION_HANDSET_MALFORMED);
            }
        }
        foreach (self::STRING_KEYS as $key => $max) {
            if (!array_key_exists($key, $handset)) {
                continue;
            }
            if (!is_string($handset[$key]) || $handset[$key] === '' || mb_strlen($handset[$key]) > $max) {
                throw new ValidationException(ErrorCode::VALIDATION_HANDSET_MALFORMED);
            }
        }
        $id = $handset['handset_id'] ?? null;
        if (!is_string($id) || 1 !== preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $id)) {
            throw new ValidationException(ErrorCode::VALIDATION_HANDSET_MALFORMED);
        }
        if (!in_array($handset['platform'] ?? null, Handset::PLATFORMS, true)) {
            throw new ValidationException(ErrorCode::VALIDATION_HANDSET_MALFORMED);
        }
        if (array_key_exists('capabilities', $handset)) {
            $tokens = $handset['capabilities'];
            if (!is_array($tokens) || !array_is_list($tokens) || $tokens !== array_filter($tokens, 'is_string')) {
                throw new ValidationException(ErrorCode::VALIDATION_HANDSET_MALFORMED);
            }
        }
        if (array_key_exists('sensors', $handset) && (!is_array($handset['sensors']) || !array_is_list($handset['sensors']))) {
            throw new ValidationException(ErrorCode::VALIDATION_HANDSET_MALFORMED);
        }
        foreach (['radio', 'state'] as $objectKey) {
            if (array_key_exists($objectKey, $handset) && (!is_array($handset[$objectKey]) || array_is_list($handset[$objectKey]) && $handset[$objectKey] !== [])) {
                throw new ValidationException(ErrorCode::VALIDATION_HANDSET_MALFORMED);
            }
        }

        return new self($handset);
    }

    public function installationId(): string
    {
        return $this->data['handset_id'];
    }

    public function platform(): string
    {
        return $this->data['platform'];
    }

    /** @return array<string, mixed> the body as received */
    public function toArray(): array
    {
        return $this->data;
    }
}
