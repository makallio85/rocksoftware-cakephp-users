<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service;

use Cake\Utility\Security;

/**
 * Encrypts the stored client secret with a key derived from the application's
 * security salt, so a database dump alone does not reveal it.
 */
final class SecretCipher
{
    private const CONTEXT = 'rocksoftware-cakephp-users:client-secret';

    /**
     * @param string $plain The secret.
     * @return string Base64 ciphertext.
     */
    public static function encrypt(string $plain): string
    {
        return base64_encode(Security::encrypt($plain, self::key()));
    }

    /**
     * @param string $stored Base64 ciphertext.
     * @return string The secret.
     */
    public static function decrypt(string $stored): string
    {
        $raw = base64_decode($stored, true);
        $plain = $raw === false ? null : Security::decrypt($raw, self::key());
        if ($plain === null) {
            throw new SignInException(
                'The stored client secret cannot be decrypted. Was the security salt changed? '
                . 'Run `bin/cake rock_software_users configure` again.',
            );
        }

        return $plain;
    }

    /**
     * @return string
     */
    private static function key(): string
    {
        return hash('sha256', self::CONTEXT . Security::getSalt());
    }
}
