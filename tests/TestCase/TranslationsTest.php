<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase;

use Cake\TestSuite\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every text the plugin shows has a Finnish translation, so an application in
 * Finnish never shows a stray English sentence.
 */
class TranslationsTest extends TestCase
{
    public function testEveryTextHasAFinnishTranslation(): void
    {
        $root = dirname(__DIR__, 2);
        $po = (string)file_get_contents($root . '/resources/locales/fi/rock_software_users.po');
        preg_match_all('/^msgid(?:_plural)? "(.+)"$/m', $po, $ids);
        $translated = array_map(fn(string $id): string => stripcslashes($id), $ids[1]);

        $missing = [];
        foreach (['src', 'templates'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $source = (string)file_get_contents($file->getPathname());
                $texts = [];
                // __d('rock_software_users', '…') and the plural __dn('rock_software_users', '…', '…'),
                // in single or double quotes.
                $quoted = "(?:'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\")";
                preg_match_all("/__dn?\\(\\s*'rock_software_users',\\s*{$quoted}(?:,\\s*{$quoted})?/", $source, $m, PREG_SET_ORDER);
                foreach ($m as $match) {
                    $isPlural = str_starts_with($match[0], '__dn');
                    $texts[] = $match[1] !== '' ? $match[1] : ($match[2] ?? '');
                    if ($isPlural) {
                        $texts[] = ($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? '');
                    }
                }
                foreach ($texts as $text) {
                    $text = str_replace(["\\'", '\\"'], ["'", '"'], $text);
                    if ($text !== '' && !in_array($text, $translated, true)) {
                        $missing[] = $text;
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)));
    }
}
