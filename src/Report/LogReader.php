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

/**
 * Liest Fehler (ERROR und höher) der letzten Zeit aus den Monolog-Dateien und gruppiert gleiche Meldungen.
 * Entspricht internal/collect/logs.go in Sitewatch.
 */
class LogReader
{
    // Monolog-Zeile: [2026-10-27T19:02:11.123456+01:00] request.CRITICAL: Nachricht {...} []
    private const string LINE = '/^\[([^\]]+)\] [^.\s]+\.([A-Z]+): (.*)$/';

    private const array LEVELS = ['ERROR' => true, 'CRITICAL' => true, 'ALERT' => true, 'EMERGENCY' => true];

    private const TAIL_BYTES = 512 * 1024;

    private const string CSP_PREFIX = 'Content-Security-Policy violation reported for "';

    /**
     * Einzige Stelle für die Ausnahmeregeln (wie cspNoise in internal/collect/csp.go in Sitewatch): bekanntes Rauschen
     * aus Besucher-Browsern, kein Fehler der Seite. Ausgeblendet wird eine CSP-Meldung, wenn die Direktive passt und der
     * User-Agent alle Teile einer Gruppe aus `ua` enthält und keinen aus `notUa`. Alles andere bleibt sichtbar.
     */
    private const array CSP_NOISE = [
        // mobiles WebKit (Safari, In-App-Browser, Chrome auf iOS) und Chrome auf Android
        ['directives' => ['style-src-attr', 'style-src-elem'], 'ua' => [['iPhone'], ['iPad'], ['Android', 'Chrome']], 'notUa' => []],
        // Desktop-Chrome und -Edge (Erweiterungen)
        ['directives' => ['connect-src'], 'ua' => [['Windows', 'Chrome'], ['Windows', 'Edg/'], ['Macintosh', 'Chrome'], ['Macintosh', 'Edg/']], 'notUa' => ['Mobile', 'Android']],
    ];

    /**
     * Liefert die Fehler (neueste zuerst) und die Zahl der als bekannt ausgeblendeten CSP-Meldungen (nicht in errors enthalten).
     *
     * @return array{errors: list<array{time: string, level: string, message: string, count: int}>, known: int}
     */
    public function read(string $dir, \DateTimeImmutable $now, int $window = 86400, int $max = 20): array
    {
        $since = $now->getTimestamp() - $window;
        $until = $now->getTimestamp() + 300;
        $groups = [];
        $known = 0;

        foreach (glob($dir.'/*.log') ?: [] as $file) {
            $mtime = @filemtime($file);
            if (false === $mtime || $mtime < $since) {
                continue;
            }

            foreach ($this->tailLines($file) as $line) {
                if (!preg_match(self::LINE, $line, $m) || !isset(self::LEVELS[$m[2]])) {
                    continue;
                }

                try {
                    $ts = (new \DateTimeImmutable($m[1]))->getTimestamp();
                } catch (\Exception) {
                    continue;
                }

                if ($ts < $since || $ts > $until) {
                    continue;
                }

                if ($this->knownCsp($m[3])) {
                    ++$known;
                    continue;
                }

                $message = $this->clean($m[3]);
                $key = $m[2]."\0".$message;

                if (isset($groups[$key])) {
                    ++$groups[$key]['count'];
                    $groups[$key]['ts'] = max($groups[$key]['ts'], $ts);
                } else {
                    $groups[$key] = ['ts' => $ts, 'level' => $m[2], 'message' => $message, 'count' => 1];
                }
            }
        }

        usort($groups, static fn (array $a, array $b): int => $b['ts'] <=> $a['ts']);

        $errors = array_map(
            static fn (array $g): array => [
                'time' => (new \DateTimeImmutable('@'.$g['ts']))->format(DATE_ATOM),
                'level' => $g['level'],
                'message' => $g['message'],
                'count' => $g['count'],
            ],
            \array_slice($groups, 0, $max),
        );

        return ['errors' => $errors, 'known' => $known];
    }

    /**
     * Meldet, ob $msg (Monolog-Meldung samt Kontext) eine CSP-Meldung von Contao ist, die unter CSP_NOISE fällt.
     * Der User-Agent steht im Kontext (ContaoContext, als JSON in JSON); fehlt er, bleibt die Meldung sichtbar.
     */
    private function knownCsp(string $msg): bool
    {
        if (!str_starts_with($msg, self::CSP_PREFIX)) {
            return false;
        }

        $rest = substr($msg, \strlen(self::CSP_PREFIX));
        $directive = strstr($rest, '"', true);
        $ua = explode('\\"browser\\":\\"', $rest, 2)[1] ?? '';
        $ua = str_replace('\\\\/', '/', explode('\\",\\"uri\\":', $ua, 2)[0]);

        // Zahl der Teile aus $parts, die im User-Agent vorkommen
        $hits = static fn (array $parts): int => \count(array_filter($parts, static fn (string $p): bool => str_contains($ua, $p)));

        foreach (self::CSP_NOISE as $rule) {
            if (!\in_array($directive, $rule['directives'], true) || $hits($rule['notUa']) > 0) {
                continue;
            }

            foreach ($rule['ua'] as $all) {
                if ($hits($all) === \count($all)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Schneidet den Monolog-Kontext (" {...}" / " []") ab und kürzt.
     */
    private function clean(string $s): string
    {
        foreach ([' {"', ' []', ' {}'] as $sep) {
            if (false !== ($i = strpos($s, $sep))) {
                $s = substr($s, 0, $i);
            }
        }

        $s = trim($s);

        return mb_strlen($s) > 200 ? mb_substr($s, 0, 200).'…' : $s;
    }

    /**
     * @return list<string>
     */
    private function tailLines(string $file): array
    {
        $size = @filesize($file);
        if (false === $size || !($fh = @fopen($file, 'r'))) {
            return [];
        }

        $partial = $size > self::TAIL_BYTES;
        if ($partial) {
            fseek($fh, $size - self::TAIL_BYTES);
        }

        $data = stream_get_contents($fh);
        fclose($fh);

        $lines = preg_split('/\r?\n/', (string) $data) ?: [];
        if ($partial) {
            array_shift($lines); // erste Zeile ist angeschnitten
        }

        return $lines;
    }
}
