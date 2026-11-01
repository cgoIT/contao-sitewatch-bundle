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

        $this->assertSame([['time' => '2026-11-01T09:31:00+00:00', 'level' => 'CRITICAL', 'message' => 'Boom', 'count' => 2]], $errors);
    }

    public function testMissingDirectoryYieldsNoErrors(): void
    {
        $this->assertSame([], (new LogReader())->read('/nicht/vorhanden', new \DateTimeImmutable()));
    }
}
