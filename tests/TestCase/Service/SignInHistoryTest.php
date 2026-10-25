<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase\Service;

use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use RockSoftware\Users\Service\SignInHistory;

/**
 * The client's address behind the proxies every application runs behind, and
 * the browser.
 */
class SignInHistoryTest extends TestCase
{
    public function testAPublicConnectionIsTheClient(): void
    {
        $request = new ServerRequest(['environment' => [
            'REMOTE_ADDR' => '203.0.113.17',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.4',
        ]]);

        $this->assertSame('203.0.113.17', SignInHistory::clientIp($request), 'a client cannot claim another address');
    }

    public function testBehindTheProxyTheProxysEntryIsTheClient(): void
    {
        $request = new ServerRequest(['environment' => [
            'REMOTE_ADDR' => '172.16.0.7',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.66, 203.0.113.17, 10.0.0.2',
        ]]);

        $this->assertSame('203.0.113.17', SignInHistory::clientIp($request), 'the left one is the client\'s own claim');
    }

    public function testOnlyPrivateAddressesFallBackToTheConnection(): void
    {
        $request = new ServerRequest(['environment' => [
            'REMOTE_ADDR' => '172.16.0.7',
            'HTTP_X_FORWARDED_FOR' => '10.0.0.2, garbage',
        ]]);

        $this->assertSame('172.16.0.7', SignInHistory::clientIp($request));
        $this->assertNull(SignInHistory::clientIp(null));
    }

    public function testTheBrowserIsKeptCutToTheColumn(): void
    {
        $request = new ServerRequest(['environment' => ['HTTP_USER_AGENT' => str_repeat('Mozilla ', 50)]]);

        $this->assertSame(255, mb_strlen((string)SignInHistory::userAgent($request)));
        $this->assertNull(SignInHistory::userAgent(new ServerRequest()));
    }
}
