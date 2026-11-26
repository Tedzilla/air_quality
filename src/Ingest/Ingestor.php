<?php

declare(strict_types=1);

namespace AirQuality\Ingest;

use AirQuality\Config;
use AirQuality\Eea\Client;
use AirQuality\Storage\Database;
use ZipArchive;

final class Ingestor
{
    public function __construct(
        private readonly Config $config,
        private readonly Database $database,
        private readonly Client $client,
        private readonly ParquetReader $parquetReader,
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

        foreach (Config::CITIES as $city) {
            try {
                $summary['cities'][$city] = $this->ingestCity($city, $from, $to, $work);
            } catch (\Throwable $e) {
                $summary['cities'][$city] = [
                    'stations' => 0,
                    'samples' => 0,
                    'note' => $e->getMessage(),
                ];
            }
        }

        $this->database->pruneOlderThan($from->format('Y-m-d') . 'T00:00:00');
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
