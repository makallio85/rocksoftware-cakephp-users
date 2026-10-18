<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service;

use Cake\Core\Configure;
use Composer\InstalledVersions;
use RockSoftware\Users\Middleware\RequireSignInMiddleware;
use RockSoftware\Users\UsersPlugin;
use Throwable;

/**
 * What the identity provider must register for this application, as paths
 * relative to the application's own address: where sign-in returns, where
 * signing out returns, the back-channel logout, provisioning and SCIM endpoints.
 */
final class ClientMetadata
{
    /**
     * @return array<string, mixed>
     */
    public static function describe(): array
    {
        $signInUrl = (string)Configure::read('RockSoftwareUsers.signInUrl', '/auth');
        $logout = array_values(array_unique([$signInUrl, '/auth']));

        try {
            $version = InstalledVersions::getPrettyVersion('makallio85/rocksoftware-cakephp-users');
        } catch (Throwable) {
            $version = null;
        }

        return [
            'redirect_uri' => '/auth/callback',
            'post_logout_redirect_uris' => $logout,
            'backchannel_logout_uri' => UsersPlugin::BACKCHANNEL_LOGOUT_PATH,
            'provision_uri' => Provisioning::config() === null ? null : UsersPlugin::PROVISION_PATH,
            'scim_uri' => UsersPlugin::SCIM_PATH,
            'password_sign_in' => Configure::read('RockSoftwareUsers.passwordSignIn') === true,
            'plugin_version' => $version,
            'sign_in_paths' => RequireSignInMiddleware::SIGN_IN_PATHS,
        ];
    }
}
