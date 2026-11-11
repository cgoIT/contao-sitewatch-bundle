# Contao Sitewatch Bundle

Meldet den Zustand einer Contao-5-Installation per Push an [Sitewatch](https://github.com/cgoIT/sitewatch). Gedacht für Hosting, auf dem kein eigenes Programm laufen darf (z. B. Shared Hosting). Wo der Go-Agent läuft, braucht es dieses Bundle nicht.

Das Bundle ist **nur lesend** und öffnet **keinen eingehenden Endpunkt**: Ein Contao-Cron-Job (stündlich) sendet den Report per HTTPS an Sitewatch.

## Installation

```bash
composer require cgoit/contao-sitewatch-bundle
```

In `config/config.yaml`:

Den fertigen Block mit Token erzeugt Sitewatch beim Anlegen eines Agenten vom Typ „Contao-Bundle“ (Seite „Agenten“).

```yaml
cgoit_sitewatch:
    url: 'https://sitewatch.example.org'
    token: '%env(SITEWATCH_TOKEN)%'   # Token der Site in Sitewatch, nicht ins Repository
    site_id: 'meine-seite'             # a-z, 0-9, -
    site_name: 'Meine Seite'           # optional
    site_url: 'https://www.example.org' # optional, nur zur Anzeige
```

`SITEWATCH_TOKEN` gehört in die `.env.local` auf dem Server. Ohne `url`, `token` und `site_id` sendet das Bundle nichts und schreibt eine Warnung ins Log. Die URL muss mit `https://` beginnen (http nur für `localhost`).

## Was gemeldet wird

PHP- und Contao-Version, Anzahl der Pakete, Wartungsmodus (`var/maintenance.html`), Zeitpunkt des Cron-Laufs und gruppierte Fehler (ERROR und höher, letzte 24 Stunden) aus `var/logs`. Bekannte CSP-Meldungen aus Besucher-Browsern werden nur gezählt (`errors_known`, Regeln in `LogReader::CSP_NOISE`).

**Nicht ermittelt** werden Updates, Sicherheitshinweise, Migrationen, Backups, TLS-Zertifikat und Serverwerte. Sitewatch kennt das am Agent-Typ `contao-bundle` und zeigt es als „nicht geprüft“; `site.problems` enthält nur echte Fehlschläge.

## Cron

Der Job hängt am Contao-Cron. Läuft dieser nur über Seitenaufrufe (Contao-Fallback ohne echten Server-Cron), meldet sich eine Seite ohne Besucher nicht. Sitewatch kann sie dann in der Site-Konfiguration anpingen, sobald der Report überfällig ist.

## Tests

```bash
composer install
composer run tests
```
