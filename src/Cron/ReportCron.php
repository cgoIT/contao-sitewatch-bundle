<?php

declare(strict_types=1);

/*
 * This file is part of cgoit\contao-sitewatch-bundle for Contao Open Source CMS.
 *
 * @copyright  Copyright (c) cgoIT
 * @author     cgoIT <https://cgo-it.de>
 * @license    LGPL-3.0-or-later
 */

namespace Cgoit\SitewatchBundle\Cron;

use Cgoit\SitewatchBundle\Report\ReportBuilder;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Schickt stündlich einen Report an Sitewatch. Es gibt keinen eingehenden Endpunkt; läuft der Contao-Cron
 * nur über Seitenaufrufe, kann Sitewatch die Seite anpingen (siehe README).
 */
#[AsCronJob('hourly')]
class ReportCron
{
    public function __construct(
        private readonly ReportBuilder $builder,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string|null $url,
        private readonly string|null $token,
        private readonly string|null $siteId,
    ) {
    }

    public function __invoke(): void
    {
        if (!$this->url || !$this->token || !$this->siteId) {
            $this->logger->warning('Sitewatch: url, token und site_id müssen unter cgoit_sitewatch konfiguriert sein, es wird nichts gesendet.');

            return;
        }

        // Das Token geht im Header mit, deshalb nur über https (http nur für lokale Tests).
        if (!preg_match('#^(https://|http://(localhost|127\.0\.0\.1)(:\d+)?(/|$))#i', $this->url)) {
            $this->logger->error('Sitewatch: url muss mit https:// beginnen, es wird nichts gesendet.');

            return;
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                rtrim($this->url, '/').'/api/v1/reports',
                [
                    'headers' => ['Authorization' => 'Bearer '.$this->token, 'Content-Type' => 'application/json'],
                    'body' => json_encode($this->builder->build(new \DateTimeImmutable()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                    'timeout' => 10,
                    'max_redirects' => 0,
                ],
            );

            if ($response->getStatusCode() >= 300) {
                $this->logger->error(\sprintf('Sitewatch antwortet %d: %s', $response->getStatusCode(), substr(trim($response->getContent(false)), 0, 300)));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Sitewatch: Report konnte nicht gesendet werden: '.$e->getMessage());
        }
    }
}
