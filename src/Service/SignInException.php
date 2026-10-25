<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service;

use RuntimeException;

/**
 * A sign-in that cannot complete. The message is written for the operator's
 * log, never shown to the person signing in.
 */
class SignInException extends RuntimeException
{
}
