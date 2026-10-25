<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service;

use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use RockSoftware\Users\Model\Entity\SessionEventType;
use RockSoftware\Users\Model\Entity\SignInMethodType;

/**
 * The identity provider's back-channel logout: a signed logout token naming a
 * subject whose sessions have ended. Moves the identity's
 * `sessions_valid_from` forward, which the session check turns into a
 * sign-out on that person's next request.
 */
class BackchannelLogout
{
    use LocatorAwareTrait;

    public const EVENT = 'RockSoftwareUsers.backchannelLogout';

    /**
     * @param mixed $token The `logout_token` form field.
     * @return int The HTTP status to answer with.
     */
    public function handle(mixed $token): int
    {
        /** @var \RockSoftware\Users\Model\Table\SettingsTable $settings */
        $settings = $this->fetchTable('RockSoftware/Users.Settings');
        $setting = $settings->enabled();
        if ($setting === null || !is_string($token)) {
            return 400;
        }

        try {
            $claims = (new OidcClient($setting, $settings->clientSecret($setting)))->validateLogoutToken($token);
        } catch (SignInException $e) {
            Log::warning('Back-channel logout rejected: ' . $e->getMessage());

            return 400;
        }

        $subject = $claims['sub'] ?? null;
        if (!is_string($subject)) {
            return 200;
        }
        /** @var \RockSoftware\Users\Model\Table\IdentitiesTable $identities */
        $identities = $this->fetchTable('RockSoftware/Users.Identities');
        $identity = $identities->findByExternalId($subject);
        if ($identity === null) {
            return 200;
        }

        $now = DateTime::now();
        $identity->sessions_valid_from = $now;
        $identities->saveOrFail($identity);

        $provisioner = new UserProvisioner();
        $user = $provisioner->users()->find()->where(['id' => $identity->user_id])->first();
        if ($user !== null) {
            (new SignInHistory())->record(SessionEventType::SIGNED_OUT_BY_PROVIDER, [
                'user_id' => (int)$user->get('id'),
                'method' => SignInMethodType::OIDC,
            ]);
            EventManager::instance()->dispatch(new Event(self::EVENT, $this, ['user' => $user]));
        }

        return 200;
    }
}
