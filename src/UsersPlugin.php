<?php
declare(strict_types=1);

namespace RockSoftware\Users;

use Authentication\Middleware\AuthenticationMiddleware;
use Cake\Cache\Cache;
use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\PluginApplicationInterface;
use Cake\Datasource\ConnectionManager;
use Cake\Event\EventManager;
use Cake\Http\BaseApplication;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\I18n\I18n;
use Cake\I18n\Package;
use Cake\I18n\Parser\PoFileParser;
use Cake\Routing\Middleware\RoutingMiddleware;
use Cake\Routing\RouteBuilder;
use Psr\Http\Server\MiddlewareInterface;
use RockSoftware\Users\Command\ConfigureCommand;
use RockSoftware\Users\Command\EmergencySignInCommand;
use RockSoftware\Users\Command\RestoreAccessCommand;
use RockSoftware\Users\Command\RevocationsCommand;
use RockSoftware\Users\Command\SetPasswordCommand;
use RockSoftware\Users\Command\StatusCommand;
use RockSoftware\Users\Middleware\BackchannelLogoutMiddleware;
use RockSoftware\Users\Middleware\ClientMetadataMiddleware;
use RockSoftware\Users\Middleware\ProvisionMiddleware;
use RockSoftware\Users\Middleware\RequireSignInMiddleware;
use RockSoftware\Users\Middleware\ScimMiddleware;
use RockSoftware\Users\Middleware\SessionCheckMiddleware;
use RockSoftware\Users\Service\AuthenticationServiceProvider;

/**
 * Sign-in through the central identity provider, plug and play: loading the
 * plugin is enough. Its middleware hook wires itself into the application's
 * queue, every setting has a default, and the link to the identity provider is
 * kept in the plugin's own `rock_software_users_identities` table.
 *
 * Routes, all under `/auth`:
 *
 * - `GET /auth` — the sign-in page
 * - `GET /auth/start` — start sign-in; `?redirect=/local/path` returns there
 * - `POST /auth/password` — the sign-in page's email and password form
 * - `GET /auth/callback` — the redirect URI registered at the provider
 * - `GET|POST /auth/sign-out` — sign out here and at the provider
 * - `POST /auth/backchannel-logout` — the provider's back-channel logout URI
 * - `POST /auth/provision` — the provider hands the application its connection
 * - `/auth/scim/v2/*` — SCIM 2.0: Rock Software ID pushes people and groups
 * - `GET /auth/client-metadata` — the addresses the provider must register
 * - `GET|POST /auth/emergency/{token}` — single-use emergency sign-in
 * - `GET /auth/settings` (+ `POST update`, `POST test`, `POST allow-provisioning`,
 *   `POST password-sign-in`, `POST sessions`) —
 *   Settings › Identity provider
 * - `GET /auth/users`, `GET /auth/users/{id}` (+ `POST end-sessions`, `POST
 *   emergency-link`) — the users page; `GET /auth/users/held-removals` (+ `POST
 *   approve`, `POST reject`, for all of them) — removals the mass-removal stop held
 */
class UsersPlugin extends BasePlugin
{
    public const BACKCHANNEL_LOGOUT_PATH = '/auth/backchannel-logout';

    public const PROVISION_PATH = '/auth/provision';

    public const CLIENT_METADATA_PATH = '/auth/client-metadata';

    public const SCIM_PATH = '/auth/scim/v2';

