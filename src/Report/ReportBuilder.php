<?php

declare(strict_types=1);

/*
 * This file is part of cgoit\contao-sitewatch-bundle for Contao Open Source CMS.
 *
 * @copyright  Copyright (c) cgoIT
 * @author     cgoIT <https://cgo-it.de>
 * @license    LGPL-3.0-or-later
 */

namespace Cgoit\SitewatchBundle\Report;

use Composer\InstalledVersions;

/**
 * Baut den Report im Schema von internal/report in Sitewatch (Schema-Version 1).
 * Fehlende Werte sind nicht gesetzt, nie geraten; was nicht ermittelt werden konnte, steht in site.problems.
 */
class ReportBuilder
{
    public const SCHEMA = 1;

    private const string BUNDLE = 'cgoit/contao-sitewatch-bundle';

    public function __construct(
        private readonly string $projectDir,
        private readonly string|null $siteId,
        private readonly string|null $siteName,
        private readonly string|null $siteUrl,
        private readonly LogReader $logReader,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(\DateTimeImmutable $now): array
    {
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $problems = [];

        $site = [
            'id' => (string) $this->siteId,
            'name' => $this->siteName ?: (string) $this->siteId,
            'kind' => 'contao',
            'php' => PHP_VERSION,
            // Dieser Job läuft gerade im Contao-Cron, der Cron funktioniert also.
            'cron_last_run' => $now->format(DATE_ATOM),
            'packages_total' => \count(InstalledVersions::getInstalledPackages()),
            // shortcut: Updates und Sicherheitshinweise sind leer, weil das Bundle sie nicht prüft (Sitewatch wertet sie bei diesem Agent-Typ nicht aus); später in Sitewatch aus den Paketversionen berechnen.
            'outdated' => [],
            'advisories' => [],
        ];

        if ($this->siteUrl) {
            $site['url'] = $this->siteUrl;
        }

        $contao = InstalledVersions::isInstalled('contao/core-bundle') ? InstalledVersions::getPrettyVersion('contao/core-bundle') : null;
        if (null === $contao) {
            $problems[] = 'Contao-Version nicht ermittelbar';
        } else {
            $site['contao'] = ltrim($contao, 'v');
        }

        // Wie im Go-Agenten: var/maintenance.html legt `contao:maintenance-mode enable` an, ihr Vorhandensein ist der Zustand.
        $maintenance = $this->projectDir.'/var/maintenance.html';
        $site['maintenance_mode'] = is_file($maintenance);
        if ($site['maintenance_mode']) {
            $mtime = @filemtime($maintenance);
            if (false !== $mtime) {
                $site['maintenance_since'] = (new \DateTimeImmutable('@'.$mtime))->format(DATE_ATOM);
            }
        }

        try {
            ['errors' => $errors, 'known' => $known] = $this->logReader->read($this->projectDir.'/var/logs', $now);
        } catch (\Throwable $e) {
            $errors = [];
            $known = null; // nicht ermittelt: Feld weglassen statt 0 melden
            $problems[] = 'Log nicht lesbar: '.$e->getMessage();
        }

        // Was das Bundle grundsätzlich nicht prüft (Updates, Migrationen, Backups, TLS, Serverwerte), kennt Sitewatch am
        // Agent-Typ und zeigt es als "nicht geprüft"; hier stehen nur Fehlschläge.
        if ([] !== $problems) {
            $site['problems'] = $problems;
        }

        return [
            'schema' => self::SCHEMA,
            'agent' => [
                'type' => 'contao-bundle',
                'version' => InstalledVersions::isInstalled(self::BUNDLE) ? (InstalledVersions::getPrettyVersion(self::BUNDLE) ?? 'dev') : 'dev',
            ],
            'host' => ['name' => (string) gethostname()],
            'site' => $site,
            'errors' => $errors,
            // bekannte CSP-Meldungen aus Besucher-Browsern: nur gezählt, nicht in errors (Regeln in LogReader::CSP_NOISE)
            ...(null === $known ? [] : ['errors_known' => $known]),
            'collected_at' => $now->format(DATE_ATOM),
        ];
    }
}
