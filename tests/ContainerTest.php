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
use Cgoit\SitewatchBundle\DependencyInjection\CgoitSitewatchExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ContainerTest extends TestCase
{
    public function testServicesWireFromConfig(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->register(LoggerInterface::class, NullLogger::class);
        $container->register(HttpClientInterface::class, MockHttpClient::class);

        (new CgoitSitewatchExtension())->load([['url' => 'https://sitewatch.example.org', 'token' => 't', 'site_id' => 'meine-seite']], $container);
        $container->getDefinition(ReportCron::class)->setPublic(true);
        $container->compile();

        $this->assertInstanceOf(ReportCron::class, $container->get(ReportCron::class));
    }

    public function testInvalidSiteIdIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new CgoitSitewatchExtension())->load([['site_id' => 'Falsche ID']], new ContainerBuilder());
    }
}
