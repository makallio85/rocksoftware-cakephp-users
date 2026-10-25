<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service\Scim;

/**
 * What the SCIM endpoint answers: a status and a JSON body (null for 204).
 */
final class ScimResponse
{
    public const ERROR_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:Error';

    /**
     * @param int $status The HTTP status.
     * @param array<string, mixed>|null $body The body.
     */
    public function __construct(public readonly int $status, public readonly ?array $body = null)
    {
    }

    /**
     * @param int $status The HTTP status.
     * @param string $detail For the operator; never personal data beyond an email address.
     * @param string|null $scimType RFC 7644 `scimType`, e.g. `uniqueness`.
     * @return self
     */
    public static function error(int $status, string $detail, ?string $scimType = null): self
    {
        $body = ['schemas' => [self::ERROR_SCHEMA], 'status' => (string)$status, 'detail' => $detail];
        if ($scimType !== null) {
            $body['scimType'] = $scimType;
        }

        return new self($status, $body);
    }
}
