<?php
declare(strict_types=1);

namespace RockSoftware\Users\Session;

use Cake\Http\Session;

/**
 * What the plugin keeps in the session, and how the application asks about it.
 *
 * An application with its own second factor uses `isProviderSession()` to skip it:
 * the provider already required one, and an emergency link is issued by
 * someone with shell access to the server. A password session is not one.
 */
final class SignInSession
{
    /** How the session was established: one of the METHOD_ constants. */
    public const METHOD = 'RockSoftwareUsers.method';

    /** The ID token from sign-in, sent back as a hint on logout. */
    public const ID_TOKEN = 'RockSoftwareUsers.idToken';

    /** Set when the plugin signed the user in. */
    public const AUTHENTICATED = 'RockSoftwareUsers.authenticated';

    /** The authorization request in progress: state, nonce, verifier, redirect. */
    public const FLOW = 'RockSoftwareUsers.flow';

    /**
     * When this session was established (Unix time). The session check compares
     * it with the identity's `sessions_valid_from` to end sessions a back-channel
     * logout invalidated, and with the maximum session length.
     */
    public const ESTABLISHED_AT = 'RockSoftwareUsers.establishedAt';

    /** The last request's time (Unix time), for the idle timeout. */
    public const LAST_SEEN_AT = 'RockSoftwareUsers.lastSeenAt';

    /**
     * The applications the person may open, from the provider's `apps` claim:
     * a list of `['name' => …, 'url' => …]`.
     */
    public const APPS = 'RockSoftwareUsers.apps';

    public const METHOD_OIDC = 'oidc';
    public const METHOD_PASSWORD = 'password';
    public const METHOD_EMERGENCY = 'emergency';

    /**
     * Whether the signed-in user came in through the identity provider or an
     * emergency link: a second factor was already required.
     *
     * @param \Cake\Http\Session|null $session The session.
     * @return bool
     */
    public static function isProviderSession(?Session $session): bool
    {
        if ($session === null) {
            return false;
        }

        return $session->read(self::AUTHENTICATED) === true
            && in_array($session->read(self::METHOD), [self::METHOD_OIDC, self::METHOD_EMERGENCY], true);
    }

    /**
     * Whether the plugin established this session, in any of its ways.
     *
     * @param \Cake\Http\Session|null $session The session.
     * @return bool
     */
    public static function isPluginSession(?Session $session): bool
    {
        if ($session === null) {
            return false;
        }

        return $session->read(self::AUTHENTICATED) === true
            && in_array(
                $session->read(self::METHOD),
                [self::METHOD_OIDC, self::METHOD_PASSWORD, self::METHOD_EMERGENCY],
                true,
            );
    }
}
