<?php

declare(strict_types=1);

namespace AirQuality\SensorCommunity;

use AirQuality\Config;
use AirQuality\Fallback\HourlySource;

/**
 * sensor.community (formerly luftdaten.info) citizen sensor network. Data is ODbL-licensed.
 *
 * - Sensor discovery: the live API lists sensors that reported in the last few minutes.
 * - History: the archive publishes one CSV per sensor per day, readings every ~2.5 minutes in UTC.
 *   They are averaged into hourly means here.
 *
 * Sensor ids are "<type>:<id>" (e.g. "sds011:53351"), because the archive file name needs the type.
 */
final class Client implements HourlySource
{
    private const MIN_INTERVAL_SECONDS = 0.15;
    private const MAX_ATTEMPTS = 4;
    /** SDS011 and similar sensors cap at 999.9 µg/m³; values at the cap are saturation, not readings. */
    private const MAX_VALID_PM25 = 999.0;

    /** @var \CurlHandle|null Reused so the archive downloads share one TLS connection. */
    private ?\CurlHandle $curl = null;
    private float $lastRequestAt = 0.0;

    public function __construct(private readonly Config $config)
    {
    }

    public function __destruct()
    {
        if ($this->curl !== null) {
            curl_close($this->curl);
        }
    }

    public function name(): string
    {
        return 'sensor_community';
    }

    public function sensorsNear(float $lat, float $lon, \DateTimeImmutable $activeSince): array
    {
        $url = sprintf(
            '%s/airrohr/v1/filter/area=%.5f,%.5f,%d',
            $this->config->sensorCommunityApiBase,
            $lat,
            $lon,
            Config::SENSOR_COMMUNITY_RADIUS_KM
        );
        [$status, $body] = $this->request($url);
        if ($status !== 200) {
            throw new \RuntimeException('sensor.community returned HTTP ' . $status . ' for the sensor list');
        }
        try {
            $readings = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('sensor.community returned invalid JSON for the sensor list');
        }

        $sensors = [];
        foreach (is_array($readings) ? $readings : [] as $reading) {
            $hasPm25 = false;
            foreach ($reading['sensordatavalues'] ?? [] as $value) {
                if (($value['value_type'] ?? null) === 'P2') {
                    $hasPm25 = true;
                    break;
                }
            }
            $type = strtolower((string) ($reading['sensor']['sensor_type']['name'] ?? ''));
            $id = (int) ($reading['sensor']['id'] ?? 0);
            if (!$hasPm25 || $id <= 0 || !preg_match('/^[a-z0-9]+$/', $type)) {
                continue;
            }
            if ((string) ($reading['location']['indoor'] ?? '0') === '1') {
                continue;
            }
            $sensorLat = (float) ($reading['location']['latitude'] ?? 0);
            $sensorLon = (float) ($reading['location']['longitude'] ?? 0);
            $sensors[$id] = [
                'id' => $type . ':' . $id,
                'label' => sprintf('%s #%d (sensor.community)', strtoupper($type), $id),
                'distance' => self::distanceKm($lat, $lon, $sensorLat, $sensorLon),
            ];
        }

        // Closest first, capped: each sensor costs one archive request per day of history.
        usort($sensors, static fn (array $a, array $b): int => $a['distance'] <=> $b['distance']);
        $sensors = array_slice(array_values($sensors), 0, Config::SENSOR_COMMUNITY_MAX_SENSORS);

        return array_map(static fn (array $s): array => ['id' => $s['id'], 'label' => $s['label']], $sensors);
    }

