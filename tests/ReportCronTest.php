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

use Cgoit\SitewatchBundle\Cron\ReportCron;
use Cgoit\SitewatchBundle\Report\LogReader;
use Cgoit\SitewatchBundle\Report\ReportBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ReportCronTest extends TestCase
{
    public function testPostsReportWithBearerToken(): void
    {
        $seen = [];
        $client = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$seen): MockResponse {
                $seen = ['method' => $method, 'url' => $url, 'options' => $options];

                return new MockResponse('', ['http_code' => 204]);
            },
        );

        $this->cron($client, 'https://sitewatch.example.org/')();

        $this->assertSame('POST', $seen['method']);
        $this->assertSame('https://sitewatch.example.org/api/v1/reports', $seen['url']);
        $this->assertContains('Authorization: Bearer geheim', $seen['options']['headers']);
        $this->assertSame('meine-seite', json_decode($seen['options']['body'], true, 512, JSON_THROW_ON_ERROR)['site']['id']);
    }

    public function testRefusesPlainHttp(): void
    {
        $client = new MockHttpClient(static fn () => throw new \LogicException('darf nicht gesendet werden'));

        $this->cron($client, 'http://sitewatch.example.org')();

        $this->assertSame(0, $client->getRequestsCount());
    }

    public function testServerErrorDoesNotThrow(): void
    {
        $this->cron(new MockHttpClient(new MockResponse('kaputt', ['http_code' => 500])), 'https://sitewatch.example.org')();

        $this->expectNotToPerformAssertions();
    }

    private function cron(MockHttpClient $client, string $url): ReportCron
    {
        $builder = new ReportBuilder(sys_get_temp_dir(), 'meine-seite', null, null, new LogReader());

        return new ReportCron($builder, $client, new NullLogger(), $url, 'geheim', 'meine-seite');
    }
}
