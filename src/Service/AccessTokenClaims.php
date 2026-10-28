<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service;

/**
 * The claims of a validated access token (`AccessTokenValidator`).
 */
final class AccessTokenClaims
{
    /**
     * @param string $subject `sub`: the person's identifier at Rock Software ID (`external_id`).
     * @param list<string> $audiences `aud`, as a list.
     * @param string|null $clientId The client the token was issued to: `azp`, else `client_id`.
     * @param list<string> $scopes `scope`, split at spaces.
     * @param int $expiresAt `exp`.
     * @param int|null $issuedAt `iat`.
     * @param string|null $jti `jti`: the token's id.
     * @param array<string, mixed> $claims Every claim.
     */
    public function __construct(
        public readonly string $subject,
        public readonly array $audiences,
        public readonly ?string $clientId,
        public readonly array $scopes,
        public readonly int $expiresAt,
        public readonly ?int $issuedAt,
        public readonly ?string $jti,
        public readonly array $claims,
    ) {
    }

    /**
     * @param array<string, mixed> $claims Claims `OidcClient::validateAccessToken()` verified.
     * @return self
     */
    public static function fromClaims(array $claims): self
    {
        $clientId = $claims['azp'] ?? $claims['client_id'] ?? null;
        $scope = $claims['scope'] ?? '';
        $jti = $claims['jti'] ?? null;

        return new self(
            (string)$claims['sub'],
            array_values(array_map('strval', array_filter((array)($claims['aud'] ?? []), 'is_string'))),
            is_string($clientId) && $clientId !== '' ? $clientId : null,
            is_string($scope) ? array_values(array_filter(explode(' ', $scope), fn($s) => $s !== '')) : [],
            (int)$claims['exp'],
            is_numeric($claims['iat'] ?? null) ? (int)$claims['iat'] : null,
            is_string($jti) && $jti !== '' ? $jti : null,
            $claims,
        );
    }

    /**
     * The `groups` claim, as a list of names.
     *
     * @return list<string>
     */
    public function groups(): array
    {
        return array_values(array_filter((array)($this->claims['groups'] ?? []), 'is_string'));
    }
}