    /**
     * Every setting and its default. An application overrides any of them in
     * `Configure::write('RockSoftwareUsers', [...])` (usually `config/app.php`).
     *
     * - `systemUsers`: ids of `users` rows the application runs jobs as
     *   (konekallio-erp's R2D2); SCIM never touches them.
     * - `alertWebhookUrl`: an https address that also receives every alert
     *   (`AccessManager::EVENT_ALERT`) as JSON, e.g. an IT monitoring
     *   application; null for none.
     * - `timezone`: the zone dates are shown in to people (access start and
     *   end, emergency link expiry).
     * - `signInUrl` / `loginRedirect`: the sign-in page, and where to go after.
     * - `layout`: the sign-in and emergency pages' layout (the plugin's own).
     * - `appName`: shown on the plugin's sign-in layout.
     * - `createUsers`: create a user on first sign-in when none matches.
     * - `settingsAccess`: who may open the settings page; a callable receiving
     *   the identity and the request. Null means any signed-in user, who is
     *   already in the application's required group.
     * - `usersAccess`: who may open the users page; the same kind of callable.
     *   Null means whoever may open the settings page.
     * - `authentication`: `auto` provides session authentication when the
     *   application has no AuthenticationMiddleware; `true`/`false` force it.
     * - `requireSignIn`: `auto` requires a signed-in session for every route
     *   when the plugin provides authentication; `true`/`false` force it.
     * - `publicPaths`: paths reachable without signing in (`fnmatch` patterns,
     *   e.g. `/health`, `/public-api/*`); the sign-in routes always are.
     * - `cacheConfig`: the cache for the provider's discovery document and keys.
     * - `locale`: the language of the plugin's texts when it differs from the
     *   application's locale (e.g. `fi`); null follows the application.
     * - `passwordSignIn`: `true` when people may also sign in with a password
     *   kept in the users table (`users.password`). The sign-in page then
     *   shows the email and password form, and the settings page a switch to
     *   turn it off and on. An application that still has a password form of
     *   its own reads the switch with `SettingsTable::passwordSignInAllowed()`.
     * - `provisioning`: `POST /auth/provision`, or `false` to switch it off.
     *   `issuerPrefix` is the trust anchor: only issuers directly under it are
     *   accepted, and nothing is fetched from anywhere else. `subject` is the
     *   account the token must be issued to, `maxAge` how many seconds after
     *   issue it is accepted, `rateLimit` requests per `rateWindow` seconds.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'systemUsers' => [],
        'alertWebhookUrl' => null,
        'timezone' => 'Europe/Helsinki',
        'signInUrl' => '/auth',
        'signInRedirect' => '/',
        'layout' => 'RockSoftware/Users.sign_in',
        'appName' => null,
        'createUsers' => true,
        'settingsAccess' => null,
        'usersAccess' => null,
        'authentication' => 'auto',
        'requireSignIn' => 'auto',
        'publicPaths' => [],
        'cacheConfig' => 'default',
        'locale' => null,
        'passwordSignIn' => false,
        'provisioning' => [
            'issuerPrefix' => 'https://id.rocksoftware.fi/application/o/',
            'subject' => 'onboarding',
            'maxAge' => 300,
            'rateLimit' => 10,
            'rateWindow' => 900,
        ],
    ];

    /** The translation domain of every text the plugin shows. */
    public const TRANSLATION_DOMAIN = 'rock_software_users';

    protected ?string $name = 'RockSoftware/Users';

    /**
     * The application, for `registerApplicationEvents()`.
     */
    private static ?PluginApplicationInterface $application = null;

    /**
     * The application whose listeners `registerApplicationEvents()` registered.
     */
    private static ?PluginApplicationInterface $eventsRegisteredFor = null;

    protected bool $middlewareEnabled = true;

    protected bool $bootstrapEnabled = true;

    /**
     * Fills in every setting the application left out.
     *
     * @param \Cake\Core\PluginApplicationInterface $app The application.
     * @return void
     */
    public function bootstrap(PluginApplicationInterface $app): void
    {
        self::$application = $app;
        self::applyDefaults();
        self::loadTranslations();
        // A migration that changes a table leaves the schema cache describing
        // the old one, and every query on that table fails until the cache is
        // cleared: `users` lost its password column that way when an
        // application moved to this plugin.
        EventManager::instance()->on('Migration.afterMigrate', [self::class, 'clearSchemaCache']);
        EventManager::instance()->on('Migration.afterRollback', [self::class, 'clearSchemaCache']);
    }

    /**
     * Registers the application's event listeners before the plugin answers a
     * request itself (SCIM, provisioning, back-channel logout), so the
     * application hears `beforeUserSave`, `accessGranted` and the rest.
     *
     * CakePHP before 5.4 registers them only when a request reaches
     * `BaseApplication::handle()`, after the middleware; these endpoints answer
     * before that, so mbm's role for a new person was never set. CakePHP 5.4
     * registers them at bootstrap (`registerEvents()`), and nothing is done.
     * Done once per application object, and only for a request that is answered
     * here and so never reaches `handle()`.
     *
     * @return void
     */
    public static function registerApplicationEvents(): void
    {
        $app = self::$application;
        if (!$app instanceof BaseApplication || method_exists($app, 'registerEvents')) {
            return;
        }
        if (self::$eventsRegisteredFor === $app) {
            return;
        }
        self::$eventsRegisteredFor = $app;
        $app->setEventManager($app->pluginEvents($app->events($app->getEventManager())));
    }

