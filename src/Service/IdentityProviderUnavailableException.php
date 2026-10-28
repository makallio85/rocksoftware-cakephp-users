<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service;

/**
 * The identity provider's discovery document or keys could not be fetched:
 * the provider is down or unreachable, not the token or the sign-in at fault.
 */
class IdentityProviderUnavailableException extends SignInException
{
}
