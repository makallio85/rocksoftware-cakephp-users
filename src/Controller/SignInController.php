<?php
declare(strict_types=1);

namespace RockSoftware\Users\Controller;

use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\Http\Response;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Exception\PersistenceFailedException;
use Cake\Routing\Router;
use RockSoftware\Users\Model\Entity\SessionEventType;
use RockSoftware\Users\Model\Entity\Setting;
use RockSoftware\Users\Service\BackchannelLogout;
use RockSoftware\Users\Service\ClientMetadata;
use RockSoftware\Users\Service\OidcClient;
use RockSoftware\Users\Service\PasswordSignIn;
use RockSoftware\Users\Service\Provisioning;
use RockSoftware\Users\Service\Scim\ScimServer;
use RockSoftware\Users\Service\SignInException;
use RockSoftware\Users\Service\SignInHistory;
use RockSoftware\Users\Service\UserAccess;
use RockSoftware\Users\Service\UserInactiveException;
use RockSoftware\Users\Service\UserProvisioner;
use RockSoftware\Users\Session\SignInSession;
use RockSoftware\Users\UsersPlugin;

/**
 * Sign-in through the identity provider or with a password, sign-out,
 * back-channel logout and emergency sign-in.
 *
 * The application's own controllers are not involved. The application learns
 * of each sign-in and sign-out through events:
 *
 * - `RockSoftwareUsers.afterSignIn` — data: user, claims, method. Record last sign-in, audit,
 *   session freshness.
 * - `RockSoftwareUsers.beforeSignOut` — data: user.
 * - `RockSoftwareUsers.backchannelLogout` — data: user. The provider ended the person's
 *   session or disabled the account.
 *
 * @property \Authentication\Controller\Component\AuthenticationComponent $Authentication
 * @property \Cake\Controller\Component\FlashComponent $Flash
 */
class SignInController extends Controller
{
    public const EVENT_AFTER_SIGN_IN = 'RockSoftwareUsers.afterSignIn';
    public const EVENT_BEFORE_SIGN_OUT = 'RockSoftwareUsers.beforeSignOut';
    public const EVENT_BACKCHANNEL_LOGOUT = BackchannelLogout::EVENT;

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->loadComponent('Flash');
        $this->loadComponent('Authentication.Authentication');
        $this->Authentication->allowUnauthenticated(
            [
                'index', 'start', 'password', 'callback', 'signOut', 'backchannelLogout', 'emergency', 'provision',
                'clientMetadata',
            ],
        );

