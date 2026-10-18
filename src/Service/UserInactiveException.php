<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service;

use Cake\Datasource\EntityInterface;

/**
 * The person is known but has no access to this application right now:
 * Rock Software ID revoked it, or it has not started or has ended
 * (`users.access_from` / `access_until`). `$state` is one of the
 * `UserAccess` state constants.
 */
class UserInactiveException extends SignInException
{
    /**
     * @param string $message For the operator's log.
     * @param string $state One of the `UserAccess` state constants.
     * @param \Cake\Datasource\EntityInterface|null $user The user refused.
     */
    public function __construct(
        string $message,
        public readonly string $state = UserAccess::REVOKED,
        public readonly ?EntityInterface $user = null,
    ) {
        parent::__construct($message);
    }
}
