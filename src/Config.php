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

    /** City centre coordinates (lat, lon), used to find OpenAQ sensors near each city. */
    public const CITY_COORDINATES = [
        'Sofia' => [42.6977, 23.3219],
        'Plovdiv' => [42.1354, 24.7453],
        'Varna' => [43.2141, 27.9147],
        'Burgas' => [42.5048, 27.4626],
        'Ruse' => [43.8356, 25.9657],
    ];

    /** A city whose EEA data covers fewer hours than this share of the window is topped up from OpenAQ. */
    public const COVERAGE_THRESHOLD = 0.75;

    /**
     * Sources in priority order. For each city-hour only the highest-priority source with data is used,
     * and a source is consulted only when the sources above it cover less than COVERAGE_THRESHOLD.
     */
    public const SOURCES = ['eea', 'openaq', 'sensor_community'];

    public const SENSOR_COMMUNITY_RADIUS_KM = 10;
    /** Each sensor costs one archive request per day of history, so only the closest ones are used. */
    public const SENSOR_COMMUNITY_MAX_SENSORS = 10;
    /** Sensors report every ~2.5 minutes (~24/hour); hours with fewer readings than this are dropped. */
    public const SENSOR_COMMUNITY_MIN_READINGS_PER_HOUR = 6;

    /** Outlier filter for low-cost sources, applied per city-hour across sensors of the same source. */
    public const OUTLIER_MIN_SENSORS = 3;
    public const OUTLIER_RATIO = 3.0;
    public const OUTLIER_MIN_DIFF = 10.0;

    /**
     * A city is ranked with high confidence when at least this share of its hours come from reference
     * monitors. Only high-confidence cities can be named the cleanest.
     */
    public const REFERENCE_SHARE_FOR_HIGH_CONFIDENCE = 0.75;

    public const OPENAQ_PM25_PARAMETER_ID = 2;
    public const OPENAQ_RADIUS_METERS = 20000;
    /** OpenAQ hourly values built from less than this share of the expected readings are dropped. */
    public const OPENAQ_MIN_HOUR_COVERAGE_PERCENT = 50.0;

    public function __construct(
        public readonly string $dataDir,
        public readonly string $duckdbBin,
        public readonly string $eeaApiBase,
        public readonly int $refreshHours,
        public readonly ?string $openAqApiKey = null,
        public readonly string $openAqApiBase = 'https://api.openaq.org/v3',
        public readonly bool $sensorCommunityEnabled = true,
        public readonly string $sensorCommunityApiBase = 'https://data.sensor.community',
        public readonly string $sensorCommunityArchiveBase = 'https://archive.sensor.community',
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
        $openAqKey = trim((string) getenv('OPENAQ_API_KEY'));
        $openAqBase = getenv('OPENAQ_API_BASE') ?: 'https://api.openaq.org/v3';
        $sensorCommunity = strtolower((string) (getenv('SENSOR_COMMUNITY_ENABLED') ?: 'true'));

        return new self(
            dataDir: rtrim($dataDir, '/'),
            duckdbBin: $duckdb,
            eeaApiBase: rtrim($api, '/'),
            refreshHours: max(1, $hours),
            openAqApiKey: $openAqKey !== '' ? $openAqKey : null,
            openAqApiBase: rtrim($openAqBase, '/'),
            sensorCommunityEnabled: !in_array($sensorCommunity, ['0', 'false', 'no', 'off'], true),
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
