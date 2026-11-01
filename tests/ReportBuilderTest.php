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
use Cgoit\SitewatchBundle\Report\ReportBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ReportBuilderTest extends TestCase
{
    // Felder aus internal/report in Sitewatch (Schema 1); ein neues Feld dort muss hier bewusst ergänzt werden.
    private const array TOP = ['schema', 'agent', 'host', 'site', 'errors', 'collected_at'];

    private const array SITE = ['id', 'name', 'kind', 'url', 'php', 'contao', 'maintenance_mode', 'maintenance_since', 'tls_expires', 'last_backup', 'backup_count', 'cron_last_run', 'pending_migrations', 'packages_total', 'outdated', 'advisories', 'problems'];

    public function testReportMatchesSchema(): void
    {
        $dir = $this->projectDir();
        $report = $this->builder($dir)->build(new \DateTimeImmutable('2026-11-01T11:00:00+01:00'));

        $this->assertSame([], array_diff(array_keys($report), self::TOP));
        $this->assertSame([], array_diff(array_keys($report['site']), self::SITE));
        $this->assertSame(1, $report['schema']);
        $this->assertSame('2026-11-01T10:00:00+00:00', $report['collected_at']);
        $this->assertSame('meine-seite', $report['site']['id']);
        $this->assertSame('contao', $report['site']['kind']);
        $this->assertFalse($report['site']['maintenance_mode']);
        $this->assertArrayNotHasKey('maintenance_since', $report['site']);
        $this->assertSame([], $report['site']['outdated']);
        $this->assertSame([], $report['errors']);
        $this->assertNotEmpty($report['site']['problems']);
        $this->assertJson(json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function testMaintenanceModeFromFile(): void
    {
        $dir = $this->projectDir();
        $fs = new Filesystem();
        $fs->dumpFile($dir.'/var/maintenance.html', 'x');
        $fs->touch($dir.'/var/maintenance.html', (new \DateTimeImmutable('2026-10-31T08:00:00+00:00'))->getTimestamp());

        $site = $this->builder($dir)->build(new \DateTimeImmutable('2026-11-01T10:00:00+00:00'))['site'];

        $this->assertTrue($site['maintenance_mode']);
        $this->assertSame('2026-10-31T08:00:00+00:00', $site['maintenance_since']);
    }

    private function projectDir(): string
    {
        $dir = sys_get_temp_dir().'/sitewatch-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($dir.'/var');

        return $dir;
    }

    private function builder(string $dir): ReportBuilder
    {
        return new ReportBuilder($dir, 'meine-seite', null, null, new LogReader());
    }
}
