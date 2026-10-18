<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service\Scim;

use Cake\I18n\DateTime;
use Throwable;

/**
 * The fields the plugin takes from a pushed SCIM user. Rock Software ID's SCIM
 * mapping sends the company email and phone of the application's company and
 * the access dates in the extension schema `ScimUsers::ACCESS_SCHEMA`
 * (`accessFrom`, `accessUntil`: ISO 8601 with an offset, or null).
 */
final class ScimPerson
{
    /**
     * @param string $externalId The Rock Software ID `uid` (the OIDC `sub`).
     * @param string $userName The SCIM `userName`.
     * @param string $email The primary email address.
     * @param string $name The name shown.
     * @param string|null $phone The mobile number, E.164.
     * @param bool $active Whether the account is active.
     * @param \Cake\I18n\DateTime|null $accessFrom Access starts at.
     * @param \Cake\I18n\DateTime|null $accessUntil Access ends at.
     * @param array<string, string|null> $otherEmails The person's other addresses => where they belong.
     */
    private function __construct(
        public readonly string $externalId,
        public readonly string $userName,
        public readonly string $email,
        public readonly string $name,
        public readonly ?string $phone,
        public readonly bool $active,
        public readonly ?DateTime $accessFrom,
        public readonly ?DateTime $accessUntil,
        public readonly array $otherEmails = [],
    ) {
    }

    /**
     * @param array<string, mixed> $payload The SCIM user.
     * @return self|string The person, or what is wrong with the payload.
     */
    public static function fromPayload(array $payload): self|string
    {
        $externalId = $payload['externalId'] ?? null;
        if (!is_scalar($externalId) || (string)$externalId === '' || strlen((string)$externalId) > 64) {
            return 'externalId is required (at most 64 characters).';
        }
        $userName = $payload['userName'] ?? null;
        if (!is_string($userName) || $userName === '' || strlen($userName) > 150) {
            return 'userName is required (at most 150 characters).';
        }

        $email = self::primary($payload['emails'] ?? null);
        if ($email === null || strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'A valid primary email address is required.';
        }

        $name = $payload['name']['formatted'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            $name = $payload['displayName'] ?? null;
        }
        if (!is_string($name) || trim($name) === '') {
            $parts = array_filter([
                $payload['name']['givenName'] ?? null,
                $payload['name']['familyName'] ?? null,
            ], 'is_string');
            $name = trim(implode(' ', $parts));
        }
        $name = trim((string)$name) === '' ? $email : mb_substr(trim((string)$name), 0, 255);

        $phone = self::phone($payload['phoneNumbers'] ?? null);
        if ($phone !== null && strlen($phone) > 32) {
            return 'A phone number is at most 32 characters.';
        }

        $active = $payload['active'] ?? true;
        if (is_string($active)) {
            $active = strtolower($active) !== 'false';
        }

        $access = $payload[ScimUsers::ACCESS_SCHEMA] ?? [];
        $access = is_array($access) ? $access : [];
        $from = self::moment($access['accessFrom'] ?? null);
        $until = self::moment($access['accessUntil'] ?? null);
        if ($from === false || $until === false) {
            return 'accessFrom and accessUntil are ISO 8601 timestamps with an offset, or null.';
        }

        return new self(
            (string)$externalId,
            $userName,
            mb_strtolower($email),
            $name,
            $phone,
            (bool)$active,
            $from,
            $until,
            self::others($payload['emails'] ?? null, mb_strtolower($email)),
        );
    }

    /**
     * @param mixed $emails The SCIM `emails`.
     * @return string|null
     */
    private static function primary(mixed $emails): ?string
    {
        if (!is_array($emails)) {
            return null;
        }
        $first = null;
        foreach ($emails as $email) {
            $value = is_array($email) ? ($email['value'] ?? null) : null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            if (($email['primary'] ?? false) === true) {
                return trim($value);
            }
            $first ??= trim($value);
        }

        return $first;
    }

    /**
     * Every address but the primary one, with where it belongs (`display`,
     * else `type`).
     *
     * @param mixed $emails The SCIM `emails`.
     * @param string $primary The primary address, lower case.
     * @return array<string, string|null>
     */
    private static function others(mixed $emails, string $primary): array
    {
        if (!is_array($emails)) {
            return [];
        }
        $others = [];
        foreach ($emails as $email) {
            $value = is_array($email) ? ($email['value'] ?? null) : null;
            if (!is_string($value) || filter_var(trim($value), FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $value = mb_strtolower(trim($value));
            if ($value === $primary || strlen($value) > 190 || array_key_exists($value, $others)) {
                continue;
            }
            $label = $email['display'] ?? $email['type'] ?? null;
            $others[$value] = is_string($label) && trim($label) !== '' ? mb_substr(trim($label), 0, 100) : null;
        }

        return $others;
    }

    /**
     * The mobile number, else the primary, else the first.
     *
     * @param mixed $numbers The SCIM `phoneNumbers`.
     * @return string|null
     */
    private static function phone(mixed $numbers): ?string
    {
        if (!is_array($numbers)) {
            return null;
        }
        $pick = null;
        $rank = 0;
        foreach ($numbers as $number) {
            $value = is_array($number) ? ($number['value'] ?? null) : null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $score = ($number['type'] ?? null) === 'mobile' ? 3 : (($number['primary'] ?? false) === true ? 2 : 1);
            if ($score > $rank) {
                $pick = preg_replace('/\s+/', '', trim($value));
                $rank = $score;
            }
        }

        return $pick;
    }

    /**
     * @param mixed $value An ISO 8601 timestamp, or null.
     * @return \Cake\I18n\DateTime|false|null False when it is not one.
     */
    private static function moment(mixed $value): DateTime|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        $iso8601 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/';
        if (!is_string($value) || !preg_match($iso8601, $value)) {
            return false;
        }
        try {
            // In the application's own time zone, so the ORM stores and reads it
            // back the way the application stores every other datetime.
            return DateTime::parse($value)->setTimezone(date_default_timezone_get());
        } catch (Throwable) {
            return false;
        }
    }
}
