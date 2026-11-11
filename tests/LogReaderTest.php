<?php

declare(strict_types=1);

/*
 * This file is part of cgoit\contao-sitewatch-bundle for Contao Open Source CMS.
 *
 * @copyright  Copyright (c) cgoIT
 * @author     cgoIT <https://cgo-it.de>
 * @license    LGPL-3.0-or-later
 */

namespace Cgoit\SitewatchBundle\Tests;

use Cgoit\SitewatchBundle\Report\LogReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class LogReaderTest extends TestCase
{
    public function testGroupsErrorsAndIgnoresOthers(): void
    {
        $now = new \DateTimeImmutable('2026-11-01T10:00:00+00:00');
        $dir = sys_get_temp_dir().'/sitewatch-'.bin2hex(random_bytes(4));
        $fs = new Filesystem();
        $fs->dumpFile($dir.'/prod-2026-11-01.log', implode("\n", [
            '[2026-11-01T09:30:00.123456+00:00] request.CRITICAL: Boom {"a":1} []',
            '[2026-11-01T09:31:00.000000+00:00] request.CRITICAL: Boom {"a":2} []',
            '[2026-11-01T09:32:00.000000+00:00] app.INFO: ignoriert [] []',
            '[2026-10-01T09:32:00.000000+00:00] app.ERROR: zu alt [] []',
            'keine Logzeile',
        ])."\n");
        $fs->touch($dir.'/prod-2026-11-01.log', $now->getTimestamp());

        $errors = (new LogReader())->read($dir, $now);

        $fs->remove($dir);

        $this->assertSame(['errors' => [['time' => '2026-11-01T09:31:00+00:00', 'level' => 'CRITICAL', 'message' => 'Boom', 'count' => 2]], 'known' => 0], $errors);
    }

    /**
     * Bekannte CSP-Meldungen aus Besucher-Browsern werden gezählt, alle anderen bleiben sichtbar (Beispieldaten).
     */
    public function testHidesKnownCspNoise(): void
    {
        $iphoneSafari = 'Mozilla\\\\/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit\\\\/605.1.15 (KHTML, like Gecko) Version\\\\/27.2 Mobile\\\\/15E148 Safari\\\\/604.1';
        $instagram = 'Mozilla\\\\/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit\\\\/605.1.15 (KHTML, like Gecko) Mobile\\\\/22H20 Instagram 400.0.0';
        $iphoneCriOS = 'Mozilla\\\\/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit\\\\/605.1.15 (KHTML, like Gecko) CriOS\\\\/151.0.0.0 Mobile\\\\/15E148 Safari\\\\/604.1';
        $androidChrome = 'Mozilla\\\\/5.0 (Linux; Android 10; K) AppleWebKit\\\\/537.36 (KHTML, like Gecko) Chrome\\\\/151.0.0.0 Mobile Safari\\\\/537.36';
        $winChrome = 'Mozilla\\\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\\\/537.36 (KHTML, like Gecko) Chrome\\\\/151.0.0.0 Safari\\\\/537.36';
        $winEdge = $winChrome.' Edg\\\\/151.0.0.0';
        $winFirefox = 'Mozilla\\\\/5.0 (Windows NT 10.0; Win64; x64; rv:150.0) Gecko\\\\/20100101 Firefox\\\\/150.0';
        $macSafari = 'Mozilla\\\\/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit\\\\/605.1.15 (KHTML, like Gecko) Version\\\\/27.2 Safari\\\\/605.1.15';

        $hidden = [
            ['style-src-attr', $iphoneSafari],
            ['style-src-attr', $instagram],
            ['style-src-elem', $androidChrome],
            ['connect-src', $winChrome],
            ['connect-src', $winEdge],
        ];
        $visible = [
            ['script-src', $winChrome],
            ['script-src-elem', $winFirefox],
            ['frame-src', $winChrome],
            ['img-src', $iphoneCriOS],
            ['style-src-attr', $winFirefox],
            ['style-src-elem', $winChrome],
            ['style-src-attr', $macSafari],
            ['connect-src', $macSafari],
            ['connect-src', $androidChrome],
            ['style-src-attr', ''], // ohne User-Agent nie ausblenden, auch wenn die URI passende Wörter enthält
        ];

        // Zeile, wie Contao (CspReportListener) sie schreibt
        $line = static fn (array $c): string => '[2026-11-01T09:30:00.000000+00:00] app.ERROR: Content-Security-Policy violation reported for "'.$c[0].'" on line 1 {"contao":{"Contao\\\\CoreBundle\\\\Monolog\\\\ContaoContext":"{\\"func\\":\\"x\\",\\"action\\":\\"ERROR\\",\\"username\\":\\"N\\\\/A\\",\\"browser\\":\\"'.$c[1].'\\",\\"uri\\":\\"https:\\\\/\\\\/example.org\\\\/iPhone-Android-Chrome-Windows\\",\\"pageId\\":null}"}} []';

        $now = new \DateTimeImmutable('2026-11-01T10:00:00+00:00');
        $dir = sys_get_temp_dir().'/sitewatch-'.bin2hex(random_bytes(4));
        $fs = new Filesystem();
        $fs->dumpFile($dir.'/prod-2026-11-01.log', implode("\n", [
            ...array_map($line, $hidden),
            ...array_map($line, $visible),
            '[2026-11-01T09:40:00.000000+00:00] request.CRITICAL: Uncaught PHP Exception RuntimeException: "Boom" at Foo.php line 3 {"exception":"[object]"} []',
        ])."\n");
        $fs->touch($dir.'/prod-2026-11-01.log', $now->getTimestamp());

        $result = (new LogReader())->read($dir, $now, max: 50);

        $fs->remove($dir);

        $this->assertSame(\count($hidden), $result['known']);
        $this->assertSame(\count($visible) + 1, array_sum(array_column($result['errors'], 'count')));
        $messages = array_column($result['errors'], 'message');
        $this->assertContains('Uncaught PHP Exception RuntimeException: "Boom" at Foo.php line 3', $messages);
        $this->assertContains('Content-Security-Policy violation reported for "style-src-attr" on line 1', $messages);
    }

    public function testMissingDirectoryYieldsNoErrors(): void
    {
        $this->assertSame(['errors' => [], 'known' => 0], (new LogReader())->read('/nicht/vorhanden', new \DateTimeImmutable()));
    }
}