        $layout = Configure::read('RockSoftwareUsers.layout');
        if (is_string($layout)) {
            $this->viewBuilder()->setLayout($layout);
        }
    }

    /**
     * The sign-in page: "Sign in with Rock Software ID", and the email and
     * password form while password sign-in is on. Failed and refused sign-ins,
     * and signing out, come back here with their reason as a flash message. An
     * application gives it its look by overriding
     * `templates/plugin/RockSoftware/Users/SignIn/index.php`.
     *
     * @return \Cake\Http\Response|null
     */
    public function index(): ?Response
    {
        if ($this->Authentication->getIdentity() !== null) {
            return $this->redirect(Configure::read('RockSoftwareUsers.signInRedirect'));
        }

        $redirect = $this->safeRedirect($this->getRequest()->getQuery('redirect'));
        $this->set([
            'providerEnabled' => $this->fetchTable('RockSoftware/Users.Settings')->enabled() !== null,
            'passwordSignIn' => (new PasswordSignIn())->available(),
            'redirect' => $redirect,
            'email' => (string)$this->getRequest()->getSession()->consume('RockSoftwareUsers.passwordEmail'),
            'signInUrl' => [
                'plugin' => 'RockSoftware/Users',
                'controller' => 'SignIn',
                'action' => 'start',
                'prefix' => false,
                '?' => array_filter(['redirect' => $redirect]),
            ],
        ]);

        return null;
    }

    /**
     * Starts the authorization code flow.
     *
     * @return \Cake\Http\Response|null
     */
    public function start(): ?Response
    {
        $setting = $this->fetchTable('RockSoftware/Users.Settings')->enabled();
        if ($setting === null) {
            $this->Flash->error(__d('rock_software_users', 'Sign-in through Rock Software ID is not available.'));

            return $this->redirect($this->signInUrl());
        }

        $flow = [
            'state' => OidcClient::randomToken(),
            'nonce' => OidcClient::randomToken(),
            'verifier' => OidcClient::randomToken(),
            'redirect' => $this->safeRedirect($this->getRequest()->getQuery('redirect')),
        ];
        $this->getRequest()->getSession()->write(SignInSession::FLOW, $flow);

        try {
            $url = $this->client($setting)
                ->authorizationUrl($this->callbackUrl(), $flow['state'], $flow['nonce'], $flow['verifier']);
        } catch (SignInException $e) {
            return $this->fail($e);
        }

        return $this->redirect($url);
    }

    /**
     * The sign-in page's email and password form.
     *
     * @return \Cake\Http\Response|null
     */
    public function password(): ?Response
    {
        $request = $this->getRequest();
        $redirect = $this->safeRedirect($request->getData('redirect'));
        $back = ['plugin' => 'RockSoftware/Users', 'controller' => 'SignIn', 'action' => 'index', 'prefix' => false,
            '?' => array_filter(['redirect' => $redirect])];
        if (!$request->is('post')) {
            return $this->redirect($back);
        }
        $email = trim((string)$request->getData('email'));
        $ip = SignInHistory::clientIp($request);
        $attempt = (new PasswordSignIn())->attempt($email, (string)$request->getData('password'), $ip);
        $user = $attempt['user'];

        if ($attempt['result'] === SessionEventType::SIGNED_IN && $user !== null) {
            $this->signIn($user, SignInSession::METHOD_PASSWORD, [], null);

            return $this->redirect($redirect ?? Configure::read('RockSoftwareUsers.signInRedirect'));
        }

        (new SignInHistory())->record($attempt['result'], [
            'user_id' => $user === null ? null : (int)$user->get('id'),
            'email' => $email,
            'method' => SignInSession::METHOD_PASSWORD,
        ], $request);
        $this->Flash->error(match ($attempt['result']) {
            SessionEventType::THROTTLED => __d(
                'rock_software_users',
                'Too many failed sign-ins. Try again in {0} minutes, or sign in with Rock Software ID.',
                PasswordSignIn::WINDOW_MINUTES,
            ),
            SessionEventType::PASSWORD_SIGN_IN_OFF => __d(
                'rock_software_users',
                'Password sign-in is off. Sign in with Rock Software ID.',
            ),
            SessionEventType::USER_INACTIVE => UserAccess::refusalMessage(UserAccess::REVOKED),
            SessionEventType::ACCESS_NOT_STARTED => UserAccess::refusalMessage(UserAccess::NOT_STARTED, $user),
            SessionEventType::ACCESS_ENDED => UserAccess::refusalMessage(UserAccess::ENDED),
            default => __d('rock_software_users', 'The email address or password is wrong.'),
        });
        $request->getSession()->write('RockSoftwareUsers.passwordEmail', mb_substr($email, 0, 190));

        return $this->redirect($back);
    }

    /**
     * Completes the flow: verifies the response, provisions the user and signs
     * them in.
     *
     * @return \Cake\Http\Response|null
     */
    public function callback(): ?Response
    {
        $session = $this->getRequest()->getSession();
        $flow = $session->consume(SignInSession::FLOW);
        $setting = $this->fetchTable('RockSoftware/Users.Settings')->enabled();

        try {
            if ($setting === null) {
                throw new SignInException('Sign-in through the identity provider is switched off.');
            }
            if (!is_array($flow)) {
                throw new SignInException('No sign-in is in progress in this browser session.');
            }
            $state = $this->getRequest()->getQuery('state');
            if (!is_string($state) || !hash_equals((string)$flow['state'], $state)) {
                throw new SignInException('The state parameter does not match.');
            }
            $error = $this->getRequest()->getQuery('error');
            if (is_string($error)) {
                throw new SignInException(sprintf('The identity provider returned %s.', $error));
            }
            $code = $this->getRequest()->getQuery('code');
            if (!is_string($code) || $code === '') {
                throw new SignInException('The callback carries no authorization code.');
            }

            $client = $this->client($setting);
            $tokens = $client->exchangeCode($code, $this->callbackUrl(), (string)$flow['verifier']);
            $claims = $client->validateIdToken((string)$tokens['id_token'], (string)$flow['nonce']);

            $group = $setting->required_group;
            if ($group !== null && $group !== '' && !in_array($group, (array)($claims['groups'] ?? []), true)) {
                // The groups the token did carry, so a misspelt group name shows.
                Log::warning(sprintf(
                    'Sign-in refused: %s is not in group %s (token groups: %s).',
                    $claims['email'] ?? $claims['sub'],
                    $group,
                    json_encode(array_values((array)($claims['groups'] ?? []))),
                ));
                $known = is_string($claims['sub'] ?? null)
                    ? (new UserProvisioner())->findBySubject((string)$claims['sub'])
                    : null;
                (new SignInHistory())->record(SessionEventType::NOT_IN_GROUP, [
                    'user_id' => $known === null ? null : (int)$known->get('id'),
                    'email' => is_string($claims['email'] ?? null) ? $claims['email'] : null,
                    'method' => SignInSession::METHOD_OIDC,
                ], $this->getRequest());
                $this->Flash->error(__d('rock_software_users', 'Your account has no access to this application.'));

                return $this->redirect($this->signInUrl());
            }

            $user = (new UserProvisioner())->provision($claims);
        } catch (UserInactiveException $e) {
            $known = $e->user ?? (new PasswordSignIn())->findUser((string)($claims['email'] ?? ''));
            (new SignInHistory())->record(UserAccess::sessionEventType($e->state), [
                'user_id' => $known === null ? null : (int)$known->get('id'),
                'email' => is_string($claims['email'] ?? null) ? $claims['email'] : null,
                'method' => SignInSession::METHOD_OIDC,
            ], $this->getRequest());
            Log::warning('Sign-in refused: ' . $e->getMessage());
            $this->Flash->error(UserAccess::refusalMessage($e->state, $known));

            return $this->redirect($this->signInUrl());
        } catch (SignInException $e) {
            return $this->fail($e);
        } catch (PersistenceFailedException $e) {
            // Typically the email address belongs to a row linked to another
            // identity, which the application's own rules refuse.
            return $this->fail(new SignInException(
                'The user row could not be saved: ' . json_encode($e->getEntity()->getErrors()),
                0,
                $e,
            ));
        }

        $this->signIn($user, SignInSession::METHOD_OIDC, $claims, (string)$tokens['id_token']);

        return $this->redirect($flow['redirect'] ?? Configure::read('RockSoftwareUsers.signInRedirect'));
    }

    /**
     * Signs out here and at the identity provider.
     *
     * @return \Cake\Http\Response|null
     */
    public function signOut(): ?Response
    {
        $session = $this->getRequest()->getSession();
        $idToken = $session->read(SignInSession::ID_TOKEN);
        $user = $this->Authentication->getIdentity()?->getOriginalData();
        if ($user instanceof EntityInterface) {
            $this->notify(self::EVENT_BEFORE_SIGN_OUT, ['user' => $user]);
            (new SignInHistory())->record(SessionEventType::SIGNED_OUT, [
                'user_id' => (int)$user->get('id'),
                'method' => is_string($session->read(SignInSession::METHOD))
                    ? $session->read(SignInSession::METHOD)
                    : null,
            ], $this->getRequest());
        }

        $this->Authentication->logout();
        $session->destroy();

        $afterLogout = Router::url($this->signInUrl(), true);
        $setting = $this->fetchTable('RockSoftware/Users.Settings')->enabled();
        if ($setting !== null && is_string($idToken)) {
            try {
                $url = $this->client($setting)->endSessionUrl($idToken, $afterLogout);
                if ($url !== null) {
                    return $this->redirect($url);
                }
            } catch (SignInException $e) {
                Log::warning('End-session URL unavailable: ' . $e->getMessage());
            }
        }

        return $this->redirect($afterLogout);
    }

    /**
     * Receives the provider's back-channel logout token.
     *
     * @return \Cake\Http\Response
     */
    public function backchannelLogout(): Response
    {
        // 1.2 answers this before the CSRF middleware
        // (BackchannelLogoutMiddleware); this action serves a 1.1 application
        // that exempts the path itself.
        $this->getRequest()->allowMethod(['post']);
        $status = (new BackchannelLogout())->handle($this->getRequest()->getData('logout_token'));

        return $this->getResponse()->withHeader('Cache-Control', 'no-store')->withStatus($status);
    }

    /**
     * The addresses the provider must register. ClientMetadataMiddleware
     * answers it before authentication; this action serves an application
     * whose queue lacks it.
     *
     * @return \Cake\Http\Response
     */
    public function clientMetadata(): Response
    {
        return $this->getResponse()
            ->withType('application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withStringBody((string)json_encode(ClientMetadata::describe(), JSON_UNESCAPED_SLASHES));
    }

    /**
     * Receives the connection the provider hands over. ProvisionMiddleware
     * answers it before CSRF and sign-in; the route exists so routing lets the
     * request reach that middleware, and this action serves an application
     * whose queue lacks it.
     *
     * @return \Cake\Http\Response
     */
    public function provision(): Response
    {
        if (Provisioning::config() === null) {
            return $this->getResponse()->withStatus(404);
        }
        $this->getRequest()->allowMethod(['post']);
        $request = $this->getRequest();
        $status = (new Provisioning())->handle(
            $request->getHeaderLine('Authorization'),
            (string)$request->getBody(),
            $request->clientIp(),
        );

        return $this->getResponse()->withHeader('Cache-Control', 'no-store')->withStatus($status);
    }

    /**
     * SCIM. ScimMiddleware answers it before CSRF and sign-in; the route exists
     * so routing lets the request reach that middleware, and this action serves
     * an application whose queue lacks it.
     *
     * @return \Cake\Http\Response
     */
    public function scim(): Response
    {
        $request = $this->getRequest();
        $path = $request->getUri()->getPath();
        $result = (new ScimServer(rtrim(Router::fullBaseUrl(), '/') . UsersPlugin::SCIM_PATH))->handle(
            $request->getMethod(),
            substr($path, strpos($path, UsersPlugin::SCIM_PATH) + strlen(UsersPlugin::SCIM_PATH)),
            $request->getQueryParams(),
            (string)$request->getBody(),
            $request->getHeaderLine('Authorization'),
        );
        $response = $this->getResponse()->withHeader('Cache-Control', 'no-store')->withStatus($result->status);

        return $result->body === null ? $response : $response
            ->withType('application/scim+json')
            ->withStringBody((string)json_encode($result->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * A single-use link from `bin/cake rock_software_users emergency_sign_in`. GET shows a
     * confirmation, so a link preview cannot spend the link; POST signs in.
     *
     * @param string $token The raw token.
     * @return \Cake\Http\Response|null
     */
    public function emergency(string $token): ?Response
    {
        $links = $this->fetchTable('RockSoftware/Users.EmergencySignIns');
        $link = $links->findUsable($token);
        if ($link === null) {
            $this->Flash->error(__d('rock_software_users', 'This sign-in link is invalid, expired or already used.'));

            return $this->redirect($this->signInUrl());
        }

        if (!$this->getRequest()->is('post')) {
            return null;
        }

        $user = (new UserProvisioner())->findById($link->user_id);
        if (!$user instanceof EntityInterface || !UserAccess::allows($user) || !$links->consume($link)) {
            $this->Flash->error(__d('rock_software_users', 'This sign-in link is invalid, expired or already used.'));

            return $this->redirect($this->signInUrl());
        }

        Log::warning(sprintf('Emergency sign-in used for user %d.', $link->user_id));
        $this->signIn($user, SignInSession::METHOD_EMERGENCY, [], null);

        return $this->redirect(Configure::read('RockSoftwareUsers.signInRedirect'));
    }

    /**
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @param string $method One of the `SignInSession::METHOD_*` values.
     * @param array<string, mixed> $claims Verified claims, empty for password and emergency sign-in.
     * @param string|null $idToken The ID token, kept for ending the provider session.
     * @return void
     */
    private function signIn(EntityInterface $user, string $method, array $claims, ?string $idToken): void
    {
        $session = $this->getRequest()->getSession();
        // A new session id on every privilege change defeats session fixation.
        $session->renew();
        $this->Authentication->setIdentity($user);
        $session->write([
            SignInSession::AUTHENTICATED => true,
            SignInSession::METHOD => $method,
            SignInSession::ID_TOKEN => $idToken,
            SignInSession::ESTABLISHED_AT => time(),
            SignInSession::LAST_SEEN_AT => time(),
            SignInSession::APPS => self::apps($claims['apps'] ?? null),
        ]);
        (new SignInHistory())->record(SessionEventType::SIGNED_IN, [
            'user_id' => (int)$user->get('id'),
            'email' => (string)$user->get(UserProvisioner::EMAIL),
            'method' => $method,
        ], $this->getRequest());

        $provisioner = new UserProvisioner();
        $identity = $provisioner->identities()->findForUser((int)$user->get('id'));
        if ($identity !== null) {
            $identity->last_signed_in_at = DateTime::now();
            $provisioner->identities()->saveOrFail($identity);
        }
        $this->notify(self::EVENT_AFTER_SIGN_IN, [
            'user' => $user,
            'claims' => $claims,
            'method' => $method,
        ]);
    }

    /**
     * The applications the person may open, from the provider's `apps` claim:
     * names and https addresses only, at most 50.
     *
     * @param mixed $claim The claim.
     * @return array<array{name: string, url: string}>
     */
    public static function apps(mixed $claim): array
    {
        if (!is_array($claim)) {
            return [];
        }
        $apps = [];
        foreach ($claim as $app) {
            if (
                is_array($app)
                && is_string($app['name'] ?? null) && $app['name'] !== ''
                && is_string($app['url'] ?? null) && str_starts_with($app['url'], 'https://')
            ) {
                $apps[] = ['name' => mb_substr($app['name'], 0, 100), 'url' => mb_substr($app['url'], 0, 500)];
            }
        }

        return array_slice($apps, 0, 50);
    }

    /**
     * @param \RockSoftware\Users\Model\Entity\Setting $setting The connection.
     * @return \RockSoftware\Users\Service\OidcClient
     */
    private function client(Setting $setting): OidcClient
    {
        return new OidcClient($setting, $this->fetchTable('RockSoftware/Users.Settings')->clientSecret($setting));
    }

    /**
     * @param \RockSoftware\Users\Service\SignInException $e The failure.
     * @return \Cake\Http\Response|null
     */
    private function fail(SignInException $e): ?Response
    {
        Log::error('Sign-in failed: ' . $e->getMessage());
        (new SignInHistory())->record(
            SessionEventType::SIGN_IN_FAILED,
            ['method' => SignInSession::METHOD_OIDC],
            $this->getRequest(),
        );
        $this->Flash->error(
            __d(
                'rock_software_users',
                'Sign-in through Rock Software ID failed. Try again, or contact the administrator.',
            ),
        );

        return $this->redirect($this->signInUrl());
    }

    /**
     * @return string
     */
    private function callbackUrl(): string
    {
        return Router::url(
            ['plugin' => 'RockSoftware/Users', 'controller' => 'SignIn', 'action' => 'callback', 'prefix' => false],
            true,
        );
    }

    /**
     * @return array<string, mixed>|string
     */
    private function signInUrl(): array|string
    {
        /** @var array<string, mixed>|string */
        return Configure::read('RockSoftwareUsers.signInUrl');
    }

    /**
     * A local path to return to, or null. Absolute and protocol-relative URLs
     * are refused so the parameter cannot become an open redirect.
     *
     * @param mixed $redirect The `redirect` query parameter.
     * @return string|null
     */
    private function safeRedirect(mixed $redirect): ?string
    {
        if (!is_string($redirect) || !str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
            return null;
        }
        if (str_contains($redirect, '\\')) {
            return null;
        }

        return $redirect;
    }

    /**
     * @param string $name Event name.
     * @param array<string, mixed> $data Event data.
     * @return void
     */
    private function notify(string $name, array $data): void
    {
        EventManager::instance()->dispatch(new Event($name, $this, $data));
    }
}
