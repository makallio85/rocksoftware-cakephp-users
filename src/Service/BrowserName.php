<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service;

/**
 * A User-Agent as people read it: the browser with its main version, and the
 * device ("Chrome 141" on "Windows"). Only what the sign-in history needs; the
 * full string stays beside it.
 */
final class BrowserName
{
    /**
     * Browsers, checked in this order: Edge and Opera also say Chrome, and
     * Chrome also says Safari.
     *
     * @var array<string, string> Pattern => name.
     */
    private const BROWSERS = [
        '#Edg(?:e|A|iOS)?/(\d+)#' => 'Edge',
        '#(?:OPR|Opera)/(\d+)#' => 'Opera',
        '#Firefox/(\d+)#' => 'Firefox',
        '#FxiOS/(\d+)#' => 'Firefox',
        '#CriOS/(\d+)#' => 'Chrome',
        '#Chrome/(\d+)#' => 'Chrome',
        '#Version/(\d+)[.\d]* (?:Mobile/\S+ )?Safari/#' => 'Safari',
    ];

    /**
     * @var array<string, string> Pattern => device.
     */
    private const DEVICES = [
        '#iPhone#' => 'iPhone',
        '#iPad#' => 'iPad',
        '#Android#' => 'Android',
        '#Windows#' => 'Windows',
        '#Macintosh|Mac OS X#' => 'macOS',
        '#CrOS#' => 'ChromeOS',
        '#Linux#' => 'Linux',
    ];

    /**
     * @param string|null $userAgent The User-Agent.
     * @return array{browser: string|null, device: string|null} Null browser: not a known browser.
     */
    public static function describe(?string $userAgent): array
    {
        if ($userAgent === null || $userAgent === '') {
            return ['browser' => null, 'device' => null];
        }
        $browser = null;
        foreach (self::BROWSERS as $pattern => $name) {
            if (preg_match($pattern, $userAgent, $match)) {
                $browser = $name . ' ' . $match[1];
                break;
            }
        }
        $device = null;
        foreach (self::DEVICES as $pattern => $name) {
            if (preg_match($pattern, $userAgent)) {
                $device = $name;
                break;
            }
        }

        return ['browser' => $browser, 'device' => $device];
    }
}