    /**
     * Empties the schema cache of every configured connection that keeps one
     * (`cacheMetadata`), without connecting to any database.
     *
     * @return void
     */
    public static function clearSchemaCache(): void
    {
        $configs = [];
        foreach (ConnectionManager::configured() as $name) {
            $cacheMetadata = ConnectionManager::getConfig($name)['cacheMetadata'] ?? null;
            if ($cacheMetadata === true) {
                $cacheMetadata = '_cake_model_';
            }
            if (is_string($cacheMetadata) && $cacheMetadata !== '') {
                $configs[$cacheMetadata] = true;
            }
        }
        foreach (array_keys($configs) as $config) {
            if (in_array($config, Cache::configured(), true)) {
                Cache::clear($config);
            }
        }
    }

    /**
     * Registers the plugin's texts (domain `rock_software_users`) from its own
     * `resources/locales`. CakePHP finds a plugin's catalogue by domain name
     * only for plugins without a vendor prefix, so the plugin registers it.
     * `locale` in the settings fixes the language whatever the application's
     * locale is (an application formatting in `en_US` with Finnish texts).
     *
     * @return void
     */
    public static function loadTranslations(): void
    {
        $path = dirname(__DIR__) . DS . 'resources' . DS . 'locales' . DS;
        I18n::config(self::TRANSLATION_DOMAIN, function (string $domain, string $locale) use ($path): Package {
            $fixed = Configure::read('RockSoftwareUsers.locale');
            $locale = is_string($fixed) && $fixed !== '' ? $fixed : $locale;
            $messages = [];
            foreach ([$locale, substr($locale, 0, 2)] as $folder) {
                $file = $path . $folder . DS . self::TRANSLATION_DOMAIN . '.po';
                if (is_file($file)) {
                    $messages = (new PoFileParser())->parse($file);
                    break;
                }
            }

            return new Package('default', null, $messages);
        });
    }

    /**
     * @return void
     */
    public static function applyDefaults(): void
    {
        Configure::write(
            'RockSoftwareUsers',
            array_replace(self::DEFAULTS, (array)Configure::read('RockSoftwareUsers', [])),
        );
    }

    /**
     * Wires the plugin into the application's middleware queue. Plugin
     * middleware runs after the application's own `middleware()`, so the
     * queue already holds the application's routing, body parsing, CSRF and
     * (perhaps) authentication; the plugin places itself around them and never
     * adds a second copy of something the application already has.
     *
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue The queue.
     * @return \Cake\Http\MiddlewareQueue
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        // The back-channel logout is a signed server-to-server POST with no
        // CSRF token; answering it before the CSRF middleware means the
        // application needs no exemption.
        // Provisioning is authorised by its bearer token in the same way.
        $earlyEndpoints = [
            new BackchannelLogoutMiddleware(),
            new ProvisionMiddleware(),
            new ScimMiddleware(),
            new ClientMetadataMiddleware(),
        ];
        foreach ($earlyEndpoints as $tokenEndpoint) {
            if (self::queueHas($middlewareQueue, CsrfProtectionMiddleware::class)) {
                $middlewareQueue->insertBefore(CsrfProtectionMiddleware::class, $tokenEndpoint);
            } else {
                self::insertAfterFirst($middlewareQueue, [RoutingMiddleware::class], $tokenEndpoint);
            }
        }

        $providesAuthentication = self::providesAuthentication($middlewareQueue);
        if ($providesAuthentication) {
            self::insertAfterFirst(
                $middlewareQueue,
                [CsrfProtectionMiddleware::class, BodyParserMiddleware::class, RoutingMiddleware::class],
                new AuthenticationMiddleware(new AuthenticationServiceProvider()),
            );
        }

        if (!self::queueHas($middlewareQueue, SessionCheckMiddleware::class)) {
            $middlewareQueue->insertAfter(AuthenticationMiddleware::class, new SessionCheckMiddleware());
        }

        $require = Configure::read('RockSoftwareUsers.requireSignIn');
        if ($require === true || ($require === 'auto' && $providesAuthentication)) {
            $middlewareQueue->insertAfter(SessionCheckMiddleware::class, new RequireSignInMiddleware());
        }

        return $middlewareQueue;
    }

    /**
     * @param \Cake\Routing\RouteBuilder $routes The route builder.
     * @return void
     */
    public function routes(RouteBuilder $routes): void
    {
        $routes->plugin('RockSoftware/Users', ['path' => '/auth'], function (RouteBuilder $builder): void {
            $builder->connect('/', ['controller' => 'SignIn', 'action' => 'index']);
            $builder->connect('/start', ['controller' => 'SignIn', 'action' => 'start']);
            $builder->connect('/password', ['controller' => 'SignIn', 'action' => 'password']);
            $builder->connect('/callback', ['controller' => 'SignIn', 'action' => 'callback']);
            $builder->connect('/sign-out', ['controller' => 'SignIn', 'action' => 'signOut']);
            $builder->connect('/backchannel-logout', ['controller' => 'SignIn', 'action' => 'backchannelLogout']);
            $builder->connect('/provision', ['controller' => 'SignIn', 'action' => 'provision']);
            $builder->connect('/client-metadata', ['controller' => 'SignIn', 'action' => 'clientMetadata']);
            $builder->connect('/scim/v2', ['controller' => 'SignIn', 'action' => 'scim']);
            $builder->connect('/scim/v2/*', ['controller' => 'SignIn', 'action' => 'scim']);
            $builder->connect('/emergency/{token}', ['controller' => 'SignIn', 'action' => 'emergency'])
                ->setPass(['token'])
                ->setPatterns(['token' => '[0-9a-f]{64}']);
            $builder->connect('/settings', ['controller' => 'Settings', 'action' => 'index']);
            $builder->connect('/settings/update', ['controller' => 'Settings', 'action' => 'update']);
            $builder->connect('/settings/test', ['controller' => 'Settings', 'action' => 'test']);
            $builder->connect(
                '/settings/password-sign-in',
                ['controller' => 'Settings', 'action' => 'passwordSignIn'],
            );
            $builder->connect(
                '/settings/allow-provisioning',
                ['controller' => 'Settings', 'action' => 'allowProvisioning'],
            );
            $builder->connect('/settings/sessions', ['controller' => 'Settings', 'action' => 'sessions']);
            $builder->connect('/users', ['controller' => 'Users', 'action' => 'index']);
            $builder->connect('/users/held-removals', ['controller' => 'Users', 'action' => 'heldRevocations']);
            foreach (['approve' => 'approveRevocations', 'reject' => 'rejectRevocations'] as $suffix => $action) {
                $builder->connect('/users/held-removals/' . $suffix, ['controller' => 'Users', 'action' => $action]);
            }
            $userActions = [
                'view' => '',
                'endSessions' => '/end-sessions',
                'emergencyLink' => '/emergency-link',
            ];
            foreach ($userActions as $action => $suffix) {
                $builder->connect('/users/{id}' . $suffix, ['controller' => 'Users', 'action' => $action])
                    ->setPass(['id'])
                    ->setPatterns(['id' => '[0-9]+']);
            }
        });
    }

