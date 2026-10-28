<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase\Middleware;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\Http\Client;
use Cake\Http\ServerRequest;
use Cake\I18n\DateTime;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Firebase\JWT\JWT;
use RockSoftware\Users\Middleware\BearerTokenMiddleware;
use RockSoftware\Users\Model\Entity\SessionEventType;
use RockSoftware\Users\Test\TestCase\FakeProvider;
use RockSoftware\Users\UsersPlugin;

/**
 * `/mcp` is the test application's bearer path, as an MCP endpoint would be.
 * Tokens come from the fake provider over mocked HTTP, the way Rock Software
 * ID publishes its keys.
 */
class BearerTokenMiddlewareTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'plugin.RockSoftware/Users.Settings',
        'plugin.RockSoftware/Users.Identities',
        'plugin.RockSoftware/Users.SessionEvents',
        'plugin.RockSoftware/Users.Users',
    ];

    private FakeProvider $provider;

    private int $jwksFetches = 0;

    /**
     * @var list<array<string, mixed>>
     */
    private array $refusals = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $acceptances = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear('default');
        Client::clearMockResponses();
        Configure::write('RockSoftwareUsers.bearer', ['paths' => ['/mcp']]);
        $this->provider = new FakeProvider();
        $this->provider->serveMetadata(FakeProvider::ISSUER, null, $this->jwksFetches);
        $this->connect(['bearer_audience' => FakeProvider::MCP_AUDIENCE]);
        EventManager::instance()->on(BearerTokenMiddleware::EVENT_REFUSED, function (EventInterface $event): void {
            $this->refusals[] = (array)$event->getData();
        });
        EventManager::instance()->on(BearerTokenMiddleware::EVENT_ACCEPTED, function (EventInterface $event): void {
            $this->acceptances[] = (array)$event->getData();
        });
    }

    protected function tearDown(): void
    {
        Client::clearMockResponses();
        Configure::write('RockSoftwareUsers.bearer', UsersPlugin::DEFAULTS['bearer']);
        EventManager::instance()->off(BearerTokenMiddleware::EVENT_REFUSED);
        EventManager::instance()->off(BearerTokenMiddleware::EVENT_ACCEPTED);
        parent::tearDown();
    }

    public function testValidTokenSignsTheUserIn(): void
    {
        $user = $this->createUser();

        $this->withToken($this->provider->accessToken(['jti' => 'token-1']));
        $this->get('/mcp');

        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('person@example.test', $body['email']);
        $this->assertSame([
            'clientId' => FakeProvider::MCP_AUDIENCE,
            'scope' => ['openid', 'email', 'profile'],
            'jti' => 'token-1',
            'subject' => 'subject-1',
        ], $body['bearer']);
        $this->assertTrue($body['isViaAgent']);
        $this->assertCount(1, $this->acceptances);
        $this->assertSame((int)$user->get('id'), (int)$this->acceptances[0]['user']->get('id'));
        $this->assertSame(200, $this->acceptances[0]['status']);
        $this->assertSame('token-1', $this->acceptances[0]['jti']);
        $this->assertSame([], $this->refusals);
    }

    public function testPostNeedsNoCsrfToken(): void
    {
        $this->createUser();

        $this->withToken($this->provider->accessToken());
        $this->post('/mcp', ['jsonrpc' => '2.0']);

        $this->assertResponseOk();
    }

    public function testMissingTokenIsABare401WithoutAnError(): void
    {
        $this->get('/mcp');

        $this->assertResponseCode(401);
        $this->assertSame('', (string)$this->_response->getBody());
        $this->assertHeader('WWW-Authenticate', 'Bearer realm="Test App"');
        $this->assertSame(BearerTokenMiddleware::REASON_MISSING_TOKEN, $this->refusals[0]['reason']);
    }

    public function testChallengeNamesTheResourceMetadata(): void
    {
        Configure::write('RockSoftwareUsers.bearer.resourceMetadataUrl', 'https://app.example.test/.well-known/oauth-protected-resource');

        $this->get('/mcp');
        $this->assertHeader(
            'WWW-Authenticate',
            'Bearer realm="Test App", resource_metadata="https://app.example.test/.well-known/oauth-protected-resource"',
        );

        $this->withToken('a.b.c');
        $this->get('/mcp');
        $this->assertHeader(
            'WWW-Authenticate',
            'Bearer realm="Test App", error="invalid_token", resource_metadata="https://app.example.test/.well-known/oauth-protected-resource"',
        );
    }

    public function testAnotherSchemeIsAnInvalidToken(): void
    {
        $this->_request = [];
        $this->configRequest(['headers' => ['Authorization' => 'Basic dXNlcjpwYXNz']]);
        $this->get('/mcp');

        $this->assertInvalidToken();
    }

    public function testEveryTokenIsRefusedUntilAnAudienceIsSet(): void
    {
        $this->createUser();
        $this->fetchTable('RockSoftware/Users.Settings')->store(['bearer_audience' => null]);

        $this->withToken($this->provider->accessToken());
        $this->get('/mcp');

        $this->assertInvalidToken(BearerTokenMiddleware::REASON_NOT_CONFIGURED);
    }

    public function testEveryTokenIsRefusedWhileTheConnectionIsOff(): void
    {
        $this->createUser();
        $this->fetchTable('RockSoftware/Users.Settings')->store(['is_enabled' => false]);

        $this->withToken($this->provider->accessToken());
        $this->get('/mcp');

        $this->assertInvalidToken(BearerTokenMiddleware::REASON_NOT_CONFIGURED);
    }

    public function testTokenForAnotherAudienceIsRefused(): void
    {
        $this->createUser();

        $this->withToken($this->provider->accessToken(['aud' => FakeProvider::CLIENT_ID]));
        $this->get('/mcp');

        $this->assertInvalidToken();
    }

    public function testAudienceMayBeAList(): void
    {
        $this->createUser();

        $this->withToken($this->provider->accessToken(['aud' => ['other-api', FakeProvider::MCP_AUDIENCE]]));
        $this->get('/mcp');
        $this->assertResponseOk();

        $this->withToken($this->provider->accessToken(['aud' => ['other-api', 'third-api']]));
        $this->get('/mcp');
        $this->assertInvalidToken();
    }

    public function testExpiredTokenIsRefused(): void
    {
        $this->createUser();

        $this->withToken($this->provider->accessToken(['iat' => time() - 900, 'exp' => time() - 90]));
        $this->get('/mcp');

        $this->assertInvalidToken();
    }

    public function testExpiryHasSixtySecondsOfLeeway(): void
    {
        $this->createUser();

        $this->withToken($this->provider->accessToken(['iat' => time() - 900, 'exp' => time() - 30]));
        $this->get('/mcp');

        $this->assertResponseOk();
    }

    public function testTokenWithoutExpiryIsRefused(): void
    {
        $this->createUser();
        $claims = ['iss' => FakeProvider::ISSUER, 'aud' => FakeProvider::MCP_AUDIENCE, 'sub' => 'subject-1', 'iat' => time()];

        $this->withToken($this->provider->idToken($claims + ['exp' => null]));
        $this->get('/mcp');

        $this->assertInvalidToken();
    }

    public function testTokenNotValidYetIsRefused(): void
    {
        $this->createUser();

        $this->withToken($this->provider->accessToken(['nbf' => time() + 120]));
        $this->get('/mcp');
        $this->assertInvalidToken();

        $this->withToken($this->provider->accessToken(['nbf' => time(), 'iat' => time() + 120]));
        $this->get('/mcp');
        $this->assertInvalidToken();

        $this->withToken($this->provider->accessToken(['nbf' => time() + 30, 'iat' => time() + 30]));
        $this->get('/mcp');
        $this->assertResponseOk('within the leeway');
    }

    public function testTokenFromAnotherIssuerIsRefused(): void
    {
        $this->createUser();

        $this->withToken($this->provider->accessToken(['iss' => 'https://id.example.test/application/o/other/']));
        $this->get('/mcp');

        $this->assertInvalidToken();
    }

    public function testForgedSignatureIsRefused(): void
    {
        $this->createUser();

        $this->withToken($this->provider->accessToken([], FakeProvider::foreignKey()));
        $this->get('/mcp');

        $this->assertInvalidToken();
    }

    public function testUnsignedTokenIsRefused(): void
    {
        $this->createUser();
        $header = JWT::urlsafeB64Encode((string)json_encode(['alg' => 'none', 'kid' => FakeProvider::KID]));
        $payload = JWT::urlsafeB64Encode((string)json_encode([
            'iss' => FakeProvider::ISSUER, 'aud' => FakeProvider::MCP_AUDIENCE, 'sub' => 'subject-1',
            'iat' => time(), 'exp' => time() + 300,
        ]));

        $this->withToken($header . '.' . $payload . '.e30');
        $this->get('/mcp');

        $this->assertInvalidToken();
    }

    public function testSymmetricallySignedTokenIsRefused(): void
    {
        $this->createUser();

        // Signed with the public key as an HMAC secret: the classic key-confusion forgery.
        $this->withToken($this->provider->accessToken([], 'any-shared-secret-of-sufficient-length-0123456789', 'HS256'));
        $this->get('/mcp');

        $this->assertInvalidToken();
    }

    public function testOnlyRs256AndEs256AreAccepted(): void
    {
        $this->createUser();

        $this->withToken($this->provider->accessToken([], null, 'RS512'));
        $this->get('/mcp');

        $this->assertInvalidToken();
    }

    public function testEs256TokenIsAccepted(): void
    {
        $this->createUser();
        $ecKey = $this->provider->addKey('ec-key', 'EC', 'ES256');
        Client::clearMockResponses();
        $this->provider->serveMetadata(FakeProvider::ISSUER, null, $this->jwksFetches);

        $this->withToken($this->provider->accessToken([], $ecKey, 'ES256', 'ec-key'));
        $this->get('/mcp');

        $this->assertResponseOk();
    }

    public function testTokenSignedWithAnAlgorithmItsKeyDoesNotDeclareIsRefused(): void
    {
        $this->createUser();
        $ecKey = $this->provider->addKey('ec-key', 'EC', 'ES256');
        Client::clearMockResponses();
        $this->provider->serveMetadata(FakeProvider::ISSUER, null, $this->jwksFetches);

        // The RSA key is published for RS256; an ES256 header naming it must not pass.
        $this->withToken($this->provider->accessToken([], $ecKey, 'ES256', FakeProvider::KID));
        $this->get('/mcp');

        $this->assertInvalidToken();
    }

    public function testTokenWithoutKeyIdIsRefused(): void
    {
        $this->createUser();

        $this->withToken($this->provider->accessToken([], null, 'RS256', null));
        $this->get('/mcp');

        $this->assertInvalidToken();
    }

    public function testUnknownKeyIsFetchedOnceThenRefused(): void
    {
        $this->createUser();
        $unpublished = FakeProvider::foreignKey();

        $this->withToken($this->provider->accessToken([], $unpublished, 'RS256', 'unknown-key'));
        $this->get('/mcp');
        $this->assertInvalidToken();
        $this->assertSame(2, $this->jwksFetches, 'the cached keys, then once more for the unknown key id');

        $this->withToken($this->provider->accessToken([], $unpublished, 'RS256', 'unknown-key'));
        $this->get('/mcp');
        $this->assertInvalidToken();
        $this->assertSame(2, $this->jwksFetches, 'made-up key ids cannot make every request call the provider');
    }

    public function testRotatedKeysAreFetchedAgain(): void
    {
        $this->createUser();
        $this->withToken($this->provider->accessToken());
        $this->get('/mcp');
        $this->assertResponseOk();
        $this->assertSame(1, $this->jwksFetches);

        $newKey = $this->provider->addKey('rotated-key');
        Client::clearMockResponses();
        $this->provider->serveMetadata(FakeProvider::ISSUER, null, $this->jwksFetches);
        $this->withToken($this->provider->accessToken([], $newKey, 'RS256', 'rotated-key'));
        $this->get('/mcp');

        $this->assertResponseOk();
        $this->assertSame(1, $this->jwksFetches, 'fetched again once for the new key id');
    }

    public function testUnknownSubjectIsForbidden(): void
    {
        $this->createUser();

        $this->withToken($this->provider->accessToken(['sub' => 'subject-nobody']));
        $this->get('/mcp');

        $this->assertForbidden(BearerTokenMiddleware::REASON_UNKNOWN_USER);
        $this->assertSame('subject-nobody', $this->refusals[0]['subject']);
    }

    public function testUserWithoutAccessIsForbidden(): void
    {
        $cases = [
            'revoked' => ['is_active' => false],
            'not started' => ['access_from' => DateTime::now()->addDays(2)],
            'ended' => ['access_until' => DateTime::now()->subMinutes(1)],
        ];
        foreach ($cases as $case => $values) {
            $this->refusals = [];
            $users = $this->fetchTable('Users');
            $users->deleteAll([]);
            $this->fetchTable('RockSoftware/Users.Identities')->deleteAll([]);
            $user = $this->createUser();
            $users->saveOrFail($users->patchEntity($user, $values, ['accessibleFields' => ['*' => true], 'validate' => false]));

            $this->withToken($this->provider->accessToken());
            $this->get('/mcp');

            $this->assertForbidden(BearerTokenMiddleware::REASON_NO_ACCESS, $case);
            $this->assertSame((int)$user->get('id'), (int)$this->refusals[0]['user']->get('id'), $case);
        }
    }

    public function testUserOutsideTheRequiredGroupIsForbidden(): void
    {
        $this->createUser();
        $this->fetchTable('RockSoftware/Users.Settings')->store(['required_group' => 'app-staff']);

        $this->withToken($this->provider->accessToken(['groups' => ['other-staff']]));
        $this->get('/mcp');
        $this->assertForbidden(BearerTokenMiddleware::REASON_NOT_IN_GROUP);

        $this->withToken($this->provider->accessToken(['groups' => null]));
        $this->get('/mcp');
        $this->assertResponseCode(403, 'a token without groups cannot show the group');

        $this->withToken($this->provider->accessToken());
        $this->get('/mcp');
        $this->assertResponseOk();
    }

    public function testTokenIssuedBeforeABackchannelLogoutIsRefused(): void
    {
        $this->createUser();
        $identities = $this->fetchTable('RockSoftware/Users.Identities');
        $identity = $identities->findByExternalId('subject-1');
        $identity->set('sessions_valid_from', DateTime::now());
        $identities->saveOrFail($identity);

        $this->withToken($this->provider->accessToken(['iat' => time() - 120]));
        $this->get('/mcp');
        $this->assertInvalidToken(BearerTokenMiddleware::REASON_TOKEN_REVOKED);

        $this->withToken($this->provider->accessToken(['iat' => time() + 5]));
        $this->get('/mcp');
        $this->assertResponseOk('a token issued afterwards');
    }

    public function testTokenIssuedBeforeSessionsWereEndedIsRefused(): void
    {
        $user = $this->createUser();
        $this->fetchTable('RockSoftware/Users.SessionEvents')->record(SessionEventType::SESSIONS_ENDED, [
            'user_id' => (int)$user->get('id'),
        ]);

        $this->withToken($this->provider->accessToken(['iat' => time() - 120]));
        $this->get('/mcp');

        $this->assertInvalidToken(BearerTokenMiddleware::REASON_TOKEN_REVOKED);
    }

    public function testRequestWithASessionCookieIsNotABearerRequest(): void
    {
        $this->createUser();

        $this->cookie((string)session_name(), 'browser-session');
        $this->withToken($this->provider->accessToken());
        $this->get('/mcp');

        // The browser's way: no session identity, so the sign-in page.
        $this->assertRedirectContains('/auth?redirect=%2Fmcp');
        $this->assertSame([], $this->acceptances);
        $this->assertSame([], $this->refusals);
    }

    public function testPostWithASessionCookieStillNeedsACsrfToken(): void
    {
        $this->cookie((string)session_name(), 'browser-session');
        $this->withToken($this->provider->accessToken());
        $this->post('/mcp', ['jsonrpc' => '2.0']);

        $this->assertResponseCode(403);
    }

    public function testOnlyListedPathsArePrefixMatched(): void
    {
        $this->assertTrue($this->isBearer('/mcp'));
        $this->assertTrue($this->isBearer('/mcp/tools'));
        $this->assertFalse($this->isBearer('/mcpx'));
        $this->assertFalse($this->isBearer('/dashboard'));

        Configure::write('RockSoftwareUsers.bearer.paths', []);
        $this->assertFalse($this->isBearer('/mcp'), 'off by default');
    }

    public function testInvalidTokensAreRateLimitedPerClientAddress(): void
    {
        $this->createUser();
        Configure::write('RockSoftwareUsers.bearer.rateLimit', 3);

        for ($i = 0; $i < 3; $i++) {
            $this->withToken($this->provider->accessToken([], FakeProvider::foreignKey()));
            $this->get('/mcp');
            $this->assertResponseCode(401);
        }
        $this->withToken($this->provider->accessToken());
        $this->get('/mcp');

        $this->assertResponseCode(429);
        $this->assertHeader('Retry-After', '900');
        $this->assertSame(BearerTokenMiddleware::REASON_RATE_LIMITED, end($this->refusals)['reason']);

        $this->_request = [];
        $this->configRequest([
            'environment' => ['REMOTE_ADDR' => '203.0.113.9'],
            'headers' => ['Authorization' => 'Bearer ' . $this->provider->accessToken()],
        ]);
        $this->get('/mcp');
        $this->assertResponseOk('another address is not held back');
    }

    public function testAccessTokenIssuerMayBeAnotherProviderOnTheSameHost(): void
    {
        $this->createUser();
        $mcpIssuer = 'https://id.example.test/application/o/app-mcp/';
        $this->provider->serveMetadata($mcpIssuer);
        $this->fetchTable('RockSoftware/Users.Settings')->store(['bearer_issuer_url' => $mcpIssuer]);

        $this->withToken($this->provider->accessToken(['iss' => $mcpIssuer]));
        $this->get('/mcp');
        $this->assertResponseOk();

        $this->withToken($this->provider->accessToken());
        $this->get('/mcp');
        $this->assertInvalidToken('invalid_token', 'the sign-in issuer is no longer the token issuer');
    }

    public function testAccessTokenIssuerOnAnotherHostIsRefused(): void
    {
        $this->createUser();
        $foreign = 'https://evil.example.test/application/o/app/';
        $this->provider->serveMetadata($foreign);
        // Written past the settings page, which refuses it.
        $this->fetchTable('RockSoftware/Users.Settings')->store(['bearer_issuer_url' => $foreign]);

        $this->withToken($this->provider->accessToken(['iss' => $foreign]));
        $this->get('/mcp');

        $this->assertInvalidToken();
    }

    public function testKeysOnAnotherHostAreRefused(): void
    {
        $this->createUser();
        $mcpIssuer = 'https://id.example.test/application/o/app-mcp/';
        $this->provider->serveMetadata($mcpIssuer, 'https://evil.example.test/jwks/');
        $this->fetchTable('RockSoftware/Users.Settings')->store(['bearer_issuer_url' => $mcpIssuer]);

        $this->withToken($this->provider->accessToken(['iss' => $mcpIssuer]));
        $this->get('/mcp');

        $this->assertInvalidToken();
    }

    /**
     * @param string $path A request path.
     * @return bool
     */
    private function isBearer(string $path): bool
    {
        return BearerTokenMiddleware::isBearerRequest(new ServerRequest(['url' => $path]));
    }

    /**
     * @param string $reason The `REASON_*` expected.
     * @param string $message Why.
     * @return void
     */
    private function assertInvalidToken(string $reason = BearerTokenMiddleware::REASON_INVALID_TOKEN, string $message = ''): void
    {
        $this->assertResponseCode(401, $message);
        $this->assertSame('', (string)$this->_response->getBody(), $message);
        $this->assertHeader('WWW-Authenticate', 'Bearer realm="Test App", error="invalid_token"', $message);
        $this->assertSame($reason, end($this->refusals)['reason'], $message);
        $this->assertSame([], $this->acceptances, $message);
    }

    /**
     * @param string $reason The `REASON_*` expected.
     * @param string $message Why.
     * @return void
     */
    private function assertForbidden(string $reason, string $message = ''): void
    {
        $this->assertResponseCode(403, $message);
        $this->assertSame('', (string)$this->_response->getBody(), $message);
        $this->assertFalse($this->_response->hasHeader('WWW-Authenticate'), $message);
        $this->assertSame($reason, end($this->refusals)['reason'], $message);
        $this->assertSame(403, end($this->refusals)['status'], $message);
        $this->assertSame([], $this->acceptances, $message);
    }

    /**
     * @param string $token The bearer token.
     * @return void
     */
    private function withToken(string $token): void
    {
        // configRequest() merges into what earlier requests of the test set.
        $this->_request = [];
        $this->acceptances = [];
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $token]]);
    }

    /**
     * @param array<string, mixed> $values Overrides.
     * @return void
     */
    private function connect(array $values): void
    {
        $this->fetchTable('RockSoftware/Users.Settings')->store($values + [
            'issuer_url' => FakeProvider::ISSUER,
            'client_id' => FakeProvider::CLIENT_ID,
            'client_secret' => FakeProvider::CLIENT_SECRET,
            'is_enabled' => true,
        ]);
    }

    /**
     * A person SCIM pushed, linked to `subject-1`.
     *
     * @return \Cake\Datasource\EntityInterface
     */
    private function createUser(): EntityInterface
    {
        $users = $this->fetchTable('Users');
        $user = $users->saveOrFail($users->newEntity([
            'email' => 'person@example.test',
            'password' => '!rsu:x',
            'name' => 'Person',
            'is_active' => true,
        ], ['accessibleFields' => ['*' => true], 'validate' => false]));
        $identities = $this->fetchTable('RockSoftware/Users.Identities');
        $identities->saveOrFail($identities->newEntity(
            ['user_id' => $user->id, 'external_id' => 'subject-1'],
            ['accessibleFields' => ['*' => true]],
        ));

        return $user;
    }
}
