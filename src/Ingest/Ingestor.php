<?php

declare(strict_types=1);

namespace AirQuality\Ingest;

use AirQuality\Config;
use AirQuality\Eea\Client;
use AirQuality\Fallback\HourlySource;
use AirQuality\Storage\Database;
use ZipArchive;

final class Ingestor
{
    public function __construct(
        private readonly Config $config,
        private readonly Database $database,
        private readonly Client $client,
        private readonly ParquetReader $parquetReader,
        /** @var list<HourlySource> Fallback sources, in Config::SOURCES priority order. */
        private readonly array $fallbacks = [],
    ) {
    }

    public function run(): array
    {
        $lockPath = $this->config->dataDir . '/ingest.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot open ingest lock file');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new \RuntimeException('Ingest is already running');
        }

        try {
            return $this->runLocked();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function runLocked(): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $from = $now->modify('-' . Config::HISTORY_MONTHS . ' months')->setTime(0, 0, 0);
        $to = $now->setTime(23, 59, 59);

        $work = $this->config->workDir();
        $this->resetDir($work);

        $summary = [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'cities' => [],
        ];

        $this->database->pruneOlderThan($from->format('Y-m-d') . 'T00:00:00');

        // Coverage is measured over whole hours that have already ended.
        $coverageTo = $now->setTime((int) $now->format('H'), 0, 0);

        foreach (Config::CITIES as $city) {
            try {
                $eea = $this->ingestCity($city, $from, $to, $work);
            } catch (\Throwable $e) {
                $eea = ['stations' => 0, 'samples' => 0, 'note' => $e->getMessage()];
            }

            $cityId = $this->database->cityId($city);
            $eeaCoverage = $this->coverage($cityId, $from, $coverageTo, ['eea']);
            $entry = ['eea' => $eea + ['coverage_percent' => $eeaCoverage['percent']]];

            // Each fallback is judged only on the sources ranked above it, so a city it once filled
            // keeps being refreshed instead of flip-flopping once its own data lifts the total.
            $above = ['eea'];
            $coverageAbove = $eeaCoverage;
            foreach ($this->fallbacks as $fallback) {
                $entry[$fallback->name()] = $this->topUp($fallback, $city, $cityId, $coverageAbove, $from, $coverageTo);
                $above[] = $fallback->name();
                $coverageAbove = $this->coverage($cityId, $from, $coverageTo, $above);
            }

            $entry['coverage'] = $this->coverage($cityId, $from, $coverageTo);
            $summary['cities'][$city] = $entry;
        }

        $this->database->setMeta('last_ingest_at', $now->format(DATE_ATOM));
        $this->database->setMeta('last_ingest_error', '');
        $this->database->setMeta('history_from', $from->format('Y-m-d'));
        $this->database->setMeta('history_to', $to->format('Y-m-d'));
        $this->database->setMeta('last_ingest_summary', json_encode($summary, JSON_THROW_ON_ERROR));

        return $summary;
    }

    private function ingestCity(
        string $city,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $work,
    ): array {
        $cityDir = $work . '/' . preg_replace('/[^A-Za-z]/', '', $city);
        $this->resetDir($cityDir);
        $zipPath = $cityDir . '/download.zip';

        $bytes = $this->client->downloadCityParquetZip($city, $from, $to, $zipPath);
        if ($bytes < 64) {
            return ['stations' => 0, 'samples' => 0, 'note' => 'EEA returned an empty file'];
        }

        $extractDir = $cityDir . '/parquet';
        mkdir($extractDir, 0775, true);
        $this->unzip($zipPath, $extractDir);

        $csvPath = $cityDir . '/measurements.csv';
        $rows = $this->parquetReader->extractHourlyPm25($extractDir, $csvPath);
        if ($rows === []) {
            return ['stations' => 0, 'samples' => 0, 'note' => 'No valid hourly PM2.5 rows'];
        }

        $cityId = $this->database->cityId($city);
        $stations = [];
        foreach ($rows as $row) {
            $stations[$row['sampling_point']] = true;
        }
        foreach (array_keys($stations) as $samplingPoint) {
            $this->database->replaceStation($samplingPoint, $cityId);
        }

        $stored = $this->database->upsertMeasurements($cityId, $rows);

        return [
            'stations' => count($stations),
            'samples' => $stored,
        ];
    }

    /**
     * @return array{hours_expected: int, hours_covered: int, percent: float, longest_gap_hours: int, complete: bool}
     */
    private function coverage(
        int $cityId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $toExclusive,
        ?array $sources = null,
    ): array {
        $fromSql = $from->format('Y-m-d\TH:i:s');
        $toSql = $toExclusive->format('Y-m-d\TH:i:s');
        $expected = max(1, intdiv($toExclusive->getTimestamp() - $from->getTimestamp(), 3600));
        $covered = $this->database->coveredHours($cityId, $fromSql, $toSql, $sources);
        $ratio = $covered / $expected;

        return [
            'hours_expected' => $expected,
            'hours_covered' => $covered,
            'percent' => round($ratio * 100, 1),
            'longest_gap_hours' => $sources === null ? $this->database->longestGapHours($cityId, $fromSql, $toSql) : 0,
            'complete' => $ratio >= Config::COVERAGE_THRESHOLD,
        ];
    }

    /**
     * Fetches hourly PM2.5 from a fallback source for a city whose higher-priority sources cover too little.
     * Only hours after the last stored one are requested for each sensor.
     */
    private function topUp(
        HourlySource $source,
        string $city,
        int $cityId,
        array $coverageAbove,
        \DateTimeImmutable $from,
        \DateTimeImmutable $toExclusive,
    ): array {
        if ($coverageAbove['complete']) {
            return ['used' => false, 'note' => 'Higher-priority sources cover ' . $coverageAbove['percent'] . '% of hours'];
        }

        [$lat, $lon] = Config::CITY_COORDINATES[$city];
        try {
            $sensors = $source->sensorsNear($lat, $lon, $from);
        } catch (\Throwable $e) {
            return ['used' => false, 'note' => $e->getMessage()];
        }
        if ($sensors === []) {
            return ['used' => false, 'note' => 'No active PM2.5 sensors near ' . $city];
        }

        $utc = new \DateTimeZone('UTC');
        $result = ['used' => true, 'sensors' => [], 'samples' => 0];
        foreach ($sensors as $sensor) {
            $samplingPoint = $source->name() . ':' . $sensor['id'];
            $latest = $this->database->latestObservation($samplingPoint);
            $start = $latest === null
                ? $from
                : max($from, (new \DateTimeImmutable($latest, $utc))->modify('+1 hour'));

            $entry = ['sensor' => $sensor['id'], 'label' => $sensor['label'], 'samples' => 0];
            if ($start < $toExclusive) {
                try {
                    $rows = $source->hourly($sensor['id'], $start, $toExclusive);
                    if ($rows !== []) {
                        $this->database->replaceStation($samplingPoint, $cityId, $source->name(), $sensor['label']);
                        $entry['samples'] = $this->database->upsertMeasurements(
                            $cityId,
                            array_map(
                                static fn (array $row): array => ['sampling_point' => $samplingPoint] + $row,
                                $rows
                            ),
                            $source->name()
                        );
                    }
                } catch (\Throwable $e) {
                    $entry['note'] = $e->getMessage();
                }
            }
            $result['samples'] += $entry['samples'];
            $result['sensors'][] = $entry;
        }

        return $result;
    }

    private function unzip(string $zipPath, string $destination): void
    {
        $zip = new ZipArchive();
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            throw new \RuntimeException('Cannot unzip EEA archive (code ' . $opened . ')');
        }
        $zip->extractTo($destination);
        $zip->close();
    }

    private function resetDir(string $dir): void
    {
        if (is_dir($dir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        } else {
            mkdir($dir, 0775, true);
        }
    }
}
