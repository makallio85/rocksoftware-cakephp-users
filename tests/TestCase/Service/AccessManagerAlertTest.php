<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase\Service;

use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;
use RockSoftware\Settings\Settings;
use RockSoftware\Settings\SettingsPlugin;
use RockSoftware\Users\Service\AccessManager;

/**
 * The alert webhook names the application by the settings plugin's
 * `application.name`.
 */
class AccessManagerAlertTest extends TestCase
{
    protected function tearDown(): void
    {
        Configure::write('RockSoftwareUsers.alertWebhookUrl', null);
        Configure::delete('RockSoftwareUsers.appName');
        Settings::set(SettingsPlugin::APPLICATION_NAME, 'Test App');
        parent::tearDown();
    }

    public function testTheWebhookNamesTheApplicationFromTheSettings(): void
    {
        Configure::write('RockSoftwareUsers.alertWebhookUrl', 'https://monitor.example.test/alerts');
        Configure::write('RockSoftwareUsers.appName', 'Old Name');
        Settings::set(SettingsPlugin::APPLICATION_NAME, 'Example Console');

        $sent = null;
        $http = $this->createMock(Client::class);
        $http->expects($this->once())
            ->method('post')
            ->with('https://monitor.example.test/alerts')
            ->willReturnCallback(function (string $url, string $body) use (&$sent): Response {
                $sent = json_decode($body, true);

                return new Response(['HTTP/1.1 200 OK']);
            });

        (new AccessManager($http))->alert(AccessManager::ALERT_REVOCATIONS_HELD, 'Revocations are held.');

        $this->assertIsArray($sent);
        $this->assertSame('Example Console', $sent['application']);
    }
}