    /**
     * @param \Cake\Console\CommandCollection $commands The command collection.
     * @return \Cake\Console\CommandCollection
     */
    public function console(CommandCollection $commands): CommandCollection
    {
        return $commands
            ->add(ConfigureCommand::defaultName(), ConfigureCommand::class)
            ->add(EmergencySignInCommand::defaultName(), EmergencySignInCommand::class)
            ->add(RestoreAccessCommand::defaultName(), RestoreAccessCommand::class)
            ->add(RevocationsCommand::defaultName(), RevocationsCommand::class)
            ->add(SetPasswordCommand::defaultName(), SetPasswordCommand::class)
            ->add(StatusCommand::defaultName(), StatusCommand::class);
    }

    /**
     * @param \Cake\Http\MiddlewareQueue $queue The queue.
     * @return bool
     */
    private static function providesAuthentication(MiddlewareQueue $queue): bool
    {
        $setting = Configure::read('RockSoftwareUsers.authentication');
        if (is_bool($setting)) {
            return $setting && !self::queueHas($queue, AuthenticationMiddleware::class);
        }

        return !self::queueHas($queue, AuthenticationMiddleware::class);
    }

    /**
     * @param \Cake\Http\MiddlewareQueue $queue The queue.
     * @param class-string $class The middleware class.
     * @return bool
     */
    private static function queueHas(MiddlewareQueue $queue, string $class): bool
    {
        foreach ($queue as $middleware) {
            if (is_a($middleware, $class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Inserts after the first of the given classes found in the queue, or at
     * the end when none is.
     *
     * @param \Cake\Http\MiddlewareQueue $queue The queue.
     * @param array<class-string> $classes Candidates, in order of preference.
     * @param \Psr\Http\Server\MiddlewareInterface $middleware The middleware.
     * @return void
     */
    private static function insertAfterFirst(
        MiddlewareQueue $queue,
        array $classes,
        MiddlewareInterface $middleware,
    ): void {
        foreach ($classes as $class) {
            if (self::queueHas($queue, $class)) {
                $queue->insertAfter($class, $middleware);

                return;
            }
        }
        $queue->add($middleware);
    }
}
