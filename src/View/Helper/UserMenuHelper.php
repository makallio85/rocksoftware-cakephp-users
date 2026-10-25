<?php
declare(strict_types=1);

namespace RockSoftware\Users\View\Helper;

use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\View\Helper;
use RockSoftware\Users\Model\Entity\SignInMethodType;
use RockSoftware\Users\Service\UserProvisioner;
use RockSoftware\Users\Session\SignInSession;
use Throwable;

/**
 * The signed-in person's menu, for the application's layout.
 *
 * `items()` returns the entries as data, translated, for an application
 * that renders its own menu. The `RockSoftwareUsers.user_menu` element renders the same
 * list as a ready dropdown:
 *
 *     <?= $this->element('RockSoftware/Users.user_menu') ?>
 *
 * Entry types: `header` (`label`, `detail`), `app` (`label`, `url`,
 * `current`), `link` (`label`, `url`) and `sign_out` (`label`, `url`,
 * `method` = `post`: render it with `FormHelper::postLink()`).
 */
class UserMenuHelper extends Helper
{
    use LocatorAwareTrait;

    /**
     * @return array<array<string, mixed>> Empty when nobody is signed in.
     */
    public function items(): array
    {
        $request = $this->getView()->getRequest();
        $identity = $request->getAttribute('identity');
        $user = is_object($identity) && method_exists($identity, 'getOriginalData')
            ? $identity->getOriginalData()
            : null;
        if (!$user instanceof EntityInterface) {
            return [];
        }
        $session = $request->getSession();

        $detail = [(string)$user->get(UserProvisioner::EMAIL)];
        $method = $session->read(SignInSession::METHOD);
        $establishedAt = $session->read(SignInSession::ESTABLISHED_AT);
        if (is_string($method) && SignInSession::isPluginSession($session) && is_int($establishedAt)) {
            $detail[] = __d(
                'rock_software_users',
                'Signed in with {0} at {1}',
                SignInMethodType::label($method),
                DateTime::createFromTimestamp($establishedAt)->i18nFormat('HH.mm'),
            );
        }
        $items = [[
            'type' => 'header',
            'label' => UserProvisioner::displayName($user),
            'detail' => implode(' · ', $detail),
        ]];

        $host = $request->host();
        foreach ((array)$session->read(SignInSession::APPS) as $app) {
            if (!is_array($app) || !is_string($app['name'] ?? null) || !is_string($app['url'] ?? null)) {
                continue;
            }
            $items[] = [
                'type' => 'app',
                'label' => $app['name'],
                'url' => $app['url'],
                'current' => parse_url($app['url'], PHP_URL_HOST) === $host,
            ];
        }

        $accountUrl = $this->accountUrl();
        if ($accountUrl !== null) {
            $items[] = [
                'type' => 'link',
                'label' => __d('rock_software_users', 'Your Rock Software ID account'),
                'url' => $accountUrl,
            ];
        }
        $items[] = [
            'type' => 'sign_out',
            'label' => __d('rock_software_users', 'Sign out'),
            'url' => [
                'plugin' => 'RockSoftware/Users',
                'controller' => 'SignIn',
                'action' => 'signOut',
                'prefix' => false,
            ],
            'method' => 'post',
        ];

        return $items;
    }

    /**
     * The identity provider's own page for the person's account, when the
     * application is connected.
     *
     * @return string|null
     */
    private function accountUrl(): ?string
    {
        $origin = $this->providerOrigin();

        return $origin === null ? null : $origin . '/if/user/';
    }

    /**
     * Rock Software ID's admin page for people, where their details and
     * access dates are changed; null when the application is not connected.
     *
     * @return string|null
     */
    public function peopleAdminUrl(): ?string
    {
        $origin = $this->providerOrigin();

        return $origin === null ? null : $origin . '/if/admin/#/identity/users';
    }

    /**
     * @return string|null
     */
    private function providerOrigin(): ?string
    {
        try {
            /** @var \RockSoftware\Users\Model\Table\SettingsTable $settings */
            $settings = $this->fetchTable('RockSoftware/Users.Settings');
            $setting = $settings->enabled();
        } catch (Throwable) {
            return null;
        }
        $parts = $setting === null ? false : parse_url($setting->issuer_url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
            return null;
        }

        return 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
