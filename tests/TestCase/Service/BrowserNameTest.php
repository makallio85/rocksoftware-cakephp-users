<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase\Service;

use Cake\TestSuite\TestCase;
use RockSoftware\Users\Service\BrowserName;

class BrowserNameTest extends TestCase
{
    public function testCommonBrowsers(): void
    {
        $cases = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' => ['Chrome 141', 'Windows'],
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0' => ['Edge 141', 'Windows'],
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1' => ['Safari 18', 'iPhone'],
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:132.0) Gecko/20100101 Firefox/132.0' => ['Firefox 132', 'macOS'],
            'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36' => ['Chrome 141', 'Android'],
            'curl/8.5.0' => [null, null],
        ];
        foreach ($cases as $agent => [$browser, $device]) {
            $this->assertSame(['browser' => $browser, 'device' => $device], BrowserName::describe($agent), $agent);
        }
        $this->assertSame(['browser' => null, 'device' => null], BrowserName::describe(null));
    }
}