    public function hourly(string $sensorId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if (!preg_match('/^([a-z0-9]+):(\d+)$/', $sensorId, $m)) {
            throw new \InvalidArgumentException('Invalid sensor.community sensor id: ' . $sensorId);
        }
        [, $type, $id] = $m;

        $utc = new \DateTimeZone('UTC');
        $fromSql = $from->setTimezone($utc)->format('Y-m-d\TH:i:s');
        $toSql = $to->setTimezone($utc)->format('Y-m-d\TH:i:s');

        /** @var array<string, array{sum: float, n: int}> $hours */
        $hours = [];
        $day = $from->setTimezone($utc)->setTime(0, 0);
        while ($day < $to) {
            $date = $day->format('Y-m-d');
            $url = sprintf('%s/%s/%s_%s_sensor_%s.csv', $this->config->sensorCommunityArchiveBase, $date, $date, $type, $id);
            [$status, $body] = $this->request($url);
            // A sensor that was offline that day (or a day not yet archived) has no file.
            if ($status === 200) {
                $this->accumulate($body, $hours);
            } elseif ($status !== 404) {
                throw new \RuntimeException('sensor.community archive returned HTTP ' . $status . ' for ' . $date);
            }
            $day = $day->modify('+1 day');
        }

        $rows = [];
        ksort($hours);
        foreach ($hours as $hour => $agg) {
            if ($hour < $fromSql || $hour >= $toSql || $agg['n'] < Config::SENSOR_COMMUNITY_MIN_READINGS_PER_HOUR) {
                continue;
            }
            $rows[] = ['observed_at' => $hour, 'pm25' => round($agg['sum'] / $agg['n'], 2)];
        }

        return $rows;
    }

    /**
     * Adds one day's readings to per-hour sums. Columns: sensor_id;sensor_type;location;lat;lon;timestamp;P1;...;P2;...
     *
     * @param array<string, array{sum: float, n: int}> $hours
     */
    private function accumulate(string $csv, array &$hours): void
    {
        $lines = preg_split('/\r?\n/', trim($csv)) ?: [];
        $header = str_getcsv((string) array_shift($lines), ';', '"', '');
        $tsCol = array_search('timestamp', $header, true);
        $pmCol = array_search('P2', $header, true);
        if ($tsCol === false || $pmCol === false) {
            return;
        }

        foreach ($lines as $line) {
            $cols = explode(';', $line);
            $timestamp = $cols[$tsCol] ?? '';
            $value = $cols[$pmCol] ?? '';
            if (strlen($timestamp) < 13 || $value === '' || !is_numeric($value)) {
                continue;
            }
            $pm25 = (float) $value;
            if ($pm25 < 0 || $pm25 >= self::MAX_VALID_PM25) {
                continue;
            }
            $hour = substr($timestamp, 0, 13) . ':00:00';
            $hours[$hour] ??= ['sum' => 0.0, 'n' => 0];
            $hours[$hour]['sum'] += $pm25;
            $hours[$hour]['n']++;
        }
    }

    /**
     * GET with retries: the archive server occasionally drops long-lived connections, so a failed
     * transfer (or a 5xx) is retried on a fresh connection.
     *
     * @return array{0: int, 1: string}
     */
    private function request(string $url): array
    {
        $error = '';
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $wait = $this->lastRequestAt + self::MIN_INTERVAL_SECONDS - microtime(true);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
            $this->lastRequestAt = microtime(true);

            if ($this->curl === null) {
                $handle = curl_init();
                if ($handle === false) {
                    throw new \RuntimeException('Unable to start HTTP request to sensor.community');
                }
                $this->curl = $handle;
            }
            curl_setopt_array($this->curl, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_USERAGENT => 'air-quality-ranking/1.0',
            ]);
            $body = curl_exec($this->curl);
            $status = (int) curl_getinfo($this->curl, CURLINFO_HTTP_CODE);

            if ($body !== false && $status < 500) {
                return [$status, (string) $body];
            }

            $error = $body === false ? curl_error($this->curl) : 'HTTP ' . $status;
            curl_close($this->curl);
            $this->curl = null;
            sleep($attempt);
        }

        throw new \RuntimeException('sensor.community request failed after ' . self::MAX_ATTEMPTS . ' attempts: ' . $error);
    }

    private static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
