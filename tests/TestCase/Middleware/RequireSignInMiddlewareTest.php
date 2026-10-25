<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase\Middleware;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * The test application has no authentication of its own, so the plugin
 * provides it and requires sign-in for every route that is not public.
 */
class RequireSignInMiddlewareTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'plugin.RockSoftware/Users.Settings',
        'plugin.RockSoftware/Users.Identities',
        'plugin.RockSoftware/Users.Users',
    ];

    public function testAnonymousPageRequestIsSentToSignInAndBack(): void
    {
        $this->get('/dashboard?tab=2');

        $this->assertRedirect('/auth?redirect=%2Fdashboard%3Ftab%3D2');
    }

    public function testAnonymousJsonRequestGets401(): void
    {
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);

        $this->get('/dashboard');

        $this->assertResponseCode(401);
        $this->assertContentType('application/json');
    }

    public function testPublicPathsPass(): void
    {
        $this->get('/health');
        $this->assertResponseOk();
        $this->assertResponseEquals('ok');

        $this->get('/auth');
        $this->assertResponseOk();
    }

    public function testSettingsPageIsNotPublic(): void
    {
        $this->get('/auth/settings');

        $this->assertRedirect('/auth?redirect=%2Fauth%2Fsettings');
    }

    public function testSignInPageUsesThePluginsLayout(): void
    {
        $this->get('/auth');

        $this->assertResponseContains('<p class="rsu-app">Test App</p>');
        $this->assertResponseContains('<main class="rsu-card">');
    }
}
