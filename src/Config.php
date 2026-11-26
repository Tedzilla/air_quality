<?php

declare(strict_types=1);

namespace AirQuality;

final class Config
{
    /** @var list<string> */
    public const CITIES = ['Sofia', 'Plovdiv', 'Varna', 'Burgas', 'Ruse'];

    public const COUNTRY = 'BG';
    public const POLLUTANT = 'PM2.5';
    public const HISTORY_MONTHS = 6;
    public const EEA_DATASET_UTD = 1;

    public function __construct(
        public readonly string $dataDir,
        public readonly string $duckdbBin,
        public readonly string $eeaApiBase,
        public readonly int $refreshHours,
    ) {
        if (!is_dir($this->dataDir) && !mkdir($this->dataDir, 0775, true) && !is_dir($this->dataDir)) {
            throw new \RuntimeException('Cannot create data directory: ' . $this->dataDir);
        }
    }

    public static function fromEnvironment(): self
    {
        $root = dirname(__DIR__);
        $dataDir = getenv('DATA_DIR') ?: $root . '/data';
        $duckdb = getenv('DUCKDB_BIN') ?: 'duckdb';
        $api = getenv('EEA_API_BASE') ?: 'https://eeadmz1-downloads-api-appservice.azurewebsites.net';
        $hours = (int) (getenv('REFRESH_HOURS') ?: '6');

        return new self(
            dataDir: rtrim($dataDir, '/'),
            duckdbBin: $duckdb,
            eeaApiBase: rtrim($api, '/'),
            refreshHours: max(1, $hours),
        );
    }

    public function sqlitePath(): string
    {
        return $this->dataDir . '/air_quality.sqlite';
    }

    public function workDir(): string
    {
        return $this->dataDir . '/work';
    }
}
